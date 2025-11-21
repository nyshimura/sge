<?php
// api/handlers/course_handlers.php

function handle_create_course($conn, $data) {
    $courseData = $data['courseData'];
    
    // Validação de Campos Obrigatórios
    if (empty($courseData['courseName']) || empty($courseData['teacherId'])) {
        send_response(false, 'Nome do curso e professor são obrigatórios.', 400);
        return;
    }

    $name = trim($courseData['courseName']);
    $description = trim($courseData['courseDescription']);
    $teacherId = filter_var($courseData['teacherId'], FILTER_VALIDATE_INT);
    
    // Campos Opcionais
    $totalSlots = !empty($courseData['totalSlots']) ? filter_var($courseData['totalSlots'], FILTER_VALIDATE_INT) : null;
    $monthlyFee = isset($courseData['monthlyFee']) ? filter_var($courseData['monthlyFee'], FILTER_VALIDATE_FLOAT) : 0.00;
    $paymentType = isset($courseData['paymentType']) ? $courseData['paymentType'] : 'mensal'; 
    $installments = ($paymentType === 'parcelado' && !empty($courseData['installments'])) ? filter_var($courseData['installments'], FILTER_VALIDATE_INT) : null;
    
    // Campos de Horário (Legado para compatibilidade)
    $dayOfWeek = !empty($courseData['dayOfWeek']) ? trim($courseData['dayOfWeek']) : null;
    $startTime = !empty($courseData['startTime']) ? trim($courseData['startTime']) : null;
    $endTime = !empty($courseData['endTime']) ? trim($courseData['endTime']) : null;
    
    // Novos Campos (Carga Horária e Múltiplos Dias)
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
        $stmt = $conn->prepare("UPDATE courses SET status = 'Encerrado' WHERE id = ?");
        $stmt->execute([$id]);
        send_response(true, ['message' => 'Curso finalizado.']);
    } catch (PDOException $e) {
        send_response(false, 'Erro ao finalizar curso.', 500);
    }
}

function handle_reopen_course($conn, $data) {
    $id = $data['id'];
    try {
        $stmt = $conn->prepare("UPDATE courses SET status = 'Aberto' WHERE id = ?");
        $stmt->execute([$id]);
        send_response(true, ['message' => 'Curso reaberto.']);
    } catch (PDOException $e) {
        send_response(false, 'Erro ao reabrir curso.', 500);
    }
}

function handle_get_course_details($conn) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        send_response(false, 'ID inválido', 400);
        return;
    }

    try {
        // 1. Busca Curso (Corrigido para usar firstName/lastName)
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

        // 2. Busca Alunos (CORRIGIDO: removido e.id que não existe, adicionado u.id)
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

function handle_save_attendance($conn, $data) {
    $courseId = $data['courseId'];
    $date = $data['date'];
    $absentStudentIds = isset($data['absentStudentIds']) ? $data['absentStudentIds'] : [];

    if (empty($date)) {
        send_response(false, 'Data inválida para salvar frequência.', 400);
        return;
    }

    $conn->beginTransaction();
    try {
        $stmtDelete = $conn->prepare("DELETE FROM attendance WHERE courseId = ? AND date = ?");
        $stmtDelete->execute([$courseId, $date]);

        $stmtStudents = $conn->prepare("SELECT studentId FROM enrollments WHERE courseId = ? AND status = 'Aprovada'");
        $stmtStudents->execute([$courseId]);
        $allStudentIds = $stmtStudents->fetchAll(PDO::FETCH_COLUMN);

        $stmtInsert = $conn->prepare("INSERT INTO attendance (courseId, studentId, date, status) VALUES (?, ?, ?, ?)");
        
        foreach ($allStudentIds as $studentId) {
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
?>