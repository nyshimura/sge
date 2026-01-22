<?php
// admin/enrollments.php
ob_start();
session_start();
require_once '../config/database.php';
require_once '../includes/enrollment_logic.php'; // Usa a lógica central

ini_set('display_errors', 1); error_reporting(E_ALL);

// Configs básicas
$defaultDueDay = 10;
$fineMonths = 0;
try {
    $stmtConfig = $pdo->query("SELECT defaultDueDay, terminationFineMonths FROM system_settings WHERE id = 1");
    $sysConfig = $stmtConfig->fetch();
    if ($sysConfig) {
        $defaultDueDay = $sysConfig['defaultDueDay'] ?? 10;
        $fineMonths = (int)($sysConfig['terminationFineMonths'] ?? 0);
    }
} catch (Exception $e) {}

// PROCESSAMENTO POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $sid = (int)$_POST['student_id'];
    $cid = (int)$_POST['course_id'];

    try {
        $pdo->beginTransaction();

        if ($_POST['action'] === 'cancel_enrollment') {
            $monthlyFee = (float)$_POST['monthly_fee'];
            cancelarMatricula($pdo, $sid, $cid, $monthlyFee);
            $msgType = 'canceled';
        }
        elseif ($_POST['action'] === 'approve_enrollment') {
            $stmtE = $pdo->prepare("SELECT customMonthlyFee, scholarshipPercentage, customDueDay FROM enrollments WHERE studentId=:s AND courseId=:c");
            $stmtE->execute([':s'=>$sid, ':c'=>$cid]);
            $enroll = $stmtE->fetch();

            if ($enroll) {
                $finalFee = 0;
                if (!empty($enroll['customMonthlyFee']) && $enroll['customMonthlyFee'] > 0) {
                    $finalFee = $enroll['customMonthlyFee'];
                } else {
                    $stmtC = $pdo->prepare("SELECT monthlyFee FROM courses WHERE id = :id");
                    $stmtC->execute([':id' => $cid]);
                    $baseFee = (float)$stmtC->fetchColumn();
                    $scholarship = (float)$enroll['scholarshipPercentage'];
                    $finalFee = max(0, $baseFee - ($baseFee * ($scholarship / 100)));
                }
                $dueDay = !empty($enroll['customDueDay']) ? $enroll['customDueDay'] : $defaultDueDay;
                
                // Reativa usando a data de hoje como base
                reativarMatricula($pdo, $sid, $cid, $finalFee, $dueDay, date('Y-m-d'));
            }
            $msgType = 'approved';
        }

        $pdo->commit();
        header("Location: enrollments.php?msg=$msgType");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: enrollments.php?msg=error&err=" . urlencode($e->getMessage()));
        exit;
    }
}

// VIEW
$pageTitle = "Gestão de Matrículas";
include '../includes/admin_header.php';
if (function_exists('checkRole')) checkRole(['admin', 'superadmin']);

// Mensagens
$msg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'approved') $msg = '<div class="alert alert-success">Matrícula <b>APROVADA/REATIVADA</b>! Financeiro ajustado. <span class="alert-close" onclick="this.parentElement.remove();">&times;</span></div>';
    if ($_GET['msg'] == 'error') $msg = '<div class="alert alert-danger">Erro: '.htmlspecialchars($_GET['err'] ?? '').' <span class="alert-close" onclick="this.parentElement.remove();">&times;</span></div>';
    if ($_GET['msg'] == 'canceled') $msg = '<div class="alert alert-warning">Matrícula <b>CANCELADA</b>. <span class="alert-close" onclick="this.parentElement.remove();">&times;</span></div>';
}

// Filtros
$filterCourse = isset($_GET['course']) ? (int)$_GET['course'] : 0;
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$where = "WHERE 1=1"; $params = [];
if ($filterCourse) { $where .= " AND e.courseId = :cid"; $params[':cid'] = $filterCourse; }
if ($filterStatus) { $where .= " AND e.status = :st"; $params[':st'] = $filterStatus; }
if ($search) { $where .= " AND (u.firstName LIKE :s OR u.lastName LIKE :s OR u.email LIKE :s)"; $params[':s'] = "%$search%"; }

$coursesList = $pdo->query("SELECT id, name FROM courses ORDER BY name ASC")->fetchAll();
$sql = "SELECT e.*, u.firstName, u.lastName, u.email, u.profilePicture, c.name as courseName, c.monthlyFee as baseFee 
        FROM enrollments e JOIN users u ON e.studentId = u.id JOIN courses c ON e.courseId = c.id $where ORDER BY e.enrollmentDate DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$enrollments = $stmt->fetchAll();
