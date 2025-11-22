// src/views/course/details.js
import { apiCall } from '../../api.js';
import { appState } from '../../state.js';

export async function renderCourseDetailsView(courseArg) {
    // Busca detalhes completos e FRESCOS do servidor
    const data = await apiCall('getCourseDetails', { id: courseArg.id }, 'GET');
    
    // AQUI ESTAVA O ERRO: Precisamos usar o curso retornado pela API, 
    // pois ele contém os campos 'teacherName' e 'schedule_json' atualizados.
    const fullCourse = data.course; 
    const students = data.students || [];
    
    // Nota: O backend atual não retorna 'admin' separado, mas se retornar no futuro, estaria aqui.
    // Se precisar do nome do admin que encerrou, precisaremos ajustar o backend para fazer join com o closed_by_admin_id.
    
    const allEnrollments = appState.enrollments || [];

    const enrolledCount = students.filter(s => s.status === 'Aprovada').length;
    const vacancies = fullCourse.totalSlots === null ? 'Ilimitadas' : Math.max(0, fullCourse.totalSlots - enrolledCount);
    let paymentInfo = fullCourse.paymentType === 'parcelado' ? `${fullCourse.installments || '?'} parcelas` : 'Recorrente';

    // === LÓGICA DE EXIBIÇÃO DA AGENDA ===
    let scheduleDisplay = '<span class="text-muted">Não definida</span>';
    
    if (fullCourse.schedule_json) {
        try {
            const schedule = JSON.parse(fullCourse.schedule_json);
            if (Array.isArray(schedule) && schedule.length > 0) {
                scheduleDisplay = `<div style="display:flex; flex-direction:column; gap:4px;">
                    ${schedule.map(s => `
                        <div>
                            <strong>${s.day_label}:</strong> ${s.start} às ${s.end}
                        </div>
                    `).join('')}
                </div>`;
            }
        } catch (e) {
            console.error("Erro ao ler agenda JSON", e);
        }
    } else if (fullCourse.dayOfWeek && fullCourse.startTime) {
        scheduleDisplay = `${fullCourse.dayOfWeek}, das ${fullCourse.startTime} às ${fullCourse.endTime}`;
    }

    let auditInfo = '';
    if (fullCourse.status === 'Encerrado' && fullCourse.closed_date) {
        const date = new Date(fullCourse.closed_date).toLocaleString('pt-BR');
        auditInfo = `<div class="audit-info"><strong>Curso Encerrado em:</strong> ${date}</div>`;
    }

    const currentUser = appState.currentUser;
    const canManage = currentUser && (currentUser.role === 'admin' || currentUser.role === 'superadmin');
    const isStudent = currentUser && currentUser.role === 'student';

    let studentEnrollmentInfo = '';
    if (isStudent) {
        const myEnrollmentDetails = students.find(s => s.id === currentUser.id);
        const myFullEnrollment = allEnrollments.find(e => e.studentId === currentUser.id && e.courseId === fullCourse.id);

        if (myEnrollmentDetails) {
            let actionButtons = '';
            if (myEnrollmentDetails.status === 'Aprovada') {
                actionButtons = `
                    <button class="action-button secondary" onclick="window.AppHandlers.handleGenerateContractPdf(${currentUser.id}, ${fullCourse.id})">Ver Contrato</button>
                    ${myFullEnrollment?.termsAcceptedAt ?
                        `<button class="action-button secondary" onclick="window.AppHandlers.handleGenerateImageTermsPdf(${currentUser.id}, ${fullCourse.id})">Ver Termo</button>`
                        : ''
                    }
                    <button class="action-button danger" onclick="window.AppHandlers.handleCancelEnrollment(${currentUser.id}, ${fullCourse.id})">Trancar Matrícula</button>
                `;
            } else if (myEnrollmentDetails.status === 'Cancelada') {
                 actionButtons = `<button class="action-button" onclick="window.AppHandlers.handleInitiateEnrollment(${fullCourse.id})">Reinscrever-se</button>`;
            } else if (myEnrollmentDetails.status === 'Pendente') {
                 actionButtons = `<span>Aguardando Aprovação</span>`;
            }

             studentEnrollmentInfo = `
                <div class="card full-width">
                    <h3 class="card-title">Minha Matrícula neste Curso</h3>
                    <ul class="list">
                        <li class="list-item">
                            <div class="list-item-content">
                                <span class="list-item-title">Status:</span>
                            </div>
                            <div class="list-item-actions">
                                <span class="status-badge status-${myEnrollmentDetails.status.toLowerCase()}">${myEnrollmentDetails.status}</span>
                                ${actionButtons}
                            </div>
                        </li>
                    </ul>
                </div>
            `;
        } else {
             studentEnrollmentInfo = `
                <div class="card full-width">
                    <h3 class="card-title">Minha Matrícula neste Curso</h3>
                    <p>Você não está matriculado neste curso.</p>
                     ${fullCourse.status === 'Aberto' ?
                        `<div class="list-item-actions" style="justify-content: flex-start;">
                             <button class="action-button" onclick="window.AppHandlers.handleInitiateEnrollment(${fullCourse.id})">Inscreva-se Agora</button>
                         </div>`
                         : ''
                     }
                </div>
             `;
        }
    }

    let studentsListHtml = '';
    if (canManage) {
        studentsListHtml = `
            <div class="card full-width">
                <h3 class="card-title">Alunos com Matrícula (${students.length})</h3>
                ${students.length > 0 ? `
                    <ul class="list">
                        ${students.map((student) => {
                            const studentFullEnrollment = allEnrollments.find(e => e.studentId === student.id && e.courseId === fullCourse.id);
                            let actionButtons = '';
                            if (student.status === 'Aprovada') {
                                actionButtons = `
                                    <button class="action-button secondary" onclick="window.AppHandlers.handleGenerateContractPdf(${student.id}, ${fullCourse.id})">Gerar Contrato</button>
                                    ${studentFullEnrollment?.termsAcceptedAt ?
                                        `<button class="action-button secondary" onclick="window.AppHandlers.handleGenerateImageTermsPdf(${student.id}, ${fullCourse.id})">Gerar Termo</button>`
                                        : ''
                                    }
                                    <button class="action-button danger" onclick="window.AppHandlers.handleCancelEnrollment(${student.id}, ${fullCourse.id})">Trancar</button>`;
                            } else if (student.status === 'Cancelada') {
                                actionButtons = `<button class="action-button" onclick="window.AppHandlers.handleReactivateEnrollment(${student.id}, ${fullCourse.id})">Reativar</button>`;
                            }

                            return `
                            <li class="list-item">
                                <div class="list-item-content">
                                    <span class="list-item-title">${student.studentName || student.email}</span>
                                    <span class="list-item-subtitle">${student.email}</span>
                                </div>
                                <div class="list-item-actions">
                                    <span class="status-badge status-${student.status.toLowerCase()}">${student.status}</span>
                                    <button class="action-button secondary" onclick="window.AppHandlers.handleNavigateToProfile(${student.id})">Ver Perfil</button>
                                    ${actionButtons}
                                </div>
                            </li>
                        `}).join('')}
                    </ul>
                ` : '<p>Nenhum aluno com matrícula neste curso ainda.</p>'}
            </div>
        `;
    }

    return `
        <div class="view-header">
            <h2>Detalhes do Curso: ${fullCourse.name}</h2>
            <button class="back-button" onclick="window.AppHandlers.handleNavigateBackToDashboard()">← Voltar</button>
        </div>
        <div class="card full-width">
            <div class="course-details-grid">
                <div><strong>Professor:</strong></div>
                <div>${fullCourse.teacherName || 'Não atribuído'}</div>

                <div><strong>Status:</strong></div>
                <div><span class="status-badge status-${fullCourse.status.toLowerCase()}">${fullCourse.status}</span></div>

                <div><strong>Vagas Ocupadas:</strong></div>
                <div>${enrolledCount} / ${fullCourse.totalSlots === null ? '∞' : fullCourse.totalSlots} (Vagas Restantes: ${vacancies})</div>

                <div><strong>Mensalidade:</strong></div>
                <div>${fullCourse.monthlyFee ? `R$ ${Number(fullCourse.monthlyFee).toFixed(2).replace('.', ',')} (${paymentInfo})` : 'Não definido'}</div>

                <div style="align-self: start; margin-top: 5px;"><strong>Agenda:</strong></div>
                <div>${scheduleDisplay}</div>
            </div>
            ${auditInfo}
            <div class="course-description">
                <strong>Descrição:</strong><br>
                ${fullCourse.description ? fullCourse.description.replace(/\n/g, '<br>') : 'Nenhuma descrição fornecida.'}
            </div>
        </div>
        ${studentEnrollmentInfo}
        ${studentsListHtml}
    `;
}