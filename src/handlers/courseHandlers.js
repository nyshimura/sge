// src/handlers/courseHandlers.js
import { apiCall } from '../api.js';
import { appState } from '../state.js';
import { render } from '../router.js';

export async function handleCreateCourse(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    const courseData = Object.fromEntries(formData.entries());

    // === NOVA LÓGICA DE AGENDAMENTO ===
    const schedule = [];
    const dayMap = {
        'seg': 'Segunda-feira', 'ter': 'Terça-feira', 'qua': 'Quarta-feira',
        'qui': 'Quinta-feira', 'sex': 'Sexta-feira', 'sab': 'Sábado', 'dom': 'Domingo'
    };

    // Seleciona todos os dias marcados na interface
    const checkedDays = form.querySelectorAll('.day-checkbox:checked');

    checkedDays.forEach(checkbox => {
        const dayId = checkbox.value; // ex: 'seg'
        const start = formData.get(`start_${dayId}`);
        const end = formData.get(`end_${dayId}`);

        if (start && end) {
            schedule.push({
                day_id: dayId,
                day_label: dayMap[dayId],
                start: start,
                end: end
            });
        }
    });

    if (schedule.length === 0) {
        alert("Por favor, selecione pelo menos um dia de aula e preencha os horários.");
        return;
    }

    // Adiciona o JSON completo ao envio
    courseData.schedule_json = JSON.stringify(schedule);

    // --- COMPATIBILIDADE (FALLBACK) ---
    // Preenche as colunas antigas com o primeiro horário definido para não quebrar o resto do sistema
    courseData.dayOfWeek = schedule[0].day_label;
    courseData.startTime = schedule[0].start;
    courseData.endTime = schedule[0].end;
    // ----------------------------------

    // Normaliza valores vazios
    courseData.totalSlots = courseData.totalSlots || null;
    courseData.installments = courseData.installments || null;
    courseData.carga_horaria = courseData.carga_horaria || null;

    const submitButton = form.querySelector('button[type="submit"]');
    if(submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = "Criando...";
    }

    try {
        await apiCall('createCourse', { courseData });
        alert('Curso criado com sucesso!');
        window.AppHandlers.handleNavigateBackToDashboard();
    } catch(e) {
        console.error(e);
        alert(e.message || 'Erro ao criar curso.');
        if(submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = "Criar Curso";
        }
    }
}

export async function handleUpdateCourse(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    const courseData = Object.fromEntries(formData.entries());

    // === NOVA LÓGICA DE AGENDAMENTO (IGUAL AO CREATE) ===
    const schedule = [];
    const dayMap = {
        'seg': 'Segunda-feira', 'ter': 'Terça-feira', 'qua': 'Quarta-feira',
        'qui': 'Quinta-feira', 'sex': 'Sexta-feira', 'sab': 'Sábado', 'dom': 'Domingo'
    };

    // Seleciona checkboxes marcados
    const checkedDays = form.querySelectorAll('.day-checkbox:checked');

    checkedDays.forEach(checkbox => {
        const dayId = checkbox.value;
        const start = formData.get(`start_${dayId}`);
        const end = formData.get(`end_${dayId}`);

        if (start && end) {
            schedule.push({
                day_id: dayId,
                day_label: dayMap[dayId],
                start: start,
                end: end
            });
        }
    });

    if (schedule.length === 0) {
        alert("Por favor, selecione pelo menos um dia de aula.");
        return;
    }

    // Adiciona o JSON ao payload
    courseData.schedule_json = JSON.stringify(schedule);

    // --- COMPATIBILIDADE COM LEGADO ---
    courseData.dayOfWeek = schedule[0].day_label;
    courseData.startTime = schedule[0].start;
    courseData.endTime = schedule[0].end;
    // ---------------------------------

    courseData.totalSlots = courseData.totalSlots || null;
    courseData.installments = courseData.installments || null;
    courseData.carga_horaria = courseData.carga_horaria || null;

    const submitButton = form.querySelector('button[type="submit"]');
    if(submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = "Salvando...";
    }

    try {
        await apiCall('updateCourse', { courseData });
        alert('Curso atualizado com sucesso!');
        window.AppHandlers.handleNavigateBackToDashboard();
    } catch(e) {
        console.error(e);
        alert(e.message || 'Erro ao atualizar curso.');
        if(submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = "Salvar Alterações";
        }
    }
}

export async function handleEndCourse(id) {
    if(!confirm("Tem certeza que deseja finalizar este curso?")) return;
    try {
        await apiCall('endCourse', { id });
        alert('Curso finalizado.');
        render();
    } catch(e) {
        alert(e.message);
    }
}

export async function handleReopenCourse(id) {
    if(!confirm("Deseja reabrir este curso?")) return;
    try {
        await apiCall('reopenCourse', { id });
        alert('Curso reaberto.');
        render();
    } catch(e) {
        alert(e.message);
    }
}

export async function handleSaveAttendance(event, courseId) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    
    // Pega a data selecionada no estado (ou hoje se não houver)
    const date = appState.attendanceState.selectedDate || new Date().toISOString().split('T')[0];
    
    // Checkboxes marcados representam faltas
    const absentStudentIds = formData.getAll('attendance[]').map(id => parseInt(id));

    try {
        const response = await apiCall('saveAttendance', {
            courseId: courseId,
            date: date,
            absentStudentIds: absentStudentIds
        });
        alert('Frequência salva com sucesso!');
    } catch (error) {
        console.error(error);
        alert('Erro ao salvar frequência: ' + error.message);
    }
}

export function handleAttendanceMonthChange(event) {
    const newMonth = event.target.value; // Formato YYYY-MM
    if (newMonth) {
        appState.attendanceState.selectedMonth = newMonth;
        const firstDayOfMonth = newMonth + '-01';
        if (!isNaN(new Date(firstDayOfMonth).getTime())) {
             const today = new Date().toISOString().split('T')[0];
             appState.attendanceState.selectedDate = firstDayOfMonth > today ? today : firstDayOfMonth;
        }
        render(); 
    }
}

export function handleAttendanceDateChangeForSave(event) {
    appState.attendanceState.selectedDate = event.target.value;
    render(); 
}

export const courseHandlers = {
    handleCreateCourse,
    handleUpdateCourse,
    handleEndCourse,
    handleReopenCourse,
    handleSaveAttendance,
    handleAttendanceMonthChange,
    handleAttendanceDateChangeForSave
};