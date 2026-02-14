<?php
// admin/events_manager.php

// 1. Inicia buffer de saída (previne erro de tela branca em redirecionamentos)
ob_start();
if (session_status() == PHP_SESSION_NONE) session_start();

// 2. Inclui configurações essenciais ANTES de qualquer HTML
require_once '../config/database.php';
require_once '../includes/functions.php'; // Ajuste se suas funções de auth estiverem aqui

// 3. Verifica permissão
checkRole(['admin', 'superadmin']);

// =============================================================
// 4. LÓGICA DE POST (PROCESSAMENTO) - AGORA NO TOPO
// =============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $id = (int)$_POST['term_id'];
        
        // A. Alternar Status (Concluir/Reativar)
        if ($_POST['action'] === 'toggle_status') {
            $newStatus = $_POST['new_status']; // 'active' ou 'concluded'
            $stmt = $pdo->prepare("UPDATE event_terms SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            
            // Redireciona limpo
            header("Location: events_manager.php?msg=updated");
            exit;
        }
        
        // B. Excluir Evento
        if ($_POST['action'] === 'delete_event') {
            $stmt = $pdo->prepare("DELETE FROM event_terms WHERE id = ?");
            $stmt->execute([$id]);
            
            // Redireciona limpo
            header("Location: events_manager.php?msg=deleted");
            exit;
        }
    }
}
// =============================================================
// FIM DA LÓGICA - AGORA PODEMOS CARREGAR O HTML
// =============================================================

$pageTitle = "Gerenciador de Eventos";
include '../includes/admin_header.php';

// --- FILTROS (GET) ---
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'active'; // Padrão: Apenas ativos
$filterCourse = isset($_GET['course']) ? (int)$_GET['course'] : 0;
$search = isset($_GET['search']) ? cleanInput($_GET['search']) : '';

// --- CONSULTA ---
$where = "WHERE 1=1";
$params = [];

if ($filterStatus !== 'all') {
    $where .= " AND t.status = :st";
    $params[':st'] = $filterStatus;
}
if ($filterCourse > 0) {
    $where .= " AND t.courseId = :cid";
    $params[':cid'] = $filterCourse;
}
if (!empty($search)) {
    $where .= " AND (t.title LIKE :q OR c.name LIKE :q)";
    $params[':q'] = "%$search%";
}

// Query poderosa: Traz evento, nome do curso, e conta quantos aceitaram/recusaram
$sql = "
    SELECT t.*, c.name as courseName,
    (SELECT COUNT(*) FROM event_term_responses r WHERE r.term_id = t.id AND r.status = 'accepted') as accepted_count,
    (SELECT COUNT(*) FROM event_term_responses r WHERE r.term_id = t.id AND r.status = 'declined') as declined_count,
    (SELECT COUNT(*) FROM enrollments e WHERE e.courseId = t.courseId AND e.status IN ('Aprovada','Ativo')) as total_students
    FROM event_terms t
    JOIN courses c ON t.courseId = c.id
    $where
    ORDER BY t.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

// Lista de cursos para o filtro
$coursesList = $pdo->query("SELECT id, name FROM courses ORDER BY name ASC")->fetchAll();
?>

