<?php
// admin/courses.php
$pageTitle = "Gestão de Cursos";
include '../includes/admin_header.php';

checkRole(['admin', 'superadmin']);

// --- LÓGICA DE GERAÇÃO EM MASSA (CERTIFICADOS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_cert_generate') {
    $cId = (int)$_POST['course_id'];
    $count = 0;

    try {
        // 1. Busca alunos elegíveis (Status: Aprovada, Concluido, Ativo)
        // Ignora 'Pendente' e 'Cancelado'
        $stmtEligible = $pdo->prepare("SELECT studentId FROM enrollments WHERE courseId = ? AND status IN ('Aprovada', 'Concluido', 'Ativo')");
        $stmtEligible->execute([$cId]);
        $students = $stmtEligible->fetchAll(PDO::FETCH_COLUMN);

        foreach ($students as $sid) {
            // 2. Verifica se JÁ existe certificado para este aluno neste curso
            $check = $pdo->prepare("SELECT id FROM certificates WHERE student_id = ? AND course_id = ?");
            $check->execute([$sid, $cId]);
            
            if ($check->rowCount() == 0) {
                // 3. Gera novo certificado se não existir
                $hash = hash('sha256', uniqid($sid . $cId . microtime(), true));
                
                // Usa 'generated_at' conforme seu padrão
                $ins = $pdo->prepare("INSERT INTO certificates (student_id, course_id, verification_hash, completion_date, generated_at) VALUES (?, ?, ?, NOW(), NOW())");
                $ins->execute([$sid, $cId, $hash]);
                $count++;
            }
        }
        
        // Redireciona com feedback
        header("Location: courses.php?view=$cId&msg=cert_generated&count=$count");
        exit;

    } catch (Exception $e) {
        $msgType = 'error'; // Tratado no bloco HTML abaixo
    }
}

// ==========================================
// === NOVA LÓGICA: VISÃO GERAL DO CURSO ===
// ==========================================
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;

if ($viewId > 0):
    // Busca dados do curso
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$viewId]);
    $course = $stmt->fetch();

    if (!$course) { echo "<script>window.location='courses.php';</script>"; exit; }

    // Busca Professores
    $stmtT = $pdo->prepare("SELECT ct.*, u.firstName, u.lastName FROM course_teachers ct JOIN users u ON ct.teacherId = u.id WHERE ct.courseId = ?");
    $stmtT->execute([$viewId]);
    $teachers = $stmtT->fetchAll();

    // Busca Alunos e verifica se já tem certificado
    $sqlS = "SELECT e.status as enrollStatus, u.id as studentId, u.firstName, u.lastName, u.cpf,
             (SELECT id FROM certificates WHERE student_id = u.id AND course_id = e.courseId LIMIT 1) as has_cert
             FROM enrollments e 
             JOIN users u ON e.studentId = u.id 
             WHERE e.courseId = ? 
             ORDER BY u.firstName ASC";
    $stmtS = $pdo->prepare($sqlS);
    $stmtS->execute([$viewId]);
    $students = $stmtS->fetchAll();
?>

