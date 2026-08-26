<?php
// admin/id_card_templates.php
$pageTitle = "Modelos de Carteirinha";
include '../includes/admin_header.php';

// Check which is the teacher template
$stmt = $pdo->query("SELECT teacher_id_card_template_id FROM system_settings LIMIT 1");
$teacherTemplateId = $stmt->fetchColumn();

try {
    // Process Duplicate Action
    if (isset($_GET['action']) && $_GET['action'] == 'duplicate' && isset($_GET['id'])) {
        $dupId = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM id_card_templates WHERE id = :id");
        $stmt->execute([':id' => $dupId]);
        $orig = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($orig) {
            $stmtInsert = $pdo->prepare("INSERT INTO id_card_templates (name, orientation, background_image, template_text, created_at) VALUES (:name, :orientation, :background_image, :template_text, NOW())");
            $stmtInsert->execute([
                ':name' => $orig['name'] . ' (Cópia)',
                ':orientation' => $orig['orientation'],
                ':background_image' => $orig['background_image'],
                ':template_text' => $orig['template_text']
            ]);
            echo "<script>window.location.href='id_card_templates.php?msg=duplicated';</script>";
            exit;
        }
    }

    $sql = "SELECT t.*, 
            (SELECT COUNT(*) FROM courses c WHERE c.id_card_template_id = t.id) as course_count 
            FROM id_card_templates t 
            ORDER BY t.id DESC";
    $stmt = $pdo->query($sql);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo "Erro: " . $e->getMessage();
    exit;
}
?>

