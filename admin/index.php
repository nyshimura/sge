<?php
// admin/index.php
$pageTitle = "Painel de Controle";
include '../includes/admin_header.php';

checkRole(['admin', 'superadmin']);

// --- 1. FEEDBACK DO SISTEMA ---
if (isset($_GET['msg'])) {
    $alertType = 'info';
    $alertMsg = '';
    
    switch ($_GET['msg']) {
        case 'approved_email_sent':
            $alertType = 'success'; 
            $alertMsg = 'Matrícula aprovada, financeiro gerado e e-mail enviado!';
            break;
        case 'approved_email_error':
            $alertType = 'warning'; 
            $alertMsg = 'Matrícula aprovada, mas houve erro ao enviar e-mail: ' . htmlspecialchars($_GET['details'] ?? '');
            break;
        case 'approved_no_config':
            $alertType = 'warning'; 
            $alertMsg = 'Matrícula aprovada, mas e-mail não enviado (SMTP desligado).';
            break;
        case 'approved':
            $alertType = 'success'; 
            $alertMsg = 'Matrícula aprovada com sucesso!';
            break;
        case 'error':
            $alertType = 'danger'; 
            $alertMsg = 'Erro: ' . htmlspecialchars($_GET['details'] ?? 'Desconhecido');
            break;
        case 'success': 
             $alertType = 'success'; 
             $alertMsg = 'Ação realizada com sucesso!';
             break;
    }

    if ($alertMsg) {
        echo "<div class='alert-float alert-$alertType'><i class='fas fa-info-circle'></i> $alertMsg</div>";
    }
}

// --- 2. LÓGICA LOCAL: BAIXA EM MASSA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_pay_late') {
    if (!empty($_POST['payment_ids'])) {
        $ids = implode(',', array_map('intval', $_POST['payment_ids']));
        $payDate = date('Y-m-d H:i:s'); 
        try {
            $pdo->query("UPDATE payments SET status = 'Pago', paymentDate = '$payDate' WHERE id IN ($ids) AND status = 'Pendente'");
            echo "<script>window.location.href='index.php?msg=success';</script>";
            exit;
        } catch (Exception $e) {
            echo "<div class='alert-float alert-danger'>Erro ao dar baixa: " . $e->getMessage() . "</div>";
        }
    }
}

// --- 3. DADOS DO DASHBOARD ---
$filterMonth = isset($_GET['late_month']) ? (int)$_GET['late_month'] : (int)date('m');
$filterYear  = isset($_GET['late_year']) ? (int)$_GET['late_year'] : (int)date('Y');
$filterCourse = isset($_GET['late_course']) ? (int)$_GET['late_course'] : 0;

$mesesPT = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];

