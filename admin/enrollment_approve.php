<?php
// admin/enrollment_approve.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// --- CARREGAMENTO DO PHPMAILER ---
if (file_exists('../libs/phpmailer/PHPMailer.php')) {
    require_once '../libs/phpmailer/Exception.php';
    require_once '../libs/phpmailer/PHPMailer.php';
    require_once '../libs/phpmailer/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

checkRole(['admin', 'superadmin']);

// 1. CAPTURA DE DADOS (Suporta POST do Modal e GET do Link)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $cid = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
    $redirect = isset($_POST['redirect']) ? $_POST['redirect'] : 'enrollments.php';
} else {
    $sid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
    $cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
    $redirect = 'enrollments.php'; // Padrão GET
}

if (!$sid || !$cid) {
    header("Location: $redirect?msg=error_params");
    exit;
}

try {
    $pdo->beginTransaction();

    // =================================================================
    // A. BUSCAR DADOS COMPLETOS (Matrícula + Curso + Aluno)
    // =================================================================
    $sql = "SELECT e.*, 
                   c.name as courseName, c.monthlyFee as baseFee,
                   u.firstName, u.lastName, u.email, 
                   u.guardianName, u.guardianEmail
            FROM enrollments e 
            JOIN courses c ON e.courseId = c.id 
            JOIN users u ON e.studentId = u.id
            WHERE e.studentId = :sid AND e.courseId = :cid";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sid' => $sid, ':cid' => $cid]);
    $enroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$enroll) throw new Exception("Matrícula não encontrada.");

    // =================================================================
    // B. ATUALIZAR STATUS
    // =================================================================
    $upd = $pdo->prepare("UPDATE enrollments SET status = 'Aprovada' WHERE studentId = :sid AND courseId = :cid");
    $upd->execute([':sid' => $sid, ':cid' => $cid]);

    // =================================================================
    // C. GERAR FINANCEIRO (Lógica de Pagamentos)
    // =================================================================
    $baseFee = (float)$enroll['baseFee'];
    $scholarship = (float)$enroll['scholarshipPercentage'];
    $customFeeDB = $enroll['customMonthlyFee'];
    $startDay = !empty($enroll['billingStartDate']) ? $enroll['billingStartDate'] : date('Y-m-d');
    $dueDay = !empty($enroll['customDueDay']) ? (int)$enroll['customDueDay'] : 10;

    // Calcular Valor Final
    $finalFee = $baseFee;
    if ($scholarship > 0) {
        $discount = $baseFee * ($scholarship / 100);
        $finalFee = max(0, $baseFee - $discount);
    } else {
        if ($customFeeDB !== null && $customFeeDB !== '') {
            $finalFee = (float)$customFeeDB;
        }
    }

    // Gerar ou Limpar
    if ($finalFee <= 0.01) {
        $del = $pdo->prepare("DELETE FROM payments WHERE studentId=:s AND courseId=:c AND status='Pendente'");
        $del->execute([':s'=>$sid, ':c'=>$cid]);
    } else {
        // Atualiza existentes
        $updPay = $pdo->prepare("UPDATE payments SET amount=:a WHERE studentId=:s AND courseId=:c AND status='Pendente'");
        $updPay->execute([':a'=>$finalFee, ':s'=>$sid, ':c'=>$cid]);

        // Loop de Geração
        $startObj = new DateTime($startDay);
        $limitDate = new DateTime(date('Y-12-31'));
        
        if ((int)$startObj->format('d') > $dueDay) {
            $startObj->modify('first day of next month');
        }

        while ($startObj <= $limitDate) {
            $ano = $startObj->format('Y');
            $mes = $startObj->format('m');
            $lastDay = cal_days_in_month(CAL_GREGORIAN, $mes, $ano);
            $diaFixo = min($dueDay, $lastDay);
            
            $dueDate = "$ano-$mes-$diaFixo";
            $refDate = "$ano-$mes-01";

            $check = $pdo->prepare("SELECT id FROM payments WHERE studentId=:s AND courseId=:c AND referenceDate=:ref");
            $check->execute([':s'=>$sid, ':c'=>$cid, ':ref'=>$refDate]);

            if ($check->rowCount() == 0) {
                $ins = $pdo->prepare("INSERT INTO payments (studentId, courseId, amount, referenceDate, dueDate, status, created_at) VALUES (:s, :c, :a, :ref, :due, 'Pendente', NOW())");
                $ins->execute([':s'=>$sid, ':c'=>$cid, ':a'=>$finalFee, ':ref'=>$refDate, ':due'=>$dueDate]);
            }
            $startObj->modify('first day of next month');
        }
    }

    $pdo->commit(); // Salva tudo no banco antes de tentar enviar email

    // =================================================================
    // D. ENVIAR E-MAIL (PHPMailer)
    // =================================================================
    $emailSuccess = false;
    $emailError = '';

    $stmtSettings = $pdo->query("SELECT * FROM system_settings WHERE id = 1");
    $settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);

    if ($settings && !empty($settings['smtpServer'])) {
        $mail = new PHPMailer(true);
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $settings['smtpServer'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $settings['smtpUser'];
            $mail->Password   = $settings['smtpPass'];
            
            if ($settings['smtpPort'] == 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->Port       = $settings['smtpPort'];
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom($settings['smtpUser'], 'Secretaria Escolar');

            // Destinatários (Aluno + Responsável)
            if (!empty($enroll['email'])) {
                $mail->addAddress($enroll['email'], $enroll['firstName'] . ' ' . $enroll['lastName']);
            }

            $nomePrincipal = $enroll['firstName']; // Default
            if (!empty($enroll['guardianEmail'])) {
                if ($enroll['guardianEmail'] !== $enroll['email']) {
                    $mail->addAddress($enroll['guardianEmail'], $enroll['guardianName']);
                }
                $nomePrincipal = $enroll['guardianName'];
            }

            // Conteúdo
            $nomeAlunoCompleto = $enroll['firstName'] . ' ' . $enroll['lastName'];
            $linkContrato      = $settings['site_url'] . "/student/my_courses.php"; 

            $subject = $settings['email_approval_subject'];
            $body    = $settings['email_approval_body'];

            $body = str_replace('{{responsavel_nome}}', $nomePrincipal, $body);
            $body = str_replace('{{aluno_nome}}', $nomeAlunoCompleto, $body);
            $body = str_replace('{{curso_nome}}', $enroll['courseName'], $body);
            $body = str_replace('{{link_contrato}}', $linkContrato, $body);
            $body = str_replace('{{escola_nome}}', 'SGE', $body);

            $mail->isHTML(true);
            $mail->Subject = $subject ?: "Matrícula Aprovada";
            $mail->Body    = nl2br($body);

            $mail->send();
            $emailSuccess = true;

        } catch (Exception $e) {
            $emailSuccess = false;
            $emailError = $mail->ErrorInfo;
        }
    }

    // =================================================================
    // E. REDIRECIONAR COM MENSAGEM
    // =================================================================
    $msg = "approved";
    if ($emailSuccess) {
        $msg = "approved_email_sent";
    } elseif ($settings && !empty($settings['smtpServer'])) {
        // Tentou enviar mas falhou
        $msg = "approved_email_error&details=" . urlencode($emailError);
    } else {
        // Não tentou enviar (sem config)
        $msg = "approved_no_config";
    }

    header("Location: $redirect?msg=$msg");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header("Location: $redirect?msg=error&details=" . urlencode($e->getMessage()));
    exit;
}
?>