?>

<div class="content-wrapper">
    <?php echo $msg; ?>
    <div class="card-box" style="margin-bottom: 20px; padding: 15px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin: 0;">Matrículas <span class="badge-count"><?php echo count($enrollments); ?></span></h3>
            <a href="enrollment_form.php" class="btn-save btn-primary" style="padding: 8px 15px; font-size: 0.9rem;">
                <i class="fas fa-plus"></i> <span class="hide-mobile">Nova</span>
            </a>
        </div>
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <select name="course" class="form-control" style="min-width: 150px; flex: 1;" onchange="this.form.submit()">
                <option value="0">Todos os Cursos</option>
                <?php foreach ($coursesList as $c): echo "<option value='{$c['id']}' ".($filterCourse==$c['id']?'selected':'').">".htmlspecialchars($c['name'])."</option>"; endforeach; ?>
            </select>
            <select name="status" class="form-control" style="min-width: 120px; flex: 1;" onchange="this.form.submit()">
                <option value="">Status</option>
                <?php foreach(['Ativo','Aprovada','Pendente','Concluido','Cancelada'] as $s): echo "<option value='$s' ".($filterStatus==$s?'selected':'').">$s</option>"; endforeach; ?>
            </select>
            <div style="position: relative; flex: 2; min-width: 200px;">
                <input type="text" name="search" class="form-control" placeholder="Buscar..." value="<?php echo htmlspecialchars($search); ?>" style="width: 100%; padding-right: 35px;">
                <button type="submit" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none;"><i class="fas fa-search"></i></button>
            </div>
            <?php if ($search || $filterCourse || $filterStatus): ?><a href="enrollments.php" class="btn-clear" style="height:38px;width:38px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-times"></i></a><?php endif; ?>
        </form>
    </div>

    <div class="card-box" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="custom-table">
                <thead><tr><th>Aluno</th><th>Curso</th><th>Início</th><th>Financeiro</th><th>Status</th><th style="text-align: right;">Ações</th></tr></thead>
                <tbody>
                    <?php if (count($enrollments) == 0): ?><tr><td colspan="6" class="empty-state">Nenhuma matrícula.</td></tr><?php else: ?>
                    <?php foreach ($enrollments as $e): 
                        $rawStatus = $e['status'];
                        // Normalização visual do status (se estiver vazio vira Pendente)
                        if(empty($rawStatus) || $rawStatus == 'Pendente') { $rawStatus = 'Pendente'; $statusColor = '#f39c12'; }
                        elseif($rawStatus == 'Aprovada' || $rawStatus == 'Ativo') $statusColor = '#27ae60';
                        elseif($rawStatus == 'Cancelada' || $rawStatus == 'Cancelado') { $rawStatus = 'Cancelada'; $statusColor = '#e74c3c'; }
                        elseif($rawStatus == 'Concluido') $statusColor = '#3498db';
                        else $statusColor = '#95a5a6';
                        
                        $statusText = ucfirst($rawStatus);
                        
                        $baseFee = (float)$e['baseFee'];
                        $finalFee = (!empty($e['customMonthlyFee']) && $e['customMonthlyFee'] > 0) ? (float)$e['customMonthlyFee'] : max(0, $baseFee - ($baseFee * ($e['scholarshipPercentage'] / 100)));
                        $finInfo = "R$ " . number_format($finalFee, 2, ',', '.');
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($e['firstName'] . ' ' . $e['lastName']); ?></strong><br><small><?php echo htmlspecialchars($e['email']); ?></small></td>
                        <td><?php echo htmlspecialchars($e['courseName']); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($e['enrollmentDate'])); ?></td>
                        <td><?php echo $finInfo; ?></td>
                        <td><span class="badge" style="background-color: <?php echo $statusColor; ?>;"><?php echo $statusText; ?></span></td>
                        <td style="text-align: right;">
                            <div class="actions-cell" style="justify-content: flex-end;">
                                <?php if($rawStatus == 'Pendente' || $rawStatus == 'Cancelada'): ?>
                                    <button class="btn-action btn-edit" style="color:#27ae60; border-color:#27ae60;" onclick="openApproveModal(<?php echo $e['studentId']; ?>, <?php echo $e['courseId']; ?>, '<?php echo addslashes($e['firstName']); ?>')"><i class="fas fa-check"></i></button>
                                <?php endif; ?>
                                <a href="enrollment_form.php?sid=<?php echo $e['studentId']; ?>&cid=<?php echo $e['courseId']; ?>" class="btn-action btn-edit"><i class="fas fa-edit"></i></a>
                                <?php if($rawStatus != 'Cancelada'): ?>
                                <button class="btn-action btn-delete" onclick="openCancelModal(<?php echo $e['studentId']; ?>, <?php echo $e['courseId']; ?>, '<?php echo addslashes($e['firstName']); ?>', <?php echo $finalFee; ?>)"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="approveModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card">
        <div class="modal-icon-wrapper" style="background:#e8f5e9;color:#27ae60;"><i class="fas fa-check-circle"></i></div>
        <h3 class="modal-h3">Aprovar / Reativar</h3>
        <p class="modal-p">Reativar: <strong id="approveInfo"></strong>?</p>
        <p class="modal-p" style="font-size:0.8rem;">O sistema irá gerar cobranças pendentes até o final do ano, <b>respeitando os meses já pagos</b>.</p>
        <form method="POST"><input type="hidden" name="action" value="approve_enrollment"><input type="hidden" name="student_id" id="approveSid"><input type="hidden" name="course_id" id="approveCid">
            <div class="modal-actions"><button type="button" class="btn-modal-cancel" onclick="closeModals()">Voltar</button><button type="submit" class="btn-modal-confirm" style="background:#27ae60;">Confirmar</button></div>
        </form>
    </div>
