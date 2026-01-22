<?php
// teacher/course_details.php
$pageTitle = "Detalhes da Turma";
include '../includes/teacher_header.php';

$teacherId = $_SESSION['user_id'];
$courseId = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;

// 1. SEGURANÇA E DADOS DO CURSO
$sqlCheck = "SELECT DISTINCT c.* FROM courses c 
             LEFT JOIN course_teachers ct ON c.id = ct.courseId
             WHERE c.id = :cid 
             AND (c.teacherId = :tid OR ct.teacherId = :tid)
             AND c.status = 'Aberto'";

$stmt = $pdo->prepare($sqlCheck);
$stmt->execute([':cid' => $courseId, ':tid' => $teacherId]);
$course = $stmt->fetch();

if (!$course) {
    echo "<div class='page-container'><div class='card-box'>Curso não encontrado.</div></div>";
    include '../includes/teacher_footer.php';
    exit;
}

// 2. CÁLCULO DE AULAS DADAS
$stmtClasses = $pdo->prepare("SELECT COUNT(DISTINCT date) FROM attendance WHERE courseId = :cid");
$stmtClasses->execute([':cid' => $courseId]);
$totalClassesGiven = $stmtClasses->fetchColumn();

// 3. BUSCAR ALUNOS E FREQUÊNCIA
$sqlStudents = "SELECT u.id, u.firstName, u.lastName, u.profilePicture 
                FROM enrollments e 
                JOIN users u ON e.studentId = u.id 
                WHERE e.courseId = :cid AND e.status = 'Aprovada' 
                ORDER BY u.firstName ASC";
$stmt = $pdo->prepare($sqlStudents);
$stmt->execute([':cid' => $courseId]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Processa frequência
foreach ($students as &$student) {
    $stmtAbsence = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE courseId = :cid AND studentId = :sid AND status = 'Falta'");
    $stmtAbsence->execute([':cid' => $courseId, ':sid' => $student['id']]);
    $absences = $stmtAbsence->fetchColumn();

    $student['absences'] = $absences;
    
    if ($totalClassesGiven > 0) {
        $presenceCount = $totalClassesGiven - $absences;
        $percent = ($presenceCount / $totalClassesGiven) * 100;
        $student['attendance_pct'] = round($percent);
    } else {
        $student['attendance_pct'] = 100;
    }
}
unset($student);
?>

<style>
    .course-hero {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white; padding: 25px; border-radius: 15px; margin-bottom: 25px;
        box-shadow: 0 5px 15px rgba(142, 68, 173, 0.3); position: relative; overflow: hidden;
    }
    .stats-row { display: flex; gap: 15px; margin-bottom: 25px; overflow-x: auto; padding-bottom: 5px; }
    .mini-stat {
        background: #fff; padding: 15px; border-radius: 10px; border: 1px solid #eee;
        min-width: 120px; flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
    }
    .mini-stat strong { font-size: 1.4rem; color: var(--primary-color); }
    .mini-stat span { font-size: 0.8rem; color: #777; text-transform: uppercase; }

    .student-list-card {
        background: #fff; border-radius: 12px; border: 1px solid #eee;
        margin-bottom: 15px; padding: 15px; display: flex; align-items: center; justify-content: space-between;
    }
    .sl-info { display: flex; align-items: center; gap: 15px; }
    .sl-avatar { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; background: #f0f0f0; }
    
    .attendance-bar-container { width: 100px; text-align: right; }
    .progress-track { background: #eee; height: 6px; border-radius: 3px; width: 100%; margin-top: 5px; overflow: hidden; }
    .progress-fill { height: 100%; border-radius: 3px; }
    
    .high-att { background: #27ae60; }
    .mid-att { background: #f39c12; }
    .low-att { background: #e74c3c; }
</style>

<div class="page-container">
    
    <a href="index.php" style="display:inline-flex; align-items:center; gap:8px; color:#666; text-decoration:none; margin-bottom:20px; font-weight:600;">
        <i class="fas fa-arrow-left"></i> Voltar ao Painel
    </a>

    <div class="course-hero">
        <h2 style="margin:0; font-size:1.5rem;"><?php echo htmlspecialchars($course['name']); ?></h2>
        <p style="margin:5px 0 0 0; opacity:0.9; font-size:0.9rem;">
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
                echo empty($labels) ? 'Horário a definir' : implode(', ', $labels);
            ?>
        </p>
    </div>

    <div class="stats-row">
        <div class="mini-stat">
            <strong><?php echo count($students); ?></strong>
            <span>Alunos</span>
        </div>
        <div class="mini-stat">
            <strong><?php echo $totalClassesGiven; ?></strong>
            <span>Aulas Dadas</span>
        </div>
    </div>

    <div style="margin-bottom: 25px;">
        <a href="attendance.php?cid=<?php echo $courseId; ?>" class="btn-primary" style="display:block; text-align:center; padding:12px; border-radius:8px; width:100%; box-sizing:border-box;">
            <i class="fas fa-calendar-check"></i> Realizar Chamada de Hoje
        </a>
    </div>

    <div class="section-header" style="margin-top:0;">
        <h3 class="section-title">Frequência dos Alunos</h3>
    </div>

    <?php if (count($students) == 0): ?>
        <div class="card-box" style="text-align:center; padding:30px; color:#888;">
            <p>Nenhum aluno com matrícula aprovada nesta turma.</p>
        </div>
    <?php else: ?>
        <div class="student-list">
            <?php foreach ($students as $student): 
                $barColor = 'high-att';
                if($student['attendance_pct'] < 75) $barColor = 'mid-att';
                if($student['attendance_pct'] < 50) $barColor = 'low-att';
            ?>
                <div class="student-list-card">
                    <div class="sl-info">
                        <?php if($student['profilePicture']): ?>
                            <img src="<?php echo htmlspecialchars($student['profilePicture']); ?>" class="sl-avatar">
                        <?php else: ?>
                            <div class="sl-avatar" style="display:flex; align-items:center; justify-content:center; color:#bbb;"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        
                        <div>
                            <div style="font-weight:bold; color:#333;"><?php echo htmlspecialchars($student['firstName'] . ' ' . $student['lastName']); ?></div>
                            <div style="font-size:0.8rem; color:#888;">Faltas: <strong><?php echo $student['absences']; ?></strong></div>
                        </div>
                    </div>

                    <div class="attendance-bar-container">
                        <span style="font-size:0.85rem; font-weight:bold; color:#555;"><?php echo $student['attendance_pct']; ?>%</span>
                        <div class="progress-track">
                            <div class="progress-fill <?php echo $barColor; ?>" style="width: <?php echo $student['attendance_pct']; ?>%;"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php include '../includes/teacher_footer.php'; ?>