$coursesList = $pdo->query("SELECT id, name FROM courses ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$sqlLateList = "SELECT p.id, p.amount, p.dueDate, u.firstName, u.lastName, c.name as courseName
                FROM payments p
                JOIN users u ON p.studentId = u.id
                JOIN courses c ON p.courseId = c.id
                WHERE p.status = 'Pendente' 
                AND p.dueDate < CURRENT_DATE()
                AND MONTH(p.dueDate) = :m AND YEAR(p.dueDate) = :y";
$paramsLate = [':m' => $filterMonth, ':y' => $filterYear];

if ($filterCourse > 0) { $sqlLateList .= " AND p.courseId = :cid"; $paramsLate[':cid'] = $filterCourse; }
$sqlLateList .= " ORDER BY p.dueDate ASC";

$stmtLate = $pdo->prepare($sqlLateList);
$stmtLate->execute($paramsLate);
$latePayments = $stmtLate->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$stmtFin = $pdo->query("SELECT SUM(amount) as total FROM payments WHERE MONTH(dueDate) = MONTH(CURRENT_DATE()) AND YEAR(dueDate) = YEAR(CURRENT_DATE())");
$revenueMonth = $stmtFin->fetch()['total'] ?? 0;
$countPending = $pdo->query("SELECT COUNT(*) as total FROM enrollments WHERE status = 'Pendente'")->fetch()['total'];
$countActive = $pdo->query("SELECT COUNT(DISTINCT studentId) as total FROM enrollments WHERE status IN ('Aprovada', 'Ativo')")->fetch()['total'];
$countLateTotal = $pdo->query("SELECT COUNT(*) as total FROM payments WHERE status = 'Pendente' AND dueDate < CURRENT_DATE()")->fetch()['total'];

// Lista de Pendentes
$listPending = $pdo->query("SELECT e.studentId, e.courseId, u.firstName, u.lastName, c.name as courseName, e.enrollmentDate FROM enrollments e JOIN users u ON e.studentId = u.id JOIN courses c ON e.courseId = c.id WHERE e.status = 'Pendente' ORDER BY e.enrollmentDate DESC LIMIT 5")->fetchAll();
?>

<div class="content-wrapper">
    <div style="margin-bottom: 25px;">
        <h2 style="color: #2c3e50; font-weight: 700; font-size: 1.5rem;">Olá, Administrador!</h2>
        <p style="color: #7f8c8d; font-size: 0.95rem;">Visão geral da escola e pendências.</p>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card kpi-finance">
            <div class="kpi-info"><h3>R$ <?php echo number_format($revenueMonth, 2, ',', '.'); ?></h3><p>Previsão (Mês)</p></div>
            <div class="kpi-icon"><i class="fas fa-hand-holding-usd"></i></div>
        </div>
        <div class="kpi-card kpi-pending">
            <div class="kpi-info"><h3><?php echo $countPending; ?></h3><p>Pendentes</p></div>
            <div class="kpi-icon"><i class="fas fa-user-clock"></i></div>
        </div>
        <div class="kpi-card kpi-students">
            <div class="kpi-info"><h3><?php echo $countActive; ?></h3><p>Ativos</p></div>
            <div class="kpi-icon"><i class="fas fa-user-graduate"></i></div>
        </div>
        <div class="kpi-card kpi-late">
            <div class="kpi-info"><h3><?php echo $countLateTotal; ?></h3><p>Atrasados</p></div>
            <div class="kpi-icon"><i class="fas fa-exclamation-circle"></i></div>
        </div>
    </div>

    <h4 class="dash-section-title" style="margin-bottom: 15px;"><i class="fas fa-bolt"></i> Acesso Rápido</h4>
    <div class="quick-actions">
        <a href="enrollment_form.php" class="btn-quick"><i class="fas fa-user-plus" style="color: #27ae60;"></i> Matrícula</a>
        <a href="course_form.php" class="btn-quick"><i class="fas fa-book-open" style="color: #3498db;"></i> Curso</a>
        <a href="users.php?action=create" class="btn-quick"><i class="fas fa-user" style="color: #8e44ad;"></i> Aluno</a>
        <a href="school_profile.php" class="btn-quick"><i class="fas fa-school" style="color: #e67e22;"></i> Escola</a>
    </div>

    <div class="table-container" style="border-left: 5px solid #e74c3c;">
        <div class="late-header">
            <h4 class="dash-section-title" style="margin:0; color: #c0392b; font-size: 1.1rem;">
                <i class="fas fa-exclamation-triangle"></i> Inadimplência: <?php echo $mesesPT[$filterMonth] . ' / ' . $filterYear; ?>
            </h4>
            
            <form method="GET" class="filter-late-form">
                <select name="late_month" class="clean-select" onchange="this.form.submit()">
                    <?php foreach($mesesPT as $num => $nome): ?>
                        <option value="<?php echo $num; ?>" <?php echo ($num == $filterMonth) ? 'selected' : ''; ?>><?php echo $nome; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="late_year" class="clean-select" onchange="this.form.submit()">
                    <?php for($y = 2024; $y <= 2030; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y == $filterYear) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <select name="late_course" class="clean-select" onchange="this.form.submit()">
                    <option value="0">Todos os Cursos</option>
                    <?php foreach($coursesList as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $filterCourse) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <form method="POST" id="formBulkLate" style="margin-top: 15px;">
            <input type="hidden" name="action" value="bulk_pay_late">
            <?php if(count($latePayments) > 0): ?>
                <div class="table-responsive">
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th width="30"><input type="checkbox" id="selectAllLate"></th>
                                <th>Vencimento</th>
                                <th>Aluno</th>
                                <th>Curso</th>
                                <th>Valor</th>
                                <th style="text-align:right;">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($latePayments as $lp): ?>
                            <tr>
                                <td><input type="checkbox" name="payment_ids[]" value="<?php echo $lp['id']; ?>" class="late-check"></td>
                                <td><span class="badge-late"><?php echo date('d/m/Y', strtotime($lp['dueDate'])); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($lp['firstName'] . ' ' . $lp['lastName']); ?></strong></td>
                                <td><?php echo htmlspecialchars($lp['courseName']); ?></td>
                                <td>R$ <?php echo number_format($lp['amount'], 2, ',', '.'); ?></td>
                                <td style="text-align:right;">
                                    <button type="button" class="btn-xs btn-approve" onclick="baixaUnica(<?php echo $lp['id']; ?>)" title="Dar Baixa"><i class="fas fa-check"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="bulkActionsLate" class="bulk-bar">
                    <span><b id="selectedCountLate">0</b> selecionados</span>
                    <button type="button" class="btn-bulk-late" onclick="confirmBulkAction()">
                        <i class="fas fa-check-double"></i> Baixar
                    </button>
                </div>
            <?php else: ?>
                <div style="text-align:center; padding: 20px; color:#999;">
                    <i class="fas fa-thumbs-up" style="color:#27ae60; font-size: 1.5rem; margin-bottom:5px;"></i><br>Nenhum atrasado!
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="dashboard-split">
        <div class="table-container">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h4 class="dash-section-title" style="margin:0; font-size:1.1rem;"><i class="fas fa-tasks"></i> Pendentes Recentes</h4>
                <a href="enrollments.php?status=Pendente" style="font-size:0.85rem; color:#3498db; text-decoration:none;">Ver todas &rarr;</a>
            </div>
            
            <?php if(count($listPending) > 0): ?>
                <div class="table-responsive">
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>Aluno</th>
                                <th>Curso</th>
                                <th>Data</th>
                                <th style="text-align:right;">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($listPending as $p): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($p['firstName'] . ' ' . $p['lastName']); ?></strong></td>
                                <td><?php echo htmlspecialchars($p['courseName']); ?></td>
                                <td><?php echo date('d/m', strtotime($p['enrollmentDate'])); ?></td>
                                <td style="text-align:right;">
                                    <button type="button" class="btn-xs btn-approve" onclick="openApproveModal(<?php echo $p['studentId']; ?>, <?php echo $p['courseId']; ?>, '<?php echo htmlspecialchars($p['firstName'] . ' ' . $p['lastName']); ?>')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align:center; padding: 30px; color:#999;">
                    <i class="fas fa-check-circle" style="font-size: 2rem; color:#ddefd0; margin-bottom:10px;"></i><br>Tudo em dia!
                </div>
            <?php endif; ?>
        </div>

        <div class="table-container" style="background: #f8f9fa; border: 1px solid #e9ecef;">
            <h4 class="dash-section-title" style="margin-top:0;"><i class="fas fa-lightbulb" style="color:#f39c12;"></i> Dica</h4>
            <p style="font-size: 0.9rem; color: #666; line-height: 1.6;">Mantenha as matrículas em dia para que o financeiro seja gerado corretamente.</p>
            <p style="font-size: 0.9rem; color: #666; line-height: 1.6;">Alunos com status <strong>"Pendente"</strong> não geram cobranças automáticas.</p>
            <hr style="border:0; border-top:1px solid #e0e0e0; margin:15px 0;">
            <div style="text-align: center;"><a href="enrollments.php" style="font-weight:600; font-size:0.9rem; color:#555; text-decoration:none;">Gerenciar Matrículas</a></div>
        </div>
    </div>
</div>

<div id="confirmModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card" style="width: 90%; max-width: 400px; margin: auto;">
        <div class="modal-icon-wrapper">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h3 class="modal-h3">Confirmar Baixa?</h3>
        <p class="modal-p">
            Você está prestes a marcar <strong id="modalCount" style="color: #2c3e50;">0</strong> pagamento(s) como <strong>PAGO</strong>.
        </p>
        <div class="modal-check-wrapper">
            <div style="display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" id="checkConfirm" class="modal-checkbox">
                <label for="checkConfirm" class="modal-label">Estou ciente e desejo continuar.</label>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeConfirmModal()">Cancelar</button>
            <button type="button" class="btn-modal-confirm" id="btnRealConfirm" disabled onclick="submitBulkForm()">Confirmar Baixa</button>
        </div>
    </div>
</div>

<div id="enrollModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card" style="width: 90%; max-width: 400px; margin: auto;">
        <div class="modal-icon-wrapper" style="background: #e8f5e9; color: #27ae60;">
            <i class="fas fa-user-check"></i>
        </div>
        <h3 class="modal-h3">Aprovar Matrícula</h3>
        <p class="modal-p">
            Confirma a aprovação do aluno <strong id="enrollStudentName" style="color: #2c3e50;"></strong>?
            <br><small style="color:#666;">Isso ativará o acesso e enviará um e-mail de confirmação.</small>
        </p>

        <form action="enrollment_approve.php" method="POST" id="formApproveEnroll">
            <input type="hidden" name="redirect" value="index.php">
            <input type="hidden" name="student_id" id="enrollStudentId">
            <input type="hidden" name="course_id" id="enrollCourseId">
            
            <div class="modal-check-wrapper">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" id="checkEnroll" class="modal-checkbox">
                    <label for="checkEnroll" class="modal-label">Confirmar Matrícula</label>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeEnrollModal()">Cancelar</button>
                <button type="submit" class="btn-modal-confirm" id="btnEnrollConfirm" disabled style="background-color: #27ae60; opacity: 0.6; cursor: not-allowed;">
                    Confirmar Aprovação
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // JS: Seleção em Massa
    const selectAllLate = document.getElementById('selectAllLate');
    const checkboxesLate = document.querySelectorAll('.late-check');
    const bulkBarLate = document.getElementById('bulkActionsLate');
    const countSpanLate = document.getElementById('selectedCountLate');

    if(selectAllLate) { selectAllLate.addEventListener('change', function() { checkboxesLate.forEach(cb => cb.checked = this.checked); updateBulkBarLate(); }); }
    if(checkboxesLate) { checkboxesLate.forEach(cb => cb.addEventListener('change', updateBulkBarLate)); }

    function updateBulkBarLate() {
        const checked = document.querySelectorAll('.late-check:checked');
        if(countSpanLate) countSpanLate.innerText = checked.length;
        if(bulkBarLate) checked.length > 0 ? bulkBarLate.classList.add('visible') : bulkBarLate.classList.remove('visible');
    }

    // JS: Modal de Baixa
    const confirmModal = document.getElementById('confirmModal');
    const checkConfirm = document.getElementById('checkConfirm');
    const btnRealConfirm = document.getElementById('btnRealConfirm');
    let formToSubmit = null;

    if(checkConfirm) {
        checkConfirm.addEventListener('change', function() {
            btnRealConfirm.disabled = !this.checked;
            if(this.checked) {
                btnRealConfirm.classList.add('active');
                btnRealConfirm.style.cursor = 'pointer';
            } else {
                btnRealConfirm.classList.remove('active');
                btnRealConfirm.style.cursor = 'not-allowed';
            }
        });
    }

    function confirmBulkAction() {
        const checkedCount = document.querySelectorAll('.late-check:checked').length;
        if(checkedCount === 0) return;
        document.getElementById('modalCount').innerText = checkedCount;
        formToSubmit = document.getElementById('formBulkLate'); 
        checkConfirm.checked = false;
        btnRealConfirm.disabled = true;
        btnRealConfirm.classList.remove('active');
        btnRealConfirm.style.cursor = 'not-allowed';
        confirmModal.style.display = 'flex';
    }

    function baixaUnica(id) {
        document.getElementById('modalCount').innerText = "1";
        checkConfirm.checked = false;
        btnRealConfirm.disabled = true;
        btnRealConfirm.classList.remove('active');
        btnRealConfirm.style.cursor = 'not-allowed';
        btnRealConfirm.onclick = function() {
            let form = document.createElement('form'); form.method = 'POST';
            let inputAction = document.createElement('input'); inputAction.type = 'hidden'; inputAction.name = 'action'; inputAction.value = 'bulk_pay_late';
            let inputId = document.createElement('input'); inputId.type = 'hidden'; inputId.name = 'payment_ids[]'; inputId.value = id;
            form.appendChild(inputAction); form.appendChild(inputId); document.body.appendChild(form); form.submit();
        };
        confirmModal.style.display = 'flex';
    }

    function closeConfirmModal() { confirmModal.style.display = 'none'; }
    function submitBulkForm() { if(formToSubmit) formToSubmit.submit(); }

    // --- JS: MODAL DE APROVAÇÃO ---
    const enrollModal = document.getElementById('enrollModal');
    const checkEnroll = document.getElementById('checkEnroll');
    const btnEnrollConfirm = document.getElementById('btnEnrollConfirm');

    if(checkEnroll) {
        checkEnroll.addEventListener('change', function() {
            btnEnrollConfirm.disabled = !this.checked;
            if (this.checked) {
                btnEnrollConfirm.style.opacity = '1';
                btnEnrollConfirm.style.cursor = 'pointer';
            } else {
                btnEnrollConfirm.style.opacity = '0.6';
                btnEnrollConfirm.style.cursor = 'not-allowed';
            }
        });
    }

    function openApproveModal(sid, cid, name) {
        document.getElementById('enrollStudentId').value = sid;
        document.getElementById('enrollCourseId').value = cid;
        document.getElementById('enrollStudentName').innerText = name;
        checkEnroll.checked = false;
        btnEnrollConfirm.disabled = true;
        btnEnrollConfirm.style.opacity = '0.6';
        btnEnrollConfirm.style.cursor = 'not-allowed';
        enrollModal.style.display = 'flex';
    }

    function closeEnrollModal() { enrollModal.style.display = 'none'; }

    window.onclick = function(event) { 
        if (event.target == confirmModal) closeConfirmModal();
        if (event.target == enrollModal) closeEnrollModal();
    }
</script>

<?php include '../includes/admin_footer.php'; ?>