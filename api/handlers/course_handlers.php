<?php
// api/handlers/course_handlers.php

function handle_create_course($conn, $data) {
    $courseData = $data['courseData'];
    
    if (empty($courseData['courseName']) || empty($courseData['teacherId'])) {
        send_response(false, 'Nome do curso e professor são obrigatórios.', 400);
        return;
    }

    $name = trim($courseData['courseName']);
    $description = trim($courseData['courseDescription']);
    $teacherId = filter_var($courseData['teacherId'], FILTER_VALIDATE_INT);
    
    $totalSlots = !empty($courseData['totalSlots']) ? filter_var($courseData['totalSlots'], FILTER_VALIDATE_INT) : null;
    $monthlyFee = isset($courseData['monthlyFee']) ? filter_var($courseData['monthlyFee'], FILTER_VALIDATE_FLOAT) : 0.00;
    $paymentType = isset($courseData['paymentType']) ? $courseData['paymentType'] : 'mensal'; 
    $installments = ($paymentType === 'parcelado' && !empty($courseData['installments'])) ? filter_var($courseData['installments'], FILTER_VALIDATE_INT) : null;
    
    $dayOfWeek = !empty($courseData['dayOfWeek']) ? trim($courseData['dayOfWeek']) : null;
    $startTime = !empty($courseData['startTime']) ? trim($courseData['startTime']) : null;
    $endTime = !empty($courseData['endTime']) ? trim($courseData['endTime']) : null;
    
    $carga_horaria = !empty($courseData['carga_horaria']) ? trim($courseData['carga_horaria']) : null;
    $scheduleJson = !empty($courseData['schedule_json']) ? $courseData['schedule_json'] : null;

    try {
        $sql = "INSERT INTO courses (
                    name, description, teacherId, totalSlots, monthlyFee, 
                    paymentType, installments, dayOfWeek, startTime, endTime, 
                    carga_horaria, schedule_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $name, $description, $teacherId, $totalSlots, $monthlyFee, 
            $paymentType, $installments, $dayOfWeek, $startTime, $endTime, 
            $carga_horaria, $scheduleJson
        ]);

        send_response(true, ['message' => 'Curso criado com sucesso!', 'id' => $conn->lastInsertId()]);
    } catch (PDOException $e) {
        error_log("Erro ao criar curso: " . $e->getMessage());
        send_response(false, 'Erro no banco de dados ao criar curso.', 500);
    }
}

function handle_update_course($conn, $data) {
    $courseData = $data['courseData'];
    $id = filter_var($courseData['id'], FILTER_VALIDATE_INT);
    
    if (!$id) {
        send_response(false, 'ID do curso inválido.', 400);
        return;
    }

    $name = trim($courseData['courseName']);
    $description = trim($courseData['courseDescription']);
    $teacherId = filter_var($courseData['teacherId'], FILTER_VALIDATE_INT);
    $totalSlots = !empty($courseData['totalSlots']) ? filter_var($courseData['totalSlots'], FILTER_VALIDATE_INT) : null;
    $monthlyFee = filter_var($courseData['monthlyFee'], FILTER_VALIDATE_FLOAT);
    $paymentType = $courseData['paymentType'];
    $installments = ($paymentType === 'parcelado' && !empty($courseData['installments'])) ? filter_var($courseData['installments'], FILTER_VALIDATE_INT) : null;
    
    $dayOfWeek = !empty($courseData['dayOfWeek']) ? trim($courseData['dayOfWeek']) : null;
    $startTime = !empty($courseData['startTime']) ? trim($courseData['startTime']) : null;
    $endTime = !empty($courseData['endTime']) ? trim($courseData['endTime']) : null;
    
    $carga_horaria = !empty($courseData['carga_horaria']) ? trim($courseData['carga_horaria']) : null;
    $scheduleJson = !empty($courseData['schedule_json']) ? $courseData['schedule_json'] : null;

    try {
        $sql = "UPDATE courses SET 
                    name = ?, description = ?, teacherId = ?, totalSlots = ?, 
                    monthlyFee = ?, paymentType = ?, installments = ?, 
                    dayOfWeek = ?, startTime = ?, endTime = ?, 
                    carga_horaria = ?, schedule_json = ? 
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $name, $description, $teacherId, $totalSlots, 
            $monthlyFee, $paymentType, $installments, 
            $dayOfWeek, $startTime, $endTime, 
            $carga_horaria, $scheduleJson, 
            $id
        ]);

        send_response(true, ['message' => 'Curso atualizado com sucesso.']);
    } catch (PDOException $e) {
        error_log("Erro update curso: " . $e->getMessage());
        send_response(false, 'Erro ao atualizar curso.', 500);
    }
}

