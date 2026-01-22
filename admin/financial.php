<?php
// admin/financial.php
$pageTitle = "Gestão Financeira";
include '../includes/admin_header.php';

checkRole(['admin', 'superadmin']);

// --- CONFIGURAÇÃO DE FILTROS ---
$period = isset($_GET['period']) ? $_GET['period'] : (int)date('m'); 
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$courseFilter = isset($_GET['courseId']) ? (int)$_GET['courseId'] : 0;
$teacherFilter = isset($_GET['teacherId']) ? (int)$_GET['teacherId'] : 0;

// --- DEFINIÇÃO DO INTERVALO DE DATAS ---
$startMonth = 1;
$endMonth = 12;
$periodLabel = "Ano de $year";

// Array de tradução dos meses
$mesesPT = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

if (is_numeric($period)) {
    $startMonth = (int)$period;
    $endMonth = (int)$period;
    $periodLabel = $mesesPT[$startMonth] . " de " . $year;
} else {
    switch ($period) {
        case 'Q1': $startMonth=1; $endMonth=3; $periodLabel="1º Trimestre de $year"; break;
        case 'Q2': $startMonth=4; $endMonth=6; $periodLabel="2º Trimestre de $year"; break;
        case 'Q3': $startMonth=7; $endMonth=9; $periodLabel="3º Trimestre de $year"; break;
        case 'Q4': $startMonth=10; $endMonth=12; $periodLabel="4º Trimestre de $year"; break;
        case 'S1': $startMonth=1; $endMonth=6; $periodLabel="1º Semestre de $year"; break;
        case 'S2': $startMonth=7; $endMonth=12; $periodLabel="2º Semestre de $year"; break;
        case 'ALL': default: $startMonth=1; $endMonth=12; $periodLabel="Ano de $year Completo"; break;
    }
}

// --- PROCESSAMENTO: BAIXA EM MASSA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_pay') {
    if (!empty($_POST['payment_ids'])) {
        $ids = implode(',', array_map('intval', $_POST['payment_ids']));
        $payDate = date('Y-m-d H:i:s'); 
        try {
            $pdo->query("UPDATE payments SET status = 'Pago', paymentDate = '$payDate' WHERE id IN ($ids) AND status != 'Pago'");
            $msgType = "success"; $msgContent = "Pagamentos baixados com sucesso!";
        } catch (Exception $e) {
            $msgType = "danger"; $msgContent = "Erro: " . $e->getMessage();
        }
    }
}

// --- CONSULTAS SQL ---

// Parâmetros base
$paramsFilter = [':y' => $year, ':start' => $startMonth, ':end' => $endMonth];

// 1. DADOS PARA O GRÁFICO DE EVOLUÇÃO
$sqlChart = "SELECT 
                MONTH(p.dueDate) as month_num,
                SUM(p.amount) as total_expected,
                SUM(CASE WHEN p.status = 'Pago' THEN p.amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN p.status = 'Pendente' AND p.dueDate < CURRENT_DATE() THEN p.amount ELSE 0 END) as total_late,
                SUM(CASE WHEN p.status = 'Pendente' AND p.dueDate >= CURRENT_DATE() THEN p.amount ELSE 0 END) as total_open
             FROM payments p
             LEFT JOIN course_teachers ct ON p.courseId = ct.courseId
             WHERE YEAR(p.dueDate) = :y 
             AND MONTH(p.dueDate) BETWEEN :start AND :end";

if ($courseFilter > 0) { $sqlChart .= " AND p.courseId = :cid"; $paramsFilter[':cid'] = $courseFilter; }
if ($teacherFilter > 0) { $sqlChart .= " AND ct.teacherId = :tid"; $paramsFilter[':tid'] = $teacherFilter; }

$sqlChart .= " GROUP BY MONTH(p.dueDate) ORDER BY MONTH(p.dueDate)";

$stmtChart = $pdo->prepare($sqlChart);
$stmtChart->execute($paramsFilter);
$chartDataRaw = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