<style>
    .status-active { background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; }
    .status-concluded { background: #e2e3e5; color: #383d41; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: bold; }
    
    .progress-track { background: #eee; height: 8px; border-radius: 4px; width: 100px; overflow: hidden; display: inline-block; vertical-align: middle; }
    .progress-fill { height: 100%; background: #27ae60; }
    
    .stats-mini { font-size: 0.75rem; color: #666; margin-top: 3px; }
    
    .btn-icon { border: none; background: none; cursor: pointer; font-size: 1.1rem; padding: 5px; transition: 0.2s; }
    .btn-icon:hover { transform: scale(1.1); }
    .txt-purple { color: #8e44ad; }
    .txt-green { color: #27ae60; }
    .txt-red { color: #e74c3c; }
    .txt-gray { color: #7f8c8d; }
    
    /* Toast básico (se já não tiver no footer) */
    .toast-msg {
        position: fixed; bottom: 20px; right: 20px; background: #333; color: #fff; padding: 12px 20px; border-radius: 6px; z-index: 9999; animation: fadein 0.5s;
    }
</style>

<div class="content-wrapper">
    
    <?php if(isset($_GET['msg'])): ?>
        <div id="toastNotification" class="toast-msg">
            <?php 
                if($_GET['msg']=='updated') echo "<i class='fas fa-check'></i> Status atualizado com sucesso!";
                if($_GET['msg']=='deleted') echo "<i class='fas fa-trash'></i> Evento excluído.";
            ?>
        </div>
        <script>setTimeout(function(){ document.getElementById('toastNotification').style.display = 'none'; }, 3000);</script>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <h2 style="margin: 0;"><i class="fas fa-calendar-check" style="color: #8e44ad;"></i> Gerenciador de Eventos</h2>
    </div>

    <div class="card-box" style="padding: 15px; margin-bottom: 20px; background: #f8f9fa;">
        <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end;">
            
            <div style="flex: 1; min-width: 200px;">
                <label style="font-size: 0.85rem; font-weight: bold; color: #555;">Status</label>
                <select name="status" class="form-control" onchange="this.form.submit()">
                    <option value="active" <?php echo $filterStatus == 'active' ? 'selected' : ''; ?>>Apenas Ativos</option>
                    <option value="concluded" <?php echo $filterStatus == 'concluded' ? 'selected' : ''; ?>>Concluídos / Histórico</option>
                    <option value="all" <?php echo $filterStatus == 'all' ? 'selected' : ''; ?>>Todos</option>
                </select>
            </div>

            <div style="flex: 1; min-width: 200px;">
                <label style="font-size: 0.85rem; font-weight: bold; color: #555;">Filtrar por Curso</label>
                <select name="course" class="form-control" onchange="this.form.submit()">
                    <option value="0">Todos os Cursos</option>
                    <?php foreach($coursesList as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $filterCourse == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 2; min-width: 200px; display: flex; gap: 5px;">
                <input type="text" name="search" class="form-control" placeholder="Buscar evento..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="btn-primary"><i class="fas fa-search"></i></button>
                <?php if($filterStatus != 'active' || $filterCourse != 0 || !empty($search)): ?>
                    <a href="events_manager.php" class="btn-secondary" style="display:flex; align-items:center; text-decoration:none;"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-box" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="custom-table" style="width: 100%;">
                <thead style="background: #f1f2f6;">
                    <tr>
                        <th style="padding-left: 20px;">Evento / Turnê</th>
                        <th>Curso</th>
                        <th>Data Criação</th>
                        <th>Adesão (Assinaturas)</th>
                        <th>Status</th>
                        <th style="text-align: right; padding-right: 20px;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($events)): ?>
                        <tr><td colspan="6" style="text-align: center; padding: 40px; color: #999;">Nenhum evento encontrado com os filtros atuais.</td></tr>
                    <?php else: ?>
                        <?php foreach($events as $evt): 
                            // Cálculo de porcentagem
                            $total = $evt['total_students'] > 0 ? $evt['total_students'] : 1; 
                            $accepted = $evt['accepted_count'];
                            $percent = round(($accepted / $total) * 100);
                            
                            $barColor = '#27ae60'; 
                            if ($percent < 30) $barColor = '#e74c3c'; 
                            elseif ($percent < 70) $barColor = '#f39c12'; 
                        ?>
                        <tr>
                            <td style="padding-left: 20px;">
                                <strong style="color: #2c3e50; font-size: 1rem;"><?php echo htmlspecialchars($evt['title']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($evt['courseName']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($evt['created_at'])); ?></td>
                            <td>
                                <div class="progress-track">
                                    <div class="progress-fill" style="width: <?php echo $percent; ?>%; background: <?php echo $barColor; ?>;"></div>
                                </div>
                                <span style="font-weight: bold; font-size: 0.85rem; margin-left: 5px;"><?php echo $percent; ?>%</span>
                                <div class="stats-mini">
                                    <span class="txt-green"><i class="fas fa-check"></i> <?php echo $accepted; ?> Sim</span> | 
                                    <span class="txt-red"><i class="fas fa-times"></i> <?php echo $evt['declined_count']; ?> Não</span>
                                </div>
                            </td>
                            <td>
                                <?php if($evt['status'] == 'active'): ?>
                                    <span class="status-active">Ativo</span>
                                <?php else: ?>
                                    <span class="status-concluded"><i class="fas fa-archive"></i> Concluído</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; padding-right: 20px;">
                                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                                    
                                    <?php if($evt['status'] == 'active'): ?>
                                        <button type="button" class="btn-icon txt-gray" title="Concluir/Arquivar" onclick="openConfirmStatus(<?php echo $evt['id']; ?>, 'concluded')">
                                            <i class="fas fa-box-archive"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn-icon txt-green" title="Reativar" onclick="openConfirmStatus(<?php echo $evt['id']; ?>, 'active')">
                                            <i class="fas fa-box-open"></i>
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" class="btn-icon txt-red" title="Excluir Permanentemente" onclick="openDeleteEvent(<?php echo $evt['id']; ?>, '<?php echo htmlspecialchars(addslashes($evt['title'])); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="statusModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card">
        <div class="modal-icon-wrapper" style="background-color: #e3f2fd;"><i class="fas fa-exchange-alt" style="color: #3498db;"></i></div>
        <h3 class="modal-h3" id="statusModalTitle">Alterar Status</h3>
        <p class="modal-p" id="statusModalText">Texto</p>
        <form method="POST">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="term_id" id="statusTermId">
            <input type="hidden" name="new_status" id="statusNewValue">
            
            <div class="modal-check-wrapper">
                <input type="checkbox" id="checkStatusConfirm">
                <label for="checkStatusConfirm">Confirmar alteração.</label>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeStatusModal()">Cancelar</button>
                <button type="submit" class="btn-modal-confirm" id="btnStatusConfirm" disabled>Confirmar</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card" style="border-top: 4px solid #c0392b;">
        <div class="modal-icon-wrapper" style="background-color: #fdedec;"><i class="fas fa-exclamation-triangle" style="color: #c0392b;"></i></div>
        <h3 class="modal-h3">Excluir Evento?</h3>
        <p class="modal-p">Você está prestes a excluir: <strong id="delEventName"></strong>.<br><br>Isso apagará o termo e <strong>todas as assinaturas</strong> dos alunos. Essa ação é irreversível.</p>
        <form method="POST">
            <input type="hidden" name="action" value="delete_event">
            <input type="hidden" name="term_id" id="delTermId">
            
            <div class="modal-check-wrapper">
                <input type="checkbox" id="checkDelConfirm">
                <label for="checkDelConfirm">Estou ciente e quero excluir.</label>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeDeleteModal()">Cancelar</button>
                <button type="submit" class="btn-modal-confirm active-danger" id="btnDelConfirm" disabled>Excluir</button>
            </div>
        </form>
    </div>
</div>

<script>
    // --- Lógica Status ---
    const statusModal = document.getElementById('statusModal');
    const checkStatus = document.getElementById('checkStatusConfirm');
    const btnStatus = document.getElementById('btnStatusConfirm');

    function openConfirmStatus(id, newStatus) {
        document.getElementById('statusTermId').value = id;
        document.getElementById('statusNewValue').value = newStatus;
        
        checkStatus.checked = false;
        btnStatus.disabled = true;
        btnStatus.style.opacity = "0.6";

        if(newStatus === 'concluded') {
            document.getElementById('statusModalTitle').innerText = "Concluir Evento?";
            document.getElementById('statusModalText').innerHTML = "Ao concluir, este evento <strong>sumirá do painel do curso</strong>, ficando visível apenas aqui no histórico.<br>Ideal para eventos que já passaram.";
        } else {
            document.getElementById('statusModalTitle').innerText = "Reativar Evento?";
            document.getElementById('statusModalText').innerHTML = "O evento voltará a aparecer na lista ativa do curso.";
        }
        statusModal.style.display = 'flex';
    }
    
    function closeStatusModal() { statusModal.style.display = 'none'; }

    checkStatus.addEventListener('change', function() {
        btnStatus.disabled = !this.checked;
        btnStatus.style.opacity = this.checked ? "1" : "0.6";
    });

    // --- Lógica Delete ---
    const delModal = document.getElementById('deleteModal');
    const checkDel = document.getElementById('checkDelConfirm');
    const btnDel = document.getElementById('btnDelConfirm');

    function openDeleteEvent(id, title) {
        document.getElementById('delTermId').value = id;
        document.getElementById('delEventName').innerText = title;
        checkDel.checked = false;
        btnDel.disabled = true;
        btnDel.style.opacity = "0.6";
        delModal.style.display = 'flex';
    }

    function closeDeleteModal() { delModal.style.display = 'none'; }

    checkDel.addEventListener('change', function() {
        btnDel.disabled = !this.checked;
        btnDel.style.opacity = this.checked ? "1" : "0.6";
    });

    window.onclick = function(e) {
        if(e.target == statusModal) closeStatusModal();
        if(e.target == delModal) closeDeleteModal();
    }
</script>

<style>
    /* Estilos Modal e Botões (Reaproveitados do padrão) */
    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center; }
    .modal-card { background: white; padding: 25px; border-radius: 8px; width: 90%; max-width: 400px; text-align: center; position: relative;}
    .modal-actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
    .btn-modal-cancel { background: #e0e0e0; color: #333; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 600; }
    .btn-modal-confirm { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 600; }
    .modal-icon-wrapper { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px auto; font-size: 1.8rem; }
    .modal-check-wrapper { margin-top: 15px; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.9rem; color: #555; }
    .active-danger { background: #c0392b !important; }
</style>

<?php include '../includes/admin_footer.php'; ?>