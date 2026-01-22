<?php
// admin/course_form.php
include '../includes/admin_header.php';
checkRole(['admin', 'superadmin']);

// Mapa para labels bonitos no JSON
$daysMap = [
    'dom' => 'Domingo', 'seg' => 'Segunda-feira', 'ter' => 'Terça-feira',
    'qua' => 'Quarta-feira', 'qui' => 'Quinta-feira', 'sex' => 'Sexta-feira', 'sab' => 'Sábado'
];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $id ? "Editar Curso" : "Novo Curso";
$pageTitle = $action;

// Variáveis Iniciais
$name = '';
$description = '';
$monthlyFee = '0.00';
$totalSlots = '';
$status = 'Aberto';
$thumbnail = '';
$carga_horaria = '';
$selectedTeachers = []; 
$schedules = []; // Array para horários
$msg = '';

// Se for edição
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $course = $stmt->fetch();
    
    if (!$course) {
        echo "<div class='content-wrapper'><div class='alert alert-danger'>Curso não encontrado.</div></div>";
        include '../includes/admin_footer.php';
        exit;
    }
    
    $name = $course['name'];
    $description = $course['description'];
    $monthlyFee = $course['monthlyFee'];
    $totalSlots = $course['totalSlots'];
    $status = $course['status'];
    $thumbnail = $course['thumbnail'];
    $carga_horaria = $course['carga_horaria'];
    
    // Decodifica horários existentes
    if (!empty($course['schedule_json'])) {
        $schedules = json_decode($course['schedule_json'], true) ?? [];
    }

    $stmtTeachers = $pdo->prepare("SELECT teacherId, commissionRate FROM course_teachers WHERE courseId = :id");
    $stmtTeachers->execute([':id' => $id]);
    $selectedTeachers = $stmtTeachers->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $stmtProf = $pdo->query("SELECT id, firstName, lastName, role FROM users WHERE role IN ('teacher', 'admin', 'superadmin') ORDER BY firstName ASC");
    $allTeachers = $stmtProf->fetchAll();
} catch (Exception $e) {
    $allTeachers = [];
}