<div class="content-wrapper">
    
    <?php if(isset($_GET['msg']) && $_GET['msg'] == 'cert_generated'): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; padding: 15px; border-radius: 8px; background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <i class="fas fa-check-circle"></i> Processo concluído! 
                <strong><?php echo (int)$_GET['count']; ?></strong> novos certificados foram gerados.
            </div>
            <span style="cursor:pointer; font-weight:bold;" onclick="this.parentElement.remove();">&times;</span>
        </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 style="margin: 0;"><i class="fas fa-book-open"></i> Detalhes: <?php echo htmlspecialchars($course['name']); ?></h3>
        <div style="display: flex; gap: 10px;">
            <button onclick="openBulkCertModal()" class="btn-save" style="background:#f39c12; color:white; border:none; cursor:pointer; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-certificate"></i> Gerar Certificados
            </button>

            <a href="course_form.php?id=<?php echo $viewId; ?>" class="btn-save" style="background:#3498db; text-decoration:none;">Editar</a>
            <a href="courses.php" class="btn-save" style="background:#95a5a6; text-decoration:none;">Voltar</a>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
        <div class="card-box">
            <div style="height: 200px; overflow: hidden; border-radius: 8px; margin-bottom: 15px; background: #eee;">
                <?php if($course['thumbnail']): ?>
                    <img src="<?php echo $course['thumbnail']; ?>" style="width:100%; height:100%; object-fit:cover;">
                <?php else: ?>
                    <div style="display:flex; align-items:center; justify-content:center; height:100%; color:#ccc;"><i class="fas fa-image fa-3x"></i></div>
                <?php endif; ?>
            </div>
            <p><strong>Status:</strong> <?php echo $course['status']; ?></p>
            <p><strong>Mensalidade:</strong> R$ <?php echo number_format($course['monthlyFee'], 2, ',', '.'); ?></p>
            <p><strong>Carga Horária:</strong> <?php echo $course['carga_horaria'] ?: '-'; ?></p>
            <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">
            <h4>Professores</h4>
            <?php foreach($teachers as $t): ?>
                <div style="font-size:0.9rem; margin-bottom:5px;">• <?php echo $t['firstName'].' '.$t['lastName']; ?> (<?php echo $t['commissionRate']; ?>%)</div>
            <?php endforeach; ?>
        </div>

        <div class="card-box" style="padding:0; overflow:hidden;">
            <div style="padding:20px;">
                <h4 style="margin:0;"><i class="fas fa-users"></i> Alunos Matriculados (<?php echo count($students); ?>)</h4>
            </div>
            <table class="custom-table" style="width:100%">
                <thead>
                    <tr>
                        <th style="padding-left:20px;">Nome</th>
                        <th>CPF</th>
                        <th>Status</th>
                        <th>Certificado</th>
                        <th style="text-align:right; padding-right:20px;">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($students as $s): ?>
                    <tr>
                        <td style="padding-left:20px;"><strong><?php echo $s['firstName'].' '.$s['lastName']; ?></strong></td>
                        <td><?php echo $s['cpf']; ?></td>
                        <td>
                            <?php 
                                $color = ($s['enrollStatus'] == 'Aprovada' || $s['enrollStatus'] == 'Concluido') ? '#27ae60' : '#f39c12';
                                echo "<span style='color:$color; font-weight:bold;'>{$s['enrollStatus']}</span>";
                            ?>
                        </td>
                        <td>
                            <?php if($s['has_cert']): ?>
                                <span style="color:#27ae60; font-size:0.9rem;"><i class="fas fa-check"></i> Emitido</span>
                            <?php else: ?>
                                <span style="color:#ccc;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; padding-right:20px;">
                            <a href="enrollment_form.php?sid=<?php echo $s['studentId']; ?>&cid=<?php echo $viewId; ?>" title="Ver Matrícula/Gerar"><i class="fas fa-external-link-alt"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($students)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:20px; color:#999;">Nenhum aluno matriculado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="bulkCertModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 450px; text-align: center;">
        <div style="margin-bottom: 15px;">
            <i class="fas fa-award" style="font-size: 3rem; color: #f39c12;"></i>
        </div>
        <h3 style="margin-bottom: 10px; color: #333;">Gerar Certificados em Massa?</h3>
        <p style="color: #666; margin-bottom: 20px; font-size: 0.95rem; line-height: 1.5;">
            Esta ação irá gerar certificados automaticamente para <strong>todos</strong> os alunos deste curso com matrícula <strong>Aprovada</strong> ou <strong>Concluída</strong>.
            <br><br>
            <small style="color: #888;">* Alunos que já possuem certificado serão ignorados para evitar duplicidade.</small>
        </p>
        
        <form method="POST">
            <input type="hidden" name="action" value="bulk_cert_generate">
            <input type="hidden" name="course_id" value="<?php echo $viewId; ?>">
            
            <div style="text-align: left; background: #fdf8e4; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fae5b0;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" id="checkBulkCert" style="width: 20px; height: 20px; cursor: pointer;">
                    <label for="checkBulkCert" style="font-size: 0.9rem; color: #8a6d3b; cursor: pointer; user-select: none; font-weight: 600;">
                        Estou ciente e desejo gerar os certificados.
                    </label>
                </div>
            </div>

            <div class="modal-actions" style="justify-content: center;">
                <button type="button" class="btn-save" style="background: #95a5a6;" onclick="document.getElementById('bulkCertModal').style.display='none'">Cancelar</button>
                
                <button type="submit" id="btnConfirmBulkCert" class="btn-save btn-disabled" style="background: #f39c12; opacity: 0.6; pointer-events: none; cursor: not-allowed;">
                    Confirmar Geração
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openBulkCertModal() {
        document.getElementById('bulkCertModal').style.display = 'flex';
        // Reset do estado
        document.getElementById('checkBulkCert').checked = false;
        const btn = document.getElementById('btnConfirmBulkCert');
        btn.style.opacity = '0.6';
        btn.style.pointerEvents = 'none';
        btn.style.cursor = 'not-allowed';
        btn.classList.add('btn-disabled');
    }

    document.getElementById('checkBulkCert').addEventListener('change', function() {
        const btn = document.getElementById('btnConfirmBulkCert');
        if(this.checked) {
            // Ativa
            btn.style.opacity = '1';
            btn.style.pointerEvents = 'auto';
            btn.style.cursor = 'pointer';
            btn.classList.remove('btn-disabled');
        } else {
            // Desativa
            btn.style.opacity = '0.6';
            btn.style.pointerEvents = 'none';
            btn.style.cursor = 'not-allowed';
            btn.classList.add('btn-disabled');
        }
    });
