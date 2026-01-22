<?php
// Arquivo: assets/api/verify_certificate.php

header('Content-Type: application/json; charset=utf-8');

// AJUSTE DE CAMINHO: Sobe 2 níveis para achar a pasta config
// (assets/api/ -> sobe para assets/ -> sobe para raiz -> entra em config)
require_once '../../config/database.php'; 

try {
    if (!isset($_GET['hash'])) {
        throw new Exception('Hash não fornecido.');
    }

    $hash = trim($_GET['hash']);

    if (!preg_match('/^[a-fA-F0-9]{64}$/', $hash)) {
        throw new Exception('Formato de hash inválido.');
    }

    // Busca o certificado
    $sql = "SELECT 
                c.verification_hash,
                c.generated_at,
                c.completion_date,
                u.firstName as studentFirstName,
                u.lastName as studentLastName,
                u.cpf as studentCpf,
                co.name as courseName
            FROM certificates c
            JOIN users u ON c.student_id = u.id
            JOIN courses co ON c.course_id = co.id
            WHERE c.verification_hash = :hash
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':hash' => $hash]);
    $certificate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$certificate) {
        echo json_encode(['success' => false, 'message' => 'Certificado não encontrado.']);
        exit;
    }

    // Mascara o CPF
    $cpfLimpo = preg_replace('/[^0-9]/', '', $certificate['studentCpf']);
    $cpfMascarado = '***.' . substr($cpfLimpo, 3, 3) . '.' . substr($cpfLimpo, 6, 3) . '-**';

    // Formata datas
    $completionDate = new DateTime($certificate['completion_date']);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'certificate' => [
                'studentFirstName' => $certificate['studentFirstName'],
                'studentLastName'  => $certificate['studentLastName'],
                'studentCpf_masked'=> $cpfMascarado,
                'courseName'       => $certificate['courseName'],
                'generated_at'     => $certificate['generated_at'],
                'completion_date_formatted' => $completionDate->format('d/m/Y')
            ]
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>