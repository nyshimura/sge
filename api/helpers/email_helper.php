<?php
// api/helpers/email_helper.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../phpmailer/Exception.php';
require_once __DIR__ . '/../phpmailer/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/SMTP.php';

function send_system_email($conn, $toEmail, $toName, $subject, $bodyHTML, $ccEmail = null, $embeddedImage = null)
{
    try {
        $stmtSettings = $conn->query("SELECT smtpServer, smtpPort, smtpUser, smtpPass FROM system_settings WHERE id = 1 LIMIT 1");
        $settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);

        if (empty($settings['smtpServer']) || empty($settings['smtpPort']) || empty($settings['smtpUser'])) {
            return false;
        }

        $stmtSchool = $conn->query("SELECT name FROM school_profile WHERE id = 1 LIMIT 1");
        $schoolName = $stmtSchool->fetchColumn() ?: 'Sistema SGE';

    } catch (PDOException $e) { return false; }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $settings['smtpServer'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $settings['smtpUser'];
        $mail->Password   = $settings['smtpPass'];

        if ($settings['smtpPort'] == 587) $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        elseif ($settings['smtpPort'] == 465) $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        else { $mail->SMTPSecure = false; $mail->SMTPAutoTLS = false; }

        $mail->Port       = (int)$settings['smtpPort'];
        $mail->CharSet    = PHPMailer::CHARSET_UTF8;

        $mail->setFrom($settings['smtpUser'], $schoolName);
        $mail->addAddress($toEmail, $toName ?: '');

        // LÓGICA DO GUARDIÃO (CC)
        if (!empty($ccEmail)) {
            $mail->addCC($ccEmail);
        }

        // LÓGICA DO QR CODE (Imagem Embutida)
        if (!empty($embeddedImage) && file_exists($embeddedImage['path'])) {
            $mail->addEmbeddedImage($embeddedImage['path'], $embeddedImage['cid'], 'qrcode.png');
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHTML;
        $mail->AltBody = strip_tags($bodyHTML);

        $mail->send();
        return true; 

    } catch (Exception $e) {
         error_log("Erro Email: " . $mail->ErrorInfo);
         return false;
    }
}

function send_password_reset_email($conn, $toEmail, $userId, $resetLink) {
    $userName = 'Usuário';
    try {
        $stmt = $conn->prepare("SELECT firstName FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userName = $stmt->fetchColumn() ?: 'Usuário';
    } catch(Exception $e) {}

    // Pega templates do banco se existirem
    $subject = 'Redefinição de Senha';
    $body = "Olá $userName,<br><br>Acesse: <a href='$resetLink'>$resetLink</a>";
    
    // (Simplificado para manter foco no erro, mas usa a função principal atualizada)
    return send_system_email($conn, $toEmail, $userName, $subject, $body);
}

function send_payment_reminder_email($conn, $toEmail, $studentName, $courseName, $dueDate, $amount, $guardianEmail = null, $pixPayload = null, $qrCodePath = null) {
    // 1. Configurações e Templates
    $schoolName = 'Sistema SGE';
    $subjectTemplate = 'Lembrete de Pagamento';
    $bodyTemplate = "Olá {{aluno_nome}},\n\nSua mensalidade vence em {{vencimento_data}}.\nValor: R$ {{valor}}.\n\nAtenciosamente,\n{{escola_nome}}";

    try {
        $stmtSettings = $conn->query("SELECT email_reminder_subject, email_reminder_body FROM system_settings WHERE id = 1 LIMIT 1");
        $settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
        if ($settings) {
            if (!empty($settings['email_reminder_subject'])) $subjectTemplate = $settings['email_reminder_subject'];
            if (!empty($settings['email_reminder_body'])) $bodyTemplate = $settings['email_reminder_body'];
        }
        $stmtSchool = $conn->query("SELECT name FROM school_profile WHERE id = 1 LIMIT 1");
        $schoolName = $stmtSchool->fetchColumn() ?: 'Escola';
    } catch (PDOException $e) {}

    // 2. Formatação
    $dueDateFormatted = date('d/m/Y', strtotime($dueDate));
    $amountFormatted = number_format($amount, 2, ',', '.');

    // 3. Substituição
    $subject = str_replace('{{escola_nome}}', $schoolName, $subjectTemplate);
    $subject = str_replace('{{aluno_nome}}', $studentName, $subject);

    $body = str_replace('{{aluno_nome}}', $studentName, $bodyTemplate);
    $body = str_replace('{{curso_nome}}', $courseName, $body);
    $body = str_replace('{{vencimento_data}}', $dueDateFormatted, $body);
    $body = str_replace('{{valor}}', $amountFormatted, $body);
    $body = str_replace('{{escola_nome}}', $schoolName, $body);

    $bodyHTML = nl2br($body);

    // 4. INSERIR PIX NO HTML (Automaticamente no final)
    $embeddedImage = null;
    if ($pixPayload && $qrCodePath) {
        $cid = 'qrcode_pix_img';
        $embeddedImage = ['path' => $qrCodePath, 'cid' => $cid];

        $pixHtml = "
        <div style='margin-top: 30px; padding: 20px; border: 1px solid #eee; background-color: #f8f9fa; border-radius: 8px; text-align: center; font-family: sans-serif;'>
            <h3 style='margin-top:0; color: #333;'>Pague com PIX</h3>
            <p style='color: #666;'>Abra o app do seu banco e escaneie:</p>
            <div style='margin: 15px 0;'>
                <img src='cid:$cid' alt='QR Code PIX' style='width: 200px; height: 200px; border: 4px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1);'>
            </div>
            <p style='font-size: 14px; margin-bottom: 5px;'>Ou copie e cole o código abaixo:</p>
            <textarea readonly style='width: 90%; height: 70px; font-size: 11px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; resize: none; background: #fff;'>$pixPayload</textarea>
        </div>";

        $bodyHTML .= $pixHtml;
    }

    return send_system_email($conn, $toEmail, $studentName, $subject, $bodyHTML, $guardianEmail, $embeddedImage);
}
?>