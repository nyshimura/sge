<?php
// recuperar_senha.php

// ATIVAR EXIBIÇÃO DE ERROS (Para debug do Erro 500)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'config/database.php';

// --- CARREGAMENTO DO PHPMAILER (Manual para v6) ---
// Certifique-se que os arquivos estão nesta pasta conforme seu print
require_once 'libs/phpmailer/Exception.php';
require_once 'libs/phpmailer/PHPMailer.php';
require_once 'libs/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$msg = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);

    if (!empty($email)) {
        // 1. Verifica usuário
        $stmt = $pdo->prepare("SELECT id, firstName, lastName FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            try {
                // 2. Gera Token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // 3. Salva no banco
                $update = $pdo->prepare("UPDATE users SET reset_token = :token, reset_token_expires_at = :exp WHERE id = :id");
                $update->execute([':token' => $token, ':exp' => $expires, ':id' => $user['id']]);

                // 4. Busca Configurações SMTP
                $settings = $pdo->query("SELECT * FROM system_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

                // 5. Configura e Envia E-mail
                $mail = new PHPMailer(true);
                
                // Configurações do Servidor
                $mail->isSMTP();
                $mail->Host       = $settings['smtpServer']; // smtp.hostinger.com
                $mail->SMTPAuth   = true;
                $mail->Username   = $settings['smtpUser'];   // nao-responda@ccrn.com.br
                $mail->Password   = $settings['smtpPass'];   // Sua senha
                
                // CORREÇÃO CRÍTICA PARA PORTA 465 (Hostinger)
                if ($settings['smtpPort'] == 465) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
                } else {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS (587)
                }
                
                $mail->Port       = $settings['smtpPort']; // 465
                $mail->CharSet    = 'UTF-8';

                // Debug (Descomente se continuar dando erro 500 para ver o log do SMTP)
                // $mail->SMTPDebug = 2; 

                // Remetente e Destinatário
                $mail->setFrom($settings['smtpUser'], 'Recuperação de Senha');
                $mail->addAddress($email, $user['firstName']);

                // Conteúdo
                $link = $settings['site_url'] . "/redefinir_senha.php?token=" . $token;
                
                // Monta o corpo
                $body = $settings['email_reset_body'];
                // Se o corpo estiver vazio no banco, usa um padrão
                if (empty($body)) {
                    $body = "Olá {{user_name}},<br>Clique abaixo para redefinir sua senha:<br><a href='{{reset_link}}'>{{reset_link}}</a>";
                }
                
                $body = str_replace('{{user_name}}', $user['firstName'], $body);
                $body = str_replace('{{reset_link}}', $link, $body);
                $body = str_replace('{{escola_nome}}', 'SGE', $body);

                $mail->isHTML(true);
                $mail->Subject = $settings['email_reset_subject'] ?: 'Recuperação de Senha';
                $mail->Body    = $body;
                $mail->AltBody = "Acesse o link para redefinir: " . $link;

                $mail->send();
                $msg = '<div class="alert alert-success">E-mail enviado com sucesso! Verifique sua caixa de entrada.</div>';

            } catch (Exception $e) {
                // Mostra o erro real do PHPMailer
                $msg = '<div class="alert alert-danger">Erro técnico ao enviar: ' . $mail->ErrorInfo . '</div>';
            } catch (PDOException $e) {
                $msg = '<div class="alert alert-danger">Erro no banco de dados: ' . $e->getMessage() . '</div>';
            }
        } else {
            // Mensagem genérica de sucesso por segurança
            $msg = '<div class="alert alert-success">Se o e-mail existir, o link foi enviado.</div>';
        }
    } else {
        $msg = '<div class="alert alert-danger">Por favor, digite seu e-mail.</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Recuperar Senha</title>
    <style>
        body { background: #f1f2f6; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 400px; text-align: center; }
        .form-control { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        .btn { background: #1e3c72; color: white; border: none; padding: 12px; width: 100%; border-radius: 4px; cursor: pointer; font-size: 1rem; }
        .btn:hover { background: #162c55; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; font-size: 0.9rem; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        a { color: #1e3c72; text-decoration: none; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Recuperar Senha</h2>
        <p style="color:#666; margin-bottom:20px;">Digite seu e-mail cadastrado.</p>
        
        <?php echo $msg; ?>

        <form method="POST">
            <input type="email" name="email" class="form-control" placeholder="E-mail" required>
            <button type="submit" class="btn">Enviar Link</button>
        </form>
        
        <div style="margin-top: 20px;">
            <a href="login.php">Voltar para Login</a>
        </div>
    </div>
</body>
</html>