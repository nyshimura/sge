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

    // C. GERAR NOVO ANO LETIVO
    elseif (isset($_POST['action']) && $_POST['action'] === 'generate_year') {
        $targetYear = (int)$_POST['renew_year'];
        $renewalResult = generateAnnualInvoices($pdo, $targetYear);
    }

    // D. Salvar Configurações Gerais
    else {
        try {
            $fields = [
                'site_url', 'language', 'timeZone', 'currencySymbol',
                'smtpServer', 'smtpPort', 'smtpUser', 'smtpPass', 
                'email_approval_subject', 'email_approval_body', 
                'email_reset_subject', 'email_reset_body',
                'email_reminder_subject', 'email_reminder_body',
                'certificate_template_text', 'imageTermsText', 'enrollmentContractText',
                'geminiApiKey', 'geminiApiEndpoint',
                'dbHost', 'dbUser', 'dbPass', 'dbName', 'dbPort',
                'mp_public_key', 'mp_access_token', 'mp_client_id', 'mp_client_secret',
            ];
            $sql = "UPDATE system_settings SET ";
            $params = [];
            foreach ($fields as $field) { $sql .= "$field = :$field, "; $params[":$field"] = $_POST[$field] ?? ''; }
            
            $sql .= "enableTerminationFine = :enableTerminationFine, ";
            $params[':enableTerminationFine'] = isset($_POST['enableTerminationFine']) ? 1 : 0;
            $sql .= "mp_active = :mp_active, ";
            $params[':mp_active'] = isset($_POST['mp_active']) ? 1 : 0;
            $sql .= "terminationFineMonths = :terminationFineMonths, ";
            $params[':terminationFineMonths'] = (int)($_POST['terminationFineMonths'] ?? 0);
            $sql .= "defaultDueDay = :defaultDueDay, ";
            $params[':defaultDueDay'] = (int)($_POST['defaultDueDay'] ?? 10);
            $sql .= "reminderDaysBefore = :reminderDaysBefore "; 
            $params[':reminderDaysBefore'] = (int)($_POST['reminderDaysBefore'] ?? 3);

            if (isset($_FILES['certificate_bg']) && $_FILES['certificate_bg']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png'];
                $ext = strtolower(pathinfo($_FILES['certificate_bg']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, $allowed)) {
                    $data = file_get_contents($_FILES['certificate_bg']['tmp_name']);
                    $sql .= ", certificate_background_image = :cert_img";
                    $params[':cert_img'] = 'data:image/' . $ext . ';base64,' . base64_encode($data);
                }
            }
            $sql .= " WHERE id = 1"; 
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $msg = '<div class="alert alert-success">Configurações salvas!</div>';
        } catch (PDOException $e) { $msg = '<div class="alert alert-danger">Erro: ' . $e->getMessage() . '</div>'; }
    }
}

