<?php
// api/handlers/user_handlers.php

// Helper para validar data no formato YYYY-MM-DD
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Retorna dados para o Dashboard (Counts e Listas Recentes)
 */
function handle_get_dashboard_data($conn, $data) {
    $userId = isset($data['userId']) ? $data['userId'] : null;
    $role = isset($data['role']) ? $data['role'] : null;
    
    $response = [
        'courses' => [], 
        'enrollments' => [], 
        'attendance' => [], 
        'payments' => [], 
        'users' => [], 
        'teachers' => []
    ];

    try {
        // Dados gerais
        $response['courses'] = $conn->query("SELECT c.*, u.firstName as teacherFirstName, u.lastName as teacherLastName FROM courses c LEFT JOIN users u ON c.teacherId = u.id ORDER BY c.name ASC")->fetchAll(PDO::FETCH_ASSOC);
        
        if ($role === 'admin' || $role === 'superadmin') {
            $response['enrollments'] = $conn->query("SELECT e.*, c.name as courseName, u.firstName, u.lastName FROM enrollments e JOIN courses c ON e.courseId = c.id JOIN users u ON e.studentId = u.id")->fetchAll(PDO::FETCH_ASSOC);
            $response['users'] = $conn->query("SELECT id, firstName, lastName, email, role, birthDate FROM users ORDER BY firstName ASC")->fetchAll(PDO::FETCH_ASSOC);
            $response['teachers'] = $conn->query("SELECT id, firstName, lastName FROM users WHERE role = 'teacher'")->fetchAll(PDO::FETCH_ASSOC);
            // Pagamentos recentes (últimos 10)
            $response['payments'] = $conn->query("SELECT p.*, u.firstName, u.lastName, c.name as courseName FROM payments p JOIN users u ON p.studentId = u.id JOIN courses c ON p.courseId = c.id ORDER BY p.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        } else if ($role === 'teacher') {
            $stmt = $conn->prepare("SELECT * FROM courses WHERE teacherId = ?");
            $stmt->execute([$userId]);
            $response['myCourses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else if ($role === 'student') {
            $stmt = $conn->prepare("SELECT e.*, c.name as courseName, c.dayOfWeek, c.startTime, c.endTime FROM enrollments e JOIN courses c ON e.courseId = c.id WHERE e.studentId = ?");
            $stmt->execute([$userId]);
            $response['myEnrollments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        send_response(true, $response);

    } catch (PDOException $e) {
        error_log("Erro em handle_get_dashboard_data: " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao buscar dados do dashboard.'], 500);
    }
}

/**
 * Busca usuários com filtros
 */
function handle_get_filtered_users($conn, $data) {
    $roleFilter = $data['role'] ?? '';
    $search = $data['search'] ?? '';
    $courseId = isset($data['courseId']) ? filter_var($data['courseId'], FILTER_VALIDATE_INT) : 0;

    // Inicia query com DISTINCT para evitar duplicatas se aluno tiver +1 matrícula
    $sql = "SELECT DISTINCT u.id, u.firstName, u.lastName, u.email, u.role, u.birthDate, u.created_at 
            FROM users u ";
    
    // Join com matrículas se filtrar por curso
    if ($courseId > 0) {
        $sql .= " JOIN enrollments e ON u.id = e.studentId ";
    }

    $sql .= " WHERE 1=1";
    $params = [];

    if (!empty($roleFilter) && $roleFilter !== 'all') {
        $sql .= " AND u.role = ?";
        $params[] = $roleFilter;
    }

    if ($courseId > 0) {
        $sql .= " AND e.courseId = ?";
        $params[] = $courseId;
    }

    if (!empty($search)) {
        $sql .= " AND (u.firstName LIKE ? OR u.lastName LIKE ? OR u.email LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $sql .= " ORDER BY u.firstName ASC";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        send_response(true, ['users' => $users]);
    } catch (PDOException $e) {
        error_log("Erro em handle_get_filtered_users: " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao buscar usuários: ' . $e->getMessage()], 500);
    }
}

/**
 * Atualiza o papel (role) de um usuário
 */
function handle_update_user_role($conn, $data) {
    $userId = $data['userId'] ?? 0;
    $newRole = $data['newRole'] ?? '';

    if ($userId <= 0) { send_response(false, ['message' => 'ID inválido.'], 400); return; }
    
    $allowedRoles = ['student', 'teacher', 'admin', 'superadmin'];
    if (!in_array($newRole, $allowedRoles)) {
        send_response(false, ['message' => 'Cargo inválido.'], 400);
        return;
    }

    try {
        $sql = "UPDATE users SET role = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $success = $stmt->execute([$newRole, $userId]);

        if ($success) {
            send_response(true, ['message' => 'Cargo atualizado com sucesso.']);
        } else {
            send_response(false, ['message' => 'Erro ao atualizar cargo.'], 500);
        }
    } catch (PDOException $e) {
        error_log("Erro handle_update_user_role: " . $e->getMessage());
        send_response(false, ['message' => 'Erro BD: ' . $e->getMessage()], 500);
    }
}

/**
 * Busca lista de professores
 */
function handle_get_teachers($conn, $data) {
    try {
        $sql = "SELECT id, firstName, lastName, email FROM users WHERE role = 'teacher' ORDER BY firstName ASC";
        $stmt = $conn->query($sql);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        send_response(true, ['teachers' => $teachers]);
    } catch (PDOException $e) {
        error_log("Erro get_teachers: " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao buscar professores.'], 500);
    }
}

/**
 * Busca lista de alunos ativos
 */
function handle_get_active_students($conn, $data) {
    try {
        $sql = "SELECT id, firstName, lastName, email FROM users WHERE role = 'student' ORDER BY firstName ASC";
        $stmt = $conn->query($sql);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        send_response(true, ['students' => $students]);
    } catch (PDOException $e) {
        error_log("Erro get_active_students: " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao buscar alunos.'], 500);
    }
}


// ============================================================================
// <<< FUNÇÕES DE PERFIL (CORRIGIDAS PARA TRAZER MATRÍCULAS) >>>
// ============================================================================

/**
 * Busca dados do perfil (Ação: getProfileData)
 */
function handle_get_profile_data($conn, $data) {
    $userId = 0;
    if (isset($data['userId'])) $userId = filter_var($data['userId'], FILTER_VALIDATE_INT);
    elseif (isset($data['id'])) $userId = filter_var($data['id'], FILTER_VALIDATE_INT);
    
    if ($userId <= 0) {
        if (session_status() == PHP_SESSION_NONE) session_start();
        $userId = $_SESSION['user_id'] ?? 0;
    }

    if ($userId <= 0) { send_response(false, ['message' => 'ID inválido.'], 400); return; }
    
    try {
        // 1. Dados do Usuário (Incluindo coluna 'phone' que adicionamos)
        $stmt = $conn->prepare("SELECT id, firstName, lastName, email, role, profilePicture, address, rg, cpf, phone, birthDate, guardianName, guardianEmail, guardianPhone, guardianRG, guardianCPF FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // 2. BUSCA MATRÍCULAS (Isso estava faltando!)
            // Traz dados vitais: customMonthlyFee, scholarshipPercentage, courseName
            $stmtEnroll = $conn->prepare("
                SELECT e.*, c.name as courseName 
                FROM enrollments e 
                JOIN courses c ON e.courseId = c.id 
                WHERE e.studentId = ?
            ");
            $stmtEnroll->execute([$userId]);
            $enrollments = $stmtEnroll->fetchAll(PDO::FETCH_ASSOC);

            // 3. BUSCA PAGAMENTOS (Para o histórico financeiro)
            $stmtPay = $conn->prepare("
                SELECT p.*, c.name as courseName 
                FROM payments p 
                JOIN courses c ON p.courseId = c.id 
                WHERE p.studentId = ? 
                ORDER BY p.dueDate DESC
            ");
            $stmtPay->execute([$userId]);
            $payments = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

            // Retorna o pacote completo
            send_response(true, [
                'user' => $user,
                'enrollments' => $enrollments,
                'payments' => $payments
            ]);
        } else {
            send_response(false, ['message' => 'Usuário não encontrado.'], 404);
        }
    } catch (PDOException $e) {
        error_log("Erro handle_get_profile_data: " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao buscar perfil.'], 500);
    }
}

// Aliases
function handle_get_user_profile($conn, $data) { handle_get_profile_data($conn, $data); }
function handle_getUserProfile($conn, $data) { handle_get_profile_data($conn, $data); }


/**
 * Atualiza o perfil do usuário (Ação: updateUserProfile)
 */
function handle_update_user_profile($conn, $data) {
    $userId = isset($data['id']) ? filter_var($data['id'], FILTER_VALIDATE_INT) : 0;
    
    if (session_status() == PHP_SESSION_NONE) session_start();
    $currentUserId = $_SESSION['user_id'] ?? 0;
    $currentUserRole = $_SESSION['role'] ?? '';

    if ($userId <= 0) $userId = $currentUserId;

    if ($userId <= 0) { send_response(false, ['message' => 'ID inválido.'], 400); return; }

    // Permissão
    if ($userId != $currentUserId && $currentUserRole !== 'admin' && $currentUserRole !== 'superadmin') {
        send_response(false, ['message' => 'Sem permissão.'], 403);
        return;
    }

    // Campos
    $firstName = isset($data['firstName']) ? trim($data['firstName']) : null;
    $lastName = isset($data['lastName']) ? trim($data['lastName']) : null;
    $email = isset($data['email']) ? trim($data['email']) : null;
    $phone = isset($data['phone']) ? trim($data['phone']) : null; 
    $address = isset($data['address']) ? trim($data['address']) : null;
    $rg = isset($data['rg']) ? trim($data['rg']) : null;
    $cpf = isset($data['cpf']) ? trim($data['cpf']) : null;
    $birthDate = isset($data['birthDate']) ? trim($data['birthDate']) : null;

    // Guardião
    $guardianName = isset($data['guardianName']) ? trim($data['guardianName']) : null;
    $guardianEmail = isset($data['guardianEmail']) ? trim($data['guardianEmail']) : null;
    $guardianPhone = isset($data['guardianPhone']) ? trim($data['guardianPhone']) : null;
    $guardianRG = isset($data['guardianRG']) ? trim($data['guardianRG']) : null;
    $guardianCPF = isset($data['guardianCPF']) ? trim($data['guardianCPF']) : null;
    
    // Foto
    $profilePicture = isset($data['profilePicture']) ? $data['profilePicture'] : null;

    try {
        $fields = [];
        $params = [];

        if ($firstName !== null) { $fields[] = "firstName = :firstName"; $params[':firstName'] = $firstName; }
        if ($lastName !== null) { $fields[] = "lastName = :lastName"; $params[':lastName'] = $lastName; }
        if ($email !== null) { $fields[] = "email = :email"; $params[':email'] = $email; }
        if ($phone !== null) { $fields[] = "phone = :phone"; $params[':phone'] = $phone; }
        if ($address !== null) { $fields[] = "address = :address"; $params[':address'] = $address; }
        if ($rg !== null) { $fields[] = "rg = :rg"; $params[':rg'] = $rg; }
        if ($cpf !== null) { $fields[] = "cpf = :cpf"; $params[':cpf'] = $cpf; }
        if ($birthDate !== null) { $fields[] = "birthDate = :birthDate"; $params[':birthDate'] = $birthDate; }

        if ($guardianName !== null) { $fields[] = "guardianName = :guardianName"; $params[':guardianName'] = $guardianName; }
        if ($guardianEmail !== null) { $fields[] = "guardianEmail = :guardianEmail"; $params[':guardianEmail'] = $guardianEmail; }
        if ($guardianPhone !== null) { $fields[] = "guardianPhone = :guardianPhone"; $params[':guardianPhone'] = $guardianPhone; }
        if ($guardianRG !== null) { $fields[] = "guardianRG = :guardianRG"; $params[':guardianRG'] = $guardianRG; }
        if ($guardianCPF !== null) { $fields[] = "guardianCPF = :guardianCPF"; $params[':guardianCPF'] = $guardianCPF; }
        
        if ($profilePicture !== null) {
            $fields[] = "profilePicture = :profilePicture";
            $params[':profilePicture'] = $profilePicture;
        }

        if (empty($fields)) {
            send_response(true, ['message' => 'Nenhuma alteração enviada.']);
            return;
        }

        $params[':id'] = $userId;
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        send_response(true, ['message' => 'Perfil atualizado com sucesso.']);

    } catch (PDOException $e) {
        error_log("Erro Update User: " . $e->getMessage());
        if ($e->getCode() == 23000) {
            send_response(false, ['message' => 'Este e-mail já está em uso.'], 409);
        }
        send_response(false, ['message' => 'Erro BD ao atualizar.'], 500);
    }
}

// Alias
function handle_updateUserProfile($conn, $data) { handle_update_user_profile($conn, $data); }
?>