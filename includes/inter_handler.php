<?php
// includes/inter_handler.php

function gerarPixInter($valor, $paymentId, $descricao) {
    global $pdo;

    // 1. CARREGA CONFIGURAÇÕES E CREDENCIAIS
    // Busca credenciais e flag de sandbox
    $settings = $pdo->query("SELECT inter_client_id, inter_client_secret, inter_cert_file, inter_key_file, inter_sandbox FROM system_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    
    // Busca a Chave Pix na tabela school_profile
    $school = $pdo->query("SELECT pixKey FROM school_profile WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $chavePix = $school['pixKey'];

    // Define URLs com base no modo Sandbox
    $isSandbox = (isset($settings['inter_sandbox']) && $settings['inter_sandbox'] == 1);
    $baseUrl = $isSandbox ? 'https://cdpj-sandbox.partners.uatinter.co' : 'https://cdpj.partners.bancointer.com.br';

    // Caminhos dos certificados
    $certPath = __DIR__ . '/../certs/' . $settings['inter_cert_file'];
    $keyPath  = __DIR__ . '/../certs/' . $settings['inter_key_file'];

    // Validações Iniciais
    if (!file_exists($certPath) || !file_exists($keyPath)) {
        return ['success' => false, 'error' => 'Certificados .crt ou .key não encontrados na pasta certs.'];
    }
    if (empty($chavePix)) {
        return ['success' => false, 'error' => 'Chave Pix não cadastrada no Perfil da Escola.'];
    }

    // 2. BUSCA DADOS DO ALUNO (Obrigatório para Inter)
    $stmtUser = $pdo->prepare("SELECT u.firstName, u.lastName, u.cpf FROM payments p JOIN users u ON p.studentId = u.id WHERE p.id = :pid");
    $stmtUser->execute([':pid' => $paymentId]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    // Sanitiza CPF (remove pontos e traços)
    $cpf = preg_replace('/[^0-9]/', '', $user['cpf'] ?? '');
    
    // Nome do aluno (limite de 200 chars)
    $nome = mb_strimwidth(($user['firstName'] . ' ' . $user['lastName']), 0, 200, "", "UTF-8");

    if (empty($cpf)) {
        return ['success' => false, 'error' => 'O aluno precisa ter CPF cadastrado para gerar Pix Inter.'];
    }

    // 3. AUTENTICAÇÃO OAUTH (Obter Token)
    $clientId     = $settings['inter_client_id'];
    $clientSecret = $settings['inter_client_secret'];
    $scope        = "cob.write cob.read"; // Escopo para Cobrança Imediata Pix

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$baseUrl/oauth/v2/token"); // URL Dinâmica
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => $scope,
        'grant_type' => 'client_credentials'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);
    curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
    curl_setopt($ch, CURLOPT_SSLKEY, $keyPath);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode != 200) {
        $err = curl_error($ch);
        curl_close($ch);
        
        // Tenta extrair mensagem de erro do JSON
        $jsonResponse = json_decode($response, true);
        $msgErro = $jsonResponse['error_description'] ?? ($jsonResponse['error'] ?? $response);
        
        return ['success' => false, 'error' => 'Erro Auth Inter (' . $httpCode . '): ' . $msgErro];
    }
    
    $authData = json_decode($response, true);
    $accessToken = $authData['access_token'];
    curl_close($ch);

    // 4. CRIAR A COBRANÇA (PUT)
    
    // Gera um txid único (26 a 35 caracteres)
    // Padrão: SGE + Zeros + ID do Pagamento. Ex: SGE000000000000000000000000846
    $txid = 'SGE' . str_pad($paymentId, 27, '0', STR_PAD_LEFT);

    $body = [
        "calendario" => [
            "expiracao" => 3600 // 1 hora de validade
        ],
        "devedor" => [
            "cpf" => $cpf,
            "nome" => $nome
        ],
        "valor" => [
            "original" => number_format($valor, 2, '.', '')
        ],
        "chave" => $chavePix,
        "solicitacaoPagador" => mb_strimwidth($descricao, 0, 140, "", "UTF-8")
    ];

    $ch = curl_init();
    // URL Dinâmica com txid no final para PUT
    curl_setopt($ch, CURLOPT_URL, "$baseUrl/pix/v2/cob/$txid");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_SSLCERT, $certPath);
    curl_setopt($ch, CURLOPT_SSLKEY, $keyPath);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $pixResponse = curl_exec($ch);
    $pixHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $pixData = json_decode($pixResponse, true);

    // Sucesso: 201 (Criado) ou 200 (Atualizado/OK)
    if (($pixHttpCode == 200 || $pixHttpCode == 201) && isset($pixData['pixCopiaECola'])) {
        
        // Salva o TXID no banco para o webhook identificar depois
        try {
            $upd = $pdo->prepare("UPDATE payments SET transaction_code = :tcode WHERE id = :pid");
            $upd->execute([':tcode' => $txid, ':pid' => $paymentId]);
        } catch (Exception $e) {
            // Se der erro no update (ex: coluna não existe), segue o fluxo para exibir o QR Code
        }

        return [
            'success' => true,
            'copia_e_cola' => $pixData['pixCopiaECola'],
            // A imagem visual do QR Code será gerada pelo pix_engine.php usando a lib local
        ];
    } else {
        // Tratamento de erros detalhado do Inter
        $erroMsg = isset($pixData['detail']) ? $pixData['detail'] : (isset($pixData['mensagem']) ? $pixData['mensagem'] : json_encode($pixData));
        
        if (isset($pixData['violacoes']) && is_array($pixData['violacoes'])) {
            $erroMsg = "";
            foreach($pixData['violacoes'] as $v) $erroMsg .= $v['razao'] . " | ";
        }
        
        return ['success' => false, 'error' => 'Inter recusou: ' . $erroMsg];
    }
}
?>