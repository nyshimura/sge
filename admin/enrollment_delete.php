<?php
// admin/enrollment_delete.php
require_once '../config/database.php';
require_once '../includes/functions.php';

checkLogin();
checkRole(['admin', 'superadmin']);

$sid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
$cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;

if ($sid && $cid) {
    try {
        $stmt = $pdo->prepare("DELETE FROM enrollments WHERE studentId = :sid AND courseId = :cid");
        $stmt->execute([':sid' => $sid, ':cid' => $cid]);
        header("Location: enrollments.php?msg=deleted");
    } catch (PDOException $e) {
        die("Erro ao excluir: " . $e->getMessage());
    }
} else {
    header("Location: enrollments.php");
}
?>