// PROCESSAR POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = cleanInput($_POST['name']);
    $description = $_POST['description']; 
    $monthlyFee = (float)$_POST['monthlyFee'];
    $totalSlots = !empty($_POST['totalSlots']) ? (int)$_POST['totalSlots'] : null;
    $status = cleanInput($_POST['status']);
    $carga_horaria = cleanInput($_POST['carga_horaria']);
    
    $postedTeachers = isset($_POST['teacher_ids']) ? $_POST['teacher_ids'] : [];
    $postedCommissions = isset($_POST['commissions']) ? $_POST['commissions'] : [];

    // --- PROCESSAR HORÁRIOS (JSON) ---
    $jsonSchedule = null;
    if (isset($_POST['sched_day'])) {
        $tempSchedule = [];
        for ($i = 0; $i < count($_POST['sched_day']); $i++) {
            $dId = $_POST['sched_day'][$i];
            if (!empty($dId)) {
                $tempSchedule[] = [
                    'day_id' => $dId,
                    'day_label' => $daysMap[$dId] ?? $dId,
                    'start' => $_POST['sched_start'][$i],
                    'end' => $_POST['sched_end'][$i]
                ];
            }
        }
        if (!empty($tempSchedule)) {
            $jsonSchedule = json_encode($tempSchedule, JSON_UNESCAPED_UNICODE);
        }
    }

    if (isset($_FILES['thumb']) && $_FILES['thumb']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['thumb']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $data = file_get_contents($_FILES['thumb']['tmp_name']);
            $thumbnail = 'data:image/' . $ext . ';base64,' . base64_encode($data);
        } else {
            $msg .= '<div class="alert alert-danger">Formato de imagem inválido. Use JPG ou PNG. <span class="alert-close" onclick="this.parentElement.remove();">&times;</span></div>';
        }
    }

    if (empty($name)) {
        $msg = '<div class="alert alert-danger">O nome do curso é obrigatório. <span class="alert-close" onclick="this.parentElement.remove();">&times;</span></div>';
    } else {
        try {
            $pdo->beginTransaction(); 

            if ($id) {
                // UPDATE (Incluindo schedule_json)
                $sql = "UPDATE courses SET 
                        name = :name, 
                        description = :desc, 
                        monthlyFee = :fee, 
                        totalSlots = :slots,
                        status = :status,
                        carga_horaria = :carga,
                        schedule_json = :sched"; // Novo campo
                
                if (isset($_FILES['thumb']) && $_FILES['thumb']['error'] == 0) {
                    $sql .= ", thumbnail = :thumb";
                }
                
                $sql .= " WHERE id = :id";
                
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':desc', $description);
                $stmt->bindValue(':fee', $monthlyFee);
                $stmt->bindValue(':slots', $totalSlots);
                $stmt->bindValue(':status', $status);
                $stmt->bindValue(':carga', $carga_horaria);
                $stmt->bindValue(':sched', $jsonSchedule); // Bind do JSON
                $stmt->bindValue(':id', $id);
                
                if (isset($_FILES['thumb']) && $_FILES['thumb']['error'] == 0) {
                    $stmt->bindValue(':thumb', $thumbnail);
                }
                $stmt->execute();

                $pdo->prepare("DELETE FROM course_teachers WHERE courseId = ?")->execute([$id]);

            } else {
                // INSERT (Incluindo schedule_json)
                $sql = "INSERT INTO courses (name, description, monthlyFee, totalSlots, status, carga_horaria, thumbnail, schedule_json, created_at) 
                        VALUES (:name, :desc, :fee, :slots, :status, :carga, :thumb, :sched, NOW())";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':name' => $name,
                    ':desc' => $description,
                    ':fee' => $monthlyFee,
                    ':slots' => $totalSlots,
                    ':status' => $status,
                    ':carga' => $carga_horaria,
                    ':thumb' => $thumbnail,
                    ':sched' => $jsonSchedule // Salva o JSON
                ]);
                $id = $pdo->lastInsertId(); 
            }

            // Salva Professores
            if (!empty($postedTeachers)) {
                $sqlTeacher = "INSERT INTO course_teachers (courseId, teacherId, commissionRate, createdAt) VALUES (:cid, :tid, :rate, NOW())";
                $stmtTeacher = $pdo->prepare($sqlTeacher);

                for ($i = 0; $i < count($postedTeachers); $i++) {
                    $tid = (int)$postedTeachers[$i];
                    $rate = isset($postedCommissions[$i]) ? (float)$postedCommissions[$i] : 0;
                    
                    if ($tid > 0) {
                        $stmtTeacher->execute([
                            ':cid' => $id,
                            ':tid' => $tid,
                            ':rate' => $rate
                        ]);
                    }
                }
            }

            $pdo->commit();
            $msg = '<div class="alert alert-success">Curso salvo com sucesso! <span class="alert-close" onclick="this.parentElement.remove();">&times;</span></div>';
            
            // Recarrega dados
            $stmtTeachers = $pdo->prepare("SELECT teacherId, commissionRate FROM course_teachers WHERE courseId = :id");
            $stmtTeachers->execute([':id' => $id]);
            $selectedTeachers = $stmtTeachers->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = '<div class="alert alert-danger">Erro ao salvar: ' . $e->getMessage() . ' <span class="alert-close" onclick="this.parentElement.remove();">&times;</span></div>';
        }
    }
}
?>

