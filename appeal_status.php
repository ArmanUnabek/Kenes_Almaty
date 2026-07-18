<?php
// Public appeal-status lookup page. No auth: the citizen enters the appeal number
// they received plus a verifier (surname or the email they used). Data comes from
// /api/appeals.php?action=track (rate-limited server-side).
require_once __DIR__ . '/config.php';

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Статус обращения — Общественный Совет</title>
    <link href="/assets/vendor/bootstrap.min.css?v=40" rel="stylesheet" />
    <link href="/styles.css?v=40" rel="stylesheet" />
    <link href="/assets/vendor/inter.css?v=40" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons.css?v=40">
    <style>
        body.appeal-page { background: var(--brand-navy, #0F1B33); min-height: 100vh; padding: 40px 16px 60px; }
        .track-card { max-width: 620px; margin: 0 auto; }
        .track-steps { list-style: none; padding: 0; margin: 0; }
        .track-steps li { display: flex; align-items: center; gap: .6rem; padding: .5rem 0; color: #6c757d; }
        .track-steps li.done { color: #198754; font-weight: 600; }
        .track-steps li.current { color: var(--brand-navy, #0F1B33); font-weight: 700; }
        .track-steps .bi { font-size: 1.2rem; }
    </style>
</head>
<body class="appeal-page">
    <div class="card shadow-sm track-card">
        <div class="card-body p-4">
            <h1 class="h4 mb-1"><i class="bi bi-search me-2"></i>Статус обращения</h1>
            <p class="text-muted small mb-4">Введите номер обращения и фамилию (или email, указанный при подаче).</p>

            <form id="trackForm" class="row g-2" autocomplete="off">
                <div class="col-12 col-sm-6">
                    <input type="text" class="form-control" id="number" placeholder="ОС-2026-0001" required />
                </div>
                <div class="col-12 col-sm-6">
                    <input type="text" class="form-control" id="verifier" placeholder="Фамилия или email" required />
                </div>
                <div class="col-12 d-grid">
                    <button type="submit" class="btn btn-primary" id="trackBtn">
                        <i class="bi bi-search me-1"></i> Проверить статус
                    </button>
                </div>
            </form>

            <div id="trackError" class="alert alert-warning mt-3 d-none" role="alert"></div>

            <div id="trackResult" class="mt-4 d-none">
                <h2 class="h6 text-muted">Обращение <span id="rNumber" class="fw-bold text-dark"></span></h2>
                <p class="mb-3"><span id="rSubject"></span></p>
                <ul class="track-steps mb-3">
                    <li data-step="new"><i class="bi bi-inbox"></i> Принято</li>
                    <li data-step="in_review"><i class="bi bi-hourglass-split"></i> На рассмотрении</li>
                    <li data-step="responded"><i class="bi bi-chat-left-text"></i> Дан ответ</li>
                    <li data-step="closed"><i class="bi bi-check2-circle"></i> Закрыто</li>
                </ul>
                <div id="rResponseWrap" class="d-none">
                    <div class="fw-semibold mb-1">Ответ:</div>
                    <div id="rResponse" class="border rounded p-3 bg-light"></div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top small">
                <a href="/appeal.php"><i class="bi bi-plus-circle me-1"></i>Подать новое обращение</a>
            </div>
        </div>
    </div>

    <script nonce="<?= $nonce ?>">
        const ORDER = ['new', 'in_review', 'responded', 'closed'];
        const form = document.getElementById('trackForm');
        const errBox = document.getElementById('trackError');
        const result = document.getElementById('trackResult');

        function showError(msg) {
            errBox.textContent = msg;
            errBox.classList.remove('d-none');
            result.classList.add('d-none');
        }

        function render(data) {
            errBox.classList.add('d-none');
            document.getElementById('rNumber').textContent = data.appeal_number || '';
            document.getElementById('rSubject').textContent = data.subject || '';
            const idx = ORDER.indexOf(data.status);
            document.querySelectorAll('.track-steps li').forEach((li) => {
                const i = ORDER.indexOf(li.dataset.step);
                li.classList.toggle('done', i >= 0 && i < idx);
                li.classList.toggle('current', i === idx);
            });
            const wrap = document.getElementById('rResponseWrap');
            if (data.response_text) {
                document.getElementById('rResponse').textContent = data.response_text;
                wrap.classList.remove('d-none');
            } else {
                wrap.classList.add('d-none');
            }
            result.classList.remove('d-none');
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const number = document.getElementById('number').value.trim();
            const verifier = document.getElementById('verifier').value.trim();
            if (!number || !verifier) return;
            const btn = document.getElementById('trackBtn');
            btn.disabled = true;
            try {
                const url = '/api/appeals.php?action=track&number=' + encodeURIComponent(number) +
                            '&verifier=' + encodeURIComponent(verifier);
                const res = await fetch(url);
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    showError(data.error || 'Не удалось получить статус. Попробуйте позже.');
                    return;
                }
                render(data);
            } catch (_) {
                showError('Сеть недоступна. Попробуйте позже.');
            } finally {
                btn.disabled = false;
            }
        });
    </script>
</body>
</html>
