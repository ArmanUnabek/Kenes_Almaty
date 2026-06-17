/**
 * Обработка истечения сессии и 401-ответов API.
 */
(function (window) {
  const LOGIN_URL = '/login.html';

  function redirectToLogin() {
    if (!window.location.pathname.endsWith('login.html')) {
      localStorage.removeItem('user');
      window.location.href = LOGIN_URL;
    }
  }

  window.handleSessionExpired = redirectToLogin;

  const originalFetch = window.fetch;
  window.fetch = async function sessionAwareFetch(...args) {
    const response = await originalFetch.apply(this, args);

    if (response.status === 401) {
      const resource = String(args[0] || '');
      const isAuthCheck = resource.includes('auth.php');
      if (!isAuthCheck) {
        redirectToLogin();
      }
    }

    return response;
  };
})(window);
