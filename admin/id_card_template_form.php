<?php
// admin/id_card_template_form.php
$pageTitle = "Formulário de Modelo de Carteirinha";
include '../includes/admin_header.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$template = [
    'name' => '',
    'template_text' => '{"name":{"x":10,"y":25,"size":12,"color":"#000000"},"course":{"x":10,"y":32,"size":10,"color":"#555555"},"cpf":{"x":10,"y":39,"size":10,"color":"#555555"},"photo":{"x":60,"y":10,"w":20,"h":25}}',
    'background_image' => '',
    'orientation' => 'L'
];

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM id_card_templates WHERE id = ?");
    $stmt->execute([$id]);
    $res = $stmt->fetch();
    if ($res) {
        $template = $res;
    }
}

// Get all courses
$stmt = $pdo->query("SELECT id, name FROM courses ORDER BY name ASC");
$allCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get currently assigned courses
$assignedCourses = [];
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT id FROM courses WHERE id_card_template_id = ?");
    $stmt->execute([$id]);
    $assignedCourses = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Is this the teacher template?
$stmt = $pdo->query("SELECT teacher_id_card_template_id FROM system_settings LIMIT 1");
$teacherTemplateId = $stmt->fetchColumn();
$isTeacherTemplate = ($teacherTemplateId == $id && $id > 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = cleanInput($_POST['name']);
    $template_text = $_POST['template_text']; // JSON configs
    $orientation = isset($_POST['orientation']) && $_POST['orientation'] === 'P' ? 'P' : 'L';
    
    // Process Background Image Upload (Base64)
    $bgImage = $template['background_image'];
    if (isset($_FILES['background_image']) && $_FILES['background_image']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['background_image']['tmp_name'];
        $type = pathinfo($_FILES['background_image']['name'], PATHINFO_EXTENSION);
        $data = file_get_contents($tmp);
        $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
        $bgImage = $base64;
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE id_card_templates SET name=?, background_image=?, template_text=?, orientation=? WHERE id=?");
        $stmt->execute([$name, $bgImage, $template_text, $orientation, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO id_card_templates (name, background_image, template_text, orientation) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $bgImage, $template_text, $orientation]);
        $id = $pdo->lastInsertId();
    }
    
    // Handle Course Assignments
    $selectedCourses = isset($_POST['courses']) ? $_POST['courses'] : [];
    // Remove this template from all courses first
    $pdo->prepare("UPDATE courses SET id_card_template_id = NULL WHERE id_card_template_id = ?")->execute([$id]);
    if (!empty($selectedCourses)) {
        $placeholders = implode(',', array_fill(0, count($selectedCourses), '?'));
        $sql = "UPDATE courses SET id_card_template_id = ? WHERE id IN ($placeholders)";
        $params = array_merge([$id], $selectedCourses);
        $pdo->prepare($sql)->execute($params);
    }
    
    // Handle Teacher Template
    $isTeacherTemplatePost = isset($_POST['is_teacher_template']) ? true : false;
    if ($isTeacherTemplatePost) {
        $pdo->prepare("UPDATE system_settings SET teacher_id_card_template_id = ?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE system_settings SET teacher_id_card_template_id = NULL WHERE teacher_id_card_template_id = ?")->execute([$id]);
    }
    
    echo "<script>window.location.href='id_card_templates.php';</script>";
    exit;
}
?>
<style>
.editor-container {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin-top: 15px;
    margin-bottom: 20px;
}
.canvas-wrapper {
    background: #e0e0e0;
    border: 2px solid #ccc;
    position: relative;
    overflow: hidden;
    background-size: 100% 100%;
    background-position: center;
    background-repeat: no-repeat;
    user-select: none;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    border-radius: 8px;
    transition: width 0.3s, height 0.3s;
}
/* Landscape defaults */
.canvas-wrapper.canvas-L { width: 428px; height: 270px; }
/* Portrait defaults */
.canvas-wrapper.canvas-P { width: 270px; height: 428px; }

