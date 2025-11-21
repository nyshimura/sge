<?php
/**
 * api/auto_migrate.php
 * Sistema de Migração Automática de Banco de Dados (Idempotente)
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
        // 1. Verifica se a coluna existe
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        
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

// CORREÇÃO AQUI: Só exibe o log se o arquivo for acessado DIRETAMENTE.
// Se for incluído por outro script (como o update_handler), ele fica quieto.
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    echo "<pre>" . print_r($logs, true) . "</pre>";
}
?>
