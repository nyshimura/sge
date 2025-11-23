// src/views/user/management.js
import { apiCall } from '../../api.js';
import { appState } from '../../state.js';


// --- FUNÇÕES LOCAIS (Não precisam ser exportadas ou globais) ---

/**
 * Função interna para processar a mudança de cargo.
 * Como é local, ela tem acesso garantido ao 'apiCall' importado acima.
 */
async function processRoleChange(userId, newRole) {
    if (!confirm(`Tem certeza que deseja alterar o cargo deste usuário para "${newRole}"?`)) {
        // Se cancelar, recarrega a lista para voltar o select ao valor original
        handleManagementFilterChange();
        return;
    }

    try {
        // Chamada direta à API importada (sem window.AppHandlers)
        const response = await apiCall('updateUserRole', { userId, newRole });
        alert(response.message || 'Cargo atualizado com sucesso!');
        
        // Atualiza a lista para refletir mudanças
        handleManagementFilterChange();

    } catch (error) {
        console.error("Erro ao mudar cargo:", error);
        alert('Erro ao atualizar cargo: ' + (error.message || error));
        handleManagementFilterChange(); // Recarrega em caso de erro
    }
}

/**
 * Adiciona os eventos aos selects após eles serem desenhados na tela.
 * Esta é a chave da nova abordagem.
 */
function attachSelectListeners() {
    // Busca todos os selects que criamos com a classe específica
    const selects = document.querySelectorAll('.user-role-select');
    
    selects.forEach(select => {
        // Remove ouvintes antigos para evitar duplicação (boa prática)
        select.onchange = null; 
        
        // Adiciona o novo ouvinte diretamente via JS
        select.onchange = (event) => {
            const userId = event.target.dataset.userid; // Pega o ID do atributo data-userid
            const newRole = event.target.value;         // Pega o valor selecionado
            
            // Chama a função local
            processRoleChange(userId, newRole);
        };
    });
}

// --- FUNÇÕES EXPORTADAS (Lógica da View) ---

// 1. Função de Filtragem e Renderização
async function handleManagementFilterChange() {
    const tbody = document.getElementById('user-list-body');
    if (!tbody) return;

    const roleEl = document.getElementById('filter-role');
    const courseEl = document.getElementById('filter-course');
    const searchEl = document.getElementById('filter-search');

    const role = roleEl ? roleEl.value : 'all';
    const courseId = courseEl ? courseEl.value : '';
    const search = searchEl ? searchEl.value : '';

    tbody.innerHTML = '<tr><td colspan="4" style="padding: 20px; text-align: center; color: var(--text-muted);">Buscando...</td></tr>';

    try {
        const response = await apiCall('getFilteredUsers', { role, courseId, search }, 'GET');
        const users = response.users || [];

        if (users.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="padding: 20px; text-align: center;">Nenhum usuário encontrado.</td></tr>';
            return;
        }

        // Renderiza o HTML
        // NOTA: Removemos o 'onchange="..."' do HTML e adicionamos classe e data-attribute
        tbody.innerHTML = users.map(user => {
            const currentRole = user.role || 'unassigned';
            return `
            <tr style="border-bottom: 1px solid var(--border-color);">
                <td style="padding: 10px;">
                    <strong>${user.firstName} ${user.lastName || ''}</strong>
                </td>
                <td style="padding: 10px;">${user.email}</td>
                <td style="padding: 10px;">
                    <select class="user-role-select" 
                            data-userid="${user.id}"
                            style="padding: 4px; border-radius: 4px; border: 1px solid var(--border-color); font-size: 0.9rem; background-color: var(--input-bg); color: var(--text-color);">
                        <option value="unassigned" ${currentRole === 'unassigned' ? 'selected' : ''}>Novo/Sem Cargo</option>
                        <option value="student" ${currentRole === 'student' ? 'selected' : ''}>Aluno</option>
                        <option value="teacher" ${currentRole === 'teacher' ? 'selected' : ''}>Professor</option>
                        <option value="admin" ${currentRole === 'admin' ? 'selected' : ''}>Admin</option>
                        <option value="superadmin" ${currentRole === 'superadmin' ? 'selected' : ''}>Super Admin</option>
                    </select>
                </td>
                <td style="padding: 10px; text-align: right;">
                    <button class="action-button secondary" onclick="window.AppHandlers.handleNavigateToProfile(${user.id})" style="padding: 4px 8px; font-size: 0.8rem;">Ver Perfil</button>
                </td>
            </tr>
            `;
        }).join('');

        // --- PASSO CRUCIAL: Ativa os ouvintes após desenhar o HTML ---
        attachSelectListeners();

    } catch (e) {
        console.error("Erro filtro:", e);
        tbody.innerHTML = `<tr><td colspan="4" style="padding: 20px; text-align: center; color: var(--danger-color);">Erro: ${e.message}</td></tr>`;
    }
}

// 2. Inicialização (Loop de verificação)
function initUserList() {
    const listBody = document.getElementById('user-list-body');
    if (listBody) {
        handleManagementFilterChange();
    } else {
        setTimeout(initUserList, 100);
    }
}

// --- RENDERIZAÇÃO PRINCIPAL ---

export async function renderUserManagementView() {
    let courses = [];
    try {
        const response = await apiCall('getCourses', {}, 'GET');
        courses = response.courses || [];
    } catch (e) {
        console.warn("Aviso: Não foi possível carregar cursos.", e);
    }

    // HTML Estrutural
    // Note que os selects de filtro no topo ainda usam window.AppHandlers pois são estáticos,
    // mas a lista dinâmica de usuários agora é autônoma.
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

    setTimeout(initUserList, 50);
    return html;
}

// --- REGISTRO GLOBAL ---
// Apenas handleManagementFilterChange precisa ser global para os filtros do topo.
// A mudança de cargo agora é interna e não depende disso.
window.AppHandlers = window.AppHandlers || {};
window.AppHandlers.handleManagementFilterChange = handleManagementFilterChange;
// (handleRoleChange foi removido do global intencionalmente pois agora é interno)