</script>

<?php else: 
// ==========================================
// === MODO LISTAGEM (CÓDIGO ORIGINAL) ===
// ==========================================

$msg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') {
        $msg = '<div class="alert alert-success">Curso excluído com sucesso! <span class="alert-close" onclick="this.parentElement.remove();">&times;</span></div>';
    } elseif ($_GET['msg'] == 'error') {
        $msg = '<div class="alert alert-danger">Erro ao processar a solicitação. <span class="alert-close" onclick="this.parentElement.remove();">&times;</span></div>';
    }
}

$search = isset($_GET['search']) ? cleanInput($_GET['search']) : '';
$filterTeacher = isset($_GET['teacher']) ? (int)$_GET['teacher'] : 0;
$where = "WHERE 1=1";
$params = [];

if ($search) { $where .= " AND (c.name LIKE :search OR c.description LIKE :search)"; $params[':search'] = "%$search%"; }
if ($filterTeacher) { $where .= " AND ct.teacherId = :tid"; $params[':tid'] = $filterTeacher; }

try {
    $stmtProf = $pdo->query("SELECT id, firstName, lastName FROM users WHERE role IN ('teacher', 'admin', 'superadmin') ORDER BY firstName ASC");
    $allTeachers = $stmtProf->fetchAll();

    $sql = "SELECT c.*, GROUP_CONCAT(TRIM(CONCAT(u.firstName, ' ', COALESCE(u.lastName, ''))) ORDER BY ct.id ASC SEPARATOR '|||') as teachers_list
            FROM courses c 
            LEFT JOIN course_teachers ct ON c.id = ct.courseId
            LEFT JOIN users u ON ct.teacherId = u.id
            $where GROUP BY c.id ORDER BY c.id DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $courses = $stmt->fetchAll();
} catch (PDOException $e) {
    $courses = [];
}
?>

