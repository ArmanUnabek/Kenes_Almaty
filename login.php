<?php
$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Вход — Журнал ОС</title>
    <link href="/assets/vendor/bootstrap.min.css?v=1" rel="stylesheet" />
    <link href="/styles.css?v=34" rel="stylesheet" />
    <link href="/assets/vendor/inter.css?v=1" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons.css?v=1">
    <style>
        body.login-page {
            background: var(--brand-navy);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
            overflow-y: auto;
        }
        @media (max-width: 400px) {
            .login-lang {
                position: static;
                margin-bottom: 12px;
                align-self: flex-end;
            }
        }
        body.login-page::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse at 20% 10%, rgba(217, 165, 33, 0.14) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 90%, rgba(29, 78, 216, 0.16) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 0%, rgba(217, 165, 33, 0.10) 0%, transparent 40%);
            pointer-events: none;
        }
        /* subtle grid pattern overlay */
        body.login-page::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image: linear-gradient(rgba(255,255,255,.02) 1px, transparent 1px),
                              linear-gradient(90deg, rgba(255,255,255,.02) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }
        .login-brand { text-align: center; margin-bottom: 32px; position: relative; z-index: 1; }
        .login-mark {
            width: 64px; height: 64px; border-radius: 16px; background: var(--brand-gold);
            color: var(--brand-navy); display: inline-flex; align-items: center; justify-content: center;
            font-size: 2rem; margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(217, 165, 33, 0.40), 0 0 0 1px rgba(217, 165, 33, 0.2);
        }
        .login-brand h1 {
            font-size: 1.5rem; font-weight: 700; color: #fff; margin: 0;
            letter-spacing: -0.01em;
        }
        .login-brand p { font-size: 0.875rem; color: rgba(199, 210, 230, 0.75); margin: 8px 0 0; }
        .login-container { width: 100%; max-width: 420px; position: relative; z-index: 1; }
        .login-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 24px 64px rgba(0,0,0,.40), 0 0 0 1px rgba(255,255,255,.06);
            overflow: hidden;
            border-top: 3px solid var(--brand-gold);
        }
        .login-body { padding: 36px; }
        .login-body h2 {
            font-size: 1.125rem; font-weight: 700; margin-bottom: 24px;
            color: var(--text-primary); letter-spacing: -0.01em;
        }
        .form-group { margin-bottom: 16px; }
        .form-label { font-weight: 600; font-size: 0.8125rem; color: var(--text-primary); margin-bottom: 6px; }
        .login-body .form-control {
            padding: 11px 14px; font-size: 0.9375rem;
            border-radius: 10px; border-color: #e5e7eb;
            transition: border-color .15s, box-shadow .15s;
        }
        .login-body .form-control:focus {
            border-color: var(--brand-primary);
            box-shadow: 0 0 0 3px rgba(29,78,216,.12);
        }
        .login-body .form-check-label { font-size: 0.8125rem; color: var(--text-secondary); }
        .btn-login {
            width: 100%; padding: 13px; font-weight: 700; border-radius: 10px;
            border: none;
            background: linear-gradient(180deg, #2a5cea 0%, var(--brand-primary) 100%);
            color: white; margin-top: 8px;
            font-size: 0.9375rem;
            box-shadow: 0 4px 12px rgba(29,78,216,.30);
            transition: transform .15s, box-shadow .15s, background-image .15s;
        }
        .btn-login:hover:not(:disabled) {
            background: linear-gradient(180deg, var(--brand-primary) 0%, var(--brand-primary-hover) 100%);
            box-shadow: 0 6px 20px rgba(29,78,216,.38);
            transform: translateY(-1px);
        }
        .btn-login:active:not(:disabled) { transform: translateY(0); box-shadow: 0 2px 8px rgba(29,78,216,.25); }
        .btn-login:disabled { opacity: 0.65; }
        .loading-spinner { display: none; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.3); border-top-color: white; border-radius: 50%; animation: spin .8s linear infinite; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-login.loading .loading-spinner { display: inline-block; }
        .login-footer {
            text-align: center; margin-top: 24px; font-size: 0.75rem;
            color: rgba(199, 210, 230, 0.6); position: relative; z-index: 1;
        }
        .login-lang { position: absolute; top: 16px; right: 16px; z-index: 2; }
    </style>
</head>
<body class="login-page">
    <a href="#loginSection" class="skip-link">Перейти к форме входа</a>
    <noscript>
        <div style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;color:#111;padding:32px;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.2);text-align:center;z-index:9999;max-width:400px;">
            <h2 style="margin:0 0 12px;font-size:1.25rem;">JavaScript отключён</h2>
            <p style="margin:0;color:#6B7280;">Для работы приложения необходимо включить JavaScript в настройках браузера.</p>
        </div>
    </noscript>
    <button type="button" class="btn btn-sm btn-outline-light login-lang" id="loginLangBtn">Қазақша</button>

    <div class="login-brand">
        <div class="login-mark"><i class="bi bi-journal-text" aria-hidden="true"></i></div>
        <h1 id="loginBrandTitle">Журнал Общественного Совета</h1>
        <p id="loginBrandSubtitle">Система учёта входящих и исходящих писем</p>
    </div>

    <main class="login-container" id="loginSection" role="main">
        <div class="login-card">
            <div class="login-body">
                <h2 id="loginFormTitle">Вход в систему</h2>
                <form id="loginForm">
                    <div id="errorAlert" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                    <div id="infoAlert" class="alert alert-info d-none" role="alert" aria-live="assertive"></div>
                    <div class="form-group">
                        <label for="username" class="form-label" id="loginUsernameLabel">Логин</label>
                        <input type="text" class="form-control" id="username" name="username" autocomplete="username" required aria-required="true" />
                    </div>
                    <div class="form-group">
                        <label for="password" class="form-label" id="loginPasswordLabel">Пароль</label>
                        <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required aria-required="true" />
                    </div>
                    <div class="form-group d-none" id="totpGroup">
                        <label for="totpCode" class="form-label">Код 2FA (Google Authenticator)</label>
                        <input type="text" class="form-control" id="totpCode" name="totp_code" autocomplete="one-time-code" inputmode="numeric" maxlength="14" placeholder="000000" />
                        <small class="text-muted">Введите 6-значный код из приложения-аутентификатора или резервный код.</small>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rememberMe" name="rememberMe" />
                        <label class="form-check-label" for="rememberMe" id="loginRememberLabel">Запомнить меня</label>
                    </div>
                    <div class="form-check consent-check">
                        <input class="form-check-input" type="checkbox" id="consentCheck" name="consent" required aria-required="true" />
                        <label class="form-check-label" for="consentCheck" id="loginConsentLabel">
                            Я принимаю <a href="/legal/terms.html" target="_blank" rel="noopener">Пользовательское соглашение</a>
                            и <a href="/legal/privacy.html" target="_blank" rel="noopener">Политику обработки персональных данных</a>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-login mt-3">
                        <span class="loading-spinner"></span>
                        <span class="btn-text" id="loginSubmitText">Войти</span>
                    </button>
                    <div class="text-center mt-3">
                        <a href="#" id="forgotPasswordLink" style="font-size:.8125rem;color:var(--text-secondary)">Забыли пароль?</a>
                    </div>
                </form>

                <!-- Forgot password form (hidden by default) -->
                <form id="forgotForm" class="d-none">
                    <div id="forgotAlert" class="alert d-none" role="alert" aria-live="assertive"></div>
                    <p style="font-size:.875rem;color:var(--text-secondary);margin-bottom:16px">Введите email, привязанный к вашему аккаунту. Мы вышлем ссылку для сброса пароля.</p>
                    <div class="form-group">
                        <label for="forgotEmail" class="form-label">Email</label>
                        <input type="email" class="form-control" id="forgotEmail" required />
                    </div>
                    <button type="submit" class="btn btn-login">Отправить ссылку</button>
                    <div class="text-center mt-3">
                        <a href="#" id="backToLoginLink" style="font-size:.8125rem;color:var(--text-secondary)">← Вернуться ко входу</a>
                    </div>
                </form>

                <!-- Reset password form (shown when ?action=reset&token=... in URL) -->
                <form id="resetForm" class="d-none">
                    <div id="resetAlert" class="alert d-none" role="alert" aria-live="assertive"></div>
                    <p style="font-size:.875rem;color:var(--text-secondary);margin-bottom:16px">Введите новый пароль для вашего аккаунта.</p>
                    <div class="form-group">
                        <label for="newPassword" class="form-label">Новый пароль</label>
                        <input type="password" class="form-control" id="newPassword" minlength="8" autocomplete="new-password" required />
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword" class="form-label">Повторите пароль</label>
                        <input type="password" class="form-control" id="confirmPassword" minlength="8" autocomplete="new-password" required />
                    </div>
                    <button type="submit" class="btn btn-login">Сохранить пароль</button>
                </form>
            </div>
        </div>
    </main>

    <div class="login-container" id="tgLoginSection" style="display:none;margin-top:16px">
        <div style="background:rgba(255,255,255,.07);border-radius:12px;padding:16px 20px;text-align:center;border:1px solid rgba(255,255,255,.12)">
            <div style="color:rgba(255,255,255,.85);font-size:.875rem;margin-bottom:10px">
                <i class="bi bi-telegram" style="font-size:1.25rem;vertical-align:middle;margin-right:6px;color:#29b6f6" aria-hidden="true"></i>
                Войти через Telegram
            </div>
            <p style="color:rgba(255,255,255,.55);font-size:.8rem;margin-bottom:10px">
                Откройте бота, отправьте <code style="background:rgba(255,255,255,.1);padding:1px 5px;border-radius:4px">/login</code> и перейдите по ссылке.
            </p>
            <a id="tgBotLink" href="#" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:6px;background:#0088cc;color:#fff;border-radius:8px;padding:8px 16px;font-size:.875rem;font-weight:600;text-decoration:none">
                <i class="bi bi-telegram" aria-hidden="true"></i> Открыть бота
            </a>
        </div>
    </div>

    <div class="login-footer">
        <div class="login-legal-links">
            <a href="/help/" data-login-i18n="footer.help">Справка</a>
            <a href="/help/faq.html" data-login-i18n="footer.faq">FAQ</a>
            <a href="/legal/privacy.html" data-login-i18n="footer.privacy">Политика ПДн</a>
            <a href="/legal/terms.html" data-login-i18n="footer.terms">Соглашение</a>
        </div>
        <div>© <span data-site="year">2026</span> <span data-site="operatorName">ТОО «Журнал ОС»</span> · БСН <span data-site="bin">—</span></div>
    </div>

    <script src="/assets/vendor/bootstrap.bundle.min.js?v=1"></script>
    <!-- Bundled login scripts (csrf-handler + login-i18n), built by `npm run build`;
         order defined in frontend/login.entry.js. Loads before the inline login call. -->
    <script src="/dist/login.js?v=30"></script>
    <script src="/js/site-config.js?v=30"></script>
    <script src="/js/site-docs.js?v=30"></script>
    <script nonce="<?= $nonce ?>">
        const form = document.getElementById('loginForm');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        const totpInput = document.getElementById('totpCode');
        const totpGroup = document.getElementById('totpGroup');
        const rememberMeCheckbox = document.getElementById('rememberMe');
        const consentCheckbox = document.getElementById('consentCheck');
        const errorAlert = document.getElementById('errorAlert');
        const infoAlert = document.getElementById('infoAlert');
        const loginBtn = form.querySelector('button[type="submit"]');
        const t = (k) => window.LoginI18n?.t(k) || k;
        let totpRequired = false;

        window.addEventListener('DOMContentLoaded', () => {
            const savedUsername = localStorage.getItem('login_username');
            if (savedUsername) {
                usernameInput.value = savedUsername;
                rememberMeCheckbox.checked = true;
                passwordInput.focus();
            }
            const consentVersion = window.SITE_CONFIG?.consentVersion || '1.0';
            if (localStorage.getItem('pd_consent_version') === consentVersion) {
                consentCheckbox.checked = true;
            }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const username = usernameInput.value.trim();
            const password = passwordInput.value;
            if (!username || !password) {
                showError(t('err.fillAll'));
                return;
            }
            if (!consentCheckbox.checked) {
                showError(t('err.consent'));
                return;
            }
            loginBtn.classList.add('loading');
            loginBtn.disabled = true;
            errorAlert.classList.add('d-none');
            infoAlert.classList.add('d-none');
            try {
                const csrfResp = await fetch('api/auth.php?action=csrf');
                const csrfData = await csrfResp.json();
                const csrfToken = csrfData.csrf_token || '';
                const params = { username, password };
                if (totpRequired && totpInput.value.trim()) {
                    params.totp_code = totpInput.value.trim();
                }
                if (rememberMeCheckbox.checked) {
                    params.remember = '1';
                }
                const response = await fetch('api/auth.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
                    body: new URLSearchParams(params)
                });
                const data = await response.json();
                if (response.status === 202 && data.totp_required) {
                    // Show 2FA field
                    totpRequired = true;
                    totpGroup.classList.remove('d-none');
                    totpInput.required = true;
                    totpInput.focus();
                    showInfo('Введите код из приложения Google Authenticator');
                    return;
                }
                if (response.ok && data.authenticated) {
                    const consentVersion = window.SITE_CONFIG?.consentVersion || '1.0';
                    localStorage.setItem('pd_consent_version', consentVersion);
                    localStorage.setItem('pd_consent_at', new Date().toISOString());
                    if (rememberMeCheckbox.checked) {
                        localStorage.setItem('login_username', username);
                    } else {
                        localStorage.removeItem('login_username');
                    }
                    if (data.user) sessionStorage.setItem('user', JSON.stringify(data.user));
                    showInfo(t('info.success'));
                    setTimeout(() => { window.location.href = 'api/'; }, 500);
                } else {
                    showError(data.error || t('err.login'));
                }
            } catch (error) {
                showError(t('err.network'));
            } finally {
                loginBtn.classList.remove('loading');
                loginBtn.disabled = false;
            }
        });

        function showError(message) {
            errorAlert.textContent = message;
            errorAlert.classList.remove('d-none');
            infoAlert.classList.add('d-none');
        }

        function showInfo(message) {
            infoAlert.textContent = message;
            infoAlert.classList.remove('d-none');
            errorAlert.classList.add('d-none');
        }

        [usernameInput, passwordInput].forEach(el => {
            el.addEventListener('input', () => errorAlert.classList.add('d-none'));
        });

        // Password reset flow
        const loginFormEl   = form;
        const forgotFormEl  = document.getElementById('forgotForm');
        const resetFormEl   = document.getElementById('resetForm');
        const loginBody     = document.querySelector('.login-body h2');

        document.getElementById('forgotPasswordLink').addEventListener('click', (e) => {
            e.preventDefault();
            loginFormEl.classList.add('d-none');
            document.getElementById('forgotPasswordLink').classList.add('d-none');
            forgotFormEl.classList.remove('d-none');
            if (loginBody) loginBody.textContent = 'Сброс пароля';
        });

        document.getElementById('backToLoginLink').addEventListener('click', (e) => {
            e.preventDefault();
            forgotFormEl.classList.add('d-none');
            loginFormEl.classList.remove('d-none');
            document.getElementById('forgotPasswordLink').classList.remove('d-none');
            if (loginBody) loginBody.textContent = 'Вход в систему';
        });

        forgotFormEl.addEventListener('submit', async (e) => {
            e.preventDefault();
            const alertEl = document.getElementById('forgotAlert');
            const email   = document.getElementById('forgotEmail').value.trim();
            try {
                const csrfResp = await fetch('api/auth.php?action=csrf');
                const csrfData = await csrfResp.json();
                const csrfToken = csrfData.csrf_token || '';
                const res  = await fetch('api/auth.php?action=forgot_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ email }),
                });
                const data = await res.json();
                alertEl.className = 'alert alert-info';
                alertEl.textContent = data.message || 'Письмо отправлено.';
                alertEl.classList.remove('d-none');
            } catch (_) {
                alertEl.className = 'alert alert-danger';
                alertEl.textContent = t('err.network');
                alertEl.classList.remove('d-none');
            }
        });

        // Check for reset token in URL
        (() => {
            const params = new URLSearchParams(window.location.search);
            if (params.get('action') === 'reset' && params.get('token')) {
                loginFormEl.classList.add('d-none');
                forgotFormEl.classList.add('d-none');
                resetFormEl.classList.remove('d-none');
                if (loginBody) loginBody.textContent = 'Новый пароль';

                resetFormEl.addEventListener('submit', async (ev) => {
                    ev.preventDefault();
                    const alertEl = document.getElementById('resetAlert');
                    const pass    = document.getElementById('newPassword').value;
                    const conf    = document.getElementById('confirmPassword').value;
                    if (pass !== conf) {
                        alertEl.className = 'alert alert-danger';
                        alertEl.textContent = 'Пароли не совпадают';
                        alertEl.classList.remove('d-none');
                        return;
                    }
                    try {
                        const csrfResp2 = await fetch('api/auth.php?action=csrf');
                        const csrfData2 = await csrfResp2.json();
                        const csrfToken2 = csrfData2.csrf_token || '';
                        const res  = await fetch('api/auth.php?action=reset_password', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken2 },
                            body: JSON.stringify({ token: params.get('token'), password: pass }),
                        });
                        const data = await res.json();
                        if (res.ok) {
                            alertEl.className = 'alert alert-success';
                            alertEl.textContent = data.message;
                            alertEl.classList.remove('d-none');
                            resetFormEl.querySelectorAll('input, button').forEach(el => el.disabled = true);
                            setTimeout(() => { window.location.href = '/login.php'; }, 2000);
                        } else {
                            alertEl.className = 'alert alert-danger';
                            alertEl.textContent = data.error || 'Ошибка сброса пароля';
                            alertEl.classList.remove('d-none');
                        }
                    } catch (_) {
                        alertEl.className = 'alert alert-danger';
                        alertEl.textContent = t('err.network');
                        alertEl.classList.remove('d-none');
                    }
                });
            }
        })();

        // Show Telegram login option if bot is configured
        (async () => {
            try {
                const res = await fetch('api/config_public.php');
                if (!res.ok) return;
                const js = await res.text();
                const m = js.match(/window\.TELEGRAM_BOT_USERNAME\s*=\s*"([^"]+)"/);
                if (m && m[1]) {
                    const section = document.getElementById('tgLoginSection');
                    const link    = document.getElementById('tgBotLink');
                    if (section && link) {
                        link.href = 'https://t.me/' + encodeURIComponent(m[1]);
                        section.style.display = '';
                    }
                }
            } catch (e) {}
        })();
    </script>
</body>
</html>