.canvas-element {
    position: absolute;
    cursor: grab;
    border: 1px dashed transparent;
    padding: 2px;
    white-space: nowrap;
    line-height: 1;
}
.canvas-element:active {
    cursor: grabbing;
}
.canvas-element:hover {
    border-color: rgba(52, 152, 219, 0.5);
}
.canvas-element.selected {
    border-color: #e74c3c;
    background: rgba(255,255,255,0.4);
    box-shadow: 0 0 5px rgba(231, 76, 60, 0.5);
}
.photo-placeholder {
    background: rgba(52, 152, 219, 0.3);
    border: 2px dashed #3498db !important;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #333;
    font-size: 14px;
    font-weight: bold;
}
.snap-guide {
    position: absolute;
    z-index: 999;
    pointer-events: none;
}
.snap-guide-v {
    width: 0;
    top: 0;
    bottom: 0;
    border-left: 1px dashed #e74c3c;
}
.snap-guide-h {
    height: 0;
    left: 0;
    right: 0;
    border-top: 1px dashed #e74c3c;
}
.properties-panel {
    flex: 1;
    min-width: 250px;
    background: #fdfdfd;
    padding: 20px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.prop-group {
    margin-bottom: 15px;
}
.prop-group label {
    display: block;
    font-size: 13px;
    color: #555;
    font-weight: 600;
    margin-bottom: 5px;
}
.prop-group input[type="number"], .prop-group input[type="color"] {
    width: 100%;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
    box-sizing: border-box;
}
.prop-group input[type="color"] {
    padding: 2px;
    height: 40px;
    cursor: pointer;
}
.toggle-visibility {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}
.grid-2-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}
.settings-box {
    background: #f8f9fa;
    padding: 15px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin-bottom: 20px;
}
.settings-box h4 {
    margin-top: 0;
    font-size: 14px;
    color: #34495e;
    margin-bottom: 15px;
    border-bottom: 1px solid #ddd;
    padding-bottom: 8px;
}
.course-checkbox-list {
    max-height: 150px;
    overflow-y: auto;
    border: 1px solid #ccc;
    padding: 10px;
    border-radius: 4px;
    background: #fff;
}
.course-checkbox-list label {
    display: block;
    margin-bottom: 5px;
    font-weight: normal;
}
</style>

