<?php
// api/handlers/enrollment_handlers.php

// Inclui o helper de e-mail no início do arquivo
require_once __DIR__ . '/../helpers/email_helper.php';
// Inclui o helper de dados para documentos (necessário para a correção)
require_once __DIR__ . '/document_data_helper.php'; 


// ... (Funções handle_enroll, handle_submit_enrollment - mantidas) ...
function handle_enroll($conn, $data) { $studentId=$data['studentId'];$courseId=$data['courseId'];$stmt=$conn->prepare("SELECT * FROM enrollments WHERE studentId=? AND courseId=?");$stmt->execute([$studentId,$courseId]);$ex=$stmt->fetch();if($ex){if($ex['status']==='Pendente'||$ex['status']==='Aprovada'){send_response(false,'Matrícula ativa/pendente existente.',409);}elseif($ex['status']==='Cancelada'){$up=$conn->prepare("UPDATE enrollments SET status='Pendente',billingStartDate=NULL,contractAcceptedAt=NULL,termsAcceptedAt=NULL WHERE studentId=? AND courseId=?");$up->execute([$studentId,$courseId]);send_response(true,['message'=>'Matrícula reaberta para aprovação.']);}}else{$ins=$conn->prepare("INSERT INTO enrollments(studentId,courseId,status)VALUES(?,?,'Pendente')");$ins->execute([$studentId,$courseId]);send_response(true,['message'=>'Solicitação criada. Complete os dados.']);} }
function handle_submit_enrollment($conn, $data) { $sId=isset($data['studentId'])?filter_var($data['studentId'],FILTER_VALIDATE_INT):0;$cId=isset($data['courseId'])?filter_var($data['courseId'],FILTER_VALIDATE_INT):0;$eData=$data['enrollmentData']??[];if($sId<=0||$cId<=0){send_response(false,'IDs inválidos.',400);}if(empty($eData['aluno_rg'])||empty($eData['aluno_cpf'])){send_response(false,'RG/CPF do aluno obrigatórios.',400);}$isM=!empty($eData['guardianName']);if($isM&&(empty($eData['guardianName'])||empty($eData['guardianRG'])||empty($eData['guardianCPF'])||empty($eData['guardianEmail'])||empty($eData['guardianPhone']))){send_response(false,'Dados do responsável obrigatórios.',400);}if(empty($eData['acceptContract'])){send_response(false,'Aceite o Contrato.',400);}$conn->beginTransaction();try{$flds=['rg=:rg','cpf=:cpf'];$prms=[':rg'=>$eData['aluno_rg'],':cpf'=>$eData['aluno_cpf'],':studentId'=>$sId];if($isM){$flds=array_merge($flds,['guardianName=:gN','guardianRG=:gRG','guardianCPF=:gCPF','guardianEmail=:gE','guardianPhone=:gP']);$prms[':gN']=$eData['guardianName'];$prms[':gRG']=$eData['guardianRG'];$prms[':gCPF']=$eData['guardianCPF'];$prms[':gE']=$eData['guardianEmail'];$prms[':gP']=$eData['guardianPhone'];}$upSql="UPDATE users SET ".implode(',',$flds)." WHERE id=:studentId";$stU=$conn->prepare($upSql);$stU->execute($prms);$stC=$conn->prepare("SELECT status FROM enrollments WHERE studentId=? AND courseId=?");$stC->execute([$sId,$cId]);$exE=$stC->fetch(PDO::FETCH_ASSOC);$now=date('Y-m-d H:i:s');$cA=$now;$tA=!empty($eData['acceptImageTerms'])?$now:null;$msg='';if($exE){if($exE['status']==='Aprovada'||$exE['status']==='Pendente'){$msg='Dados atualizados. Matrícula continua '.$exE['status'].'.';}elseif($exE['status']==='Cancelada'){$upE=$conn->prepare("UPDATE enrollments SET status='Pendente',billingStartDate=NULL,contractAcceptedAt=?,termsAcceptedAt=? WHERE studentId=? AND courseId=?");$upE->execute([$cA,$tA,$sId,$cId]);$msg='Matrícula reativada, aguardando aprovação.';}}else{$insE=$conn->prepare("INSERT INTO enrollments(studentId,courseId,status,contractAcceptedAt,termsAcceptedAt)VALUES(?,?,?,?,?)");$insE->execute([$sId,$cId,'Pendente',$cA,$tA]);$msg='Solicitação enviada, aguardando aprovação.';}$conn->commit();send_response(true,['message'=>$msg]);}catch(Exception $e){$conn->rollBack();error_log("Erro submit_enroll: ".$e->getMessage());send_response(false,'Erro matrícula: '.$e->getMessage(),500);} }


