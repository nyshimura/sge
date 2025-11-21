// src/handlers/systemHandlers.js
import { apiCall } from '../api.js';
import { appState } from '../state.js';
import { render } from '../router.js';

// ... (outras funções fileToBase64, handleUpdateSystemSettings, handleExportDatabase mantidas iguais) ...

// Helper para converter arquivo para Base64
export function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result);
        reader.onerror = error => reject(error);
    });
}
window.AppHandlers = window.AppHandlers || {};
window.AppHandlers.fileToBase64 = fileToBase64;

export async function handleUpdateSystemSettings(event) {
    event.preventDefault();
    const form = event.target;
    const submitButton = form.querySelector('button[type="submit"]');
    if(submitButton) { submitButton.disabled = true; submitButton.textContent = 'Salvando...'; }

    const settingsData = {};
    const formData = new FormData(form);
    formData.forEach((value, key) => { if (key !== 'certificateBackgroundImageInput_temp') settingsData[key] = value; });

    const fileInput = document.getElementById('certificateBackgroundImageInput');
    if (fileInput && fileInput.files.length > 0) {
        try {
            settingsData['certificate_background_image'] = await fileToBase64(fileInput.files[0]);
        } catch (error) {
            alert("Erro imagem."); if(submitButton) submitButton.disabled = false; return;
        }
    }
    settingsData['enableTerminationFine'] = form.querySelector('#enableTerminationFine')?.checked ? 1 : 0;

    try {
        const response = await apiCall('updateSystemSettings', { settings: settingsData });
        if (response.success) {
             appState.systemSettings = { ...appState.systemSettings, ...settingsData };
             alert('Configurações salvas com sucesso!');
        } else { throw new Error(response.message); }
    } catch (error) { alert('Erro: ' + error.message); } 
    finally { if(submitButton) { submitButton.disabled = false; submitButton.textContent = 'Salvar Configurações Gerais'; } }
}

export async function handleExportDatabase() {
    try{
        const data = await apiCall('exportDatabase',{},'GET');
        const str = JSON.stringify(data.exportData, null, 2);
        const blob = new Blob([str],{type:'application/json;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const ts = new Date().toISOString().replace(/[:.]/g,'-');
        a.href = url; a.download = `sge_export_${ts}.json`;
        document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
    } catch(e) { alert("Erro export: " + e.message); }
}

// === FUNÇÕES DE ATUALIZAÇÃO ===

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

        if(data.error) { alert(data.error); return; }

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
        console.error(e); alert("Erro ao verificar atualizações."); btn.innerText = originalText;
    } finally {
        btn.disabled = false;
    }
}

export async function handlePerformUpdate(btn) {
    if(!confirm("Atenção: O sistema será atualizado. Recomenda-se backup. Continuar?")) return;
    
    const originalText = btn.innerText;
    btn.innerText = "Baixando e Instalando...";
    btn.disabled = true;
    
    try {
        const response = await fetch('api/handlers/update_handler.php?action=update');
        
        // Verifica se a resposta é válida antes de converter json
        if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
        
        const data = await response.json();
        
        if(data.success) {
            // === AQUI ESTAVA O PROBLEMA ===
            // Antes: alert("Atualizado com sucesso!..."); (Ignorava o log do banco)
            // Agora: Mostra exatamente o que o PHP retornou
            alert(data.message); 
            window.location.reload();
        } else {
            alert("Erro: " + data.message);
            btn.innerText = "Tentar Novamente";
            btn.disabled = false;
        }
    } catch (e) {
        console.error(e);
        alert("Erro crítico na atualização (Veja o console para detalhes).");
        btn.innerText = "Erro";
        btn.disabled = false;
    }
}

window.AppHandlers.handleUpdateSystemSettings = handleUpdateSystemSettings;
window.AppHandlers.handleExportDatabase = handleExportDatabase;
window.AppHandlers.handleCheckUpdate = handleCheckUpdate;
window.AppHandlers.handlePerformUpdate = handlePerformUpdate;
