<?php
// admin/system_settings.php

// 1. INICIALIZAÇÃO
ob_start(); 
session_start();

require_once '../config/database.php';
include '../includes/admin_header.php';

// Garante permissão
checkRole(['admin', 'superadmin']);

// Inclui a lógica de renovação anual
require_once '../includes/annual_renewal_logic.php';

$msg = '';
$renewalResult = null; // Variável exclusiva para o Modal

// --- 2. PROCESSAMENTO DE REQUISIÇÕES ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // A. Adicionar Recesso
    if (isset($_POST['add_recess'])) {
        $rName = $_POST['recess_name']; $rStart = $_POST['recess_start']; $rEnd = $_POST['recess_end'];
        if (!empty($rName) && !empty($rStart) && !empty($rEnd)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO school_recess (name, start_date, end_date) VALUES (:name, :start, :end)");
                $stmt->execute([':name' => $rName, ':start' => $rStart, ':end' => $rEnd]);
                $msg = '<div class="alert alert-success">Recesso adicionado!</div>';
            } catch (PDOException $e) { $msg = '<div class="alert alert-danger">Erro: ' . $e->getMessage() . '</div>'; }
        }
    }
    
    // B. Excluir Recesso
    elseif (isset($_POST['delete_recess_id'])) {
        try {
            $pdo->prepare("DELETE FROM school_recess WHERE id = ?")->execute([(int)$_POST['delete_recess_id']]);
            $msg = '<div class="alert alert-success">Recesso removido!</div>';
        } catch (PDOException $e) { $msg = '<div class="alert alert-danger">Erro: ' . $e->getMessage() . '</div>'; }
    }

    // C. GERAR NOVO ANO LETIVO (Totalmente Isolado)
    elseif (isset($_POST['action']) && $_POST['action'] === 'generate_year') {
        $targetYear = (int)$_POST['renew_year'];
        $renewalResult = generateAnnualInvoices($pdo, $targetYear);
    }

    // D. SALVAR CONFIGURAÇÕES GERAIS
    elseif (isset($_POST['action']) && $_POST['action'] === 'save_settings') {
        try {
            // 1. Atualiza System Settings (Configurações Gerais)
            $fields = [
                'site_url', 'language', 'timeZone', 'currencySymbol',
                'smtpServer', 'smtpPort', 'smtpUser', 'smtpPass', 
                'email_approval_subject', 'email_approval_body', 
                'email_reset_subject', 'email_reset_body',
                'email_reminder_subject', 'email_reminder_body',
                
                // Contratos
                'enrollmentContractText', 
                'term_text_adult', 
                'term_text_minor', 
                'certificate_template_text', 
                'imageTermsText', 
                
                'geminiApiKey', 'geminiApiEndpoint',
                'dbHost', 'dbUser', 'dbPass', 'dbName', 'dbPort',
                
                // Pagamentos (APIs)
                'mp_public_key', 'mp_access_token', 'mp_client_id', 'mp_client_secret',
                'inter_client_id', 'inter_client_secret'
            ];
            
            $sql = "UPDATE system_settings SET ";
            $params = [];
            foreach ($fields as $field) { $sql .= "$field = :$field, "; $params[":$field"] = $_POST[$field] ?? ''; }
            
            // Checkboxes
            $sql .= "enableTerminationFine = :enableTerminationFine, ";
            $params[':enableTerminationFine'] = isset($_POST['enableTerminationFine']) ? 1 : 0;
            
            $sql .= "mp_active = :mp_active, ";
            $params[':mp_active'] = isset($_POST['mp_active']) ? 1 : 0;

            $sql .= "inter_active = :inter_active, ";
            $params[':inter_active'] = isset($_POST['inter_active']) ? 1 : 0;

            $sql .= "inter_sandbox = :inter_sandbox, ";
            $params[':inter_sandbox'] = isset($_POST['inter_sandbox']) ? 1 : 0;

            // Campos Numéricos
            $sql .= "terminationFineMonths = :terminationFineMonths, ";
            $params[':terminationFineMonths'] = (int)($_POST['terminationFineMonths'] ?? 0);
            
            $sql .= "defaultDueDay = :defaultDueDay, ";
            $params[':defaultDueDay'] = (int)($_POST['defaultDueDay'] ?? 10);
            
            $sql .= "reminderDaysBefore = :reminderDaysBefore "; 
            $params[':reminderDaysBefore'] = (int)($_POST['reminderDaysBefore'] ?? 3);

            // Upload Imagem Certificado (Visual)
            if (isset($_FILES['certificate_bg']) && $_FILES['certificate_bg']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $ext = strtolower(pathinfo($_FILES['certificate_bg']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed)) {
                    $data = file_get_contents($_FILES['certificate_bg']['tmp_name']);
                    $sql .= ", certificate_background_image = :cert_img";
                    $params[':cert_img'] = 'data:image/' . $ext . ';base64,' . base64_encode($data);
                }
            }

            // --- UPLOAD CERTIFICADOS INTER (CRT, KEY e WEBHOOK CA) ---
            $certDir = __DIR__ . '/../certs/';
            if (!is_dir($certDir)) mkdir($certDir, 0755, true);

            $ambiente = isset($_POST['inter_sandbox']) ? 'Sandbox' : 'Prod';

            // Arquivo .crt (Aplicação)
            if (isset($_FILES['inter_cert_file']) && $_FILES['inter_cert_file']['error'] == 0) {
                $fName = 'Inter_' . $ambiente . '_' . time() . '.crt'; 
                if (move_uploaded_file($_FILES['inter_cert_file']['tmp_name'], $certDir . $fName)) {
                    $sql .= ", inter_cert_file = :inter_crt";
                    $params[':inter_crt'] = $fName;
                }
            }
            // Arquivo .key (Chave Privada)
            if (isset($_FILES['inter_key_file']) && $_FILES['inter_key_file']['error'] == 0) {
                $fName = 'Inter_' . $ambiente . '_' . time() . '.key';
                if (move_uploaded_file($_FILES['inter_key_file']['tmp_name'], $certDir . $fName)) {
                    $sql .= ", inter_key_file = :inter_key";
                    $params[':inter_key'] = $fName;
                }
            }
            // Arquivo ca.crt (Webhook)
            if (isset($_FILES['inter_webhook_crt']) && $_FILES['inter_webhook_crt']['error'] == 0) {
                $fName = 'Inter_Webhook_CA_' . time() . '.crt';
                if (move_uploaded_file($_FILES['inter_webhook_crt']['tmp_name'], $certDir . $fName)) {
                    $sql .= ", inter_webhook_crt = :webhook_crt";
                    $params[':webhook_crt'] = $fName;
                }
            }

            $sql .= " WHERE id = 1"; 
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // 2. Atualiza School Profile (Chave Pix Manual)
            if (isset($_POST['manual_pix_key'])) {
                $updSchool = $pdo->prepare("UPDATE school_profile SET pixKey = ? WHERE id = 1");
                $updSchool->execute([$_POST['manual_pix_key']]);
            }

            $msg = '<div class="alert alert-success">Configurações salvas com sucesso!</div>';
        } catch (PDOException $e) { $msg = '<div class="alert alert-danger">Erro: ' . $e->getMessage() . '</div>'; }
    }
}

