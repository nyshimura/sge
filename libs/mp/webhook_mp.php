<?php
// libs/mp/webhook_mp.php

// Define JSON header and 200 status immediately to stop MP from resending
header("Content-Type: application/json");
http_response_code(200);

// Error handling to avoid silent 500 errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    // 1. Load Database - PATH CORRECTION
    // Since we are in libs/mp/, we need to go up two levels to reach config/
    if (file_exists(__DIR__ . '/../../config/database.php')) {
        require_once __DIR__ . '/../../config/database.php';
    } elseif (file_exists(__DIR__ . '/../config/database.php')) {
        // Fallback in case it's in the previous structure
        require_once __DIR__ . '/../config/database.php';
    } else {
        throw new Exception("Database configuration file not found.");
    }

    // 2. Safe Log Function
    function mpLog($msg) {
        $logFile = __DIR__ . '/webhook_log.txt';
        $date = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$date] $msg" . PHP_EOL, FILE_APPEND);
    }

    // 3. Capture Payload (JSON or GET)
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    
    $paymentId = $_GET['id'] ?? $_GET['data_id'] ?? null;
    
    if (!$paymentId && isset($input['data']['id'])) {
        $paymentId = $input['data']['id'];
    }
    
    // If ID is still not found, check old format or root id
    if (!$paymentId && isset($input['id'])) {
        $paymentId = $input['id'];
    }

    // --- SIMULATION DETECTION ---
    // If it's the dashboard simulator (live_mode = false), verify if it's explicitly strictly false
    if (isset($input['live_mode']) && $input['live_mode'] === false) {
        mpLog("SIMULATION TEST RECEIVED. ID: $paymentId - Ignored successfully.");
        echo json_encode(["status" => "ignored_test"]);
        exit;
    }

    if (!$paymentId) {
        // If no ID, exit silently
        exit;
    }

    mpLog("------------------------------------------------");
    mpLog("Notification Received. Payment ID MP: $paymentId");

    // 4. Fetch Access Token from Database
    $stmtSettings = $pdo->query("SELECT * FROM system_settings WHERE id = 1");
    $settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
    
    // Clean token
    $accessToken = isset($settings['mp_access_token']) ? preg_replace('/\s+/', '', $settings['mp_access_token']) : '';

    if (empty($accessToken)) {
        mpLog("Error: MP Token not configured.");
        exit;
    }

    // 5. OFFICIAL QUERY TO MERCADO PAGO API
    $ch = curl_init("https://api.mercadopago.com/v1/payments/$paymentId");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200 && $httpCode != 201) {
        mpLog("Error querying MP API. HTTP: $httpCode. ID: $paymentId");
        exit;
    }

    $paymentData = json_decode($response, true);
    
    $status = $paymentData['status'] ?? 'unknown'; 
    $externalRef = $paymentData['external_reference'] ?? ''; 
    $mpTransactionId = $paymentData['id'] ?? $paymentId; 

    mpLog("Status MP: $status | Ref Local: $externalRef");

    // 6. DATABASE UPDATE
    if ($status === 'approved' && !empty($externalRef)) {
        
        // Find payment in school system
        $stmtPay = $pdo->prepare("SELECT id, status, studentId FROM payments WHERE id = :id");
        $stmtPay->execute([':id' => $externalRef]);
        $localPayment = $stmtPay->fetch(PDO::FETCH_ASSOC);

        if ($localPayment) {
            if ($localPayment['status'] !== 'Pago') {
                try {
                    $pdo->beginTransaction();

                    $updateSql = "UPDATE payments SET status = 'Pago', paymentDate = NOW(), mp_payment_id = :mp_id WHERE id = :id";
                    $stmtUpdate = $pdo->prepare($updateSql);
                    $stmtUpdate->execute([
                        ':mp_id' => $mpTransactionId,
                        ':id' => $externalRef
                    ]);

                    $pdo->commit();
                    mpLog("SUCCESS: Payment #$externalRef cleared.");

                } catch (Exception $e) {
                    $pdo->rollBack();
                    mpLog("SQL ERROR: " . $e->getMessage());
                }
            } else {
                mpLog("Ignored: Payment #$externalRef was already paid.");
            }
        } else {
            mpLog("ERROR: Payment #$externalRef not found in database.");
        }
    }

} catch (Throwable $e) {
    // Capture fatal PHP 7+ errors to avoid returning 500
    if (function_exists('mpLog')) {
        mpLog("SYSTEM CRASH: " . $e->getMessage());
    }
}
?>