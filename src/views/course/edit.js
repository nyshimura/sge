// src/views/course/edit.js
import { apiCall } from '../../api.js';
import { appState } from '../../state.js';

export async function renderEditCourseView(course) {
    // Garante que a lista de professores esteja carregada
    if (!appState.users.some(u => u.role === 'teacher')) {
        try { 
            const data = await apiCall('getTeachers', {}, 'GET'); 
            const teacherIds = new Set(appState.users.filter(u => u.role === 'teacher').map(t => t.id)); 
            (data.teachers || []).forEach(t => { if (!teacherIds.has(t.id)) appState.users.push(t); }); 
        } catch(e) { console.error("Erro buscar professores:", e); }
    }
    
    const teachers = appState.users.filter(u => u.role === 'teacher');
    const isAiEnabled = appState.systemSettings && appState.systemSettings.geminiApiKey;

    // === LÓGICA DE RECUPERAÇÃO DE HORÁRIOS ===
    let schedule = [];
    try {
        if (course.schedule_json) {
            // Caso 1: Curso novo ou já migrado (usa JSON)
            schedule = JSON.parse(course.schedule_json);
        } else if (course.dayOfWeek) {
            // Caso 2: Curso antigo (converte legado para formato novo visualmente)
            const mapLegacy = {
                'Segunda-feira': 'seg', 'Terça-feira': 'ter', 'Quarta-feira': 'qua',
                'Quinta-feira': 'qui', 'Sexta-feira': 'sex', 'Sábado': 'sab', 'Domingo': 'dom'
            };
            const dayId = mapLegacy[course.dayOfWeek];
            if (dayId) {
                schedule.push({ day_id: dayId, start: course.startTime, end: course.endTime });
            }
        }
    } catch(e) {
        console.error("Erro ao processar horários:", e);
    }

    // Função auxiliar para verificar se o dia está ativo
    const getDayData = (id) => schedule.find(s => s.day_id === id);

    // Lista de dias
    const days = [
        { id: 'seg', label: 'Segunda-feira' },
        { id: 'ter', label: 'Terça-feira' },
        { id: 'qua', label: 'Quarta-feira' },
        { id: 'qui', label: 'Quinta-feira' },
        { id: 'sex', label: 'Sexta-feira' },
        { id: 'sab', label: 'Sábado' },
        { id: 'dom', label: 'Domingo' }
    ];

    // Gera HTML dos dias (já preenchido com dados do banco)
    const scheduleHtml = days.map(d => {
        const dayData = getDayData(d.id);
        const isChecked = !!dayData;
        const startVal = dayData ? dayData.start : '';
        const endVal = dayData ? dayData.end : '';

        return `
        <div class="schedule-row" style="display:flex; flex-direction:column; margin-bottom:8px; padding:8px; border:1px solid #eee; border-radius:4px;">
            <label style="display:flex; align-items:center; font-weight:bold; cursor:pointer;">
                <input type="checkbox" class="day-checkbox" value="${d.id}" ${isChecked ? 'checked' : ''} style="margin-right:10px; width:auto;" 
                       onchange="document.getElementById('times-${d.id}').style.display = this.checked ? 'flex' : 'none'; 
                                 document.querySelectorAll('#times-${d.id} input').forEach(i => i.required = this.checked);">
                ${d.label}
            </label>
            <div id="times-${d.id}" style="display:${isChecked ? 'flex' : 'none'}; gap:10px; margin-top:8px; align-items:center;">
                <div style="flex:1;">
                    <small>Início</small>
                    <input type="time" name="start_${d.id}" value="${startVal}" ${isChecked ? 'required' : ''} style="margin-top:2px;">
                </div>
                <div style="flex:1;">
                    <small>Fim</small>
                    <input type="time" name="end_${d.id}" value="${endVal}" ${isChecked ? 'required' : ''} style="margin-top:2px;">
                </div>
            </div>
        </div>`;
    }).join('');

    return `
        <div class="view-header">
            <h2>Editando Curso: ${course.name}</h2>
            <button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">Cancelar</button>
        </div>
        <div class="card full-width">
             <form id="edit-course-form" onsubmit="window.AppHandlers.handleUpdateCourse(event)">
                <input type="hidden" name="courseId" value="${course.id}">
                <input type="hidden" name="id" value="${course.id}"> <div class="form-group">
                    <label for="courseName">Nome do Curso</label>
                    <input type="text" id="courseName" name="courseName" value="${course.name}" required>
                </div>

                <div class="form-group">
                    <div class="form-group-header">
                        <label for="courseDescription">Descrição</label>
                        ${isAiEnabled ? `<button type="button" class="action-button secondary generate-ai-button" onclick="window.AppHandlers.handleGenerateDescription('edit-course-form')">Reescrever com IA ✨</button>` : ''}
                    </div>
                    <textarea id="courseDescription" name="courseDescription" rows="3" required>${course.description}</textarea>
                </div>

                <div class="form-group">
                    <label for="teacherId">Professor</label>
                    <select id="teacherId" name="teacherId" required>
                        <option value="">Selecione...</option>
                        ${teachers.map(t => `<option value="${t.id}" ${course.teacherId == t.id ? 'selected' : ''}>${t.name}</option>`).join('')}
                    </select>
                </div>

                <div class="form-group">
                     <label for="monthlyFee">Mensalidade (R$)</label>
                     <input type="number" id="monthlyFee" name="monthlyFee" step="0.01" min="0" value="${course.monthlyFee}">
                </div>

                <div class="form-group">
                     <label for="paymentType">Tipo de Pagamento</label>
                     <select id="paymentType" name="paymentType" onchange="this.value === 'parcelado' ? document.getElementById('installments-group').style.display='block' : document.getElementById('installments-group').style.display='none'">
                        <option value="mensal" ${course.paymentType === 'mensal' ? 'selected' : ''}>Mensalidade Recorrente</option>
                        <option value="parcelado" ${course.paymentType === 'parcelado' ? 'selected' : ''}>Parcelado (Curso Fechado)</option>
                        <option value="unico" ${course.paymentType === 'unico' ? 'selected' : ''}>Pagamento Único</option>
                     </select>
                </div>

                <div class="form-group" id="installments-group" style="display: ${course.paymentType === 'parcelado' ? 'block' : 'none'};">
                     <label for="installments">Número de Parcelas</label>
                     <input type="number" id="installments" name="installments" min="1" value="${course.installments || ''}">
                </div>
                
                <div class="form-group">
                    <label for="carga_horaria">Carga Horária (p/ certificado)</label>
                    <input type="text" id="carga_horaria" name="carga_horaria" value="${course.carga_horaria || ''}" placeholder="Ex: 40 horas">
                </div>

                <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
                
                <div class="form-group">
                    <label>Dias e Horários das Aulas</label>
                    <p style="font-size:0.9em; color:#666; margin-bottom:10px;">Ajuste os dias da semana e horários conforme necessário.</p>
                    ${scheduleHtml}
                </div>

                <button type="submit" class="action-button">Salvar Alterações</button>
            </form>
        </div>
    `;
}