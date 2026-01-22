<?php
/**
 * libs/auto_migrate.php
 * Sistema de Atualização e Migração (Versão para pasta LIBS)
 */

// 1. CONFIGURAÇÕES GERAIS
set_time_limit(300); // 5 minutos para download/unzip
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- CONFIGURAÇÕES DO GITHUB (EDITAR AQUI) ---
define('GITHUB_USER',   'nyshimura');      // <-- Coloque seu usuário
define('GITHUB_REPO',   'sge');  // <-- Coloque seu repositório
define('GITHUB_BRANCH', 'main');             // Branch principal
define('GITHUB_TOKEN',  '');                 // Token (se privado)
// ---------------------------------------------

// 2. CONEXÃO COM BANCO DE DADOS
// Ajustado para rodar a partir de /libs/
if (!isset($conn)) {
    // Tenta achar o config voltando um nível (../config/database.php)
    if (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
        // Se o seu config cria $pdo em vez de $conn, fazemos o alias:
        if (isset($pdo) && !isset($conn)) $conn = $pdo;
    } else {
        die("❌ Erro Crítico: config/database.php não encontrado.");
    }
}

// ==============================================================================
// PARTE 1: ATUALIZAÇÃO DE ARQUIVOS (GITHUB)
// ==============================================================================

function downloadAndExtractUpdate(&$logs) {
    $zipUrl = "https://github.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/archive/refs/heads/" . GITHUB_BRANCH . ".zip";
    $tempZip = __DIR__ . '/update_temp.zip';
    $extractPath = __DIR__ . '/update_temp_folder';
    
    $logs[] = "⬇️ Baixando atualização de: $zipUrl";

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => ['User-Agent: PHP-Updater-Script']
        ]
    ];
    
    if (defined('GITHUB_TOKEN') && !empty(GITHUB_TOKEN)) {
        $opts['http']['header'][] = "Authorization: token " . GITHUB_TOKEN;
    }

    $context = stream_context_create($opts);
    $fileContent = @file_get_contents($zipUrl, false, $context);

    if (!$fileContent) {
        $logs[] = "❌ Falha no download. Verifique usuário/repo ou token (se privado).";
        return false;
    }

    file_put_contents($tempZip, $fileContent);
    $logs[] = "✅ Download concluído.";

    // Extração
    $zip = new ZipArchive;
    if ($zip->open($tempZip) === TRUE) {
        if (!is_dir($extractPath)) mkdir($extractPath, 0755, true);
        $zip->extractTo($extractPath);
        $zip->close();
        
        // Encontra a pasta raiz dentro do ZIP (ex: repo-main)
        $subFolders = scandir($extractPath);
        $sourceRoot = null;
        foreach ($subFolders as $folder) {
            if ($folder != '.' && $folder != '..' && is_dir($extractPath . '/' . $folder)) {
                $sourceRoot = $extractPath . '/' . $folder;
                break;
            }
        }

        if ($sourceRoot) {
            // A raiz do sistema é o diretório pai de libs/ (ou seja, ../)
            $systemRoot = realpath(__DIR__ . '/../');
            
            $logs[] = "📂 Copiando arquivos para: $systemRoot";
            recursiveCopy($sourceRoot, $systemRoot, $logs);
            $logs[] = "🚀 Arquivos atualizados com sucesso!";
        } else {
            $logs[] = "❌ Erro: Estrutura do ZIP inválida.";
        }

        cleanupTemp($tempZip, $extractPath);
        return true;
    } else {
        $logs[] = "❌ Erro ao abrir o arquivo ZIP.";
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

            // --- PROTEÇÃO DO CONFIG (ESSENCIAL) ---
            // Ignora se o arquivo for config/database.php para não sobrescrever senha
            if (strpos($dstPath, 'config/database.php') !== false) {
                $logs[] = "🛡️ Protegido: config/database.php (Mantido original)";
                continue;
            }
            // --------------------------------------

            if (is_dir($srcPath)) {
                recursiveCopy($srcPath, $dstPath, $logs);
            } else {
                if (!copy($srcPath, $dstPath)) {
                    $logs[] = "⚠️ Falha ao copiar: $file";
                }
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
// PARTE 2: MIGRAÇÃO DE BANCO DE DADOS
// ==============================================================================

function checkAndAddColumn($conn, $table, $column, $sqlCommand) {
    try {
        // Verifica se coluna existe
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($stmt->rowCount() == 0) {
            $conn->exec($sqlCommand);
            return "✅ [DB] Coluna '$column' criada em '$table'.";
        }
        return null; // Retorna null se já existe para não poluir o log
    } catch (PDOException $e) {
        return "❌ [DB ERRO] $table.$column: " . $e->getMessage();
    }
}

// LISTA DE MIGRAÇÕES (Adicione novas aqui)
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

// ==============================================================================
// PARTE 3: EXECUÇÃO
// ==============================================================================
$logs = [];

// Ação de Atualizar Arquivos (via GET)
if (isset($_GET['action']) && $_GET['action'] == 'update_system') {
    $logs[] = "🚀 INICIANDO UPDATE DE ARQUIVOS (GITHUB)...";
    downloadAndExtractUpdate($logs);
    $logs[] = "------------------------------------------";
}

// Ação de Migrar Banco (Sempre roda)
if (isset($conn)) {
    $logs[] = "🛠️ VERIFICANDO BANCO DE DADOS...";
    foreach ($migrations as $mig) {
        $res = checkAndAddColumn($conn, $mig['table'], $mig['column'], $mig['command']);
        if ($res) $logs[] = $res;
    }
    
    // Insere template padrão se não existir
    try {
        $chk = $conn->query("SELECT email_reminder_body FROM system_settings WHERE id=1");
        if ($chk && $chk->fetchColumn() === null) {
            $defaultBody = 'Olá {{aluno_nome}},\n\nSua mensalidade vence em {{vencimento}}.\nValor: {{valor}}';
            $conn->exec("UPDATE system_settings SET email_reminder_body='$defaultBody' WHERE id=1");
            $logs[] = "✅ [DB] Template de e-mail padrão inserido.";
        }
    } catch (Exception $e) {}
}

// EXIBIÇÃO NA TELA
if (basename($_SERVER['PHP_SELF']) == 'auto_migrate.php') {
    echo "<body style='font-family:sans-serif; background:#f4f6f9; padding:20px;'>";
    echo "<div style='max-width:800px; margin:0 auto; background:white; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.1);'>";
    echo "<h2 style='color:#2c3e50; border-bottom:2px solid #eee; padding-bottom:10px;'>Relatório de Atualização</h2>";
    
    if (empty($logs)) {
        echo "<p style='color:#27ae60;'><strong>Tudo atualizado!</strong> Nenhuma alteração de banco necessária.</p>";
    } else {
        echo "<pre style='background:#2d3436; color:#dfe6e9; padding:15px; border-radius:5px; overflow:auto;'>" . implode("\n", $logs) . "</pre>";
    }
    
    echo "<div style='margin-top:20px; text-align:right;'>";
    echo "<a href='../admin/system_settings.php' style='display:inline-block; padding:10px 20px; background:#3498db; color:white; text-decoration:none; border-radius:5px; font-weight:bold;'>Voltar para Painel</a>";
    echo "</div></div></body>";
}
?>