<style>
.templates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.template-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
    display: flex;
    flex-direction: column;
    border: 1px solid #eaeaea;
}
.template-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}
.template-preview {
    height: 140px;
    background-color: #f8f9fa;
    background-size: cover;
    background-position: center;
    position: relative;
    border-bottom: 1px solid #eaeaea;
}
.template-preview.placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #b2bec3;
    font-size: 2rem;
}
.badge-orientation {
    position: absolute;
    top: 10px;
    left: 10px;
    background: rgba(0,0,0,0.6);
    color: #fff;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}
.badge-teacher {
    position: absolute;
    bottom: 10px;
    right: 10px;
    background: #f1c40f;
    color: #fff;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: bold;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}
.card-menu {
    position: absolute;
    top: 10px;
    right: 10px;
}
.card-menu-btn {
    background: rgba(255,255,255,0.9);
    border: none;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    color: #333;
    transition: 0.2s;
}
.card-menu-btn:hover { background: #fff; transform: scale(1.05); }
.card-menu-content {
    display: none;
    position: absolute;
    right: 0;
    top: 30px; /* Adjusted to remove the gap between the button and the dropdown */
    background: #fff;
    min-width: 140px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.15);
    border-radius: 8px;
    z-index: 10;
    overflow: hidden;
}
.card-menu:hover .card-menu-content {
    display: block;
}
.card-menu-item {
    display: block;
    padding: 10px 15px;
    color: #333;
    text-decoration: none;
    font-size: 0.9rem;
    transition: 0.2s;
}
.card-menu-item:hover {
    background: #f1f2f6;
    color: #2980b9;
}
.template-info {
    padding: 15px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.template-title {
    font-size: 1.1rem;
    color: #2d3436;
    margin: 0 0 5px 0;
    font-weight: 600;
}
.template-meta {
    font-size: 0.85rem;
    color: #636e72;
    margin-bottom: 15px;
}
.template-actions {
    display: flex;
    justify-content: space-between;
    margin-top: auto;
    border-top: 1px solid #f1f2f6;
    padding-top: 15px;
}
.btn-card-action {
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    text-decoration: none;
    font-weight: 500;
    transition: background 0.2s;
    flex: 1;
    text-align: center;
}
.btn-edit {
    background: #e3f2fd;
    color: #1976d2;
    margin-right: 10px;
}
.btn-edit:hover { background: #bbdefb; }
.btn-delete {
    background: #ffebee;
    color: #d32f2f;
}
.btn-delete:hover { background: #ffcdd2; }
</style>

<div class="content-wrapper">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f2f6; padding-bottom: 15px; margin-bottom: 20px;">
        <div class="page-title-group" style="display: flex; align-items: center; gap: 10px;">
            <h3 style="margin: 0; color: #2c3e50; font-size: 1.5rem;"><i class="far fa-id-card"></i> Modelos de Carteirinha</h3>
            <span class="badge" style="background: #3498db; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8rem; font-weight: bold;"><?php echo count($templates); ?></span>
        </div>
        <a href="id_card_template_form.php" class="btn-save btn-primary" style="padding: 10px 20px; font-weight: 600; border-radius: 8px; box-shadow: 0 4px 6px rgba(52,152,219,0.2);">
            <i class="fas fa-plus"></i> Novo Modelo
        </a>
    </div>

    <?php if(empty($templates)): ?>
        <div style="text-align: center; padding: 50px; background: #fff; border-radius: 12px; border: 1px dashed #ccc; color: #8c9097;">
            <i class="far fa-folder-open" style="font-size: 3rem; margin-bottom: 15px; color: #dfe6e9;"></i>
            <h4>Nenhum modelo cadastrado</h4>
            <p style="font-size: 0.9rem;">Crie o primeiro modelo para personalizar as carteirinhas dos seus cursos.</p>
        </div>
    <?php else: ?>
        <div class="templates-grid">
            <?php foreach ($templates as $t): ?>
                <div class="template-card">
                    <div class="template-preview <?php echo empty($t['background_image']) ? 'placeholder' : ''; ?>" 
                         style="<?php echo !empty($t['background_image']) ? 'background-image: url('.htmlspecialchars($t['background_image']).');' : ''; ?>">
                         
                        <?php if(empty($t['background_image'])): ?>
                            <i class="far fa-image"></i>
                        <?php endif; ?>
                        
                        <span class="badge-orientation">
                            <i class="fas fa-arrows-alt-<?php echo $t['orientation'] == 'P' ? 'v' : 'h'; ?>"></i>
                            <?php echo $t['orientation'] == 'P' ? 'Retrato' : 'Paisagem'; ?>
                        </span>
                        
                        <?php if ($teacherTemplateId == $t['id']): ?>
                            <span class="badge-teacher" title="Crachá Oficial dos Professores">
                                <i class="fas fa-star"></i> Oficial (Prof.)
                            </span>
                        <?php endif; ?>
                        
                        <div class="card-menu">
                            <button class="card-menu-btn"><i class="fas fa-ellipsis-v"></i></button>
                            <div class="card-menu-content">
                                <a href="id_card_templates.php?action=duplicate&id=<?php echo $t['id']; ?>" class="card-menu-item">
                                    <i class="far fa-copy"></i> Duplicar
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="template-info">
                        <h4 class="template-title"><?php echo htmlspecialchars($t['name']); ?></h4>
                        <div class="template-meta">
                            <div><i class="fas fa-link" style="width:16px;"></i> <?php echo $t['course_count']; ?> Curso(s) vinculado(s)</div>
                            <div><i class="far fa-calendar-alt" style="width:16px;"></i> Criado em: <?php echo date('d/m/Y', strtotime($t['created_at'])); ?></div>
                        </div>
                        
                        <div class="template-actions">
                            <a href="id_card_template_form.php?id=<?php echo $t['id']; ?>" class="btn-card-action btn-edit">
                                <i class="fas fa-pen"></i> Editar
                            </a>
                            <a href="id_card_template_delete.php?id=<?php echo $t['id']; ?>" class="btn-card-action btn-delete" onclick="confirmDelete(event, this.href);">
                                <i class="fas fa-trash"></i> Excluir
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function confirmDelete(event, url) {
    event.preventDefault();
    Swal.fire({
        title: 'Tem certeza?',
        text: "Você não poderá reverter a exclusão deste modelo!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#95a5a6',
        confirmButtonText: 'Sim, excluir!',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
}
</script>

<?php include '../includes/admin_footer.php'; ?>
