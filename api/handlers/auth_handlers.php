<?php
/**
 * handlers/auth_handlers.php
 * Funções para lidar com autenticação, registro e gerenciamento de senhas.
 */

// Garante que o config.php (que pode definir send_response ou outras funções)
// já foi incluído pelo index.php. Se não, inclua aqui.
// require_once __DIR__ . '/../config.php'; // Ajuste o caminho se necessário

// --- FUNÇÕES DE AUTENTICAÇÃO ---

/**
 * Lida com o login do usuário.
 */
function handle_login($conn, $params) {
    if (!isset($params['email']) || !isset($params['password'])) {
        send_response(false, ['message' => 'E-mail e senha são obrigatórios.'], 400);
    }

    $email = trim($params['email']);
    $password = $params['password'];

    try {
        $sql = "SELECT id, password_hash, role, firstName FROM users WHERE email = :email";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Login bem-sucedido
            if (session_status() == PHP_SESSION_NONE) {
                session_start(); // Inicia a sessão se ainda não estiver iniciada
            }
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_firstName'] = $user['firstName']; // Opcional: guardar nome para exibição

            error_log("[AUTH_HANDLERS] Login bem-sucedido para usuário ID: " . $user['id'] . ". Sessão iniciada: " . session_id());

            send_response(true, [
                'message' => 'Login bem-sucedido!',
                'user' => [ // Enviar dados básicos do usuário para o frontend
                    'id' => $user['id'],
                    'role' => $user['role'],
                    'firstName' => $user['firstName']
                 ]
            ]);
        } else {
            // Credenciais inválidas
            error_log("[AUTH_HANDLERS] Tentativa de login falhou para o e-mail: " . $email);
            send_response(false, ['message' => 'E-mail ou senha inválidos.'], 401); // 401 Unauthorized
        }
    } catch (PDOException $e) {
        error_log("Erro PDO handle_login: " . $e->getMessage());
        send_response(false, ['message' => 'Erro no banco de dados durante o login.'], 500);
    }
}

/**
 * Lida com o registro de um novo usuário (geralmente como 'student').
 */
