<?php
// student/catalog.php
$pageTitle = "Catálogo de Cursos";
include '../includes/student_header.php';

// --- 1. CONFIGURAÇÃO DE FUSO HORÁRIO DINÂMICA ---
try {
    $stmtSettings = $pdo->query("SELECT timeZone FROM system_settings WHERE id = 1");
    $settings = $stmtSettings->fetch();
    
    if ($settings && !empty($settings['timeZone'])) {
        date_default_timezone_set($settings['timeZone']);
    } else {
        date_default_timezone_set('America/Sao_Paulo'); 
    }
} catch (Exception $e) {
    date_default_timezone_set('America/Sao_Paulo');
}

// --- 2. DADOS DO ALUNO E CURSOS ---
$stmtMe = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmtMe->execute([':id' => $_SESSION['user_id']]);
$me = $stmtMe->fetch();

$sql = "SELECT c.*, 
        (SELECT COUNT(*) FROM enrollments e WHERE e.courseId = c.id AND e.studentId = :sid AND e.status != 'Cancelado') as is_enrolled
        FROM courses c 
        WHERE c.status = 'Aberto'
        ORDER BY c.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([':sid' => $_SESSION['user_id']]);
$courses = $stmt->fetchAll();
?>

<style>
    /* --- CORREÇÃO DE LAYOUT E SCROLLBAR --- */
    
    /* 1. O Wrapper apenas define a área de rolagem, SEM PADDING */
    .content-wrapper {
        padding: 0 !important; /* Força zero padding para a barra colar na borda */
        width: 100%;
        box-sizing: border-box;
        overflow-y: auto;
        height: 100%;
    }

    /* 2. O Container Interno dá o respiro visual */
    .page-container {
        padding: 30px;
        width: 100%;
        max-width: 1400px;
        margin: 0 auto;
        box-sizing: border-box;
    }

    /* 3. Estilo da Barra de Rolagem Fina e Moderna */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    ::-webkit-scrollbar-track {
        background: transparent; /* Fundo transparente */
    }
    ::-webkit-scrollbar-thumb {
        background-color: #bdc3c7; /* Cinza discreto */
        border-radius: 4px;
    }
    ::-webkit-scrollbar-thumb:hover {
        background-color: var(--primary-color); /* Verde ao passar o mouse */
    }

    /* --- Grid do Catálogo --- */
    .catalog-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 30px;
        padding-bottom: 40px;
    }

    /* --- Card do Curso --- */
    .catalog-card {
        background: #fff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
        border: 1px solid #f0f0f0;
        display: flex;
        flex-direction: column;
        height: 100%;
        position: relative;
    }

    .catalog-card:hover {
        transform: translateY(-7px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        border-color: var(--primary-color-light, #a0ddd0);
    }

    /* --- Imagem e Preço --- */
    .card-thumb {
        height: 180px;
        background: #f8f9fa;
        position: relative;
        overflow: hidden;
    }
    .card-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.5s ease;
    }
    .catalog-card:hover .card-thumb img {
        transform: scale(1.05);
    }
    .card-thumb-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #dce0e3;
        font-size: 3rem;
    }

    .price-badge {
        position: absolute;
        bottom: 15px;
        right: 15px;
        background: rgba(0, 0, 0, 0.8);
        color: #fff;
        padding: 6px 14px;
        border-radius: 50px;
        font-weight: 700;
        font-size: 0.9rem;
        backdrop-filter: blur(4px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    }

    /* --- Conteúdo do Card --- */
    .card-body {
        padding: 25px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .card-title {
        margin: 0 0 10px 0;
        font-size: 1.2rem;
        color: #2c3e50;
        font-weight: 700;
        line-height: 1.3;
    }

    .card-desc {
        font-size: 0.9rem;
        color: #7f8c8d;
        line-height: 1.5;
        margin-bottom: 20px;
        flex-grow: 1;
    }

    /* --- Botões --- */
    .btn-catalog {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 12px;
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.95rem;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
    }

    .btn-enroll {
        background: var(--primary-color);
        color: #fff;
        box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    }
    .btn-enroll:hover {
        background-color: #2c3e50;
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.25);
    }

    .btn-enrolled {
        background: #e0e0e0;
        color: #7f8c8d;
        cursor: default;
        box-shadow: none;
    }

    /* --- ESTILOS DO MODAL E FORMULÁRIO --- */
    .modal-overlay { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); align-items: center; justify-content: center; backdrop-filter: blur(3px); }
    .modal-card { background-color: #fff; width: 95%; max-width: 600px; border-radius: 12px; overflow: hidden; box-shadow: 0 15px 40px rgba(0,0,0,0.2); animation: modalFadeIn 0.3s; display: flex; flex-direction: column; max-height: 90vh; }
    
    .modal-header { padding: 20px 25px; background: #f8f9fa; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-size: 1.2rem; font-weight: 700; color: #333; }
    .btn-close { font-size: 1.5rem; color: #aaa; cursor: pointer; transition: 0.2s; }
    .btn-close:hover { color: #e74c3c; }

    .modal-body { padding: 25px; overflow-y: auto; }
    .modal-footer { padding: 15px 25px; background: #f8f9fa; border-top: 1px solid #eee; display: flex; justify-content: space-between; }

    /* Wizard Steps */
    .step-content { display: none; animation: fadeIn 0.4s; }
    .step-content.active { display: block; }

    /* Form Inputs */
    .input-row { display: flex; gap: 20px; margin-bottom: 15px; }
    .input-group { flex: 1; display: flex; flex-direction: column; }
    .input-group label { font-size: 0.85rem; font-weight: 600; color: #555; margin-bottom: 5px; }
    .input-group input { padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95rem; }
    .input-group input:focus { border-color: var(--primary-color); outline: none; }

    .guardian-box { display: none; background: #fff8e1; border: 1px solid #ffe082; padding: 15px; border-radius: 8px; margin-top: 15px; }
    
    .contract-container { height: 200px; overflow-y: scroll; border: 1px solid #ccc; padding: 15px; background: #f9f9f9; font-size: 0.85rem; border-radius: 6px; margin-bottom: 15px; white-space: pre-wrap; }
    .checkbox-wrapper { display: flex; align-items: center; gap: 10px; font-size: 0.9rem; cursor: pointer; }
    .checkbox-wrapper input { width: 18px; height: 18px; cursor: pointer; }

    .btn-step { padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; font-size: 0.9rem; transition: 0.2s; }
    .btn-back { background: #e0e0e0; color: #333; }
    .btn-back:hover { background: #d0d0d0; }
    .btn-next { background: var(--primary-color); color: #fff; }
    .btn-next:hover { opacity: 0.9; }

    @keyframes modalFadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @media (max-width: 600px) { .input-row { flex-direction: column; gap: 10px; } }
</style>

<div class="content-wrapper">
    
    <div class="page-container">

        <div style="margin-bottom: 35px;">
            <h2 style="color: #2c3e50; margin: 0; font-size: 1.8rem;">Catálogo de Cursos</h2>
            <p style="color: #7f8c8d; margin-top: 8px;">Explore nossos cursos e inicie sua jornada de aprendizado.</p>
        </div>

        <div class="catalog-grid">
            <?php foreach ($courses as $c): ?>
                <div class="catalog-card">
                    <div class="card-thumb">
                        <?php if ($c['thumbnail']): ?>
                            <img src="<?php echo htmlspecialchars($c['thumbnail']); ?>" alt="<?php echo htmlspecialchars($c['name']); ?>">
                        <?php else: ?>
                            <div class="card-thumb-placeholder"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                        
                        <span class="price-badge">
                            R$ <?php echo number_format($c['monthlyFee'], 2, ',', '.'); ?>
                        </span>
                    </div>
                    
                    <div class="card-body">
                        <h3 class="card-title"><?php echo htmlspecialchars($c['name']); ?></h3>
                        <p class="card-desc">
                            <?php echo substr(strip_tags($c['description']), 0, 90) . (strlen($c['description']) > 90 ? '...' : ''); ?>
                        </p>
                        
                        <div style="margin-top: auto;">
                            <?php if ($c['is_enrolled'] > 0): ?>
                                <button class="btn-catalog btn-enrolled" disabled>
                                    <i class="fas fa-check-circle" style="margin-right: 8px;"></i> Já Matriculado
                                </button>
                            <?php else: ?>
                                <button class="btn-catalog btn-enroll" onclick="openEnrollModal(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($c['name'])); ?>', <?php echo $c['monthlyFee']; ?>)">
                                    Matricular-se
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div> </div>

<div id="enrollModal" class="modal-overlay">
    <div class="modal-card">
        <form id="enrollForm" action="enroll_action.php" method="POST">
            <input type="hidden" name="courseId" id="modalCourseId">
            
            <div class="modal-header">
                <span class="modal-title">Matrícula: <span id="modalCourseName" style="color:var(--primary-color);"></span></span>
                <span class="btn-close" onclick="closeModal()">&times;</span>
            </div>
            
            <div class="modal-body">
                <div id="step1" class="step-content active">
                    
                    <div style="margin-bottom: 25px;">
                        <h5 style="margin: 0 0 15px 0; color: #555; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                            <i class="fas fa-user-circle"></i> Seus Dados
                        </h5>
                        <div class="input-row">
                            <div class="input-group">
                                <label>Nome Completo</label>
                                <input type="text" value="<?php echo htmlspecialchars($me['firstName'] . ' ' . $me['lastName']); ?>" disabled style="background:#f5f5f5; color:#777;">
                            </div>
                            <div class="input-group">
                                <label>Data de Nascimento *</label>
                                <input type="date" name="birthDate" id="birthDate" value="<?php echo $me['birthDate']; ?>" required onchange="checkAge()">
                            </div>
                        </div>
                    </div>

                    <div id="guardianSection" class="guardian-box">
                        <h5 style="color: #856404; margin-top:0; margin-bottom: 10px;">
                            <i class="fas fa-user-shield"></i> Responsável Financeiro (Obrigatório)
                        </h5>
                        <p style="font-size:0.85rem; color:#856404; margin-bottom:15px;">Como você é menor de 18 anos, precisamos dos dados do seu responsável para gerar o contrato.</p>
                        
                        <div class="input-row">
                            <div class="input-group">
                                <label>Nome do Responsável *</label>
                                <input type="text" name="guardianName" id="guardianName" value="<?php echo htmlspecialchars($me['guardianName']); ?>">
                            </div>
                            <div class="input-group">
                                <label>CPF do Responsável *</label>
                                <input type="text" name="guardianCPF" id="guardianCPF" value="<?php echo htmlspecialchars($me['guardianCPF']); ?>">
                            </div>
                        </div>
                        <div class="input-row">
                            <div class="input-group">
                                <label>E-mail do Responsável</label>
                                <input type="email" name="guardianEmail" value="<?php echo htmlspecialchars($me['guardianEmail']); ?>">
                            </div>
                            <div class="input-group">
                                <label>Telefone</label>
                                <input type="text" name="guardianPhone" value="<?php echo htmlspecialchars($me['guardianPhone']); ?>">
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 25px;">
                        <h5 style="margin: 0 0 15px 0; color: #555; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                            <i class="fas fa-wallet"></i> Financeiro
                        </h5>
                        <div class="input-row" style="align-items: flex-end;">
                            <div class="input-group">
                                <label>Valor da Mensalidade</label>
                                <div style="font-size: 1.4rem; font-weight: 800; color: #27ae60;" id="modalCoursePrice"></div>
                            </div>
                            <div class="input-group">
                                <label>Dia de Vencimento</label>
                                <input type="text" value="Todo dia <?php echo date('d'); ?>" disabled style="font-weight:bold; color:#555; background:#f9f9f9;">
                                <input type="hidden" id="customDueDay" value="<?php echo date('d'); ?>">
                                <small style="color:#999; margin-top:3px;">Definido pela data de hoje.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="step2" class="step-content">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <h4 style="color: var(--primary-color); margin:0;">Quase lá!</h4>
                        <p style="color: #666; font-size: 0.9rem;">Leia atentamente o contrato gerado e confirme a matrícula.</p>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <h5 style="margin-bottom: 5px;">Contrato de Prestação de Serviços</h5>
                        <div class="contract-container" id="contractContent">
                            <div style="text-align:center; padding:50px; color:#999;">
                                <i class="fas fa-spinner fa-spin"></i> Gerando contrato...
                            </div>
                        </div>
                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="acceptContract" id="acceptContract" required>
                            <label for="acceptContract">Li e concordo com o Contrato de Serviços.</label>
                        </div>
                    </div>

                    <div>
                        <h5 style="margin-bottom: 5px;">Termo de Uso de Imagem</h5>
                        <div class="contract-container" id="termsContent" style="height: 100px;">
                            Carregando...
                        </div>
                        <div class="checkbox-wrapper">
                            <input type="checkbox" name="acceptTerms" id="acceptTerms" required>
                            <label for="acceptTerms">Li e concordo com o Termo de Uso de Imagem.</label>
                        </div>
                    </div>
                </div>

            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-step btn-back" id="btnCancel" onclick="closeModal()">Cancelar</button>
                <button type="button" class="btn-step btn-back" id="btnBack" onclick="prevStep()" style="display:none;">Voltar</button>
                
                <button type="button" class="btn-step btn-next" id="btnNext" onclick="nextStep()">Continuar &rarr;</button>
                <button type="submit" class="btn-step btn-next" id="btnConfirm" style="display:none;">Confirmar Matrícula <i class="fas fa-check"></i></button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // --- Lógica do Wizard ---
    let currentStep = 1;
    const enrollModal = document.getElementById('enrollModal');

    function openEnrollModal(id, name, price) {
        // Reset
        currentStep = 1;
        document.getElementById('step1').classList.add('active');
        document.getElementById('step2').classList.remove('active');
        updateButtons();
        
        // Preencher dados
        document.getElementById('modalCourseId').value = id;
        document.getElementById('modalCourseName').innerText = name;
        document.getElementById('modalCoursePrice').innerText = "R$ " + price.toFixed(2).replace('.', ',');
        
        enrollModal.style.display = 'flex';
        checkAge(); // Verifica responsável
    }

    function closeModal() {
        enrollModal.style.display = 'none';
    }

    function nextStep() {
        // Validação do Passo 1
        const birthDate = document.getElementById('birthDate').value;
        if (!birthDate) {
            Swal.fire("Atenção", "Por favor, preencha a data de nascimento.", "warning");
            return;
        }

        // Se menor, valida responsável
        if (isMinor()) {
            const gName = document.getElementById('guardianName').value;
            const gCPF = document.getElementById('guardianCPF').value;
            if (!gName || !gCPF) {
                Swal.fire("Atenção", "Para menores de 18 anos, Nome e CPF do responsável são obrigatórios.", "warning");
                return;
            }
        }

        // Se passou, carrega o contrato e avança
        loadContractData();
        
        currentStep = 2;
        document.getElementById('step1').classList.remove('active');
        document.getElementById('step2').classList.add('active');
        updateButtons();
    }

    function prevStep() {
        currentStep = 1;
        document.getElementById('step2').classList.remove('active');
        document.getElementById('step1').classList.add('active');
        updateButtons();
    }

    function updateButtons() {
        if (currentStep === 1) {
            document.getElementById('btnCancel').style.display = 'inline-block';
            document.getElementById('btnBack').style.display = 'none';
            document.getElementById('btnNext').style.display = 'inline-block';
            document.getElementById('btnConfirm').style.display = 'none';
        } else {
            document.getElementById('btnCancel').style.display = 'none';
            document.getElementById('btnBack').style.display = 'inline-block';
            document.getElementById('btnNext').style.display = 'none';
            document.getElementById('btnConfirm').style.display = 'inline-block';
        }
    }

    // --- Lógica de Negócio ---

    function isMinor() {
        const birthDateVal = document.getElementById('birthDate').value;
        if (!birthDateVal) return false;
        const birthDate = new Date(birthDateVal);
        const today = new Date();
        let age = today.getFullYear() - birthDate.getFullYear();
        const m = today.getMonth() - birthDate.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) age--;
        return age < 18;
    }

    function checkAge() {
        const section = document.getElementById('guardianSection');
        const gName = document.getElementById('guardianName');
        const gCPF = document.getElementById('guardianCPF');

        if (isMinor()) {
            section.style.display = 'block';
            gName.required = true;
            gCPF.required = true;
        } else {
            section.style.display = 'none';
            gName.required = false;
            gCPF.required = false;
        }
    }

    function loadContractData() {
        document.getElementById('contractContent').innerHTML = '<div style="text-align:center; padding:30px; color:#999;"><i class="fas fa-spinner fa-spin"></i> Gerando contrato personalizado...</div>';
        
        const courseId = document.getElementById('modalCourseId').value;
        const formData = new FormData();
        formData.append('courseId', courseId);
        formData.append('dueDay', document.getElementById('customDueDay').value);
        formData.append('guardianName', document.getElementById('guardianName').value);
        formData.append('guardianCPF', document.getElementById('guardianCPF').value);
        const gEmail = document.getElementsByName('guardianEmail')[0];
        if(gEmail) formData.append('guardianEmail', gEmail.value);

        fetch('ajax_contract.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if(data.error) {
                Swal.fire("Erro", data.error, "error");
            } else {
                document.getElementById('contractContent').innerHTML = data.contract;
                document.getElementById('termsContent').innerHTML = data.terms;
            }
        })
        .catch(err => {
            console.error(err);
            document.getElementById('contractContent').innerHTML = "Erro ao carregar o contrato.";
        });
    }

    // --- CORREÇÃO PRINCIPAL: ENVIO DO FORMULÁRIO SEM SAIR DA PÁGINA ---
    document.getElementById('enrollForm').addEventListener('submit', function(e) {
        e.preventDefault(); // Impede a tela preta (recupera o controle)

        const btn = document.getElementById('btnConfirm');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
        btn.disabled = true;

        const formData = new FormData(this);

        fetch('enroll_action.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: 'Parabéns!',
                    text: data.message,
                    icon: 'success',
                    confirmButtonText: 'Ir para Meus Cursos',
                    confirmButtonColor: '#27ae60'
                }).then((result) => {
                    window.location.href = 'index.php'; // Redireciona para o painel
                });
                closeModal();
            } else {
                Swal.fire({
                    title: 'Atenção',
                    text: data.message,
                    icon: 'warning',
                    confirmButtonColor: '#e74c3c'
                });
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            Swal.fire({
                title: 'Erro',
                text: 'Ocorreu um erro interno de conexão. Tente novamente.',
                icon: 'error'
            });
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
    });

    // Fechar ao clicar fora
    window.onclick = function(event) {
        if (event.target == enrollModal) closeModal();
    }
</script>

<?php include '../includes/student_footer.php'; ?>