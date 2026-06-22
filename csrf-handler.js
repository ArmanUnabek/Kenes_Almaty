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

// Intercept all fetch requests to inject CSRF token and capture rotated token
const originalFetch = window.fetch;
window.fetch = async function(...args) {
    const [resource, config] = args;
    const method = (config?.method || 'GET').toUpperCase();

    // Add CSRF token for state-changing requests
    if (['POST', 'PUT', 'DELETE'].includes(method)) {
        const token = await getCsrfToken();
        if (token) {
            if (!config) args[1] = {};
            if (!args[1].headers) args[1].headers = {};
            args[1].headers['X-CSRF-Token'] = token;
        }
    }

    const response = await originalFetch.apply(this, args);

    // If server rotated the CSRF token, update our cache immediately
    const newToken = response.headers.get('X-New-CSRF-Token');
    if (newToken) {
        csrfToken = newToken;
    }

    return response;
};

// Fetch initial CSRF token on page load
document.addEventListener('DOMContentLoaded', () => {
    getCsrfToken();
});
