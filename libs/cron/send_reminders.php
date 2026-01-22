<?php
// libs/cron/send_reminders.php

// 1. AMBIENTE
chdir(__DIR__);
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300);
header('Content-Type: text/plain; charset=utf-8');

function logger($msg) {
    echo "[" . date('H:i:s') . "] " . $msg . "\n";
    flush();
}

logger("=== CRON DE COBRANÇA: INICIADO ===");

// 2. CONEXÃO
define('BASE_DIR', realpath(__DIR__ . '/../../')); 
require_once BASE_DIR . '/config/database.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Teste rápido
    $pdo->query("SELECT 1"); 
} catch (PDOException $e) {
    logger("ERRO FATAL: Sem conexão com banco. " . $e->getMessage());
    exit;
}

if (file_exists(BASE_DIR . '/includes/qr_generator.php')) {
    require_once BASE_DIR . '/includes/qr_generator.php';
}

// 3. CLASSE SMTP ROBUSTA (Hostinger Compatible)
class SimpleSMTP {
    private $host; private $user; private $pass; private $socket;
    
    public function __construct($host, $user, $pass) {
        $this->host = $host; 
        $this->user = $user; 
        $this->pass = $pass;
    }
    
    private function readResponse() {
        $data = "";
        while($str = fgets($this->socket, 512)) {
            $data .= $str;
            if(isset($str[3]) && $str[3] == ' ') { break; }
        }
        return $data;
    }
    
    private function cmd($cmd) {
        fwrite($this->socket, $cmd . "\r\n");
        return $this->readResponse();
    }
    
    public function send($to, $subject, $body, $fromName) {
        $ctx = stream_context_create(['ssl' => ['verify_peer'=>false, 'verify_peer_name'=>false]]);
        
        $this->socket = stream_socket_client("ssl://{$this->host}:465", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->socket) throw new Exception("Falha de Conexão: $errstr");
        
        $this->readResponse(); 
        $this->cmd("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $this->cmd("AUTH LOGIN");
        $this->cmd(base64_encode($this->user));
        
        $passResp = $this->cmd(base64_encode($this->pass));
        
        if (strpos($passResp, '235') === false) {
            throw new Exception("Autenticação Recusada. Verifique usuário/senha no banco.");
        }

        $this->cmd("MAIL FROM: <{$this->user}>");
        $this->cmd("RCPT TO: <$to>");
        $this->cmd("DATA");
        
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: $fromName <{$this->user}>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "Date: " . date("r") . "\r\n";
        
        fwrite($this->socket, "$headers\r\n$body\r\n.\r\n");
        $resp = $this->readResponse();
        $this->cmd("QUIT");
        fclose($this->socket);
        
        return true;
    }
}

// 4. LÓGICA DE NEGÓCIO
try {
    $settings = $pdo->query("SELECT * FROM system_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $school = $pdo->query("SELECT name FROM school_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $schoolName = $school['name'] ?? 'Escola';

    // === PREPARAÇÃO DA SENHA (DO BANCO) ===
    // 1. Remove espaços (trim)
    // 2. Converte entidades HTML se houver (ex: &amp; vira &)
    $smtpPass = html_entity_decode(trim($settings['smtpPass']));

    if (empty($smtpPass)) {
        logger("ERRO: Senha SMTP vazia no banco de dados.");
        exit;
    }

    $mailer = new SimpleSMTP($settings['smtpServer'], $settings['smtpUser'], $smtpPass);

    $days = (int)$settings['reminderDaysBefore'];
    $targetDate = date('Y-m-d', strtotime("+$days days"));
    
    logger("Buscando vencimentos em: $targetDate ($days dias antes)");

    $sql = "SELECT p.*, u.firstName, u.email, u.age, u.guardianEmail, u.guardianName, c.name as courseName 
            FROM payments p 
            JOIN users u ON p.studentId = u.id 
            JOIN courses c ON p.courseId = c.id
            WHERE p.status = 'Pendente' 
            AND p.dueDate = ? 
            AND p.reminderSent = 0";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$targetDate]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    logger("Faturas encontradas: " . count($payments));

    foreach ($payments as $pay) {
        $toEmail = $pay['email'];
        $nomePara = $pay['firstName'];
        
        // Lógica de Responsável
        if ($pay['age'] < 18 && !empty($pay['guardianEmail'])) {
            $toEmail = $pay['guardianEmail'];
            $nomePara = $pay['guardianName'];
        }

        if (empty($toEmail)) { logger("ID {$pay['id']}: Ignorado (Sem e-mail)"); continue; }

        // Gera Pix
        $pixCopiaCola = ""; $qrCodeHtml = "";
        if (function_exists('generatePixForPayment')) {
            try {
                $pix = generatePixForPayment($pay['id'], $pdo);
                if ($pix && $pix['success']) {
                    $pixCopiaCola = $pix['copia_e_cola'];
                    $qrCodeHtml = '<img src="https://quickchart.io/qr?text='.urlencode($pixCopiaCola).'&size=200">';
                }
            } catch (Exception $e) {}
        }

        $replaces = [
            '{{aluno_nome}}' => $nomePara,
            '{{valor}}' => number_format($pay['amount'],2,',','.'),
            '{{vencimento}}' => date('d/m/Y', strtotime($pay['dueDate'])),
            '{{escola_nome}}' => $schoolName,
            '{{pix_qr_code}}' => $qrCodeHtml,
            '{{pix_copia_cola}}' => $pixCopiaCola,
            '{{curso_nome}}' => $pay['courseName']
        ];

        $subject = str_replace(array_keys($replaces), array_values($replaces), $settings['email_reminder_subject']);
        $body = str_replace(array_keys($replaces), array_values($replaces), nl2br($settings['email_reminder_body']));

        try {
            $mailer->send($toEmail, $subject, $body, $schoolName);
            
            // Marca como enviado
            $pdo->prepare("UPDATE payments SET reminderSent = 1 WHERE id = ?")->execute([$pay['id']]);
            logger("✅ Enviado para: $toEmail");
        } catch (Exception $e) {
            logger("❌ Falha envio ($toEmail): " . $e->getMessage());
        }
    }

} catch (Exception $e) {
    logger("ERRO GERAL: " . $e->getMessage());
}

logger("=== CRON FINALIZADO ===");
?>