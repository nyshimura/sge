// src/views/school/settings.js
import { apiCall } from '../../api.js';
import { appState } from '../../state.js';

export async function renderSystemSettingsView() {
    if (!appState.systemSettings) {
        try { const data = await apiCall('getSystemSettings', {}, 'GET'); appState.systemSettings = data.settings; }
        catch (e) { console.error("Falha ao carregar settings:", e); return `<h2>Erro</h2><p>${e.message}</p><button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar</button>`; }
    }
    const settings = appState.systemSettings;
    if (!settings) return `<h2>Erro</h2><p>Dados não encontrados.</p><button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar</button>`;

    const emailPlaceholders = [ '{{aluno_nome}}', '{{responsavel_nome}}', '{{user_name}}', '{{curso_nome}}', '{{escola_nome}}', '{{link_contrato}}', '{{reset_link}}' ];

    // Campos hidden dos templates para não zerar ao salvar aqui
    const hiddenTemplateFields = `
        <input type="hidden" name="enrollmentContractText" value="${settings.enrollmentContractText || ''}">
        <input type="hidden" name="imageTermsText" value="${settings.imageTermsText || ''}">
        <input type="hidden" name="certificate_template_text" value="${settings.certificate_template_text || ''}">
        <input type="hidden" name="certificate_background_image" value="${settings.certificate_background_image || ''}">
    `;

    return `
        <div class="view-header">
            <h2>Configurações do Sistema</h2>
            <button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar</button>
        </div>

        <form onsubmit="window.AppHandlers.handleUpdateSystemSettings(event)">
            ${hiddenTemplateFields} 

            
            <div class="card full-width">
                 <h3 class="card-title">Modelos de Documentos e Certificados</h3>
                 <p>Gerencie os textos e layouts dos documentos gerados pelo sistema.</p>
                 <div class="list-item-actions" style="justify-content: flex-start;">
                    <button type="button" class="action-button" onclick="window.AppHandlers.handleNavigateToDocumentTemplates()">Gerir Contrato/Termos</button>
                    <button type="button" class="action-button" onclick="window.AppHandlers.handleNavigateToCertificateTemplate()">Gerir Certificado</button>
                 </div>
            </div>
            

            <div class="card full-width">
                <div class="settings-grid">
                    <div class="settings-section">
                        <h3 class="card-title">⚙️ Geral</h3>
                         <div class="form-group"> <label for="language">Linguagem</label> <select id="language" name="language"><option value="pt-BR" ${settings.language === 'pt-BR' ? 'selected' : ''}>Português (Brasil)</option></select> </div>
                        <div class="form-group"> <label for="timeZone">Fuso Horário</label> <input type="text" id="timeZone" name="timeZone" value="${settings.timeZone || 'America/Sao_Paulo'}"><small>Ex: America/Sao_Paulo</small> </div>
                        <div class="form-group" style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);"> <label for="site_url">URL do Site (Raiz do SGE)</label> <input type="url" id="site_url" name="site_url" value="${settings.site_url || ''}" placeholder="https://.../sge/" required><small>Necessário para links. Use URL completa com "/" no final.</small> </div>
                    </div>


                    <div class="settings-section">
                        <h3 class="card-title">💰 Financeiro</h3>
                        <div class="form-group"> <label for="currencySymbol">Símbolo da Moeda</label> <input type="text" id="currencySymbol" name="currencySymbol" value="${settings.currencySymbol || 'R$'}"> </div>
                         <div class="form-group"> <label for="defaultDueDay">Dia Padrão de Vencimento</label> <input type="number" id="defaultDueDay" name="defaultDueDay" value="${settings.defaultDueDay || 10}" min="1" max="28"> </div>
                        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                            <h4 style="margin-top: 0; margin-bottom: 1rem;">Multa Rescisória</h4>
                            <div class="form-group"> <label class="form-group-inline" style="gap: 0.5rem; cursor: pointer;"> <input type="checkbox" id="enableTerminationFine" name="enableTerminationFine" ${settings.enableTerminationFine ? 'checked' : ''} onchange="document.getElementById('termination-fine-months-group').style.display = this.checked ? 'block' : 'none'" style="width: auto;"> <span>Habilitar multa</span> </label> </div>
                            <div class="form-group" id="termination-fine-months-group" style="display: ${settings.enableTerminationFine ? 'block' : 'none'};"> <label for="terminationFineMonths">Nº de Mensalidades</label> <input type="number" id="terminationFineMonths" name="terminationFineMonths" value="${settings.terminationFineMonths || 1}" min="1"> </div>
                        </div>
                    </div>


                    <div class="settings-section">
                        <h3 class="card-title">🤖 Integração com IA</h3>
                        <div class="form-group"><label for="geminiApiKey">Chave API Gemini</label><input type="password" id="geminiApiKey" name="geminiApiKey" value="${settings.geminiApiKey || ''}"></div>
                        <div class="form-group"><label for="geminiApiEndpoint">URL Endpoint</label><input type="text" id="geminiApiEndpoint" name="geminiApiEndpoint" value="${settings.geminiApiEndpoint || ''}" placeholder="https://generativelanguage..."><small>Ex: .../gemini-1.5-flash:generateContent</small></div>
                    </div>


                    <div class="settings-section">
                        <h3 class="card-title">✉️ E-mail (SMTP)</h3>
                        <div class="form-group"><label for="smtpServer">Servidor</label><input type="text" id="smtpServer" name="smtpServer" value="${settings.smtpServer || ''}" placeholder="smtp.exemplo.com"></div>
                        <div class="form-group"><label for="smtpPort">Porta</label><input type="text" id="smtpPort" name="smtpPort" value="${settings.smtpPort || ''}" placeholder="587"></div>
                        <div class="form-group"><label for="smtpUser">Usuário</label><input type="text" id="smtpUser" name="smtpUser" value="${settings.smtpUser || ''}" placeholder="seu@email.com"></div>
                        <div class="form-group"><label for="smtpPass">Senha</label><input type="password" id="smtpPass" name="smtpPass" value="${settings.smtpPass || ''}"></div>
                    </div>


                    <div class="settings-section" style="grid-column: span 2;">
                        <h3 class="card-title">📧 Modelos de E-mail</h3>
                        <div class="placeholders-sidebar" style="padding: 10px; font-size: 0.8rem; background-color: var(--subtle-bg); border-radius: 8px; margin-bottom: 1.5rem;"> <strong>Placeholders:</strong><br> ${emailPlaceholders.map(ph => `<button type="button" class="placeholder-button" style="margin: 2px;" onclick="navigator.clipboard.writeText('${ph}')">${ph}</button>`).join('')} </div>

                        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                            <h4 style="margin-top: 0;">1. Matrícula Aprovada</h4>
                            <div class="form-group"> <label for="email_approval_subject">Assunto</label> <input type="text" id="email_approval_subject" name="email_approval_subject" value="${settings.email_approval_subject || 'Matrícula Aprovada!'}"> </div>
                            <div class="form-group"> <label for="email_approval_body">Corpo</label> <textarea id="email_approval_body" name="email_approval_body" rows="8">${settings.email_approval_body || `Olá {{responsavel_nome}},\n\nParabéns! A matrícula de {{aluno_nome}} no curso {{curso_nome}} foi aprovada.\n\nVeja seu contrato:\n{{link_contrato}}\n\nAtenciosamente,\nEquipe {{escola_nome}}`}</textarea> </div>
                        </div>

                        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--border-color);">
                            <h4 style="margin-top: 0;">2. Redefinição de Senha</h4>
                            <div class="form-group"> <label for="email_reset_subject">Assunto</label> <input type="text" id="email_reset_subject" name="email_reset_subject" value="${settings.email_reset_subject || 'Redefinição de Senha Solicitada'}"> </div>
                            <div class="form-group"> <label for="email_reset_body">Corpo</label> <textarea id="email_reset_body" name="email_reset_body" rows="8">${settings.email_reset_body || `Olá {{user_name}},\n\nLink para redefinir sua senha (expira em 1 hora):\n{{reset_link}}\n\nAtenciosamente,\nEquipe {{escola_nome}}`}</textarea> </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <h3 class="card-title">🔄 Atualização do Sistema</h3>
                        <div id="update-status-container">
                            <p class="small-text">Verifique se há novas versões disponíveis no repositório.</p>
                            <div id="update-info-display" style="display:none; margin-bottom: 10px; padding: 10px; border: 1px solid var(--border-color); border-radius: 4px;">
                                <strong>Versão Atual:</strong> <span id="local-version">-</span><br>
                                <strong>Nova Versão:</strong> <span id="remote-version">-</span>
                            </div>
                            <button type="button" id="btn-check-update" class="action-button secondary" onclick="window.AppHandlers.handleCheckUpdate(this)">
                                Verificar Atualização
                            </button>
                            <button type="button" id="btn-perform-update" class="action-button" style="display:none; margin-top: 10px;" onclick="window.AppHandlers.handlePerformUpdate(this)">
                                ⬇️ Baixar e Instalar
                            </button>
                        </div>
                    </div>

                    <div class="settings-section">
                        <h3 class="card-title">💾 Base de Dados</h3>
                        <button type="button" class="action-button secondary" onclick="window.AppHandlers.handleExportDatabase()" style="margin-top: 1rem;">Exportar Dados (JSON)</button>
                        <small style="display: block; margin-top: 0.5rem;">Exporta todos os dados do banco.</small>
                    </div>
                </div>
                
                <p class="error-message" id="settings-error"></p>
                <button type="submit" class="action-button" style="margin-top: 2rem;">Salvar Configurações Gerais</button>
            </div>
        </form>
    `;
}