// --- APROVAÇÃO DE MATRÍCULA E GERAÇÃO INICIAL DE PAGAMENTOS (COM ENVIO DE E-MAIL) ---
function handle_approve_enrollment(PDO $conn, $data) {
    $studentId = $data['studentId'];
    $courseId = $data['courseId'];
    $billingStartChoice = $data['billingStartChoice'];
    $overrideFee = isset($data['overrideFee']) && is_numeric($data['overrideFee']) ? (float)$data['overrideFee'] : null;

    $emailData = [ 'studentEmail' => null, 'studentFirstName' => null, 'courseName' => null ];

    $conn->beginTransaction();
    try {
        // Pega e-mail/nome do aluno E responsável (para o e-mail)
        $stmtUser = $conn->prepare("SELECT email, firstName, guardianEmail, guardianName FROM users WHERE id = ?");
        $stmtUser->execute([$studentId]);
        $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if (!$userData) throw new Exception("Aluno não encontrado.");
        
        // Decide para quem enviar: responsável (se houver) ou aluno
        $emailData['toEmail'] = !empty($userData['guardianEmail']) ? $userData['guardianEmail'] : $userData['email'];
        $emailData['toName'] = !empty($userData['guardianName']) ? $userData['guardianName'] : $userData['firstName'];
        $emailData['studentFirstName'] = $userData['firstName']; // Nome do aluno para o placeholder
        
        if(empty($emailData['toEmail'])) {
            error_log("Aprovação Matrícula (Aluno $studentId): Sem e-mail (aluno ou responsável) para notificar.");
            // Não lança exceção, permite continuar sem e-mail
        }

        // Pega nome do curso
        $stmtCourseName = $conn->prepare("SELECT name FROM courses WHERE id = ?");
        $stmtCourseName->execute([$courseId]);
        $emailData['courseName'] = $stmtCourseName->fetchColumn();
        if (!$emailData['courseName']) throw new Exception("Curso não encontrado.");

        // Define a data de início da cobrança
        $billingDate = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
        if ($billingStartChoice === 'next_month') { $billingDate->modify('first day of next month'); }
        else { $billingDate->modify('first day of this month'); }
        $billingStartDate = $billingDate->format('Y-m-d');

        // Atualiza a matrícula
        $stmt = $conn->prepare("UPDATE enrollments SET status = 'Aprovada', billingStartDate = ?, customMonthlyFee = ? WHERE studentId = ? AND courseId = ? AND status = 'Pendente'");
        $stmt->execute([$billingStartDate, $overrideFee, $studentId, $courseId]);
        if ($stmt->rowCount() == 0) { $conn->rollBack(); $cStmt=$conn->prepare("SELECT status FROM enrollments WHERE studentId=? AND courseId=?"); $cStmt->execute([$studentId,$courseId]); $cS=$cStmt->fetchColumn(); if($cS==='Aprovada'){send_response(true,['message'=>'Matrícula já aprovada.']);}else{throw new Exception('Matrícula não pendente.');} return; }

        // Busca detalhes para gerar pagamentos
        $stmtDetails = $conn->prepare("SELECT c.monthlyFee, c.paymentType, c.installments, e.customMonthlyFee, e.scholarshipPercentage FROM courses c JOIN enrollments e ON c.id = e.courseId WHERE e.studentId = ? AND e.courseId = ?");
        $stmtDetails->execute([$studentId, $courseId]); $details = $stmtDetails->fetch();
        $stmtSettings = $conn->query("SELECT defaultDueDay FROM system_settings WHERE id = 1"); $settings = $stmtSettings->fetch(); $dueDay = isset($settings['defaultDueDay'])?max(1,min(28,(int)$settings['defaultDueDay'])):10;

        // Gera pagamentos
        if ($details && ($details['monthlyFee'] > 0 || $details['customMonthlyFee'] !== null) && ($details['scholarshipPercentage'] === null || $details['scholarshipPercentage'] < 100)) {
            $baseAmount = $details['customMonthlyFee'] !== null ? $details['customMonthlyFee'] : $details['monthlyFee']; $scholarship = $details['scholarshipPercentage'] ?? 0; $finalAmount = round($baseAmount * (1 - ($scholarship / 100)), 2);
            if ($finalAmount > 0) {
                $limit = 0;
                if ($details['paymentType'] === 'parcelado' && !empty($details['installments']) && $details['installments'] > 0) { $limit = (int)$details['installments']; } else { $limit = 12; } // 12 para recorrente
                $cursorDate = clone $billingDate;
                for ($i = 0; $i < $limit; $i++) {
                    $refDate = $cursorDate->format('Y-m-01'); $lastDay = (int)$cursorDate->format('t'); $actualDue = min($dueDay, $lastDay); $dueDate = $cursorDate->format('Y-m-') . str_pad($actualDue, 2, '0', STR_PAD_LEFT);
                    $stmtInsP = $conn->prepare("INSERT INTO payments (studentId, courseId, amount, referenceDate, dueDate, status) VALUES (?, ?, ?, ?, ?, 'Pendente')"); $stmtInsP->execute([$studentId, $courseId, $finalAmount, $refDate, $dueDate]);
                    $cursorDate->modify('+1 month');
                }
            }
        }

        // --- ENVIO DE E-MAIL ---
        $emailSent = false;
        $responseMessage = 'Matrícula aprovada e pagamentos gerados.'; 

        if (!empty($emailData['toEmail'])) {
            $stmtMailSettings = $conn->query("SELECT s.name as schoolName, ss.site_url, ss.email_approval_subject, ss.email_approval_body, ss.smtpUser FROM system_settings ss JOIN school_profile s ON s.id = 1 WHERE ss.id = 1");
            $mailSettings = $stmtMailSettings->fetch(PDO::FETCH_ASSOC);

            if ($mailSettings && !empty($mailSettings['smtpUser']) && !empty($mailSettings['email_approval_subject']) && !empty($mailSettings['email_approval_body'])) {
                $siteUrl = rtrim($mailSettings['site_url'] ?? '', '/'); 
                $contractLink = $siteUrl . "/api/index.php?action=generateContractPdf&studentId=$studentId&courseId=$courseId";

                $placeholders = [
                    '{{aluno_nome}}' => htmlspecialchars($emailData['studentFirstName']),
                    '{{responsavel_nome}}' => htmlspecialchars($emailData['toName']),
                    '{{curso_nome}}' => htmlspecialchars($emailData['courseName']),
                    '{{escola_nome}}' => htmlspecialchars($mailSettings['schoolName']),
                    '{{link_contrato}}' => $contractLink
                ];

                $subject = $mailSettings['email_approval_subject'];
                $bodyHTML = nl2br($mailSettings['email_approval_body']); 
                
                foreach ($placeholders as $key => $value) {
                    $subject = str_replace($key, $value, $subject);
                    $bodyHTML = str_replace($key, $value, $bodyHTML);
                }
                
                $emailSent = send_system_email($conn, $emailData['toEmail'], $emailData['toName'], $subject, $bodyHTML);

                if ($emailSent) { $responseMessage .= ' E-mail de confirmação enviado para ' . $emailData['toEmail'] . '.'; } 
                else { $responseMessage .= ' Falha ao enviar e-mail de confirmação (verifique config. SMTP).'; }
            } else {
                $responseMessage .= ' E-mail não enviado (modelos ou SMTP não configurados).';
                error_log("Aprovação Matrícula (Aluno $studentId): E-mail não enviado (modelos/SMTP não configurados).");
            }
        }

        $conn->commit();
        send_response(true, ['message' => $responseMessage]);

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Erro ao aprovar matrícula (Aluno $studentId, Curso $courseId): " . $e->getMessage());
        send_response(false, 'Erro ao aprovar matrícula: ' . $e->getMessage(), 500);
    }
}


