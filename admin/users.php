<?php
// admin/users.php
$pageTitle = "Gestão de Usuários";
include '../includes/admin_header.php';

// Filtro de Busca
$search = isset($_GET['search']) ? cleanInput($_GET['search']) : '';
$where = "WHERE 1=1";
if ($search) {
    $where .= " AND (firstName LIKE '%$search%' OR lastName LIKE '%$search%' OR email LIKE '%$search%')";
}

// Paginação
$limit = 20; 
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$start = ($page - 1) * $limit;

// Buscar Usuários
try {
    // Total
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM users $where");
    $total = $stmtCount->fetchColumn();
    $pages = ceil($total / $limit);

    // Consulta
    $sql = "SELECT id, firstName, lastName, email, role, created_at 
            FROM users 
            $where 
            ORDER BY id DESC 
            LIMIT $start, $limit";
    $stmt = $pdo->query($sql);
    $users = $stmt->fetchAll();

} catch (PDOException $e) {
    echo "Erro ao buscar usuários: " . $e->getMessage();
    exit;
}
?>

<div class="content-wrapper">
    <div class="page-header">
        <div class="page-title-group">
            <h3>Usuários Cadastrados</h3>
            <span class="badge-count"><?php echo $total; ?></span>
        </div>
        <a href="user_form.php" class="btn-save btn-primary">
            <i class="fas fa-plus" style="margin-right: 8px;"></i> Novo Usuário
        </a>
    </div>

    <div class="filter-container">
        <form method="GET" action="" class="search-form">
            <div class="input-icon-wrapper">
                <i class="fas fa-search input-icon"></i>
                <input type="text" name="search" class="form-control" placeholder="Buscar por nome ou email..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <?php if ($search): ?>
                <a href="users.php" class="btn-clear" title="Limpar busca"><i class="fas fa-times"></i></a>
            <?php endif; ?>
            <button type="submit" class="btn-save btn-secondary">Buscar</button>
        </form>
    </div>

    <div class="table-wrapper">
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th width="60">ID</th>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Perfil</th>
                        <th class="text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="text-muted">#<?php echo $u['id']; ?></td>
                        <td class="font-weight-bold">
                            <?php echo htmlspecialchars($u['firstName'] . ' ' . $u['lastName']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td>
                            <?php 
                                $roleClass = 'badge-default';
                                $label = ucfirst($u['role']);
                                
                                switch ($u['role']) {
                                    case 'student': $roleClass = 'badge-student'; $label = 'Aluno'; break;
                                    case 'teacher': $roleClass = 'badge-teacher'; $label = 'Professor'; break;
                                    case 'admin':   $roleClass = 'badge-admin';   $label = 'Admin'; break;
                                    case 'superadmin': $roleClass = 'badge-super'; $label = 'Super Admin'; break;
                                }
                            ?>
                            <span class="role-badge <?php echo $roleClass; ?>">
                                <?php echo $label; ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions-cell">
                                <a href="user_form.php?id=<?php echo $u['id']; ?>" class="btn-action btn-edit" title="Editar"><i class="fas fa-edit"></i></a>
                                <a href="#" class="btn-action btn-delete" title="Excluir" onclick="openDeleteModal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['firstName'])); ?>')"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (count($users) == 0): ?>
                        <tr><td colspan="5" class="empty-state">Nenhum usuário encontrado.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="?p=<?php echo $i; ?>&search=<?php echo $search; ?>" 
               class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>">
               <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<div id="deleteModal" class="modal-overlay" style="display: none;">
    <div class="modal-card confirm-card" style="width: 90%; max-width: 400px; margin: auto;">
        <div class="modal-icon-wrapper">
            <i class="fas fa-user-times" style="color: #e74c3c;"></i>
        </div>
        <h3 class="modal-h3">Excluir Usuário?</h3>
        <p class="modal-p">
            Você está prestes a excluir o usuário <strong id="deleteUserName" style="color: #2c3e50;"></strong>.
            Esta ação é irreversível.
        </p>
        
        <div class="modal-check-wrapper">
            <div style="display: flex; align-items: center; gap: 10px;">
                <input type="checkbox" id="checkDeleteConfirm" class="modal-checkbox">
                <label for="checkDeleteConfirm" class="modal-label">Estou ciente e quero excluir.</label>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeDeleteModal()">Cancelar</button>
            <a href="#" id="btnRealDelete" class="btn-danger disabled">Confirmar Exclusão</a>
        </div>
    </div>
</div>

<script>
    // Lógica do Modal de Exclusão
    const deleteModal = document.getElementById('deleteModal');
    const checkDelete = document.getElementById('checkDeleteConfirm');
    const btnRealDelete = document.getElementById('btnRealDelete');
    const deleteNameSpan = document.getElementById('deleteUserName');

    function openDeleteModal(id, name) {
        deleteNameSpan.innerText = name;
        btnRealDelete.href = "user_delete.php?id=" + id;
        
        // Reset
        checkDelete.checked = false;
        btnRealDelete.classList.add('disabled');
        btnRealDelete.style.pointerEvents = 'none';
        btnRealDelete.classList.remove('active-danger'); // Garante reset visual
        
        deleteModal.style.display = 'flex';
    }

    function closeDeleteModal() {
        deleteModal.style.display = 'none';
    }

    checkDelete.addEventListener('change', function() {
        if(this.checked) {
            btnRealDelete.classList.remove('disabled');
            btnRealDelete.style.pointerEvents = 'auto';
            btnRealDelete.classList.add('active-danger');
        } else {
            btnRealDelete.classList.add('disabled');
            btnRealDelete.style.pointerEvents = 'none';
            btnRealDelete.classList.remove('active-danger');
        }
    });

    window.onclick = function(event) {
        if (event.target == deleteModal) closeDeleteModal();
    }
</script>

<?php include '../includes/admin_footer.php'; ?>