<?php
// api/handlers/update_handler.php
require_once '../config.php';

// Aumenta o tempo e memória para garantir o processo
ini_set('max_execution_time', 300); 
ini_set('memory_limit', '256M');
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// --- CAMINHOS ABSOLUTOS (Mais Seguro) ---
$baseDir = realpath(__DIR__ . '/../../'); // Raiz do site
$tempZip = $baseDir . '/temp_update.zip';
$tempExtract = $baseDir . '/temp_update_folder';

function getLocalVersion() {
    global $baseDir;
    $path = $baseDir . '/package.json';
    if (!file_exists($path)) return '0.0.0';
    $content = file_get_contents($path);
    $json = json_decode($content, true);
    return $json['version'] ?? '0.0.0';
}

function getRemoteVersion() {
    $url = "https://raw.githubusercontent.com/" . REPO_OWNER . "/" . REPO_NAME . "/" . REPO_BRANCH . "/package.json";
    $opts = ["http" => ["method" => "GET", "header" => "User-Agent: SGE-Updater\r\n"]];
    if (defined('GITHUB_TOKEN') && GITHUB_TOKEN != '') {
        $opts['http']['header'] .= "Authorization: token " . GITHUB_TOKEN . "\r\n";
    }
    $context = stream_context_create($opts);
    $content = @file_get_contents($url, false, $context);
    return ($content) ? (json_decode($content, true)['version'] ?? false) : false;
}

function recurseCopy($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);
    while(false !== ( $file = readdir($dir)) ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            if ( is_dir($src . '/' . $file) ) {
                recurseCopy($src . '/' . $file,$dst . '/' . $file);
            } else {
                copy($src . '/' . $file,$dst . '/' . $file);
            }
        }
    }
    closedir($dir);
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

if ($action === 'check') {
    $local = getLocalVersion();
    $remote = getRemoteVersion();

    if (!$remote) {
        echo json_encode(['error' => 'Não foi possível conectar ao GitHub.']);
        exit;
    }

    $hasUpdate = version_compare($remote, $local, '>');
    echo json_encode(['local_version' => $local, 'remote_version' => $remote, 'has_update' => $hasUpdate]);

} elseif ($action === 'update') {
    $zipUrl = "https://github.com/" . REPO_OWNER . "/" . REPO_NAME . "/archive/refs/heads/" . REPO_BRANCH . ".zip";

    // 1. Download
    $fp = fopen($tempZip, 'w+');
    $ch = curl_init($zipUrl);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SGE-Updater');
    if (defined('GITHUB_TOKEN') && GITHUB_TOKEN != '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: token " . GITHUB_TOKEN]);
    }
    $exec = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$exec || $httpCode != 200) {
        @unlink($tempZip);
        echo json_encode(['success' => false, 'message' => 'Erro download ZIP. HTTP: ' . $httpCode]);
        exit;
    }

    // 2. Extração
    $zip = new ZipArchive;
    if ($zip->open($tempZip) === TRUE) {
        if(is_dir($tempExtract)) deleteDirectory($tempExtract);
        
        $zip->extractTo($tempExtract);
        $zip->close();
        
        // Localiza pasta interna do GitHub (ex: sge-main)
        $files = scandir($tempExtract);
        $internalFolder = '';
        foreach($files as $f) {
            if($f != '.' && $f != '..' && is_dir($tempExtract . '/' . $f)) {
                $internalFolder = $tempExtract . '/' . $f;
                break;
            }
        }

        if (!$internalFolder) {
            echo json_encode(['success' => false, 'message' => 'Pasta interna inválida.']);
            exit;
        }

        // 3. Segurança e Cópia
        if(file_exists($internalFolder . '/api/config.php')) {
            unlink($internalFolder . '/api/config.php');
        }

        // Copia para a raiz (usando caminho absoluto)
        recurseCopy($internalFolder, $baseDir);
        
        // ============================================================
        // 4. AUTO-MIGRAÇÃO (DEBUG APRIMORADO)
        // ============================================================
        $migrationFile = $baseDir . '/api/auto_migrate.php';
        $migrationMsg = "";

        // Limpa cache de status de arquivo do PHP para ver o arquivo novo imediatamente
        clearstatcache(true, $migrationFile);

        if(file_exists($migrationFile)) {
            try {
                // Torna $conn disponível explicitamente para o include
                global $conn; 
                include $migrationFile;
                
                if (isset($logs) && is_array($logs) && !empty($logs)) {
                    $migrationMsg = "\n\n[Banco de Dados]:\n" . implode("\n", $logs);
                } else {
                    $migrationMsg = "\n\n[Banco de Dados]: Verificado (Nenhuma alteração pendente).";
                }
            } catch (Exception $e) {
                $migrationMsg = "\n\n[Erro Migração]: " . $e->getMessage();
            }
        } else {
            $migrationMsg = "\n\n[Aviso]: Arquivo 'api/auto_migrate.php' não encontrado em: " . $migrationFile;
        }
        // ============================================================

        // 5. Limpeza Final
        @unlink($tempZip);
        deleteDirectory($tempExtract);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Atualização concluída!' . $migrationMsg
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao abrir o ZIP.']);
    }
} else {
    echo json_encode(['error' => 'Ação inválida']);
}
?>
