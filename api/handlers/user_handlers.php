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
    if (session_status() == PHP_SESSION_NONE) session_start();
    
    $userId = $data['userId'] ?? $_SESSION['user_id'] ?? 0;
    $role = $data['role'] ?? $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
    
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
            $response['payments'] = $conn->query("SELECT p.*, u.firstName, u.lastName, c.name as courseName FROM payments p JOIN users u ON p.studentId = u.id JOIN courses c ON p.courseId = c.id ORDER BY p.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        } else if ($role === 'teacher') {
            $stmt = $conn->prepare("SELECT * FROM courses WHERE teacherId = ?");
            $stmt->execute([$userId]);
            $response['myCourses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else if ($role === 'student') {
            // Busca matrículas do aluno logado
            $stmt = $conn->prepare("SELECT e.*, c.name as courseName, c.dayOfWeek, c.startTime, c.endTime FROM enrollments e JOIN courses c ON e.courseId = c.id WHERE e.studentId = ?");
            $stmt->execute([$userId]);
            $response['myEnrollments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Busca frequência (Total)
            $stmtAtt = $conn->prepare("SELECT * FROM attendance WHERE studentId = ?");
            $stmtAtt->execute([$userId]);
            $response['attendance'] = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

            // --- CORREÇÃO: Busca Pagamentos do Aluno (Restaurado) ---
            $stmtPay = $conn->prepare("
                SELECT p.*, c.name as courseName 
                FROM payments p 
                JOIN courses c ON p.courseId = c.id 
                WHERE p.studentId = ? 
                ORDER BY p.dueDate ASC
            ");
            $stmtPay->execute([$userId]);
            $response['payments'] = $stmtPay->fetchAll(PDO::FETCH_ASSOC);
        }

        send_response(true, $response);

    } catch (PDOException $e) {
        error_log("Erro em handle_get_dashboard_data: " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao buscar dados do dashboard.'], 500);
    }
}

// ... (O restante do arquivo: handle_get_filtered_users, handle_update_user_role, etc. permanecem iguais) ...
function handle_get_filtered_users($conn, $data) {
    // (Código original mantido...)
    $roleFilter = $data['role'] ?? '';
    $search = $data['search'] ?? '';
    $courseId = isset($data['courseId']) ? filter_var($data['courseId'], FILTER_VALIDATE_INT) : 0;
    $sql = "SELECT DISTINCT u.id, u.firstName, u.lastName, u.email, u.role, u.birthDate, u.created_at FROM users u WHERE 1=1";
    $params = [];
    if (!empty($roleFilter) && $roleFilter !== 'all') { $sql .= " AND u.role = ?"; $params[] = $roleFilter; }
    if ($courseId > 0) { $sql .= " AND (u.id IN (SELECT studentId FROM enrollments WHERE courseId = ?) OR u.id IN (SELECT teacherId FROM courses WHERE id = ?))"; $params[] = $courseId; $params[] = $courseId; }
    if (!empty($search)) { $sql .= " AND (u.firstName LIKE ? OR u.lastName LIKE ? OR u.email LIKE ?)"; $searchTerm = "%$search%"; $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm; }
    $sql .= " ORDER BY u.firstName ASC";
    try {
        $stmt = $conn->prepare($sql); $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC); send_response(true, ['users' => $users]);
    } catch (PDOException $e) { error_log("Erro em handle_get_filtered_users: " . $e->getMessage()); send_response(false, ['message' => 'Erro ao buscar usuários: ' . $e->getMessage()], 500); }
}

function handle_update_user_role($conn, $data) {
    // (Código original mantido...)
    $userId = isset($data['userId']) ? filter_var($data['userId'], FILTER_VALIDATE_INT) : 0;
    $newRole = $data['newRole'] ?? '';
    if ($userId <= 0) { send_response(false, ['message' => 'ID de usuário inválido.'], 400); return; }
    $allowedRoles = ['unassigned', 'student', 'teacher', 'admin', 'superadmin'];
    if (!in_array($newRole, $allowedRoles)) { send_response(false, ['message' => "Cargo inválido: $newRole"], 400); return; }
    try {
        $sql = "UPDATE users SET role = ? WHERE id = ?"; $stmt = $conn->prepare($sql);
        $success = $stmt->execute([$newRole, $userId]);
        if ($success) { send_response(true, ['message' => 'Cargo atualizado com sucesso.']); } 
        else { send_response(false, ['message' => 'Nenhuma alteração feita ou erro ao atualizar.'], 500); }
    } catch (PDOException $e) { error_log("Erro handle_update_user_role: " . $e->getMessage()); send_response(false, ['message' => 'Erro BD: ' . $e->getMessage()], 500); }
}

function handle_get_teachers($conn, $data) {
    try { $sql = "SELECT id, firstName, lastName, email FROM users WHERE role = 'teacher' ORDER BY firstName ASC"; $stmt = $conn->query($sql); $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC); send_response(true, ['teachers' => $teachers]); } catch (PDOException $e) { error_log("Erro get_teachers: " . $e->getMessage()); send_response(false, ['message' => 'Erro ao buscar professores.'], 500); }
}

