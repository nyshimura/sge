<?php
// student/index.php

// 1. INICIALIZAÇÃO E SESSÃO
if (session_status() == PHP_SESSION_NONE) session_start();
require_once '../config/database.php';
require_once '../includes/qr_generator.php'; 

$studentId = $_SESSION['user_id'];
$feedbackMsg = '';

// --- LÓGICA 1: PROCESSAR ASSINATURA EM MASSA (NOVO) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_all_pending') {
    try {
        // Atualiza TODOS os cursos ativos deste aluno que estão sem data de aceite
        $stmtUpdate = $pdo->prepare("
            UPDATE enrollments 
            SET termsAcceptedAt = NOW() 
            WHERE studentId = :uid 
            AND status IN ('Aprovada', 'Ativo')
            AND (termsAcceptedAt IS NULL OR termsAcceptedAt = '0000-00-00 00:00:00')
        ");
        $stmtUpdate->execute([':uid' => $studentId]);
        
        if ($stmtUpdate->rowCount() > 0) {
            $feedbackMsg = 'success_terms';
        }
    } catch (Exception $e) {
        $feedbackMsg = 'error_terms';
    }
}

// --- LÓGICA 2: AJAX PIX (MANTIDA) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_pix' && isset($_GET['pid'])) {
    header('Content-Type: application/json');
    $paymentId = (int)$_GET['pid'];
    $studentIdCheck = $_SESSION['user_id'];

    // Verificação de segurança
    $check = $pdo->prepare("SELECT id FROM payments WHERE id = :pid AND studentId = :sid");
    $check->execute([':pid' => $paymentId, ':sid' => $studentIdCheck]);
    
    if ($check->rowCount() > 0) {
        $result = generatePixForPayment($paymentId, $pdo);
        echo json_encode($result);
    } else {
        echo json_encode(['success' => false, 'error' => 'Acesso negado.']);
    }
    exit;
}

$pageTitle = "Painel do Aluno";
include '../includes/student_header.php';

// --- CONSULTAS PADRÃO (Cursos, Certificados, Financeiro) ---
// (Mantive suas consultas originais aqui)
$stmtCourses = $pdo->prepare("SELECT c.id, c.name, c.thumbnail, e.status, e.customMonthlyFee, e.scholarshipPercentage, c.monthlyFee, e.enrollmentDate FROM enrollments e JOIN courses c ON e.courseId = c.id WHERE e.studentId = :uid AND e.status IN ('Aprovada', 'Ativo') ORDER BY e.enrollmentDate DESC");
$stmtCourses->execute([':uid' => $studentId]);
$activeCourses = $stmtCourses->fetchAll();

$stmtCert = $pdo->prepare("SELECT cert.*, c.name as course_name FROM certificates cert JOIN courses c ON cert.course_id = c.id WHERE cert.student_id = :uid ORDER BY cert.completion_date DESC");
$stmtCert->execute([':uid' => $studentId]);
$certificates = $stmtCert->fetchAll();

$openInvoices = [];
try {
    $stmtFin = $pdo->prepare("SELECT p.*, c.name as course_name FROM payments p JOIN courses c ON p.courseId = c.id WHERE p.studentId = :uid AND p.status = 'Pendente' ORDER BY p.dueDate ASC LIMIT 3");
    $stmtFin->execute([':uid' => $studentId]);
    $openInvoices = $stmtFin->fetchAll();
} catch (Exception $e) {}

