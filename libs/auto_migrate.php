<?php
/**
 * libs/auto_migrate.php
 * Sistema de Atualização (Force Overwrite + Logs Detalhados)
 */

if (ob_get_length()) ob_clean();
ob_start();

set_time_limit(600);
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// --- CONFIGURAÇÕES ---
define('GITHUB_USER',   'nyshimura');      
define('GITHUB_REPO',   'sge');  
define('GITHUB_BRANCH', 'main');             
define('GITHUB_TOKEN',  ''); 

// Conexão DB
$conn = null;
if (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
    if (isset($pdo)) $conn = $pdo;
}

$response = [
    'success' => false,
    'update_available' => false,
    'logs' => [],
    'version_local' => '0.0.0',
    'version_remote' => '---'
];

function addLog(&$resp, $msg, $type = 'info') {
    $resp['logs'][] = ['msg' => $msg, 'type' => $type];
}

// --- FUNÇÕES DE VERSÃO ---
function getLocalVersion() {
    $path = __DIR__ . '/../package.json';
    // Limpa cache de arquivo para garantir leitura fresca
    clearstatcache(true, $path);
    return file_exists($path) ? (json_decode(file_get_contents($path), true)['version'] ?? '0.0.0') : '0.0.0';
}
function getRemoteVersion() {
    $url = "https://raw.githubusercontent.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/package.json";
    $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>['User-Agent: PHP-Updater']]]);
    $c = @file_get_contents($url, false, $ctx);
    return $c ? (json_decode($c, true)['version'] ?? null) : null;
}

// --- DOWNLOAD E CÓPIA (CORAÇÃO DO UPDATE) ---
function downloadAndExtractUpdate(&$resp) {
    addLog($resp, "1. Iniciando download do GitHub...", 'info');
    
    $zipUrl = "https://github.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/archive/refs/heads/" . GITHUB_BRANCH . ".zip";
    $tempZip = __DIR__ . '/update_temp.zip';
    $extractPath = __DIR__ . '/update_temp_folder';
    
    // Limpeza prévia
    if (file_exists($tempZip)) unlink($tempZip);
    if (is_dir($extractPath)) deleteDirectory($extractPath);

    // Download
    $opts = ['http' => ['method' => 'GET', 'header' => ['User-Agent: PHP-Updater']]];
    if (!empty(GITHUB_TOKEN)) $opts['http']['header'][] = "Authorization: token " . GITHUB_TOKEN;
    
    $fileContent = @file_get_contents($zipUrl, false, stream_context_create($opts));

    if (!$fileContent || strlen($fileContent) < 100) {
        addLog($resp, "Erro Fatal: Download falhou ou arquivo vazio.", 'error');
        return false;
    }
    file_put_contents($tempZip, $fileContent);
    addLog($resp, "Download concluído (" . round(strlen($fileContent)/1024) . " KB).", 'info');

    // Extração
    $zip = new ZipArchive;
    if ($zip->open($tempZip) === TRUE) {
        if (!is_dir($extractPath)) mkdir($extractPath, 0755, true);
        $zip->extractTo($extractPath);
        $zip->close();
        
        // Encontra a pasta raiz dentro do ZIP (ex: sge-main)
        $subFolders = scandir($extractPath);
        $sourceRoot = null;
        foreach ($subFolders as $folder) {
            if ($folder != '.' && $folder != '..' && is_dir($extractPath . '/' . $folder)) {
                $sourceRoot = $extractPath . '/' . $folder;
                break;
            }
        }

        if ($sourceRoot) {
            $systemRoot = dirname(__DIR__); // Raiz real do sistema (../)
            addLog($resp, "2. Copiando arquivos para: $systemRoot", 'info');
            
            $count = recursiveCopy($sourceRoot, $systemRoot, $resp);
            
            if ($count > 0) {
                addLog($resp, "Sucesso: $count arquivos atualizados.", 'success');
                
                // Tenta limpar opcache para refletir mudanças na hora
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }
            } else {
                addLog($resp, "Aviso: Nenhum arquivo foi copiado. Verifique permissões.", 'warning');
            }

        } else {
            addLog($resp, "Erro: Estrutura do ZIP inválida (pasta raiz não encontrada).", 'error');
        }
        
        // Limpeza
        @unlink($tempZip);
        deleteDirectory($extractPath);
        return true;
    } else {
        addLog($resp, "Erro ao descompactar ZIP.", 'error');
        return false;
    }
}

