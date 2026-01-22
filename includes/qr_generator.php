<?php
// includes/qr_generator.php

require_once __DIR__ . '/../config/database.php';

// 1. Carrega a Lib de QR Code Manual (phpqrcode)
// Certifique-se de que a pasta libs/phpqrcode existe e contém qrlib.php
if (file_exists(__DIR__ . '/../libs/phpqrcode/qrlib.php')) {
    require_once __DIR__ . '/../libs/phpqrcode/qrlib.php';
}

// 2. Carrega a Lib do Mercado Pago
if (file_exists(__DIR__ . '/../libs/mp/MPPix.php')) {
    require_once __DIR__ . '/../libs/mp/MPPix.php';
}

/**
 * CLASSE PARA PIX MANUAL (BR Code)
 */
class PixPayload {
    const ID_PAYLOAD_FORMAT_INDICATOR = '00';
    const ID_MERCHANT_ACCOUNT_INFORMATION = '26';
    const ID_MERCHANT_ACCOUNT_INFORMATION_GUI = '00';
    const ID_MERCHANT_ACCOUNT_INFORMATION_KEY = '01';
    const ID_MERCHANT_CATEGORY_CODE = '52';
    const ID_TRANSACTION_CURRENCY = '53';
    const ID_TRANSACTION_AMOUNT = '54';
    const ID_COUNTRY_CODE = '58';
    const ID_MERCHANT_NAME = '59';
    const ID_MERCHANT_CITY = '60';
    const ID_ADDITIONAL_DATA_FIELD_TEMPLATE = '62';
    const ID_ADDITIONAL_DATA_FIELD_TEMPLATE_TXID = '05';
    const ID_CRC16 = '63';

    private $pixKey;
    private $merchantName;
    private $merchantCity;
    private $amount;
    private $txid;

    public function setPixKey($pixKey) { $this->pixKey = $pixKey; return $this; }
    public function setMerchantName($merchantName) { $this->merchantName = $merchantName; return $this; }
    public function setMerchantCity($merchantCity) { $this->merchantCity = $merchantCity; return $this; }
    public function setAmount($amount) { $this->amount = (string)number_format($amount, 2, '.', ''); return $this; }
    public function setTxid($txid) { $this->txid = $txid; return $this; }

    private function getValue($id, $value) {
        $size = str_pad(strlen($value), 2, '0', STR_PAD_LEFT);
        return $id . $size . $value;
    }

    private function getMerchantAccountInformation() {
        $gui = $this->getValue(self::ID_MERCHANT_ACCOUNT_INFORMATION_GUI, 'br.gov.bcb.pix');
        $key = $this->getValue(self::ID_MERCHANT_ACCOUNT_INFORMATION_KEY, $this->pixKey);
        return $this->getValue(self::ID_MERCHANT_ACCOUNT_INFORMATION, $gui . $key);
    }

    private function getAdditionalDataFieldTemplate() {
        $txid = $this->getValue(self::ID_ADDITIONAL_DATA_FIELD_TEMPLATE_TXID, $this->txid ? $this->txid : '***');
        return $this->getValue(self::ID_ADDITIONAL_DATA_FIELD_TEMPLATE, $txid);
    }

    private function getCRC16($payload) {
        $payload .= self::ID_CRC16 . '04';
        $polinomio = 0x1021;
        $resultado = 0xFFFF;
        if (($length = strlen($payload)) > 0) {
            for ($offset = 0; $offset < $length; $offset++) {
                $resultado ^= (ord($payload[$offset]) << 8);
                for ($bitwise = 0; $bitwise < 8; $bitwise++) {
                    if (($resultado <<= 1) & 0x10000) $resultado ^= $polinomio;
                    $resultado &= 0xFFFF;
                }
            }
        }
        return strtoupper(str_pad(dechex($resultado), 4, '0', STR_PAD_LEFT));
    }

    public function getPayload() {
        $payload = $this->getValue(self::ID_PAYLOAD_FORMAT_INDICATOR, '01') .
                   $this->getMerchantAccountInformation() .
                   $this->getValue(self::ID_MERCHANT_CATEGORY_CODE, '0000') .
                   $this->getValue(self::ID_TRANSACTION_CURRENCY, '986') .
                   $this->getValue(self::ID_TRANSACTION_AMOUNT, $this->amount) .
                   $this->getValue(self::ID_COUNTRY_CODE, 'BR') .
                   $this->getValue(self::ID_MERCHANT_NAME, substr($this->merchantName, 0, 25)) .
                   $this->getValue(self::ID_MERCHANT_CITY, substr($this->merchantCity, 0, 15)) .
                   $this->getAdditionalDataFieldTemplate();

        return $payload . self::ID_CRC16 . '04' . $this->getCRC16($payload);
    }
}