// Carregar Dados
$settings = $pdo->query("SELECT * FROM system_settings WHERE id = 1")->fetch();
if (!$settings) {
    $pdo->query("INSERT INTO system_settings (id, site_url) VALUES (1, 'http://localhost')");
    $settings = $pdo->query("SELECT * FROM system_settings WHERE id = 1")->fetch();
}

$schoolProfile = $pdo->query("SELECT * FROM school_profile WHERE id = 1")->fetch();
$recessList = $pdo->query("SELECT * FROM school_recess ORDER BY start_date DESC")->fetchAll();

// Placeholders
$placeholders = [
    'email_approval' => ['Geral' => ['{{aluno_nome}}', '{{responsavel_nome}}', '{{curso_nome}}', '{{link_contrato}}']],
    'email_reset' => ['Geral' => ['{{user_name}}', '{{reset_link}}']],
    'email_finance' => ['Geral' => ['{{aluno_nome}}', '{{responsavel_nome}}', '{{curso_nome}}', '{{valor}}', '{{vencimento}}', '{{pix_copia_cola}}']],
    'contract' => ['Geral' => ['{{aluno_nome}}', '{{aluno_cpf}}', '{{curso_nome}}', '{{curso_valor}}']]
];

function renderToolbar($targetId) {
    echo '<div class="editor-toolbar">
            <button type="button" class="editor-btn" onclick="wrapText(\''.$targetId.'\', \'<b>\', \'</b>\')"><b>B</b></button>
            <button type="button" class="editor-btn" onclick="wrapText(\''.$targetId.'\', \'<i>\', \'</i>\')"><i>I</i></button>
            <button type="button" class="editor-btn" onclick="insertTagAtCursor(\''.$targetId.'\', \'<br>\')">BR</button>
            <button type="button" class="editor-btn" onclick="wrapText(\''.$targetId.'\', \'<p>\', \'</p>\')">P</button>
          </div>';
}
?>

