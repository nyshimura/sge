<?php
/**
 * libs/auto_migrate.php
 * Sistema de Atualização Robusto v2.0
 * - Download Seguro
 * - Extração Inteligente (detecta subpastas do GitHub)
 * - Migração de Banco idempotente (não quebra se já existir)
 */

// 1. Configurações de Ambiente
if (ob_get_length()) ob_clean(); // Limpa buffers anteriores
ob_start(); // Inicia novo buffer para garantir JSON puro no final

set_time_limit(600); // 10 minutos limite
ini_set('display_errors', 0); // Não mostrar erros na tela (quebra o JSON)
ini_set('memory_limit', '256M');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// --- CREDENCIAIS DO REPOSITÓRIO ---
define('GITHUB_USER',   'nyshimura');      
define('GITHUB_REPO',   'sge');  
define('GITHUB_BRANCH', 'main');             
define('GITHUB_TOKEN',  ''); // Deixe vazio se for repositório público

// 2. Conexão com Banco de Dados
$conn = null;
$dbConfigFile = __DIR__ . '/../config/database.php';

if (file_exists($dbConfigFile)) {
    require_once $dbConfigFile;
    if (isset($pdo)) $conn = $pdo;
}

// Array de Resposta Padrão
$response = [
    'success' => false,
    'update_available' => false,
    'logs' => [],
    'version_local' => '0.0.0',
    'version_remote' => '---'
];

function addLog(&$resp, $msg, $type = 'info') {
    $timestamp = date('H:i:s');
    $resp['logs'][] = ['msg' => "[$timestamp] $msg", 'type' => $type];
}

// --- FUNÇÕES DE VERSÃO ---
function getLocalVersion() {
    $path = __DIR__ . '/../package.json';
    clearstatcache(true, $path);
    return file_exists($path) ? (json_decode(file_get_contents($path), true)['version'] ?? '0.0.0') : '0.0.0';
}

function getRemoteVersion() {
    $url = "https://raw.githubusercontent.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/" . GITHUB_BRANCH . "/package.json";
    // Contexto para evitar cache e simular browser
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: PHP-Updater',
                'Cache-Control: no-cache'
            ]
        ]
    ];
    if (!empty(GITHUB_TOKEN)) $opts['http']['header'][] = "Authorization: token " . GITHUB_TOKEN;
    
    $ctx = stream_context_create($opts);
    $c = @file_get_contents($url, false, $ctx);
    return $c ? (json_decode($c, true)['version'] ?? null) : null;
}

