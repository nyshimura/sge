<?php
/**
 * libs/auto_migrate.php
 * Sistema de Atualização Inteligente (Verifica package.json)
 */

set_time_limit(300);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- CONFIGURAÇÕES DO GITHUB ---
define('GITHUB_USER',   'nyshimura');      
define('GITHUB_REPO',   'sge');  
define('GITHUB_BRANCH', 'main');             
define('GITHUB_TOKEN',  '');                 // Necessário para ler repositórios privados
// ---------------------------------------------

// Ajuste de conexão para rodar em /libs/
if (!isset($conn)) {
    if (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
        if (isset($pdo) && !isset($conn)) $conn = $pdo;
    }
}

// ==============================================================================
// FUNÇÕES DE VERSÃO (NOVO)
// ==============================================================================

function getLocalVersion() {
    $path = __DIR__ . '/../package.json';
    if (!file_exists($path)) return '0.0.0';
    
    $json = json_decode(file_get_contents($path), true);
    return $json['version'] ?? '0.0.0';
}

function getRemoteVersion(&$logs) {
    // URL do arquivo cru (Raw) no GitHub
    $url = "https://raw.githubusercontent.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/package.json";
    
    $opts = ['http' => ['method' => 'GET', 'header' => ['User-Agent: PHP-Updater']]];
    if (!empty(GITHUB_TOKEN)) {
        // Para repos privados, a URL muda para API e o header é obrigatório
        $url = "https://api.github.com/repos/" . GITHUB_USER . "/" . GITHUB_REPO . "/contents/package.json?ref=" . GITHUB_BRANCH;
        $opts['http']['header'][] = "Authorization: token " . GITHUB_TOKEN;
        $opts['http']['header'][] = "Accept: application/vnd.github.v3.raw"; // Pede o conteúdo raw
    }

    $context = stream_context_create($opts);
    $content = @file_get_contents($url, false, $context);
    
    if (!$content) {
        $logs[] = "⚠️ Não foi possível ler o package.json remoto (GitHub).";
        return null;
    }

    $json = json_decode($content, true);
    return $json['version'] ?? null;
}

// ==============================================================================
// ATUALIZAÇÃO DE ARQUIVOS
// ==============================================================================

function downloadAndExtractUpdate(&$logs) {
    // 1. VERIFICAÇÃO DE VERSÃO
    $localVer = getLocalVersion();
    $remoteVer = getRemoteVersion($logs);

    $logs[] = "🔍 Versão Local: <strong>$localVer</strong>";
    
    if ($remoteVer) {
        $logs[] = "🔍 Versão GitHub: <strong>$remoteVer</strong>";
        
        // Se a versão remota NÃO for maior que a local, avisa mas permite forçar
        if (version_compare($remoteVer, $localVer, '<=')) {
            if (!isset($_GET['force'])) {
                $logs[] = "✅ O sistema já está atualizado. (Use ?action=update_system&force=1 para reinstalar)";
                return false;
            } else {
                $logs[] = "⚠️ Reinstalando versão mesmo estando atualizado (Modo Forçado).";
            }
        } else {
            $logs[] = "✨ <strong>Nova atualização disponível! Iniciando...</strong>";
        }
    }

    // 2. DOWNLOAD E EXTRAÇÃO (Código original mantido)
    $zipUrl = "https://github.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/archive/refs/heads/" . GITHUB_BRANCH . ".zip";
    $tempZip = __DIR__ . '/update_temp.zip';
    $extractPath = __DIR__ . '/update_temp_folder';
    
    $opts = ['http' => ['method' => 'GET', 'header' => ['User-Agent: PHP-Updater']]];
    if (!empty(GITHUB_TOKEN)) $opts['http']['header'][] = "Authorization: token " . GITHUB_TOKEN;
    
    $context = stream_context_create($opts);
    $fileContent = @file_get_contents($zipUrl, false, $context);

    if (!$fileContent) {
        $logs[] = "❌ Falha no download do ZIP.";
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
            recursiveCopy($sourceRoot, $systemRoot, $logs);
            $logs[] = "🚀 Sistema atualizado para v$remoteVer!";
        } else {
            $logs[] = "❌ Erro: Estrutura do ZIP inválida.";
        }
        cleanupTemp($tempZip, $extractPath);
        return true;
    } else {
        $logs[] = "❌ Erro ao abrir ZIP.";
        return false;
    }
}

function recursiveCopy($src, $dst, &$logs) {
    $dir = opendir($src);
    @mkdir($dst);
    while (($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            
            // Protege o Database Config
            if (strpos($dstPath, 'config/database.php') !== false) {
                // $logs[] = "🛡️ Ignorado: config/database.php"; // Comentei para não poluir log
                continue;
            }

            if (is_dir($srcPath)) {
                recursiveCopy($srcPath, $dstPath, $logs);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }
    closedir($dir);
}

function cleanupTemp($zip, $folder) {
    @unlink($zip);
    deleteDirectory($folder);
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

// ==============================================================================
// MIGRAÇÃO DE BANCO (Mantida igual)
// ==============================================================================
function checkAndAddColumn($conn, $table, $column, $sqlCommand) {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->rowCount() == 0) {
            $conn->exec($sqlCommand);
            return "✅ [DB] Coluna '$column' criada em '$table'.";
        }
        return null;
    } catch (PDOException $e) { return "❌ [DB] Erro: " . $e->getMessage(); }
}

$migrations = [
    ['table'=>'courses', 'column'=>'schedule_json', 'command'=>"ALTER TABLE courses ADD COLUMN schedule_json TEXT DEFAULT NULL"],
    // ... (suas outras migrações aqui) ...
];

// ==============================================================================
// EXECUÇÃO
// ==============================================================================
$logs = [];

// Ação de Atualizar Arquivos
if (isset($_GET['action']) && $_GET['action'] == 'update_system') {
    downloadAndExtractUpdate($logs);
}

// Ação de Migrar Banco
if (isset($conn)) {
    foreach ($migrations as $mig) {
        $res = checkAndAddColumn($conn, $mig['table'], $mig['column'], $mig['command']);
        if ($res) $logs[] = $res;
    }
}

// HTML OUTPUT
if (basename($_SERVER['PHP_SELF']) == 'auto_migrate.php') {
    echo "<body style='font-family:sans-serif; background:#f4f6f9; padding:20px;'>";
    echo "<div style='max-width:800px; margin:0 auto; background:white; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);'>";
    echo "<h2 style='color:#2c3e50; border-bottom:2px solid #eee; padding-bottom:10px;'>Status da Atualização</h2>";
    
    if (empty($logs)) {
        echo "<p style='color:#27ae60;'><strong>Sistema sincronizado.</strong> Banco de dados OK.</p>";
        // Mostra versão atual se não houver logs
        $v = getLocalVersion();
        echo "<p style='color:#7f8c8d; font-size:0.9em;'>Versão Atual: v$v</p>";
    } else {
        echo "<div style='background:#2d3436; color:#dfe6e9; padding:15px; border-radius:5px; overflow:auto; max-height:500px;'>";
        foreach($logs as $log) echo "<div>$log</div>";
        echo "</div>";
    }
    
    echo "<div style='margin-top:20px; text-align:right;'>";
    echo "<a href='../admin/system_settings.php' style='display:inline-block; padding:10px 20px; background:#3498db; color:white; text-decoration:none; border-radius:5px; font-weight:bold;'>Voltar</a>";
    echo "</div></div></body>";
}
?>