<div class="content-wrapper">
    <div style="margin-bottom: 20px;">
        <h2 style="margin:0; color:#2c3e50;">Configurações</h2>
    </div>

    <?php echo $msg; ?>

    <div class="settings-tabs">
        <button class="settings-tab-link active" onclick="openTab(event, 'tab-geral')"><i class="fas fa-sliders-h"></i> Geral</button>
        <button class="settings-tab-link" onclick="openTab(event, 'tab-calendario')"><i class="far fa-calendar-alt"></i> Calendário</button>
        <button class="settings-tab-link" onclick="openTab(event, 'tab-email')"><i class="fas fa-envelope"></i> E-mail</button>
        <button class="settings-tab-link" onclick="openTab(event, 'tab-docs')"><i class="fas fa-file-contract"></i> Docs</button>
        <button class="settings-tab-link" onclick="openTab(event, 'tab-fin')"><i class="fas fa-wallet"></i> Financeiro</button>
        <button class="settings-tab-link" onclick="openTab(event, 'tab-pagamentos')"><i class="fas fa-hand-holding-usd"></i> Pagamentos</button>
        <button class="settings-tab-link" onclick="openTab(event, 'tab-system')"><i class="fas fa-sync"></i> Sistema</button>
    </div>

    <form method="POST" action="" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save_settings">
        
        <div id="tab-geral" class="tab-content active">
            <div class="section-block">
                <h4 class="section-title"><i class="fas fa-globe"></i> Dados Básicos</h4>
                <div class="settings-grid"> <div class="span-2">
                        <div class="form-group">
                            <label>URL do Sistema</label>
                            <input type="text" name="site_url" class="form-control" value="<?php echo htmlspecialchars($settings['site_url']); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Idioma</label>
                        <select name="language" class="form-control">
                            <option value="pt-BR" <?php echo $settings['language']=='pt-BR'?'selected':''; ?>>Português (BR)</option>
                            <option value="en-US" <?php echo $settings['language']=='en-US'?'selected':''; ?>>English (US)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Fuso Horário</label>
                        <input type="text" name="timeZone" class="form-control" value="<?php echo htmlspecialchars($settings['timeZone']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Moeda</label>
                        <input type="text" name="currencySymbol" class="form-control" value="<?php echo htmlspecialchars($settings['currencySymbol']); ?>">
                    </div>
                </div>
                <div style="text-align: right;">
                    <button type="submit" class="btn-save btn-primary">Salvar Alterações</button>
                </div>
            </div>
        </div>

        <div id="tab-email" class="tab-content">
            <div class="section-block">
                <h4 class="section-title"><i class="fas fa-server"></i> Servidor SMTP</h4>
                <div class="settings-grid">
                    <div class="form-group">
                        <label>Host SMTP</label>
                        <input type="text" name="smtpServer" class="form-control" value="<?php echo htmlspecialchars($settings['smtpServer']); ?>" placeholder="smtp.gmail.com">
                    </div>
                    <div class="form-group">
                        <label>Porta</label>
                        <input type="number" name="smtpPort" class="form-control" value="<?php echo htmlspecialchars($settings['smtpPort']); ?>" placeholder="587">
                    </div>
                    <div class="form-group">
                        <label>Usuário / E-mail</label>
                        <input type="text" name="smtpUser" class="form-control" value="<?php echo htmlspecialchars($settings['smtpUser']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Senha</label>
                        <input type="password" name="smtpPass" class="form-control" value="<?php echo htmlspecialchars($settings['smtpPass']); ?>">
                    </div>
                </div>
            </div>

            <div class="section-block">
                <h4 class="section-title"><i class="fas fa-envelope-open-text"></i> Modelos de E-mail</h4>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight:bold; color:#3498db;">Aprovação de Matrícula - Assunto</label>
                    <input type="text" name="email_approval_subject" class="form-control" value="<?php echo htmlspecialchars($settings['email_approval_subject']); ?>">
                </div>
                <div class="form-group">
                    <label>Mensagem</label>
                    <?php renderToolbar('email_approval_body'); ?>
                    <textarea id="email_approval_body" name="email_approval_body" class="form-control has-toolbar" style="height:150px;"><?php echo htmlspecialchars($settings['email_approval_body']); ?></textarea>
                </div>

                <hr style="border:0; border-top:1px solid #eee; margin: 20px 0;">

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="font-weight:bold; color:#3498db;">Recuperação de Senha - Assunto</label>
                    <input type="text" name="email_reset_subject" class="form-control" value="<?php echo htmlspecialchars($settings['email_reset_subject']); ?>">
                </div>
                <div class="form-group">
                    <label>Mensagem</label>
                    <?php renderToolbar('email_reset_body'); ?>
                    <textarea id="email_reset_body" name="email_reset_body" class="form-control has-toolbar" style="height:150px;"><?php echo htmlspecialchars($settings['email_reset_body']); ?></textarea>
                </div>

                <div style="text-align: right; margin-top: 15px;">
                    <button type="submit" class="btn-save btn-primary">Salvar E-mails</button>
                </div>
            </div>
        </div>

        <div id="tab-docs" class="tab-content">
            <div class="section-block">
                <h4 class="section-title"><i class="fas fa-file-signature"></i> Contrato de Matrícula (Geral)</h4>
                <div class="form-group">
                    <?php renderToolbar('enrollmentContractText'); ?>
                    <textarea id="enrollmentContractText" name="enrollmentContractText" class="form-control has-toolbar" style="height: 300px;"><?php echo htmlspecialchars($settings['enrollmentContractText']); ?></textarea>
                </div>
            </div>

            <div class="section-block" style="margin-top: 20px;">
                <h4 class="section-title"><i class="fas fa-camera"></i> Termos de Uso de Imagem</h4>
                <div class="settings-grid">
                    <div class="span-2">
                        <div class="form-group">
                            <label style="font-weight:bold; color:#27ae60;">Para Alunos Maiores (+18)</label>
                            <?php renderToolbar('term_text_adult'); ?>
                            <textarea id="term_text_adult" name="term_text_adult" class="form-control has-toolbar" style="height: 200px;"><?php echo htmlspecialchars($settings['term_text_adult'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="span-2">
                        <div class="form-group">
                            <label style="font-weight:bold; color:#e67e22;">Para Alunos Menores (-18)</label>
                            <?php renderToolbar('term_text_minor'); ?>
                            <textarea id="term_text_minor" name="term_text_minor" class="form-control has-toolbar" style="height: 200px;"><?php echo htmlspecialchars($settings['term_text_minor'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-block">
                <h4 class="section-title"><i class="fas fa-certificate"></i> Certificado</h4>
                <div class="settings-grid">
                    <div class="span-2">
                        <div class="form-group">
                            <label>Texto Padrão</label>
                            <?php renderToolbar('certificate_template_text'); ?>
                            <textarea id="certificate_template_text" name="certificate_template_text" class="form-control has-toolbar" style="height:150px;"><?php echo htmlspecialchars($settings['certificate_template_text']); ?></textarea>
                        </div>
                    </div>
                    <div class="span-2">
                        <div class="form-group">
                            <label>Imagem de Fundo (A4 Paisagem)</label>
                            <?php if (!empty($settings['certificate_background_image'])): ?>
                                <br><img src="<?php echo $settings['certificate_background_image']; ?>" style="height: 60px; border:1px solid #ccc; margin-bottom:5px;">
                            <?php endif; ?>
                            <input type="file" name="certificate_bg" class="form-control" accept="image/*">
                        </div>
                    </div>
                </div>
                <div style="text-align: right; margin-top: 15px;">
                    <button type="submit" class="btn-save btn-primary">Salvar Documentos</button>
                </div>
            </div>
        </div>

        <div id="tab-fin" class="tab-content">
            <div class="section-block">
                <h4 class="section-title"><i class="fas fa-coins"></i> Regras de Cobrança</h4>
                <div class="settings-grid">
                    <div class="form-group">
                        <label>Dia de Vencimento Padrão</label>
                        <input type="number" name="defaultDueDay" class="form-control" value="<?php echo $settings['defaultDueDay']; ?>" min="1" max="31">
                    </div>
                    <div class="form-group">
                        <label>Dias para Lembrete (Antes)</label>
                        <input type="number" name="reminderDaysBefore" class="form-control" value="<?php echo $settings['reminderDaysBefore']; ?>">
                    </div>
                    <div class="form-group">
                        <label>Meses p/ Multa Cancelamento</label>
                        <input type="number" name="terminationFineMonths" class="form-control" value="<?php echo $settings['terminationFineMonths']; ?>">
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end; padding-bottom: 10px;">
                        <label style="cursor:pointer; display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="enableTerminationFine" value="1" <?php echo $settings['enableTerminationFine'] ? 'checked' : ''; ?> style="width:20px; height:20px;">
                            <span>Habilitar Multa Automática</span>
                        </label>
                    </div>
                </div>
                
                <hr style="margin:25px 0; border:0; border-top:1px solid #eee;">
                <h4 class="section-title"><i class="fas fa-envelope-open-text"></i> Personalizar E-mail de Cobrança</h4>
                
                <div class="form-group">
                    <label style="font-weight:bold; color:#3498db;">Assunto do E-mail</label>
                    <input type="text" name="email_reminder_subject" class="form-control" value="<?php echo htmlspecialchars($settings['email_reminder_subject']); ?>">
                </div>

                <div class="form-group">
                    <label>Corpo do E-mail</label>
                    <div style="margin-bottom:10px; font-size:0.85rem; color:#666;">
                        <strong>Tags disponíveis:</strong>
                        <?php foreach ($placeholders['email_finance']['Geral'] as $tag): ?>
                            <span style="background:#eee; padding:2px 6px; border-radius:4px; margin-right:5px; cursor:pointer;" onclick="insertTagAtCursor('email_reminder_body', '<?php echo $tag; ?>')" title="Inserir <?php echo $tag; ?>"><?php echo $tag; ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php renderToolbar('email_reminder_body'); ?>
                    <textarea id="email_reminder_body" name="email_reminder_body" class="form-control has-toolbar" style="height:200px;"><?php echo htmlspecialchars($settings['email_reminder_body']); ?></textarea>
                </div>
            </div>

            <div class="section-block highlight-card" style="margin-top: 20px;">
                <h4 class="section-title" style="color:#2c3e50; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-calendar-plus" style="color: #3498db;"></i> Ferramentas de Renovação
                </h4>
                <div id="renewLayoutSection">
                    <div style="display: flex; gap: 10px; align-items: flex-end; max-width: 400px;">
                        <div style="flex: 1;">
                            <label style="font-weight: bold; font-size: 0.85rem;">Ano de Referência</label>
                            <input type="number" id="renew_year_input" class="form-control" value="<?php echo date('Y') + 1; ?>" min="2024" max="2050">
                        </div>
                        <button type="button" class="btn-generate" onclick="openRenewModal()">
                            Processar <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div style="text-align: right; margin-top: 15px;">
                <button type="submit" class="btn-save btn-primary">Salvar Regras</button>
            </div>
        </div>

        <div id="tab-pagamentos" class="tab-content">
            
            <div class="section-block" style="border-left: 4px solid #ff7900; background-color: #fffaf0;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <h4 class="section-title" style="color:#ff7900; margin:0;"><i class="fas fa-university"></i> Banco Inter (API v2)</h4>
                        
                        <label class="switch-label" style="font-size:0.8rem; margin-left: 15px;">
                            <input type="checkbox" name="inter_sandbox" value="1" <?php echo $settings['inter_sandbox'] ? 'checked' : ''; ?>>
                            <span class="slider-round" style="width:34px; height:20px;"></span> 
                            <span style="margin-left: 5px; color: #d35400;">Modo Sandbox (Teste)</span>
                        </label>
                        <style>
                            input:checked + .slider-round[style*="width:34px"] { background-color: #e67e22; }
                            input:checked + .slider-round[style*="width:34px"]:before { transform: translateX(14px); }
                        </style>
                    </div>

                    <label class="switch-label">
                        <input type="checkbox" id="check_inter" name="inter_active" value="1" <?php echo $settings['inter_active'] ? 'checked' : ''; ?> onchange="togglePaymentGateway('inter')">
                        <span class="slider-round"></span>
                        <span style="margin-left: 5px;">Ativar Integração</span>
                    </label>
                </div>
                <hr>
                <div class="settings-grid">
                    <div class="form-group"><label>Client ID</label><input type="text" name="inter_client_id" class="form-control" value="<?php echo htmlspecialchars($settings['inter_client_id'] ?? ''); ?>"></div>
                    <div class="form-group"><label>Client Secret</label><input type="password" name="inter_client_secret" class="form-control" value="<?php echo htmlspecialchars($settings['inter_client_secret'] ?? ''); ?>"></div>
                    
                    <div class="form-group">
                        <label>Certificado (.crt)</label>
                        <?php if(!empty($settings['inter_cert_file'])): ?> <small style="color:green;"><i class="fas fa-check"></i> Enviado</small> <?php endif; ?>
                        <input type="file" name="inter_cert_file" class="form-control" accept=".crt">
                    </div>
                    <div class="form-group">
                        <label>Chave (.key)</label>
                        <?php if(!empty($settings['inter_key_file'])): ?> <small style="color:green;"><i class="fas fa-check"></i> Enviado</small> <?php endif; ?>
                        <input type="file" name="inter_key_file" class="form-control" accept=".key">
                    </div>
                    
                    <div class="form-group span-2">
                        <label>Certificado Webhook (ca.crt) <small style="font-weight:normal; color:#666;">(Opcional para mTLS)</small></label>
                        <?php if(!empty($settings['inter_webhook_crt'])): ?> <small style="color:green;"><i class="fas fa-check"></i> Enviado</small> <?php endif; ?>
                        <input type="file" name="inter_webhook_crt" class="form-control" accept=".crt">
                    </div>
                </div>
                <small style="color:#d35400;">* Ao trocar entre Sandbox e Produção, lembre-se de atualizar o Client ID, Secret e reenviar os certificados.</small>
            </div>

            <div class="section-block" style="border-left: 4px solid #009ee3; background-color: #f0faff; margin-top: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h4 class="section-title" style="color:#009ee3; margin:0;"><i class="fas fa-handshake"></i> Mercado Pago</h4>
                    <label class="switch-label">
                        <input type="checkbox" id="check_mp" name="mp_active" value="1" <?php echo $settings['mp_active'] ? 'checked' : ''; ?> onchange="togglePaymentGateway('mp')">
                        <span class="slider-round"></span>
                        <span style="margin-left: 5px;">Ativar Integração</span>
                    </label>
                </div>
                <hr>
                <div class="settings-grid">
                    <div class="form-group"><label>Public Key</label><input type="text" name="mp_public_key" class="form-control" value="<?php echo htmlspecialchars($settings['mp_public_key']); ?>"></div>
                    <div class="form-group"><label>Access Token</label><input type="password" name="mp_access_token" class="form-control" value="<?php echo htmlspecialchars($settings['mp_access_token']); ?>"></div>
                    <div class="form-group"><label>Client ID</label><input type="text" name="mp_client_id" class="form-control" value="<?php echo htmlspecialchars($settings['mp_client_id']); ?>"></div>
                    <div class="form-group"><label>Client Secret</label><input type="password" name="mp_client_secret" class="form-control" value="<?php echo htmlspecialchars($settings['mp_client_secret']); ?>"></div>
                </div>
            </div>

            <div class="section-block" style="border-left: 4px solid #7f8c8d; margin-top: 20px;">
                <h4 class="section-title" style="color:#7f8c8d;"><i class="fas fa-qrcode"></i> Pix Manual / Estático</h4>
                <div class="form-group">
                    <label>Chave Pix (CPF, CNPJ, Email ou Aleatória)</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($schoolProfile['pixKey'] ?? ''); ?>" readonly style="background-color: #e9ecef; cursor: not-allowed; color: #555;">
                    <small style="color:#666; font-size:0.85rem; margin-top:5px; display:block;"><i class="fas fa-lock"></i> Gerenciado no menu <strong>Perfil da Escola</strong>.</small>
                </div>
            </div>

            <div style="text-align: right; margin-top: 15px;">
                <button type="submit" class="btn-save btn-primary">Salvar Configurações de Pagamento</button>
            </div>
        </div>



        <div id="tab-system" class="tab-content">
            <div class="section-block" style="border-left: 4px solid #6f42c1; background-color: #f9f2ff;">
                <h4 class="section-title" style="color: #6f42c1;">
                    <i class="fas fa-sync-alt"></i> Atualização do Sistema
                </h4>
                <p style="color: #666; font-size: 0.9rem; margin-bottom: 15px;">
                    Verifica atualizações no GitHub e sincroniza o banco de dados. 
                    <strong>config/database.php</strong> é preservado.
                </p>

                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="button" 
                        onclick="openMigrationModal('check_db')" 
                        class="btn-primary" 
                        style="background-color: #6f42c1; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; display: flex; align-items: center; gap: 5px;">
                        <i class="fas fa-cloud-download-alt"></i> <span>Buscar Atualizações</span>
                    </button>
                </div>
            </div>
        </div>

        <div id="tab-calendario" class="tab-content">
            <div class="section-block">
                <h4 class="section-title"><i class="far fa-calendar-plus"></i> Novo Recesso/Feriado</h4>
                <div class="settings-grid">
                    <div class="span-2">
                        <div class="form-group">
                            <label>Nome do Evento</label>
                            <input type="text" name="recess_name" class="form-control" placeholder="Ex: Feriado Nacional">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Início</label>
                        <input type="date" name="recess_start" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Fim</label>
                        <input type="date" name="recess_end" class="form-control">
                    </div>
                </div>
                <div style="text-align: right;">
                    <button type="submit" name="add_recess" class="btn-save btn-primary">Adicionar ao Calendário</button>
                </div>
            </div>

            <div class="section-block" style="padding:0; overflow:hidden;">
                <div class="table-responsive">
                    <table class="custom-table">
                        <thead style="background: #f8f9fa;">
                            <tr>
                                <th style="padding:15px;">Nome</th>
                                <th style="padding:15px;">Início</th>
                                <th style="padding:15px;">Fim</th>
                                <th style="padding:15px; text-align:right;">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recessList) > 0): ?>
                                <?php foreach ($recessList as $rec): ?>
                                    <tr>
                                        <td style="padding:15px;"><?php echo htmlspecialchars($rec['name']); ?></td>
                                        <td style="padding:15px;"><?php echo date('d/m/Y', strtotime($rec['start_date'])); ?></td>
                                        <td style="padding:15px;"><?php echo date('d/m/Y', strtotime($rec['end_date'])); ?></td>
                                        <td style="padding:15px; text-align:right;">
                                            <button type="submit" name="delete_recess_id" value="<?php echo $rec['id']; ?>" style="background:none; border:none; color:#e74c3c; cursor:pointer;" onclick="return confirm('Remover?')"><i class="fas fa-trash"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align:center; padding:20px; color:#999;">Nenhum registro.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </form>
</div>

<div id="migrationModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center; z-index: 10000;">
    <div class="modal-card" style="width: 600px; max-width: 90%; background: #2d3436; color: #dfe6e9; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
        
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 1px solid #636e72;">
            <h3 style="margin: 0; color: #00cec9; font-family: monospace;">
                <i class="fas fa-terminal"></i> System Updater
            </h3>
            <button type="button" onclick="closeMigrationModal()" style="background: none; border: none; color: #ff7675; font-size: 1.2rem; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div id="migrationContent" style="height: 250px; overflow-y: auto; padding: 15px; font-family: 'Courier New', monospace; font-size: 0.9rem; line-height: 1.5; background: #1e272e;">
            <div style="text-align: center; margin-top: 80px; color: #b2bec3;">
                Clique em "Verificar Agora" para iniciar.
            </div>
        </div>

        <div id="securityCheckArea" style="display: none; background: #2d3436; border-top: 1px solid #636e72; padding: 15px;">
            <div style="background: #d63031; color: white; padding: 10px; border-radius: 4px; font-size: 0.85rem; margin-bottom: 10px; border-left: 5px solid #ff7675;">
                <i class="fas fa-exclamation-triangle"></i> <strong>RECOMENDAÇÃO CRÍTICA:</strong><br>
                Antes de continuar, faça um <strong>BACKUP COMPLETO</strong> (Arquivos + Banco de Dados).<br>
                O processo é irreversível.
            </div>
            
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: #dfe6e9; font-weight: bold; font-size: 0.9rem; user-select: none;">
                <input type="checkbox" id="chkConfirmUpdate" onchange="toggleInstallButton()" style="width: 18px; height: 18px;">
                <span>Li o aviso e desejo atualizar o sistema.</span>
            </label>
        </div>

        <div class="modal-footer" style="padding: 15px; border-top: 1px solid #636e72; display: flex; justify-content: flex-end; gap: 10px; background: #2d3436; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px;">
            <button type="button" onclick="closeMigrationModal()" class="btn-secondary" style="background: #636e72; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">
                Fechar
            </button>

            <button type="button" id="btnAction" onclick="checkUpdate()" style="background: #0984e3; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold;">
                <i class="fas fa-search"></i> Verificar Agora
            </button>
        </div>
    </div>
</div>

<div id="renewConfirmModal" class="modal-overlay" style="display: none; animation: fadeIn 0.3s;">
    <div class="modal-card feedback-card">
        <div class="modal-icon-wrapper" style="background-color: #fef9e7; color: #f39c12;"><i class="fas fa-exclamation-triangle"></i></div>
        <h3 class="modal-h3" style="color: #f39c12;">Atenção!</h3>
        <div class="modal-body-content">Isso irá gerar cobranças para <b>TODOS</b> os alunos ativos para o ano selecionado.<br><br>Deseja realmente continuar?</div>
        <div class="modal-actions" style="display: flex; gap: 10px;">
            <button type="button" class="btn-modal-confirm" onclick="closeRenewModal()" style="background-color: #95a5a6; flex: 1;">Cancelar</button>
            <button type="button" class="btn-modal-confirm" onclick="confirmRenewSubmission()" style="background-color: #3498db; flex: 1;">Confirmar</button>
        </div>
    </div>
</div>

<?php if (isset($renewalResult)): 
    $isSuccess = ($renewalResult['type'] === 'success');
    $icon = $isSuccess ? 'fa-check-circle' : 'fa-times-circle';
    $color = $isSuccess ? '#27ae60' : '#c0392b';
    $bgColor = $isSuccess ? '#e8f5e9' : '#fdedec';
    $title = $isSuccess ? 'Processo Concluído!' : 'Atenção';
?>
<div id="feedbackModal" class="modal-overlay" style="display: flex; animation: fadeIn 0.3s;">
    <div class="modal-card feedback-card">
        <div class="modal-icon-wrapper" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $color; ?>;"><i class="fas <?php echo $icon; ?>"></i></div>
        <h3 class="modal-h3" style="color: <?php echo $color; ?>;"><?php echo $title; ?></h3>
        <div class="modal-body-content"><?php echo $renewalResult['msg']; ?></div>
        <div class="modal-actions"><button type="button" class="btn-modal-confirm" onclick="document.getElementById('feedbackModal').remove()" style="background-color: <?php echo $color; ?>; width: 100%;">Entendido</button></div>
    </div>
</div>
<?php endif; ?>

<script>
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) tabcontent[i].classList.remove("active");
    tablinks = document.getElementsByClassName("settings-tab-link");
    for (i = 0; i < tablinks.length; i++) tablinks[i].classList.remove("active");
    document.getElementById(tabName).classList.add("active");
    evt.currentTarget.classList.add("active");
}

function togglePaymentGateway(gateway) {
    const checkInter = document.getElementById('check_inter');
    const checkMp = document.getElementById('check_mp');

    if (gateway === 'inter' && checkInter.checked) {
        checkMp.checked = false; 
    } else if (gateway === 'mp' && checkMp.checked) {
        checkInter.checked = false; 
    }
}

function insertTagAtCursor(elementId, textToInsert) {
    var el = document.getElementById(elementId);
    var startPos = el.selectionStart; var endPos = el.selectionEnd; var text = el.value;
    el.value = text.substring(0, startPos) + textToInsert + text.substring(endPos, text.length);
    el.focus(); el.selectionStart = startPos + textToInsert.length; el.selectionEnd = startPos + textToInsert.length;
}

function wrapText(elementId, tagStart, tagEnd) {
    var el = document.getElementById(elementId);
    var startPos = el.selectionStart; var endPos = el.selectionEnd; var text = el.value;
    if (startPos !== endPos) {
        var selectedText = text.substring(startPos, endPos);
        var replacement = tagStart + selectedText + tagEnd;
        el.value = text.substring(0, startPos) + replacement + text.substring(endPos, text.length);
        el.focus(); el.selectionStart = startPos; el.selectionEnd = startPos + replacement.length;
    } else {
        insertTagAtCursor(elementId, tagStart + tagEnd);
        el.selectionStart = el.selectionStart - tagEnd.length; el.selectionEnd = el.selectionStart;
    }
}

// Funções de Modal e Migração
function openRenewModal() { document.getElementById('renewConfirmModal').style.display = 'flex'; }
function closeRenewModal() { document.getElementById('renewConfirmModal').style.display = 'none'; }
function confirmRenewSubmission() {
    var form = document.createElement("form");
    form.method = "POST";
    form.action = "";
    
    var inputAction = document.createElement("input");
    inputAction.type = "hidden";
    inputAction.name = "action";
    inputAction.value = "generate_year";
    form.appendChild(inputAction);

    var inputYear = document.createElement("input");
    inputYear.type = "hidden";
    inputYear.name = "renew_year";
    inputYear.value = document.getElementById('renew_year_input').value;
    form.appendChild(inputYear);

    document.body.appendChild(form);
    form.submit();
}

// --- JS DE ATUALIZAÇÃO (COM TRAVA DE SEGURANÇA) ---
let updateState = 'check'; // 'check' ou 'install'

function openMigrationModal(action) {
    const modal = document.getElementById('migrationModal');
    const content = document.getElementById('migrationContent');
    const btnAction = document.getElementById('btnAction');
    const secArea = document.getElementById('securityCheckArea');
    const chk = document.getElementById('chkConfirmUpdate');
    
    modal.style.display = 'flex';
    
    // Reseta estado visual
    updateState = 'check';
    secArea.style.display = 'none'; // Esconde alerta
    chk.checked = false; // Desmarca checkbox
    
    btnAction.style.display = 'inline-block';
    btnAction.disabled = false;
    btnAction.innerHTML = '<i class="fas fa-search"></i> Verificar Agora';
    btnAction.style.backgroundColor = '#0984e3'; // Azul
    btnAction.style.cursor = 'pointer';
    btnAction.onclick = checkUpdate; 

    if (action === 'check_db') {
        checkUpdate();
    } else {
        content.innerHTML = '<div style="text-align: center; margin-top: 80px; color: #b2bec3;">Clique no botão abaixo para buscar atualizações.</div>';
    }
}

function toggleInstallButton() {
    const chk = document.getElementById('chkConfirmUpdate');
    const btnAction = document.getElementById('btnAction');
    
    // Só libera se estiver no modo de instalação E o checkbox estiver marcado
    if (updateState === 'install') {
        if (chk.checked) {
            btnAction.disabled = false;
            btnAction.style.backgroundColor = '#00b894'; // Verde
            btnAction.style.cursor = 'pointer';
            btnAction.innerHTML = '<i class="fas fa-download"></i> Instalar Atualização';
        } else {
            btnAction.disabled = true;
            btnAction.style.backgroundColor = '#b2bec3'; // Cinza apagado
            btnAction.style.cursor = 'not-allowed';
            btnAction.innerHTML = '<i class="fas fa-lock"></i> Confirme o Backup';
        }
    }
}

function checkUpdate() {
    const content = document.getElementById('migrationContent');
    const btnAction = document.getElementById('btnAction');
    const secArea = document.getElementById('securityCheckArea');
    
    btnAction.disabled = true;
    btnAction.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';
    content.innerHTML = '<div style="text-align: center; margin-top: 80px; color: #00cec9;"><i class="fas fa-circle-notch fa-spin fa-3x"></i><br><br>Consultando GitHub...</div>';

    fetch('../libs/auto_migrate.php?action=check')
    .then(response => response.json())
    .then(data => {
        renderLogs(data, content);
        
        if (data.update_available === true) {
            // MODO INSTALAÇÃO
            updateState = 'install';
            secArea.style.display = 'block'; // Mostra o alerta e checkbox
            
            // Trava o botão imediatamente
            btnAction.onclick = runRealUpdate;
            toggleInstallButton(); // Chama a função para aplicar o estilo bloqueado
            
        } else {
            // TUDO EM DIA
            btnAction.disabled = false;
            btnAction.innerHTML = '<i class="fas fa-check"></i> Tudo em dia';
            btnAction.style.backgroundColor = '#636e72'; 
        }
    })
    .catch(error => {
        content.innerHTML = `<div style="color: #ff7675;">Erro: ${error}</div>`;
        btnAction.disabled = false;
        btnAction.innerHTML = 'Tentar Novamente';
    });
}

function runRealUpdate() {
    const content = document.getElementById('migrationContent');
    const btnAction = document.getElementById('btnAction');
    const secArea = document.getElementById('securityCheckArea');
    
    // Segurança extra (embora o botão já devesse estar bloqueado)
    const chk = document.getElementById('chkConfirmUpdate');
    if (!chk.checked) return;

    btnAction.disabled = true;
    btnAction.innerHTML = '<i class="fas fa-cog fa-spin"></i> Instalando...';
    
    // Esconde a área de segurança para limpar a tela
    secArea.style.display = 'none';
    
    content.innerHTML += '<div style="color: #ffeaa7; margin-top: 10px; border-top: 1px dashed #555; padding-top: 10px;">--- Iniciando Instalação ---</div>';
    content.scrollTop = content.scrollHeight;

    fetch('../libs/auto_migrate.php?action=perform_update')
    .then(response => response.json())
    .then(data => {
        renderLogs(data, content);
        
        btnAction.innerHTML = '<i class="fas fa-check-double"></i> Concluído';
        btnAction.style.backgroundColor = '#636e72';
    })
    .catch(error => {
        content.innerHTML += `<div style="color: #ff7675;">Erro fatal: ${error}</div>`;
        btnAction.disabled = false;
        btnAction.innerHTML = 'Erro. Tentar de novo?';
    });
}

function renderLogs(data, content) {
    // Se for a primeira renderização (check), limpa a tela. Se for update, adiciona.
    if (updateState === 'check') content.innerHTML = ''; 
    
    // Cabeçalho de Versão (Só mostra no check)
    if (updateState === 'check') {
        content.innerHTML += `<div style="margin-bottom: 10px; border-bottom: 1px dashed #555; padding-bottom: 5px; font-weight: bold;">
            Versão Local: <span style="color: #fab1a0">${data.version_local}</span> <br>
            Versão GitHub: <span style="color: #55efc4">${data.version_remote}</span>
        </div>`;
    }

    if (data.logs && data.logs.length > 0) {
        data.logs.forEach(log => {
            let color = '#dfe6e9';
            let icon = '•';
            if (log.type === 'success') { color = '#55efc4'; icon = '✓'; }
            if (log.type === 'error')   { color = '#ff7675'; icon = '✗'; }
            if (log.type === 'warning') { color = '#ffeaa7'; icon = '!'; }
            
            content.innerHTML += `<div style="color: ${color}; margin-bottom: 3px; font-family: monospace;">
                <span style="opacity:0.7; margin-right:5px;">${icon}</span> ${log.msg}
            </div>`;
        });
    }
    content.scrollTop = content.scrollHeight;
}

function closeMigrationModal() { document.getElementById('migrationModal').style.display = 'none'; }
</script>

<style>
    .highlight-card { border-left: 4px solid #3498db; background: #fdfdfd; }
    .btn-generate { background: #3498db; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: 600; height: 38px; }
    .btn-generate:hover { background: #2980b9; }
    .feedback-card { text-align: center; max-width: 450px !important; padding: 30px !important; border-top: 5px solid transparent; }
    .modal-body-content { margin: 15px 0 25px 0; font-size: 1rem; color: #555; line-height: 1.6; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    .feedback-card { animation: slideDown 0.4s ease-out; }
    
    /* Estilos Switch */
    .switch-label { position: relative; display: inline-flex; align-items: center; gap: 12px; cursor: pointer; font-weight: bold; color: #555; user-select: none; }
    .switch-label input { opacity: 0; width: 0; height: 0; position: absolute; }
    .slider-round { position: relative; width: 42px; height: 24px; background-color: #ccc; transition: .4s; border-radius: 34px; }
    .slider-round:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    .switch-label input:checked ~ .slider-round { background-color: #27ae60; }
    .switch-label input:checked ~ .slider-round:before { transform: translateX(18px); }
</style>

<?php include '../includes/admin_footer.php'; ?>
