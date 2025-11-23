// index.js

/**
* Ponto de Entrada Principal (Frontend)
*/
import { render } from './src/router.js';
import { appState } from './src/state.js';
import { apiCall } from './src/api.js'; // Importa a API para disponibilizar globalmente

// Importa todos os handlers dos módulos
import * as authHandlers from './src/handlers/authHandlers.js';
import { navigationHandlers } from './src/handlers/navigationHandlers.js';
import * as enrollmentHandlers from './src/handlers/enrollmentHandlers.js';
import { courseHandlers } from './src/handlers/courseHandlers.js';
import * as financialHandlers from './src/handlers/financialHandlers.js';
import * as profileHandlers from './src/handlers/profileHandlers.js';
import * as modalHandlers from './src/handlers/modalHandlers.js';
import * as systemHandlers from './src/handlers/systemHandlers.js';
import * as uiHandlers from './src/handlers/uiHandlers.js';
import * as aiHandlers from './src/handlers/aiHandlers.js';
import { certificateHandlers } from './src/handlers/certificateHandlers.js';
import { handleDragStart, handleDragEnd, handleDragOver, handleDrop } from './src/utils/helpers.js';

// 1. Garante que o objeto global exista (caso views tenham carregado antes)
window.AppHandlers = window.AppHandlers || {};

// 2. Define os handlers deste arquivo principal
const mainHandlers = {
  ...authHandlers,
  ...navigationHandlers,
  ...enrollmentHandlers,
  ...courseHandlers,
  ...financialHandlers,
  ...profileHandlers,
  ...modalHandlers,
  ...systemHandlers,
  ...uiHandlers,
  ...aiHandlers,
  ...certificateHandlers,
  handleDragStart,
  handleDragEnd,
  handleDragOver,
  handleDrop,
  
  // --- CORREÇÃO CRÍTICA: Disponibiliza apiCall globalmente ---
  apiCall, 

  // Navegação
  navigateTo: function(view) {
    appState.currentView = view;
    if (window.location.hash) {
      history.pushState("", document.title, window.location.pathname + window.location.search);
    }
    render();
  }
};

// 3. --- CORREÇÃO CRÍTICA: MESCLA EM VEZ DE SUBSTITUIR ---
// O original usava "window.AppHandlers = { ... }", o que apagava
// as funções do management.js que carregaram antes.
Object.assign(window.AppHandlers, mainHandlers);

// Inicialização
document.addEventListener('DOMContentLoaded', () => {
  initializeApp();
});

window.addEventListener('hashchange', render);

async function initializeApp() {
  const savedUser = localStorage.getItem('currentUser');
  if (savedUser) {
    try {
      appState.currentUser = JSON.parse(savedUser);
      if (window.location.hash.startsWith('#resetPassword') || window.location.hash === '#forgotPasswordRequest') {
        appState.currentView = 'dashboard';
        history.pushState("", document.title, window.location.pathname + window.location.search);
      } else {
        const currentHash = window.location.hash.substring(1);
        appState.currentView = currentHash || 'dashboard';
      }
    } catch (e) {
      console.error("Falha ao analisar usuário salvo:", e);
      localStorage.removeItem('currentUser');
      appState.currentView = 'login';
    }
  } else {
    if (window.location.hash.startsWith('#resetPassword')) {
      appState.currentView = 'resetPassword';
    } else if (window.location.hash === '#forgotPasswordRequest') {
      appState.currentView = 'forgotPasswordRequest';
    } else {
      appState.currentView = 'login';
      if (window.location.hash) { history.pushState("", document.title, window.location.pathname + window.location.search); }
    }
  }
  render();
}