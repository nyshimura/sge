<?php
// libs/cron/send_reminders.php
// --- CONFIGURAÇÕES DE DEBUG EXTREMO ---
// 1. Fixar diretório
chdir(__DIR__);

// 2. Forçar exibição de erros
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 3. DESLIGAR BUFFER (Isso destrava o output)
if (function_exists('ob_end_clean')) { while (ob_get_level() > 0) { ob_end_clean(); } }
ob_implicit_flush(true);

// 4. Aumentar tempo limite (evita erro 504/timeout se o SMTP demorar)
set_time_limit(300); // 5 minutos


// 1. CONFIGURAÇÕES DE AMBIENTE
ini_set('display_errors', 0); 
error_reporting(0);
header('Content-Type: text/plain; charset=utf-8');

define('BASE_DIR', realpath(__DIR__ . '/../../'));
echo "=== CRON DE COBRANÇA: MODO API EXTERNA (QUICKCHART) ===\n";

// 2. CARREGAMENTO DE DEPENDÊNCIAS
require_once BASE_DIR . '/config/database.php'; 
require_once BASE_DIR . '/includes/qr_generator.php'; 

// --- 3. CLASSE SMTP (CORRIGIDA PARA HOSTINGER) ---
class SimpleSMTPProfile {
    private $host; private $port; private $user; private $pass; private $socket;
    public function __construct($host, $port, $user, $pass) {
        $this->host = $host; $this->port = $port; $this->user = $user; $this->pass = $pass;
    }

    private function cmd($cmd, $expect = 250) {
        if ($cmd !== null) fwrite($this->socket, $cmd . "\r\n");
        $resp = "";
        while ($line = fgets($this->socket, 512)) {
            $resp .= $line;
            if (strlen($line) >= 4 && $line[3] == ' ') break; 
        }
        $code = substr($resp, 0, 3);
        if ($code != $expect) throw new Exception("SMTP Erro: $resp");
        return $resp;
    }

    public function send($to, $subject, $htmlBody, $fromName) {
        $tls = ($this->port == 587) ? 'tls://' : (($this->port == 465) ? 'ssl://' : '');
        $this->socket = fsockopen($tls . $this->host, $this->port, $errno, $errstr, 30);
        if (!$this->socket) throw new Exception("Falha SMTP: $errstr");

        fgets($this->socket, 512);
        $this->cmd("EHLO " . $_SERVER['SERVER_NAME']);
        $this->cmd("AUTH LOGIN", 334);
        $this->cmd(base64_encode($this->user), 334);
        $this->cmd(base64_encode($this->pass), 235);
        $this->cmd("MAIL FROM: <{$this->user}>");
        $this->cmd("RCPT TO: <$to>");
        $this->cmd("DATA", 354);

        $headers  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: $fromName <{$this->user}>\r\nTo: $to\r\nSubject: $subject\r\n";

        fwrite($this->socket, $headers . "\r\n" . $htmlBody . "\r\n.\r\n");
        $this->cmd(null, 250); 
        $this->cmd("QUIT", 221);
        fclose($this->socket);
        return true;
    }
}

try {
    $settings = $pdo->query("SELECT * FROM system_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $school = $pdo->query("SELECT name FROM school_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $mailer = new SimpleSMTPProfile($settings['smtpServer'], $settings['smtpPort'], $settings['smtpUser'], $settings['smtpPass']);

    $targetDate = date('Y-m-d', strtotime("+{$settings['reminderDaysBefore']} days"));
    
    $stmt = $pdo->prepare("SELECT p.*, u.firstName, u.email, u.age, u.guardianEmail, u.guardianName, c.name as courseName 
                           FROM payments p 
                           JOIN users u ON p.studentId = u.id 
                           JOIN courses c ON p.courseId = c.id
                           WHERE p.status = 'Pendente' AND p.dueDate = ? AND p.reminderSent = 0");
    $stmt->execute([$targetDate]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Faturas encontradas: " . count($payments) . "\n";

    foreach ($payments as $pay) {
        echo "> Processando {$pay['firstName']}... ";

        // A. GERAÇÃO DO PAYLOAD PIX
        // Chamamos sua função original, mas usaremos apenas a string 'copia_e_cola'
        $pixData = generatePixForPayment($pay['id'], $pdo);

        $qrCodeHtml = ""; 
        $pixCopiaCola = "";

        if ($pixData['success']) {
            $pixCopiaCola = $pixData['copia_e_cola'];
            
            // CRIANDO O LINK DA API EXTERNA (QuickChart)
            // Codificamos a string do PIX para ser usada em uma URL
            $urlSafePix = urlencode($pixCopiaCola);
            $apiUrl = "https://quickchart.io/qr?text={$urlSafePix}&size=200&margin=1";
            
            $qrCodeHtml = '<img src="' . $apiUrl . '" style="width:200px; height:200px; border:1px solid #ddd; padding:10px;" alt="QR Code PIX">';
        }

        // B. DESTINATÁRIO
        $toEmail = ($pay['age'] < 18 && !empty($pay['guardianEmail'])) ? $pay['guardianEmail'] : $pay['email'];
        $nomeTratamento = ($pay['age'] < 18 && !empty($pay['guardianName'])) ? $pay['guardianName'] : $pay['firstName'];

        // C. PLACEHOLDERS
        $replaces = [
            '{{aluno_nome}}'          => $nomeTratamento,
            '{{nome_aluno_original}}' => $pay['firstName'],
            '{{curso_nome}}'          => $pay['courseName'],
            '{{valor}}'               => 'R$ ' . number_format($pay['amount'], 2, ',', '.'),
            '{{vencimento}}'          => date('d/m/Y', strtotime($pay['dueDate'])),
            '{{pix_qr_code}}'         => $qrCodeHtml,
            '{{pix_copia_cola}}'      => '<div style="background:#f4f4f4; padding:10px; word-break:break-all; font-family:monospace;">'.$pixCopiaCola.'</div>',
            '{{escola_nome}}'         => $school['name']
        ];

        $subject = str_replace(array_keys($replaces), array_values($replaces), $settings['email_reminder_subject']);
        $body = $settings['email_reminder_body'];
        foreach ($replaces as $tag => $value) { $body = str_replace($tag, $value, $body); }
        $body = nl2br($body);

        // D. ENVIO REAL E ATUALIZAÇÃO
        try {
            if ($mailer->send($toEmail, $subject, $body, $school['name'])) {
                $upd = $pdo->prepare("UPDATE payments SET reminderSent = 1 WHERE id = ?");
                $upd->execute([$pay['id']]);
                echo "SUCESSO E ATUALIZADO!\n";
            }
        } catch (Exception $e) { echo "ERRO SMTP: " . $e->getMessage() . "\n"; }
    }
} catch (Exception $e) { echo "ERRO: " . $e->getMessage(); }