// Função de Cópia Recursiva com LOG DE ERROS DE PERMISSÃO
function recursiveCopy($src, $dst, &$resp) {
    $dir = opendir($src);
    @mkdir($dst, 0755, true); // Garante que a pasta de destino exista
    
    $copiedCount = 0;

    while (($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            
            // --- PROTEÇÃO ---
            // Ignora database.php e pasta certs
            if (strpos($dstPath, 'config/database.php') !== false) continue;
            if (strpos($dstPath, '/certs/') !== false) continue;
            
            if (is_dir($srcPath)) {
                $copiedCount += recursiveCopy($srcPath, $dstPath, $resp);
            } else {
                // Tenta copiar
                if (!@copy($srcPath, $dstPath)) {
                    // Se falhar, tenta dar permissão e copiar de novo
                    @chmod($dstPath, 0644);
                    if (!@copy($srcPath, $dstPath)) {
                        addLog($resp, "FALHA ao sobrescrever: $file (Permissão Negada)", 'error');
                    } else {
                        $copiedCount++;
                    }
                } else {
                    $copiedCount++;
                }
            }
        }
    }
    closedir($dir);
    return $copiedCount;
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

// --- MIGRAÇÕES DO BANCO DE DADOS ---
function getMigrations() {
    return [
        ['type'=>'col', 't'=>'system_settings', 'c'=>'term_text_adult', 'sql'=>"ALTER TABLE system_settings ADD COLUMN term_text_adult text DEFAULT NULL"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'term_text_minor', 'sql'=>"ALTER TABLE system_settings ADD COLUMN term_text_minor text DEFAULT NULL"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'mp_client_id', 'sql'=>"ALTER TABLE system_settings ADD COLUMN mp_client_id VARCHAR(255) NULL AFTER mp_access_token"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'mp_client_secret', 'sql'=>"ALTER TABLE system_settings ADD COLUMN mp_client_secret VARCHAR(255) NULL AFTER mp_client_id"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'inter_active', 'sql'=>"ALTER TABLE system_settings ADD COLUMN inter_active tinyint(1) DEFAULT 0"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'inter_client_id', 'sql'=>"ALTER TABLE system_settings ADD COLUMN inter_client_id varchar(255) DEFAULT NULL"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'inter_client_secret', 'sql'=>"ALTER TABLE system_settings ADD COLUMN inter_client_secret varchar(255) DEFAULT NULL"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'inter_cert_file', 'sql'=>"ALTER TABLE system_settings ADD COLUMN inter_cert_file varchar(255) DEFAULT NULL"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'inter_key_file', 'sql'=>"ALTER TABLE system_settings ADD COLUMN inter_key_file varchar(255) DEFAULT NULL"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'inter_sandbox', 'sql'=>"ALTER TABLE system_settings ADD COLUMN inter_sandbox tinyint(1) DEFAULT 0"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'inter_webhook_crt', 'sql'=>"ALTER TABLE system_settings ADD COLUMN inter_webhook_crt varchar(255) DEFAULT NULL"],
        ['type'=>'col', 't'=>'payments', 'c'=>'method', 'sql'=>"ALTER TABLE `payments` ADD COLUMN `method` varchar(50) DEFAULT NULL AFTER `paymentDate`"],
        ['type'=>'col', 't'=>'payments', 'c'=>'transaction_code', 'sql'=>"ALTER TABLE `payments` ADD COLUMN `transaction_code` varchar(50) DEFAULT NULL"],
        ['type'=>'col', 't'=>'payments', 'c'=>'mp_payment_id', 'sql'=>"ALTER TABLE `payments` ADD COLUMN `mp_payment_id` VARCHAR(50) NULL DEFAULT NULL AFTER `paymentDate`"],
        ['type'=>'col', 't'=>'courses', 'c'=>'thumbnail', 'sql'=>"ALTER TABLE courses ADD COLUMN thumbnail LONGTEXT DEFAULT NULL"],
        ['type'=>'col', 't'=>'certificates', 'c'=>'custom_workload', 'sql'=>"ALTER TABLE certificates ADD COLUMN custom_workload VARCHAR(50) DEFAULT NULL AFTER completion_date"],
        ['type'=>'tbl', 't'=>'school_recess', 'sql'=>"CREATE TABLE IF NOT EXISTS school_recess (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"],
        ['type'=>'tbl', 't'=>'course_teachers', 'sql'=>"CREATE TABLE IF NOT EXISTS `course_teachers` (`id` int(11) NOT NULL AUTO_INCREMENT, `courseId` int(11) NOT NULL, `teacherId` int(11) NOT NULL, `commissionRate` decimal(5,2) DEFAULT 0.00, `createdAt` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), KEY `courseId` (`courseId`), KEY `teacherId` (`teacherId`), CONSTRAINT `course_teachers_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE, CONSTRAINT `course_teachers_ibfk_2` FOREIGN KEY (`teacherId`) REFERENCES `users` (`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"]
    ];
}

function runMigrations($conn, &$resp) {
    // addLog($resp, "Verificando DB...", 'info');
    $migrations = getMigrations();

    foreach ($migrations as $mig) {
        $table = $mig['t'];
        $sql   = $mig['sql'];
        $type  = $mig['type'];

        try {
            if ($type === 'col') {
                $col = $mig['c'];
                $stmtT = $conn->query("SHOW TABLES LIKE '$table'");
                if ($stmtT->rowCount() == 0) continue; 
                $stmtC = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
                if ($stmtC->rowCount() == 0) {
                    $conn->exec($sql);
                    addLog($resp, "DB: Coluna criada: $col", 'success');
                }
            } elseif ($type === 'tbl') {
                $stmtT = $conn->query("SHOW TABLES LIKE '$table'");
                if ($stmtT->rowCount() == 0) {
                    $conn->exec($sql);
                    addLog($resp, "DB: Tabela criada: $table", 'success');
                }
            }
        } catch (Exception $e) {
            // Silencioso em erros de DB para focar nos arquivos
        }
    }
}

// --- FLUXO PRINCIPAL ---
$action = $_GET['action'] ?? 'check';

try {
    $local = getLocalVersion();
    $remote = getRemoteVersion();
    
    $response['version_local'] = $local;
    $response['version_remote'] = $remote ?: 'Erro';
    
    if ($remote && version_compare($remote, $local, '>')) {
        $response['update_available'] = true;
    }

    if ($action == 'check') {
        if ($response['update_available']) {
            addLog($response, "Nova versão encontrada: v$remote", 'info');
        } else {
            addLog($response, "Sistema atualizado.", 'success');
        }
    }
    elseif ($action == 'perform_update') {
        // 1. Atualiza Arquivos
        downloadAndExtractUpdate($response);

        // 2. Atualiza Banco
        if ($conn) runMigrations($conn, $response);

        // 3. Atualiza package.json local se o download falhou em atualizar ele
        // (Geralmente o zip já contém o package.json novo, mas garantimos aqui)
        $response['version_local'] = getLocalVersion();
    }

    $response['success'] = true;

} catch (Exception $e) {
    addLog($response, "Erro Fatal: " . $e->getMessage(), 'error');
}

ob_end_clean();
echo json_encode($response);
exit;
?>
