// script.js - Versión corregida

// Global State
let currentUser = null;
let currentView = 'main';
let demonList = [];
let userStats = {};
let globalStats = {};

// API Base URL
const API_BASE = '/api';

// Theme Management
function initTheme() {
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeIcon(savedTheme);
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-theme');
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
    updateThemeIcon(newTheme);
}

function updateThemeIcon(theme) {
    const themeIcon = document.querySelector('.theme-toggle i');
    if (themeIcon) {
        themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
}

// ================== Navegación ==================
function goToMain() { showMainList(); }

function showMainList() {
    currentView = 'main';
    showView('list-view');
    updateListContent('main', 'Main List', 'The top 75 hardest demons');
    updateActiveNav('main');
    loadDemonList(1, 75);
}

function showExtendedList() {
    currentView = 'extended';
    showView('list-view');
    updateListContent('extended', 'Extended List', 'Demons ranked 76-150');
    updateActiveNav('extended');
    loadDemonList(76, 150);
}

function showLegacyList() {
    currentView = 'legacy';
    showView('list-view');
    updateListContent('legacy', 'Legacy List', 'Former list demons');
    updateActiveNav('legacy');
    loadDemonList(151, null);
}

function showStatsViewer() {
    currentView = 'stats';
    showView('stats-view');
    updateActiveNav('stats');
    showGlobalStats();
}

function showProfile(userId) {
    currentView = 'profile';
    showView('profile-view');
    loadUserProfile(userId);
}

function showAdmin() {
    if (!currentUser || !currentUser.isAdmin) {
        alert('Access denied. Admin privileges required.');
        return;
    }
    currentView = 'admin';
    showView('admin-view');
    showLevelManagement();
}

function showView(viewId) {
    const views = ['list-view', 'stats-view', 'profile-view', 'admin-view'];
    views.forEach(view => {
        const el = document.getElementById(view);
        if (el) el.classList.add('hidden');
    });
    document.getElementById(viewId).classList.remove('hidden');
}

function updateListContent(type, title, description) {
    document.getElementById('list-title').textContent = title;
    document.getElementById('list-description').textContent = description;
}

function updateActiveNav(active) {
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => link.classList.remove('active'));

    const activeMap = { 'main': 0, 'extended': 1, 'legacy': 2, 'stats': 3 };
    if (activeMap[active] !== undefined) {
        navLinks[activeMap[active]].classList.add('active');
    }
}

// ================== Carga de datos ==================
async function loadDemonList(start, end) {
    try {
        let url = `${API_BASE}/demons?start=${start}`;
        if (end) url += `&end=${end}`;
        const response = await fetch(url);
        const demons = await response.json();
        renderDemonList(demons);
    } catch (error) {
        console.error('Error loading demon list:', error);
        renderDemonListFallback(start, end);
    }
}

function renderDemonListFallback(start, end) {
    const demoDemons = [
        { id: 1, name: 'Thinking Space II', creator: 'CairoX', verifier: 'CairoX', position: 1, points: 1000 },
        { id: 2, name: 'Amethyst', creator: 'Blat', verifier: 'Blat', position: 2, points: 975 },
        { id: 3, name: 'Tidal Wave', creator: 'OmLuix', verifier: 'OmLuix', position: 3, points: 950 }
    ];
    const filtered = demoDemons.filter(demon => end ? demon.position >= start && demon.position <= end : demon.position >= start);
    renderDemonList(filtered);
}

function renderDemonList(demons) {
    const container = document.getElementById('demon-list');
    container.innerHTML = '';
    demons.forEach(demon => container.appendChild(createDemonCard(demon)));
}

function createDemonCard(demon) {
    const card = document.createElement('div');
    card.className = 'demon-card';
    card.onclick = () => showDemonDetails(demon.id);
    card.innerHTML = `
        <div class="demon-header">
            <div class="demon-rank">#${demon.position}</div>
            <div class="demon-name">${demon.name}</div>
            <div class="demon-points">${demon.points} pts</div>
        </div>
        <div class="demon-info">
            <div class="demon-creator"><i class="fas fa-hammer"></i> by ${demon.creator}</div>
            <div class="demon-verifier"><i class="fas fa-check-circle"></i> verified by ${demon.verifier}</div>
        </div>`;
    return card;
}

// ================== Inicialización ==================
function init() {
    initTheme();
    showMainList();

    // Recuperar sesión previa
    const savedUser = localStorage.getItem('currentUser');
    if (savedUser) {
        try {
            currentUser = JSON.parse(savedUser);
            updateLoginButton();
        } catch {
            localStorage.removeItem('currentUser');
        }
    }
}

document.addEventListener('DOMContentLoaded', init);