<div class="content-wrapper">
    <div class="page-header">
        <h3><?php echo $id > 0 ? 'Editar Modelo' : 'Novo Modelo'; ?></h3>
        <a href="id_card_templates.php" class="btn-action"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>

    <form method="POST" enctype="multipart/form-data" class="white-box">
        
        <div class="grid-2-col">
            <!-- Esquerda: Informações Básicas e Vínculos -->
            <div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display:block; font-weight: bold; margin-bottom: 5px;">Nome do Modelo</label>
                    <input type="text" name="name" class="form-control" style="width: 100%; padding: 8px;" value="<?php echo htmlspecialchars($template['name']); ?>" placeholder="ex: K-Pop Padrão" required>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="display:block; font-weight: bold; margin-bottom: 5px;">Imagem de Fundo (Retrato ou Paisagem)</label>
                    <input type="file" name="background_image" id="bgImageInput" class="form-control" style="width: 100%;" accept="image/*">
                    <small style="color:#666; display:block; margin-top:5px;">O sistema detectará automaticamente se a imagem é Retrato (Vertical) ou Paisagem (Horizontal).</small>
                </div>
                
                <input type="hidden" name="orientation" id="inputOrientation" value="<?php echo $template['orientation']; ?>">
                <textarea name="template_text" id="templateText" style="display:none;"><?php echo htmlspecialchars($template['template_text']); ?></textarea>
            </div>
            
            <!-- Direita: Associações (Cursos e Professores) -->
            <div>
                <div class="settings-box">
                    <h4><i class="fas fa-link"></i> Associações de Template</h4>
                    
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label style="font-weight: bold; display:block; margin-bottom: 5px;">Cursos Vinculados</label>
                        <div class="course-checkbox-list">
                            <?php foreach($allCourses as $c): ?>
                                <label>
                                    <input type="checkbox" name="courses[]" value="<?php echo $c['id']; ?>" <?php echo in_array($c['id'], $assignedCourses) ? 'checked' : ''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?>
                                </label>
                            <?php endforeach; ?>
                            <?php if(empty($allCourses)): ?>
                                <span style="color:#999; font-size:12px;">Nenhum curso cadastrado.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label style="font-weight: bold; display:flex; align-items:center; gap: 8px; cursor: pointer; color:#2c3e50;">
                            <input type="checkbox" name="is_teacher_template" value="1" <?php echo $isTeacherTemplate ? 'checked' : ''; ?>>
                            <i class="fas fa-star" style="color:#f1c40f;"></i> Definir como Crachá Oficial de Professores/Funcionários
                        </label>
                        <small style="color:#666; display:block; margin-top:5px; margin-left:25px;">Substitui a configuração global e aplica este modelo para todos os professores.</small>
                    </div>
                </div>
            </div>
        </div>

        <h4 style="margin-top: 20px; margin-bottom:0; color:#2c3e50;"><i class="fas fa-paint-brush"></i> Editor Visual de Layout</h4>
        <p style="color:#666; font-size: 0.9rem; margin-top:5px;">Arraste os elementos no quadro abaixo para posicioná-los. Clique em um elemento para editar tamanho e cor.</p>
        
        <div class="editor-container">
            <div>
                <div class="canvas-wrapper canvas-<?php echo $template['orientation']; ?>" id="idCanvas" style="<?php if(!empty($template['background_image'])) echo 'background-image: url('.$template['background_image'].');'; ?>">
                    <div id="el_name" class="canvas-element" data-id="name">Nome do Aluno</div>
                    <div id="el_course" class="canvas-element" data-id="course">Nome do Curso</div>
                    <div id="el_cpf" class="canvas-element" data-id="cpf">123.456.789-00</div>
                    <div id="el_photo" class="canvas-element photo-placeholder" data-id="photo">Foto</div>
                </div>
                <small style="color:#888; display:block; margin-top:10px;" id="canvasInfoText"><i class="fas fa-info-circle"></i> Escala: 1mm = 5px. Orientação: <?php echo $template['orientation'] == 'P' ? 'Retrato (Vertical)' : 'Paisagem (Horizontal)'; ?>.</small>
            </div>
            
            <div class="properties-panel" id="propPanel">
                <h4 style="margin-top:0; color:#3498db; border-bottom: 1px solid #eee; padding-bottom:10px;">
                    <i class="fas fa-layer-group"></i> Selecionar Elemento
                </h4>
                <div style="margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 5px;">
                    <button type="button" class="btn-action" style="padding: 5px 10px; font-size:12px;" onclick="selectElement('name')">Nome</button>
                    <button type="button" class="btn-action" style="padding: 5px 10px; font-size:12px;" onclick="selectElement('course')">Curso/Cargo</button>
                    <button type="button" class="btn-action" style="padding: 5px 10px; font-size:12px;" onclick="selectElement('cpf')">CPF</button>
                    <button type="button" class="btn-action" style="padding: 5px 10px; font-size:12px;" onclick="selectElement('photo')">Foto</button>
                </div>

                <div id="propFields" style="display: none;">
                    <h4 style="margin-top:0; color:#e74c3c; border-bottom: 1px solid #eee; padding-bottom:10px;" id="propTitle">Propriedades</h4>
                    
                    <div class="prop-group">
                    <label class="toggle-visibility">
                        <input type="checkbox" id="propVisible">
                        Exibir na Carteirinha
                    </label>
                </div>
                
                <div class="prop-group text-prop">
                    <label>Tamanho da Fonte (pt)</label>
                    <input type="number" id="propSize" min="5" max="50">
                </div>
                
                <div class="prop-group text-prop">
                    <label>Cor do Texto</label>
                    <input type="color" id="propColor">
                </div>

                <div class="prop-group text-prop">
                    <label>Largura da Caixa (mm)</label>
                    <input type="number" id="propWText" placeholder="Automático" min="5" max="100">
                    <small style="font-size:11px; color:#888;">Deixe vazio p/ auto.</small>
                </div>
                
                <div class="prop-group text-prop">
                    <label>Alinhamento do Texto</label>
                    <select id="propAlign" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        <option value="L">Esquerda</option>
                        <option value="C">Centralizado</option>
                        <option value="R">Direita</option>
                    </select>
                </div>
                
                <div class="prop-group photo-prop" style="display:none;">
                    <label>Largura (mm)</label>
                    <input type="number" id="propW" min="10" max="100">
                </div>
                
                <div class="prop-group photo-prop" style="display:none;">
                    <label>Altura (mm)</label>
                    <input type="number" id="propH" min="10" max="100">
                </div>

                <div class="prop-group photo-prop" style="display:none;">
                    <label>Formato da Foto</label>
                    <select id="propShape" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px;">
                        <option value="rect">Retângulo (ex: 3x4)</option>
                        <option value="circle">Círculo</option>
                    </select>
                </div>
                </div> <!-- /propFields -->
            </div>
        </div>

        <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">
        <button type="submit" class="btn-save btn-primary" style="padding: 12px 25px; font-size: 1.1rem;"><i class="fas fa-save"></i> Salvar Modelo</button>
    </form>
</div>

