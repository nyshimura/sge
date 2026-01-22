<?php
// admin/user_delete.php
require_once '../config/database.php';
require_once '../includes/functions.php';

checkLogin();
// CORREÇÃO: Adicionado superadmin
checkRole(['admin', 'superadmin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id) {
    // Evita que o usuário se exclua a si mesmo
    if ($id == $_SESSION['user_id']) {
        header("Location: users.php?msg=voce_nao_pode_se_excluir");
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        header("Location: users.php?msg=deletado");
    } catch (PDOException $e) {
        die("Erro ao excluir: " . $e->getMessage());
    }
} else {
    header("Location: users.php");
}
?>