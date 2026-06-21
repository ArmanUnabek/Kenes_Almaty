// CSRF Token Handler
let csrfToken = null;

async function getCsrfToken() {
    if (csrfToken) {
        return csrfToken;
    }

    try {
        const response = await fetch('/api/auth.php?action=csrf');
        const data = await response.json();
        if (data.csrf_token) {
            csrfToken = data.csrf_token;
            return csrfToken;
        }
    } catch (error) {
        console.error('Failed to get CSRF token:', error);
    }
    return null;
}

// Перехватчик для всех fetch запросов
const originalFetch = window.fetch;
window.fetch = async function(...args) {
    const [resource, config] = args;
    const method = (config?.method || 'GET').toUpperCase();

    // Добавить CSRF токен для POST, PUT, DELETE запросов
    if (['POST', 'PUT', 'DELETE'].includes(method)) {
        const token = await getCsrfToken();
        if (token) {
            if (!config) args[1] = {};
            if (!args[1].headers) args[1].headers = {};
            args[1].headers['X-CSRF-Token'] = token;
        }
    }

    return originalFetch.apply(this, args);
};

// Reset cached token (call on logout or session change)
window.resetCsrfToken = function () { csrfToken = null; };

// Получить токен при загрузке страницы
document.addEventListener('DOMContentLoaded', () => {
    getCsrfToken();
});
