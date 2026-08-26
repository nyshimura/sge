<?php
require_once(__DIR__ . '/config/database.php');
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN role_title VARCHAR(100) DEFAULT NULL");
    echo "Coluna role_title adicionada com sucesso.";
} catch (PDOException $e) {
    echo "Erro (pode já existir): " . $e->getMessage();
}
?>
