<?php
/**
 * libs/auto_migrate.php
 * Sistema de Atualização (Retorno JSON para Modal)
 */

// Limpa qualquer output anterior para não quebrar o JSON
ob_start();

set_time_limit(300);
ini_set('display_errors', 0); // Desliga erros na tela (vão pro log)
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// --- CONFIGURAÇÕES ---
define('GITHUB_USER',   'nyshimura');      
define('GITHUB_REPO',   'sge');  
define('GITHUB_BRANCH', 'main');             
define('GITHUB_TOKEN',  '');                 

// Ajuste de conexão
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
    // Tipos: info, success, warning, error
    $resp['logs'][] = ['msg' => $msg, 'type' => $type];
}

// ... (Funções auxiliares: getLocalVersion, getRemoteVersion, downloadAndExtract, recursiveCopy, cleanupTemp) ...
// VOU RESUMIR AS FUNÇÕES AQUI MANTENDO A LÓGICA, MAS ADAPTANDO PARA O ARRAY DE LOGS

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
    if (!$content) return null;
    $json = json_decode($content, true);
    return $json['version'] ?? null;
}

function downloadAndExtractUpdate(&$resp) {
    // 1. Verificação
    $localVer = getLocalVersion();
    $remoteVer = getRemoteVersion();
    
    $resp['version_local'] = $localVer;
    $resp['version_remote'] = $remoteVer ?: 'Erro ao ler';

    if ($remoteVer) {
        if (version_compare($remoteVer, $localVer, '<=') && !isset($_GET['force'])) {
            addLog($resp, "Sistema já está atualizado (v$localVer).", 'success');
            return true; 
        }
        addLog($resp, "Nova versão detectada: v$remoteVer. Iniciando...", 'info');
    }

    // 2. Download
    $zipUrl = "https://github.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/archive/refs/heads/" . GITHUB_BRANCH . ".zip";
    $tempZip = __DIR__ . '/update_temp.zip';
    $extractPath = __DIR__ . '/update_temp_folder';
    
    $opts = ['http' => ['method' => 'GET', 'header' => ['User-Agent: PHP-Updater']]];
    if (!empty(GITHUB_TOKEN)) $opts['http']['header'][] = "Authorization: token " . GITHUB_TOKEN;
    
    $context = stream_context_create($opts);
    $fileContent = @file_get_contents($zipUrl, false, $context);

    if (!$fileContent) {
        addLog($resp, "Falha no download do ZIP.", 'error');
        return false;
    }
    file_put_contents($tempZip, $fileContent);
    addLog($resp, "Download concluído.", 'info');

    // 3. Extração
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
        } else {
            addLog($resp, "Erro: Estrutura do ZIP inválida.", 'error');
        }
        cleanupTemp($tempZip, $extractPath);
        return true;
    } else {
        addLog($resp, "Erro ao abrir ZIP.", 'error');
        return false;
    }
}

function recursiveCopy($src, $dst, &$resp) {
    $dir = opendir($src);
    @mkdir($dst);
    while (($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            if (strpos($dstPath, 'config/database.php') !== false) {
                // addLog($resp, "Protegido: database.php", 'warning'); // Opcional logar
                continue;
            }
            if (is_dir($srcPath)) {
                recursiveCopy($srcPath, $dstPath, $resp);
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

// Função Banco
function checkAndAddColumn($conn, $table, $column, $sqlCommand) {
    try {
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->rowCount() == 0) {
            $conn->exec($sqlCommand);
            return "Coluna '$column' criada em '$table'.";
        }
        return null;
    } catch (PDOException $e) { return "Erro DB ($table): " . $e->getMessage(); }
}

// --- EXECUÇÃO ---

$action = $_GET['action'] ?? '';

try {
    // 1. Atualizar Arquivos
    if ($action == 'update_system') {
        addLog($response, "Iniciando atualização de arquivos...", 'info');
        downloadAndExtractUpdate($response);
    }

    // 2. Migrar Banco (Sempre roda se houver conexão)
    if ($conn) {
        addLog($response, "Verificando banco de dados...", 'info');
        
        $migrations = [
            ['table'=>'courses', 'column'=>'schedule_json', 'command'=>"ALTER TABLE courses ADD COLUMN schedule_json TEXT DEFAULT NULL"],
            ['table'=>'users', 'column'=>'phone', 'command'=>"ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) DEFAULT NULL"],
            ['table'=>'courses', 'column'=>'closed_date', 'command'=>"ALTER TABLE courses ADD COLUMN closed_date DATETIME DEFAULT NULL"],
            ['table'=>'enrollments', 'column'=>'customDueDay', 'command'=>"ALTER TABLE `enrollments` ADD COLUMN `customDueDay` int(2) DEFAULT NULL"],
            ['table'=>'payments', 'column'=>'reminderSent', 'command'=>"ALTER TABLE `payments` ADD COLUMN `reminderSent` tinyint(1) NOT NULL DEFAULT 0"],
            ['table'=>'system_settings', 'column'=>'email_reminder_subject', 'command'=>"ALTER TABLE `system_settings` ADD COLUMN `email_reminder_subject` varchar(255) DEFAULT 'Lembrete'"],
            ['table'=>'system_settings', 'column'=>'email_reminder_body', 'command'=>"ALTER TABLE `system_settings` ADD COLUMN `email_reminder_body` text DEFAULT NULL"],
            ['table'=>'system_settings', 'column'=>'reminderDaysBefore', 'command'=>"ALTER TABLE `system_settings` ADD COLUMN `reminderDaysBefore` int(11) NOT NULL DEFAULT 3"]
        ];

        foreach ($migrations as $mig) {
            $res = checkAndAddColumn($conn, $mig['table'], $mig['column'], $mig['command']);
            if ($res) addLog($response, $res, 'success');
        }
        
        // Template
        $chk = $conn->query("SELECT email_reminder_body FROM system_settings WHERE id=1");
        if ($chk && $chk->fetchColumn() === null) {
            $defaultBody = 'Olá {{aluno_nome}},\n\nSua mensalidade vence em {{vencimento}}.\nValor: {{valor}}';
            $conn->exec("UPDATE system_settings SET email_reminder_body='$defaultBody' WHERE id=1");
            addLog($response, "Template de e-mail padrão inserido.", 'success');
        }
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
