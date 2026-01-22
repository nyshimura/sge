<?php
// libs/mp/MPPix.php

class MPPix {
    private $accessToken;

    public function __construct($accessToken) {
        // CORREÇÃO CRUCIAL: Remove espaços, quebras de linha e caracteres invisíveis do Token
        // Isso resolve o erro "Header X-Idempotency-Key can’t be null"
        $this->accessToken = preg_replace('/\s+/', '', $accessToken);
    }

    /**
     * Gera um pagamento PIX no Mercado Pago (API v1)
     */
    public function createPayment($amount, $description, $email, $firstName, $refId) {
        $url = 'https://api.mercadopago.com/v1/payments';

        // Garante e-mail válido
        $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'cliente@naoinformado.com';
        
        // Garante formato float
        $floatAmount = (float)$amount;

        // Gera uma chave de idempotência robusta (igual ao seu exemplo recharge.php)
        $idempotencyKey = 'PIX-' . $refId . '-' . uniqid();

        $data = [
            'transaction_amount' => $floatAmount,
            'description' => substr($description, 0, 255),
            'payment_method_id' => 'pix',
            'payer' => [
                'email' => $validEmail,
                'first_name' => $firstName
            ],
            'external_reference' => (string)$refId
            // 'notification_url' => 'https://seusite.com/webhook', // Descomente e ajuste quando tiver webhook
        ];

        // Headers definidos explicitamente
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessToken,
            'X-Idempotency-Key: ' . $idempotencyKey
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $curlErr = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'error' => 'Erro cURL: ' . $curlErr];
        }
        
        curl_close($ch);

        $json = json_decode($response, true);

        // Sucesso é 201 (Created)
        if ($httpCode == 201 && isset($json['point_of_interaction'])) {
            return [
                'success' => true,
                'qr_code' => $json['point_of_interaction']['transaction_data']['qr_code'],
                'qr_image' => $json['point_of_interaction']['transaction_data']['qr_code_base64'],
                'mp_id' => $json['id'],
                'status' => $json['status']
            ];
        } else {
            $errorMsg = isset($json['message']) ? $json['message'] : 'Erro desconhecido na API MP';
            
            // Tenta pegar erro detalhado da causa
            if(isset($json['cause']) && is_array($json['cause'])) {
                foreach($json['cause'] as $cause) {
                    $desc = $cause['description'] ?? '';
                    $errorMsg .= ' - ' . $desc;
                }
            }
            
            return [
                'success' => false,
                'error' => "Erro MP ($httpCode): $errorMsg",
                'debug_response' => $json
            ];
        }
    }
}
?>