<script>
const scale = 5; // 1mm = 5px
const canvas = document.getElementById('idCanvas');
const templateInput = document.getElementById('templateText');
const inputOrientation = document.getElementById('inputOrientation');
const canvasInfoText = document.getElementById('canvasInfoText');

// UI Elements
const propPanel = document.getElementById('propPanel');
const propTitle = document.getElementById('propTitle');
const propVisible = document.getElementById('propVisible');
const propSize = document.getElementById('propSize');
const propColor = document.getElementById('propColor');
const propW = document.getElementById('propW');
const propH = document.getElementById('propH');
const propShape = document.getElementById('propShape');
const propWText = document.getElementById('propWText');
const propAlign = document.getElementById('propAlign');
const textProps = document.querySelectorAll('.text-prop');
const photoProps = document.querySelectorAll('.photo-prop');

let config = {};
try {
    config = JSON.parse(templateInput.value);
} catch(e) {
    config = {};
}

let activeElement = null;
let isDragging = false;
let startX, startY, initialLeft, initialTop;

const elements = {
    name: document.getElementById('el_name'),
    course: document.getElementById('el_course'),
    cpf: document.getElementById('el_cpf'),
    photo: document.getElementById('el_photo')
};

// Map labels
let labels = {
    name: "NOME DO ALUNO",
    course: "CURSO / CARGO",
    cpf: "CPF",
    photo: "FOTO"
};

const isTeacherCheckbox = document.querySelector('input[name="is_teacher_template"]');
function updatePlaceholderTexts() {
    if (isTeacherCheckbox && isTeacherCheckbox.checked) {
        labels.name = "NOME DO PROFESSOR";
        labels.course = "CARGO (EX: DIRETOR)";
        document.getElementById('el_name').innerText = "Nome do Professor";
        document.getElementById('el_course').innerText = "Cargo (ex: Diretor)";
    } else {
        labels.name = "NOME DO ALUNO";
        labels.course = "CURSO / CARGO";
        document.getElementById('el_name').innerText = "Nome do Aluno";
        document.getElementById('el_course').innerText = "Curso / Cargo";
    }
    if (activeElement) {
        propTitle.innerHTML = "<i class='fas fa-sliders-h'></i> " + labels[activeElement];
    }
}
if (isTeacherCheckbox) {
    isTeacherCheckbox.addEventListener('change', updatePlaceholderTexts);
    updatePlaceholderTexts();
}

// Initialize Canvas
function initCanvas() {
    for (let key in elements) {
        let el = elements[key];
        
        if (!config[key]) {
            el.style.display = 'none';
        } else {
            el.style.display = 'flex';
            let cX = config[key].x !== undefined ? config[key].x : 10;
            let cY = config[key].y !== undefined ? config[key].y : 10;
            
            let pLeft = cX * scale;
            let pTop = cY * scale;
            
            // Forçar os elementos a ficarem visíveis dentro da tela (não sair pela direita/baixo)
            let maxW = canvas.classList.contains('canvas-P') ? 270 : 428;
            let maxH = canvas.classList.contains('canvas-P') ? 428 : 270;
            
            // Se estiver fora da tela na horizontal, centraliza perfeitamente
            if (pLeft > maxW - 20 || pLeft < 0) {
                let elW = (key === 'photo') ? ((config[key].w || 20) * scale) : 100;
                pLeft = (maxW / 2) - (elW / 2);
            }
            if (pTop > maxH - 20) pTop = Math.max(10, maxH - 50);

            el.style.left = pLeft + 'px';
            el.style.top = pTop + 'px';
            
            if (key === 'photo') {
                let cW = config[key].w || 20;
                let cH = config[key].h || 25;
                el.style.width = (cW * scale) + 'px';
                el.style.height = (cH * scale) + 'px';
                if (config[key].shape === 'circle') {
                    el.style.borderRadius = '50%';
                } else {
                    el.style.borderRadius = '0';
                }
            } else {
                el.style.color = config[key].color || '#000000';
                el.style.fontSize = (config[key].size * 1.5) + 'px';
                el.style.fontWeight = 'bold';
                el.style.fontFamily = 'Arial, sans-serif';
                el.style.display = 'flex';
                el.style.alignItems = 'center';
                
                if (config[key].w) {
                    el.style.width = (config[key].w * scale) + 'px';
                    // Enable flex alignment
                    let align = config[key].align || 'L';
                    if (align === 'C') {
                        el.style.justifyContent = 'center';
                        el.style.textAlign = 'center';
                    } else if (align === 'R') {
                        el.style.justifyContent = 'flex-end';
                        el.style.textAlign = 'right';
                    } else {
                        el.style.justifyContent = 'flex-start';
                        el.style.textAlign = 'left';
                    }
                } else {
                    el.style.width = 'auto';
                    el.style.justifyContent = 'flex-start';
                    el.style.textAlign = 'left';
                }
            }
        }
        
        // Mouse events for drag
        el.onmousedown = function(e) {
            selectElement(key);
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            initialLeft = parseInt(el.style.left || 0);
            initialTop = parseInt(el.style.top || 0);
            e.stopPropagation(); // Previne deselecionar
        };
    }
}

