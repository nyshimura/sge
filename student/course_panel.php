<?php
// student/course_panel.php

// --- LÓGICA AJAX PARA GERAR PIX ---
if (isset($_GET['action']) && $_GET['action'] === 'get_pix' && isset($_GET['pid'])) {
    if (session_status() == PHP_SESSION_NONE) session_start();
    require_once '../config/database.php';
    header('Content-Type: application/json');
    
    // Importa o motor (ajuste o caminho se necessário)
    if (file_exists('../includes/pix_engine.php')) {
        require_once '../includes/pix_engine.php';
        $response = processPixRequest((int)$_GET['pid'], $_SESSION['user_id'], $pdo);
        echo json_encode($response);
    } else {
        echo json_encode(['success' => false, 'error' => 'Motor PIX não encontrado.']);
    }
    exit;
}
// --- FIM DA LÓGICA AJAX ---

include '../includes/student_header.php';

// Recebe o ID do CURSO
$courseId = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
$studentId = $_SESSION['user_id'];

if (!$courseId) {
    echo "<div class='card-box'>Curso não especificado.</div>";
    include '../includes/student_footer.php';
    exit;
}

// 1. Busca Dados da Matrícula
$sql = "SELECT e.status as enrollStatus, e.enrollmentDate, 
                e.contractAcceptedAt, e.termsAcceptedAt, e.customDueDay,
                c.id as realCourseId, c.name, c.description, c.thumbnail, c.monthlyFee 
        FROM enrollments e
        JOIN courses c ON e.courseId = c.id
        WHERE e.courseId = :cid AND e.studentId = :sid
        LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([':cid' => $courseId, ':sid' => $studentId]);
$enroll = $stmt->fetch();

if (!$enroll) {
    echo "<div class='card-box'>Matrícula não encontrada.</div>";
    include '../includes/student_footer.php';
    exit;
}

$pageTitle = $enroll['name'];

// 2. Busca Financeiro
$financials = [];
try {
    $stmtPay = $pdo->prepare("SELECT * FROM payments WHERE studentId = :sid AND courseId = :cid ORDER BY dueDate ASC");
    $stmtPay->execute([':sid' => $studentId, ':cid' => $courseId]);
    $financials = $stmtPay->fetchAll();
} catch (Exception $e) { $financials = []; }

// 3. Busca Presença
try {
    $checkAtt = $pdo->query("SHOW TABLES LIKE 'attendance'");
    if($checkAtt->rowCount() > 0) {
        $stmtAtt = $pdo->prepare("SELECT * FROM attendance WHERE courseId = :cid AND studentId = :sid ORDER BY date DESC");
        $stmtAtt->execute([':cid' => $courseId, ':sid' => $studentId]);
        $attendance = $stmtAtt->fetchAll();
    } else { $attendance = []; }
} catch (Exception $e) { $attendance = []; }

// 4. Busca Termos de Eventos (COM STATUS DE RESPOSTA)
$sqlEvents = "
    SELECT t.id, t.title, t.content, t.created_at,
           r.status as response_status, r.responded_at
    FROM event_terms t
    LEFT JOIN event_term_responses r ON t.id = r.term_id AND r.studentId = :sid
    WHERE t.courseId = :cid
    ORDER BY t.created_at DESC
";
$stmtEvt = $pdo->prepare($sqlEvents);
$stmtEvt->execute([':cid' => $courseId, ':sid' => $studentId]);
$courseEvents = $stmtEvt->fetchAll();


// Cálculos de Frequência
$totalClasses = count($attendance);
$presentClasses = 0;
if ($totalClasses > 0) {
    foreach($attendance as $a) { 
        if($a['status'] != 'Ausente') $presentClasses++; 
    }
    $percentage = round(($presentClasses / $totalClasses) * 100);
} else { $percentage = 0; }
?>

