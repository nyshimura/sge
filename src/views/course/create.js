// src/views/course/create.js
import { apiCall } from '../../api.js'; // Adicionado import apiCall
import { appState } from '../../state.js';

export async function renderCreateCourseView() { // Agora é async
    // 1. Garante que os professores estejam carregados
    if (!appState.users.some(u => u.role === 'teacher')) {
        try {
            const data = await apiCall('getTeachers', {}, 'GET');
            const teacherIds = new Set(appState.users.filter(u => u.role === 'teacher').map(t => t.id));
            (data.teachers || []).forEach(t => {
                if (!teacherIds.has(t.id)) appState.users.push(t);
            });
        } catch (e) {
            console.error("Erro ao buscar professores:", e);
        }
    }

    const teachers = appState.users.filter(u => u.role === 'teacher');
    const isAiEnabled = appState.systemSettings && appState.systemSettings.geminiApiKey;

    // Lista de dias para gerar o HTML
    const days = [
        { id: 'seg', label: 'Segunda-feira' },
        { id: 'ter', label: 'Terça-feira' },
        { id: 'qua', label: 'Quarta-feira' },
        { id: 'qui', label: 'Quinta-feira' },
        { id: 'sex', label: 'Sexta-feira' },
        { id: 'sab', label: 'Sábado' },
        { id: 'dom', label: 'Domingo' }
    ];

    // Gera o HTML dos dias
    const scheduleHtml = days.map(d => `
        <div class="schedule-row" style="display:flex; flex-direction:column; margin-bottom:8px; padding:8px; border:1px solid #eee; border-radius:4px;">
            <label style="display:flex; align-items:center; font-weight:bold; cursor:pointer;">
                <input type="checkbox" class="day-checkbox" value="${d.id}" style="margin-right:10px; width:auto;" 
                       onchange="document.getElementById('times-${d.id}').style.display = this.checked ? 'flex' : 'none'; 
                                 document.querySelectorAll('#times-${d.id} input').forEach(i => i.required = this.checked);">
                ${d.label}
            </label>
            <div id="times-${d.id}" style="display:none; gap:10px; margin-top:8px; align-items:center;">
                <div style="flex:1;">
                    <small>Início</small>
                    <input type="time" name="start_${d.id}" style="margin-top:2px;">
                </div>
                <div style="flex:1;">
                    <small>Fim</small>
                    <input type="time" name="end_${d.id}" style="margin-top:2px;">
                </div>
            </div>
        </div>
    `).join('');

    return `
        <div class="view-header">
            <h2>Criar Novo Curso</h2>
            <button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar ao Painel</button>
        </div>
        <div class="card full-width">
            <form id="create-course-form" onsubmit="window.AppHandlers.handleCreateCourse(event)">
                <div class="form-group">
                    <label for="courseName">Nome do Curso</label>
                    <input type="text" id="courseName" name="courseName" required>
                </div>

                <div class="form-group">
                    <div class="form-group-header">
                        <label for="courseDescription">Descrição</label>
                        ${isAiEnabled ? `<button type="button" class="action-button secondary generate-ai-button" onclick="window.AppHandlers.handleGenerateDescription('create-course-form')">Gerar com IA ✨</button>` : ''}
                    </div>
                    <textarea id="courseDescription" name="courseDescription" rows="3" required></textarea>
                </div>

                <div class="form-group">
                    <label for="teacherId">Professor</label>
                    <select id="teacherId" name="teacherId" required>
                        <option value="">Selecione...</option>
                        ${teachers.map(t => `<option value="${t.id}">${t.firstName} ${t.lastName || ''}</option>`).join('')}
                    </select>
                </div>

                <div class="form-group">
                     <label for="installments">Número de Parcelas</label>
                     <input type="number" id="installments" name="installments" min="1" placeholder="Ex: 12">
                </div>

                <div class="form-group">
                    <label for="carga_horaria">Carga Horária (p/ certificado)</label>
                    <input type="text" id="carga_horaria" name="carga_horaria" placeholder="Ex: 40 horas">
                </div>

                <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
                
                <div class="form-group">
                    <label>Dias e Horários das Aulas</label>
                    <p style="font-size:0.9em; color:#666; margin-bottom:10px;">Selecione os dias da semana e defina os horários.</p>
                    ${scheduleHtml}
                </div>

                <button type="submit" class="action-button">Criar Curso</button>
            </form>
        </div>
    `;
}
