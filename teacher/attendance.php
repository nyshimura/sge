<?php
// teacher/attendance.php
$pageTitle = "Realizar Chamada";
include '../includes/teacher_header.php';

$teacherId = $_SESSION['user_id'];
$courseId = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
$dateParam = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// =================================================================================
// 1. LISTA DE SELEÇÃO (Se nenhum curso foi escolhido)
// =================================================================================
if ($courseId === 0) {
    // Busca cursos onde o professor é Titular ou Auxiliar
    $sqlList = "SELECT DISTINCT c.* FROM courses c
                LEFT JOIN course_teachers ct ON c.id = ct.courseId
                WHERE (c.teacherId = :tid OR ct.teacherId = :tid) 
                AND c.status = 'Aberto'";
                
    $stmt = $pdo->prepare($sqlList);
    $stmt->execute([':tid' => $teacherId]);
    $coursesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ordenação PHP por horário de início (Lendo o JSON)
    $myCourses = [];
    foreach ($coursesRaw as $course) {
        $firstTime = '23:59';
        $schedules = json_decode($course['schedule_json'] ?? '[]', true);
        if (is_array($schedules)) {
            foreach ($schedules as $s) {
                if (isset($s['start']) && $s['start'] < $firstTime) $firstTime = $s['start'];
            }
        }
        $course['sort_time'] = $firstTime;
        $myCourses[] = $course;
    }
    
    // Ordena: Aulas mais cedo aparecem primeiro
    usort($myCourses, function($a, $b) {
        return strcmp($a['sort_time'], $b['sort_time']);
    });

    ?>
    <div class="page-container">
        <div class="section-header" style="margin-top:0;">
            <h3 class="section-title"><i class="fas fa-list-ul" style="color:var(--primary-color);"></i> Selecione uma Turma</h3>
        </div>

        <?php if(count($myCourses) == 0): ?>
            <div class="card-box" style="text-align:center; color:#888; padding:40px;">
                <i class="fas fa-chalkboard fa-3x" style="opacity:0.3; margin-bottom:15px;"></i>
                <p>Nenhuma turma encontrada.</p>
            </div>
        <?php else: ?>
            <div class="teacher-course-grid">
                <?php foreach($myCourses as $course): ?>
                    <a href="attendance.php?cid=<?php echo $course['id']; ?>" style="text-decoration:none; color:inherit;">
                        <div class="teacher-course-card">
                            <div class="tcc-header" style="background:var(--primary-color); color:white; border:none;">
                                <h4 style="margin:0; color:white;"><?php echo htmlspecialchars($course['name']); ?></h4>
                                <i class="fas fa-chevron-right"></i>
                            </div>
                            <div class="tcc-body" style="padding:15px;">
                                <small style="color:#666; display:block;">
                                    <i class="far fa-clock"></i> 
                                    <?php 
                                        $schedules = json_decode($course['schedule_json'] ?? '[]', true);
                                        $labels = [];
                                        if(is_array($schedules)) {
                                            foreach($schedules as $s) {
                                                $day = mb_substr($s['day_label'] ?? '', 0, 3);
                                                $time = $s['start'] ?? '';
                                                if($day && $time) $labels[] = "$day $time";
                                            }
                                        }
                                        echo empty($labels) ? 'A definir' : implode(', ', $labels);
                                    ?>
                                </small>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    include '../includes/teacher_footer.php';
    exit;
}

// =================================================================================
// 2. TELA DE CHAMADA (Curso Selecionado)
// =================================================================================

// Validação de Segurança
$sqlCheck = "SELECT DISTINCT c.* FROM courses c 
             LEFT JOIN course_teachers ct ON c.id = ct.courseId
             WHERE c.id = :cid 
             AND (c.teacherId = :tid OR ct.teacherId = :tid)
             AND c.status = 'Aberto'";

$stmt = $pdo->prepare($sqlCheck);
$stmt->execute([':cid' => $courseId, ':tid' => $teacherId]);
$course = $stmt->fetch();

if (!$course) {
    echo "<div class='page-container'><div class='card-box'>Curso não encontrado ou acesso negado.</div></div>";
    include '../includes/teacher_footer.php';
    exit;
}

// --- PROCESSAR SALVAMENTO ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $attendanceDate = $_POST['attendance_date'];
    $absentStudents = isset($_POST['absent']) ? $_POST['absent'] : []; 
    
    // Busca TODOS os alunos com matrícula APROVADA
    $stmtStudents = $pdo->prepare("SELECT studentId FROM enrollments WHERE courseId = :cid AND status = 'Aprovada'");
    $stmtStudents->execute([':cid' => $courseId]);
    $allStudents = $stmtStudents->fetchAll(PDO::FETCH_COLUMN);

    $pdo->beginTransaction();
    try {
        // Limpa registros anteriores desse dia para evitar duplicidade
        $stmtDel = $pdo->prepare("DELETE FROM attendance WHERE courseId = :cid AND date = :date");
        $stmtDel->execute([':cid' => $courseId, ':date' => $attendanceDate]);

        $stmtInsert = $pdo->prepare("INSERT INTO attendance (courseId, studentId, date, status) VALUES (:cid, :sid, :date, :status)");
        
        foreach ($allStudents as $sid) {
            // CORREÇÃO CRÍTICA: Se estiver no array de ausentes, salva 'Falta', senão 'Presente'
            $status = in_array($sid, $absentStudents) ? 'Falta' : 'Presente';
            
            $stmtInsert->execute([
                ':cid' => $courseId, 
                ':sid' => $sid, 
                ':date' => $attendanceDate, 
                ':status' => $status
            ]);
        }
        
        $pdo->commit();
        $msg = "<div style='background:#d4edda; color:#155724; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #c3e6cb;'><i class='fas fa-check-circle'></i> Chamada salva com sucesso!</div>";
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "<div style='background:#f8d7da; color:#721c24; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #f5c6cb;'>Erro ao salvar: " . $e->getMessage() . "</div>";
    }
}

