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

    // --- CORREÇÃO: Removido o input hidden da certificate_background_image ---
    // Isso evita enviar a string Base64 gigante sem necessidade.
    const hiddenTemplateFields = `
        <input type="hidden" name="enrollmentContractText" value="${settings.enrollmentContractText || ''}">
        <input type="hidden" name="imageTermsText" value="${settings.imageTermsText || ''}">
        <input type="hidden" name="certificate_template_text" value="${settings.certificate_template_text || ''}">
    `;

    return `
        <div class="header-section">
            <h2>⚙️ Configurações do Sistema</h2>
            <button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar</button>
        </div>

        <form id="system-settings-form" onsubmit="window.AppHandlers.handleUpdateSystemSettings(event)">
            ${hiddenTemplateFields}
            
            <div class="card full-width">
                 <h3 class="card-title">Modelos de Documentos e Certificados</h3>
                 <p>Gerencie os textos e layouts dos documentos gerados pelo sistema.</p>
                 <div class="list-item-actions" style="justify-content: flex-start;">
                    <button type="button" class="action-button" onclick="window.AppHandlers.handleNavigateToDocumentTemplates()">Gerir Contrato/Termos</button>
                    <button type="button" class="action-button" onclick="window.AppHandlers.handleNavigateToCertificateTemplate()">Gerir Certificado</button>
                 </div>
            </div>
            
            <div class="settings-grid">
                
                <div class="settings-column">
                    <div class="settings-section">
                        <h3 class="card-title">🏫 Configurações da Escola</h3>
                         <div class="form-group">
                            <label>Nome do Sistema / Escola</label>
                            <input type="text" name="name" class="form-control" value="${settings.name || ''}" disabled title="Edite no Perfil da Escola">
                        </div>
                        <div class="form-group">
                            <label>URL do Site (Base URL)</label>
                            <input type="text" name="site_url" class="form-control" value="${settings.site_url || ''}">
                            <small>Ex: http://localhost/sge/</small>
                        </div>
                         <div class="form-group">
                            <label>Fuso Horário</label>
                            <input type="text" name="timeZone" class="form-control" value="${settings.timeZone || 'America/Sao_Paulo'}">
                        </div>
                         <div class="form-group">
                            <label>Idioma Padrão</label>
                            <input type="text" name="language" class="form-control" value="${settings.language || 'pt-BR'}">
                        </div>
                    </div>

                    <div class="settings-section">
                        <h3 class="card-title">📧 Configurações de E-mail (SMTP)</h3>
                        <div class="form-group">
                            <label>Servidor SMTP</label>
                            <input type="text" name="smtpServer" class="form-control" value="${settings.smtpServer || ''}" placeholder="Ex: smtp.gmail.com">
                        </div>
                        <div class="form-group">
                            <label>Porta SMTP</label>
                            <input type="text" name="smtpPort" class="form-control" value="${settings.smtpPort || ''}" placeholder="Ex: 587 ou 465">
                        </div>
                        <div class="form-group">
                            <label>Usuário SMTP (E-mail)</label>
                            <input type="text" name="smtpUser" class="form-control" value="${settings.smtpUser || ''}">
                        </div>
                        <div class="form-group">
                            <label>Senha SMTP</label>
                            <input type="password" name="smtpPass" class="form-control" value="${settings.smtpPass || ''}" placeholder="Deixe em branco para não alterar">
                        </div>
                    </div>

                    <div class="settings-section">
                        <h3 class="card-title">📝 Templates de E-mail (Aprovação / Senha)</h3>
                        <div class="form-group">
                            <label>Assunto (Aprovação de Matrícula)</label>
                            <input type="text" name="email_approval_subject" class="form-control" value="${settings.email_approval_subject || ''}">
                        </div>
                        <div class="form-group">
                            <label>Corpo (Aprovação de Matrícula)</label>
                            <textarea name="email_approval_body" class="form-control" style="height: 100px;">${settings.email_approval_body || ''}</textarea>
                        </div>
                         <div class="form-group">
                            <label>Assunto (Redefinição de Senha)</label>
                            <input type="text" name="email_reset_subject" class="form-control" value="${settings.email_reset_subject || ''}">
                        </div>
                        <div class="form-group">
                            <label>Corpo (Redefinição de Senha)</label>
                            <textarea name="email_reset_body" class="form-control" style="height: 100px;">${settings.email_reset_body || ''}</textarea>
                        </div>
                        <small>Placeholders comuns: ${emailPlaceholders.join(', ')}</small>
                    </div>

                    <div class="settings-section">
                        <h3 class="card-title">⏰ Automação de Cobrança</h3>
                        <div class="form-group">
                            <label>Dias de Antecedência para o Lembrete</label>
                            <input type="number" name="reminderDaysBefore" class="form-control" value="${settings.reminderDaysBefore || 3}" min="1" max="30">
                            <small>Quantos dias antes do vencimento o e-mail deve ser enviado.</small>
                        </div>
                        <div class="form-group">
                            <label>Assunto do E-mail de Lembrete</label>
                            <input type="text" name="email_reminder_subject" class="form-control" value="${settings.email_reminder_subject || ''}">
                        </div>
                        <div class="form-group">
                            <label>Corpo do E-mail de Lembrete</label>
                            <textarea name="email_reminder_body" class="form-control" style="height: 150px;">${settings.email_reminder_body || ''}</textarea>
                            <small>Variáveis exclusivas deste e-mail: {{aluno_nome}}, {{curso_nome}}, {{vencimento_data}}, {{valor}}, {{escola_nome}}</small>
                        </div>
                    </div>

                </div>

                <div class="settings-column">
                    <div class="settings-section">
                        <h3 class="card-title">💰 Configurações Financeiras</h3>
                        <div class="form-group">
                            <label>Símbolo da Moeda</label>
                            <input type="text" name="currencySymbol" class="form-control" value="${settings.currencySymbol || 'R$'}">
                        </div>
                        <div class="form-group">
                            <label>Dia Padrão de Vencimento</label>
                            <input type="number" name="defaultDueDay" class="form-control" value="${settings.defaultDueDay || 10}">
                        </div>
                        
                        <div class="form-group checkbox-group">
                            <input type="hidden" name="enableTerminationFine" value="0">
                            <input type="checkbox" id="enableTerminationFine" name="enableTerminationFine" value="1" ${settings.enableTerminationFine == 1 ? 'checked' : ''}>
                            <label for="enableTerminationFine">Habilitar Multa de Rescisão</label>
                        </div>
                         <div class="form-group">
                            <label>Meses para base de cálculo da multa</label>
                            <input type="number" name="terminationFineMonths" class="form-control" value="${settings.terminationFineMonths || 1}">
                        </div>
                    </div>

                    <div class="settings-section">
                        <h3 class="card-title">✨ Integração Gemini AI</h3>
                        <div class="form-group">
                            <label>API Key</label>
                            <input type="password" name="geminiApiKey" class="form-control" value="${settings.geminiApiKey || ''}">
                        </div>
                        <div class="form-group">
                            <label>Endpoint (Opcional)</label>
                            <input type="text" name="geminiApiEndpoint" class="form-control" value="${settings.geminiApiEndpoint || ''}">
                        </div>
                    </div>

                    <div class="settings-section">
                         <h3 class="card-title">💳 Mercado Pago (Integração)</h3>
                         <div class="form-group">
                            <label>Ativar Integração?</label>
                            <select name="mp_active" class="form-control">
                                <option value="false" ${settings.mp_active === 'false' ? 'selected' : ''}>Não</option>
                                <option value="true" ${settings.mp_active === 'true' ? 'selected' : ''}>Sim</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Public Key (Chave Pública)</label>
                            <input type="text" name="mp_public_key" class="form-control" value="${settings.mp_public_key || ''}">
                        </div>
                        <div class="form-group">
                            <label>Access Token (Token de Acesso)</label>
                            <input type="password" name="mp_access_token" class="form-control" value="${settings.mp_access_token || ''}">
                        </div>
                    </div>

                    <div class="settings-section">
                        <h3 class="card-title">🔄 Atualização do Sistema</h3>
                        <div id="update-status-container">
                            <div class="version-display" style="margin-bottom: 15px;">
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