// Carregar Dados
$settings = $pdo->query("SELECT * FROM system_settings WHERE id = 1")->fetch();
if (!$settings) {
    $pdo->query("INSERT INTO system_settings (id, site_url) VALUES (1, 'http://localhost')");
    $settings = $pdo->query("SELECT * FROM system_settings WHERE id = 1")->fetch();
}
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
        <button class="settings-tab-link" onclick="openTab(event, 'tab-api')"><i class="fas fa-plug"></i> Integrações</button>
        <button class="settings-tab-link" onclick="openTab(event, 'tab-system')"><i class="fas fa-sync"></i> Sistema</button>
    </div>

    <form method="POST" action="" enctype="multipart/form-data">
        
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
                <h4 class="section-title"><i class="fas fa-file-signature"></i> Contrato de Matrícula</h4>
                <div class="form-group">
                    <?php renderToolbar('enrollmentContractText'); ?>
                    <textarea id="enrollmentContractText" name="enrollmentContractText" class="form-control has-toolbar" style="height: 300px;"><?php echo htmlspecialchars($settings['enrollmentContractText']); ?></textarea>
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

            <div class="section-block" style="border-left: 4px solid #009ee3;">
                <h4 class="section-title" style="color:#009ee3;"><i class="fas fa-handshake"></i> Mercado Pago</h4>
                <div style="margin-bottom: 20px;">
                    <label style="cursor:pointer; font-weight:bold; display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="mp_active" value="1" <?php echo $settings['mp_active'] ? 'checked' : ''; ?> style="width:20px; height:20px;">
                        Ativar Integração
                    </label>
                </div>
                <div class="settings-grid">
                    <div class="form-group"><label>Public Key</label><input type="text" name="mp_public_key" class="form-control" value="<?php echo htmlspecialchars($settings['mp_public_key']); ?>"></div>
                    <div class="form-group"><label>Access Token</label><input type="password" name="mp_access_token" class="form-control" value="<?php echo htmlspecialchars($settings['mp_access_token']); ?>"></div>
                    <div class="form-group"><label>Client ID</label><input type="text" name="mp_client_id" class="form-control" value="<?php echo htmlspecialchars($settings['mp_client_id']); ?>"></div>
                    <div class="form-group"><label>Client Secret</label><input type="password" name="mp_client_secret" class="form-control" value="<?php echo htmlspecialchars($settings['mp_client_secret']); ?>"></div>
                </div>
                <div style="text-align: right; margin-top: 15px;">
                    <button type="submit" class="btn-save btn-primary">Salvar Financeiro</button>
                </div>
            </div>
            
            <div class="section-block highlight-card" style="margin-top: 20px;">
                <h4 class="section-title" style="color:#2c3e50; display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-calendar-plus" style="color: #3498db;"></i> Ferramentas de Renovação
                </h4>
                
                <p style="color: #666; font-size: 0.9rem; line-height: 1.5; margin-bottom: 15px;">
                    Gera automaticamente os carnês de pagamento (Jan a Dez) para <b>todos os alunos com matrícula aprovada</b>.
                    <br><i class="fas fa-info-circle" style="color:#3498db;"></i> O sistema verifica mês a mês e <b>não duplica</b> cobranças existentes.
                </p>

                <div id="renewFormSection">
                    <input type="hidden" name="action" value="generate_year">
                    
                    <div style="display: flex; gap: 10px; align-items: flex-end; max-width: 400px;">
                        <div style="flex: 1;">
                            <label style="font-weight: bold; font-size: 0.85rem;">Ano de Referência</label>
                            <input type="number" id="renew_year_input" name="renew_year" class="form-control" value="<?php echo date('Y') + 1; ?>" min="2024" max="2050">
                        </div>
                        <button type="button" class="btn-generate" onclick="openRenewModal()">
                            Processar <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div id="tab-api" class="tab-content">
        <form method="POST">
            <div class="section-block">
                <h4 class="section-title"><i class="fas fa-brain"></i> IA (Google Gemini)</h4>
                <div class="settings-grid">
                    <div class="form-group"><label>API Key</label><input type="password" name="geminiApiKey" class="form-control" value="<?php echo htmlspecialchars($settings['geminiApiKey']); ?>"></div>
                    <div class="form-group"><label>Endpoint</label><input type="text" name="geminiApiEndpoint" class="form-control" value="<?php echo htmlspecialchars($settings['geminiApiEndpoint']); ?>"></div>
                </div>
            </div>

            <div class="section-block" style="border-left: 4px solid #e74c3c;">
                <h4 class="section-title" style="color:#e74c3c;"><i class="fas fa-database"></i> Banco de Dados</h4>
                <div class="settings-grid">
                    <div class="form-group span-2"><label>Host</label><input type="text" name="dbHost" class="form-control" value="<?php echo htmlspecialchars($settings['dbHost']); ?>"></div>
                    <div class="form-group"><label>Nome do Banco</label><input type="text" name="dbName" class="form-control" value="<?php echo htmlspecialchars($settings['dbName']); ?>"></div>
                    <div class="form-group"><label>Usuário</label><input type="text" name="dbUser" class="form-control" value="<?php echo htmlspecialchars($settings['dbUser']); ?>"></div>
                    <div class="form-group"><label>Senha</label><input type="password" name="dbPass" class="form-control" value="<?php echo htmlspecialchars($settings['dbPass']); ?>"></div>
                    <div class="form-group"><label>Porta</label><input type="text" name="dbPort" class="form-control" value="<?php echo htmlspecialchars($settings['dbPort']); ?>"></div>
                </div>
                <div style="text-align: right; margin-top: 15px;">
                    <button type="submit" class="btn-save btn-primary">Salvar Integrações</button>
                </div>
            </div>
        </form>
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
                


                <button type="button" onclick="openMigrationModal('update_system')" class="btn-primary" style="background-color: #6f42c1; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; display: flex; align-items: center; gap: 5px;">
                    <i class="fab fa-github"></i> <span>Buscar Atualizações (GitHub)</span>
                </button>

            </div>
        </div>
    </div>

    <div id="tab-calendario" class="tab-content">
        <div class="section-block">
            <h4 class="section-title"><i class="far fa-calendar-plus"></i> Novo Recesso/Feriado</h4>
            <form method="POST" action="">
                <div class="settings-grid">
                    <div class="span-2">
                        <div class="form-group">
                            <label>Nome do Evento</label>
                            <input type="text" name="recess_name" class="form-control" placeholder="Ex: Feriado Nacional" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Início</label>
                        <input type="date" name="recess_start" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Fim</label>
                        <input type="date" name="recess_end" class="form-control" required>
                    </div>
                </div>
                <div style="text-align: right;">
                    <button type="submit" name="add_recess" class="btn-save btn-primary">Adicionar ao Calendário</button>
                </div>
            </form>
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
                                        <form method="POST" onsubmit="return confirm('Remover?');" style="display:inline;">
                                            <input type="hidden" name="delete_recess_id" value="<?php echo $rec['id']; ?>">
                                            <button type="submit" style="background:none; border:none; color:#e74c3c; cursor:pointer;"><i class="fas fa-trash"></i></button>
                                        </form>
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

