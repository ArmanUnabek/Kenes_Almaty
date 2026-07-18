<?php
// Load regions for the select
require_once __DIR__ . '/config.php';

$regions = [];
try {
    $db = getDBConnection();
    $stmt = $db->query('SELECT id, name_ru FROM regions WHERE is_active = 1 ORDER BY name_ru');
    $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // non-fatal — form will still render without region list
}

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Обращение гражданина — Общественный Совет</title>
    <link href="/assets/vendor/bootstrap.min.css?v=40" rel="stylesheet" />
    <link href="/styles.css?v=40" rel="stylesheet" />
    <link href="/assets/vendor/inter.css?v=40" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons.css?v=40">
    <style>
        body.appeal-page {
            background: var(--brand-navy);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 40px 16px 60px;
            position: relative;
        }
        body.appeal-page::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse at 20% 10%, rgba(217,165,33,.14) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 90%, rgba(29,78,216,.16) 0%, transparent 50%);
            pointer-events: none;
        }
        .appeal-brand { text-align: center; margin-bottom: 28px; position: relative; z-index: 1; }
        .appeal-mark {
            width: 56px; height: 56px; border-radius: 14px; background: var(--brand-gold);
            color: var(--brand-navy); display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.75rem; margin-bottom: 14px;
            box-shadow: 0 8px 24px rgba(217,165,33,.4);
        }
        .appeal-brand h1 { font-size: 1.3rem; font-weight: 700; color: #fff; margin: 0; }
        .appeal-brand p  { font-size: .85rem; color: rgba(199,210,230,.75); margin: 6px 0 0; }
        .appeal-wrap { width: 100%; max-width: 580px; position: relative; z-index: 1; }
        .appeal-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 24px 64px rgba(0,0,0,.4), 0 0 0 1px rgba(255,255,255,.06);
            border-top: 3px solid var(--brand-gold);
            overflow: hidden;
        }
        .appeal-body { padding: 32px; }
        .appeal-body h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: 20px; color: var(--text-primary); }
        .appeal-footer-link {
            text-align: center;
            margin-top: 16px;
            font-size: .85rem;
            color: rgba(199,210,230,.7);
        }
        .appeal-footer-link a { color: rgba(199,210,230,.9); }
        #appealResult { display: none; }
    </style>
