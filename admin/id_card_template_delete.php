<?php
// admin/id_card_template_delete.php
require '../config/database.php';
require '../includes/functions.php';

// Garante que está logado e é admin/superadmin
checkLogin();
checkRole(['admin', 'superadmin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $stmt = $pdo->prepare("DELETE FROM id_card_templates WHERE id = ?");
    $stmt->execute([$id]);
}
header('Location: id_card_templates.php');
exit;