<style>
    /* Estilos Locais Extras */
    .btn-back-top { display: inline-flex; align-items: center; gap: 5px; text-decoration: none; color: #555; margin-bottom: 15px; font-weight: 600; }
    .status-badge { padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold; color: white; }
    
    /* Tabela Simples */
    .simple-table { width: 100%; border-collapse: collapse; }
    .simple-table th, .simple-table td { padding: 12px 10px; border-bottom: 1px solid #eee; text-align: left; }
    .simple-table th { color: #777; font-size: 0.85rem; text-transform: uppercase; }
</style>

<a href="my_courses.php" class="btn-back-top"><i class="fas fa-arrow-left"></i> Voltar</a>

<div class="card-box course-header-card">
    <div class="ch-thumb">
        <?php if($enroll['thumbnail']): ?>
            <img src="<?php echo $enroll['thumbnail']; ?>">
        <?php else: ?>
            <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#aaa;"><i class="fas fa-image fa-2x"></i></div>
        <?php endif; ?>
    </div>
    
    <div class="ch-info">
        <h2 style="margin:0 0 5px 0; color:#333; font-size: 1.3rem;"><?php echo htmlspecialchars($enroll['name']); ?></h2>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <span style="color:#666; font-size: 0.85rem;"><i class="far fa-calendar-alt"></i> Início: <?php echo date('d/m/Y', strtotime($enroll['enrollmentDate'])); ?></span>
            <?php 
                $badgeColor = '#27ae60';
                if($enroll['enrollStatus'] == 'Pendente') $badgeColor = '#f39c12';
                if($enroll['enrollStatus'] == 'Cancelado') $badgeColor = '#e74c3c';
                if($enroll['enrollStatus'] == 'Concluido') $badgeColor = '#3498db';
            ?>
            <span class="status-badge" style="background:<?php echo $badgeColor; ?>;"><?php echo ucfirst($enroll['enrollStatus']); ?></span>
        </div>
    </div>
    
    <div class="ch-stats">
        <div style="font-size:1.8rem; font-weight:bold; color:var(--primary-color); line-height: 1;"><?php echo $percentage; ?>%</div>
        <div style="font-size:0.75rem; color:#777;">Frequência</div>
    </div>
</div>

<div class="tabs">
    <button class="tab-btn active" onclick="openTab(event, 'tabFrequency')"><i class="fas fa-user-check"></i> Frequência</button>
    <button class="tab-btn" onclick="openTab(event, 'tabFinance')"><i class="fas fa-file-invoice-dollar"></i> Financeiro</button>
    <button class="tab-btn" onclick="openTab(event, 'tabDocs')"><i class="fas fa-file-contract"></i> Documentos</button>
    <?php if($enroll['enrollStatus'] == 'Concluido'): ?>
        <button class="tab-btn" onclick="openTab(event, 'tabCert')"><i class="fas fa-certificate"></i> Certificado</button>
    <?php endif; ?>
</div>

<div id="tabFrequency" class="tab-content active">
    <div class="card-box">
        <h3 style="margin-top:0; font-size:1.1rem;">Histórico de Presença</h3>
        <?php if(count($attendance) == 0): ?>
            <p style="color:#777; text-align: center; padding: 20px;">Nenhum registro encontrado.</p>
        <?php else: ?>
            <div class="attendance-grid">
                <?php foreach($attendance as $att): 
                    $classStyle = 'att-present'; $icon = 'check';
                    if($att['status'] == 'Ausente') { $classStyle = 'att-absent'; $icon = 'times'; }
                    if($att['status'] == 'Justificado') { $classStyle = 'att-justified'; $icon = 'info-circle'; }
                ?>
                    <div class="attendance-day <?php echo $classStyle; ?>" title="<?php echo $att['status']; ?>">
                        <div style="font-size:0.75rem; font-weight:bold;"><?php echo date('d/m', strtotime($att['date'])); ?></div>
                        <div style="font-size:1.2rem; margin:5px 0;"><i class="fas fa-<?php echo $icon; ?>"></i></div>
                        <div style="font-size:0.65rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo $att['status']; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="tabFinance" class="tab-content">
    <div class="card-box">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; font-size:1.1rem;">Extrato Financeiro</h3>
            <div style="font-size:0.8rem; color:#666; background:#f5f5f5; padding:5px 10px; border-radius:4px;">Vencimento padrão: <strong>Dia <?php echo $enroll['customDueDay'] ?: '10'; ?></strong></div>
        </div>

        <?php if(empty($financials)): ?>
            <div style="text-align:center; padding:30px; background:#f9f9f9; border-radius:12px;"><p style="color:#999;">Nenhuma cobrança gerada.</p></div>
        <?php else: ?>
            <div class="finance-list">
                <?php foreach($financials as $pay): 
                    $statusClass = 'status-pendente'; $statusLabel = $pay['status'];
                    $isLate = (strtotime($pay['dueDate']) < time() && $pay['status'] == 'Pendente');
                    if($pay['status'] == 'Pago') $statusClass = 'status-pago';
                    elseif($pay['status'] == 'Cancelado') $statusClass = 'status-cancelado';
                    elseif($isLate) { $statusClass = 'status-atrasado'; $statusLabel = 'Atrasado'; }
                ?>
                <div class="finance-item">
                    <div class="finance-info">
                        <span class="date">Vencimento: <?php echo date('d/m/Y', strtotime($pay['dueDate'])); ?></span>
                        <span class="ref">Mensalidade - <?php echo date('m/Y', strtotime($pay['referenceDate'])); ?></span>
                    </div>
                    <div class="finance-status-box">
                        <span class="finance-amount">R$ <?php echo number_format($pay['amount'], 2, ',', '.'); ?></span>
                        <span class="finance-status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                        <?php if($pay['status'] == 'Pendente'): ?>
                            <button class="btn-pix-small" onclick="abrirPix(<?php echo $pay['id']; ?>, '<?php echo number_format($pay['amount'], 2, ',', '.'); ?>')"><i class="fas fa-qrcode"></i> Pagar</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="tabDocs" class="tab-content">
    <div class="card-box">
        <h3 style="margin-top:0; font-size:1.1rem; margin-bottom: 20px;">Documentos e Contratos</h3>
        
        <h4 style="font-size: 0.95rem; color: #555; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-bottom: 10px;">Matrícula</h4>
        <div class="table-responsive">
            <table class="simple-table">
                <thead><tr><th>Documento</th><th>Data Assinatura</th><th>Status</th><th>Ação</th></tr></thead>
                <tbody>
                    <tr>
                        <td><i class="fas fa-file-contract" style="color:var(--primary-color);"></i> Contrato</td>
                        <td><?php echo $enroll['contractAcceptedAt'] ? date('d/m/y H:i', strtotime($enroll['contractAcceptedAt'])) : '-'; ?></td>
                        <td>
                            <?php if($enroll['contractAcceptedAt']): ?><span style="color:green; font-weight:bold; font-size:0.8rem;">Assinado</span>
                            <?php else: ?><span style="color:red; font-size:0.8rem;">Pendente</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if($enroll['contractAcceptedAt']): ?>
                                <a href="../includes/generate_contract_pdf.php?cid=<?php echo $courseId; ?>&type=contract" target="_blank" class="btn-primary" style="padding:4px 8px; font-size:0.7rem;"><i class="fas fa-file-pdf"></i> PDF</a>
                            <?php else: ?> - <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><i class="fas fa-camera" style="color:var(--primary-color);"></i> Uso de Imagem</td>
                        <td><?php echo $enroll['termsAcceptedAt'] ? date('d/m/y H:i', strtotime($enroll['termsAcceptedAt'])) : '-'; ?></td>
                        <td>
                            <?php if($enroll['termsAcceptedAt']): ?><span style="color:green; font-weight:bold; font-size:0.8rem;">Aceito</span>
                            <?php else: ?><span style="color:red; font-size:0.8rem;">Pendente</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if($enroll['termsAcceptedAt']): ?>
                                <a href="../includes/generate_contract_pdf.php?cid=<?php echo $courseId; ?>&type=terms" target="_blank" class="btn-primary" style="padding:4px 8px; font-size:0.7rem;"><i class="fas fa-file-pdf"></i> PDF</a>
                            <?php else: ?> - <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if(count($courseEvents) > 0): ?>
            <h4 style="font-size: 0.95rem; color: #555; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-bottom: 10px; margin-top: 30px;">
                Eventos e Compromissos
            </h4>
            <div class="table-responsive">
                <table class="simple-table">
                    <thead><tr><th>Evento (Clique para ler)</th><th>Data Resposta</th><th>Sua Resposta</th></tr></thead>
                    <tbody>
                        <?php foreach($courseEvents as $evt): ?>
                            <tr>
                                <td>
                                    <a href="javascript:void(0)" onclick='verTermoHistorico(<?php echo htmlspecialchars(json_encode($evt), ENT_QUOTES, 'UTF-8'); ?>)' style="text-decoration:none; color:inherit;">
                                        <strong style="color:#3498db; cursor:pointer;">
                                            <i class="fas fa-search-plus"></i> <?php echo htmlspecialchars($evt['title']); ?>
                                        </strong>
                                    </a>
                                    <br>
                                    <small style="color:#777;">Criado em: <?php echo date('d/m/Y', strtotime($evt['created_at'])); ?></small>
                                </td>
                                <td>
                                    <?php echo $evt['responded_at'] ? date('d/m/y H:i', strtotime($evt['responded_at'])) : '-'; ?>
                                </td>
                                <td>
                                    <?php if($evt['response_status'] == 'accepted'): ?>
                                        <span style="color:#27ae60; font-weight:bold; background:#e8f5e9; padding:2px 6px; border-radius:4px; font-size:0.8rem;">
                                            <i class="fas fa-check-circle"></i> Aceito
                                        </span>
                                    <?php elseif($evt['response_status'] == 'declined'): ?>
                                        <span style="color:#c0392b; font-weight:bold; background:#fdedec; padding:2px 6px; border-radius:4px; font-size:0.8rem;">
                                            <i class="fas fa-times-circle"></i> Recusado
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#f39c12; font-weight:bold; background:#fef9e7; padding:2px 6px; border-radius:4px; font-size:0.8rem;">
                                            <i class="fas fa-clock"></i> Pendente
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
</div>

<div id="tabCert" class="tab-content">
    <div class="card-box" style="text-align:center; padding:40px;">
        <i class="fas fa-award fa-4x" style="color:#f39c12; margin-bottom:20px;"></i>
        <h3>Certificado Disponível</h3>
        <p>Parabéns pela conclusão do curso!</p>
        <button class="btn-primary" style="padding:10px 20px; font-size:1rem; margin-top:10px;"><i class="fas fa-download"></i> Baixar PDF</button>
    </div>
</div>

<div id="pixModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center;">
    <div class="card-box" style="max-width:400px; width:95%; text-align:center; border-top: 5px solid #32bcad;">
        <h3 style="margin-top:0; color:#2c3e50;">Pagamento via PIX</h3>
        <div id="pixLoading">
            <div style="border: 4px solid #f3f3f3; border-top: 4px solid #32bcad; border-radius: 50%; width: 30px; height: 30px; animation: spin 1s linear infinite; margin: 20px auto;"></div>
            <p>Gerando QR Code...</p>
            <style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
        </div>
        <div id="pixContent" style="display:none;">
            <p style="color:#7f8c8d; margin-bottom:15px;">Escaneie o código abaixo</p>
            <div style="background:#fff; padding:15px; border-radius:12px; display:inline-block; border:1px solid #eee; margin-bottom:15px;">
                <img src="" id="pixQrImage" style="width:200px; height:200px; display:block;" alt="QR Code PIX">
            </div>
            <p style="font-size:1.1rem; font-weight:bold; color:#333; margin:5px 0;">Valor: <span id="pixVal" style="color:#27ae60;"></span></p>
            <div style="background:#f4f6f9; padding:12px; border-radius:8px; margin:15px 0; border:1px dashed #ccc;">
                <small style="color:#666; display:block; margin-bottom:5px; font-weight:bold; text-transform:uppercase;">Copia e Cola:</small>
                <textarea id="pixKeyText" readonly style="width:100%; height:70px; font-size:0.8rem; border:1px solid #ddd; border-radius:4px; padding:5px; resize:none;"></textarea>
                <button onclick="copyPixKey()" style="display:block; margin:8px auto 0; background:none; border:none; color:var(--primary-color); cursor:pointer; font-size:0.8rem; font-weight:bold;"><i class="far fa-copy"></i> Copiar Código</button>
            </div>
        </div>
        <button class="btn-primary" onclick="closePix()" style="width:100%; margin-top:10px;">Fechar</button>
    </div>
</div>

<div id="historyModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(2px); padding:20px; box-sizing:border-box;">
    <div class="card-box" style="background:#fff; border-radius:10px; max-width:600px; width:100%; position:relative; display:flex; flex-direction:column; max-height: 90vh; padding: 0; overflow: hidden;">
        
        <div style="padding: 15px; border-bottom: 1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
            <h3 id="histTitle" style="margin:0; font-size:1.1rem; color:#333;">Termo de Compromisso</h3>
            <span style="cursor:pointer; font-size:1.5rem;" onclick="closeHistory()">&times;</span>
        </div>

        <div style="padding: 20px; overflow-y: auto; background:#f9f9f9; flex-grow:1;">
            <div id="histStatusBanner" style="padding:10px; border-radius:6px; margin-bottom:15px; font-weight:bold; text-align:center; display:none;"></div>
            
            <div id="histContent" style="white-space: pre-wrap; font-family:sans-serif; line-height:1.6; color:#333; background:white; padding:15px; border:1px solid #eee; border-radius:4px;"></div>
        </div>

        <div id="histFooterRead" style="padding: 15px; border-top: 1px solid #eee; text-align: right; display:none;">
            <button class="btn-primary" onclick="closeHistory()" style="padding:8px 20px; cursor:pointer;">Fechar</button>
        </div>

        <form action="actions/respond_term.php" method="POST" id="histFooterAction" style="padding: 15px; border-top: 1px solid #eee; display:none; justify-content:flex-end; gap:10px; background: white;">
            <input type="hidden" name="term_id" id="histTermId">
            <button type="submit" name="response" value="declined" style="background:white; color:#dc3545; border:1px solid #dc3545; padding:10px 20px; border-radius:4px; cursor:pointer;">Recusar</button>
            <button type="submit" name="response" value="accepted" style="background:#28a745; color:white; border:none; padding:10px 20px; border-radius:4px; cursor:pointer; font-weight:bold;">Aceitar</button>
        </form>

    </div>
</div>

<script>
// --- Função Abas ---
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) { tabcontent[i].style.display = "none"; tabcontent[i].classList.remove("active"); }
    tablinks = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tablinks.length; i++) { tablinks[i].className = tablinks[i].className.replace(" active", ""); }
    document.getElementById(tabName).style.display = "block";
    document.getElementById(tabName).classList.add("active");
    if(evt) evt.currentTarget.className += " active";
}

