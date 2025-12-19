// src/handlers/enrollmentHandlers.js
import { apiCall } from '../api.js';
import { appState } from '../state.js';
import { render } from '../router.js';

export async function handleInitiateEnrollment(courseId, isReenrollment = false) {
    if (!appState.currentUser || appState.currentUser.role !== 'student') return;

    // Fecha modais anteriores (usando handlers globais)
    window.AppHandlers.handleClosePixModal();
    window.AppHandlers.handleCloseEnrollmentModal();

    try {
        const data = await apiCall('getEnrollmentDocuments', { studentId: appState.currentUser.id, courseId }, 'GET');
        data.courseId = courseId;
        data.isReenrollment = isReenrollment;
        appState.enrollmentModalState.isOpen = true;
        appState.enrollmentModalState.data = data;
        appState.enrollmentModalState.isReenrollment = isReenrollment;
        render();
    } catch (e) {
        console.error("Erro ao iniciar matrícula:", e);
        alert(e.message || 'Erro ao carregar dados da matrícula.');
    }
}

export async function handleSubmitEnrollment(event) {
    event.preventDefault();
    const form = event.target;
    const submitButton = form.querySelector('#submit-enrollment-btn');
    const formData = new FormData(form);
    const enrollmentData = {};
    formData.forEach((value, key) => { enrollmentData[key] = value.trim(); });
    enrollmentData.acceptContract = formData.has('acceptContract');
    enrollmentData.acceptImageTerms = formData.has('acceptImageTerms');
    const studentId = parseInt(enrollmentData.studentId, 10);
    const courseId = parseInt(enrollmentData.courseId, 10);
    const isReenrollment = enrollmentData.isReenrollment === 'true';
    delete enrollmentData.studentId; delete enrollmentData.courseId; delete enrollmentData.isReenrollment;

    // Validações
    if (!enrollmentData.aluno_rg || !enrollmentData.aluno_cpf) return alert("Preencha RG e CPF.");
    const isMinor = appState.enrollmentModalState.data?.isMinor;
    if (isMinor && (!enrollmentData.guardianName || !enrollmentData.guardianRG || !enrollmentData.guardianCPF || !enrollmentData.guardianEmail || !enrollmentData.guardianPhone)) return alert("Preencha todos os dados do responsável.");
    if (!enrollmentData.acceptContract) return alert("Aceite o Contrato.");

    if (submitButton) { submitButton.disabled = true; submitButton.textContent = 'Enviando...'; }
    const apiAction = isReenrollment ? 'submitReenrollment' : 'submitEnrollment';

    try {
        const result = await apiCall(apiAction, { studentId, courseId, enrollmentData });
        alert(result.message || 'Operação realizada!');

        // Atualiza estado local
        const enrollmentIndex = appState.enrollments.findIndex(e => e.studentId === studentId && e.courseId === courseId);
        const nowISO = new Date().toISOString();
        if (apiAction === 'submitEnrollment') {
            if (enrollmentIndex > -1) {
                if (appState.enrollments[enrollmentIndex].status === 'Cancelada') {
                    appState.enrollments[enrollmentIndex].status = 'Pendente';
                    appState.enrollments[enrollmentIndex].contractAcceptedAt = nowISO;
                    appState.enrollments[enrollmentIndex].termsAcceptedAt = enrollmentData.acceptImageTerms ? nowISO : null;
                }
            } else {
                appState.enrollments.push({ studentId, courseId, status: 'Pendente', contractAcceptedAt: nowISO, termsAcceptedAt: enrollmentData.acceptImageTerms ? nowISO : null, scholarshipPercentage: 0, customMonthlyFee: null });
            }
        } else if (apiAction === 'submitReenrollment') {
            if (enrollmentIndex > -1) {
                appState.enrollments[enrollmentIndex].contractAcceptedAt = nowISO;
                appState.enrollments[enrollmentIndex].termsAcceptedAt = enrollmentData.acceptImageTerms ? nowISO : null;
            }
            const paymentData = await apiCall('getStudentPayments', { studentId }, 'GET');
            appState.payments = [...appState.payments.filter(p => p.studentId !== studentId), ...(paymentData.payments || [])];
        }

        // Atualiza dados do usuário logado
        if(appState.currentUser && appState.currentUser.id === studentId) {
             Object.assign(appState.currentUser, {
                rg: enrollmentData.aluno_rg,
                cpf: enrollmentData.aluno_cpf,
                guardianName: isMinor ? enrollmentData.guardianName : appState.currentUser.guardianName,
                guardianRG: isMinor ? enrollmentData.guardianRG : appState.currentUser.guardianRG,
                guardianCPF: isMinor ? enrollmentData.guardianCPF : appState.currentUser.guardianCPF,
                guardianEmail: isMinor ? enrollmentData.guardianEmail : appState.currentUser.guardianEmail,
                guardianPhone: isMinor ? enrollmentData.guardianPhone : appState.currentUser.guardianPhone,
            });
            localStorage.setItem('currentUser', JSON.stringify(appState.currentUser));
        }

        window.AppHandlers.handleCloseEnrollmentModal();
        setTimeout(render, 50);

    } catch (e) {
        console.error(`Falha ao submeter matrícula (${apiAction}):`, e);
        const currentSubmitButton = document.getElementById('submit-enrollment-btn');
        if (currentSubmitButton) {
            currentSubmitButton.disabled = !currentSubmitButton.form?.checkValidity() || !document.getElementById('acceptContract')?.checked;
            currentSubmitButton.textContent = isReenrollment ? 'Confirmar Rematrícula' : 'Confirmar Matrícula';
        }
        const modalErrorElement = document.getElementById('enrollment-error'); // Adicione este <p> ao modal
        if (modalErrorElement) modalErrorElement.textContent = e.message || 'Erro ao submeter.';
        else alert(e.message || 'Erro ao submeter.');
    }
}

