<?php
// admin/actions/create_event_term.php

session_start();
require_once '../../config/database.php';

// 1. Verificação de Segurança (Apenas Admins)
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'superadmin'])) {
    // Se não for admin, manda para login ou home
    header("Location: ../../index.php");
    exit;
}

// 2. Processa o Formulário
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Coleta e limpa os dados
    $courseId = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
    $title    = isset($_POST['title']) ? trim($_POST['title']) : '';
    $content  = isset($_POST['content']) ? trim($_POST['content']) : '';

    // Validação: Garante que nada está vazio
    if ($courseId > 0 && !empty($title) && !empty($content)) {
        try {
            // Prepara o SQL
            $sql = "INSERT INTO event_terms (courseId, title, content) VALUES (:cid, :title, :content)";
            $stmt = $pdo->prepare($sql);
            
            // Executa
            $stmt->execute([
                ':cid'     => $courseId,
                ':title'   => $title,
                ':content' => $content
            ]);

            // Sucesso: Volta para a tela do curso com mensagem verde
            header("Location: ../courses.php?view=$courseId&msg=term_created");
            exit;

        } catch (PDOException $e) {
            // Erro de Banco: Volta com mensagem de erro
            // Dica: Em produção, use error_log($e->getMessage()) para não mostrar erro técnico na tela
            header("Location: ../courses.php?view=$courseId&msg=error");
            exit;
        }
    } else {
        // Erro: Campos vazios
        header("Location: ../courses.php?view=$courseId&msg=error_empty");
        exit;
    }

} else {
    // Se tentar acessar o arquivo direto pela URL sem enviar POST
    header("Location: ../courses.php");
    exit;
}