// --- REGRAS DE DATA E RECESSO ---
$isFuture = strtotime($dateParam) > strtotime(date('Y-m-d'));
$recess = false;
try {
    $stmtRecess = $pdo->prepare("SELECT name FROM school_recess WHERE :date BETWEEN start_date AND end_date LIMIT 1");
    $stmtRecess->execute([':date' => $dateParam]);
    $recess = $stmtRecess->fetch();
} catch(Exception $e) {}

$dayOfWeekNum = date('w', strtotime($dateParam));
$daysMap = [0=>'dom', 1=>'seg', 2=>'ter', 3=>'qua', 4=>'qui', 5=>'sex', 6=>'sab'];
$currentDaySlug = $daysMap[$dayOfWeekNum];

$allowedDays = []; 
$scheduleData = json_decode($course['schedule_json'] ?? '[]', true);
if (is_array($scheduleData)) {
    foreach ($scheduleData as $s) if (isset($s['day_id'])) $allowedDays[] = $s['day_id'];
}
$isValidDay = in_array($currentDaySlug, $allowedDays);
$canTakeAttendance = !$isFuture && !$recess && $isValidDay;

// --- BUSCAR ALUNOS ---
// CORREÇÃO: Status 'Aprovada' na tabela enrollments
$sqlStudents = "SELECT u.id, u.firstName, u.lastName, u.profilePicture 
                FROM enrollments e 
                JOIN users u ON e.studentId = u.id 
                WHERE e.courseId = :cid AND e.status = 'Aprovada' 
                ORDER BY u.firstName ASC";
$stmt = $pdo->prepare($sqlStudents);
$stmt->execute([':cid' => $courseId]);
$students = $stmt->fetchAll();

// Buscar Status Existente (Para preencher os checkboxes)
$existingAttendance = [];
if ($canTakeAttendance) {
    $stmtAtt = $pdo->prepare("SELECT studentId, status FROM attendance WHERE courseId = :cid AND date = :date");
    $stmtAtt->execute([':cid' => $courseId, ':date' => $dateParam]);
    while ($row = $stmtAtt->fetch()) {
        $existingAttendance[$row['studentId']] = $row['status'];
    }
}
?>

