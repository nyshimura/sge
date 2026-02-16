<?php
// admin/enrollment_form.php

// 1. INICIALIZAÇÃO E BUFFER
ob_start();
session_start();

// CAMINHOS SEGUROS
require_once __DIR__ . '/../config/database.php'; 
require_once __DIR__ . '/../includes/enrollment_logic.php'; 

// Debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ======================================================
// 2. BUSCAR CONFIGURAÇÃO
// ======================================================
$fineMonths = 0;
try {
    $sysStmt = $pdo->query("SELECT terminationFineMonths FROM system_settings WHERE id = 1 LIMIT 1");
    $sysConfig = $sysStmt->fetch(PDO::FETCH_ASSOC);
    if ($sysConfig && isset($sysConfig['terminationFineMonths'])) {
        $fineMonths = (int)$sysConfig['terminationFineMonths'];
    }
} catch (Exception $ex) { $fineMonths = 0; }

$studentId = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
$courseId = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
$isEdit = ($studentId && $courseId);

// ======================================================
// 3. PROCESSAMENTO (POST)
// ======================================================

// A: CERTIFICADOS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cert_action'])) {
    $c_sid = (int)$_POST['studentId'];
    $c_cid = (int)$_POST['courseId'];
    try {
        if ($_POST['cert_action'] === 'generate') {
            $hash = hash('sha256', uniqid($c_sid . $c_cid . microtime(), true));
            $customLoad = !empty($_POST['custom_workload']) ? $_POST['custom_workload'] : null;
            $stmt = $pdo->prepare("INSERT INTO certificates (student_id, course_id, verification_hash, completion_date, generated_at, custom_workload) VALUES (?, ?, ?, NOW(), NOW(), ?)");
            $stmt->execute([$c_sid, $c_cid, $hash, $customLoad]);
            $msgType = 'cert_created';
        } 
        elseif ($_POST['cert_action'] === 'revoke') {
            $certId = (int)$_POST['cert_id'];
            $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
            $stmt->execute([$certId]);
            $msgType = 'cert_revoked';
        }
        header("Location: enrollment_form.php?sid=$c_sid&cid=$c_cid&msg=$msgType");
        exit;
    } catch (Exception $e) { $error = "Erro certificado: " . $e->getMessage(); }
}

// B: AÇÕES DE PAGAMENTO (MANUAL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_action'])) {
    $p_sid = (int)$_POST['studentId'];
    $p_cid = (int)$_POST['courseId'];
    $idsRaw = $_POST['payment_ids'] ?? ''; 
    $action = $_POST['payment_action'];

    if (!empty($idsRaw)) {
        try {
            $idsArray = array_map('intval', explode(',', $idsRaw));
            $inQuery = implode(',', $idsArray);
            
            // BAIXA MANUAL
            if ($action === 'bulk_pay') {
                $sql = "UPDATE payments SET status = 'Pago', paymentDate = NOW() WHERE id IN ($inQuery) AND studentId = :sid AND courseId = :cid";
                $msg = 'payments_updated';
            } 
            // REVERTER PARA PENDENTE
            elseif ($action === 'revert_pay') {
                $sql = "UPDATE payments SET status = 'Pendente', paymentDate = NULL WHERE id IN ($inQuery) AND studentId = :sid AND courseId = :cid";
                $msg = 'payments_reverted';
            }
            // CANCELAR PAGAMENTO ESPECÍFICO (Mantém no histórico como 'Cancelado')
            elseif ($action === 'cancel_pay') {
                // OBS: Usando 'Cancelado' com 'o' conforme sua imagem do banco
                $sql = "UPDATE payments SET status = 'Cancelado', paymentDate = NULL WHERE id IN ($inQuery) AND studentId = :sid AND courseId = :cid";
                $msg = 'payments_updated';
            }
            
            if (isset($sql)) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':sid' => $p_sid, ':cid' => $p_cid]);
                header("Location: enrollment_form.php?sid=$p_sid&cid=$p_cid&msg=$msg");
                exit;
            }
        } catch (Exception $e) { $error = "Erro financeiro: " . $e->getMessage(); }
    }
}

// C: RESETAR TERMO DE COMPROMISSO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_term_response') {
    $termId = (int)$_POST['term_id'];
    $r_sid = (int)$_POST['studentId'];
    $r_cid = (int)$_POST['courseId'];
    
    $stmtDel = $pdo->prepare("DELETE FROM event_term_responses WHERE term_id = ? AND studentId = ?");
    $stmtDel->execute([$termId, $r_sid]);
    
    header("Location: enrollment_form.php?sid=$r_sid&cid=$r_cid&msg=term_reset");
    exit;
}