// ... (Funções handle_cancel_enrollment, handle_reactivate_enrollment - mantidas) ...
function handle_cancel_enrollment($conn, $data) { $sId=isset($data['studentId'])?(int)$data['studentId']:null;$cId=isset($data['courseId'])?(int)$data['courseId']:null;if(!$sId||!$cId){send_response(false,'IDs obrigatórios.',400);}$conn->beginTransaction();try{$stmt=$conn->prepare("UPDATE enrollments SET status='Cancelada' WHERE studentId=? AND courseId=? AND status='Aprovada'");$stmt->execute([$sId,$cId]);if($stmt->rowCount()==0){throw new Exception('Matrícula não aprovada.');}$setStmt=$conn->query("SELECT enableTerminationFine,terminationFineMonths FROM system_settings WHERE id=1");$set=$setStmt->fetch(PDO::FETCH_ASSOC);$fineE=isset($set['enableTerminationFine'])&&$set['enableTerminationFine']==1;$fineM=isset($set['terminationFineMonths'])?(int)$set['terminationFineMonths']:0;$msg='Matrícula trancada.';$payStmt=$conn->prepare("SELECT id FROM payments WHERE studentId=? AND courseId=? AND status IN ('Pendente','Atrasado') ORDER BY dueDate ASC");$payStmt->execute([$sId,$cId]);$fPay=$payStmt->fetchAll(PDO::FETCH_COLUMN);$toCancel=[];if(count($fPay)>0){if($fineE&&$fineM>0){$toCancel=array_slice($fPay,$fineM);$kept=count($fPay)-count($toCancel);if($kept>0)$msg.=" Multa de {$kept} mensalidade(s) mantida.";if(!empty($toCancel))$msg.=" Demais cancelados.";}else{$toCancel=$fPay;$msg.=' Pagamentos futuros cancelados.';}if(!empty($toCancel)){$phs=implode(',',array_fill(0,count($toCancel),'?'));$canSql="UPDATE payments SET status='Cancelado',paymentDate=NULL WHERE id IN ($phs)";$canStmt=$conn->prepare($canSql);$canStmt->execute($toCancel);}}else{$msg.=' Sem pagamentos futuros.';}$conn->commit();send_response(true,['message'=>$msg]);}catch(Exception $e){$conn->rollBack();error_log("Erro trancar: ".$e->getMessage());send_response(false,'Erro trancar: '.$e->getMessage(),500);} }
function handle_reactivate_enrollment($conn, $data) { $sId=isset($data['studentId'])?(int)$data['studentId']:null;$cId=isset($data['courseId'])?(int)$data['courseId']:null;if(!$sId||!$cId){send_response(false,'IDs obrigatórios.',400);}$conn->beginTransaction();try{$stmt=$conn->prepare("UPDATE enrollments SET status='Aprovada' WHERE studentId=? AND courseId=? AND status='Cancelada'");$stmt->execute([$sId,$cId]);if($stmt->rowCount()==0){throw new Exception('Matrícula não cancelada.');}$payStmt=$conn->prepare("SELECT id,dueDate FROM payments WHERE studentId=? AND courseId=? AND status='Cancelado'");$payStmt->execute([$sId,$cId]);$canPay=$payStmt->fetchAll(PDO::FETCH_ASSOC);$today=new DateTime('now',new DateTimeZone('America/Sao_Paulo'));$today->setTime(0,0,0);foreach($canPay as $p){$due=new DateTime($p['dueDate'],new DateTimeZone('America/Sao_Paulo'));$due->setTime(0,0,0);$newS=($due<$today)?'Atrasado':'Pendente';$upP=$conn->prepare("UPDATE payments SET status=? WHERE id=?");$upP->execute([$newS,$p['id']]);}$conn->commit();send_response(true,['message'=>'Matrícula reativada. Pagamentos restaurados.']);}catch(Exception $e){$conn->rollBack();error_log("Erro reativar: ".$e->getMessage());send_response(false,'Erro reativar: '.$e->getMessage(),500);} }

