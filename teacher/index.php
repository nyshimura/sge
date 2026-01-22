<?php
// teacher/index.php
$pageTitle = "Painel do Professor";
include '../includes/teacher_header.php';

$teacherId = $_SESSION['user_id'];

// --- 1. BUSCAR CURSOS (Titular OU Auxiliar) ---
// Mesma lógica de antes: Distinct + Left Join + Ordenação por Horário
$sqlCourses = "SELECT DISTINCT c.* FROM courses c
               LEFT JOIN course_teachers ct ON c.id = ct.courseId
               WHERE (c.teacherId = :tid OR ct.teacherId = :tid) 
               AND c.status = 'Aberto'";

$stmt = $pdo->prepare($sqlCourses);
$stmt->execute([':tid' => $teacherId]);
$coursesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Processamento: Contar Alunos e Ordenar por Horário
$myCourses = [];
$totalActiveStudents = 0; // Variável para o card de total de alunos

foreach ($coursesRaw as $course) {
    // Conta alunos com status 'Aprovada'
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE courseId = :cid AND status = 'Aprovada'");
    $stmtCount->execute([':cid' => $course['id']]);
    $course['active_students'] = $stmtCount->fetchColumn();
    
    // Soma ao total geral
    $totalActiveStudents += $course['active_students'];

    // Descobre o primeiro horário para ordenação
    $firstTime = '23:59'; 
    $schedules = json_decode($course['schedule_json'] ?? '[]', true);
    if (is_array($schedules)) {
        foreach ($schedules as $s) {
            if (isset($s['start']) && $s['start'] < $firstTime) {
                $firstTime = $s['start'];
            }
        }
    }
    $course['sort_time'] = $firstTime;
    $myCourses[] = $course;
}

// Ordena o array de cursos pelo horário
usort($myCourses, function($a, $b) {
    return strcmp($a['sort_time'], $b['sort_time']);
});

// --- 2. CALCULAR COMISSÕES DO MÊS ATUAL (NOVO) ---
$currentMonth = date('m');
$currentYear = date('Y');

// Query que soma: (Valor Pago * Taxa%) para pagamentos deste mês
$sqlComm = "SELECT SUM(p.amount * (ct.commissionRate / 100)) 
            FROM payments p
            JOIN courses c ON p.courseId = c.id
            JOIN course_teachers ct ON c.id = ct.courseId AND ct.teacherId = :tid
            WHERE p.status = 'Pago'
            AND MONTH(p.paymentDate) = :month
            AND YEAR(p.paymentDate) = :year
            AND ct.commissionRate > 0";

$stmtComm = $pdo->prepare($sqlComm);
$stmtComm->execute([
    ':tid' => $teacherId,
    ':month' => $currentMonth,
    ':year' => $currentYear
]);

// Se retornar null (sem pagamentos), assume 0.00
$commissionValue = $stmtComm->fetchColumn() ?: 0.00;

// Nome do mês para exibição (opcional, para ficar bonito no card)
$monthNames = [1=>'Jan', 2=>'Fev', 3=>'Mar', 4=>'Abr', 5=>'Mai', 6=>'Jun', 7=>'Jul', 8=>'Ago', 9=>'Set', 10=>'Out', 11=>'Nov', 12=>'Dez'];
$curMonthName = $monthNames[(int)$currentMonth];
?>

<div class="dashboard-stats">
    
    <div class="stat-card">
        <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
        <div class="stat-info">
            <h4>Minhas Turmas</h4>
            <p><?php echo count($myCourses); ?></p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="color: #27ae60; background: #e8f5e9;"><i class="fas fa-users"></i></div>
        <div class="stat-info">
            <h4>Alunos Ativos</h4>
            <p><?php echo $totalActiveStudents; ?></p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="color: #f39c12; background: #fff8e1;"><i class="fas fa-coins"></i></div>
        <div class="stat-info">
            <h4>Comissões (<?php echo $curMonthName; ?>)</h4>
            <p>R$ <?php echo number_format($commissionValue, 2, ',', '.'); ?></p>
        </div>
    </div>
</div>

<div class="section-header">
    <h3 class="section-title"><i class="fas fa-book-open" style="color: var(--primary-color);"></i> Gerenciar Turmas</h3>
</div>

<?php if(count($myCourses) == 0): ?>
    <div class="card-box" style="text-align:center; padding:50px; color:#888;">
        <i class="fas fa-chalkboard-teacher fa-3x" style="opacity:0.3; margin-bottom:15px;"></i>
        <p>Você ainda não possui turmas atribuídas.</p>
    </div>
<?php else: ?>
    <div class="teacher-course-grid">
        <?php foreach($myCourses as $course): ?>
            <div class="teacher-course-card">
                <div class="tcc-header">
                    <div>
                        <h4 style="margin:0; color:#333; font-size:1.1rem;"><?php echo htmlspecialchars($course['name']); ?></h4>
                        <small style="color:#777;">
                            <i class="far fa-calendar-alt"></i> 
                            <?php 
                                // Exibe dias e horários formatados
                                $daysText = "A definir";
                                $schedules = json_decode($course['schedule_json'] ?? '[]', true);
                                if (is_array($schedules) && count($schedules) > 0) {
                                    $labels = [];
                                    foreach ($schedules as $sched) {
                                        $label = $sched['day_label'] ?? '';
                                        $shortDay = mb_substr($label, 0, 3); 
                                        $time = $sched['start'] ?? '';
                                        if($label && $time) $labels[] = "$shortDay $time";
                                    }
                                    if (!empty($labels)) {
                                        $daysText = implode(', ', $labels);
                                    }
                                }
                                echo $daysText;
                            ?>
                        </small>
                    </div>
                    <div style="background:var(--primary-color); color:white; padding:5px 10px; border-radius:50px; font-size:0.8rem; font-weight:bold;">
                        <?php echo $course['active_students']; ?> Alunos
                    </div>
                </div>
                
                <div class="tcc-body">
                    <p style="color:#666; font-size:0.9rem; margin:0;">
                        <?php 
                        if (!empty($course['description'])) {
                            echo substr(strip_tags($course['description']), 0, 80) . '...'; 
                        } else {
                            echo "Sem descrição.";
                        }
                        ?>
                    </p>
                </div>
                
                <div class="tcc-actions">
                    <a href="attendance.php?cid=<?php echo $course['id']; ?>" class="btn-action btn-fill">
                        <i class="fas fa-user-check"></i> Chamada
                    </a>
                    <a href="course_details.php?cid=<?php echo $course['id']; ?>" class="btn-action btn-outline">
                        <i class="fas fa-eye"></i> Detalhes
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include '../includes/teacher_footer.php'; ?>