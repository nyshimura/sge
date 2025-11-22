// src/views/course/attendance.js
import { apiCall } from '../../api.js';
import { appState } from '../../state.js';

export async function renderAttendanceManagementView(courseId) {
    const date = appState.attendanceState.selectedDate || new Date().toISOString().split('T')[0];
    const month = appState.attendanceState.selectedMonth || date.substring(0, 7); // YYYY-MM

    let data;
    try {
        // Busca dados da API
        data = await apiCall('getAttendanceData', { courseId, date, month }, 'GET');
    } catch (e) {
        return `<div class="error-message">Erro ao carregar chamada: ${e.message}</div>`;
    }

    const { course, students, datesWithAttendance } = data;

    // Helper para verificar se o dia tem chamada (para pintar no calendário, se houver)
    const hasAttendance = (d) => datesWithAttendance.includes(d);

    return `
        <div class="view-header">
            <h2>Frequência: ${course.name}</h2>
            <button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar</button>
        </div>

        <div class="card full-width">
            <div class="attendance-controls" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; align-items: center; flex-wrap: wrap;">
                
                <div class="form-group" style="margin-bottom: 0;">
                    <label style="display:block; font-size: 0.8rem;">Mês de Referência</label>
                    <input type="month" value="${month}" onchange="window.AppHandlers.handleAttendanceMonthChange(event)">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label style="display:block; font-size: 0.8rem;">Data da Chamada</label>
                    <input type="date" value="${date}" onchange="window.AppHandlers.handleAttendanceDateChangeForSave(event)">
                </div>

                <div style="margin-left: auto; font-size: 0.9rem; color: var(--text-muted);">
                    Total de Alunos: <strong>${students.length}</strong>
                </div>
            </div>

            <form onsubmit="window.AppHandlers.handleSaveAttendance(event, ${course.id})">
                <div class="table-responsive">
                    <table class="table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background-color: var(--secondary-bg); text-align: left;">
                                <th style="padding: 10px; border-bottom: 2px solid var(--border-color);">Aluno</th>
                                <th style="padding: 10px; border-bottom: 2px solid var(--border-color); width: 100px; text-align: center;">Status</th>
                                <th style="padding: 10px; border-bottom: 2px solid var(--border-color); width: 50px; text-align: center;">Falta?</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${students.length > 0 ? students.map(student => {
                                // O backend retorna 'attendanceStatus' como 'Presente' ou 'Falta' (ou null se não houve chamada)
                                // Se for 'Falta', marcamos o checkbox.
                                const isAbsent = student.attendanceStatus === 'Falta';
                                const statusBadge = student.attendanceStatus 
                                    ? `<span class="status-badge status-${student.attendanceStatus.toLowerCase() === 'falta' ? 'cancelada' : 'aprovada'}">${student.attendanceStatus}</span>` 
                                    : '<span class="text-muted">-</span>';
                                
                                // CORREÇÃO DO NOME: Usa student.name (que vem do backend concatenado) ou student.firstName
                                const studentName = student.name || student.firstName || 'Nome Desconhecido';

                                return `
                                <tr style="border-bottom: 1px solid var(--border-color);">
                                    <td style="padding: 10px;">
                                        <strong>${studentName}</strong>
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        ${statusBadge}
                                    </td>
                                    <td style="padding: 10px; text-align: center;">
                                        <input type="checkbox" name="attendance[]" value="${student.id}" ${isAbsent ? 'checked' : ''} style="width: 20px; height: 20px; cursor: pointer;">
                                    </td>
                                </tr>
                                `;
                            }).join('') : '<tr><td colspan="3" style="padding: 20px; text-align: center;">Nenhum aluno matriculado nesta turma.</td></tr>'}
                        </tbody>
                    </table>
                </div>

                <div style="margin-top: 20px; text-align: right;">
                    <p class="small-text text-muted" style="margin-bottom: 10px;">* Marque a caixa apenas para alunos que <strong>FALTARAM</strong>.</p>
                    <button type="submit" class="action-button primary">Salvar Chamada do dia ${date.split('-').reverse().join('/')}</button>
                </div>
            </form>
        </div>
    `;
}