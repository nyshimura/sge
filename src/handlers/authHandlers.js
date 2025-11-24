// src/handlers/authHandlers.js

import { apiCall } from '../api.js';
import { appState } from '../state.js';
import { render } from '../router.js';

export async function handleLogin(event) {
  event.preventDefault();
  const form = event.target;
  const email = form.elements.namedItem('email').value;
  const password = form.elements.namedItem('password').value;
  const errorElement = document.getElementById('login-error');
  const submitButton = form.querySelector('button[type="submit"]');
  if (errorElement) errorElement.textContent = '';
  if (submitButton) submitButton.disabled = true;
  try {
    const data = await apiCall('login', { email, password });
    if (data && data.user) {
      appState.currentUser = data.user;
      localStorage.setItem('currentUser', JSON.stringify(data.user));
      appState.currentView = 'dashboard';
      if (window.location.hash) {
        history.pushState("", document.title, window.location.pathname + window.location.search);
      }
      render();
    } else {
      throw new Error("Resposta de login inválida do servidor.");
    }
  } catch (error) {
    if (errorElement) errorElement.textContent = error.message || 'Ocorreu um erro desconhecido.';
  } finally {
    if (submitButton) submitButton.disabled = false;
  }
}

export async function handleRegister(event) {
  event.preventDefault();
  const form = event.target;
  const firstName = form.elements.namedItem('name').value;
  const email = form.elements.namedItem('email').value;
  const password = form.elements.namedItem('password').value;
  const confirmPassword = form.elements.namedItem('confirmPassword').value;
  const errorElement = document.getElementById('register-error');
  const submitButton = form.querySelector('button[type="submit"]');
  if (errorElement) errorElement.textContent = '';
  if (password.length < 6) {
    if (errorElement) errorElement.textContent = 'A senha deve ter pelo menos 6 caracteres.';
    return;
  }
  if (password !== confirmPassword) {
    if (errorElement) errorElement.textContent = 'As senhas não coincidem.';
    return;
  }
  if (submitButton) submitButton.disabled = true;
  try {
    await apiCall('register', { firstName, email, password, confirmPassword });
    alert('Cadastro realizado com sucesso! Faça o login.');
    appState.currentView = 'login';
    if (window.location.hash) {
      history.pushState("", document.title, window.location.pathname + window.location.search);
    }
    render();
  } catch(error) {
    if (errorElement) errorElement.textContent = error.message || 'Ocorreu um erro desconhecido.';
  } finally {
    if (submitButton) submitButton.disabled = false;
  }
}

export function handleLogout() {
  localStorage.removeItem('currentUser');
  appState.currentUser = null;
  appState.currentView = 'login';
  Object.assign(appState, {
    users: [], courses: [], enrollments: [], attendance: [], payments: [],
    schoolProfile: appState.schoolProfile,
    systemSettings: null,
    adminView: 'dashboard', viewingCourseId: null, viewingUserId: null,
    userFilters: { name: '', role: 'all', courseId: 'all', enrollmentStatus: 'all' },
    attendanceState: { courseId: null, selectedDate: new Date().toISOString().split('T')[0], students: [], history: {} },
    financialState: { isDashboardVisible: false, isControlPanelVisible: false, isDefaultersReportVisible: false, selectedDate: new Date().toISOString().slice(0, 7), defaultersReportMonth: new Date().toISOString().slice(0, 7), defaultersReportCourseId: 'all', expandedStudentId: null },
    documentTemplatesState: { isVisible: false },
    pixModal: { isOpen: false, paymentIds: [], content: null },
    enrollmentModalState: { isOpen: false, data: null, isReenrollment: false }
  });
  if (window.location.hash) {
    history.pushState("", document.title, window.location.pathname + window.location.search);
  }
  render();
}

export async function handleForgotPasswordRequest(event) {
  event.preventDefault();
  const form = event.target;
  const email = form.elements.namedItem('email').value;
  const submitButton = form.querySelector('button[type="submit"]');
  const errorElement = document.getElementById('forgot-password-error');
  if (errorElement) errorElement.textContent = '';
  if (submitButton) {
    submitButton.disabled = true;
    submitButton.textContent = 'Enviando...';
  }
  try {
    const result = await apiCall('requestPasswordReset', { email });
    alert(result.message || 'Solicitação processada. Verifique seu e-mail.');
    form.reset();
  } catch (error) {
    if (errorElement) errorElement.textContent = error.message || 'Ocorreu um erro ao processar a solicitação.';
    console.error("Erro na solicitação de reset:", error);
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = 'Enviar Link de Redefinição';
    }
  }
}

export async function handleResetPassword(event) {
  event.preventDefault();
  const form = event.target;
  const token = form.elements.namedItem('token').value;
  const newPassword = form.elements.namedItem('newPassword').value;
  const confirmPassword = form.elements.namedItem('confirmPassword').value;
  const submitButton = form.querySelector('button[type="submit"]');
  const errorElement = document.getElementById('reset-password-error');
  if (errorElement) errorElement.textContent = '';
  if (newPassword !== confirmPassword) {
    if (errorElement) errorElement.textContent = 'As senhas não coincidem.';
    return;
  }
  if (newPassword.length < 6) {
    if (errorElement) errorElement.textContent = 'A nova senha deve ter pelo menos 6 caracteres.';
    return;
  }
  if (submitButton) {
    submitButton.disabled = true;
    submitButton.textContent = 'Redefinindo...';
  }
  try {
    const result = await apiCall('resetPassword', { token, newPassword, confirmPassword });
    alert(result.message || 'Senha redefinida com sucesso!');
    window.AppHandlers.navigateTo('login');
  } catch (error) {
    if (errorElement) errorElement.textContent = error.message || 'Ocorreu um erro ao redefinir a senha.';
    console.error("Erro ao redefinir senha:", error);
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = 'Redefinir Senha';
    }
  }
}

// --- Handler para Alteração de Senha (Logado) ---
export async function handleChangePassword(event) {
    event.preventDefault(); // ESSENCIAL: Evita recarregar a página
    const form = event.target;
    const submitButton = form.querySelector('button[type="submit"]');
    const errorElement = document.getElementById('change-password-error');
    
    if (errorElement) errorElement.textContent = '';
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Salvando...';
    }

    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    // Validação de confirmação
    if (data.newPassword !== data.confirmPassword) {
        if (errorElement) errorElement.textContent = 'As novas senhas não coincidem.';
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Alterar Senha';
        }
        return;
    }

    try {
        const response = await apiCall('changePassword', data);
        alert(response.message || 'Senha alterada com sucesso!');
        form.reset();
    } catch (error) {
        console.error(error);
        if (errorElement) errorElement.textContent = error.message || 'Erro ao alterar senha.';
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = 'Alterar Senha';
        }
    }
}

// --- REGISTRO GLOBAL ---
window.AppHandlers = window.AppHandlers || {};
window.AppHandlers.handleLogin = handleLogin;
window.AppHandlers.handleRegister = handleRegister;
window.AppHandlers.handleLogout = handleLogout;
window.AppHandlers.handleForgotPasswordRequest = handleForgotPasswordRequest;
window.AppHandlers.handleResetPassword = handleResetPassword;
window.AppHandlers.handleChangePassword = handleChangePassword;