</head>
<body class="appeal-page">
    <div class="appeal-brand">
        <div class="appeal-mark"><i class="bi bi-envelope-paper" aria-hidden="true"></i></div>
        <h1>Общественный Совет</h1>
        <p>Подача обращения гражданина</p>
    </div>

    <main class="appeal-wrap" role="main">
        <div class="appeal-card">
            <div class="appeal-body">
                <h2>Обращение гражданина</h2>

                <!-- Success state -->
                <div id="appealResult" class="text-center py-3">
                    <div class="mb-3" style="font-size:3rem; color:var(--brand-gold)" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></div>
                    <h5 class="fw-bold mb-1">Обращение принято</h5>
                    <p class="text-muted mb-0">Ваш номер обращения:</p>
                    <div id="appealNumberDisplay" class="fs-4 fw-bold text-primary my-2"></div>
                    <p class="small text-muted">Мы рассмотрим ваше обращение в установленные сроки.<br>Сохраните номер для отслеживания статуса.</p>
                    <a href="/appeal.php" class="btn btn-outline-secondary btn-sm mt-2">Подать ещё одно</a>
                </div>

                <!-- Form -->
                <form id="appealForm" novalidate>
                    <!-- Honeypot (скрытое поле — боты заполняют его) -->
                    <input type="text" name="url" id="urlField" style="display:none" tabindex="-1" autocomplete="off" aria-hidden="true" />

                    <div id="appealAlert" class="alert d-none mb-3" role="alert" aria-live="assertive"></div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="aFullName">ФИО <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="text" class="form-control" id="aFullName" name="full_name" placeholder="Иванов Иван Иванович" required aria-required="true" />
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold" for="aEmail">Email</label>
                            <input type="email" class="form-control" id="aEmail" name="email" placeholder="you@example.com" />
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold" for="aPhone">Телефон</label>
                            <input type="tel" class="form-control" id="aPhone" name="phone" placeholder="+7 700 000 0000" />
                        </div>
                        <?php if ($regions): ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="aRegion">Регион ОС</label>
                            <select class="form-select" id="aRegion" name="region_id">
                                <option value="">— Выберите регион —</option>
                                <?php foreach ($regions as $r): ?>
                                <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['name_ru'], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="aCategory">Категория <span class="text-danger" aria-hidden="true">*</span></label>
                            <select class="form-select" id="aCategory" name="category" required aria-required="true">
                                <option value="other">Иное</option>
                                <option value="complaint">Жалоба</option>
                                <option value="suggestion">Предложение</option>
                                <option value="question">Вопрос</option>
                                <option value="request">Заявление</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="aSubject">Тема обращения <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="text" class="form-control" id="aSubject" name="subject" required aria-required="true" placeholder="Кратко опишите суть" />
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="aMessage">Текст обращения <span class="text-danger" aria-hidden="true">*</span></label>
                            <textarea class="form-control" id="aMessage" name="message" rows="5" required aria-required="true" placeholder="Подробно изложите вашу проблему или предложение (минимум 10 символов)"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary w-100" id="appealSubmitBtn">
                                <span id="appealBtnText"><i class="bi bi-send me-1"></i>Отправить обращение</span>
                                <span id="appealBtnSpinner" class="d-none"><span class="spinner-border spinner-border-sm me-1" role="status"></span>Отправка...</span>
                            </button>
                        </div>
                        <div class="col-12">
                            <p class="small text-muted mb-0">
                                Подача обращения регулируется законодательством РК.
                                Не более 3 обращений в сутки с одного устройства.
                            </p>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="appeal-footer-link">
            <a href="/api/">← Перейти в систему</a>
        </div>
    </main>

    <script src="/assets/vendor/bootstrap.bundle.min.js?v=40" nonce="<?= htmlspecialchars($nonce, ENT_QUOTES) ?>"></script>
    <script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES) ?>">
    (function () {
        const form    = document.getElementById('appealForm');
        const result  = document.getElementById('appealResult');
        const alert   = document.getElementById('appealAlert');
        const btnText = document.getElementById('appealBtnText');
        const btnSpin = document.getElementById('appealBtnSpinner');
        const btn     = document.getElementById('appealSubmitBtn');
        const numEl   = document.getElementById('appealNumberDisplay');
        let csrfToken = '';

        // Fetch CSRF token before submit
        async function getCsrf() {
            if (csrfToken) return csrfToken;
            const res = await fetch('/api/auth.php?action=csrf');
            if (!res.ok) throw new Error('csrf failed');
            const data = await res.json();
            csrfToken = data.csrf_token || data.token || '';
            return csrfToken;
        }

        function showAlert(msg, type = 'danger') {
            alert.className = `alert alert-${type}`;
            alert.textContent = msg;
            alert.classList.remove('d-none');
        }
        function hideAlert() { alert.classList.add('d-none'); }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            hideAlert();

            // Contact validation
            const email = document.getElementById('aEmail').value.trim();
            const phone = document.getElementById('aPhone').value.trim();
            if (!email && !phone) {
                showAlert('Укажите email или телефон для связи.');
                return;
            }

            btn.disabled = true;
            btnText.classList.add('d-none');
            btnSpin.classList.remove('d-none');

            const payload = {
                full_name: document.getElementById('aFullName').value.trim(),
                email,
                phone,
                region_id: document.getElementById('aRegion')?.value || null,
                category:  document.getElementById('aCategory').value,
                subject:   document.getElementById('aSubject').value.trim(),
                message:   document.getElementById('aMessage').value.trim(),
                // Honeypot
                url: document.getElementById('urlField').value,
            };

            try {
                const token = await getCsrf();
                const res = await fetch('/api/appeals.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': token,
                    },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (res.ok && data.success) {
                    form.style.display = 'none';
                    numEl.textContent = data.appeal_number || '—';
                    result.style.display = 'block';
                } else {
                    const msg = data.errors
                        ? Object.values(data.errors).join(' ')
                        : (data.error || 'Ошибка при отправке. Попробуйте ещё раз.');
                    showAlert(msg);
                }
            } catch (err) {
                showAlert('Ошибка сети. Проверьте подключение и попробуйте снова.');
            } finally {
                btn.disabled = false;
                btnText.classList.remove('d-none');
                btnSpin.classList.add('d-none');
            }
        });
    })();
    </script>
</body>
</html>