// --- CORREÇÃO: handle_update_enrollment_details com timezone corrigido e customDueDay ---
function handle_update_enrollment_details($conn, $data) { 
    $sId = filter_var($data['studentId'], FILTER_VALIDATE_INT);
    $cId = filter_var($data['courseId'], FILTER_VALIDATE_INT);
    
    $sch = isset($data['scholarshipPercentage']) && is_numeric($data['scholarshipPercentage']) ? (float)$data['scholarshipPercentage'] : 0.0;
    if ($sch < 0 || $sch > 100) $sch = 0.0;
    
    $cFee = isset($data['customMonthlyFee']) && $data['customMonthlyFee'] !== '' && is_numeric($data['customMonthlyFee']) && (float)$data['customMonthlyFee'] >= 0 ? (float)$data['customMonthlyFee'] : null;
    
    // Validação do customDueDay
    $cDue = isset($data['customDueDay']) && is_numeric($data['customDueDay']) ? (int)$data['customDueDay'] : null;
    if ($cDue !== null && ($cDue < 1 || $cDue > 28)) $cDue = null; // Garante limite seguro

    if ($sId === false || $cId === false) { send_response(false, 'IDs inválidos.', 400); }
    
    $conn->beginTransaction();
    try {
        // Atualiza incluindo customDueDay
        $upStmt = $conn->prepare("UPDATE enrollments SET scholarshipPercentage=?, customMonthlyFee=?, customDueDay=? WHERE studentId=? AND courseId=?");
        $upStmt->execute([$sch, $cFee, $cDue, $sId, $cId]);
        
        // Remove pagamentos pendentes/atrasados antigos
        $delStmt = $conn->prepare("DELETE FROM payments WHERE studentId=? AND courseId=? AND status IN ('Pendente','Atrasado')");
        $delStmt->execute([$sId, $cId]);
        
        $stD = $conn->prepare("SELECT c.monthlyFee, c.paymentType, c.installments, e.billingStartDate FROM courses c JOIN enrollments e ON c.id=e.courseId WHERE e.studentId=? AND e.courseId=? AND e.status='Aprovada'");
        $stD->execute([$sId, $cId]);
        $d = $stD->fetch();
        
        if ($d) {
            $sysDueDay = $conn->query("SELECT defaultDueDay FROM system_settings WHERE id=1")->fetchColumn() ?? 10;
            
            // Define o dia efetivo: Personalizado > Sistema
            $effectiveDueDay = $cDue !== null ? $cDue : $sysDueDay;
            $effectiveDueDay = max(1, min(28, (int)$effectiveDueDay));
            
            if ($sch < 100) {
                $bA = $cFee !== null ? $cFee : ($d['monthlyFee'] ?? 0);
                $fA = round($bA * (1 - ($sch / 100)), 2);
                
                if ($fA > 0) {
                    $lastP = $conn->prepare("SELECT MAX(referenceDate) as lastDate FROM payments WHERE studentId=? AND courseId=? AND status='Pago'");
                    $lastP->execute([$sId, $cId]);
                    $lastPD = $lastP->fetchColumn();
                    $startD = null;
                    
                    if ($lastPD) {
                        $startD = new DateTime($lastPD, new DateTimeZone('America/Sao_Paulo'));
                        $startD->modify('first day of next month');
                    } elseif ($d['billingStartDate']) {
                        $startD = new DateTime($d['billingStartDate'], new DateTimeZone('America/Sao_Paulo'));
                        $startD->modify('first day of this month');
                        
                        $nowD = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
                        if ($startD < $nowD) {
                            $startD = $nowD;
                            $startD->modify('first day of this month');
                        }
                    }
                    
                    if ($startD) {
                        $paidC = $conn->prepare("SELECT COUNT(id) FROM payments WHERE studentId=? AND courseId=? AND status='Pago'");
                        $paidC->execute([$sId, $cId]);
                        $pMadeC = $paidC->fetchColumn();
                        $limit = 0;
                        
                        if ($d['paymentType'] === 'parcelado' && !empty($d['installments']) && $d['installments'] > 0) {
                            $rem = (int)$d['installments'] - $pMadeC;
                            $limit = max(0, $rem);
                        } else {
                            $limit = 12;
                        }
                        
                        $curD = clone $startD;
                        for ($i = 0; $i < $limit; $i++) {
                            $refD = $curD->format('Y-m-01');
                            $lastDay = (int)$curD->format('t');
                            $actDue = min($effectiveDueDay, $lastDay);
                            $dueD = $curD->format('Y-m-') . str_pad($actDue, 2, '0', STR_PAD_LEFT);
                            $insStmt = $conn->prepare("INSERT INTO payments(studentId,courseId,amount,referenceDate,dueDate,status)VALUES(?,?,?,?,?,'Pendente')");
                            $insStmt->execute([$sId, $cId, $fA, $refD, $dueD]);
                            $curD->modify('+1 month');
                        }
                    } else {
                        error_log("Falha startDate $sId,$cId");
                    }
                }
            }
        } 
        $conn->commit();
        send_response(true, ['message' => 'Detalhes atualizados. Pagamentos recalculados com novo vencimento.']);
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Erro updateEnrollDet: " . $e->getMessage());
        send_response(false, 'Erro ao atualizar: ' . $e->getMessage(), 500);
    } 
}