</div>

<div id="migrationModal" class="modal-overlay" style="display: none; align-items: center; justify-content: center; z-index: 10000;">
    <div class="modal-card" style="width: 600px; max-width: 90%; background: #2d3436; color: #dfe6e9; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
        
        <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 1px solid #636e72;">
            <h3 style="margin: 0; color: #00cec9; font-family: monospace;">
                <i class="fas fa-terminal"></i> System Updater
            </h3>
            <button onclick="closeMigrationModal()" style="background: none; border: none; color: #ff7675; font-size: 1.2rem; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div id="migrationContent" style="height: 300px; overflow-y: auto; padding: 15px; font-family: 'Courier New', monospace; font-size: 0.9rem; line-height: 1.5;">
            <div style="text-align: center; margin-top: 100px;">
                <i class="fas fa-circle-notch fa-spin fa-2x"></i><br>Inicializando...
            </div>
        </div>

        <div class="modal-footer" style="padding-top: 15px; border-top: 1px solid #636e72; text-align: right;">
            <button id="btnForceUpdate" onclick="runMigration('update_system', true)" style="display: none; background: #e67e22; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-right: 10px;">
                <i class="fas fa-sync"></i> Forçar Reinstalação
            </button>
            <button onclick="closeMigrationModal()" class="btn-primary" style="background: #00cec9; border: none; color: #2d3436; font-weight: bold;">
                Fechar
            </button>
        </div>
    </div>
</div>

<div id="renewConfirmModal" class="modal-overlay" style="display: none; animation: fadeIn 0.3s;">
    <div class="modal-card feedback-card">
        <div class="modal-icon-wrapper" style="background-color: #fef9e7; color: #f39c12;">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        
        <h3 class="modal-h3" style="color: #f39c12;">Atenção!</h3>
        
        <div class="modal-body-content">
            Isso irá gerar cobranças para <b>TODOS</b> os alunos ativos para o ano selecionado.<br><br>
            Deseja realmente continuar?
        </div>

        <div class="modal-actions" style="display: flex; gap: 10px;">
            <button type="button" class="btn-modal-confirm" onclick="closeRenewModal()" style="background-color: #95a5a6; flex: 1;">
                Cancelar
            </button>
            <button type="button" class="btn-modal-confirm" onclick="confirmRenewSubmission()" style="background-color: #3498db; flex: 1;">
                Confirmar
            </button>
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
        <div class="modal-icon-wrapper" style="background-color: <?php echo $bgColor; ?>; color: <?php echo $color; ?>;">
            <i class="fas <?php echo $icon; ?>"></i>
        </div>
        
        <h3 class="modal-h3" style="color: <?php echo $color; ?>;"><?php echo $title; ?></h3>
        
        <div class="modal-body-content">
            <?php echo $renewalResult['msg']; ?>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn-modal-confirm" onclick="document.getElementById('feedbackModal').remove()" style="background-color: <?php echo $color; ?>; width: 100%;">
                Entendido
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tab-content");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].classList.remove("active");
    }
    tablinks = document.getElementsByClassName("settings-tab-link");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].classList.remove("active");
    }
    document.getElementById(tabName).classList.add("active");
    evt.currentTarget.classList.add("active");
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

