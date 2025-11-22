// src/handlers/profileHandlers.js
import { apiCall } from '../api.js';
import { appState } from '../state.js';

// --- HELPER: Converter arquivo para Base64 ---
function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result);
        reader.onerror = error => reject(error);
    });
}

// --- HELPER: Pré-visualizar imagem (Global) ---
export function previewImage(input, targetId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const imgElement = document.getElementById(targetId);
            if (imgElement) {
                imgElement.src = e.target.result;
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// --- ATUALIZAR PERFIL DO USUÁRIO ---
export async function handleUpdateUserProfile(event) {
    event.preventDefault(); // IMPEDE O RECARREGAMENTO DA PÁGINA
    const form = event.target;
    const submitButton = form.querySelector('button[type="submit"]');
    
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Salvando...';
    }

    const formData = new FormData(form);
    const profileData = {};

    // 1. Coleta campos de texto
    formData.forEach((value, key) => {
        // Ignora o arquivo cru, vamos processá-lo abaixo
        if (!(value instanceof File)) {
            profileData[key] = value;
        }
    });

    // 2. Processa Upload de FOTO (se houver)
    let fileInput = form.querySelector('input[type="file"]');
    // Fallback para encontrar o input se o querySelector falhar
    if (!fileInput) {
        fileInput = document.getElementById('profile-upload');
    }
    
    if (fileInput && fileInput.files.length > 0) {
        try {
            // Converte imagem para texto (Base64) para enviar via JSON
            profileData['profilePicture'] = await fileToBase64(fileInput.files[0]);
        } catch (e) {
            console.error("Erro ao processar foto:", e);
            alert("Erro ao processar a foto de perfil.");
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Salvar Alterações';
            }
            return;
        }
    }

    try {
        // Envia para API (dados planos para user_handlers.php)
        const response = await apiCall('updateUserProfile', profileData);
        
        alert(response.message || 'Perfil atualizado com sucesso!');
        
        // Atualiza o estado local para refletir a mudança sem F5
        if (appState.currentUser && appState.currentUser.id == profileData.id) {
            appState.currentUser = { ...appState.currentUser, ...profileData };
            localStorage.setItem('currentUser', JSON.stringify(appState.currentUser));
        }
        
        // Atualiza a foto no topo (Header) se ela mudou
        if (profileData['profilePicture']) {
             const headerAvatars = document.querySelectorAll('.user-avatar, .profile-pic-header, #header-user-avatar, .logo-img'); 
             headerAvatars.forEach(img => img.src = profileData['profilePicture']);
        }

    } catch (error) {
        console.error(error);
        alert('Erro ao atualizar perfil: ' + error.message);
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Salvar Alterações';
        }
    }
}

// --- ATUALIZAR PERFIL DA ESCOLA (UE) ---
export async function handleUpdateSchoolProfile(event) {
    event.preventDefault();
    const form = event.target;
    const submitButton = form.querySelector('button[type="submit"]');
    
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Salvando...';
    }

    const profileData = {};
    const formData = new FormData(form);

    formData.forEach((value, key) => {
        if (!(value instanceof File)) {
            profileData[key] = value;
        }
    });

    // Logo
    const logoInput = document.getElementById('logo-upload');
    if (logoInput && logoInput.files.length > 0) {
        try {
            profileData['profilePicture'] = await fileToBase64(logoInput.files[0]);
        } catch (e) {
            console.error(e);
            alert("Erro ao processar o Logo.");
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Salvar Dados da Escola';
            }
            return;
        }
    }

    // Assinatura
    const sigInput = document.getElementById('signature-upload');
    if (sigInput && sigInput.files.length > 0) {
        try {
            profileData['signatureImage'] = await fileToBase64(sigInput.files[0]);
        } catch (e) {
            console.error(e);
            alert("Erro ao processar a Assinatura.");
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Salvar Dados da Escola';
            }
            return;
        }
    }

    try {
        // Nota: School profile espera empacotado em 'profile'
        const response = await apiCall('updateSchoolProfile', { profile: profileData });
        alert(response.message || 'Perfil da escola atualizado!');
        appState.schoolProfile = { ...appState.schoolProfile, ...profileData };

        if (profileData['profilePicture']) {
             const headerLogo = document.querySelector('.logo-img');
             if(headerLogo) headerLogo.src = profileData['profilePicture'];
        }
    } catch (error) {
        alert('Erro: ' + error.message);
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Salvar Dados da Escola';
        }
    }
}

// REGISTRO GLOBAL DAS FUNÇÕES (Essencial para o HTML funcionar)
window.AppHandlers = window.AppHandlers || {};
window.AppHandlers.handleUpdateUserProfile = handleUpdateUserProfile;
window.AppHandlers.handleUpdateSchoolProfile = handleUpdateSchoolProfile;
window.AppHandlers.previewImage = previewImage;