// D: PROCESSAMENTO DA MATRÍCULA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['cert_action']) && !isset($_POST['payment_action']) && !isset($_POST['action'])) {
    $sid = (int)$_POST['studentId'];
    $cid = (int)$_POST['courseId'];
    $status = $_POST['status'];
    
    $scholarship = isset($_POST['scholarshipPercentage']) ? (float)str_replace(',', '.', $_POST['scholarshipPercentage']) : 0.0;
    $inputFee = isset($_POST['customMonthlyFee']) ? (float)str_replace(',', '.', $_POST['customMonthlyFee']) : 0.0;
    
    $dueDay = !empty($_POST['customDueDay']) ? (int)$_POST['customDueDay'] : 10;
    $startDay = !empty($_POST['billingStartDate']) ? $_POST['billingStartDate'] : date('Y-m-d');
    
    $stmtC = $pdo->prepare("SELECT monthlyFee FROM courses WHERE id = :id");
    $stmtC->execute([':id' => $cid]);
    $courseData = $stmtC->fetch();
    $baseFee = (float)$courseData['monthlyFee'];

    $finalFee = $baseFee;
    $customFeeToSave = null; 

    if ($inputFee >= 0) {
        $finalFee = $inputFee;
        $customFeeToSave = $finalFee;
        if ($baseFee > 0) {
            $scholarship = (($baseFee - $finalFee) / $baseFee) * 100;
        } else {
            $scholarship = 0;
        }
    }

    try {
        $pdo->beginTransaction();

        if ($isEdit) {
            $sql = "UPDATE enrollments SET status=:st, scholarshipPercentage=:sc, customMonthlyFee=:fe, customDueDay=:du, billingStartDate=:bi WHERE studentId=:s AND courseId=:c";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':st'=>$status, ':sc'=>$scholarship, ':fe'=>$customFeeToSave, ':du'=>$dueDay, ':bi'=>$startDay, ':s'=>$sid, ':c'=>$cid]);
            $msgType = ($status == 'Cancelada') ? 'canceled_fine' : 'updated';
        } else {
            $chk = $pdo->prepare("SELECT studentId FROM enrollments WHERE studentId=:s AND courseId=:c");
            $chk->execute([':s'=>$sid, ':c'=>$cid]);
            if ($chk->rowCount() > 0) throw new Exception("Aluno já matriculado.");

            $sql = "INSERT INTO enrollments (studentId, courseId, status, scholarshipPercentage, customMonthlyFee, customDueDay, billingStartDate, enrollmentDate) VALUES (:s, :c, :st, :sc, :fe, :du, :bi, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':s'=>$sid, ':c'=>$cid, ':st'=>$status, ':sc'=>$scholarship, ':fe'=>$customFeeToSave, ':du'=>$dueDay, ':bi'=>$startDay]);
            $msgType = 'created';
        }

        // LÓGICA DE CANCELAMENTO (Chama função externa)
        if ($status === 'Cancelada') {
            cancelarMatricula($pdo, $sid, $cid, $finalFee);
        }
        elseif ($status == 'Aprovada') {
            if ($finalFee > 0.01) {
                reativarMatricula($pdo, $sid, $cid, $finalFee, $dueDay, $startDay);
            } else {
                $primeiroDiaMesAtual = date('Y-m-01');
                $dataCorte = ($startDay > $primeiroDiaMesAtual) ? $startDay : $primeiroDiaMesAtual;
                $del = $pdo->prepare("DELETE FROM payments WHERE studentId=:s AND courseId=:c AND status='Pendente' AND dueDate >= :cutoff");
                $del->execute([':s'=>$sid, ':c'=>$cid, ':cutoff'=>$dataCorte]);
            }
        }
        elseif ($status === 'Pendente') {
            $primeiroDiaMesAtual = date('Y-m-01');
            $del = $pdo->prepare("DELETE FROM payments WHERE studentId=:s AND courseId=:c AND status='Pendente' AND dueDate >= :cutoff");
            $del->execute([':s'=>$sid, ':c'=>$cid, ':cutoff'=>$primeiroDiaMesAtual]);
        }

        $pdo->commit();
        header("Location: enrollment_form.php?sid=$sid&cid=$cid&msg=$msgType");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Erro ao salvar: " . $e->getMessage();
    }
}

// ======================================================
// 4. DADOS PARA VIEW
// ======================================================

$courses = $pdo->query("SELECT * FROM courses ORDER BY name ASC")->fetchAll();
$students = $pdo->query("SELECT id, firstName, lastName, cpf FROM users ORDER BY firstName ASC")->fetchAll();

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM enrollments WHERE studentId=:s AND courseId=:c");
    $stmt->execute([':s'=>$studentId, ':c'=>$courseId]);
    $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$enrollment) { header("Location: enrollments.php?error=not_found"); exit; }

    $stmtPay = $pdo->prepare("SELECT * FROM payments WHERE studentId=:s AND courseId=:c ORDER BY dueDate ASC");
    $stmtPay->execute([':s'=>$studentId, ':c'=>$courseId]);
    $paymentHistory = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

    $stmtCert = $pdo->prepare("SELECT * FROM certificates WHERE student_id = :s AND course_id = :c ORDER BY generated_at DESC");
    $stmtCert->execute([':s'=>$studentId, ':c'=>$courseId]);
    $certificates = $stmtCert->fetchAll(PDO::FETCH_ASSOC);
    
    // BUSCA TERMOS
    $stmtEvt = $pdo->prepare("
        SELECT t.id, t.title, t.created_at, r.status as response_status, r.responded_at
        FROM event_terms t
        LEFT JOIN event_term_responses r ON t.id = r.term_id AND r.studentId = :sid
        WHERE t.courseId = :cid
        ORDER BY t.created_at DESC
    ");
    $stmtEvt->execute([':sid' => $studentId, ':cid' => $courseId]);
    $studentEvents = $stmtEvt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pega preço base
    $stmtC = $pdo->prepare("SELECT monthlyFee FROM courses WHERE id = :id");
    $stmtC->execute([':id' => $courseId]);
    $currentCourseData = $stmtC->fetch();
    $hiddenBasePrice = $currentCourseData['monthlyFee'];
}

