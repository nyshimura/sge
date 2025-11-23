// src/views/dashboard/student.js
import { appState } from '../../state.js';
import { renderStudentFinancialHistory } from '../financial/history.js';

export function renderStudentView(studentId, data) {
  const student = appState.currentUser;
  if (!student) return '';

  console.log(">>> DEBUG STUDENT VIEW - Dados Recebidos:", data);

  const myEnrollments = data.myEnrollments || data.enrollments || [];
  const myAttendance = data.attendance || [];
  const allCourses = data.courses || [];
  
  // --- LÓGICA DE FILTROS ---

  const activeEnrollments = myEnrollments.filter(
    (e) => e.status === 'Aprovada' || e.status === 'Pendente'
  );

  const idsToHide = activeEnrollments.map(e => String(e.courseId));

  const availableCoursesToDisplay = allCourses.filter(course =>
    course.status === 'Aberto' &&
    !idsToHide.includes(String(course.id))
  );

  const cards = [
    {
      id: 'student-courses',
      html: `
        <div class="card" id="student-courses" draggable="true" ondragstart="window.AppHandlers.handleDragStart(event)" ondragend="window.AppHandlers.handleDragEnd(event)">
            <h3 class="card-title">📚 Meus Cursos e Matrículas</h3>
            <div class="list-wrapper">
                <ul class="list">
                    ${activeEnrollments.length === 0 ? '<li>Nenhuma matrícula ativa encontrada.</li>' : activeEnrollments.map((enrollment) => {
                        const course = allCourses.find((c) => String(c.id) === String(enrollment.courseId));
                        
                        if (!course) return `<li class="list-item error">Curso ID ${enrollment.courseId} não encontrado.</li>`;

                        const teacherName = course.teacherFirstName 
                            ? `${course.teacherFirstName} ${course.teacherLastName || ''}`
                            : 'Professor não definido';

                        // --- CORREÇÃO DE CORES AQUI ---
                        // As classes do CSS são 'status-aprovada' (verde) e 'status-pendente' (amarelo)
                        const badgeClass = enrollment.status === 'Aprovada' ? 'status-aprovada' : 'status-pendente';

                        return `
                            <li class="list-item">
                                <div class="list-item-content">
                                    <span class="list-item-title">${course.name}</span>
                                    <span class="list-item-subtitle">Professor: ${teacherName}</span>
                                </div>
                                <div class="list-item-actions">
                                    <span class="status-badge ${badgeClass}">${enrollment.status}</span>
                                    <button class="action-button secondary" onclick="window.AppHandlers.handleNavigateToCourseDetails(${course.id})">Detalhes</button>
                                </div>
                            </li>
                        `;
                    }).join('')}
                </ul>
            </div>
        </div>
      `
    },
    {
      id: 'student-available-courses',
      html: `
        <div class="card" id="student-available-courses" draggable="true" ondragstart="window.AppHandlers.handleDragStart(event)" ondragend="window.AppHandlers.handleDragEnd(event)">
            <h3 class="card-title">🏫 Cursos Disponíveis para Inscrição</h3>
             <div class="list-wrapper">
                <ul class="list">
                    ${availableCoursesToDisplay.length === 0 ? '<li>🎉 Não há novos cursos disponíveis no momento.</li>' : availableCoursesToDisplay.map((course) => {
                        const teacherName = course.teacherFirstName 
                            ? `${course.teacherFirstName} ${course.teacherLastName || ''}`
                            : 'A definir';

                        const wasCancelled = myEnrollments.some((e) => String(e.courseId) === String(course.id) && e.status === 'Cancelada');
                        
                        let actionButton = '';
                        if (wasCancelled) {
                             actionButton = `<button class="action-button" onclick="window.AppHandlers.handleInitiateEnrollment(${course.id})">Reinscrever-se</button>`;
                        } else {
                             actionButton = `<button class="action-button" onclick="window.AppHandlers.handleInitiateEnrollment(${course.id})">Inscreva-se Agora</button>`;
                        }

                        return `
                            <li class="list-item">
                                <div class="list-item-content">
                                    <span class="list-item-title">${course.name}</span>
                                    <span class="list-item-subtitle">Prof.: ${teacherName} | Vagas: ${course.totalSlots || 'Ilimitadas'}</span>
                                </div>
                                <div class="list-item-actions">
                                    <button class="action-button secondary" onclick="window.AppHandlers.handleNavigateToCourseDetails(${course.id})">Detalhes</button>
                                    ${actionButton}
                                </div>
                            </li>
                        `;
                    }).join('')}
                </ul>
            </div>
        </div>
      `
    },
    {
      id: 'student-attendance',
      html: `
        <div class="card" id="student-attendance" draggable="true" ondragstart="window.AppHandlers.handleDragStart(event)" ondragend="window.AppHandlers.handleDragEnd(event)">
            <h3 class="card-title">📊 Meu Relatório de Frequência</h3>
             ${myAttendance.length === 0 ? '<p style="padding:10px; color:var(--text-muted);">Nenhuma presença registrada ainda.</p>' : `
                <div class="table-wrapper">
                <table class="table">
                    <thead><tr><th>Curso</th><th>Data</th><th>Status</th></tr></thead>
                    <tbody>
                        ${myAttendance.sort((a, b) => b.date.localeCompare(a.date)).map((record) => {
                            const course = allCourses.find((c) => String(c.id) === String(record.courseId));
                            
                            let formattedDate = record.date;
                            try {
                                const dateParts = record.date.split('-');
                                if(dateParts.length === 3) formattedDate = `${dateParts[2]}/${dateParts[1]}/${dateParts[0]}`;
                            } catch (e) {}

                            const statusClass = record.status === 'Presente' ? 'status-aprovada' : 'status-falta';
                            
                            return `
                                <tr>
                                    <td>${course ? course.name : `ID: ${record.courseId}`}</td>
                                    <td>${formattedDate}</td>
                                    <td><span class="status-badge ${statusClass}">${record.status}</span></td>
                                </tr>
                            `
                        }).join('')}
                    </tbody>
                </table>
                </div>
             `}
        </div>
      `
    },
    {
      id: 'student-finance',
      html: `
        <div class="card full-width" id="student-finance" draggable="true" ondragstart="window.AppHandlers.handleDragStart(event)" ondragend="window.AppHandlers.handleDragEnd(event)">
             ${renderStudentFinancialHistory(studentId, data.payments)}
        </div>
      `
    }
  ];

  return `
    <div class="welcome-message">
        <h2>Olá, ${student.firstName}!</h2>
        <p>Bem-vindo ao seu portal do aluno.</p>
    </div>
    <div class="dashboard-grid" ondragover="window.AppHandlers.handleDragOver(event)" ondrop="window.AppHandlers.handleDrop(event)">
        ${cards.map(c => c.html).join('')}
    </div>
  `;
}