function handle_get_active_students($conn, $data) {
    try { $sql = "SELECT id, firstName, lastName, email FROM users WHERE role = 'student' ORDER BY firstName ASC"; $stmt = $conn->query($sql); $students = $stmt->fetchAll(PDO::FETCH_ASSOC); send_response(true, ['students' => $students]); } catch (PDOException $e) { error_log("Erro get_active_students: " . $e->getMessage()); send_response(false, ['message' => 'Erro ao buscar alunos.'], 500); }
}

function handle_get_profile_data($conn, $data) {
    // (Código original mantido...)
    $userId = 0;
    if (isset($data['userId'])) $userId = filter_var($data['userId'], FILTER_VALIDATE_INT); elseif (isset($data['id'])) $userId = filter_var($data['id'], FILTER_VALIDATE_INT);
    if ($userId <= 0) { if (session_status() == PHP_SESSION_NONE) session_start(); $userId = $_SESSION['user_id'] ?? 0; }
    if ($userId <= 0) { send_response(false, ['message' => 'ID inválido.'], 400); return; }
    try {
        $stmt = $conn->prepare("SELECT id, firstName, lastName, email, role, profilePicture, address, rg, cpf, phone, birthDate, guardianName, guardianEmail, guardianPhone, guardianRG, guardianCPF FROM users WHERE id = ?");
        $stmt->execute([$userId]); $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $stmtEnroll = $conn->prepare("SELECT e.*, c.name as courseName FROM enrollments e JOIN courses c ON e.courseId = c.id WHERE e.studentId = ?");
            $stmtEnroll->execute([$userId]); $enrollments = $stmtEnroll->fetchAll(PDO::FETCH_ASSOC);
            $stmtPay = $conn->prepare("SELECT p.*, c.name as courseName FROM payments p JOIN courses c ON p.courseId = c.id WHERE p.studentId = ? ORDER BY p.dueDate DESC");
            $stmtPay->execute([$userId]); $payments = $stmtPay->fetchAll(PDO::FETCH_ASSOC);
            send_response(true, ['user' => $user, 'enrollments' => $enrollments, 'payments' => $payments]);
        } else { send_response(false, ['message' => 'Usuário não encontrado.'], 404); }
    } catch (PDOException $e) { error_log("Erro handle_get_profile_data: " . $e->getMessage()); send_response(false, ['message' => 'Erro ao buscar perfil.'], 500); }
}

function handle_update_user_profile($conn, $data) {
    // (Código original mantido...)
    $userId = isset($data['id']) ? filter_var($data['id'], FILTER_VALIDATE_INT) : 0;
    if (session_status() == PHP_SESSION_NONE) session_start();
    $currentUserId = $_SESSION['user_id'] ?? 0; $currentUserRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? '';
    if ($userId <= 0) $userId = $currentUserId;
    if ($userId <= 0) { send_response(false, ['message' => 'ID inválido.'], 400); return; }
    if ($userId != $currentUserId && $currentUserRole !== 'admin' && $currentUserRole !== 'superadmin') { send_response(false, ['message' => 'Sem permissão.'], 403); return; }
    $fieldsToUpdate = ['firstName', 'lastName', 'email', 'phone', 'address', 'rg', 'cpf', 'birthDate', 'guardianName', 'guardianEmail', 'guardianPhone', 'guardianRG', 'guardianCPF', 'profilePicture'];
    $fields = []; $params = [];
    foreach($fieldsToUpdate as $f) { if (array_key_exists($f, $data)) { $fields[] = "$f = :$f"; $params[":$f"] = trim($data[$f]); } }
    if (empty($fields)) { send_response(true, ['message' => 'Nenhuma alteração enviada.']); return; }
    try { $params[':id'] = $userId; $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id"; $stmt = $conn->prepare($sql); $stmt->execute($params); send_response(true, ['message' => 'Perfil atualizado com sucesso.']); } 
    catch (PDOException $e) { if ($e->getCode() == 23000) send_response(false, ['message' => 'E-mail já em uso.'], 409); send_response(false, ['message' => 'Erro BD ao atualizar.'], 500); }
}

function handle_get_user_profile($conn, $data) { handle_get_profile_data($conn, $data); }
function handle_getUserProfile($conn, $data) { handle_get_profile_data($conn, $data); }
function handle_updateUserProfile($conn, $data) { handle_update_user_profile($conn, $data); }
?>