/**
 * FUNÇÃO CENTRALIZADORA
 */
function generatePixForPayment($paymentId, $pdo) {
    // 1. Busca Configurações do Sistema (Mercado Pago)
    $settings = $pdo->query("SELECT * FROM system_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    
    // 2. Busca Perfil da Escola (Chave Pix Manual e Nome)
    // --- CORREÇÃO: Busca a chave manual na tabela certa ---
    $school = $pdo->query("SELECT * FROM school_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    // 3. Busca Dados do Pagamento
    $stmtPay = $pdo->prepare("
        SELECT p.id, p.amount, c.name as courseName, u.email, u.firstName 
        FROM payments p
        JOIN users u ON p.studentId = u.id
        JOIN courses c ON p.courseId = c.id
        WHERE p.id = :pid
    ");
    $stmtPay->execute([':pid' => $paymentId]);
    $payment = $stmtPay->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        return ['success' => false, 'error' => 'Pagamento não encontrado.'];
    }

    // --- CENÁRIO A: MERCADO PAGO ATIVO ---
    if (!empty($settings['mp_active']) && $settings['mp_active'] == 1 && !empty($settings['mp_access_token'])) {
        
        if (!class_exists('MPPix')) {
            return ['success' => false, 'error' => 'Biblioteca MPPix não encontrada.'];
        }

        $mp = new MPPix($settings['mp_access_token']);
        
        $email = filter_var($payment['email'], FILTER_VALIDATE_EMAIL) ? $payment['email'] : 'aluno@sistema.com';
        $desc = "Pgto #" . $payment['id'] . " - " . substr($payment['courseName'], 0, 20);

        $result = $mp->createPayment(
            $payment['amount'], 
            $desc, 
            $email, 
            $payment['firstName'], 
            $payment['id']
        );

        if ($result['success']) {
            return [
                'success' => true,
                'type' => 'mp',
                'copia_e_cola' => $result['qr_code'],
                'qr_image_base64' => $result['qr_image'],
                'mp_id' => $result['mp_id']
            ];
        } else {
            return ['success' => false, 'error' => 'Erro MP: ' . $result['error']];
        }
    }

    // --- CENÁRIO B: PIX MANUAL (ESTÁTICO) ---
    
    // Busca a chave no perfil da escola (school_profile)
    $pixKey = !empty($school['pixKey']) ? $school['pixKey'] : ''; 
    
    if (empty($pixKey)) {
        return ['success' => false, 'error' => 'Chave PIX manual não configurada em Perfil da Escola e Mercado Pago inativo.'];
    }

    $merchantName = !empty($school['schoolName']) ? substr($school['schoolName'], 0, 25) : 'Escola';
    $merchantCity = !empty($school['city']) ? substr($school['city'], 0, 15) : 'SAO PAULO';
    $txId = 'PGTO' . $payment['id'];

    // Gera o Payload (String Copia e Cola)
    $pixObj = (new PixPayload())
        ->setPixKey($pixKey)
        ->setMerchantName($merchantName)
        ->setMerchantCity($merchantCity)
        ->setAmount($payment['amount'])
        ->setTxid($txId);

    $payload = $pixObj->getPayload();

    // Gera a imagem PNG em Base64 usando phpqrcode
    $imageBase64 = null;
    if (class_exists('QRcode')) {
        ob_start(); 
        QRcode::png($payload, null, QR_ECLEVEL_M, 5); 
        $imageString = ob_get_contents(); 
        ob_end_clean();
        $imageBase64 = base64_encode($imageString);
    } else {
        // Fallback: Se não tiver a lib de imagem, retorna erro ou link externo
        // return ['success' => false, 'error' => 'Biblioteca phpqrcode não encontrada.'];
    }

    return [
        'success' => true,
        'type' => 'static',
        'copia_e_cola' => $payload,
        'qr_image_base64' => $imageBase64 
    ];
}
?>