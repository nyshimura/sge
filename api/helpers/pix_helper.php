<?php
/**
 * Classe utilitária para gerar o Payload do PIX (EMV QRCPS MPM)
 * Baseado nos padrões do Banco Central do Brasil.
 */
class PixPayload {
    
    /**
     * IDs do Payload do Pix
     */
    const ID_PAYLOAD_FORMAT_INDICATOR = '00';
    const ID_MERCHANT_ACCOUNT_INFORMATION = '26';
    const ID_MERCHANT_ACCOUNT_INFORMATION_GUI = '00';
    const ID_MERCHANT_ACCOUNT_INFORMATION_KEY = '01';
    const ID_MERCHANT_ACCOUNT_INFORMATION_DESCRIPTION = '02';
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

    public function setPixKey($pixKey) {
        $this->pixKey = $pixKey;
        return $this;
    }

    public function setMerchantName($merchantName) {
        $this->merchantName = $merchantName;
        return $this;
    }

    public function setMerchantCity($merchantCity) {
        $this->merchantCity = $merchantCity;
        return $this;
    }

    public function setAmount($amount) {
        $this->amount = (string)number_format($amount, 2, '.', '');
        return $this;
    }

    public function setTxid($txid) {
        $this->txid = $txid;
        return $this;
    }

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

    /**
     * Calcula o CRC16 (Cyclic Redundancy Check) conforme norma ISO/IEC 13239
     */
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

        return self::ID_CRC16 . '04' . strtoupper(dechex($resultado));
    }

    public function getPayload() {
        $payload = $this->getValue(self::ID_PAYLOAD_FORMAT_INDICATOR, '01') .
                   $this->getMerchantAccountInformation() .
                   $this->getValue(self::ID_MERCHANT_CATEGORY_CODE, '0000') .
                   $this->getValue(self::ID_TRANSACTION_CURRENCY, '986') . // 986 = BRL
                   $this->getValue(self::ID_TRANSACTION_AMOUNT, $this->amount) .
                   $this->getValue(self::ID_COUNTRY_CODE, 'BR') .
                   $this->getValue(self::ID_MERCHANT_NAME, substr($this->merchantName, 0, 25)) . // Max 25 chars
                   $this->getValue(self::ID_MERCHANT_CITY, substr($this->merchantCity, 0, 15)) . // Max 15 chars
                   $this->getAdditionalDataFieldTemplate();

        return $payload . $this->getCRC16($payload);
    }
}
?>