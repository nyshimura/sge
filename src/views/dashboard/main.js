// src/views/dashboard/main.js
import { apiCall } from '../../api.js';
import { appState } from '../../state.js';
import { renderAdminView } from './admin.js';
import { renderStudentView } from './student.js';
import { renderTeacherView } from './teacher.js';

/**
 * Função principal para renderizar o dashboard apropriado baseado na função do usuário.
 * Busca os dados necessários da API e distribui para as views.
 */
export async function renderDashboard(appRoot) {
    const { currentUser } = appState;

    if (!currentUser) {
        window.AppHandlers.navigateTo('login');
        return '<div class="loading-placeholder">Redirecionando...</div>';
    }

    if (appRoot) appRoot.innerHTML = '<div class="loading-placeholder">Carregando seu painel...</div>';

    try {
        // 1. Busca dados do Backend
        const data = await apiCall('getDashboardData', { userId: currentUser.id, role: currentUser.role }, 'GET');

        if (!data || typeof data !== 'object') {
            throw new Error("Dados inválidos recebidos do servidor.");
        }

        // 2. Atualiza o estado global (CORREÇÃO AQUI)
        // Adicionamos 'myEnrollments' e 'myCourses' que estavam faltando!
        appState.courses = data.courses || [];
        appState.enrollments = data.enrollments || []; // Para Admin
        appState.myEnrollments = data.myEnrollments || []; // <--- PARA ALUNO (Faltava isso)
        appState.myCourses = data.myCourses || []; // <--- PARA PROFESSOR (Faltava isso)
        
        appState.attendance = data.attendance || []; 
        appState.payments = data.payments || []; 
        appState.users = data.users || []; 
        appState.teachers = data.teachers || []; 

        // 3. Renderiza a view específica passando os dados completos
        const dashboardHtml = renderDashboardHelper(currentUser.id, currentUser.role, appState); 

        if (typeof dashboardHtml !== 'string' || dashboardHtml.trim() === '') {
            console.error("Erro: View retornou vazio.", dashboardHtml);
            throw new Error("Falha ao gerar visualização.");
        }

        return dashboardHtml; 

    } catch (error) {
        console.error("Erro dashboard:", error);
        return `<div class="error-placeholder">
            <h3>Ops! Algo deu errado.</h3>
            <p>${error.message}</p>
            <button onclick="location.reload()">Tentar Novamente</button>
        </div>`;
    }
}

/**
 * Escolhe qual tela desenhar
 */
function renderDashboardHelper(userId, role, data) { 
    switch (role) {
        case 'admin':
        case 'superadmin':
            return renderAdminView(userId, data);
        case 'student':
            // Agora 'data' contém 'myEnrollments' corretamente!
            return renderStudentView(userId, data);
        case 'teacher':
            return renderTeacherView(userId, data);
        case 'unassigned':
            return '<div class="card"><h3 class="card-title">Acesso Pendente</h3><p>Aguarde a liberação do seu acesso pela secretaria.</p></div>';
        default:
            return '<div class="card"><p>Erro: Perfil de usuário desconhecido.</p></div>';
    }
}