function handle_end_course($conn, $data) {
    $id = $data['id'];
    try {
        $stmt = $conn->prepare("UPDATE courses SET status = 'Encerrado', closed_date = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        send_response(true, ['message' => 'Curso finalizado.']);
    } catch (PDOException $e) {
        error_log("Erro ao finalizar: " . $e->getMessage());
        send_response(false, 'Erro ao finalizar curso.', 500);
    }
}

function handle_reopen_course($conn, $data) {
    $id = $data['id'];
    try {
        $stmt = $conn->prepare("UPDATE courses SET status = 'Aberto', closed_date = NULL WHERE id = ?");
        $stmt->execute([$id]);
        send_response(true, ['message' => 'Curso reaberto.']);
    } catch (PDOException $e) {
        send_response(false, 'Erro ao reabrir curso.', 500);
    }
}

function handle_get_course_details($conn, $data) {
    // Aceita tanto 'id' quanto 'courseId' para flexibilidade
    $id = 0;
    if (isset($data['id'])) $id = filter_var($data['id'], FILTER_VALIDATE_INT);
    elseif (isset($data['courseId'])) $id = filter_var($data['courseId'], FILTER_VALIDATE_INT);

    if (!$id) {
        send_response(false, 'ID inválido', 400);
        return;
    }

    try {
        $stmt = $conn->prepare("
            SELECT c.*, CONCAT(u.firstName, ' ', COALESCE(u.lastName, '')) as teacherName 
            FROM courses c 
            LEFT JOIN users u ON c.teacherId = u.id 
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course) {
            send_response(false, 'Curso não encontrado', 404);
            return;
        }

        $stmtEnroll = $conn->prepare("
            SELECT 
                u.id, 
                e.status, 
                CONCAT(u.firstName, ' ', COALESCE(u.lastName, '')) as studentName, 
                u.email
            FROM enrollments e
            JOIN users u ON e.studentId = u.id
            WHERE e.courseId = ?
        ");
        $stmtEnroll->execute([$id]);
        $students = $stmtEnroll->fetchAll(PDO::FETCH_ASSOC);

        send_response(true, ['course' => $course, 'students' => $students]);
    } catch (PDOException $e) {
        error_log("Erro GetCourseDetails: " . $e->getMessage()); 
        send_response(false, 'Erro ao buscar detalhes', 500);
    }
}

// ============================================================
// <<< FUNÇÃO NOVA PARA CARREGAR TELA DE FREQUÊNCIA >>>
// ============================================================
function handle_get_attendance_data($conn, $data) {
    $courseId = isset($data['courseId']) ? filter_var($data['courseId'], FILTER_VALIDATE_INT) : 0;
    $date = isset($data['date']) ? $data['date'] : date('Y-m-d');
    
    if (!$courseId) {
        send_response(false, ['message' => 'ID do curso obrigatório.'], 400);
        return;
    }

    try {
        // 1. Dados do Curso
        $stmtCourse = $conn->prepare("SELECT id, name FROM courses WHERE id = ?");
        $stmtCourse->execute([$courseId]);
        $course = $stmtCourse->fetch(PDO::FETCH_ASSOC);
        
        if (!$course) {
            send_response(false, ['message' => 'Curso não encontrado.'], 404);
            return;
        }

        // 2. Alunos Matriculados (Aprovados)
        // Trazemos também o status de presença para a data específica usando LEFT JOIN
        $sql = "
            SELECT 
                u.id, 
                CONCAT(u.firstName, ' ', COALESCE(u.lastName, '')) as name,
                a.status as attendanceStatus
            FROM users u
            JOIN enrollments e ON u.id = e.studentId
            LEFT JOIN attendance a ON a.studentId = u.id AND a.courseId = e.courseId AND a.date = :date
            WHERE e.courseId = :courseId AND e.status = 'Aprovada'
            ORDER BY u.firstName ASC
        ";
        
        $stmtStudents = $conn->prepare($sql);
        $stmtStudents->execute([':courseId' => $courseId, ':date' => $date]);
        $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

        // 3. Datas com Chamada no Mês (para pintar o calendário)
        // Se um mês foi passado, usamos ele. Senão, mês da data selecionada.
        $month = isset($data['month']) ? $data['month'] : substr($date, 0, 7); // YYYY-MM
        
        $stmtDates = $conn->prepare("
            SELECT DISTINCT date 
            FROM attendance 
            WHERE courseId = ? AND date LIKE ?
        ");
        $stmtDates->execute([$courseId, "$month%"]);
        $datesWithAttendance = $stmtDates->fetchAll(PDO::FETCH_COLUMN);

        send_response(true, [
            'course' => $course,
            'students' => $students,
            'datesWithAttendance' => $datesWithAttendance
        ]);

    } catch (PDOException $e) {
        error_log("Erro getAttendanceData: " . $e->getMessage());
        send_response(false, ['message' => 'Erro ao carregar dados de frequência.'], 500);
    }
}

// ============================================================
// <<< FUNÇÃO PARA SALVAR FREQUÊNCIA >>>
// ============================================================
function handle_save_attendance($conn, $data) {
    $courseId = isset($data['courseId']) ? filter_var($data['courseId'], FILTER_VALIDATE_INT) : 0;
    $date = $data['date'];
    // Recebe IDs dos ausentes (checkboxes marcados)
    $absentStudentIds = isset($data['absentStudentIds']) ? $data['absentStudentIds'] : [];

    if (empty($date) || !$courseId) {
        send_response(false, 'Dados inválidos para salvar frequência.', 400);
        return;
    }

    $conn->beginTransaction();
    try {
        // 1. Limpa registros anteriores desse dia para evitar duplicação/conflito
        $stmtDelete = $conn->prepare("DELETE FROM attendance WHERE courseId = ? AND date = ?");
        $stmtDelete->execute([$courseId, $date]);

        // 2. Busca alunos ativos para garantir que todos tenham registro (Presente ou Falta)
        $stmtStudents = $conn->prepare("SELECT studentId FROM enrollments WHERE courseId = ? AND status = 'Aprovada'");
        $stmtStudents->execute([$courseId]);
        $allStudentIds = $stmtStudents->fetchAll(PDO::FETCH_COLUMN);

        // 3. Insere os novos registros
        $stmtInsert = $conn->prepare("INSERT INTO attendance (courseId, studentId, date, status) VALUES (?, ?, ?, ?)");
        
        foreach ($allStudentIds as $studentId) {
            // Se o ID estiver na lista de ausentes, marca Falta. Senão, Presente.
            // Nota: O frontend deve enviar APENAS os IDs de quem faltou (checkbox marcado)
            $status = in_array($studentId, $absentStudentIds) ? 'Falta' : 'Presente';
            $stmtInsert->execute([$courseId, $studentId, $date, $status]);
        }

        $conn->commit();
        send_response(true, ['message' => 'Frequência salva com sucesso.']);
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Erro attendance: " . $e->getMessage());
        send_response(false, 'Erro ao salvar frequência.', 500);
    }
}

// --- Função auxiliar para buscar professores (usada no select) ---
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
?>