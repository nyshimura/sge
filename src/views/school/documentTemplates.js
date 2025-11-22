// src/views/school/documentTemplates.js
import { apiCall } from '../../api.js';
import { appState } from '../../state.js';

export async function renderDocumentTemplatesView() {
    // Busca settings se necessário (contém os textos atuais dos templates)
    if (!appState.systemSettings || appState.systemSettings.enrollmentContractText === undefined) {
        try {
            const data = await apiCall('getSystemSettings', {}, 'GET');
            appState.systemSettings = data.settings;
        } catch(e) {
             console.error("Erro ao buscar settings para templates:", e);
             return `<div class="view-header"><h2>Erro</h2><button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar</button></div><p>Erro ao carregar.</p>`
        }
    }
    const settings = appState.systemSettings || {}; // Usa objeto vazio como fallback

    // Placeholders específicos para Contrato/Termos
    const placeholders = [
        { group: 'Aluno', items: ['{{aluno_nome}}', '{{aluno_email}}', '{{aluno_rg}}', '{{aluno_cpf}}', '{{aluno_endereco}}'] },
        { group: 'Responsável', items: ['{{responsavel_nome}}', '{{responsavel_rg}}', '{{responsavel_cpf}}', '{{responsavel_email}}', '{{responsavel_telefone}}'] },
        { group: 'Contratante (Auto)', items: ['{{contratante_nome}}', '{{contratante_rg}}', '{{contratante_cpf}}', '{{contratante_email}}', '{{clausula_financeira}}', '{{contratante_endereco}}'] },
        { group: 'Curso', items: ['{{curso_nome}}', '{{curso_mensalidade}}', '{{curso_mensalidade_extenso}}', '{{vencimento_dia}}'] },
        { group: 'Escola', items: ['{{escola_nome}}', '{{escola_cnpj}}', '{{escola_endereco}}'] },
        { group: 'Data', items: ['{{data_atual_extenso}}'] }
    ];

    return `
        <div class="view-header">
            <h2>Gerir Modelos de Contrato e Termos</h2>
            <button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar</button>
        </div>

        <form id="doc-template-form" class="card full-width" onsubmit="window.AppHandlers.handleUpdateDocumentTemplates(event)">
            <div class="document-editor-container">

                <div class="document-editor">
                    <h3 class="card-title">Modelo do Contrato de Matrícula</h3>
                    <div class="form-group">
                        <textarea id="enrollmentContractText" name="enrollmentContractText" rows="20" placeholder="Cole ou digite o texto do contrato aqui...">${settings.enrollmentContractText || ''}</textarea>
                    </div>
                </div>

                <div class="placeholders-sidebar">
                    <h3 class="card-title">Placeholders Disponíveis</h3>
                    <p>Clique para copiar.</p>
                    ${placeholders.map(group => `
                        <div class="placeholder-group">
                            <h4>${group.group}</h4>
                            ${group.items.map(ph => `<button type="button" class="placeholder-button" onclick="navigator.clipboard.writeText('${ph}')">${ph}</button>`).join('')}
                        </div>
                    `).join('')}
                </div>

                <div class="document-editor" style="grid-column: 1;">
                    <h3 class="card-title">Modelo do Termo de Uso de Imagem</h3>
                    <div class="form-group">
                        <textarea id="imageTermsText" name="imageTermsText" rows="20" placeholder="Cole ou digite o texto do termo de uso de imagem aqui...">${settings.imageTermsText || ''}</textarea>
                    </div>
                </div>

            </div>
             <p class="error-message" id="doc-template-error"></p>
            <button type="submit" class="action-button" style="margin-top: 1.5rem;">Salvar Modelos</button>
        </form>
    `;
}