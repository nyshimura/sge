<?php
/**
 * libs/auto_migrate.php
 * Sistema de Atualização Automática (Arquivos + Banco via database.sql)
 */

ob_start();
set_time_limit(300);
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// --- CONFIGURAÇÕES GITHUB ---
define('GITHUB_USER',   'nyshimura');      
define('GITHUB_REPO',   'sge');  
define('GITHUB_BRANCH', 'main');             
define('GITHUB_TOKEN',  ''); // Se for repositório privado, coloque o token aqui

// Conexão com o Banco
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

// --- FUNÇÕES DE VERSÃO E DOWNLOAD (Mantidas e Otimizadas) ---

function getLocalVersion() {
    $path = __DIR__ . '/../package.json';
    if (!file_exists($path)) return '0.0.0';
    $json = json_decode(file_get_contents($path), true);
    return $json['version'] ?? '0.0.0';
}

function getRemoteVersion() {
    $url = "https://raw.githubusercontent.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/package.json";
    $opts = ['http' => ['method' => 'GET', 'header' => ['User-Agent: PHP-Updater']]];
    if (!empty(GITHUB_TOKEN)) {
        $url = "https://api.github.com/repos/" . GITHUB_USER . "/" . GITHUB_REPO . "/contents/package.json?ref=" . GITHUB_BRANCH;
        $opts['http']['header'][] = "Authorization: token " . GITHUB_TOKEN;
        $opts['http']['header'][] = "Accept: application/vnd.github.v3.raw";
    }
    $context = stream_context_create($opts);
    $content = @file_get_contents($url, false, $context);
    return $content ? (json_decode($content, true)['version'] ?? null) : null;
}