// --- Função Modal Histórico/Leitura ---
function verTermoHistorico(evt) {
    const modal = document.getElementById('historyModal');
    const title = document.getElementById('histTitle');
    const content = document.getElementById('histContent');
    const banner = document.getElementById('histStatusBanner');
    const footerRead = document.getElementById('histFooterRead');
    const footerAction = document.getElementById('histFooterAction');
    const inputId = document.getElementById('histTermId');

    // Preenche dados
    title.innerText = evt.title;
    content.innerText = evt.content;
    inputId.value = evt.id;

    // Configura o visual baseada no status
    if (evt.response_status === 'accepted') {
        banner.style.display = 'block';
        banner.style.background = '#d4edda';
        banner.style.color = '#155724';
        banner.innerHTML = '<i class="fas fa-check-circle"></i> Você ACEITOU este termo em ' + formatDate(evt.responded_at);
        footerRead.style.display = 'block';
        footerAction.style.display = 'none';

    } else if (evt.response_status === 'declined') {
        banner.style.display = 'block';
        banner.style.background = '#f8d7da';
        banner.style.color = '#721c24';
        banner.innerHTML = '<i class="fas fa-times-circle"></i> Você RECUSOU este termo em ' + formatDate(evt.responded_at);
        footerRead.style.display = 'block';
        footerAction.style.display = 'none';

    } else {
        // Pendente
        banner.style.display = 'block';
        banner.style.background = '#fff3cd';
        banner.style.color = '#856404';
        banner.innerHTML = '<i class="fas fa-clock"></i> Pendente de resposta.';
        footerRead.style.display = 'none';
        footerAction.style.display = 'flex';
    }

    modal.style.display = 'flex';
}