<div class="card-box">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 style="margin: 0;"><?php echo $action; ?></h3>
        <a href="courses.php" class="btn-back"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <?php echo $msg; ?>
    
    <form method="POST" action="" enctype="multipart/form-data" id="courseForm">
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div style="flex: 2; min-width: 300px;">
                <div class="form-group">
                    <label>Nome do Curso *</label>
                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($name); ?>" required placeholder="Ex: Informática Básica">
                </div>

                <div class="form-group">
                    <label>Descrição</label>
                    <textarea name="description" class="form-control" style="height: 120px;"><?php echo htmlspecialchars($description); ?></textarea>
                </div>

                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1;">
                        <label>Mensalidade (R$)</label>
                        <input type="number" step="0.01" name="monthlyFee" class="form-control" value="<?php echo $monthlyFee; ?>">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Carga Horária</label>
                        <input type="text" name="carga_horaria" class="form-control" value="<?php echo htmlspecialchars($carga_horaria); ?>" placeholder="Ex: 40 horas">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Vagas Totais</label>
                        <input type="number" name="totalSlots" class="form-control" value="<?php echo $totalSlots; ?>" placeholder="Vazio = Ilimitado">
                    </div>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="Aberto" <?php echo ($status == 'Aberto' || $status == 'active') ? 'selected' : ''; ?>>Aberto (Ativo)</option>
                        <option value="Fechado" <?php echo ($status == 'Fechado' || $status == 'inactive') ? 'selected' : ''; ?>>Fechado (Inativo)</option>
                    </select>
                </div>
            </div>

            <div style="flex: 1; min-width: 250px; border-left: 1px solid #eee; padding-left: 20px;">
                <div class="form-group">
                    <label>Capa do Curso</label>
                    <div style="margin-bottom: 10px; width: 100%; height: 180px; background: #f9f9f9; border: 1px dashed #ccc; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 4px;">
                        <?php if (!empty($thumbnail)): ?>
                            <img src="<?php echo $thumbnail; ?>" style="width: 100%; height: 100%; object-fit: cover;" id="preview">
                        <?php else: ?>
                            <img src="" style="display: none; width: 100%; height: 100%; object-fit: cover;" id="preview">
                            <span style="color: #ccc;" id="preview-text">Sem Imagem</span>
                        <?php endif; ?>
                    </div>
                    <input type="file" name="thumb" class="form-control" accept="image/*" onchange="previewImage(this)">
                </div>
            </div>
        </div>

        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

        <h4><i class="far fa-clock"></i> Grade de Horários</h4>
        <p style="font-size: 0.9rem; color: #666;">Defina os dias da semana e horários das aulas deste curso.</p>

        <div id="schedule-container" style="margin-bottom: 15px;">
            </div>

        <button type="button" class="btn-save" style="background-color: #f39c12; border:none; padding: 8px 15px; font-size: 0.9rem;" onclick="addScheduleRow()">
            <i class="fas fa-plus"></i> Adicionar Horário
        </button>

        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

        <h4><i class="fas fa-chalkboard-teacher"></i> Professores e Comissões</h4>
        <p style="font-size: 0.9rem; color: #666;">Adicione os professores responsáveis por este curso.</p>
        
        <div id="teachersList" style="margin-top: 15px;">
            </div>
        
        <button type="button" class="btn-save" style="background-color: #3498db; margin-top: 10px; padding: 8px 15px; font-size: 0.9rem;" onclick="addTeacherRow()">
            <i class="fas fa-plus"></i> Adicionar Professor
        </button>

        <div style="margin-top: 40px; border-top: 1px solid #eee; padding-top: 20px;">
            <button type="submit" class="btn-save"><i class="fas fa-save"></i> Salvar Curso Completo</button>
            <a href="courses.php" style="margin-left: 15px; color: #666; text-decoration: none;">Cancelar</a>
        </div>
    </form>
</div>

<style>
    /* Estilo para as linhas de horário */
    .schedule-row {
        display: flex; gap: 10px; align-items: flex-end; margin-bottom: 10px;
        background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #eee;
        flex-wrap: wrap;
    }
    .sched-col { flex: 1; min-width: 150px; }
    .btn-remove-sched { background: #e74c3c; color: white; border: none; padding: 0 15px; border-radius: 5px; cursor: pointer; height: 42px; display: flex; align-items: center; justify-content: center; }

    /* NOVO: Estilo para as linhas de Professor (Empilhado) */
    .teacher-row-stacked {
        background: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.02);
    }
    .teacher-row-stacked label {
        font-size: 0.85rem; 
        font-weight: 600; 
        color: #555; 
        display: block; 
        margin-bottom: 5px;
    }
    .bottom-group {
        display: flex;
        gap: 10px;
        margin-top: 10px;
        align-items: flex-end;
    }
    .btn-remove-teacher {
        height: 42px; /* Mesma altura do input */
        width: 42px;
        min-width: 42px;
        border: 1px solid #e74c3c;
        background: #fff;
        color: #e74c3c;
        border-radius: 4px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: 0.2s;
    }
    .btn-remove-teacher:hover {
        background: #e74c3c;
        color: white;
    }
</style>

