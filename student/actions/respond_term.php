<?php
// student/actions/respond_term.php

session_start();
require_once '../../config/database.php';

// 1. Segurança: Verifica se está logado
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $studentId = $_SESSION['user_id'];
    $termId    = isset($_POST['term_id']) ? (int)$_POST['term_id'] : 0;
    $response  = isset($_POST['response']) ? $_POST['response'] : ''; // 'accepted' ou 'declined'

    // Validação básica
    if ($termId > 0 && in_array($response, ['accepted', 'declined'])) {
        try {
            // 2. Verifica se o termo existe e se o aluno já respondeu (para evitar duplicidade)
            $check = $pdo->prepare("SELECT id FROM event_term_responses WHERE term_id = ? AND studentId = ?");
            $check->execute([$termId, $studentId]);

            if ($check->rowCount() > 0) {
                // Se já respondeu, atualiza (caso ele tenha mudado de ideia, opcional)
                $stmt = $pdo->prepare("UPDATE event_term_responses SET status = ?, responded_at = NOW() WHERE term_id = ? AND studentId = ?");
                $stmt->execute([$response, $termId, $studentId]);
            } else {
                // Se não respondeu, insere novo
                $stmt = $pdo->prepare("INSERT INTO event_term_responses (term_id, studentId, status, responded_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$termId, $studentId, $response]);
            }

            // 3. Feedback e Redirecionamento
            $msg = ($response === 'accepted') ? 'Obrigado! Sua participação foi confirmada.' : 'Resposta registrada.';
            
            // Redireciona de volta para o Painel (index) ou para onde ele estava
            header("Location: ../index.php?msg=Obrigado");
            exit;

        } catch (PDOException $e) {
            // Erro de banco
            header("Location: ../index.php?error=db_error");
            exit;
        }
    } else {
        // Dados inválidos
        header("Location: ../index.php?error=invalid_data");
        exit;
    }

} else {
    header("Location: ../index.php");
    exit;
}
?>