document.addEventListener('mousemove', function(e) {
    if (!isDragging || !activeElement) return;

    // Remove old guides
    document.querySelectorAll('.snap-guide').forEach(g => g.remove());

    let dx = e.clientX - startX;
    let dy = e.clientY - startY;
    
    let newLeft = initialLeft + dx;
    let newTop = initialTop + dy;
    
    let el = elements[activeElement];
    
    // Magnetic Snapping Logic
    let snapThreshold = 10;
    let snappedX = false;
    let snappedY = false;
    
    let elCenterX = newLeft + el.offsetWidth / 2;
    let elCenterY = newTop + el.offsetHeight / 2;
    
    // Canvas Center Snap
    let canvasCenterX = canvas.offsetWidth / 2;
    let canvasCenterY = canvas.offsetHeight / 2;
    
    if (Math.abs(elCenterX - canvasCenterX) < snapThreshold) {
        newLeft = canvasCenterX - el.offsetWidth / 2;
        snappedX = true;
        drawGuide('v', canvasCenterX);
    }
    if (Math.abs(elCenterY - canvasCenterY) < snapThreshold) {
        newTop = canvasCenterY - el.offsetHeight / 2;
        snappedY = true;
        drawGuide('h', canvasCenterY);
    }
    
    // Element to Element Snap (Center to Center)
    for (let key in elements) {
        if (key === activeElement || elements[key].style.display === 'none') continue;
        let other = elements[key];
        let otherCenterX = parseInt(other.style.left) + other.offsetWidth / 2;
        let otherCenterY = parseInt(other.style.top) + other.offsetHeight / 2;
        
        if (!snappedX && Math.abs(elCenterX - otherCenterX) < snapThreshold) {
            newLeft = otherCenterX - el.offsetWidth / 2;
            snappedX = true;
            drawGuide('v', otherCenterX);
        }
        if (!snappedY && Math.abs(elCenterY - otherCenterY) < snapThreshold) {
            newTop = otherCenterY - el.offsetHeight / 2;
            snappedY = true;
            drawGuide('h', otherCenterY);
        }
    }
    
    // Boundaries
    if (newLeft < 0) newLeft = 0;
    if (newTop < 0) newTop = 0;
    if (newLeft + el.offsetWidth > canvas.offsetWidth) newLeft = canvas.offsetWidth - el.offsetWidth;
    if (newTop + el.offsetHeight > canvas.offsetHeight) newTop = canvas.offsetHeight - el.offsetHeight;
    
    el.style.left = newLeft + 'px';
    el.style.top = newTop + 'px';
});

function drawGuide(type, pos) {
    let guide = document.createElement('div');
    guide.className = 'snap-guide snap-guide-' + type;
    if (type === 'v') {
        guide.style.left = pos + 'px';
    } else {
        guide.style.top = pos + 'px';
    }
    canvas.appendChild(guide);
}

document.addEventListener('mouseup', function(e) {
    if (isDragging) {
        isDragging = false;
        document.querySelectorAll('.snap-guide').forEach(g => g.remove());
        updateConfigFromDOM();
    }
});

canvas.addEventListener('mousedown', function(e) {
    if (e.target === canvas) {
        deselectAll();
    }
});

function selectElement(key) {
    if (activeElement) {
        elements[activeElement].classList.remove('selected');
    }
    activeElement = key;
    elements[key].classList.add('selected');
    
    // Mostra o painel principal de propriedades
    document.getElementById('propFields').style.display = 'block';
    propTitle.innerHTML = "<i class='fas fa-sliders-h'></i> " + labels[key];
    
    let conf = config[key] || {};
    propVisible.checked = (elements[key].style.display !== 'none');
    
    if (key === 'photo') {
        textProps.forEach(el => el.style.display = 'none');
        photoProps.forEach(el => el.style.display = 'block');
        propW.value = conf.w || 20;
        propH.value = conf.h || 25;
        propShape.value = conf.shape || 'rect';
    } else {
        textProps.forEach(el => el.style.display = 'block');
        photoProps.forEach(el => el.style.display = 'none');
        propSize.value = conf.size || 10;
        propColor.value = conf.color || '#000000';
        propWText.value = conf.w || '';
        propAlign.value = conf.align || 'L';
    }
}