export async function handleApprove(event) {
    event.preventDefault();
    const form = event.target;
    const studentId = parseInt(form.dataset.studentId, 10);
    const courseId = parseInt(form.dataset.courseId, 10);
    const billingStartChoice = form.elements.namedItem('billingStart').value;
    const overrideFeeInput = form.elements.namedItem('overrideFee');
    const overrideFee = (overrideFeeInput && overrideFeeInput.value !== '' && !isNaN(parseFloat(overrideFeeInput.value)) && parseFloat(overrideFeeInput.value) >= 0) ? parseFloat(overrideFeeInput.value) : null;
    const approveButton = form.querySelector('button[type="submit"]');

    if(approveButton) approveButton.disabled = true;

    try {
        const result = await apiCall('approveEnrollment', { studentId, courseId, billingStartChoice, overrideFee });
        const enrollmentIndex = appState.enrollments.findIndex(e => e.studentId === studentId && e.courseId === courseId);
         if (enrollmentIndex > -1) { appState.enrollments[enrollmentIndex].status = 'Aprovada'; appState.enrollments[enrollmentIndex].customMonthlyFee = overrideFee; }
         try { const paymentData = await apiCall('getStudentPayments', { studentId }, 'GET'); appState.payments = [...appState.payments.filter(p => p.studentId !== studentId), ...(paymentData.payments || [])]; } catch (paymentError) { console.error("Erro recarregar pagamentos:", paymentError); }
        alert(result.message || 'Matrícula aprovada!');
        render();
    } catch (e) { console.error("Erro aprovar:", e); if(approveButton) approveButton.disabled = false; alert(e.message || 'Erro ao aprovar matrícula.'); }
}

export async function handleCancelEnrollment(studentId, courseId) {
    if(confirm("Trancar matrícula?")) {
        try { const res=await apiCall('cancelEnrollment',{studentId,courseId}); alert(res.message||'Trancada.'); const idx=appState.enrollments.findIndex(e=>e.studentId===studentId&&e.courseId===courseId); if(idx>-1)appState.enrollments[idx].status='Cancelada'; render(); }
        catch(e){ alert(e.message || 'Erro ao trancar matrícula.'); }
    }
}

export async function handleReactivateEnrollment(studentId, courseId) {
    if(confirm("Reativar matrícula?")) {
        try { const res=await apiCall('reactivateEnrollment',{studentId,courseId}); alert(res.message||'Reativada.'); const idx=appState.enrollments.findIndex(e=>e.studentId===studentId&&e.courseId===courseId); if(idx>-1)appState.enrollments[idx].status='Aprovada'; render(); }
        catch(e){ alert(e.message || 'Erro ao reativar matrícula.'); }
    }
}

export async function handleUpdateEnrollmentDetails(event, studentId, courseId) {
    const btn = event.target; 
    const li = btn.closest('li.list-item'); 
    if(!li) return; 

    const schI = li.querySelector(`#scholarship-${courseId}`); 
    const feeI = li.querySelector(`#customFee-${courseId}`); 
    const dayI = li.querySelector(`#customDueDay-${courseId}`); // Captura o input do dia

    if (!schI || !feeI) { console.error("Inputs não encontrados"); return; }

    const data = {
        studentId,
        courseId,
        scholarshipPercentage: Math.max(0, Math.min(100, parseFloat(schI.value) || 0)),
        customMonthlyFee: (feeI.value === '' || isNaN(parseFloat(feeI.value)) || parseFloat(feeI.value) < 0) ? null : parseFloat(feeI.value),
        customDueDay: (dayI && dayI.value !== '' && !isNaN(parseInt(dayI.value))) ? parseInt(dayI.value) : null // Envia o novo campo
    };

    btn.disabled = true; 
    btn.textContent = 'Salvando...';

    try { 
        const res = await apiCall('updateEnrollmentDetails', data); 
        alert(res.message || 'Atualizado.'); 
        
        const idx = appState.enrollments.findIndex(e => e.studentId === studentId && e.courseId === courseId); 
        if (idx > -1) { 
            appState.enrollments[idx].scholarshipPercentage = data.scholarshipPercentage; 
            appState.enrollments[idx].customMonthlyFee = data.customMonthlyFee;
            appState.enrollments[idx].customDueDay = data.customDueDay; // Atualiza estado local
        }

        if (appState.viewingUserId === studentId || appState.financialState.expandedStudentId === studentId) { 
            const pData = await apiCall('getStudentPayments', { studentId }, 'GET'); 
            appState.payments = [...appState.payments.filter(p => p.studentId !== studentId), ...(pData.payments || [])]; 
        } 
        render();

    } catch (e) { 
        alert(e.message || 'Erro ao atualizar detalhes.');
    } finally { 
        btn.disabled = false; 
        btn.textContent = 'Salvar Alterações da Matrícula'; 
    }
}