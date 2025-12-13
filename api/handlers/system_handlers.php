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
        $stmt = $conn->query("SELECT * FROM `school_profile` WHERE `id` = 1 LIMIT 1");
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        send_response(true, ['profile' => $profile]);
    } catch (PDOException $e) {
        error_log("Erro PDO em handle_get_school_profile: " . $e->getMessage());
        send_response(false, 'Erro ao buscar perfil da escola.', 500);
    }
}

function handle_update_school_profile($conn, $data) {
    // Verifica se os dados vieram dentro de 'profile' ou na raiz
    $profileData = $data['profile'] ?? $data;

    $fields = [];
    $params = [':id' => 1];

    $allowedFields = [
        'name', 'cnpj', 'state', 'schoolCity', 'address', 'phone', 
        'pixKeyType', 'pixKey', 'profilePicture', 'signatureImage'
    ];

    foreach ($profileData as $key => $value) {
        if (in_array($key, $allowedFields)) {
            $fields[] = "`$key` = :$key";
            $params[":$key"] = $value;
        }
    }

    if (empty($fields)) {
        send_response(false, 'Nenhum dado válido para atualizar.', 400);
        return;
    }

    $sql = "UPDATE `school_profile` SET " . implode(', ', $fields) . " WHERE `id` = :id";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        send_response(true, ['message' => 'Perfil da escola atualizado com sucesso.']);
    } catch (PDOException $e) {
        error_log("Erro PDO em handle_update_school_profile: " . $e->getMessage());
        send_response(false, 'Erro ao atualizar perfil.', 500);
    }
}

function handle_get_system_settings($conn, $data) {
    $settings = get_system_settings($conn);
    
    if ($settings) {
        // --- ALTERAÇÃO AQUI: Buscar o nome da escola para exibição ---
        try {
            $stmtSchool = $conn->query("SELECT name FROM school_profile WHERE id = 1 LIMIT 1");
            $schoolName = $stmtSchool->fetchColumn();
            // Injeta o nome no array de settings para o frontend consumir
            $settings['name'] = $schoolName ? $schoolName : ''; 
        } catch (Exception $e) {
            // Se falhar, apenas segue sem o nome
            error_log("Erro ao buscar nome da escola em settings: " . $e->getMessage());
        }
        // -------------------------------------------------------------

        send_response(true, ['settings' => $settings]);
    } else {
        send_response(false, 'Erro ao buscar configurações do sistema.', 500);
    }
}

function handle_update_system_settings($conn, $data) {
    // Garante que pega os dados corretamente (da chave 'settings' ou da raiz)
    $settings = $data['settings'] ?? $data;

    $fields = [];
    $params = [':id' => 1];

    // Lista de campos permitidos (Note que 'name' NÃO está aqui, então é seguro)
    $allowedFields = [
        'smtpServer', 'smtpPort', 'smtpUser', 'smtpPass',
        'email_approval_subject', 'email_approval_body',
        'email_reset_subject', 'email_reset_body',
        'email_reminder_subject', 'email_reminder_body', 'reminderDaysBefore',
        'site_url', 'language', 'timeZone', 'currencySymbol',
        'enableTerminationFine', 'terminationFineMonths', 'defaultDueDay',
        'geminiApiKey', 'geminiApiEndpoint',
        'mp_active', 'mp_public_key', 'mp_access_token',
        'dbHost', 'dbUser', 'dbPass', 'dbName', 'dbPort',
        'profilePicture', 'signatureImage' 
    ];

    foreach ($settings as $key => $value) {
        if (in_array($key, $allowedFields)) {
            $fields[] = "`$key` = :$key";
            $params[":$key"] = $value;
        }
    }

    if (empty($fields)) {
        send_response(false, 'Nenhum dado para atualizar.', 400);
        return;
    }

    $sql = "UPDATE `system_settings` SET " . implode(', ', $fields) . " WHERE `id` = :id";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        send_response(true, ['message' => 'Configurações do sistema atualizadas.']);
    } catch (PDOException $e) {
        error_log("Erro PDO em handle_update_system_settings: " . $e->getMessage());
        send_response(false, 'Erro ao atualizar configurações.', 500);
    }
}

function handle_update_document_templates($conn, $data) {
    $settings = $data['settings'] ?? $data;
    $fields = [];
    $params = [':id' => 1];

    $allowedFields = [
        'certificate_template_text',
        'enrollmentContractText',
        'imageTermsText',
        'certificate_background_image' 
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
        send_response(false, ['message' => "Nenhum campo válido para salvar."], 400);
    }
}
?>