<div class="content-wrapper">
    <?php echo $msg; ?>
    <div class="filters-bar">
        <div class="filter-group" style="flex-grow: 1;">
            <h3 style="margin: 0; margin-right: 15px;">Cursos (<?php echo count($courses); ?>)</h3>
            <a href="course_form.php" class="btn-save" style="padding: 8px 15px; font-size: 0.9rem;">+ Novo</a>
        </div>
        <form method="GET" action="" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <select name="teacher" class="form-control" style="width: auto; padding: 8px;" onchange="this.form.submit()">
                <option value="0">Todos os Professores</option>
                <?php foreach ($allTeachers as $t): ?>
                    <option value="<?php echo $t['id']; ?>" <?php echo $filterTeacher == $t['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['firstName'] . ' ' . $t['lastName']); ?></option>
                <?php endforeach; ?>
            </select>
            <div style="position: relative;">
                <input type="text" name="search" class="form-control" placeholder="Buscar curso..." value="<?php echo htmlspecialchars($search); ?>" style="padding: 8px 30px 8px 10px; width: 200px;">
                <button type="submit" style="position: absolute; right: 8px; top: 8px; background: none; border: none; cursor: pointer; color: #7f8c8d;"><i class="fas fa-search"></i></button>
            </div>
            <?php if ($search || $filterTeacher): ?><a href="courses.php" style="padding: 8px; color: #e74c3c; text-decoration: none;" title="Limpar Filtros"><i class="fas fa-times"></i></a><?php endif; ?>
        </form>
        <div class="view-toggles">
            <button class="view-btn active" id="btnGrid" onclick="switchView('grid')" title="Cards"><i class="fas fa-th-large"></i></button>
            <button class="view-btn" id="btnList" onclick="switchView('list')" title="Lista"><i class="fas fa-list"></i></button>
        </div>
    </div>

    <?php if (count($courses) == 0): ?>
        <div class="card-box" style="text-align: center; padding: 40px;"><p style="color: #999;">Nenhum curso encontrado.</p></div>
    <?php else: ?>
        <div id="viewGrid" class="stats-grid" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));">
            <?php foreach ($courses as $c): 
                $profName = '<span style="color:orange">Sem professor</span>';
                if (!empty($c['teachers_list'])) {
                    $profsArray = explode('|||', $c['teachers_list']);
                    $safeProfs = array_map('htmlspecialchars', $profsArray);
                    $profName = implode(', ', $safeProfs);
                }
            ?>
                <div class="card-box" style="padding: 0; overflow: hidden; display: flex; flex-direction: column; cursor:pointer;" onclick="window.location='?view=<?php echo $c['id']; ?>'">
                    <div style="height: 150px; overflow: hidden; background: #eee; position: relative;">
                        <?php if (!empty($c['thumbnail'])): ?><img src="<?php echo $c['thumbnail']; ?>" style="width: 100%; height: 100%; object-fit: cover;"><?php else: ?><div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #aaa;"><i class="fas fa-image fa-3x"></i></div><?php endif; ?>
                        <span style="position: absolute; top: 10px; right: 10px; padding: 5px 10px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; color: white; background: <?php echo ($c['status'] == 'Aberto' || $c['status'] == 'active') ? '#27ae60' : '#e74c3c'; ?>;"><?php echo ucfirst($c['status']); ?></span>
                    </div>
                    <div style="padding: 20px; flex-grow: 1; display: flex; flex-direction: column;">
                        <h4 style="margin: 0 0 10px 0; color: #2c3e50;"><?php echo htmlspecialchars($c['name']); ?></h4>
                        <div style="font-size: 0.85rem; color: #555; margin-bottom: 15px;">
                            <div style="margin-bottom: 5px; line-height: 1.4;"><i class="fas fa-chalkboard-teacher"></i> <strong>Prof(s):</strong> <?php echo $profName; ?></div>
                            <div style="margin-top: 5px;"><i class="fas fa-tag"></i> <strong>Valor:</strong> R$ <?php echo number_format($c['monthlyFee'], 2, ',', '.'); ?></div>
                        </div>
                        <div style="display: flex; gap: 10px; border-top: 1px solid #eee; padding-top: 15px;">
                            <a href="course_form.php?id=<?php echo $c['id']; ?>" onclick="event.stopPropagation();" style="flex: 1; text-align: center; color: #3498db; text-decoration: none; font-weight: bold; padding: 5px; border: 1px solid #3498db; border-radius: 4px; transition: 0.3s;">Editar</a>
                            <button type="button" style="flex: 1; text-align: center; color: #e74c3c; background:white; cursor:pointer; font-weight: bold; padding: 5px; border: 1px solid #e74c3c; border-radius: 4px; transition: 0.3s;" onclick="event.stopPropagation(); openDeleteModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')">Excluir</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div id="viewList" class="courses-list-view" style="display:none;">
            <table class="custom-table">
                <thead><tr><th width="60">Img</th><th>Curso</th><th>Professores</th><th>Carga</th><th>Valor</th><th>Vagas</th><th>Status</th><th style="text-align: right;">Ações</th></tr></thead>
                <tbody>
                    <?php foreach ($courses as $c): 
                        $profName = '<span style="color:orange">Sem professor</span>';
                        if (!empty($c['teachers_list'])) { $profsArray = explode('|||', $c['teachers_list']); $safeProfs = array_map('htmlspecialchars', $profsArray); $profName = implode(', ', $safeProfs); }
                    ?>
                    <tr>
                        <td><?php if (!empty($c['thumbnail'])): ?><img src="<?php echo $c['thumbnail']; ?>" class="table-thumb"><?php else: ?><div class="table-thumb" style="display: flex; align-items: center; justify-content: center; color: #ccc;"><i class="fas fa-image"></i></div><?php endif; ?></td>
                        <td><a href="?view=<?php echo $c['id']; ?>" style="color:inherit; text-decoration:none;"><strong><?php echo htmlspecialchars($c['name']); ?></strong></a></td>
                        <td style="max-width: 250px; font-size: 0.9rem;"><?php echo $profName; ?></td>
                        <td><?php echo htmlspecialchars($c['carga_horaria'] ?? '-'); ?></td>
                        <td style="color: #27ae60; font-weight: bold;">R$ <?php echo number_format($c['monthlyFee'], 2, ',', '.'); ?></td>
                        <td><?php echo $c['totalSlots'] ? $c['totalSlots'] : '∞'; ?></td>
                        <td><span style="padding: 3px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: bold; color: white; background: <?php echo ($c['status'] == 'Aberto' || $c['status'] == 'active') ? '#27ae60' : '#e74c3c'; ?>;"><?php echo ucfirst($c['status']); ?></span></td>
                        <td style="text-align: right; white-space: nowrap;">
                            <a href="?view=<?php echo $c['id']; ?>" style="color: #2c3e50; font-size: 1.1rem; text-decoration:none; margin-right:10px;" title="Ver Detalhes"><i class="fas fa-eye"></i></a>
                            <a href="course_form.php?id=<?php echo $c['id']; ?>" style="color: #3498db; font-size: 1.1rem; text-decoration:none;" title="Editar"><i class="fas fa-edit"></i></a>
                            <a href="#" style="color: #e74c3c; font-size: 1.1rem; text-decoration:none;" class="action-gap" title="Excluir" onclick="openDeleteModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>')"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div id="deleteModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="modal-title"><i class="fas fa-exclamation-triangle"></i> Atenção</div>
        <p>Você está prestes a excluir o curso:</p>
        <h4 id="deleteCourseName" style="color: #333;"></h4>
        <p style="font-size: 0.9rem; color: #666; margin-top: 10px;">Isso apagará permanentemente o curso, matrículas e histórico financeiro associado. Essa ação é irreversível.</p>
        <div style="margin-top: 15px; text-align: left; background: #f9f9f9; padding: 10px; border-radius: 4px;">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" id="confirmCheck" onchange="toggleDeleteButton()" style="margin-right: 10px; width: 18px; height: 18px;">
                <span style="font-size: 0.9rem;">Estou ciente e quero excluir.</span>
            </label>
        </div>
        <div class="modal-actions">
            <button class="btn-save" style="background: #95a5a6;" onclick="closeDeleteModal()">Cancelar</button>
            <a href="#" id="deleteLink" class="btn-save btn-disabled" style="background: #e74c3c;">Confirmar Exclusão</a>
        </div>
    </div>
