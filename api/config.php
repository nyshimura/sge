<?php
/**
 * Arquivo de Configuração Principal
 */

// Configuração de erros para ambiente de produção.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// *** GARANTE O FUSO HORÁRIO PADRÃO ***
date_default_timezone_set('America/Sao_Paulo');

// --- CONFIGURAÇÃO DE CORS (Cross-Origin Resource Sharing) ---
// Permitir de qualquer origem (em produção, restrinja se possível)
header("Access-Control-Allow-Origin: *"); 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Resposta rápida para pre-flight requests (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}


// --- CREDENCIAIS DO BANCO DE DADOS ---
define('DB_HOST', 'localhost');
define('DB_USER', 'suabase');
define('DB_PASS', 'senha');
define('DB_NAME', 'dbname');

// --- CONEXÃO COM O BANCO DE DADOS (USANDO PDO) ---

/* * CONFIGURAÇÕES DE AUTO-UPDATE
 * Defina aqui o repositório de onde virão as atualizações.
 */
define('REPO_OWNER', 'nyshimura'); // Seu usuário no GitHub
define('REPO_NAME', 'sge');        // Nome do repositório
define('REPO_BRANCH', 'main');     // Branch principal (ex: main ou master)
// define('GITHUB_TOKEN', '');     // Opcional: Se o repositório for privado

$conn = null;
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Log do erro real no servidor
    error_log("Erro de Conexão com o Banco de Dados: " . $e->getMessage());
    
    // Resposta genérica para o cliente
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'data' => ['message' => 'Erro de Conexão com o Banco de Dados. Verifique o config.php.']
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

?>
