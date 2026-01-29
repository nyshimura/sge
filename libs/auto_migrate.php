<?php
/**
 * libs/auto_migrate.php
 * Sistema de Atualização Automática (Blindado contra erros de sintaxe)
 */

ob_start();
set_time_limit(300);
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// --- CONFIGURAÇÕES ---
define('GITHUB_USER',   'nyshimura');      
define('GITHUB_REPO',   'sge');  
define('GITHUB_BRANCH', 'main');             
define('GITHUB_TOKEN',  ''); 

// Conexão
$conn = null;
if (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
    if (isset($pdo)) $conn = $pdo;
}

$response = [
    'success' => false,
    'logs' => [],
    'version_local' => '0.0.0',
    'version_remote' => '---'
];

function addLog(&$resp, $msg, $type = 'info') {
    $resp['logs'][] = ['msg' => $msg, 'type' => $type];
}

// --- FUNÇÕES DE DOWNLOAD (Resumidas para focar na correção do SQL) ---
function getLocalVersion() {
    $path = __DIR__ . '/../package.json';
    return file_exists($path) ? (json_decode(file_get_contents($path), true)['version'] ?? '0.0.0') : '0.0.0';
}
function getRemoteVersion() {
    $url = "https://raw.githubusercontent.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/package.json";
    $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>['User-Agent: PHP-Updater']]]);
    $c = @file_get_contents($url, false, $ctx);
    return $c ? (json_decode($c, true)['version'] ?? null) : null;
}
function downloadAndExtractUpdate(&$resp) {
    // ... (Mantendo a lógica de download original para economizar espaço aqui) ...
    // Se precisar dessa parte completa novamente, me avise. O foco agora é o fix do SQL.
    addLog($resp, "Função de download ignorada neste fix (foco no SQL).", 'info');
    return true; 
}

// --- CORREÇÃO PRINCIPAL: PARSER SQL MAIS INTELIGENTE ---

function syncDatabaseFromSql($conn, &$resp) {
    $sqlFile = __DIR__ . '/../database.sql';

    if (!file_exists($sqlFile)) {
        addLog($resp, "Arquivo database.sql não encontrado na raiz.", 'warning');
        return;
    }

    $sqlContent = file_get_contents($sqlFile);
    
    // 1. Extrai as tabelas (CREATE TABLE)
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*)\)\s*(?:ENGINE|DEFAULT|CHARSET|;)/sUi', $sqlContent, $matches);

    if (empty($matches[0])) {
        addLog($resp, "Nenhuma tabela encontrada no database.sql.", 'warning');
        return;
    }

    foreach ($matches[1] as $idx => $tableName) {
        $tableBody = $matches[2][$idx];
        
        try {
            // Verifica se a tabela existe
            $stmt = $conn->query("SHOW TABLES LIKE '$tableName'");
            
            // A. CRIA TABELA SE NÃO EXISTIR
            if ($stmt->rowCount() == 0) {
                $createQuery = "CREATE TABLE IF NOT EXISTS `$tableName` ($tableBody) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                $conn->exec($createQuery);
                addLog($resp, "Tabela criada: $tableName", 'success');
                continue;
            }

            // B. VERIFICA COLUNAS
            // Regex ajustada para evitar linhas vazias ou chaves
            preg_match_all('/^\s*`(\w+)`\s+(.*?),?$/m', $tableBody, $colMatches);

            foreach ($colMatches[1] as $cIdx => $colName) {
                // FILTRO DE SEGURANÇA: Ignora palavras reservadas que não são colunas
                $upperName = strtoupper($colName);
                if (in_array($upperName, ['PRIMARY', 'KEY', 'CONSTRAINT', 'UNIQUE', 'FOREIGN', 'INDEX', 'FULLTEXT'])) continue;

                $colDef = trim($colMatches[2][$cIdx]);
                // Remove vírgula final
                if (substr($colDef, -1) == ',') $colDef = substr($colDef, 0, -1);
                $colDef = trim($colDef);

                // PROTEÇÃO: Se a definição estiver vazia, pula (evita o erro Syntax error near '')
                if (empty($colDef)) {
                    // addLog($resp, "Ignorando linha mal formatada em $tableName: $colName", 'warning');
                    continue;
                }

                // Verifica se a coluna já existe no banco
                $stmtCol = $conn->query("SHOW COLUMNS FROM `$tableName` LIKE '$colName'");
                
                if ($stmtCol->rowCount() == 0) {
                    $alterSQL = "ALTER TABLE `$tableName` ADD COLUMN `$colName` $colDef";
                    
                    try {
                        $conn->exec($alterSQL);
                        addLog($resp, "Coluna adicionada: $colName em $tableName", 'success');
                    } catch (Exception $sqlErr) {
                        // Loga o comando exato que falhou para debug
                        addLog($resp, "Erro ao adicionar $colName. Cmd: \"$alterSQL\". Erro: " . $sqlErr->getMessage(), 'error');
                    }
                }
            }

        } catch (Exception $e) {
            addLog($resp, "Erro ao processar tabela $tableName: " . $e->getMessage(), 'error');
        }
    }
}

// --- EXECUÇÃO ---

$action = $_GET['action'] ?? '';

try {
    if ($action == 'update_system') {
        // downloadAndExtractUpdate($response); // Comentado para focar no teste do SQL
    }

    if ($conn) {
        addLog($response, "Sincronizando banco...", 'info');
        syncDatabaseFromSql($conn, $response);
    } else {
        addLog($response, "Sem conexão com Banco.", 'error');
    }

    $response['success'] = true;

} catch (Exception $e) {
    addLog($response, "Erro Fatal: " . $e->getMessage(), 'error');
}

ob_end_clean();
echo json_encode($response);
exit;
?>