function recursiveCopy($src, $dst, &$resp) {
    $dir = opendir($src);
    @mkdir($dst);
    while (($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            // Protege o arquivo de conexão para não ser sobrescrito
            if (strpos($dstPath, 'config/database.php') !== false) continue;
            
            if (is_dir($srcPath)) {
                recursiveCopy($srcPath, $dstPath, $resp);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }
    closedir($dir);
}

function downloadAndExtractUpdate(&$resp) {
    $localVer = getLocalVersion();
    $remoteVer = getRemoteVersion();
    
    $resp['version_local'] = $localVer;
    $resp['version_remote'] = $remoteVer ?: 'Erro ao ler';

    if ($remoteVer && version_compare($remoteVer, $localVer, '<=') && !isset($_GET['force'])) {
        addLog($resp, "Sistema já está atualizado (v$localVer).", 'success');
        return true; 
    }
    
    if ($remoteVer) addLog($resp, "Nova versão detectada: v$remoteVer. Baixando...", 'info');

    $zipUrl = "https://github.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/archive/refs/heads/" . GITHUB_BRANCH . ".zip";
    $tempZip = __DIR__ . '/update_temp.zip';
    $extractPath = __DIR__ . '/update_temp_folder';
    
    // Deleta temp antigos se existirem
    if (file_exists($tempZip)) unlink($tempZip);
    if (is_dir($extractPath)) deleteDirectory($extractPath);

    $opts = ['http' => ['method' => 'GET', 'header' => ['User-Agent: PHP-Updater']]];
    if (!empty(GITHUB_TOKEN)) $opts['http']['header'][] = "Authorization: token " . GITHUB_TOKEN;
    
    $fileContent = @file_get_contents($zipUrl, false, stream_context_create($opts));

    if (!$fileContent) {
        addLog($resp, "Falha no download do ZIP.", 'error');
        return false;
    }
    file_put_contents($tempZip, $fileContent);

    $zip = new ZipArchive;
    if ($zip->open($tempZip) === TRUE) {
        if (!is_dir($extractPath)) mkdir($extractPath, 0755, true);
        $zip->extractTo($extractPath);
        $zip->close();
        
        $subFolders = scandir($extractPath);
        $sourceRoot = null;
        foreach ($subFolders as $folder) {
            if ($folder != '.' && $folder != '..' && is_dir($extractPath . '/' . $folder)) {
                $sourceRoot = $extractPath . '/' . $folder;
                break;
            }
        }

        if ($sourceRoot) {
            $systemRoot = realpath(__DIR__ . '/../');
            recursiveCopy($sourceRoot, $systemRoot, $resp);
            addLog($resp, "Arquivos atualizados com sucesso!", 'success');
        }
        
        // Limpeza
        @unlink($tempZip);
        deleteDirectory($extractPath);
        return true;
    } else {
        addLog($resp, "Erro ao abrir ZIP.", 'error');
        return false;
    }
}

function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// --- NOVA LÓGICA DE MIGRAÇÃO AUTOMÁTICA VIA SQL ---

function syncDatabaseFromSql($conn, &$resp) {
    $sqlFile = __DIR__ . '/../database.sql';

    if (!file_exists($sqlFile)) {
        addLog($resp, "Arquivo database.sql não encontrado na raiz.", 'warning');
        return;
    }

    $sqlContent = file_get_contents($sqlFile);
    
    // 1. Extrai as definições de tabela (CREATE TABLE)
    // Regex poderosa para pegar o nome da tabela e o corpo dela
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*)\)\s*(?:ENGINE|DEFAULT|CHARSET|;)/sUi', $sqlContent, $matches);

    if (empty($matches[0])) {
        addLog($resp, "Nenhuma tabela encontrada no database.sql.", 'warning');
        return;
    }

    foreach ($matches[1] as $idx => $tableName) {
        $tableBody = $matches[2][$idx];
        
        // Verifica se a tabela existe
        try {
            $stmt = $conn->query("SHOW TABLES LIKE '$tableName'");
            
            // A. SE A TABELA NÃO EXISTIR -> CRIA
            if ($stmt->rowCount() == 0) {
                $createQuery = "CREATE TABLE IF NOT EXISTS `$tableName` ($tableBody) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
                $conn->exec($createQuery);
                addLog($resp, "Tabela criada: $tableName", 'success');
                continue; // Passa para a próxima tabela
            }

            // B. SE A TABELA EXISTIR -> VERIFICA COLUNAS
            // Regex para pegar linhas que começam com `nome_coluna`
            preg_match_all('/^\s*`(\w+)`\s+(.*?),?$/m', $tableBody, $colMatches);

            foreach ($colMatches[1] as $cIdx => $colName) {
                // Ignora chaves primárias ou constraints definidas no final
                if (in_array(strtoupper($colName), ['PRIMARY', 'KEY', 'CONSTRAINT', 'UNIQUE', 'FOREIGN'])) continue;

                $colDef = trim($colMatches[2][$cIdx]);
                // Remove vírgula final se tiver
                if (substr($colDef, -1) == ',') $colDef = substr($colDef, 0, -1);

                // Verifica se a coluna existe na tabela do banco
                $stmtCol = $conn->query("SHOW COLUMNS FROM `$tableName` LIKE '$colName'");
                
                if ($stmtCol->rowCount() == 0) {
                    // Coluna não existe, vamos adicionar!
                    $alterSQL = "ALTER TABLE `$tableName` ADD COLUMN `$colName` $colDef";
                    $conn->exec($alterSQL);
                    addLog($resp, "Coluna adicionada: $colName em $tableName", 'success');
                }
            }

        } catch (Exception $e) {
            addLog($resp, "Erro ao sincronizar $tableName: " . $e->getMessage(), 'error');
        }
    }
}

// --- EXECUÇÃO ---

$action = $_GET['action'] ?? '';

try {
    // 1. Atualizar Arquivos (Se solicitado)
    if ($action == 'update_system') {
        addLog($response, "Iniciando atualização de arquivos...", 'info');
        downloadAndExtractUpdate($response);
    }

    // 2. Migrar Banco (Roda sempre que chamar o script)
    if ($conn) {
        addLog($response, "Sincronizando banco de dados...", 'info');
        
        // Chama a nova função mágica
        syncDatabaseFromSql($conn, $response);

        // Se quiser manter migrações manuais específicas, pode deixar aqui também:
        // checkAndAddColumn($conn, 'tabela', 'coluna', 'comando sql...');
        
    } else {
        addLog($response, "Sem conexão com Banco de Dados.", 'error');
    }

    $response['success'] = true;

} catch (Exception $e) {
    addLog($response, "Erro Fatal: " . $e->getMessage(), 'error');
}

// Limpa buffer e envia JSON
ob_end_clean();
echo json_encode($response);
exit;
?>