</div>

<script>
    function switchView(viewType) {
        const grid = document.getElementById('viewGrid'); const list = document.getElementById('viewList');
        const btnGrid = document.getElementById('btnGrid'); const btnList = document.getElementById('btnList');
        if (!grid || !list) return;
        if (viewType === 'list') {
            grid.style.display = 'none'; list.style.display = 'block';
            btnGrid.classList.remove('active'); btnList.classList.add('active');
            localStorage.setItem('coursesViewMode', 'list');
        } else {
            grid.style.display = 'grid'; list.style.display = 'none';
            btnList.classList.remove('active'); btnGrid.classList.add('active');
            localStorage.setItem('coursesViewMode', 'grid');
        }
    }
    document.addEventListener('DOMContentLoaded', () => {
        const savedView = localStorage.getItem('coursesViewMode');
        if (savedView === 'list') switchView('list'); else switchView('grid');
    });

    function openDeleteModal(id, name) {
        const modal = document.getElementById('deleteModal');
        const nameSpan = document.getElementById('deleteCourseName');
        const btnDelete = document.getElementById('deleteLink');
        const checkbox = document.getElementById('confirmCheck');
        nameSpan.innerText = name;
        btnDelete.href = "course_delete.php?id=" + id;
        checkbox.checked = false;
        btnDelete.classList.add('btn-disabled'); btnDelete.style.pointerEvents = 'none'; btnDelete.style.opacity = '0.6';
        modal.style.display = 'flex';
    }
    function closeDeleteModal() { document.getElementById('deleteModal').style.display = 'none'; }
    function toggleDeleteButton() {
        const checkbox = document.getElementById('confirmCheck');
        const btnDelete = document.getElementById('deleteLink');
        if (checkbox.checked) { btnDelete.classList.remove('btn-disabled'); btnDelete.style.pointerEvents = 'auto'; btnDelete.style.opacity = '1';
        } else { btnDelete.classList.add('btn-disabled'); btnDelete.style.pointerEvents = 'none'; btnDelete.style.opacity = '0.6'; }
    }
    window.onclick = function(event) {
        const modal = document.getElementById('deleteModal');
        const bulkModal = document.getElementById('bulkCertModal');
        if (event.target == modal) closeDeleteModal();
        if (bulkModal && event.target == bulkModal) bulkModal.style.display = 'none';
    }
</script>

<?php include '../includes/admin_footer.php'; ?>