function deselectAll() {
    activeElement = null;
    document.getElementById('propFields').style.display = 'none';
    for (let key in elements) {
        elements[key].classList.remove('selected');
    }
}

function updateConfigFromDOM() {
    for (let key in elements) {
        let el = elements[key];
        if (el.style.display === 'none') {
            delete config[key];
        } else {
            if (!config[key]) config[key] = {};
            // Convert px to mm
            config[key].x = Math.round(parseInt(el.style.left) / scale) || 0;
            config[key].y = Math.round(parseInt(el.style.top) / scale) || 0;
            if (key === 'photo') {
                config[key].w = config[key].w || 20;
                config[key].h = config[key].h || 25;
                config[key].shape = config[key].shape || 'rect';
            }
        }
    }
    templateInput.value = JSON.stringify(config);
}

// Property change listeners
propVisible.addEventListener('change', function() {
    if (!activeElement) return;
    if (this.checked) {
        // Create default if missing
        if (!config[activeElement]) {
            if (activeElement === 'photo') {
                let maxW = canvas.classList.contains('canvas-P') ? 270 : 428;
                let cX = Math.round(((maxW / 2) - 50) / scale); // Center horizontally (100px / 2 = 50)
                config[activeElement] = {x: cX, y:10, w:20, h:25, shape:'rect'};
            } else {
                config[activeElement] = {x:10, y:10, size:10, color:'#000000'};
            }
        }
    } else {
        elements[activeElement].style.display = 'none';
    }
    initCanvas();
    updateConfigFromDOM();
});

propSize.addEventListener('input', function() {
    if (!activeElement || activeElement === 'photo') return;
    if (!config[activeElement]) return;
    config[activeElement].size = parseInt(this.value) || 10;
    initCanvas();
    updateConfigFromDOM();
});

propColor.addEventListener('input', function() {
    if (!activeElement || activeElement === 'photo') return;
    if (!config[activeElement]) return;
    config[activeElement].color = this.value;
    initCanvas();
    updateConfigFromDOM();
});

propWText.addEventListener('input', function() {
    if (!activeElement || activeElement === 'photo') return;
    if (!config[activeElement]) return;
    if (this.value) {
        config[activeElement].w = parseInt(this.value);
    } else {
        delete config[activeElement].w;
    }
    initCanvas();
    updateConfigFromDOM();
});

propAlign.addEventListener('change', function() {
    if (!activeElement || activeElement === 'photo') return;
    if (!config[activeElement]) return;
    config[activeElement].align = this.value;
    initCanvas();
    updateConfigFromDOM();
});

propW.addEventListener('input', function() {
    if (activeElement !== 'photo') return;
    if (!config.photo) return;
    config.photo.w = parseInt(this.value) || 20;
    initCanvas();
    updateConfigFromDOM();
});

propH.addEventListener('input', function() {
    if (activeElement !== 'photo') return;
    if (!config.photo) return;
    config.photo.h = parseInt(this.value) || 25;
    initCanvas();
    updateConfigFromDOM();
});

propShape.addEventListener('change', function() {
    if (activeElement !== 'photo') return;
    if (!config.photo) return;
    config.photo.shape = this.value;
    initCanvas();
    updateConfigFromDOM();
});

// Image preview and Auto-Detect Orientation
document.getElementById('bgImageInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(evt) {
            const img = new Image();
            img.onload = function() {
                // Auto detect orientation
                const orient = (img.height >= img.width) ? 'P' : 'L';
                inputOrientation.value = orient;
                
                // Switch classes
                canvas.className = 'canvas-wrapper canvas-' + orient;
                
                canvasInfoText.innerHTML = "<i class='fas fa-info-circle'></i> Escala: 1mm = 5px. Orientação auto-detectada: " + (orient === 'P' ? "Retrato (Vertical)" : "Paisagem (Horizontal)") + ".";
                
                // Reposition elements within new bounds if necessary
                updateConfigFromDOM(); 
                initCanvas();
            };
            img.src = evt.target.result;
            canvas.style.backgroundImage = 'url(' + evt.target.result + ')';
        }
        reader.readAsDataURL(file);
    }
});

// Start
initCanvas();
</script>

<?php include '../includes/admin_footer.php'; ?>