function handle_register($conn, $params) {
    // Validação básica dos campos necessários
    if (!isset($params['firstName']) || !isset($params['email']) || !isset($params['password']) || !isset($params['confirmPassword'])) {
        send_response(false, ['message' => 'Nome, e-mail e senhas são obrigatórios.'], 400);
    }

    $firstName = trim($params['firstName']);
    $email = trim($params['email']);
    $password = $params['password'];
    $confirmPassword = $params['confirmPassword'];
    $role = $params['role'] ?? 'student'; // Define 'student' como padrão se não fornecido

    // Validações adicionais
    if (empty($firstName) || empty($email) || empty($password)) {
        send_response(false, ['message' => 'Campos obrigatórios não podem estar vazios.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        send_response(false, ['message' => 'Formato de e-mail inválido.'], 400);
    }
    if (strlen($password) < 6) {
        send_response(false, ['message' => 'A senha deve ter pelo menos 6 caracteres.'], 400);
    }
    if ($password !== $confirmPassword) {
        send_response(false, ['message' => 'As senhas não coincidem.'], 400);
    }

    // Verifica se o e-mail já existe
    try {
        $sqlCheck = "SELECT id FROM users WHERE email = :email";
        $stmtCheck = $conn->prepare($sqlCheck);
        $stmtCheck->bindParam(':email', $email);
        $stmtCheck->execute();
        if ($stmtCheck->fetch()) {
            send_response(false, ['message' => 'Este e-mail já está cadastrado.'], 409); // 409 Conflict
        }

        // Hash da senha
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Insere o novo usuário
        $sqlInsert = "INSERT INTO users (firstName, email, password_hash, role, created_at) VALUES (:firstName, :email, :passwordHash, :role, NOW())";
        $stmtInsert = $conn->prepare($sqlInsert);
        $stmtInsert->bindParam(':firstName', $firstName);
        $stmtInsert->bindParam(':email', $email);
        $stmtInsert->bindParam(':passwordHash', $passwordHash);
        $stmtInsert->bindParam(':role', $role); // Usando a role definida

        if ($stmtInsert->execute()) {
            send_response(true, ['message' => 'Usuário registrado com sucesso!']);
        } else {
            error_log("Erro PDO handle_register (insert): " . implode(":", $stmtInsert->errorInfo()));
            send_response(false, ['message' => 'Erro ao registrar usuário.'], 500);
        }

    } catch (PDOException $e) {
        error_log("Erro PDO handle_register: " . $e->getMessage());
        send_response(false, ['message' => 'Erro no banco de dados durante o registro.'], 500);
    }
}

/**
 * Lida com a alteração de senha pelo usuário logado.
 */
function handle_change_password($conn, $params) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    // Verifica se o usuário está logado
    if (!isset($_SESSION['user_id'])) {
        send_response(false, ['message' => 'Usuário não autenticado.'], 401);
    }
    $userId = $_SESSION['user_id'];

    // Validação dos parâmetros recebidos do frontend (via JSON body)
    if (!isset($params['currentPassword']) || !isset($params['newPassword']) || !isset($params['confirmPassword'])) {
        send_response(false, ['message' => 'Todos os campos de senha são obrigatórios.'], 400);
    }

    $currentPassword = $params['currentPassword'];
    $newPassword = $params['newPassword'];
    $confirmPassword = $params['confirmPassword']; // Mesmo que a validação seja no front, pegamos aqui para consistência ou futuras checagens no backend

    // Validações adicionais
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
         send_response(false, ['message' => 'Campos de senha não podem estar vazios.'], 400);
    }
    if (strlen($newPassword) < 6) {
        send_response(false, ['message' => 'A nova senha deve ter pelo menos 6 caracteres.'], 400);
    }
    if ($newPassword !== $confirmPassword) {
        // Esta validação idealmente já foi feita no frontend, mas é bom ter aqui também
        send_response(false, ['message' => 'As novas senhas não coincidem.'], 400);
    }

    try {
        // 1. Buscar o hash da senha atual do usuário no banco
        $sqlSelect = "SELECT password_hash FROM users WHERE id = :userId";
        $stmtSelect = $conn->prepare($sqlSelect);
        $stmtSelect->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmtSelect->execute();
        $user = $stmtSelect->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            send_response(false, ['message' => 'Usuário não encontrado.'], 404);
        }

        // 2. Verificar se a senha atual fornecida corresponde ao hash armazenado
        if (!password_verify($currentPassword, $user['password_hash'])) {
            send_response(false, ['message' => 'Senha atual incorreta.'], 401); // 401 Unauthorized (ou 403 Forbidden)
        }

        // 3. Gerar o hash da nova senha
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // 4. Atualizar o hash da senha no banco de dados
        // <<< CORREÇÃO APLICADA AQUI: REMOVIDO updatedAt >>>
        $sqlUpdate = "UPDATE users SET password_hash = :newPasswordHash WHERE id = :userId";
        $stmtUpdate = $conn->prepare($sqlUpdate);
        $stmtUpdate->bindParam(':newPasswordHash', $newPasswordHash);
        $stmtUpdate->bindParam(':userId', $userId, PDO::PARAM_INT);

        if ($stmtUpdate->execute()) {
            send_response(true, ['message' => 'Senha alterada com sucesso!']);
        } else {
            // Log do erro específico do PDO
            error_log("Erro PDO handle_change_password (update): " . implode(":", $stmtUpdate->errorInfo()));
            send_response(false, ['message' => 'Erro no banco de dados ao alterar a senha.'], 500);
        }

    } catch (PDOException $e) {
        error_log("Erro PDO handle_change_password: " . $e->getMessage());
        send_response(false, ['message' => 'Erro no banco de dados ao processar a alteração de senha.'], 500);
    }
}


/**
 * Lida com a solicitação de redefinição de senha (envia e-mail com token).
 */