<style>
    .date-navigator { display: flex; align-items: center; justify-content: center; gap: 15px; background: #fff; padding: 20px; border-radius: 12px; border: 1px solid #eee; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
    .date-display { font-size: 1.1rem; font-weight: bold; color: #333; display: flex; align-items: center; gap: 10px; position: relative; }
    .date-display input[type="date"] { border: none; font-family: inherit; font-size: inherit; font-weight: inherit; color: inherit; background: transparent; cursor: pointer; outline: none; }
    .btn-nav { background: #f4ecf7; border: none; width: 40px; height: 40px; border-radius: 50%; color: var(--primary-color); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; text-decoration: none; font-size: 1rem; }
    .btn-nav:hover { background: var(--primary-color); color: white; }
    
    .attendance-list { display: flex; flex-direction: column; gap: 10px; }
    .student-row { display: flex; align-items: center; justify-content: space-between; background: #fff; padding: 15px; border-radius: 10px; border: 1px solid #eee; transition: 0.2s; cursor: pointer; user-select: none; }
    .student-checkbox:checked + .student-row { background: #fff5f5; border-color: #ef9a9a; }
    .student-checkbox:checked + .student-row .status-text { color: #c62828; }
    .student-checkbox:checked + .student-row .icon-status { color: #c62828; transform: rotate(0deg); }
    .student-checkbox:checked + .student-row .icon-status::before { content: "\f00d"; } /* X */
    .student-checkbox:not(:checked) + .student-row .status-text { color: #2e7d32; }
    .student-checkbox:not(:checked) + .student-row .icon-status { color: #2e7d32; }
    .student-checkbox:not(:checked) + .student-row .icon-status::before { content: "\f00c"; } /* Check */
    .status-text::after { content: "Presente"; }
    .student-checkbox:checked + .student-row .status-text::after { content: "Falta"; } /* Alterado texto visual */
    .block-alert { text-align: center; padding: 40px; background: #fff; border-radius: 12px; border: 1px dashed #ccc; color: #777; }
    .block-icon { font-size: 3rem; margin-bottom: 15px; opacity: 0.4; }
    .student-info { display: flex; align-items: center; gap: 15px; }
    .student-avatar { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; background: #eee; }
    .status-toggle { pointer-events: none; font-weight: bold; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; }
</style>

<div class="page-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <div>
            <a href="attendance.php" style="text-decoration: none; color: #777; font-size: 0.9rem;"><i class="fas fa-arrow-left"></i> Trocar Turma</a>
            <h2 style="margin: 5px 0 0 0; color: #2c3e50;"><?php echo htmlspecialchars($course['name']); ?></h2>
        </div>
    </div>

    <?php echo $msg; ?>

    <div class="date-navigator">
        <a href="?cid=<?php echo $courseId; ?>&date=<?php echo date('Y-m-d', strtotime($dateParam . ' -1 day')); ?>" class="btn-nav"><i class="fas fa-chevron-left"></i></a>
        <div class="date-display">
            <i class="far fa-calendar-alt"></i>
            <input type="date" value="<?php echo $dateParam; ?>" onchange="window.location.href='?cid=<?php echo $courseId; ?>&date='+this.value">
        </div>
        <a href="?cid=<?php echo $courseId; ?>&date=<?php echo date('Y-m-d', strtotime($dateParam . ' +1 day')); ?>" class="btn-nav"><i class="fas fa-chevron-right"></i></a>
    </div>

    <?php if ($isFuture): ?>
        <div class="block-alert">
            <div class="block-icon"><i class="fas fa-hourglass-half"></i></div>
            <h3>Data Futura</h3>
            <p>Não é possível realizar chamadas para dias que ainda não chegaram.</p>
        </div>
    <?php elseif ($recess): ?>
        <div class="block-alert" style="border-color: #ffeeba; background: #fff3cd; color: #856404;">
            <div class="block-icon"><i class="fas fa-umbrella-beach"></i></div>
            <h3>Recesso Escolar</h3>
            <p>Data inclusa no período: <strong><?php echo htmlspecialchars($recess['name']); ?></strong>.</p>
        </div>
    <?php elseif (!$isValidDay): ?>
        <div class="block-alert">
            <div class="block-icon"><i class="fas fa-calendar-times"></i></div>
            <h3>Dia sem Aula</h3>
            <p>Não há aula configurada para <strong><?php echo ucfirst($daysMap[$dayOfWeekNum]); ?>-feira</strong>.</p>
            <p style="font-size: 0.9rem;">Dias configurados: <?php 
                $labels = [];
                $daysLabelMap = ['dom'=>'Dom', 'seg'=>'Seg', 'ter'=>'Ter', 'qua'=>'Qua', 'qui'=>'Qui', 'sex'=>'Sex', 'sab'=>'Sab'];
                foreach($allowedDays as $d) $labels[] = $daysLabelMap[$d] ?? $d;
                echo empty($labels) ? 'Nenhum' : implode(', ', array_map('ucfirst', $labels));
            ?></p>
        </div>
    <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="save_attendance" value="1">
            <input type="hidden" name="attendance_date" value="<?php echo $dateParam; ?>">

            <div class="card-box">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin:0;"><i class="fas fa-user-check" style="color:var(--primary-color);"></i> Chamada</h3>
                    <span style="font-size: 0.75rem; color: #666; background: #eee; padding: 5px 10px; border-radius: 4px;">Toque para marcar falta</span>
                </div>

                <div class="attendance-list">
                    <?php if (count($students) == 0): ?>
                        <p style="text-align:center; color:#999; padding:20px;">Nenhum aluno com matrícula 'Aprovada' nesta turma.</p>
                    <?php endif; ?>

                    <?php foreach ($students as $student): 
                        // Verifica se o aluno já estava com "Falta" no banco
                        $isAbsent = isset($existingAttendance[$student['id']]) && $existingAttendance[$student['id']] == 'Falta';
                    ?>
                        <input type="checkbox" name="absent[]" value="<?php echo $student['id']; ?>" id="st_<?php echo $student['id']; ?>" class="student-checkbox" style="display:none;" <?php echo $isAbsent ? 'checked' : ''; ?>>
                        <label for="st_<?php echo $student['id']; ?>" class="student-row">
                            <div class="student-info">
                                <?php if($student['profilePicture']): ?>
                                    <img src="<?php echo htmlspecialchars($student['profilePicture']); ?>" class="student-avatar">
                                <?php else: ?>
                                    <div class="student-avatar" style="display:flex; align-items:center; justify-content:center; color:#bbb;"><i class="fas fa-user"></i></div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:bold; color:#333;"><?php echo htmlspecialchars($student['firstName'] . ' ' . $student['lastName']); ?></div>
                                    <div style="font-size:0.8rem; color:#888;">#<?php echo $student['id']; ?></div>
                                </div>
                            </div>
                            <div class="status-toggle">
                                <span class="status-text"></span>
                                <i class="fas icon-status"></i>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 30px; text-align: right;">
                    <button type="submit" class="btn-primary" style="padding: 15px 30px; font-size: 1rem; width: 100%; max-width: 300px;"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include '../includes/teacher_footer.php'; ?>