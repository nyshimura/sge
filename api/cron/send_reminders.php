<?php
// api/cron/send_reminders.php

// 1. ATIVAR DEBUG (Para ver o erro 500 real)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "=== INICIANDO DIAGNÓSTICO E ENVIO ===\n";

// 2. VERIFICAÇÕES DE AMBIENTE
if (!extension_loaded('gd')) {
    die("ERRO FATAL: A extensão 'GD' do PHP não está ativada. O QR Code não pode ser gerado sem ela.\n");
}

$tempDir = sys_get_temp_dir();
if (!is_writable($tempDir)) {
    echo "AVISO: O diretório temporário ($tempDir) não permite escrita. O anexo do QR Code pode falhar.\n";
}

// 3. CARREGAMENTO DE ARQUIVOS (Com validação)
$files = [
    __DIR__ . '/../config.php',
    __DIR__ . '/../helpers/email_helper.php',
    __DIR__ . '/../helpers/pix_helper.php',
    __DIR__ . '/../libs/phpqrcode/qrlib.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        die("ERRO FATAL: Arquivo não encontrado: $file\n");
    }
    require_once $file;
}

echo "Bibliotecas carregadas com sucesso.\n";

// --- INÍCIO DA LÓGICA ORIGINAL ---

try {
    // 1. Obter Configurações (Dias)
    $stmtSettings = $conn->query("SELECT reminderDaysBefore FROM system_settings WHERE id = 1");
    $daysBefore = $stmtSettings->fetchColumn();
    // Se for null ou false, usa 3 como padrão
    $daysBefore = ($daysBefore !== false && $daysBefore !== null) ? $daysBefore : 3;
    
    $targetDate = date('Y-m-d', strtotime("+$daysBefore days"));

    // 2. Obter Dados da Escola para o PIX
    $stmtProfile = $conn->query("SELECT name, schoolCity, pixKey, pixKeyType FROM school_profile WHERE id = 1");
    $schoolProfile = $stmtProfile->fetch(PDO::FETCH_ASSOC);

    echo "Config: $daysBefore dias antes. Alvo: $targetDate.\n";

    // 3. Buscar Faturas
    $sql = "SELECT 
                p.id as paymentId, p.amount, p.dueDate,
                c.name as courseName,
                u.firstName, u.lastName, u.email, u.age, u.guardianEmail, u.guardianName
            FROM payments p
            JOIN users u ON p.studentId = u.id
            JOIN courses c ON p.courseId = c.id
            WHERE p.status = 'Pendente' 
            AND p.dueDate = :targetDate
            AND p.reminderSent = 0
            AND u.email IS NOT NULL AND u.email != ''";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':targetDate' => $targetDate]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Faturas encontradas: " . count($payments) . "\n\n";

    if (count($payments) > 0) {
        $countSent = 0;
        
        foreach ($payments as $pay) {
            $studentName = $pay['firstName'] . ' ' . $pay['lastName'];
            echo "> $studentName... ";

            // A. Gerar Payload PIX
            $pixPayload = null;
            $qrCodePath = null;
            
            if (!empty($schoolProfile['pixKey'])) {
                try {
                    $obPix = (new PixPayload())
                        ->setPixKey($schoolProfile['pixKey'])
                        ->setMerchantName($schoolProfile['name'])
                        ->setMerchantCity($schoolProfile['schoolCity'])
                        ->setAmount($pay['amount'])
                        ->setTxid('SGE' . $pay['paymentId']); 

                    $pixPayload = $obPix->getPayload();

                    // B. Gerar Imagem QR Code
                    $fileName = 'pix_' . $pay['paymentId'] . '_' . time() . '.png';
                    $qrCodePath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
                    
                    // Gera o arquivo PNG
                    QRcode::png($pixPayload, $qrCodePath, QR_ECLEVEL_L, 4, 2);
                    
                } catch (Exception $e) {
                    echo "[AVISO PIX: " . $e->getMessage() . "] ";
                }
            } else {
                echo "[PIX: Chave não config] ";
            }

            // C. Verificar Guardião
            $guardianEmail = null;
            if ($pay['age'] !== null && $pay['age'] < 18 && !empty($pay['guardianEmail'])) {
                $guardianEmail = $pay['guardianEmail'];
                echo "[CC: Guardião] ";
            }

            // D. Enviar E-mail
            $sent = send_payment_reminder_email(
                $conn, 
                $pay['email'], 
                $studentName, 
                $pay['courseName'], 
                $pay['dueDate'], 
                $pay['amount'],
                $guardianEmail, 
                $pixPayload,    
                $qrCodePath     
            );

            // E. Limpeza e Log
            if ($sent) {
                $conn->prepare("UPDATE payments SET reminderSent = 1 WHERE id = ?")->execute([$pay['paymentId']]);
                echo "[OK] Enviado.\n";
                $countSent++;
            } else {
                echo "[ERRO] Falha envio email.\n";
            }

            // Remove a imagem temporária
            if ($qrCodePath && file_exists($qrCodePath)) {
                @unlink($qrCodePath);
            }
        }
    } else {
        echo "Nenhuma cobrança pendente para a data $targetDate.\n";
    }

} catch (PDOException $e) {
    echo "\nERRO BANCO DE DADOS: " . $e->getMessage();
} catch (Exception $e) {
    echo "\nERRO GERAL: " . $e->getMessage();
} catch (Throwable $t) {
    echo "\nERRO FATAL (Throwable): " . $t->getMessage();
}

echo "\n=== FIM DA EXECUÇÃO ===\n";
?>