function handle_request_password_reset($conn, $params) {
    
    if (!isset($params['email']) || !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
        send_response(false, ['message' => 'E-mail inválido.'], 400);
    }
     $email = $params['email'];

     // O arquivo está em /helpers/email_helper.php
     require_once __DIR__ . '/../helpers/email_helper.php'; 

     try {
         $sql = "SELECT id FROM users WHERE email = :email";
         $stmt = $conn->prepare($sql);
         $stmt->bindParam(':email', $email);
         $stmt->execute();
         $user = $stmt->fetch(PDO::FETCH_ASSOC);

         if ($user) {
             $token = bin2hex(random_bytes(32));
             $expiresAt = new DateTime('+1 hour');
             $expiresAtFormatted = $expiresAt->format('Y-m-d H:i:s');

             $sqlUpdate = "UPDATE users SET reset_token = :token, reset_token_expires_at = :expires WHERE id = :id";
             $stmtUpdate = $conn->prepare($sqlUpdate);
             $stmtUpdate->bindParam(':token', $token);
             $stmtUpdate->bindParam(':expires', $expiresAtFormatted);
             $stmtUpdate->bindParam(':id', $user['id']);

             if ($stmtUpdate->execute()) {
                 
                 // --- CORREÇÃO: Carrega system_handlers.php se necessário ---
                 if (!function_exists('get_system_settings')) {
                    require_once __DIR__ . '/system_handlers.php';
                 }
                 // -----------------------------------------------------------

                 $settings = get_system_settings($conn); // Busca URL do site das configurações
                 
                 $siteUrl = $settings['site_url'] ?? 'http://localhost/seu_projeto/';
                 
                 // --- CORREÇÃO APLICADA AQUI ---
                 // Troca /reset.html por uma rota de hash (SPA)
                 // Assumindo que a rota do frontend seja #/reset-password
                 $resetLink = rtrim($siteUrl, '/') . '/#resetPassword?token=' . $token; 
                 // --- FIM DA CORREÇÃO ---



                 // Tenta enviar o email
                 if (send_password_reset_email($conn, $email, $user['id'], $resetLink)) {
                     send_response(true, ['message' => 'Se o e-mail estiver cadastrado, um link de redefinição foi enviado. Verifique sua caixa de entrada e spam.']);
                 } else {
                    error_log("Falha ao enviar e-mail de redefinição para $email (ID: {$user['id']})");
                    send_response(false, ['message' => 'Não foi possível enviar o e-mail de redefinição no momento.'], 500);
                 }
             } else {
                 error_log("Erro PDO ao salvar token de reset para $email (ID: {$user['id']}): " . implode(":", $stmtUpdate->errorInfo()));
                 send_response(false, ['message' => 'Erro ao processar solicitação.'], 500);
             }
         } else {
             // Não encontrou o usuário, mas envia a mesma mensagem por segurança
             send_response(true, ['message' => 'Se o e-mail estiver cadastrado, um link de redefinição foi enviado. Verifique sua caixa de entrada e spam.']);
         }

     } catch (PDOException $e) {
         error_log("Erro PDO handle_request_password_reset: " . $e->getMessage());
         send_response(false, ['message' => 'Erro no banco de dados.'], 500);
     } catch (Exception $e) {
        error_log("Erro Geral handle_request_password_reset: " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao processar a solicitação.'], 500);
     }
}

/**
 * Lida com a redefinição de senha usando o token recebido por e-mail.
 */
function handle_reset_password($conn, $params) {
    
    if (!isset($params['token']) || !isset($params['newPassword']) || !isset($params['confirmPassword'])) {
        send_response(false, ['message' => 'Token e senhas são obrigatórios.'], 400);
    }

    $token = $params['token'];
    $newPassword = $params['newPassword'];
    $confirmPassword = $params['confirmPassword'];

    if (strlen($newPassword) < 6) {
        send_response(false, ['message' => 'A senha deve ter pelo menos 6 caracteres.'], 400);
    }
    if ($newPassword !== $confirmPassword) {
        send_response(false, ['message' => 'As senhas não coincidem.'], 400);
    }

    try {
        $sql = "SELECT id, reset_token_expires_at FROM users WHERE reset_token = :token";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $now = new DateTime();
            $expiresAt = new DateTime($user['reset_token_expires_at']);

            if ($now > $expiresAt) {
                send_response(false, ['message' => 'Token de redefinição expirado.'], 400);
            } else {
                // Token válido, atualiza a senha
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $sqlUpdate = "UPDATE users SET password_hash = :newPasswordHash, reset_token = NULL, reset_token_expires_at = NULL WHERE id = :id";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->bindParam(':newPasswordHash', $newPasswordHash);
                $stmtUpdate->bindParam(':id', $user['id']);

                if ($stmtUpdate->execute()) {
                    send_response(true, ['message' => 'Senha redefinida com sucesso! Você já pode fazer login com a nova senha.']);
                } else {
                    error_log("Erro PDO handle_reset_password (update): " . implode(":", $stmtUpdate->errorInfo()));
                    send_response(false, ['message' => 'Erro ao atualizar a senha.'], 500);
                }
            }
        } else {
            send_response(false, ['message' => 'Token de redefinição inválido.'], 400);
        }

    } catch (PDOException $e) {
        error_log("Erro PDO handle_reset_password: " . $e->getMessage());
        send_response(false, ['message' => 'Erro no banco de dados.'], 500);
    } catch (Exception $e) { // Captura erros de DateTime
        error_log("Erro Geral handle_reset_password: " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao processar a solicitação de redefinição.'], 500);
    }
}

?>