// Prepara arrays para o JavaScript
$monthsLabels = [];
$dataPaid = [];
$dataLate = [];
$dataOpen = [];

for ($m = $startMonth; $m <= $endMonth; $m++) {
    $found = false;
    $pt_months_short = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
    $monthsLabels[] = $pt_months_short[$m];

    foreach ($chartDataRaw as $row) {
        if ($row['month_num'] == $m) {
            $dataPaid[] = $row['total_paid'];
            $dataLate[] = $row['total_late'];
            $dataOpen[] = $row['total_open'];
            $found = true;
            break;
        }
    }
    if (!$found) { $dataPaid[] = 0; $dataLate[] = 0; $dataOpen[] = 0; }
}

// 2. LISTA DETALHADA
$sqlPay = "SELECT p.*, u.firstName, u.lastName, c.name as courseName 
           FROM payments p
           JOIN users u ON p.studentId = u.id
           JOIN courses c ON p.courseId = c.id";

$wherePay = ["YEAR(p.dueDate) = :y", "MONTH(p.dueDate) BETWEEN :start AND :end"];

if ($courseFilter > 0) $wherePay[] = "p.courseId = :cid";
if ($teacherFilter > 0) {
    $sqlPay .= " JOIN course_teachers ct_filter ON p.courseId = ct_filter.courseId";
    $wherePay[] = "ct_filter.teacherId = :tid";
}

$sqlPay .= " WHERE " . implode(" AND ", $wherePay) . " ORDER BY p.dueDate ASC";
$stmt = $pdo->prepare($sqlPay);
$stmt->execute($paramsFilter);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. REPASSES
$sqlComm = "SELECT 
                u.firstName, u.lastName, c.name as courseName,
                ct.commissionRate,
                SUM(p.amount) as total_collected,
                (SUM(p.amount) * (ct.commissionRate / 100)) as commission_value
            FROM payments p
            JOIN course_teachers ct ON p.courseId = ct.courseId
            JOIN users u ON ct.teacherId = u.id
            JOIN courses c ON p.courseId = c.id
            WHERE p.status = 'Pago' 
            AND YEAR(p.dueDate) = :y 
            AND MONTH(p.dueDate) BETWEEN :start AND :end";

if ($courseFilter > 0) $sqlComm .= " AND p.courseId = :cid";
if ($teacherFilter > 0) $sqlComm .= " AND ct.teacherId = :tid";

$sqlComm .= " GROUP BY u.id, c.id, ct.commissionRate HAVING commission_value > 0";

$stmtComm = $pdo->prepare($sqlComm);
$stmtComm->execute($paramsFilter); 
$commissions = $stmtComm->fetchAll(PDO::FETCH_ASSOC);

// 4. TOTAIS GERAIS
$totalExpected = 0; $totalPaid = 0; $totalLate = 0;
$chartCourses = [];

foreach ($payments as $p) {
    $totalExpected += $p['amount'];
    if ($p['status'] == 'Pago') {
        $totalPaid += $p['amount'];
        $cName = $p['courseName'];
        if (!isset($chartCourses[$cName])) $chartCourses[$cName] = 0;
        $chartCourses[$cName] += $p['amount'];
    }
    if ($p['status'] == 'Pendente' && $p['dueDate'] < date('Y-m-d')) $totalLate += $p['amount'];
}

$totalCommissions = 0; 
foreach ($commissions as $c) $totalCommissions += $c['commission_value'];
$delinquencyRate = ($totalExpected > 0) ? ($totalLate / $totalExpected) * 100 : 0;

