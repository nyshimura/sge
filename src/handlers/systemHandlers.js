// src/handlers/systemHandlers.js
import { apiCall } from '../api.js';
import { appState } from '../state.js';
import { render } from '../router.js';

// Helper para converter arquivo para Base64
export function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result);
        reader.onerror = error => reject(error);
    });
}
// Anexa ao handler global
window.AppHandlers = window.AppHandlers || {};
window.AppHandlers.fileToBase64 = fileToBase64;


// Handler para salvar Configurações Gerais (Logo, Cores, etc)
export async function handleUpdateSystemSettings(event) {
    event.preventDefault();
    const form = event.target;
    const submitButton = form.querySelector('button[type="submit"]');

    if(submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Salvando...';
    }

    const settingsData = {};
    const formData = new FormData(form);

    // Pega todos os campos do formulário
    formData.forEach((value, key) => {
        if (key !== 'certificateBackgroundImageInput_temp') {
             settingsData[key] = value;
        }
    });

    const fileInput = document.getElementById('certificateBackgroundImageInput');
    if (fileInput && fileInput.files.length > 0) {
        try {
            const base64Img = await fileToBase64(fileInput.files[0]);
            settingsData['certificate_background_image'] = base64Img;
        } catch (error) {
            console.error("Erro ao converter imagem:", error);
            alert("Erro ao processar a imagem de fundo.");
            if(submitButton) { submitButton.disabled = false; submitButton.textContent = 'Salvar Configurações Gerais'; }
            return;
        }
    }

    // Checkboxes
    const terminationCheck = form.querySelector('#enableTerminationFine');
    if (terminationCheck) {
        settingsData['enableTerminationFine'] = terminationCheck.checked ? 1 : 0;
    }

    try {
        const responseData = await apiCall('updateSystemSettings', { settings: settingsData });
        
        // Atualiza o estado local
        appState.systemSettings = { ...appState.systemSettings, ...settingsData };
        
        alert(responseData.message || 'Configurações salvas com sucesso!');

    } catch (error) {
        console.error('Erro:', error);
        const errorEl = document.getElementById('settings-error');
        if(errorEl) errorEl.textContent = error.message;
        alert('Erro ao salvar configurações: ' + error.message);
    } finally {
        if(submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Salvar Configurações Gerais';
        }
    }
}

// === NOVA FUNÇÃO CORRIGIDA: Salvar Modelos de Contrato/Termos ===
export async function handleUpdateDocumentTemplates(event) {
    event.preventDefault(); // Impede o envio pela URL (Correção principal)
    const form = event.target;
    const submitButton = form.querySelector('button[type="submit"]');

    if(submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Salvando...';
    }

    const settingsData = {};
    const formData = new FormData(form);

    // Captura os campos de texto (enrollmentContractText, imageTermsText)
    formData.forEach((value, key) => {
        settingsData[key] = value;
    });

    try {
        // Chama a API específica para templates
        const responseData = await apiCall('updateDocumentTemplates', { settings: settingsData });
        
        // Atualiza o estado local para refletir a mudança sem precisar recarregar
        if (appState.systemSettings) {
            appState.systemSettings = { ...appState.systemSettings, ...settingsData };
        }

        alert(responseData.message || 'Modelos atualizados com sucesso!');

    } catch (error) {
        console.error('Erro:', error);
        const errorEl = document.getElementById('doc-template-error');
        if(errorEl) errorEl.textContent = error.message;
        alert('Erro ao salvar modelos: ' + error.message);
    } finally {
        if(submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Salvar Modelos';
        }
    }
}
// ==============================================================

// Handler para Exportar BD
export async function handleExportDatabase() {
    try{
        const data = await apiCall('exportDatabase',{},'GET');
        const str = JSON.stringify(data.exportData, null, 2);
        const blob = new Blob([str],{type:'application/json;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const ts = new Date().toISOString().replace(/[:.]/g,'-');
        a.href = url;
        a.download = `sge_export_${ts}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch(e) {
        console.error(e);
        alert("Erro ao exportar base de dados: " + e.message);
    }
}

// Handler para Verificar Atualização
export async function handleCheckUpdate(btn) {
    const originalText = btn.innerText;
    btn.innerText = "Verificando...";
    btn.disabled = true;
    
    const infoDisplay = document.getElementById('update-info-display');
    const btnUpdate = document.getElementById('btn-perform-update');
    const localVer = document.getElementById('local-version');
    const remoteVer = document.getElementById('remote-version');

    try {
        const response = await fetch('api/handlers/update_handler.php?action=check');
        const data = await response.json();

        if(data.error) { 
            alert(data.error); 
            btn.innerText = originalText;
            return; 
        }

        if (infoDisplay) infoDisplay.style.display = 'block';
        if (localVer) localVer.innerText = data.local_version;
        if (remoteVer) remoteVer.innerText = data.remote_version;

        if (data.has_update) {
            btn.innerText = "Atualização Disponível!";
            if (btnUpdate) btnUpdate.style.display = 'inline-block';
        } else {
            btn.innerText = "Sistema Atualizado";
            if (btnUpdate) btnUpdate.style.display = 'none';
        }
    } catch (e) {
        console.error(e);
        alert("Erro ao verificar atualizações.");
        btn.innerText = originalText;
    } finally {
        btn.disabled = false;
    }
}

// Handler para Realizar Atualização
export async function handlePerformUpdate(btn) {
    if(!confirm("Atenção: O sistema será atualizado. Recomenda-se backup. Continuar?")) return;
    
    const originalText = btn.innerText;
    btn.innerText = "Baixando e Instalando...";
    btn.disabled = true;
    
    try {
        const response = await fetch('api/handlers/update_handler.php?action=update');
        
        if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
        const data = await response.json();
        
        if(data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert("Erro: " + data.message);
            btn.innerText = "Tentar Novamente";
            btn.disabled = false;
        }
    } catch (e) {
        console.error(e);
        alert("Erro crítico na atualização.");
        btn.innerText = "Erro";
        btn.disabled = false;
    }
}

// Atribuindo ao objeto global para serem acessíveis via onclick no HTML
window.AppHandlers.handleUpdateSystemSettings = handleUpdateSystemSettings;
window.AppHandlers.handleUpdateDocumentTemplates = handleUpdateDocumentTemplates; // <<< ADICIONADO
window.AppHandlers.handleExportDatabase = handleExportDatabase;
window.AppHandlers.handleCheckUpdate = handleCheckUpdate;
window.AppHandlers.handlePerformUpdate = handlePerformUpdate;