<script>
    // --- LÓGICA DE PROFESSORES (Alterada para Layout Empilhado) ---
    const availableTeachers = <?php echo json_encode($allTeachers); ?>;
    const existingTeachers = <?php echo json_encode($selectedTeachers); ?>;

    function addTeacherRow(teacherId = '', commission = 0) {
        const container = document.getElementById('teachersList');
        const div = document.createElement('div');
        div.className = 'teacher-row-stacked';
        
        let options = '<option value="">Selecione um Professor...</option>';
        availableTeachers.forEach(t => {
            const selected = (t.id == teacherId) ? 'selected' : '';
            let fullName = t.firstName + (t.lastName ? ' ' + t.lastName : '');
            let roleLabel = (t.role !== 'teacher') ? ` (${t.role})` : '';
            options += `<option value="${t.id}" ${selected}>${fullName}${roleLabel}</option>`;
        });

        // HTML do Card Empilhado
        div.innerHTML = `
            <div style="width: 100%;">
                <label>Professor</label>
                <select name="teacher_ids[]" class="form-control" required>${options}</select>
            </div>
            
            <div class="bottom-group">
                <div style="flex-grow: 1;">
                    <label>Comissão (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="commissions[]" class="form-control" value="${commission}" placeholder="Ex: 10">
                </div>
                <div>
                    <button type="button" class="btn-remove-teacher" onclick="this.closest('.teacher-row-stacked').remove()" title="Remover">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
        container.appendChild(div);
    }

    // --- LÓGICA DE HORÁRIOS ---
    const existingSchedules = <?php echo json_encode($schedules); ?>;

    function addScheduleRow(data = null) {
        const container = document.getElementById('schedule-container');
        const div = document.createElement('div');
        div.className = 'schedule-row';
        
        const dayVal = data ? data.day_id : '';
        const startVal = data ? data.start : '';
        const endVal = data ? data.end : '';

        div.innerHTML = `
            <div class="sched-col" style="flex: 2;">
                <label style="font-size:0.8rem; font-weight:bold;">Dia da Semana</label>
                <select name="sched_day[]" class="form-control" required>
                    <option value="">Selecione...</option>
                    <option value="seg" ${dayVal === 'seg' ? 'selected' : ''}>Segunda-feira</option>
                    <option value="ter" ${dayVal === 'ter' ? 'selected' : ''}>Terça-feira</option>
                    <option value="qua" ${dayVal === 'qua' ? 'selected' : ''}>Quarta-feira</option>
                    <option value="qui" ${dayVal === 'qui' ? 'selected' : ''}>Quinta-feira</option>
                    <option value="sex" ${dayVal === 'sex' ? 'selected' : ''}>Sexta-feira</option>
                    <option value="sab" ${dayVal === 'sab' ? 'selected' : ''}>Sábado</option>
                    <option value="dom" ${dayVal === 'dom' ? 'selected' : ''}>Domingo</option>
                </select>
            </div>
            <div class="sched-col">
                <label style="font-size:0.8rem; font-weight:bold;">Início</label>
                <input type="time" name="sched_start[]" class="form-control" value="${startVal}" required>
            </div>
            <div class="sched-col">
                <label style="font-size:0.8rem; font-weight:bold;">Fim</label>
                <input type="time" name="sched_end[]" class="form-control" value="${endVal}" required>
            </div>
            <div>
                <label style="visibility:hidden; display:block; font-size:0.8rem;">X</label>
                <button type="button" class="btn-remove-sched" onclick="this.parentElement.parentElement.remove()" title="Remover dia">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(div);
    }

    // --- LÓGICA DE IMAGEM ---
    function previewImage(input) {
        var preview = document.getElementById('preview');
        var text = document.getElementById('preview-text');
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
                if(text) text.style.display = 'none';
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    // INICIALIZAÇÃO
    document.addEventListener('DOMContentLoaded', () => {
        // Carrega professores existentes
        if (existingTeachers.length > 0) {
            existingTeachers.forEach(et => addTeacherRow(et.teacherId, et.commissionRate));
        } else {
            addTeacherRow();
        }

        // Carrega horários existentes
        if (existingSchedules.length > 0) {
            existingSchedules.forEach(sched => addScheduleRow(sched));
        } else {
            addScheduleRow(); // Começa com um vazio
        }
    });
</script>

<?php include '../includes/admin_footer.php'; ?>