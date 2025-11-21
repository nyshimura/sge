<?php
require_once '../config.php';

// Segurança: Verifique se o usuário é admin na sua lógica de sessão
// session_start();
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { die(json_encode(['error' => 'Não autorizado'])); }

header('Content-Type: application/json');
ini_set('max_execution_time', 300); // 5 minutos para baixar

$action = $_GET['action'] ?? '';

function getLocalVersion() {
    $path = '../../package.json';
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
    if ($content === false) return false;
    $json = json_decode($content, true);
    return $json['version'] ?? false;
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
    $tempZip = '../../temp_update.zip';
    $tempExtract = '../../temp_update_folder';

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
    curl_close($ch);
    fclose($fp);

    if (!$exec) {
        echo json_encode(['success' => false, 'message' => 'Erro no download.']);
        if(file_exists($tempZip)) unlink($tempZip);
        exit;
    }

    $zip = new ZipArchive;
    if ($zip->open($tempZip) === TRUE) {
        deleteDirectory($tempExtract);
        $zip->extractTo($tempExtract);
        $zip->close();
        
        $files = scandir($tempExtract);
        $internalFolder = '';
        foreach($files as $f) {
            if($f != '.' && $f != '..' && is_dir($tempExtract . '/' . $f)) {
                $internalFolder = $tempExtract . '/' . $f;
                break;
            }
        }

        // === PROTEÇÃO DO CONFIG.PHP ===
        if(file_exists($internalFolder . '/api/config.php')) {
            unlink($internalFolder . '/api/config.php');
        }
        // ==============================

        // Copia arquivos novos
        recurseCopy($internalFolder, '../../');
        
        // ============================================================
        // [NOVO] AUTO-MIGRAÇÃO DE BANCO DE DADOS
        // ============================================================
        // Verifica se o script de migração existe (ele acabou de ser copiado/atualizado)
        // e o executa para garantir que o banco tenha as colunas novas.
        if(file_exists('../../api/auto_migrate.php')) {
            include '../../api/auto_migrate.php';
        }
        // ============================================================

        // Limpeza
        unlink($tempZip);
        deleteDirectory($tempExtract);
        
        echo json_encode(['success' => true, 'message' => 'Atualizado com sucesso!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Erro ao extrair.']);
    }
} else {
    echo json_encode(['error' => 'Ação inválida']);
}
?>