// --- REMATRÍCULA AUTOMÁTICA ---
function handle_submit_reenrollment(PDO $conn, $data) { $sId=isset($data['studentId'])?filter_var($data['studentId'],FILTER_VALIDATE_INT):0;$cId=isset($data['courseId'])?filter_var($data['courseId'],FILTER_VALIDATE_INT):0;$eData=$data['enrollmentData']??[];if($sId<=0||$cId<=0){send_response(false,'IDs inválidos.',400);}if(empty($eData['acceptContract'])){send_response(false,'Aceite o Contrato.',400);}$conn->beginTransaction();try{$stC=$conn->prepare("SELECT id FROM enrollments WHERE studentId=? AND courseId=? AND status='Aprovada'");$stC->execute([$sId,$cId]);$eId=$stC->fetchColumn();if(!$eId){throw new Exception('Matrícula não ativa.');}$now=date('Y-m-d H:i:s');$cA=$now;$tA=!empty($eData['acceptImageTerms'])?$now:null;$upE=$conn->prepare("UPDATE enrollments SET contractAcceptedAt=?,termsAcceptedAt=? WHERE id=?");$upE->execute([$cA,$tA,$eId]);$delS=$conn->prepare("DELETE FROM payments WHERE studentId=? AND courseId=? AND status IN('Pendente','Atrasado')");$delS->execute([$sId,$cId]);$delC=$delS->rowCount();$stD=$conn->prepare("SELECT c.monthlyFee,c.paymentType,c.installments,e.customMonthlyFee,e.scholarshipPercentage FROM courses c JOIN enrollments e ON c.id=e.courseId WHERE e.id=?");$stD->execute([$eId]);$d=$stD->fetch();$stS=$conn->query("SELECT defaultDueDay FROM system_settings WHERE id=1");$set=$stS->fetch();$dueD=isset($set['defaultDueDay'])?max(1,min(28,(int)$set['defaultDueDay'])):10;$genC=0;if($d&&($d['monthlyFee']>0||$d['customMonthlyFee']!==null)&&($d['scholarshipPercentage']===null||$d['scholarshipPercentage']<100)){$bA=$d['customMonthlyFee']!==null?$d['customMonthlyFee']:$d['monthlyFee'];$sch=$d['scholarshipPercentage']??0;$fA=round($bA*(1-($sch/100)),2);if($fA>0){$lastP=$conn->prepare("SELECT MAX(referenceDate) as lastDate FROM payments WHERE studentId=? AND courseId=?");$lastP->execute([$sId,$cId]);$lastPD=$lastP->fetchColumn();$startD=new DateTime('now',new DateTimeZone('America/Sao_Paulo'));if($lastPD){$startD=new DateTime($lastPD,new DateTimeZone('America/Sao_Paulo'));$startD->modify('first day of next month');}else{$bS=$conn->prepare("SELECT billingStartDate FROM enrollments WHERE id=?");$bS->execute([$eId]);$bSD=$bS->fetchColumn();if($bSD){$startD=new DateTime($bSD,new DateTimeZone('America/Sao_Paulo'));$startD->modify('first day of this month');$nowD=new DateTime('now',new DateTimeZone('America/Sao_Paulo'));if($startD<$nowD){$startD=$nowD;$startD->modify('first day of this month');}}else{$startD->modify('first day of this month');}}$limit=0;if($d['paymentType']==='parcelado'&&!empty($d['installments'])&&$d['installments']>0){$pC=$conn->prepare("SELECT COUNT(id) FROM payments WHERE studentId=? AND courseId=? AND status='Pago'");$pC->execute([$sId,$cId]);$pMadeC=$pC->fetchColumn();$limit=max(0,(int)$d['installments']-$pMadeC);}else{$limit=12;}$curD=clone $startD;for($i=0;$i<$limit;$i++){$refD=$curD->format('Y-m-01');$lastDay=(int)$curD->format('t');$actDue=min($dueD,$lastDay);$dueD=$curD->format('Y-m-').str_pad($actDue,2,'0',STR_PAD_LEFT);$insStmt=$conn->prepare("INSERT INTO payments(studentId,courseId,amount,referenceDate,dueDate,status)VALUES(?,?,?,?,?,'Pendente')");$insStmt->execute([$sId,$cId,$fA,$refD,$dueD]);$genC++;$curD->modify('+1 month');}}}$conn->commit();send_response(true,['message'=>"Rematrícula confirmada! ".($genC>0?"$genC pagamentos gerados.":"Nenhum gerado.").($delC>0?" $delC anteriores removidos.":"")]);}catch(Exception $e){$conn->rollBack();error_log("Erro submitReenr: ".$e->getMessage());send_response(false,'Erro rematrícula: '.$e->getMessage(),500);} }


