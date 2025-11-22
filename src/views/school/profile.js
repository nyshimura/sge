// src/views/school/profile.js
import { apiCall } from '../../api.js';
import { appState } from '../../state.js';

export async function renderSchoolProfileView() {
    // Garante que temos os dados
    if (!appState.schoolProfile) {
        try {
            const data = await apiCall('getSchoolProfile', {}, 'GET');
            appState.schoolProfile = data.profile;
        } catch (e) {
            return `<p class="error-message">Erro ao carregar perfil: ${e.message}</p>`;
        }
    }
    
    const school = appState.schoolProfile || {};

    return `
        <div class="view-header">
            <h2>Perfil da Escola (UE)</h2>
            <button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar</button>
        </div>

        <div class="card full-width">
            <form onsubmit="window.AppHandlers.handleUpdateSchoolProfile(event)">
                <div class="settings-grid">
                    
                    <div class="settings-section">
                        <h3 class="card-title">Dados Institucionais</h3>
                        
                        <div class="form-group">
                            <label>Nome da Escola</label>
                            <input type="text" name="name" value="${school.name || ''}" required>
                        </div>
                        
                        <div class="form-group">
                            <label>CNPJ</label>
                            <input type="text" name="cnpj" value="${school.cnpj || ''}">
                        </div>

                        <div class="form-group">
                            <label>Endereço Completo</label>
                            <textarea name="address" rows="3">${school.address || ''}</textarea>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 100px; gap: 10px;">
                            <div class="form-group">
                                <label>Cidade</label>
                                <input type="text" name="schoolCity" value="${school.schoolCity || ''}">
                            </div>
                            <div class="form-group">
                                <label>UF</label>
                                <input type="text" name="state" value="${school.state || ''}" maxlength="2">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Telefone</label>
                            <input type="text" name="phone" value="${school.phone || ''}">
                        </div>

                        <h4 style="margin-top: 15px; border-bottom: 1px solid #eee; padding-bottom:5px;">Chave PIX</h4>
                        <div style="display:grid; grid-template-columns: 100px 1fr; gap: 10px;">
                            <div class="form-group">
                                <label>Tipo</label>
                                <select name="pixKeyType">
                                    <option value="CNPJ" ${school.pixKeyType === 'CNPJ' ? 'selected' : ''}>CNPJ</option>
                                    <option value="CPF" ${school.pixKeyType === 'CPF' ? 'selected' : ''}>CPF</option>
                                    <option value="E-mail" ${school.pixKeyType === 'E-mail' ? 'selected' : ''}>E-mail</option>
                                    <option value="Telefone" ${school.pixKeyType === 'Telefone' ? 'selected' : ''}>Telefone</option>
                                    <option value="Aleatória" ${school.pixKeyType === 'Aleatória' ? 'selected' : ''}>Aleatória</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Chave</label>
                                <input type="text" name="pixKey" value="${school.pixKey || ''}">
                            </div>
                        </div>
                    </div>

                    <div class="settings-section">
                        <h3 class="card-title">Identidade Visual</h3>

                        <div class="form-group" style="text-align: center;">
                            <label>Logo da Escola</label>
                            <div style="margin: 10px 0; border: 1px dashed #ccc; padding: 10px; border-radius: 8px;">
                                <img src="${school.profilePicture || 'assets/default-logo.png'}" 
                                     style="max-height: 100px; max-width: 100%; display: block; margin: 0 auto;" 
                                     id="preview-logo">
                            </div>
                            <input type="file" id="logo-upload" accept="image/*" 
                                   onchange="if(this.files[0]) document.getElementById('preview-logo').src = window.URL.createObjectURL(this.files[0])">
                            <small style="display:block; color:#666; margin-top:5px;">Será usado no topo dos documentos.</small>
                        </div>

                        <div class="form-group" style="text-align: center; margin-top: 30px;">
                            <label>Assinatura da Diretoria</label>
                            <div style="margin: 10px 0; border: 1px dashed #ccc; padding: 10px; border-radius: 8px;">
                                <img src="${school.signatureImage || ''}" 
                                     style="max-height: 60px; max-width: 100%; display: block; margin: 0 auto; min-height: 20px;" 
                                     id="preview-sig">
                                ${!school.signatureImage ? '<span style="color:#ccc; font-size:0.8rem;">Sem assinatura</span>' : ''}
                            </div>
                            <input type="file" id="signature-upload" accept="image/*"
                                   onchange="if(this.files[0]) document.getElementById('preview-sig').src = window.URL.createObjectURL(this.files[0])">
                            <small style="display:block; color:#666; margin-top:5px;">Aparecerá nos certificados e contratos.</small>
                        </div>
                    </div>

                </div>

                <button type="submit" class="action-button" style="margin-top: 20px; width: 100%;">Salvar Dados da Escola</button>
            </form>
        </div>
    `;
}