$labelsCourses = array_keys($chartCourses);
$dataCourses = array_values($chartCourses);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="content-wrapper">
    <div style="max-width: 1200px; margin: 0 auto;">
        
        <div class="financial-header profile-header">
            <div class="page-title">
                <h2>Gestão Financeira</h2>
                <p>Análise de receitas e repasses a profissionais</p>
            </div>
            <div class="header-actions">
                <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>

        <div class="ux-card filter-card">
            <form method="GET" class="filter-bar">
                <div class="filter-group">
                    <i class="far fa-calendar-alt"></i>
                    <select name="period" class="clean-select" onchange="this.form.submit()">
                        <optgroup label="Visão Macro">
                            <option value="ALL" <?php echo ($period == 'ALL') ? 'selected' : ''; ?>>Ano Todo</option>
                            <option value="S1" <?php echo ($period == 'S1') ? 'selected' : ''; ?>>1º Semestre</option>
                            <option value="S2" <?php echo ($period == 'S2') ? 'selected' : ''; ?>>2º Semestre</option>
                        </optgroup>
                        <optgroup label="Trimestres">
                            <option value="Q1" <?php echo ($period == 'Q1') ? 'selected' : ''; ?>>1º Trimestre</option>
                            <option value="Q2" <?php echo ($period == 'Q2') ? 'selected' : ''; ?>>2º Trimestre</option>
                            <option value="Q3" <?php echo ($period == 'Q3') ? 'selected' : ''; ?>>3º Trimestre</option>
                            <option value="Q4" <?php echo ($period == 'Q4') ? 'selected' : ''; ?>>4º Trimestre</option>
                        </optgroup>
                        <optgroup label="Mensal">
                            <?php foreach($mesesPT as $num => $name){ $sel = ($period == $num) ? 'selected' : ''; echo "<option value='$num' $sel>$name</option>"; } ?>
                        </optgroup>
                    </select>
                </div>
                
                <div class="filter-separator"></div>
                
                <div class="filter-group">
                    <i class="fas fa-history"></i>
                    <select name="year" class="clean-select" onchange="this.form.submit()">
                        <?php for($y=2024; $y<=2030; $y++){ $sel = ($y == $year) ? 'selected' : ''; echo "<option value='$y' $sel>$y</option>"; } ?>
                    </select>
                </div>
                
                <div class="filter-separator"></div>
                
                <div class="filter-group">
                    <i class="fas fa-book"></i>
                    <select name="courseId" class="clean-select" onchange="this.form.submit()">
                        <option value="0">Todos os Cursos</option>
                        <?php 
                        $courses = $pdo->query("SELECT id, name FROM courses ORDER BY name ASC")->fetchAll();
                        foreach($courses as $c){ $sel = ($courseFilter == $c['id']) ? 'selected' : ''; echo "<option value='{$c['id']}' $sel>{$c['name']}</option>"; }
                        ?>
                    </select>
                </div>
                
                <div class="filter-separator"></div>
                
                <div class="filter-group" style="flex-grow: 2;">
                    <i class="fas fa-user-tie"></i>
                    <select name="teacherId" class="clean-select" style="width: 100%;" onchange="this.form.submit()">
                        <option value="0">Todos os Profissionais</option>
                        <?php 
                        $professionals = $pdo->query("SELECT DISTINCT u.id, u.firstName, u.lastName, u.role FROM users u JOIN course_teachers ct ON u.id = ct.teacherId ORDER BY u.firstName ASC")->fetchAll();
                        foreach($professionals as $prof){
                            $sel = ($teacherFilter == $prof['id']) ? 'selected' : '';
                            $roleLabel = ($prof['role'] !== 'teacher') ? " (".ucfirst($prof['role']).")" : "";
                            echo "<option value='{$prof['id']}' $sel>{$prof['firstName']} {$prof['lastName']}{$roleLabel}</option>";
                        }
                        ?>
                    </select>
                </div>
            </form>
        </div>

        <div class="kpi-row">
            <div class="kpi-card-fin blue"><label>Previsto</label><h3>R$ <?php echo number_format($totalExpected, 2, ',', '.'); ?></h3></div>
            <div class="kpi-card-fin green"><label>Arrecadado</label><h3>R$ <?php echo number_format($totalPaid, 2, ',', '.'); ?></h3></div>
            <div class="kpi-card-fin red"><label>Inadimplência</label><h3 style="color: #e74c3c;">R$ <?php echo number_format($totalLate, 2, ',', '.'); ?></h3><small style="color: #e74c3c;"><?php echo number_format($delinquencyRate, 1); ?>% do período</small></div>
            <div class="kpi-card-fin orange"><label>Total Comissões</label><h3>R$ <?php echo number_format($totalCommissions, 2, ',', '.'); ?></h3><small>A repassar</small></div>
        </div>

        <div class="charts-row">
            <div class="ux-card" style="padding: 20px;">
                <h4 style="margin-top:0; color:#555;">Análise Financeira (<?php echo $periodLabel; ?>)</h4>
                <div style="height: 250px;"><canvas id="chartEvolucao"></canvas></div>
            </div>
            <div class="ux-card" style="padding: 20px;">
                <h4 style="margin-top:0; color:#555;">Receita por Curso</h4>
                <div style="height: 250px; display: flex; align-items: center; justify-content: center;">
                    <?php if(empty($dataCourses)): ?>
                        <p style="color:#999;">Sem dados pagos no período.</p>
                    <?php else: ?>
                        <canvas id="chartCursos"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (isset($msgType)): ?><div class="alert-float alert-<?php echo $msgType; ?>" style="margin-bottom: 20px;"><i class="fas fa-info-circle"></i> <?php echo $msgContent; ?></div><?php endif; ?>

        <div class="tab-container">
            <button class="tab-btn active" onclick="openTab(event, 'recebiveis')"><i class="fas fa-user-graduate"></i> Mensalidades</button>
            <button class="tab-btn" onclick="openTab(event, 'repasses')"><i class="fas fa-chalkboard-teacher"></i> Repasses (Comissões)</button>
        </div>

        <div id="recebiveis" class="tab-content active">
            <form method="POST" id="bulkForm">
                <input type="hidden" name="action" value="bulk_pay">
                <div class="ux-card" style="padding: 0; overflow: hidden; border-top-left-radius: 0;">
                    <div class="table-responsive">
                        <table class="fin-table">
                            <thead>
                                <tr>
                                    <th width="40"><input type="checkbox" id="selectAll"></th>
                                    <th>Vencimento</th>
                                    <th>Aluno</th>
                                    <th>Curso</th>
                                    <th>Valor</th>
                                    <th>Status</th>
                                    <th width="100">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($payments) > 0): ?>
                                    <?php foreach($payments as $p): ?>
                                    <?php $stClass = ($p['status'] == 'Pago') ? 'st-paid' : (($p['dueDate'] < date('Y-m-d')) ? 'st-late' : 'st-pending'); ?>
                                    <tr>
                                        <td><?php if($p['status'] != 'Pago'): ?><input type="checkbox" name="payment_ids[]" value="<?php echo $p['id']; ?>" class="pay-check"><?php endif; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($p['dueDate'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($p['firstName'] . ' ' . $p['lastName']); ?></strong></td>
                                        <td><span class="badge-course"><?php echo htmlspecialchars($p['courseName']); ?></span></td>
                                        <td>R$ <?php echo number_format($p['amount'], 2, ',', '.'); ?></td>
                                        <td><span class="status-pill <?php echo $stClass; ?>"><?php echo $p['status']; ?></span></td>
                                        <td>
                                            <?php if($p['status'] != 'Pago'): ?>
                                                <button type="button" class="btn-icon" onclick="baixaIndividual(<?php echo $p['id']; ?>)"><i class="fas fa-check"></i></button>
                                            <?php else: ?>
                                                <small class="text-muted"><?php echo date('d/m', strtotime($p['paymentDate'])); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" style="text-align:center; padding: 30px; color:#999;">Nenhum lançamento encontrado para este filtro.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="bulkActions" class="bulk-bar">
                    <span><b id="selectedCount">0</b> selecionados</span>
                    <button type="submit" class="btn-bulk" onclick="return confirm('Confirmar baixa?')">
                        <i class="fas fa-check-double"></i> Baixar Selecionados
                    </button>
                </div>
            </form>
        </div>

        <div id="repasses" class="tab-content">
            <div class="ux-card" style="padding: 0; overflow: hidden; border-top-left-radius: 0;">
                <div class="table-responsive">
                    <table class="fin-table">
                        <thead>
                            <tr><th>Profissional</th><th>Curso</th><th>Total Pago pelos Alunos</th><th>Comissão (%)</th><th>Valor Líquido</th></tr>
                        </thead>
                        <tbody>
                            <?php if(count($commissions) > 0): ?>
                                <?php foreach($commissions as $c): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($c['firstName'] . ' ' . $c['lastName']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($c['courseName']); ?></td>
                                    <td>R$ <?php echo number_format($c['total_collected'], 2, ',', '.'); ?></td>
                                    <td><?php echo number_format($c['commissionRate'], 1); ?>%</td>
                                    <td style="color: #27ae60; font-weight:bold;">R$ <?php echo number_format($c['commission_value'], 2, ',', '.'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align:center; padding: 30px; color:#999;">Sem repasses para o profissional ou período selecionado.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function openTab(evt, tabName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; tabcontent[i].classList.remove("active"); }
        tablinks = document.getElementsByClassName("tab-btn");
        for (i = 0; i < tablinks.length; i++) { tablinks[i].classList.remove("active"); }
        document.getElementById(tabName).style.display = "block";
        document.getElementById(tabName).classList.add("active");
        evt.currentTarget.classList.add("active");
    }
    document.addEventListener("DOMContentLoaded", function() { document.getElementById('repasses').style.display = 'none'; });
    
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.pay-check');
    const bulkBar = document.getElementById('bulkActions');
    const countSpan = document.getElementById('selectedCount');
    if(selectAll) selectAll.addEventListener('change', function() { checkboxes.forEach(cb => cb.checked = this.checked); updateBulkBar(); });
    if(checkboxes) checkboxes.forEach(cb => cb.addEventListener('change', updateBulkBar));
    function updateBulkBar() {
        const checked = document.querySelectorAll('.pay-check:checked');
        if(countSpan) countSpan.innerText = checked.length;
        if(bulkBar) checked.length > 0 ? bulkBar.classList.add('visible') : bulkBar.classList.remove('visible');
    }
    function baixaIndividual(id) {
        if(confirm('Confirmar o pagamento?')) {
            let form = document.createElement('form'); form.method = 'POST';
            let inputAction = document.createElement('input'); inputAction.type = 'hidden'; inputAction.name = 'action'; inputAction.value = 'bulk_pay';
            let inputId = document.createElement('input'); inputId.type = 'hidden'; inputId.name = 'payment_ids[]'; inputId.value = id;
            form.appendChild(inputAction); form.appendChild(inputId); document.body.appendChild(form); form.submit();
        }
    }

    // --- GRÁFICO 1: EVOLUÇÃO ---
    const ctxEvolucao = document.getElementById('chartEvolucao').getContext('2d');
    new Chart(ctxEvolucao, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($monthsLabels); ?>,
            datasets: [
                { label: 'Recebido (Pago)', data: <?php echo json_encode($dataPaid); ?>, backgroundColor: '#27ae60', borderRadius: 4 },
                { label: 'Inadimplente (Atrasado)', data: <?php echo json_encode($dataLate); ?>, backgroundColor: '#e74c3c', borderRadius: 4 },
                { label: 'A Receber (Aberto)', data: <?php echo json_encode($dataOpen); ?>, backgroundColor: '#bdc3c7', borderRadius: 4 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } }, plugins: { legend: { position: 'bottom' } } }
    });

    // --- GRÁFICO 2: CURSOS ---
    <?php if(!empty($dataCourses)): ?>
    const ctxCursos = document.getElementById('chartCursos').getContext('2d');
    new Chart(ctxCursos, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($labelsCourses); ?>,
            datasets: [{ data: <?php echo json_encode($dataCourses); ?>, backgroundColor: ['#3498db', '#9b59b6', '#f1c40f', '#e67e22', '#e74c3c', '#1abc9c'], borderWidth: 0 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
    <?php endif; ?>
</script>

<?php include '../includes/admin_footer.php'; ?>