// --- MIGRAÇÕES DE BANCO DE DADOS (LISTA COMPLETA) ---
function getMigrations() {
    return [
        // 1. Configurações e Pagamentos (Antigos)
        ['type'=>'col', 't'=>'system_settings', 'c'=>'term_text_adult', 'sql'=>"ALTER TABLE system_settings ADD COLUMN term_text_adult text DEFAULT NULL"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'term_text_minor', 'sql'=>"ALTER TABLE system_settings ADD COLUMN term_text_minor text DEFAULT NULL"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'mp_client_id', 'sql'=>"ALTER TABLE system_settings ADD COLUMN mp_client_id VARCHAR(255) NULL AFTER mp_access_token"],
        ['type'=>'col', 't'=>'system_settings', 'c'=>'mp_client_secret', 'sql'=>"ALTER TABLE system_settings ADD COLUMN mp_client_secret VARCHAR(255) NULL AFTER mp_client_id"],
        ['type'=>'col', 't'=>'payments', 'c'=>'method', 'sql'=>"ALTER TABLE `payments` ADD COLUMN `method` varchar(50) DEFAULT NULL AFTER `paymentDate`"],
        ['type'=>'col', 't'=>'courses', 'c'=>'thumbnail', 'sql'=>"ALTER TABLE courses ADD COLUMN thumbnail LONGTEXT DEFAULT NULL"],
        
        // 2. Tabelas Básicas
        ['type'=>'tbl', 't'=>'school_recess', 'sql'=>"CREATE TABLE IF NOT EXISTS school_recess (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"],
        ['type'=>'tbl', 't'=>'course_teachers', 'sql'=>"CREATE TABLE IF NOT EXISTS `course_teachers` (`id` int(11) NOT NULL AUTO_INCREMENT, `courseId` int(11) NOT NULL, `teacherId` int(11) NOT NULL, `commissionRate` decimal(5,2) DEFAULT 0.00, `createdAt` timestamp NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), KEY `courseId` (`courseId`), KEY `teacherId` (`teacherId`), CONSTRAINT `course_teachers_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE, CONSTRAINT `course_teachers_ibfk_2` FOREIGN KEY (`teacherId`) REFERENCES `users` (`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"],

        // 3. NOVAS TABELAS DE EVENTOS (O que implementamos hoje)
        [
            'type' => 'tbl', 
            't' => 'event_terms', 
            'sql' => "CREATE TABLE IF NOT EXISTS `event_terms` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `courseId` int(11) NOT NULL,
                `title` varchar(255) NOT NULL,
                `content` longtext NOT NULL,
                `status` enum('active','concluded') NOT NULL DEFAULT 'active',
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `courseId` (`courseId`),
                CONSTRAINT `event_terms_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        [
            'type' => 'tbl', 
            't' => 'event_term_responses', 
            'sql' => "CREATE TABLE IF NOT EXISTS `event_term_responses` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `term_id` int(11) NOT NULL,
                `studentId` int(11) NOT NULL,
                `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
                `responded_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `term_id` (`term_id`),
                KEY `studentId` (`studentId`),
                CONSTRAINT `event_resp_ibfk_1` FOREIGN KEY (`term_id`) REFERENCES `event_terms` (`id`) ON DELETE CASCADE,
                CONSTRAINT `event_resp_ibfk_2` FOREIGN KEY (`studentId`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],
        // 4. Garante a coluna STATUS na tabela de eventos (caso a tabela já existisse sem ela)
        ['type'=>'col', 't'=>'event_terms', 'c'=>'status', 'sql'=>"ALTER TABLE `event_terms` ADD COLUMN `status` ENUM('active', 'concluded') NOT NULL DEFAULT 'active' AFTER `content`"]
    ];
}

function runMigrations($conn, &$resp) {
    if (!$conn) {
        addLog($resp, "AVISO: Sem conexão com banco. Migrações puladas.", 'warning');
        return;
    }

    $migrations = getMigrations();
    addLog($resp, "Verificando " . count($migrations) . " migrações...", 'info');

    foreach ($migrations as $mig) {
        $table = $mig['t'];
        $sql   = $mig['sql'];
        $type  = $mig['type'];

        try {
            if ($type === 'col') {
                $col = $mig['c'];
                // Verifica se a tabela existe
                $stmtT = $conn->query("SHOW TABLES LIKE '$table'");
                if ($stmtT->rowCount() > 0) {
                    // Verifica se a coluna já existe
                    $stmtC = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
                    if ($stmtC->rowCount() == 0) {
                        $conn->exec($sql);
                        addLog($resp, "DB: Coluna '$col' criada em '$table'.", 'success');
                    }
                }
            } 
            elseif ($type === 'tbl') {
                // Tenta criar tabela (IF NOT EXISTS já resolve, mas o log ajuda)
                $stmtT = $conn->query("SHOW TABLES LIKE '$table'");
                if ($stmtT->rowCount() == 0) {
                    $conn->exec($sql);
                    addLog($resp, "DB: Tabela '$table' criada.", 'success');
                }
            }
        } catch (Exception $e) {
            // Loga erro mas não para o script (pode ser erro de sintaxe ou algo menor)
            addLog($resp, "Erro DB ($table): " . $e->getMessage(), 'warning');
        }
    }
}

// --- FUNÇÕES DE ARQUIVO ---
function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

function recursiveCopy($src, $dst, &$resp) {
    $dir = opendir($src);
    @mkdir($dst, 0755, true);
    
    $copied = 0;
    while (($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            
            // PROTEÇÃO: Não sobrescrever o arquivo de conexão
            if (strpos($dstPath, 'config/database.php') !== false) {
                // addLog($resp, "Ignorado: database.php (protegido)", 'info');
                continue;
            }

            if (is_dir($srcPath)) {
                $copied += recursiveCopy($srcPath, $dstPath, $resp);
            } else {
                if (@copy($srcPath, $dstPath)) {
                    $copied++;
                } else {
                    addLog($resp, "Falha ao copiar: $file", 'error');
                }
            }
        }
    }
    closedir($dir);
    return $copied;
}

// --- FLUXO PRINCIPAL ---
$action = $_GET['action'] ?? 'check';

try {
    $local = getLocalVersion();
    $remote = getRemoteVersion();
    
    $response['version_local'] = $local;
    $response['version_remote'] = $remote ?: 'Erro';
    
    // Comparação simples de versão
    if ($remote && $remote !== $local) {
        $response['update_available'] = true;
    }

    if ($action == 'perform_update') {
        
        // 1. Download
        addLog($response, "Baixando atualização...", 'info');
        $zipUrl = "https://github.com/" . GITHUB_USER . "/" . GITHUB_REPO . "/archive/refs/heads/" . GITHUB_BRANCH . ".zip";
        $tempZip = __DIR__ . '/temp_update.zip';
        $extractPath = __DIR__ . '/temp_extract';
        
        if (file_exists($tempZip)) unlink($tempZip);
        if (is_dir($extractPath)) deleteDirectory($extractPath);

        // Download com Stream Context (importante para alguns servidores)
        $opts = ['http' => ['method' => 'GET', 'header' => ['User-Agent: PHP-Updater']]];
        if (!empty(GITHUB_TOKEN)) $opts['http']['header'][] = "Authorization: token " . GITHUB_TOKEN;
        
        $zipContent = @file_get_contents($zipUrl, false, stream_context_create($opts));
        
        if (!$zipContent) throw new Exception("Falha no download do GitHub.");
        file_put_contents($tempZip, $zipContent);

        // 2. Extração
        $zip = new ZipArchive;
        if ($zip->open($tempZip) === TRUE) {
            $zip->extractTo($extractPath);
            $zip->close();
            
            // 3. Localizar pasta raiz dentro do ZIP (GitHub cria uma pasta tipo 'sge-main')
            $sourceRoot = null;
            $files = scandir($extractPath);
            foreach ($files as $f) {
                if ($f != '.' && $f != '..' && is_dir("$extractPath/$f")) {
                    $sourceRoot = "$extractPath/$f";
                    break;
                }
            }

            if ($sourceRoot) {
                // 4. Copiar Arquivos
                $systemRoot = dirname(__DIR__); // Sobe um nível para a raiz do SGE
                addLog($response, "Aplicando arquivos...", 'info');
                $count = recursiveCopy($sourceRoot, $systemRoot, $response);
                addLog($response, "Arquivos atualizados: $count", 'success');
                
                // 5. Rodar Migrations
                runMigrations($conn, $response);
                
                // Limpa Opcache (pra garantir que o PHP leia os arquivos novos)
                if (function_exists('opcache_reset')) opcache_reset();

                // Atualiza versão local na resposta
                $response['version_local'] = getLocalVersion();

            } else {
                throw new Exception("Estrutura do ZIP inválida.");
            }
        } else {
            throw new Exception("Não foi possível abrir o arquivo ZIP.");
        }

        // Limpeza
        @unlink($tempZip);
        deleteDirectory($extractPath);
    }

    $response['success'] = true;

} catch (Exception $e) {
    $response['success'] = false;
    addLog($response, "Erro Fatal: " . $e->getMessage(), 'error');
}

// Finaliza Buffer e cospe JSON
ob_end_clean();
echo json_encode($response);
exit;
