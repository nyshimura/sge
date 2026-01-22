<?php
// admin/course_delete.php
require_once '../config/database.php';
require_once '../includes/functions.php';

checkLogin();
checkRole(['admin', 'superadmin']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id) {
    try {
        // Deleta o curso. Devido às chaves estrangeiras (FOREIGN KEYS) configuradas no banco com 'ON DELETE CASCADE'
        // (como visto no seu arquivo SQL), isso deve apagar automaticamente as matrículas (enrollments) associadas.
        $stmt = $pdo->prepare("DELETE FROM courses WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        header("Location: courses.php?msg=deleted");
    } catch (PDOException $e) {
        die("Erro ao excluir curso: " . $e->getMessage() . "<br><a href='courses.php'>Voltar</a>");
    }
} else {
    header("Location: courses.php");
}
?>