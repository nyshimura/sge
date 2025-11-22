<?php
/**
 * api/auto_migrate.php
 * Sistema de Migração Automática de Banco de Dados (Idempotente 111)
 */

// Garante acesso à conexão $conn
if (!isset($conn)) {
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } elseif (file_exists('../config.php')) {
        require_once '../config.php';
    }
}

function checkAndAddColumn($conn, $table, $column, $sqlCommand) {
    try {
        // --- CORREÇÃO DE COMPATIBILIDADE MARIADB/MYSQL ---
        // O comando SHOW COLUMNS não aceita '?' em algumas versões.
        // Como a variável $column vem do nosso array hardcoded (seguro), 
        // inserimos diretamente na string.
        $stmt = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        
        if ($stmt->rowCount() == 0) {
            // 2. Se NÃO existir, roda o comando para criar
            $conn->exec($sqlCommand);
            return "✅ [SUCESSO] Coluna '$column' adicionada na tabela '$table'.";
        } else {
            // 3. Se JÁ existir, não faz nada
            return "ℹ️ [INFO] Coluna '$column' já existe em '$table'. Nenhuma ação necessária.";
        }
    } catch (PDOException $e) {
        return "❌ [ERRO] Falha ao migrar '$table.$column': " . $e->getMessage();
    }
}

// ==============================================================================
// LISTA DE MIGRAÇÕES (Adicione suas novas colunas aqui)
// ==============================================================================
$migrations = [
    [
        'table'   => 'courses',
        'column'  => 'schedule_json',
        'command' => "ALTER TABLE courses ADD COLUMN schedule_json TEXT DEFAULT NULL COMMENT 'Armazena horários múltiplos em JSON'"
    ],
    [
        'table'   => 'users',
        'column'  => 'phone',
        'command' => "ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) DEFAULT NULL COMMENT 'Telefone pessoal do aluno/usuario' AFTER `email`"
    ],
    [
        'table'   => 'courses',
        'column'  => 'closed_date',
        'command' => "ALTER TABLE courses ADD COLUMN closed_date DATETIME DEFAULT NULL"
    ],
];

// ==============================================================================
// EXECUTANDO AS MIGRAÇÕES
// ==============================================================================
$logs = [];
if (isset($conn)) {
    foreach ($migrations as $mig) {
        $logs[] = checkAndAddColumn($conn, $mig['table'], $mig['column'], $mig['command']);
    }
} else {
    $logs[] = "Erro Crítico: Não foi possível conectar ao banco de dados para migração.";
}

// --- PROTEÇÃO CRÍTICA ---
// Só mostra mensagem na tela se você acessar o arquivo diretamente pelo navegador.
// Se ele for chamado pelo update_handler (invisível), ele fica mudo para não quebrar o JSON.
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    echo "<pre>" . print_r($logs, true) . "</pre>";
}
?>
