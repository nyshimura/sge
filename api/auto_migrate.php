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
        return "❌ [ERRO] Falha ao migrar '$column' em '$table': " . $e->getMessage();
    }
}

// ==============================================================================
// DEFINIÇÃO DAS MIGRAÇÕES (Adicione novas colunas aqui)
// ==============================================================================
$migrations = [
    // Migrações Antigas
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

    // --- NOVAS MIGRAÇÕES (Sistema de Lembretes Vencimento) ---
    [
        'table'   => 'payments',
        'column'  => 'reminderSent',
        'command' => "ALTER TABLE `payments` ADD COLUMN `reminderSent` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 se o lembrete de vencimento já foi enviado'"
    ],
    [
        'table'   => 'system_settings',
        'column'  => 'email_reminder_subject',
        'command' => "ALTER TABLE `system_settings` ADD COLUMN `email_reminder_subject` varchar(255) DEFAULT 'Lembrete: Sua mensalidade vence em breve'"
    ],
    [
        'table'   => 'system_settings',
        'column'  => 'email_reminder_body',
        'command' => "ALTER TABLE `system_settings` ADD COLUMN `email_reminder_body` text DEFAULT NULL"
    ],
    [
        'table'   => 'system_settings',
        'column'  => 'reminderDaysBefore',
        'command' => "ALTER TABLE `system_settings` ADD COLUMN `reminderDaysBefore` int(11) NOT NULL DEFAULT 3 COMMENT 'Dias antes do vencimento para enviar o lembrete'"
    ]
];

// ==============================================================================
// EXECUTANDO AS MIGRAÇÕES
// ==============================================================================
$logs = [];
if (isset($conn)) {
    foreach ($migrations as $mig) {
        $logs[] = checkAndAddColumn($conn, $mig['table'], $mig['column'], $mig['command']);
    }

    // --- EXECUÇÃO DE SQL DE DADOS (OPCIONAL) ---
    // Atualiza o template padrão se ele estiver vazio/nulo logo após a criação
    try {
        $checkBody = $conn->query("SELECT email_reminder_body FROM system_settings WHERE id = 1");
        if ($checkBody && $checkBody->fetchColumn() === null) {
            $defaultBody = 'Olá {{aluno_nome}},\n\nEste é um lembrete amigável de que a mensalidade do curso {{curso_nome}} vence no dia {{vencimento_data}}.\nValor: R$ {{valor}}\n\nCaso já tenha efetuado o pagamento, por favor, desconsidere este e-mail.\n\nAtenciosamente,\nEquipe {{escola_nome}}';
            $updateStmt = $conn->prepare("UPDATE `system_settings` SET `email_reminder_body` = :body WHERE `id` = 1");
            $updateStmt->execute([':body' => $defaultBody]);
            $logs[] = "✅ [DADOS] Template de e-mail padrão inserido.";
        }
    } catch (Exception $e) {
        // Ignora erro de update de dados, pois não é estrutural
    }

} else {
    $logs[] = "Erro Crítico: Não foi possível conectar ao banco de dados para migração.";
}

// --- PROTEÇÃO CRÍTICA ---
// Só mostra mensagem na tela se você acessar o arquivo diretamente pelo navegador.
// Se ele for chamado pelo update_handler (invisível), ele fica mudo para não quebrar o JSON.
if (basename($_SERVER['PHP_SELF']) == 'auto_migrate.php') {
    echo "<pre>" . implode("\n", $logs) . "</pre>";
}
?>