// Funções para o Modal de Confirmação de Renovação
function openRenewModal() {
    document.getElementById('renewConfirmModal').style.display = 'flex';
}
function closeRenewModal() {
    document.getElementById('renewConfirmModal').style.display = 'none';
}
function confirmRenewSubmission() {
    var form = document.querySelector('form[action=""]'); 
    // Removemos input anterior se houver
    var oldInput = document.getElementById('temp_action_input');
    if(oldInput) oldInput.remove();
    
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'action';
    input.value = 'generate_year';
    input.id = 'temp_action_input';
    form.appendChild(input);

    var yearInput = document.createElement('input');
    yearInput.type = 'hidden';
    yearInput.name = 'renew_year';
    yearInput.value = document.getElementById('renew_year_input').value;
    form.appendChild(yearInput);

    form.submit();
}

// --- LÓGICA DO MODAL DE MIGRAÇÃO ---

function openMigrationModal(action) {
    const modal = document.getElementById('migrationModal');
    const content = document.getElementById('migrationContent');
    const btnForce = document.getElementById('btnForceUpdate');
    
    modal.style.display = 'flex';
    btnForce.style.display = 'none'; // Esconde botão forçar inicialmente
    
    // Mostra loading
    content.innerHTML = '<div style="text-align: center; margin-top: 100px; color: #00cec9;"><i class="fas fa-circle-notch fa-spin fa-3x"></i><br><br>Processando... Por favor, aguarde.</div>';

    // Chama a função real
    runMigration(action, false);
}

function closeMigrationModal() {
    document.getElementById('migrationModal').style.display = 'none';
}

function runMigration(action, force) {
    let url = '../libs/auto_migrate.php?json=1';
    if (action === 'update_system') url += '&action=update_system';
    if (force) url += '&force=1';

    const content = document.getElementById('migrationContent');

    fetch(url)
    .then(response => response.json())
    .then(data => {
        content.innerHTML = ''; // Limpa loading
        
        // Cabeçalho de Versão
        if (data.version_local) {
            content.innerHTML += `<div style="margin-bottom: 10px; border-bottom: 1px dashed #555; padding-bottom: 5px;">
                Local: <span style="color: #fab1a0">${data.version_local}</span> | 
                GitHub: <span style="color: #55efc4">${data.version_remote}</span>
            </div>`;
        }

        // Renderiza Logs
        if (data.logs && data.logs.length > 0) {
            data.logs.forEach(log => {
                let color = '#dfe6e9'; // Default white
                let icon = '•';
                
                if (log.type === 'success') { color = '#55efc4'; icon = '✓'; }
                if (log.type === 'error')   { color = '#ff7675'; icon = '✗'; }
                if (log.type === 'warning') { color = '#ffeaa7'; icon = '!'; }
                if (log.type === 'info')    { color = '#74b9ff'; icon = 'ℹ'; }

                content.innerHTML += `<div style="color: ${color}; margin-bottom: 3px;">
                    <span style="opacity:0.7; margin-right:5px;">${icon}</span> ${log.msg}
                </div>`;
            });
        } else {
            content.innerHTML += '<div style="color: #fab1a0">Nenhum log retornado.</div>';
        }

        // Verifica se precisa mostrar botão de Forçar
        if (action === 'update_system' && !force) {
            const isUpdated = data.logs.some(l => l.msg.includes('já está atualizado'));
            if (isUpdated) {
                document.getElementById('btnForceUpdate').style.display = 'inline-block';
            }
        }
        
        content.scrollTop = content.scrollHeight;

    })
    .catch(error => {
        content.innerHTML = `<div style="color: #ff7675; text-align: center; margin-top: 50px;">
            <i class="fas fa-exclamation-triangle fa-2x"></i><br><br>
            Erro na comunicação com o servidor:<br>${error}
        </div>`;
    });
}
</script>

<style>
    /* Estilo Específico do Card de Ano Letivo */
    .highlight-card { border-left: 4px solid #3498db; background: #fdfdfd; }
    .btn-generate { background: #3498db; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: 600; height: 38px; }
    .btn-generate:hover { background: #2980b9; }

    /* CSS ADICIONAL PARA O FEEDBACK */
    .feedback-card {
        text-align: center;
        max-width: 450px !important;
        padding: 30px !important;
        border-top: 5px solid transparent;
    }
    
    .modal-body-content {
        margin: 15px 0 25px 0;
        font-size: 1rem;
        color: #555;
        line-height: 1.6;
    }

    /* Animação suave de entrada */
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes slideDown {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .feedback-card {
        animation: slideDown 0.4s ease-out;
    }
</style>

<?php include '../includes/admin_footer.php'; ?>
