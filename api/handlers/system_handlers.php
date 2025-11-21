<?php
// api/handlers/system_handlers.php

// Aumenta limite para lidar com uploads grandes eventuais
ini_set('memory_limit', '256M');
ini_set('post_max_size', '64M');
ini_set('upload_max_filesize', '64M');

/**
 * Função auxiliar global para buscar configurações do sistema.
 * @return array|null Retorna um array com as configurações ou null se falhar.
 */
function get_system_settings($conn) {
    if (!isset($conn) || !$conn instanceof PDO) {
        error_log("Erro: Conexão PDO inválida em get_system_settings (helper).");
        return null;
    }
    try {
        $stmt = $conn->prepare("SELECT * FROM `system_settings` WHERE `id` = 1 LIMIT 1");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        return $settings ? $settings : null; 
    } catch (PDOException $e) {
        error_log("Erro PDO em get_system_settings (helper): " . $e->getMessage());
        return null;
    }
}

/**
 * Handlers para ações relacionadas ao sistema e perfil da escola.
 */

function handle_get_school_profile($conn, $data) {
    if (!isset($conn) || !$conn instanceof PDO) {
        send_response(false, 'Erro interno de conexão DB.', 500);
        return;
    }
    try {
        $stmt = $conn->prepare("SELECT * FROM `school_profile` WHERE `id` = 1 LIMIT 1");
        $stmt->execute();
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        send_response(true, ['profile' => $profile]);
    } catch (PDOException $e) {
        error_log("Erro PDO em handle_get_school_profile: " . $e->getMessage());
        send_response(false, ['message' => "Erro ao buscar perfil da escola."], 500);
    }
}

function handle_update_school_profile($conn, $data) {
    if (empty($data['profile'])) {
        send_response(false, 'Dados do perfil ausentes.', 400);
        return;
    }
    $profileData = $data['profile'];
    $fields = [];
    $params = [];

    foreach ($profileData as $key => $value) {
        if ($key === 'id') continue; 
        $fields[] = "`$key` = :$key";
        $params[":$key"] = $value;
    }
    $params[':id'] = 1;

    if (empty($fields)) {
        send_response(true, ['message' => 'Nenhuma alteração enviada.']);
        return;
    }

    try {
        $sql = "UPDATE `school_profile` SET " . implode(', ', $fields) . " WHERE `id` = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        send_response(true, ['message' => 'Perfil atualizado com sucesso.']);
    } catch (PDOException $e) {
        error_log("Erro PDO update escola: " . $e->getMessage());
        send_response(false, ['message' => "Erro ao atualizar perfil."], 500);
    }
}

function handle_upload_school_logo($conn) {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        send_response(false, 'Nenhum arquivo enviado ou erro no upload.', 400);
        return;
    }

    $fileTmpPath = $_FILES['logo']['tmp_name'];
    $fileType = $_FILES['logo']['type'];

    // Validação simples de imagem
    if (strpos($fileType, 'image/') !== 0) {
        send_response(false, 'O arquivo deve ser uma imagem.', 400);
        return;
    }

    // Converte para Base64
    $data = file_get_contents($fileTmpPath);
    $base64 = 'data:' . $fileType . ';base64,' . base64_encode($data);

    try {
        $stmt = $conn->prepare("UPDATE `school_profile` SET `profilePicture` = :pic WHERE `id` = 1");
        $stmt->execute([':pic' => $base64]);
        send_response(true, ['message' => 'Logo atualizado.', 'path' => $base64]);
    } catch (PDOException $e) {
        error_log("Erro PDO upload logo: " . $e->getMessage());
        send_response(false, ['message' => "Erro ao salvar logo no banco."], 500);
    }
}

function handle_get_system_settings($conn, $data) {
    $settings = get_system_settings($conn);
    if ($settings) {
        send_response(true, ['settings' => $settings]);
    } else {
        send_response(false, ['message' => "Configurações não encontradas."], 404);
    }
}

function handle_update_system_settings($conn, $data) {
    if (empty($data['settings'])) {
        send_response(false, 'Dados ausentes.', 400);
        return;
    }

    $settings = $data['settings'];
    $fields = [];
    $params = [];

    foreach ($settings as $key => $value) {
        // Proteção simples contra SQL Injection nos nomes das colunas (apenas letras, números e _)
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) continue; 
        if ($key === 'id') continue;

        $fields[] = "`$key` = :$key";
        $params[":$key"] = $value;
    }
    $params[':id'] = 1; 

    if (empty($fields)) {
        send_response(true, ['message' => 'Nenhuma alteração enviada.']);
        return;
    }

    try {
        $sql = "UPDATE `system_settings` SET " . implode(', ', $fields) . " WHERE `id` = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        send_response(true, ['message' => 'Configurações atualizadas.']);
    } catch (PDOException $e) {
        error_log("Erro PDO update settings: " . $e->getMessage());
        send_response(false, ['message' => "Erro ao salvar configurações."], 500);
    }
}

function handle_update_document_templates($conn, $data) {
    if (empty($data['settings'])) {
        send_response(false, 'Dados de template ausentes.', 400);
        return;
    }

    $settings = $data['settings'];
    $fields = [];
    $params = [':id' => 1];

    // Mapeamento seguro dos campos permitidos para esta ação
    $allowedFields = [
        'enrollmentContractText',
        'imageTermsText',
        'certificate_template_text',
        'certificate_background_image' // Permitir atualizar a imagem se vier
    ];

    foreach ($settings as $key => $value) {
        if (in_array($key, $allowedFields)) {
            $fields[] = "`$key` = :$key";
            $params[":$key"] = $value;
        }
    }

    if (!empty($fields)) {
        $setFields = implode(', ', $fields);
        $sql = "UPDATE `system_settings` SET {$setFields} WHERE `id` = :id";
        // error_log("SQL Query (document templates): " . $sql); // Debug

        try {
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() > 0) {
                send_response(true, ['message' => 'Modelos salvos com sucesso.', 'success' => true]);
            } else {
                send_response(true, ['message' => 'Nenhuma alteração detectada.', 'success' => true]);
            }
        } catch (PDOException $e) {
            error_log("Erro PDO em handle_update_document_templates: " . $e->getMessage());
            send_response(false, ['message' => "Erro DB ao salvar modelos."], 500);
        } catch (Exception $e) {
            error_log("Erro geral em handle_update_document_templates: " . $e->getMessage());
            send_response(false, ['message' => "Erro interno ao salvar modelos."], 500);
        }
    } else {
        error_log("Nenhum campo de template recebido para atualização.");
        send_response(true, ['message' => 'Nenhuma alteração enviada.', 'success' => true]);
    }
}
?>