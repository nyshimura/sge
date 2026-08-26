<?php
// switch_role.php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['real_role'])) {
    header("Location: login.php");
    exit;
}

$toRole = isset($_GET['role']) ? $_GET['role'] : '';

// Apenas permite ir para 'student' ou voltar para o 'real_role'
if ($toRole === 'student') {
    $_SESSION['user_role'] = 'student';
    header("Location: student/index.php");
    exit;
} elseif ($toRole === $_SESSION['real_role']) {
    $_SESSION['user_role'] = $_SESSION['real_role'];
    if ($_SESSION['real_role'] === 'teacher') {
        header("Location: teacher/index.php");
    } elseif ($_SESSION['real_role'] === 'admin' || $_SESSION['real_role'] === 'superadmin') {
        header("Location: admin/index.php");
    } else {
        header("Location: student/index.php");
    }
    exit;
} else {
    // Ação inválida, manda pra home conforme o role atual
    if ($_SESSION['user_role'] === 'student') {
        header("Location: student/index.php");
    } elseif ($_SESSION['user_role'] === 'teacher') {
        header("Location: teacher/index.php");
    } else {
        header("Location: admin/index.php");
    }
    exit;
}
