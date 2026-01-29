<?php
// includes/pix_engine.php

/**
 * Motor Central de Processamento PIX
 * Lógica:
 * 1. Banco Inter (Se ativo em system_settings)
 * 2. Fallback -> generatePixForPayment (que decide entre Mercado Pago ou Manual)
 */
function processPixRequest($paymentId, $studentId, $pdo) {
    
    // --- 1. Carrega Dependências ---
    // Carrega seu gerador original (que tem a classe PixPayload e lógica MP/Manual)
    require_once __DIR__ . '/qr_generator.php'; 

    // --- 2. Valida Pagamento ---
    $stmt = $pdo->prepare("SELECT id, amount, status FROM payments WHERE id = :pid AND studentId = :sid");
    $stmt->execute([':pid' => $paymentId, ':sid' => $studentId]);
    $payData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payData) {
        return ['success' => false, 'error' => 'Pagamento não encontrado.'];
    }
    
    if ($payData['status'] == 'Pago') {
        return ['success' => false, 'error' => 'Pagamento já realizado.'];
    }

    // --- 3. Verifica Configuração do Inter ---
    // Busca APENAS a config do Inter, pois as outras o qr_generator já busca
    $settings = $pdo->query("SELECT inter_active FROM system_settings WHERE id = 1")->fetch();

    // --- ROTA A: BANCO INTER (Prioridade Máxima) ---
    if (!empty($settings['inter_active']) && $settings['inter_active'] == 1) {
        
        $handlerPath = __DIR__ . '/inter_handler.php';
        
        if (file_exists($handlerPath)) {
            require_once $handlerPath;
            
            // Chama função do Inter (que retorna 'copia_e_cola')
            $result = gerarPixInter($payData['amount'], $paymentId, "Mensalidade Escolar");
            
            // ADAPTAÇÃO: O Inter não gera imagem nativamente.
            // Vamos usar a biblioteca 'phpqrcode' que você JÁ TEM no qr_generator.php para gerar a imagem do Inter também!
            if (isset($result['success']) && $result['success'] && !empty($result['copia_e_cola'])) {
                
                if (class_exists('QRcode')) {
                    ob_start(); 
                    QRcode::png($result['copia_e_cola'], null, QR_ECLEVEL_M, 5); 
                    $imageString = ob_get_contents(); 
                    ob_end_clean();
                    $result['qr_image_base64'] = base64_encode($imageString);
                } else {
                    // Fallback se a lib falhar: API Externa
                    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=10&data=' . urlencode($result['copia_e_cola']);
                    $imgContent = @file_get_contents($qrUrl);
                    if ($imgContent) $result['qr_image_base64'] = base64_encode($imgContent);
                }
            }
            return $result;
        } else {
            return ['success' => false, 'error' => 'Módulo Inter não instalado (inter_handler.php).'];
        }
    }

    // --- ROTA B: SISTEMA LEGADO (Mercado Pago ou Manual) ---
    // Se não for Inter, deixa seu código original trabalhar. Ele é ótimo.
    return generatePixForPayment($paymentId, $pdo);
}
?>