</div>

<div id="cancelModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card" style="border-top:4px solid #c0392b;">
        <div class="modal-icon-wrapper" style="background-color:#fdedec;"><i class="fas fa-exclamation-triangle" style="color:#c0392b;"></i></div>
        <h3 class="modal-h3">Cancelar Matrícula?</h3>
        <p class="modal-p" id="cancelInfoText"></p>
        <form method="POST"><input type="hidden" name="action" value="cancel_enrollment"><input type="hidden" name="student_id" id="cancelSid"><input type="hidden" name="course_id" id="cancelCid"><input type="hidden" name="monthly_fee" id="cancelFee">
            <div class="modal-actions"><button type="button" class="btn-modal-cancel" onclick="closeModals()">Voltar</button><button type="submit" class="btn-modal-confirm active-danger">Cancelar e Multar</button></div>
        </form>
    </div>
</div>

<script>
    const fineMonthsConfig = <?php echo $fineMonths; ?>;
    function closeModals() { document.getElementById('cancelModal').style.display='none'; document.getElementById('approveModal').style.display='none'; }
    function openCancelModal(sid, cid, name, fee) {
        document.getElementById('cancelSid').value = sid; document.getElementById('cancelCid').value = cid; document.getElementById('cancelFee').value = fee;
        let msg = `Cancelar matrícula de <strong>${name}</strong>?`;
        if (fineMonthsConfig > 0 && fee > 0) {
            msg += `<br><br><div style="background:#fff5f5;padding:10px;border:1px solid #feb2b2;border-radius:5px;text-align:left;color:#c0392b;"><strong>⚠️ Multa Rescisória:</strong> ${(fee * fineMonthsConfig).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})} (${fineMonthsConfig} meses).</div>`;
        }
        document.getElementById('cancelInfoText').innerHTML = msg;
        document.getElementById('cancelModal').style.display = 'flex';
    }
    function openApproveModal(sid, cid, name) {
        document.getElementById('approveSid').value = sid; document.getElementById('approveCid').value = cid; document.getElementById('approveInfo').innerText = name;
        document.getElementById('approveModal').style.display = 'flex';
    }
    window.onclick = function(e) { if(e.target.className === 'modal-overlay') closeModals(); }
</script>

<style>
    .table-responsive { width: 100%; overflow-x: auto; }
    .custom-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 600px; }
    .custom-table th { background: #f8f9fa; padding: 12px; text-align: left; border-bottom: 2px solid #e9ecef; }
    .custom-table td { padding: 12px; border-bottom: 1px solid #e9ecef; vertical-align: middle; }

    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; justify-content: center; align-items: center; }
    .modal-card { background: white; padding: 25px; border-radius: 8px; width: 90%; max-width: 400px; text-align: center; box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
    .modal-icon-wrapper { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; font-size: 1.5rem; }
    .modal-actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
    .btn-modal-cancel { background: #e0e0e0; color: #333; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 600; }
    .btn-modal-confirm { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 600; }
    .active-danger { background: #c0392b !important; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; color: white; }
    .btn-clear { background: #f1f1f1; border: 1px solid #ddd; border-radius: 4px; color: #666; }
</style>
<?php include '../includes/admin_footer.php'; ?>