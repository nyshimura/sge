<?php
/**
 * libs/auto_migrate.php
 * Sistema de Atualização Manual Controlada (Migrations Explícitas)
 */

if (ob_get_length()) ob_clean();
ob_start();

set_time_limit(300);
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// Configurações GitHub (apenas para exibir versão, não baixa SQL mais)
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
    'logs' => [],
    'version_local' => '0.0.0',
    'version_remote' => '---'
];

function addLog(&$resp, $msg, $type = 'info') {
    $resp['logs'][] = ['msg' => $msg, 'type' => $type];
}

// --- LISTA MESTRA DE ATUALIZAÇÕES ---
// Adicione suas novas alterações no final desta lista
function getMigrations() {
    return [
        // --- 1. CONFIGURAÇÕES GERAIS (TERMOS E MERCADO PAGO) ---
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'term_text_adult',
            'sql' => "ALTER TABLE system_settings ADD COLUMN term_text_adult text DEFAULT NULL"
        ],
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'term_text_minor',
            'sql' => "ALTER TABLE system_settings ADD COLUMN term_text_minor text DEFAULT NULL"
        ],
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'mp_client_id',
            'sql' => "ALTER TABLE system_settings ADD COLUMN mp_client_id VARCHAR(255) NULL AFTER mp_access_token"
        ],
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'mp_client_secret',
            'sql' => "ALTER TABLE system_settings ADD COLUMN mp_client_secret VARCHAR(255) NULL AFTER mp_client_id"
        ],

        // --- 2. CONFIGURAÇÕES BANCO INTER ---
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'inter_active',
            'sql' => "ALTER TABLE system_settings ADD COLUMN inter_active tinyint(1) DEFAULT 0"
        ],
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'inter_client_id',
            'sql' => "ALTER TABLE system_settings ADD COLUMN inter_client_id varchar(255) DEFAULT NULL"
        ],
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'inter_client_secret',
            'sql' => "ALTER TABLE system_settings ADD COLUMN inter_client_secret varchar(255) DEFAULT NULL"
        ],
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'inter_cert_file',
            'sql' => "ALTER TABLE system_settings ADD COLUMN inter_cert_file varchar(255) DEFAULT NULL"
        ],
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'inter_key_file',
            'sql' => "ALTER TABLE system_settings ADD COLUMN inter_key_file varchar(255) DEFAULT NULL"
        ],
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'inter_sandbox',
            'sql' => "ALTER TABLE system_settings ADD COLUMN inter_sandbox tinyint(1) DEFAULT 0"
        ],
        [
            'type' => 'column',
            'table' => 'system_settings',
            'column' => 'inter_webhook_crt',
            'sql' => "ALTER TABLE system_settings ADD COLUMN inter_webhook_crt varchar(255) DEFAULT NULL"
        ],

        // --- 3. TABELA PAGAMENTOS (Integrações) ---
        [
            'type' => 'column',
            'table' => 'payments',
            'column' => 'method',
            'sql' => "ALTER TABLE `payments` ADD COLUMN `method` varchar(50) DEFAULT NULL AFTER `paymentDate`"
        ],
        [
            'type' => 'column',
            'table' => 'payments',
            'column' => 'transaction_code',
            'sql' => "ALTER TABLE `payments` ADD COLUMN `transaction_code` varchar(50) DEFAULT NULL"
        ],
        [
            'type' => 'column',
            'table' => 'payments',
            'column' => 'mp_payment_id',
            'sql' => "ALTER TABLE `payments` ADD COLUMN `mp_payment_id` VARCHAR(50) NULL DEFAULT NULL AFTER `paymentDate`"
        ],

        // --- 4. TABELA CURSOS E CERTIFICADOS ---
        [
            'type' => 'column',
            'table' => 'courses',
            'column' => 'thumbnail',
            'sql' => "ALTER TABLE courses ADD COLUMN thumbnail LONGTEXT DEFAULT NULL"
        ],
        [
            'type' => 'column',
            'table' => 'certificates',
            'column' => 'custom_workload',
            'sql' => "ALTER TABLE certificates ADD COLUMN custom_workload VARCHAR(50) DEFAULT NULL AFTER completion_date"
        ],

        // --- 5. NOVA TABELA: SCHOOL_RECESS ---
        [
            'type' => 'create_table',
            'table' => 'school_recess',
            'sql' => "CREATE TABLE IF NOT EXISTS school_recess (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL,
                        start_date DATE NOT NULL,
                        end_date DATE NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ],

        // --- 6. NOVA TABELA: COURSE_TEACHERS (Com Chaves Estrangeiras) ---
        [
            'type' => 'create_table',
            'table' => 'course_teachers',
            'sql' => "CREATE TABLE IF NOT EXISTS `course_teachers` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `courseId` int(11) NOT NULL,
                        `teacherId` int(11) NOT NULL,
                        `commissionRate` decimal(5,2) DEFAULT 0.00,
                        `createdAt` timestamp NULL DEFAULT current_timestamp(),
                        PRIMARY KEY (`id`),
                        KEY `courseId` (`courseId`),
                        KEY `teacherId` (`teacherId`),
                        CONSTRAINT `course_teachers_ibfk_1` FOREIGN KEY (`courseId`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
                        CONSTRAINT `course_teachers_ibfk_2` FOREIGN KEY (`teacherId`) REFERENCES `users` (`id`) ON DELETE CASCADE
                      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        ]
    ];
}

// --- EXECUTOR (ENGINE) ---
function runMigrations($conn, &$resp) {
    addLog($resp, "Iniciando atualizações...", 'info');
    
    $migrations = getMigrations();

    foreach ($migrations as $mig) {
        $table = $mig['table'];
        $sql   = $mig['sql'];
        $type  = $mig['type'];

        try {
            // TIPO 1: ADICIONAR COLUNA
            if ($type === 'column') {
                $col = $mig['column'];
                
                // Verifica se tabela existe antes
                $stmtTable = $conn->query("SHOW TABLES LIKE '$table'");
                if ($stmtTable->rowCount() == 0) continue; 

                // Verifica se a coluna JÁ existe
                $stmtCol = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
                
                if ($stmtCol->rowCount() == 0) {
                    $conn->exec($sql);
                    addLog($resp, "Coluna criada: $col em $table", 'success');
                }
            }
            
            // TIPO 2: CRIAR TABELA NOVA
            elseif ($type === 'create_table') {
                $stmtTable = $conn->query("SHOW TABLES LIKE '$table'");
                if ($stmtTable->rowCount() == 0) {
                    $conn->exec($sql);
                    addLog($resp, "Tabela criada: $table", 'success');
                }
            }

        } catch (Exception $e) {
            // Se der erro, loga mas continua (pode ser chave duplicada ou erro menor)
            addLog($resp, "Erro em $table: " . $e->getMessage(), 'error');
        }
    }
}

// --- RODAPÉ DE VERSÕES (AUXILIAR) ---
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

// --- EXECUÇÃO ---

try {
    $response['version_local'] = getLocalVersion();
    $remote = getRemoteVersion();
    $response['version_remote'] = $remote ?: '---';
} catch (Exception $e) {}

$action = $_GET['action'] ?? '';

try {
    if ($conn) {
        runMigrations($conn, $response);
    } else {
        addLog($response, "Sem conexão DB.", 'error');
    }
    $response['success'] = true;

} catch (Exception $e) {
    addLog($response, "Fatal: " . $e->getMessage(), 'error');
}

ob_end_clean();
echo json_encode($response);
exit;
?>