// --- VERIFICAÇÃO DE TERMOS PENDENTES ---
// Verifica se AINDA sobrou algum termo pendente para mostrar o box amarelo
$stmtTerms = $pdo->prepare("
    SELECT c.name as courseName 
    FROM enrollments e 
    JOIN courses c ON e.courseId = c.id
    WHERE e.studentId = :uid 
    AND e.status IN ('Aprovada', 'Ativo')
    AND (e.termsAcceptedAt IS NULL OR e.termsAcceptedAt = '0000-00-00 00:00:00')
");
$stmtTerms->execute([':uid' => $studentId]);
$pendingTerms = $stmtTerms->fetchAll(PDO::FETCH_ASSOC);

// Total mensalidade
$totalMensalidade = 0;
foreach($activeCourses as $ac) {
    if (!empty($ac['customMonthlyFee']) && $ac['customMonthlyFee'] > 0) $totalMensalidade += $ac['customMonthlyFee'];
    elseif (!empty($ac['scholarshipPercentage']) && $ac['scholarshipPercentage'] > 0) $totalMensalidade += max(0, $ac['monthlyFee'] - ($ac['monthlyFee'] * ($ac['scholarshipPercentage'] / 100)));
    else $totalMensalidade += $ac['monthlyFee'];
}
?>

<style>
    /* (Seus estilos CSS anteriores mantidos...) */
    /* ... */
    
    /* --- Scrollbar Local --- */
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background-color: #bdc3c7; border-radius: 4px; border: none; }
    ::-webkit-scrollbar-thumb:hover { background-color: var(--primary-color); }

    /* --- Stats Grid Responsivo --- */
    .dashboard-stats { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); 
        gap: 20px; 
        margin-bottom: 30px; 
    }
    
    .stat-card { 
        background: #fff; 
        padding: 20px; 
        border-radius: 12px; 
        box-shadow: 0 2px 8px rgba(0,0,0,0.05); 
        display: flex; 
        align-items: center; 
        gap: 15px; 
        border: 1px solid #f0f0f0;
        transition: transform 0.2s;
    }
    .stat-card:hover { transform: translateY(-3px); }

    .stat-icon { 
        width: 50px; height: 50px; 
        background: #f8f9fa; 
        border-radius: 50%; 
        display: flex; align-items: center; justify-content: center; 
        font-size: 1.5rem; color: var(--primary-color); 
    }
    
    .stat-info h4 { margin: 0 0 5px; font-size: 0.85rem; color: #7f8c8d; text-transform: uppercase; font-weight: 600; }
    .stat-info p { margin: 0; font-size: 1.4rem; font-weight: bold; color: #333; }

    /* --- Headers --- */
    .section-header { 
        display: flex; justify-content: space-between; align-items: center; 
        margin-bottom: 15px; margin-top: 30px; 
    }
    .section-title { font-size: 1.2rem; font-weight: 700; color: #2c3e50; margin: 0; display: flex; align-items: center; gap: 8px; }
    .view-all-link { font-size: 0.9rem; color: var(--primary-color); font-weight: 600; text-decoration: none; }

    /* --- Cursos Grid --- */
    .courses-grid { 
        display: grid; 
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); 
        gap: 20px; 
    }
    .course-card-dash { 
        background: #fff; 
        border-radius: 10px; 
        overflow: hidden; 
        box-shadow: 0 2px 8px rgba(0,0,0,0.06); 
        transition: transform 0.2s; 
        display: flex; flex-direction: column; 
        border: 1px solid #f0f0f0;
    }
    .course-card-dash:hover { transform: translateY(-3px); }
    
    .ccd-thumb { height: 140px; background: #f8f9fa; position: relative; overflow: hidden; }
    .ccd-thumb img { width: 100%; height: 100%; object-fit: cover; }
    
    .ccd-body { padding: 15px; flex-grow: 1; display: flex; flex-direction: column; }
    .ccd-title { font-weight: bold; margin-bottom: 8px; color: #333; font-size: 1rem; }
    .ccd-meta { font-size: 0.85rem; color: #666; margin-bottom: 15px; }
    
    .btn-access-sm { 
        margin-top: auto; 
        background: var(--primary-color); 
        color: white; 
        text-align: center; 
        padding: 8px 15px; 
        border-radius: 50px; 
        text-decoration: none; 
        font-weight: 600; 
        font-size: 0.85rem;
        transition: 0.2s;
    }
    .btn-access-sm:hover { background-color: var(--secondary-color); }

    /* --- Tabelas & Listas --- */
    .table-responsive {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .dash-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); white-space: nowrap; }
    .dash-table th, .dash-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
    .dash-table th { background: #f8f9fa; font-weight: 600; color: #555; font-size: 0.85rem; text-transform: uppercase; }
    
    .btn-pix { background: #32bcad; color: white; border: none; padding: 5px 10px; border-radius: 4px; font-size: 0.75rem; cursor: pointer; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }
    
    /* Layout das Colunas Inferiores */
    .bottom-cols {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 30px;
        margin-top: 40px;
    }

    /* Modal QR Atualizado */
    #pixModal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(2px); padding: 20px; box-sizing: border-box; }
    .qr-container { background:#fff; padding:15px; border-radius:12px; display:inline-block; border:1px solid #eee; margin-bottom:15px; }
    
    .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 20px auto; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

    /* Estilo do Botão de Assinar em Massa */
    .btn-sign-all {
        background-color: #27ae60; /* Verde para ação positiva */
        color: white;
        font-weight: 700;
        padding: 12px 25px;
        border-radius: 6px;
        text-decoration: none;
        transition: 0.2s;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        font-size: 1rem;
        box-shadow: 0 3px 0 #219150;
    }
    .btn-sign-all:hover {
        background-color: #2ecc71;
        transform: translateY(-2px);
    }
    .btn-sign-all:active {
        transform: translateY(0);
        box-shadow: none;
    }
</style>

<div class="content-wrapper" style="padding: 0;">
    <div class="page-container" style="padding: 30px; max-width: 1400px; margin: 0 auto;">

        <?php if ($feedbackMsg === 'success_terms'): ?>
            <div class="alert alert-success" style="margin-bottom: 20px; border-radius: 8px; padding: 15px; background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; display:flex; align-items:center; gap:10px;">
                <i class="fas fa-check-circle fa-2x"></i>
                <div>
                    <strong>Sucesso!</strong><br>
                    Todos os termos pendentes foram assinados e registrados com a data de hoje.
                </div>
            </div>
        <?php endif; ?>

        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-book-open"></i></div>
                <div class="stat-info">
                    <h4>Cursos Ativos</h4>
                    <p><?php echo count($activeCourses); ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="color: #f39c12; background: #fff8e1;"><i class="fas fa-award"></i></div>
                <div class="stat-info">
                    <h4>Certificados</h4>
                    <p><?php echo count($certificates); ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="color: #2980b9; background: #e3f2fd;"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-info">
                    <h4>Mensalidade Total</h4>
                    <p>R$ <?php echo number_format($totalMensalidade, 2, ',', '.'); ?></p>
                </div>
            </div>
        </div>

        <div class="section-header">
            <h3 class="section-title"><i class="fas fa-chalkboard-teacher"></i> Meus Cursos</h3>
            <a href="my_courses.php" class="view-all-link">Ver todos &rarr;</a>
        </div>

        <?php if(count($activeCourses) == 0): ?>
            <div class="card-box" style="text-align:center; padding: 40px; color: #888; border: 1px dashed #ddd; background: #f9f9f9;">
                <i class="fas fa-graduation-cap" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i>
                <p>Você não está matriculado em nenhum curso ativo.</p>
                <a href="catalog.php" class="btn-primary" style="margin-top: 10px; display: inline-block;">Ver Catálogo</a>
            </div>
        <?php else: ?>
            <div class="courses-grid">
                <?php foreach($activeCourses as $ac): ?>
                    <div class="course-card-dash">
                        <div class="ccd-thumb">
                            <?php if($ac['thumbnail']): ?>
                                <img src="<?php echo htmlspecialchars($ac['thumbnail']); ?>" alt="Capa">
                            <?php else: ?>
                                <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#dce0e3;"><i class="fas fa-image fa-3x"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="ccd-body">
                            <div class="ccd-title"><?php echo htmlspecialchars($ac['name']); ?></div>
                            <div class="ccd-meta"><i class="far fa-calendar-alt"></i> Início: <?php echo date('d/m/Y', strtotime($ac['enrollmentDate'])); ?></div>
                            <a href="course_panel.php?cid=<?php echo $ac['id']; ?>" class="btn-access-sm">Acessar Painel</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bottom-cols">
            
            <div>
                <div class="section-header" style="margin-top:0;">
                    <h3 class="section-title"><i class="fas fa-file-invoice-dollar" style="color: #e74c3c;"></i> Pagamentos Pendentes</h3>
                </div>
                
                <?php if(count($openInvoices) > 0): ?>
                    <div class="table-responsive">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>Ref.</th>
                                    <th>Vencimento</th>
                                    <th>Valor</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($openInvoices as $inv): 
                                    $isLate = (strtotime($inv['dueDate']) < time());
                                ?>
                                <tr>
                                    <td><small style="font-weight:600; color:#555;"><?php echo date('m/Y', strtotime($inv['referenceDate'])); ?></small></td>
                                    <td>
                                        <?php echo date('d/m', strtotime($inv['dueDate'])); ?>
                                        <?php if($isLate): ?><i class="fas fa-exclamation-circle" style="color:#e74c3c;" title="Atrasado"></i><?php endif; ?>
                                    </td>
                                    <td><strong style="<?php echo $isLate ? 'color:#e74c3c;' : 'color:#2c3e50;'; ?>">R$ <?php echo number_format($inv['amount'], 2, ',', '.'); ?></strong></td>
                                    <td>
                                        <button class="btn-pix" onclick="abrirPix(<?php echo $inv['id']; ?>, '<?php echo number_format($inv['amount'], 2, ',', '.'); ?>')">
                                            Pix
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="card-box" style="text-align:center; padding: 30px; color: #888;">
                        <i class="fas fa-check-circle fa-2x" style="color: #27ae60; margin-bottom: 10px; opacity: 0.8;"></i>
                        <p style="font-size:0.9rem; margin:0;">Tudo em dia!</p>
                    </div>
                <?php endif; ?>

                <?php if (count($pendingTerms) > 0): ?>
                    <div class="pending-terms-box" style="background-color: #fff3cd; border: 1px solid #ffeeba; border-radius: 8px; padding: 20px; margin-top: 25px;">
                        
                        <div class="terms-header" style="display:flex; align-items:center; gap:10px; color:#856404; margin-bottom:15px;">
                            <i class="fas fa-file-contract fa-lg"></i>
                            <h3 style="margin:0; font-size:1.1rem;">Termos de Uso Pendentes</h3>
                        </div>
                        
                        <p style="color: #856404; font-size: 0.95rem; margin-bottom: 20px; line-height: 1.5;">
                            Identificamos que você possui <strong><?php echo count($pendingTerms); ?></strong> curso(s) sem a confirmação de leitura dos termos de uso.
                            <br><small>(Ao clicar abaixo, você declara que leu e aceita os termos vigentes).</small>
                        </p>

                        <form method="POST">
                            <input type="hidden" name="action" value="sign_all_pending">
                            
                            <button type="submit" class="btn-sign-all" style="width: 100%; justify-content: center;">
                                <i class="fas fa-pen-fancy"></i> 
                                Li e Concordo - Assinar Tudo
                            </button>
                        </form>

                    </div>
                <?php endif; ?>
                </div>

            <div>
                <div class="section-header" style="margin-top:0;">
                    <h3 class="section-title"><i class="fas fa-certificate" style="color: #f39c12;"></i> Últimos Certificados</h3>
                </div>
                
                <?php if(count($certificates) == 0): ?>
                    <div class="card-box" style="text-align:center; padding: 30px; color: #999;">
                        <i class="fas fa-award fa-2x" style="opacity:0.3; margin-bottom:10px;"></i>
                        <p style="font-size:0.9rem; margin:0;">Nenhum certificado emitido.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="dash-table">
                            <thead><tr><th>Curso</th><th>Data</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach(array_slice($certificates, 0, 3) as $cert): ?>
                                <tr>
                                    <td style="font-weight:500; color:#333; white-space: normal;"><?php echo htmlspecialchars($cert['course_name']); ?></td>
                                    <td style="color:#666; font-size:0.85rem;"><?php echo date('d/m/Y', strtotime($cert['completion_date'])); ?></td>
                                    <td style="text-align:right;">
                                        <a href="../includes/generate_certificate_pdf.php?hash=<?php echo $cert['verification_hash']; ?>" target="_blank" class="btn-pix" style="background:var(--primary-color); text-decoration:none; padding: 5px 10px;">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div> 
</div>

<div id="pixModal">
    <div class="card-box" style="max-width:400px; width:95%; text-align:center; border-top: 5px solid #32bcad;">
        <h3 style="margin-top:0; color:#2c3e50;">Pagamento via PIX</h3>
        
        <div id="pixLoading">
            <div class="spinner"></div>
            <p>Gerando QR Code...</p>
        </div>

        <div id="pixContent" style="display:none;">
            <p style="color:#7f8c8d; margin-bottom:15px;">Escaneie o código abaixo para pagar</p>
            
            <div class="qr-container">
                <img src="" id="pixQrImage" style="width:200px; height:200px; display:block;" alt="QR Code PIX">
            </div>

            <p style="font-size:1.1rem; font-weight:bold; color:#333; margin:5px 0;">Valor: <span id="pixVal" style="color:#27ae60;"></span></p>
            
            <div style="background:#f4f6f9; padding:12px; border-radius:8px; margin:15px 0; border:1px dashed #ccc;">
                <small style="color:#666; display:block; margin-bottom:5px; font-weight:bold; text-transform:uppercase;">
                    Copia e Cola:
                </small>
                
                <textarea id="pixKeyText" readonly style="width:100%; height:70px; font-size:0.8rem; border:1px solid #ddd; border-radius:4px; padding:5px; resize:none;"></textarea>
                
                <button onclick="copyPixKey()" style="display:block; margin:8px auto 0; background:none; border:none; color:var(--primary-color); cursor:pointer; font-size:0.8rem; font-weight:bold;">
                    <i class="far fa-copy"></i> Copiar Código
                </button>
            </div>
        </div>
        
        <button class="btn-primary" onclick="closePix()" style="width:100%; margin-top:10px;">Fechar Janela</button>
    </div>
</div>

<script>
function abrirPix(paymentId, val) {
    // 1. Abre o modal em estado de carregamento
    document.getElementById('pixModal').style.display = 'flex';
    document.getElementById('pixLoading').style.display = 'block';
    document.getElementById('pixContent').style.display = 'none';
    document.getElementById('pixVal').innerText = 'R$ ' + val;

    // 2. Faz a requisição AJAX
    fetch('index.php?action=get_pix&pid=' + paymentId)
        .then(response => response.json())
        .then(data => {
            document.getElementById('pixLoading').style.display = 'none';
            
            if (data.success) {
                // 3. Exibe os dados
                document.getElementById('pixContent').style.display = 'block';
                document.getElementById('pixQrImage').src = 'data:image/png;base64,' + data.qr_image_base64;
                document.getElementById('pixKeyText').value = data.copia_e_cola;
                // REMOVIDA A LÓGICA DE EXIBIÇÃO DA BADGE
            } else {
                alert("Erro ao gerar PIX: " + data.error);
                closePix();
            }
        })
        .catch(err => {
            console.error(err);
            alert("Erro de conexão. Tente novamente.");
            closePix();
        });
}

function closePix() { document.getElementById('pixModal').style.display = 'none'; }

function copyPixKey() {
    const keyText = document.getElementById('pixKeyText');
    keyText.select();
    keyText.setSelectionRange(0, 99999); 
    navigator.clipboard.writeText(keyText.value).then(() => { alert("Código copiado!"); });
}
</script>

<?php include '../includes/student_footer.php'; ?>