function closeHistory() {
    document.getElementById('historyModal').style.display = 'none';
}

// --- Função Formatar Data ---
function formatDate(dateString) {
    if(!dateString) return '';
    const d = new Date(dateString);
    return d.toLocaleDateString('pt-BR') + ' às ' + d.toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});
}

// --- Função PIX ---
function abrirPix(paymentId, val) {
    document.getElementById('pixModal').style.display = 'flex';
    document.getElementById('pixLoading').style.display = 'block';
    document.getElementById('pixContent').style.display = 'none';
    document.getElementById('pixVal').innerText = 'R$ ' + val;
    fetch('course_panel.php?action=get_pix&pid=' + paymentId + '&cid=<?php echo $courseId; ?>')
        .then(response => response.json())
        .then(data => {
            document.getElementById('pixLoading').style.display = 'none';
            if (data.success) {
                document.getElementById('pixContent').style.display = 'block';
                document.getElementById('pixQrImage').src = 'data:image/png;base64,' + data.qr_image_base64;
                document.getElementById('pixKeyText').value = data.copia_e_cola;
            } else { alert("Erro ao gerar PIX: " + data.error); closePix(); }
        })
        .catch(err => { console.error(err); alert("Erro de conexão."); closePix(); });
}
function closePix() { document.getElementById('pixModal').style.display = 'none'; }
function copyPixKey() {
    const keyText = document.getElementById('pixKeyText');
    keyText.select(); keyText.setSelectionRange(0, 99999); 
    navigator.clipboard.writeText(keyText.value).then(() => { alert("Código copiado!"); });
}

// Fecha modais ao clicar fora
window.onclick = function(e) {
    if (e.target == document.getElementById('pixModal')) closePix();
    if (e.target == document.getElementById('historyModal')) closeHistory();
}
</script>

<?php include '../includes/student_footer.php'; ?>