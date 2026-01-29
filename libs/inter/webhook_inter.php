<?php
// sge/libs/inter/webhook_inter.php

// Força exibição de erros para debug (remova em produção se preferir)
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json");

// Caminho absoluto para o config (a prova de falhas)
$rootDir = dirname(dirname(__DIR__)); 
require_once $rootDir . '/config/database.php';

// Função de Log
function interLog($msg) {
    $file = __DIR__ . '/inter_webhook.log';
    $date = date('d/m/Y H:i:s');
    file_put_contents($file, "[$date] $msg" . PHP_EOL, FILE_APPEND);
}

try {
    // 1. Recebe o Payload
    $raw = file_get_contents('php://input');
    $dados = json_decode($raw, true);

    // Se não tiver dados, encerra
    if (empty($dados) || !isset($dados['pix'])) {
        echo json_encode(['status' => 'ignored', 'reason' => 'No pix data']);
        exit;
    }

    interLog("------------------------------------------------");
    interLog("Webhook recebido: " . $raw);

    $processados = 0;

    // 2. Loop pelos Pix recebidos (o Inter pode mandar mais de um)
    foreach ($dados['pix'] as $pix) {
        $txid = $pix['txid'];           // O nosso código: SGE000...846
        $e2eId = $pix['endToEndId'];    // ID único do BC
        $valorPago = $pix['valor'];     // Valor que caiu na conta

        // Verifica se é um Pix do nosso sistema
        if (strpos($txid, 'SGE') !== 0) {
            interLog("Ignorado: TXID não pertence ao SGE ($txid)");
            continue;
        }

        // 3. Busca o Pagamento no Banco
        // Usamos o transaction_code que vi na sua tabela payments
        $stmt = $pdo->prepare("
            SELECT p.id, p.amount, p.status, u.firstName, u.lastName 
            FROM payments p
            JOIN users u ON p.studentId = u.id
            WHERE p.transaction_code = :txid
        ");
        $stmt->execute([':txid' => $txid]);
        $fatura = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fatura) {
            interLog("Erro: Fatura não encontrada para o TXID $txid");
            continue;
        }

        // 4. Validações
        if ($fatura['status'] == 'Pago') {
            interLog("Aviso: Fatura #{$fatura['id']} já estava paga.");
            continue;
        }

        // Opcional: Validar valor (com margem de 1 centavo)
        /*
        if (abs($fatura['amount'] - $valorPago) > 0.01) {
            interLog("Erro: Valor divergente. Esperado: {$fatura['amount']}, Recebido: $valorPago");
            continue;
        }
        */

        // 5. Dá a Baixa (Update)
        // Atualiza status, data e método. O campo mp_payment_id pode ser usado para guardar o e2eId do Pix
        $upd = $pdo->prepare("
            UPDATE payments 
            SET status = 'Pago', 
                paymentDate = NOW(), 
                mp_payment_id = :e2eid, 
                method = 'Pix Inter'
            WHERE id = :id
        ");
        
        $upd->execute([
            ':e2eid' => $e2eId, // Guardamos o ID do Banco Central aqui para referência
            ':id' => $fatura['id']
        ]);

        $aluno = $fatura['firstName'] . ' ' . $fatura['lastName'];
        interLog("SUCESSO: Pagamento #{$fatura['id']} ($aluno) confirmado! Valor: R$ $valorPago");
        
        $processados++;
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok', 'processed' => $processados]);

} catch (Exception $e) {
    interLog("CRITICAL ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>