// --- FUNÇÃO CORRIGIDA: BUSCAR DADOS PARA O MODAL DE MATRÍCULA ---
function handle_get_enrollment_documents(PDO $conn, $data) {
    $studentId = $data['studentId'] ?? 0;
    $courseId = $data['courseId'] ?? 0;

    if ($studentId <= 0 || $courseId <= 0) {
        send_response(false, ['message' => 'IDs de estudante ou curso inválidos.'], 400);
    }

    try {
        // 1. Busca todos os detalhes necessários, incluindo os modelos de texto
        $details = get_document_details($conn, $studentId, $courseId);

        if (!$details) {
            throw new Exception("Dados de matrícula/curso não encontrados.");
        }

        // 2. Monta o array de placeholders (chave => valor)
        $placeholders = get_placeholders($details);
        
        // 3. Obtém os textos crus do contrato e termo
        $rawContractText = $details['enrollmentContractText'] ?? 'Modelo de contrato padrão não configurado no sistema.';
        $rawTermsText = $details['imageTermsText'] ?? 'Modelo de termo de imagem padrão não configurado no sistema.';

        // 4. SUBSTITUIÇÃO DOS PLACEHOLDERS
        $contractText = str_replace(array_keys($placeholders), array_values($placeholders), $rawContractText);
        $termsText = str_replace(array_keys($placeholders), array_values($placeholders), $rawTermsText);

        // 5. Separa os dados de aluno/responsável para o frontend
        $isMinor = ($details['age'] !== null && (int)$details['age'] < 18);
        
        $studentData = [
            'rg' => $details['aluno_rg'],
            'cpf' => $details['aluno_cpf']
        ];
        
        $guardianData = [
            'name' => $details['guardianName'],
            'email' => $details['guardianEmail'],
            'phone' => $details['guardianPhone'],
            'rg' => $details['guardianRG'],
            'cpf' => $details['guardianCPF']
        ];


        // 6. Envia a resposta formatada que o modal espera
        send_response(true, [
            'studentData' => $studentData,
            'guardianData' => $guardianData,
            'isMinor' => $isMinor,
            'contractText' => nl2br(htmlspecialchars($contractText)), 
            'termsText' => nl2br(htmlspecialchars($termsText))
        ]);

    } catch (Exception $e) {
        error_log("Erro em handle_get_enrollment_documents (Aluno $studentId): " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao buscar dados da matrícula: ' . $e->getMessage()], 500);
    }
}
?>