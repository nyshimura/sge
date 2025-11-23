// src/views/user/management.js
import { apiCall } from '../../api.js';
import { appState } from '../../state.js';

// --- DEFINIÇÃO DAS FUNÇÕES (Escopo do Módulo) ---

// 1. Função de Filtragem
async function handleManagementFilterChange() {
    const tbody = document.getElementById('user-list-body');
    if (!tbody) return;

    // Captura segura dos elementos
    const roleEl = document.getElementById('filter-role');
    const courseEl = document.getElementById('filter-course');
    const searchEl = document.getElementById('filter-search');

    // Valores padrão
    const role = roleEl ? roleEl.value : 'all';
    const courseId = courseEl ? courseEl.value : '';
    const search = searchEl ? searchEl.value : '';

    tbody.innerHTML = '<tr><td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">Buscando...</td></tr>';

    try {
        // Chama a API
        const response = await apiCall('getFilteredUsers', { role, courseId, search }, 'GET');
        const users = response.users || [];

        if (users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="padding: 20px; text-align: center;">Nenhum usuário encontrado.</td></tr>';
            return;
        }

        // Renderiza linhas da tabela
        tbody.innerHTML = users.map(user => `
            <tr style="border-bottom: 1px solid var(--border-color);">
                <td style="padding: 10px;">
                    <strong>${user.firstName} ${user.lastName || ''}</strong>
                </td>
                <td style="padding: 10px;">${user.email}</td>
                <td style="padding: 10px;">
                    <select onchange="window.AppHandlers.handleRoleChange(event, ${user.id})" 
                            style="padding: 4px; border-radius: 4px; border: 1px solid var(--border-color); font-size: 0.9rem; background-color: var(--input-bg); color: var(--text-color);">
                        <option value="unassigned" ${user.role === 'unassigned' ? 'selected' : ''}>Novo/Sem Cargo</option>
                        <option value="student" ${user.role === 'student' ? 'selected' : ''}>Aluno</option>
                        <option value="teacher" ${user.role === 'teacher' ? 'selected' : ''}>Professor</option>
                        <option value="admin" ${user.role === 'admin' ? 'selected' : ''}>Admin</option>
                        <option value="superadmin" ${user.role === 'superadmin' ? 'selected' : ''}>Super Admin</option>
                    </select>
                </td>
                <td style="padding: 10px; text-align: right;">
                    <button class="action-button secondary" onclick="window.AppHandlers.handleNavigateToProfile(${user.id})" style="padding: 4px 8px; font-size: 0.8rem;">Ver Perfil</button>
                </td>
            </tr>
        `).join('');

    } catch (e) {
        console.error("Erro filtro:", e);
        tbody.innerHTML = `<tr><td colspan="4" style="padding: 20px; text-align: center; color: var(--danger-color);">Erro: ${e.message}</td></tr>`;
    }
}

// 2. Função de Mudança de Cargo
async function handleRoleChange(event, userId) {
    const newRole = event.target.value;
    
    if(!confirm(`Tem certeza que deseja alterar o cargo deste usuário para "${newRole}"?`)) {
        handleManagementFilterChange(); // Reseta a lista visualmente
        return; 
    }

    try {
        // Usa o apiCall importado no topo
        const response = await apiCall('updateUserRole', { userId, newRole });
        alert(response.message || 'Cargo atualizado com sucesso!');
    } catch (error) {
        console.error("Erro mudança cargo:", error);
        alert('Erro ao atualizar cargo: ' + error.message);
        handleManagementFilterChange(); // Recarrega lista
    }
}

// 3. Função Auxiliar de Inicialização
function initUserList() {
    const listBody = document.getElementById('user-list-body');
    if (listBody) {
        // Chama a função interna diretamente (sem window.AppHandlers)
        handleManagementFilterChange();
    } else {
        setTimeout(initUserList, 100);
    }
}


// --- EXPORTAÇÃO PARA O ROTEADOR (View Principal) ---

export async function renderUserManagementView() {
    // Busca cursos para o filtro
    let courses = [];
    try {
        const courseData = await apiCall('getCourses', {}, 'GET');
        courses = courseData.courses || [];
    } catch (e) {
        console.warn("Aviso: Não foi possível carregar cursos.", e);
    }

    const html = `
        <div class="view-header">
            <h2>Gerenciamento de Usuários</h2>
            <button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar ao Painel</button>
        </div>

        <div class="card full-width">
            <div class="settings-grid" style="align-items: end; gap: 10px;">
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label style="font-size: 0.85rem;">Função</label>
                    <select id="filter-role" onchange="window.AppHandlers.handleManagementFilterChange()">
                        <option value="all">Todos</option>
                        <option value="student">Alunos</option>
                        <option value="teacher">Professores</option>
                        <option value="admin">Administradores</option>
                        <option value="superadmin">Super Admins</option>
                        <option value="unassigned">Sem Cargo</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label style="font-size: 0.85rem;">Filtrar por Curso</label>
                    <select id="filter-course" onchange="window.AppHandlers.handleManagementFilterChange()">
                        <option value="">Todos os Cursos</option>
                        ${courses.map(c => `<option value="${c.id}">${c.name}</option>`).join('')}
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0; flex-grow: 1;">
                    <label style="font-size: 0.85rem;">Buscar Nome/Email</label>
                    <div style="display: flex; gap: 5px;">
                        <input type="text" id="filter-search" placeholder="Digite para buscar..." 
                               onkeyup="if(event.key === 'Enter') window.AppHandlers.handleManagementFilterChange()">
                        <button class="action-button secondary" onclick="window.AppHandlers.handleManagementFilterChange()">
                            🔍
                        </button>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <button class="action-button" onclick="window.AppHandlers.handleLogout()">+ Novo (Sair e Criar)</button>
                </div>
            </div>
        </div>

        <div class="card full-width">
            <h3 class="card-title">Lista de Usuários</h3>
            <div class="table-responsive">
                <table class="table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background-color: var(--secondary-bg); text-align: left;">
                            <th style="padding: 10px;">Nome</th>
                            <th style="padding: 10px;">Email</th>
                            <th style="padding: 10px;">Função</th>
                            <th style="padding: 10px; text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="user-list-body">
                        <tr><td colspan="4" style="padding: 20px; text-align: center;">Carregando usuários...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    `;

    // Inicia o loop de verificação para carregar a lista
    setTimeout(initUserList, 50);

    return html;
}

// --- REGISTRO GLOBAL DE HANDLERS (Crucial) ---
// Isso garante que o HTML consiga chamar as funções via onclick/onchange
window.AppHandlers = window.AppHandlers || {};
window.AppHandlers.handleManagementFilterChange = handleManagementFilterChange;
window.AppHandlers.handleRoleChange = handleRoleChange;