$pageTitle = "Editar/Nova Matrícula";
include '../includes/admin_header.php';
checkRole(['admin', 'superadmin']);
?>

<div id="toast" class="toast">Salvo com sucesso!</div>

<div class="content-wrapper">
    
    <div class="card-box" style="max-width: 800px; margin: 0 auto 30px auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2><?php echo $isEdit ? 'Editar Matrícula' : 'Nova Matrícula'; ?></h2>
            <a href="enrollments.php" class="btn-back" ><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>

        <?php if (isset($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if (isset($_GET['msg'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var m = "<?php echo $_GET['msg']; ?>";
                    var msgText = "Operação realizada!";
                    if(m == 'updated') msgText = 'Dados atualizados!';
                    if(m == 'created') msgText = 'Matrícula criada!';
                    if(m == 'term_reset') msgText = 'Termo reativado para o aluno!';
                    showToast(msgText);
                });
            </script>
        <?php endif; ?>

        <form method="POST" id="mainEnrollmentForm">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <label>Aluno</label>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="studentId" value="<?php echo $studentId; ?>">
                        <select class="form-control" disabled>
                            <?php foreach ($students as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo $studentId == $s['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['firstName'].' '.$s['lastName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <select name="studentId" class="form-control" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($students as $s): ?>
                                <option value="<?php echo $s['id']; ?>">
                                    <?php echo htmlspecialchars($s['firstName'].' '.$s['lastName'].' (CPF: '.$s['cpf'].')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div>
                    <label>Curso</label>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="courseId" value="<?php echo $courseId; ?>">
                        <select id="courseSelect" class="form-control" disabled>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo $c['id']; ?>" data-price="<?php echo $c['monthlyFee']; ?>" <?php echo $courseId == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <select name="courseId" id="courseSelect" class="form-control" required onchange="updateBasePrice()">
                            <option value="" data-price="0">Selecione...</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?php echo $c['id']; ?>" data-price="<?php echo $c['monthlyFee']; ?>">
                                    <?php echo htmlspecialchars($c['name']); ?> (R$ <?php echo number_format($c['monthlyFee'], 2, ',', '.'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <input type="hidden" id="hiddenBasePrice" value="<?php echo $isEdit ? $hiddenBasePrice : '0'; ?>">
                </div>
            </div>

            <div class="divider"></div>

            <h4 style="margin-bottom: 15px; color: #555;">Financeiro e Status</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <label>Bolsa (%)</label>
                    <div style="display:flex; align-items:center;">
                        <input type="number" step="0.01" min="0" max="100" name="scholarshipPercentage" id="scholarshipInput" class="form-control" 
                               value="<?php echo $enrollment['scholarshipPercentage'] ?? '0'; ?>" 
                               oninput="calculateFromScholarship()"> <span style="margin-left:5px;">%</span>
                    </div>
                </div>
                <div>
                    <label>Valor Mensal (R$)</label>
                    <input type="number" step="0.01" name="customMonthlyFee" id="feeInput" class="form-control" 
                           value="<?php echo $enrollment['customMonthlyFee'] ?? ''; ?>" 
                           style="background-color: #fff; font-weight: bold;"
                           oninput="calculateFromFee()"> </div>
                <div>
                    <label>Dia Vencimento</label>
                    <input type="number" min="1" max="31" name="customDueDay" class="form-control" value="<?php echo $enrollment['customDueDay'] ?? ''; ?>" placeholder="10">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <label>Início Cobrança</label>
                    <input type="date" name="billingStartDate" class="form-control" value="<?php echo $enrollment['billingStartDate'] ?? date('Y-m-d'); ?>">
                </div>
                <div>
                    <label>Status</label>
                    <select name="status" id="statusSelect" class="form-control">
                        <?php 
                            $st = $enrollment['status'] ?? 'Pendente'; 
                            $opts = ['Pendente', 'Aprovada', 'Cancelada', 'Concluido'];
                            foreach($opts as $opt) {
                                $sel = ($st == $opt) ? 'selected' : '';
                                echo "<option value='$opt' $sel>$opt</option>";
                            }
                        ?>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 30px;">
                <?php if($isEdit): ?>
                <button type="button" class="btn-cancel-enrollment" onclick="triggerTrashAction()">
                    <i class="fas fa-trash"></i> Cancelar Matrícula
                </button>
                <?php else: ?>
                <div></div>
                <?php endif; ?>

                <button type="submit" class="btn-primary" style="padding: 12px 30px;">Salvar Alterações</button>
            </div>
        </form>
    </div>

    <?php if ($isEdit): ?>
        <div class="card-box" style="max-width: 800px; margin: 0 auto 30px auto;">
            <h4 style="margin-bottom: 20px; color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-file-contract"></i> Contratos e Termos
            </h4>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px;">
                <div class="doc-card <?php echo !empty($enrollment['contractAcceptedAt']) ? 'doc-signed' : ''; ?>">
                    <div class="doc-icon"><i class="fas fa-file-signature"></i></div>
                    <div class="doc-info">
                        <h5>Contrato</h5>
                        <?php if(!empty($enrollment['contractAcceptedAt'])): ?>
                            <span class="status-ok">Assinado em <?php echo date('d/m/Y', strtotime($enrollment['contractAcceptedAt'])); ?></span>
                            <a href="../includes/generate_contract_pdf.php?cid=<?php echo $courseId; ?>&sid=<?php echo $studentId; ?>&type=contract" target="_blank" class="btn-doc">Baixar PDF</a>
                        <?php else: ?>
                            <span class="status-pend">Pendente</span>
                            <small>Aguardando aceite do aluno.</small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="doc-card <?php echo !empty($enrollment['termsAcceptedAt']) ? 'doc-signed' : ''; ?>">
                    <div class="doc-icon"><i class="fas fa-camera"></i></div>
                    <div class="doc-info">
                        <h5>Termo de Imagem</h5>
                        <?php if(!empty($enrollment['termsAcceptedAt'])): ?>
                            <span class="status-ok">Aceito em <?php echo date('d/m/Y', strtotime($enrollment['termsAcceptedAt'])); ?></span>
                            <a href="../includes/generate_contract_pdf.php?cid=<?php echo $courseId; ?>&sid=<?php echo $studentId; ?>&type=terms" target="_blank" class="btn-doc">Baixar PDF</a>
                        <?php else: ?>
                            <span class="status-pend">Pendente</span>
                            <small>Aguardando aceite do aluno.</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <hr style="border:0; border-top:1px solid #eee; margin-bottom: 15px;">
            <h5 style="color:#555; margin-bottom:15px;">Termos de Compromisso (Eventos/Turnês)</h5>
            
            <?php if(empty($studentEvents)): ?>
                <p style="color:#999; font-style:italic; text-align:center;">Nenhum termo extra criado para este curso.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Evento</th>
                                <th>Status do Aluno</th>
                                <th>Data Resposta</th>
                                <th style="text-align:right;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($studentEvents as $evt): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($evt['title']); ?></strong><br>
                                        <small style="color:#999;">Criado em: <?php echo date('d/m/Y', strtotime($evt['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if($evt['response_status'] == 'accepted'): ?>
                                            <span class="badge badge-success"><i class="fas fa-check"></i> Aceito</span>
                                        <?php elseif($evt['response_status'] == 'declined'): ?>
                                            <span class="badge badge-danger"><i class="fas fa-times"></i> Recusado</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><i class="fas fa-clock"></i> Pendente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $evt['responded_at'] ? date('d/m/Y H:i', strtotime($evt['responded_at'])) : '-'; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if($evt['response_status']): ?>
                                            <button type="button" class="btn-revert" style="font-size:0.8rem; padding:4px 8px;" title="Reenviar Notificação / Resetar" 
                                                onclick="openResetTermModal(<?php echo $evt['id']; ?>)">
                                                <i class="fas fa-redo"></i> Reativar
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#ccc; font-size:0.8rem;">Aguardando...</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        </div>

        <div class="card-box" style="max-width: 800px; margin: 0 auto 30px auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h4 style="margin: 0; color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-award"></i> Certificados
                </h4>
                <button type="button" class="btn-issue" onclick="openCertModal('generate')">
                    <i class="fas fa-plus"></i> Emitir Novo Certificado
                </button>
            </div>
            <?php if (!empty($certificates)): ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead><tr><th>Data Emissão</th><th>Código Validação</th><th>Carga Horária</th><th style="text-align: right;">Ações</th></tr></thead>
                        <tbody>
                            <?php foreach ($certificates as $cert): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($cert['generated_at'])); ?></td>
                                <td><code style="background:#eee; padding:2px 5px; border-radius:3px;"><?php echo substr($cert['verification_hash'], 0, 16) . '...'; ?></code></td>
                                <td>
                                    <?php if(!empty($cert['custom_workload'])): ?>
                                        <span style="font-weight:bold; color:#d35400;"><?php echo htmlspecialchars($cert['custom_workload']); ?> Horas</span>
                                    <?php else: ?>
                                        <span style="color: #555;"><?php echo htmlspecialchars($standardWorkload ?? '0'); ?> Horas</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="../includes/generate_certificate_pdf.php?hash=<?php echo $cert['verification_hash']; ?>" target="_blank" class="btn-doc" title="Baixar PDF"><i class="fas fa-download"></i></a>
                                    <button type="button" class="btn-revoke" onclick="openCertModal('revoke', <?php echo $cert['id']; ?>)" title="Revogar/Excluir"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 30px; color: #999; border: 1px dashed #ddd; border-radius: 8px;">Nenhum certificado emitido.</div>
            <?php endif; ?>
        </div>

        <div class="card-box" style="max-width: 800px; margin: 0 auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h4 style="margin: 0; color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-money-bill-wave"></i> Histórico Financeiro
                </h4>
                <div class="btn-action-group">
                    <button type="button" class="btn-pay" onclick="openPayModal('pay')">Baixar</button>
                    <button type="button" class="btn-revert" onclick="openPayModal('revert')">Reverter</button>
                    <button type="button" class="btn-cancel-pay" onclick="openPayModal('cancel')">Cancelar</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead><tr><th width="40"><input type="checkbox" id="selectAllPay" onclick="toggleSelectAll()"></th><th>Vencimento</th><th>Referência</th><th>Valor</th><th>Status</th><th style="text-align: right;">Pagamento</th></tr></thead>
                    <tbody id="paymentTableBody">
                        <?php if (!empty($paymentHistory)): ?>
                            <?php foreach ($paymentHistory as $pay): 
                                $statusClass = ''; 
                                // Ajuste para status conforme imagem do banco
                                switch($pay['status']) { 
                                    case 'Pago': $statusClass = 'badge-success'; break; 
                                    case 'Cancelado': // Usando 'Cancelado' com 'o'
                                    case 'Cancelada': // Mantendo compatibilidade
                                        $statusClass = 'badge-secondary'; break; 
                                    case 'Pendente': $statusClass = 'badge-warning'; break; 
                                    case 'Atrasado': $statusClass = 'badge-danger'; break; 
                                }
                            ?>
                            <tr class="<?php echo $pay['status'] == 'Pago' ? 'row-paid' : 'row-pending'; ?>">
                                <td><input type="checkbox" class="pay-checkbox" value="<?php echo $pay['id']; ?>"></td>
                                <td><strong><?php echo date('d/m/Y', strtotime($pay['dueDate'])); ?></strong></td>
                                <td><small class="text-muted"><?php echo date('m/Y', strtotime($pay['referenceDate'])); ?></small></td>
                                <td style="font-weight:600;">R$ <?php echo number_format($pay['amount'], 2, ',', '.'); ?></td>
                                <td><span class="badge <?php echo $statusClass; ?>"><?php echo $pay['status']; ?></span></td>
                                <td style="text-align: right;"><?php echo $pay['paymentDate'] ? date('d/m/Y', strtotime($pay['paymentDate'])) : '---'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="cancelFineModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card" style="border-top: 4px solid #c0392b;">
        <div class="modal-icon-wrapper" style="background-color: #fdedec;"><i class="fas fa-exclamation-triangle" style="color: #c0392b;"></i></div>
        <h3 class="modal-h3">Cancelar Matrícula?</h3>
        <p class="modal-p" id="fineWarningText"></p>
        <div class="modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeCancelModal()">Voltar</button>
            <button type="button" class="btn-modal-confirm active-danger" onclick="confirmAndSubmitCancel()">Sim, Cancelar</button>
        </div>
    </div>
</div>

<div id="payModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card">
        <div class="modal-icon-wrapper" id="payModalIconWrapper"><i id="payModalIcon" class="fas fa-money-bill-wave"></i></div>
        <h3 class="modal-h3" id="payModalTitle">Título</h3>
        <p class="modal-p" id="payModalText">Texto</p>
        <form method="POST" id="payForm">
            <input type="hidden" name="payment_action" id="paymentActionInput">
            <input type="hidden" name="studentId" value="<?php echo $studentId; ?>">
            <input type="hidden" name="courseId" value="<?php echo $courseId; ?>">
            <input type="hidden" name="payment_ids" id="paymentIdsInput">
            <div class="modal-check-wrapper"><input type="checkbox" id="checkPayConfirm"><label for="checkPayConfirm">Confirmar ação.</label></div>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="document.getElementById('payModal').style.display='none'">Cancelar</button>
                <button type="submit" class="btn-modal-confirm" id="btnPayConfirm" disabled>Confirmar</button>
            </div>
        </form>
    </div>
</div>

<div id="certModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card">
        <div class="modal-icon-wrapper" id="modalIconWrapper"><i class="fas fa-award" id="modalIcon"></i></div>
        <h3 class="modal-h3" id="modalTitle">Título</h3>
        <p class="modal-p" id="modalText">Texto descritivo</p>
        <form method="POST" id="certForm">
            <input type="hidden" name="cert_action" id="certActionInput">
            <input type="hidden" name="studentId" value="<?php echo $studentId; ?>">
            <input type="hidden" name="courseId" value="<?php echo $courseId; ?>">
            <input type="hidden" name="cert_id" id="certIdInput" value="0">
            <div id="customWorkloadField" style="margin-bottom: 20px; text-align: left; display: none;">
                <label>Carga Horária Personalizada</label>
                <input type="text" name="custom_workload" class="form-control" placeholder="Ex: 120">
            </div>
            <div class="modal-check-wrapper"><input type="checkbox" id="checkCertConfirm"><label for="checkCertConfirm">Estou ciente.</label></div>
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeCertModal()">Cancelar</button>
                <button type="submit" class="btn-modal-confirm" id="btnCertConfirm" disabled>Confirmar</button>
            </div>
        </form>
    </div>
</div>

<div id="resetTermModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card" style="border-top: 4px solid #17a2b8;">
        <div class="modal-icon-wrapper" style="background-color: #e0f7fa;"><i class="fas fa-redo" style="color: #17a2b8;"></i></div>
        <h3 class="modal-h3">Reativar Notificação?</h3>
        <p class="modal-p">Isso apagará a resposta atual (Aceite ou Recusa). O termo aparecerá novamente no painel do aluno como PENDENTE.</p>
        <form method="POST">
            <input type="hidden" name="action" value="reset_term_response">
            <input type="hidden" name="term_id" id="resetTermIdInput">
            <input type="hidden" name="studentId" value="<?php echo $studentId; ?>">
            <input type="hidden" name="courseId" value="<?php echo $courseId; ?>">
            
            <div class="modal-check-wrapper">
                <input type="checkbox" id="checkResetConfirm">
                <label for="checkResetConfirm">Estou ciente.</label>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeResetTermModal()">Cancelar</button>
                <button type="submit" class="btn-modal-confirm" id="btnResetConfirm" disabled style="background-color: #17a2b8;">Confirmar</button>
            </div>
        </form>
    </div>
</div>

<script>
    const fineMonthsConfig = <?php echo $fineMonths; ?>; 

    // --- CÁLCULO DE VALORES EM TEMPO REAL ---
    function getBasePrice() {
        const select = document.getElementById('courseSelect');
        if (select.disabled) {
            return parseFloat(document.getElementById('hiddenBasePrice').value) || 0;
        }
        if (select.selectedIndex !== -1) {
            return parseFloat(select.options[select.selectedIndex].getAttribute('data-price')) || 0;
        }
        return 0;
    }

    function calculateFromScholarship() {
        const basePrice = getBasePrice();
        let scholarStr = document.getElementById('scholarshipInput').value;
        scholarStr = scholarStr.toString().replace(',', '.');
        const scholarship = parseFloat(scholarStr) || 0;
        
        let finalPrice = basePrice;
        if (scholarship > 0) {
            const discount = basePrice * (scholarship / 100);
            finalPrice = Math.max(0, basePrice - discount);
        }
        document.getElementById('feeInput').value = finalPrice.toFixed(2);
    }

    function calculateFromFee() {
        const basePrice = getBasePrice();
        if (basePrice <= 0) return; 

        let feeStr = document.getElementById('feeInput').value;
        feeStr = feeStr.toString().replace(',', '.');
        const fee = parseFloat(feeStr) || 0;

        let scholarship = 0;
        if (fee < basePrice) {
            scholarship = ((basePrice - fee) / basePrice) * 100;
        }
        document.getElementById('scholarshipInput').value = scholarship.toFixed(2);
    }

    function updateBasePrice() { calculateFromScholarship(); }

    window.addEventListener('DOMContentLoaded', () => {
        const select = document.getElementById('courseSelect');
        if (select.disabled) {
            const option = select.querySelector('option[selected]');
            if (option) document.getElementById('hiddenBasePrice').value = option.getAttribute('data-price');
        }
    });

    // --- TOAST FEEDBACK ---
    function showToast(message) {
        var toast = document.getElementById("toast");
        toast.innerText = message;
        toast.className = "toast show";
        setTimeout(function(){ toast.className = toast.className.replace("show", ""); }, 3000);
    }

    // --- MODAIS ---
    
    document.getElementById('mainEnrollmentForm').addEventListener('submit', function(event) {
        const select = document.getElementById('statusSelect');
        if (select.value === 'Cancelada') {
            event.preventDefault(); 
            openCancelModal(); 
        }
    });

    function triggerTrashAction() {
        const select = document.getElementById('statusSelect');
        select.value = 'Cancelada'; 
        openCancelModal(); 
    }

    function openCancelModal() {
        const modal = document.getElementById('cancelFineModal');
        const text = document.getElementById('fineWarningText');
        const monthlyFee = parseFloat(document.getElementById('feeInput').value) || 0;
        
        let msg = "Tem certeza que deseja cancelar? Isso removerá pendências futuras.";
        if (fineMonthsConfig > 0 && monthlyFee > 0.01) {
            const totalFine = (monthlyFee * fineMonthsConfig).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            msg += `<br><br><strong>⚠️ MULTA:</strong> Será gerada multa de <strong>${fineMonthsConfig} meses</strong>.<br>Valor: <strong style="color: #c0392b;">${totalFine}</strong>`;
        }
        text.innerHTML = msg;
        modal.style.display = 'flex';
    }

    function closeCancelModal() { document.getElementById('cancelFineModal').style.display = 'none'; }
    function confirmAndSubmitCancel() { document.getElementById('mainEnrollmentForm').submit(); }

    // --- Pagamentos ---
    const btnPayConfirm = document.getElementById('btnPayConfirm');
    const checkPayConfirm = document.getElementById('checkPayConfirm');
    const paymentActionInput = document.getElementById('paymentActionInput');

    function toggleSelectAll() {
        const master = document.getElementById('selectAllPay');
        document.querySelectorAll('.pay-checkbox').forEach(box => box.checked = master.checked);
    }

    function openPayModal(action) {
        const selected = document.querySelectorAll('.pay-checkbox:checked');
        if(selected.length === 0) { alert("Selecione um item."); return; }
        
        document.getElementById('paymentIdsInput').value = Array.from(selected).map(cb => cb.value).join(',');
        checkPayConfirm.checked = false;
        btnPayConfirm.disabled = true;
        btnPayConfirm.classList.remove('active');
        btnPayConfirm.classList.remove('active-danger');

        // Ícones e cores padrão
        document.getElementById('payModalIcon').className = "fas fa-money-bill-wave";
        document.getElementById('payModalIconWrapper').style.background = "#e3f2fd";
        document.getElementById('payModalIcon').style.color = "#3498db";

        if (action === 'pay') {
            document.getElementById('payModalTitle').innerText = "Baixar Pagamentos?";
            document.getElementById('payModalText').innerText = "Confirmar baixa dos pagamentos selecionados?";
            btnPayConfirm.innerText = "Confirmar Baixa";
            paymentActionInput.value = 'bulk_pay';
        } 
        else if (action === 'revert') {
            document.getElementById('payModalTitle').innerText = "Reverter?";
            document.getElementById('payModalText').innerText = "Reverter para pendente?";
            btnPayConfirm.innerText = "Reverter";
            paymentActionInput.value = 'revert_pay';
        }
        else if (action === 'cancel') {
            document.getElementById('payModalTitle').innerText = "Cancelar Cobrança?";
            document.getElementById('payModalText').innerHTML = "Tem certeza? Isso anulará a cobrança selecionada.<br><small>(Muda status para Cancelado)</small>";
            document.getElementById('payModalIcon').className = "fas fa-ban";
            document.getElementById('payModalIconWrapper').style.background = "#fdedec";
            document.getElementById('payModalIcon').style.color = "#c0392b";
            btnPayConfirm.innerText = "Cancelar";
            paymentActionInput.value = 'cancel_pay';
        }
        document.getElementById('payModal').style.display = 'flex';
    }

    checkPayConfirm.addEventListener('change', function() {
        btnPayConfirm.disabled = !this.checked;
        if(this.checked) {
            // Se for reverter ou cancelar, botão fica vermelho
            if(paymentActionInput.value === 'revert_pay' || paymentActionInput.value === 'cancel_pay') {
                btnPayConfirm.classList.add('active-danger');
            } else {
                btnPayConfirm.classList.add('active');
            }
        } else {
            btnPayConfirm.classList.remove('active');
            btnPayConfirm.classList.remove('active-danger');
        }
    });

    // --- Certificados ---
    const certModal = document.getElementById('certModal');
    const checkCertConfirm = document.getElementById('checkCertConfirm');
    const btnCertConfirm = document.getElementById('btnCertConfirm');

    function openCertModal(action, id = 0) {
        document.getElementById('certActionInput').value = action;
        document.getElementById('certIdInput').value = id;
        checkCertConfirm.checked = false;
        btnCertConfirm.disabled = true;
        btnCertConfirm.classList.remove('active', 'active-danger');
        document.getElementById('customWorkloadField').style.display = 'none';

        if (action === 'generate') {
            document.getElementById('modalTitle').innerText = "Gerar Certificado";
            document.getElementById('modalText').innerText = "Deseja emitir um novo certificado para este aluno?";
            document.getElementById('modalIcon').className = "fas fa-award";
            document.getElementById('modalIconWrapper').style.background = "#fdf8e4";
            document.getElementById('modalIcon').style.color = "#f39c12";
            btnCertConfirm.innerText = "Emitir";
            document.getElementById('customWorkloadField').style.display = 'block';
        } else {
            document.getElementById('modalTitle').innerText = "Revogar Certificado?";
            document.getElementById('modalText').innerText = "Esta ação é irreversível. O certificado será excluído.";
            document.getElementById('modalIcon').className = "fas fa-trash";
            document.getElementById('modalIconWrapper').style.background = "#fdedec";
            document.getElementById('modalIcon').style.color = "#c0392b";
            btnCertConfirm.innerText = "Revogar";
        }
        certModal.style.display = 'flex';
    }

    function closeCertModal() { certModal.style.display = 'none'; }

    checkCertConfirm.addEventListener('change', function() {
        btnCertConfirm.disabled = !this.checked;
        if (this.checked) {
            if (document.getElementById('certActionInput').value === 'revoke') btnCertConfirm.classList.add('active-danger');
            else btnCertConfirm.classList.add('active');
        } else {
            btnCertConfirm.classList.remove('active', 'active-danger');
        }
    });

    // --- Resetar Termo (Novo) ---
    const resetModal = document.getElementById('resetTermModal');
    const checkReset = document.getElementById('checkResetConfirm');
    const btnReset = document.getElementById('btnResetConfirm');

    function openResetTermModal(termId) {
        document.getElementById('resetTermIdInput').value = termId;
        checkReset.checked = false;
        btnReset.disabled = true;
        btnReset.style.opacity = "0.6";
        resetModal.style.display = 'flex';
    }

    function closeResetTermModal() {
        resetModal.style.display = 'none';
    }

    checkReset.addEventListener('change', function() {
        btnReset.disabled = !this.checked;
        btnReset.style.opacity = this.checked ? "1" : "0.6";
    });

    window.onclick = function(event) {
        if (event.target == certModal) closeCertModal();
        if (event.target == document.getElementById('payModal')) document.getElementById('payModal').style.display='none';
        if (event.target == document.getElementById('cancelFineModal')) closeCancelModal();
        if (event.target == resetModal) closeResetTermModal();
    }
</script>

<style>
    /* Estilos Visuais */
    .btn-cancel-enrollment { background-color: #fff; color: #c0392b; border: 1px solid #c0392b; padding: 10px 20px; border-radius: 4px; font-weight: 600; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
    .btn-cancel-enrollment:hover { background-color: #c0392b; color: white; }
    .divider { height: 1px; background: #eee; margin: 20px 0; }
    label { font-weight: 600; font-size: 0.9rem; margin-bottom: 5px; display: block; }
    .doc-card { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; display: flex; gap: 15px; }
    .doc-signed { background: #e8f8f5; border-color: #d1f2eb; }
    .doc-icon { font-size: 1.5rem; color: #7f8c8d; min-width: 40px; text-align: center; }
    .doc-signed .doc-icon { color: #27ae60; }
    .status-ok { color: #27ae60; font-weight: 600; display: block; margin-bottom: 5px; font-size: 0.8rem; }
    .status-pend { color: #e74c3c; font-weight: 600; display: block; margin-bottom: 5px; font-size: 0.8rem; }
    .btn-doc { padding: 6px 12px; background: #3498db; color: white; border-radius: 4px; text-decoration: none; font-size: 0.85rem; }
    .btn-pay { background: #27ae60; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; }
    .btn-revert { background: #fff; color: #e67e22; border: 1px solid #e67e22; padding: 8px 16px; border-radius: 4px; cursor: pointer; }
    .btn-cancel-pay { background: #fff; color: #c0392b; border: 1px solid #c0392b; padding: 8px 16px; border-radius: 4px; cursor: pointer; }
    .btn-cancel-pay:hover { background: #c0392b; color: white; }
    .btn-issue { background: #f39c12; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; display:flex; align-items:center; gap:5px;}
    .btn-revoke { background: white; color: #c0392b; border: 1px solid #c0392b; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
    .btn-action-group { display: flex; gap: 8px; }
    .table-custom { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 10px; border: 1px solid #eef2f7; border-radius: 6px; }
    .table-custom th { padding: 12px; background: #f8f9fa; text-align: left; }
    .table-custom td { padding: 12px; border-bottom: 1px solid #eef2f7; }
    .row-paid { background: #fafffc; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; color: white; }
    .badge-success { background: #d1e7dd; color: #0f5132; } .badge-warning { background: #fff3cd; color: #664d03; } .badge-danger { background: #f8d7da; color: #842029; } .badge-secondary { background: #e2e3e5; color: #41464b; }
    .active-danger { background: #c0392b !important; border-color: #c0392b !important; }
    .active { background: #27ae60 !important; }
    
    /* TOAST */
    .toast {
        visibility: hidden;
        min-width: 250px;
        background-color: #333;
        color: #fff;
        text-align: center;
        border-radius: 4px;
        padding: 16px;
        position: fixed;
        z-index: 10000;
        left: 50%;
        bottom: 30px;
        transform: translateX(-50%);
        font-size: 17px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.3);
    }
    .toast.show {
        visibility: visible;
        -webkit-animation: fadein 0.5s, fadeout 0.5s 2.5s;
        animation: fadein 0.5s, fadeout 0.5s 2.5s;
    }
    @-webkit-keyframes fadein { from {bottom: 0; opacity: 0;} to {bottom: 30px; opacity: 1;} }
    @keyframes fadein { from {bottom: 0; opacity: 0;} to {bottom: 30px; opacity: 1;} }
    @-webkit-keyframes fadeout { from {bottom: 30px; opacity: 1;} to {bottom: 0; opacity: 0;} }
    @keyframes fadeout { from {bottom: 30px; opacity: 1;} to {bottom: 0; opacity: 0;} }

    /* Modais */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center; }
    .modal-card { background: white; padding: 25px; border-radius: 8px; width: 90%; max-width: 400px; text-align: center; position: relative;}
    .modal-actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
    .btn-modal-cancel { background: #e0e0e0; color: #333; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 600; }
    .btn-modal-confirm { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 600; }
    .modal-icon-wrapper { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px auto; font-size: 1.8rem; }
    .modal-check-wrapper { margin-top: 15px; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.9rem; color: #555; }
</style>

<?php include '../includes/admin_footer.php'; ?>
