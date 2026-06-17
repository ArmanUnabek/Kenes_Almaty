const API = '/api';

let regions = [];
let users = [];
let editRegionModal;
let bootstrapRegionModal;
let editUserModal;
let auditDetailModal;
let auditPage = 1;
let auditItems = [];
let auditSecurityOnly = false;
let auditRegionId = '';
const auditLimit = 50;

async function apiFetch(url, options = {}) {
  const resp = await fetch(url, options);
  const data = await resp.json().catch(() => ({}));
  if (!resp.ok) {
    throw new Error(data.error || `HTTP ${resp.status}`);
  }
  return data;
}

async function ensureAdmin() {
  const data = await apiFetch(`${API}/auth.php`);
  if (!data.authenticated || !data.user?.is_admin) {
    window.location.href = '/login.html';
    return null;
  }
  regions = data.regions || [];
  return data.user;
}

function showTab(name) {
  document.querySelectorAll('#adminTabs .nav-link').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.tab === name);
  });
  document.getElementById('tab-regions').classList.toggle('d-none', name !== 'regions');
  document.getElementById('tab-users').classList.toggle('d-none', name !== 'users');
  document.getElementById('tab-overview').classList.toggle('d-none', name !== 'overview');
  document.getElementById('tab-audit').classList.toggle('d-none', name !== 'audit');
}

async function loadRegionsWithStats() {
  const list = await apiFetch(`${API}/regions.php`);
  const detailed = await Promise.all(
    list.map(async (r) => {
      try {
        return await apiFetch(`${API}/regions.php?id=${r.id}`);
      } catch {
        return r;
      }
    })
  );
  regions = detailed;
  renderRegionsGrid();
  renderOverview();
  populateRegionSelects();
  populateAuditRegionFilter();
}

function populateAuditRegionFilter() {
  const select = document.getElementById('auditRegionFilter');
  if (!select) return;
  const prev = select.value;
  select.innerHTML = '<option value="">Все регионы</option>' +
    regions.map((r) => `<option value="${r.id}">${escapeHtml(r.name_ru)}</option>`).join('');
  if (prev) select.value = prev;
}

function renderRegionsGrid() {
  const grid = document.getElementById('regionsGrid');
  if (!regions.length) {
    grid.innerHTML = '<div class="col-12 text-muted">Регионов пока нет</div>';
    return;
  }
  grid.innerHTML = regions.map((r) => {
    const stats = r.stats || {};
    const active = r.is_active == 1 || r.is_active === true;
    return `
      <div class="col-md-6 col-lg-4">
        <div class="card region-card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h5 class="card-title mb-0">${escapeHtml(r.name_ru)}</h5>
              <span class="badge ${active ? 'bg-success' : 'bg-secondary'}">${active ? 'Активен' : 'Выкл.'}</span>
            </div>
            <div class="stat-pill mb-2">Код: <code>${escapeHtml(r.code)}</code></div>
            <div class="small text-muted">
              Членов: ${stats.members_count ?? '—'} · Комиссий: ${stats.commissions_count ?? '—'}<br>
              Вх: ${stats.incoming_letters_count ?? '—'} · Исх: ${stats.outgoing_letters_count ?? '—'}
            </div>
          </div>
          <div class="card-footer bg-white d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-primary" data-action="edit-region" data-id="${r.id}">Изменить</button>
            <button class="btn btn-sm btn-outline-success" data-action="open-region" data-id="${r.id}">Открыть журнал</button>
            <button class="btn btn-sm btn-outline-warning" data-action="bootstrap-region" data-id="${r.id}">Инициализировать</button>
            <a class="btn btn-sm btn-outline-secondary" href="${API}/region_export.php?region_id=${r.id}&download=1">Экспорт</a>
            ${active ? `<button class="btn btn-sm btn-outline-secondary" data-action="deactivate-region" data-id="${r.id}">Деактивировать</button>` : ''}
            ${active ? '' : `<button class="btn btn-sm btn-outline-secondary" data-action="activate-region" data-id="${r.id}">Активировать</button>`}
          </div>
        </div>
      </div>`;
  }).join('');

  grid.querySelectorAll('[data-action="edit-region"]').forEach((btn) => {
    btn.addEventListener('click', () => openEditRegion(Number(btn.dataset.id)));
  });
  grid.querySelectorAll('[data-action="open-region"]').forEach((btn) => {
    btn.addEventListener('click', () => switchToRegion(Number(btn.dataset.id)));
  });
  grid.querySelectorAll('[data-action="activate-region"]').forEach((btn) => {
    btn.addEventListener('click', () => activateRegion(Number(btn.dataset.id)));
  });
  grid.querySelectorAll('[data-action="deactivate-region"]').forEach((btn) => {
    btn.addEventListener('click', () => deactivateRegion(Number(btn.dataset.id)));
  });
  grid.querySelectorAll('[data-action="bootstrap-region"]').forEach((btn) => {
    btn.addEventListener('click', () => openBootstrapRegion(Number(btn.dataset.id)));
  });
}

function renderOverview() {
  const grid = document.getElementById('overviewGrid');
  grid.innerHTML = regions.map((r) => {
    const s = r.stats || {};
    return `
      <div class="col-md-6 col-lg-4">
        <div class="card h-100">
          <div class="card-header">${escapeHtml(r.name_ru)}</div>
          <div class="card-body small">
            <div class="d-flex justify-content-between"><span>Члены ОС</span><strong>${s.members_count ?? 0}</strong></div>
            <div class="d-flex justify-content-between"><span>Комиссии</span><strong>${s.commissions_count ?? 0}</strong></div>
            <div class="d-flex justify-content-between"><span>Входящие</span><strong>${s.incoming_letters_count ?? 0}</strong></div>
            <div class="d-flex justify-content-between"><span>Исходящие</span><strong>${s.outgoing_letters_count ?? 0}</strong></div>
          </div>
        </div>
      </div>`;
  }).join('');
}

function parseRegionSettings(raw) {
  if (typeof raw === 'string') {
    try { return JSON.parse(raw); } catch { return {}; }
  }
  return raw || {};
}

function populateRegionSelects() {
  const select = document.getElementById('userRegionId');
  const editSelect = document.getElementById('editUserRegionId');
  const options = '<option value="">— без региона (админ) —</option>' +
    regions.filter((r) => r.is_active == 1 || r.is_active === true)
      .map((r) => `<option value="${r.id}">${escapeHtml(r.name_ru)}</option>`).join('');
  if (select) select.innerHTML = options;
  if (editSelect) editSelect.innerHTML = options;

  const tpl = document.getElementById('bootstrapTemplateRegion');
  if (tpl) {
    tpl.innerHTML = regions
      .map((r) => `<option value="${r.id}">${escapeHtml(r.name_ru)}</option>`).join('');
  }
}

async function loadUsers() {
  const data = await apiFetch(`${API}/users.php`);
  users = data.items || [];
  renderUsersTable();
}

function renderUsersTable() {
  const tbody = document.querySelector('#usersTable tbody');
  tbody.innerHTML = users.map((u) => `
    <tr>
      <td>${escapeHtml(u.full_name)}</td>
      <td>${escapeHtml(u.username)}</td>
      <td><span class="badge bg-light text-dark border">${escapeHtml(u.role)}</span></td>
      <td>${escapeHtml(u.region_name || '—')}</td>
      <td>${u.is_active == 1 || u.is_active === true ? 'Да' : 'Нет'}</td>
      <td class="text-end d-flex gap-1 justify-content-end">
        <button class="btn btn-sm btn-outline-primary" data-edit-user="${u.id}">Изменить</button>
        ${u.is_active == 1 || u.is_active === true
          ? `<button class="btn btn-sm btn-outline-danger" data-deactivate="${u.id}">Деактивировать</button>`
          : ''}
      </td>
    </tr>`).join('');

  tbody.querySelectorAll('[data-deactivate]').forEach((btn) => {
    btn.addEventListener('click', () => deactivateUser(Number(btn.dataset.deactivate)));
  });
  tbody.querySelectorAll('[data-edit-user]').forEach((btn) => {
    btn.addEventListener('click', () => openEditUser(Number(btn.dataset.editUser)));
  });
}

function openEditUser(id) {
  const user = users.find((u) => Number(u.id) === id);
  if (!user) return;
  document.getElementById('editUserId').value = user.id;
  document.getElementById('editUserUsername').value = user.username || '';
  document.getElementById('editUserEmail').value = user.email || '';
  document.getElementById('editUserFullName').value = user.full_name || '';
  document.getElementById('editUserRole').value = user.role || 'viewer';
  document.getElementById('editUserRegionId').value = user.region_id || '';
  document.getElementById('editUserRegionId').disabled = user.role === 'admin';
  document.getElementById('editUserPassword').value = '';
  document.getElementById('editUserActive').checked = !!(user.is_active == 1 || user.is_active === true);
  editUserModal.show();
}

function openEditRegion(id) {
  const r = regions.find((x) => Number(x.id) === id);
  if (!r) return;
  document.getElementById('editRegionId').value = r.id;
  document.getElementById('editRegionNameRu').value = r.name_ru || '';
  document.getElementById('editRegionNameKz').value = r.name_kz || '';
  document.getElementById('editRegionCode').value = r.code || '';
  document.getElementById('editRegionActive').checked = !!(r.is_active == 1 || r.is_active === true);
  const settings = parseRegionSettings(r.settings);
  document.getElementById('editRegionSeqIncoming').value = settings.seq_baseline_incoming ?? 0;
  document.getElementById('editRegionSeqOutgoing').value = settings.seq_baseline_outgoing ?? 0;
  editRegionModal.show();
}

async function switchToRegion(regionId) {
  await apiFetch(`${API}/auth.php?action=switch_region`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'switch_region', region_id: String(regionId) }),
  });
  window.location.href = '/api/';
}

async function activateRegion(id) {
  const r = regions.find((x) => Number(x.id) === id);
  if (!r) return;
  await apiFetch(`${API}/regions.php`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...r, id, is_active: true }),
  });
  await loadRegionsWithStats();
}

async function deactivateRegion(id) {
  const r = regions.find((x) => Number(x.id) === id);
  if (!r || !confirm(`Деактивировать регион «${r.name_ru}»?`)) return;
  await apiFetch(`${API}/regions.php`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...r, id, is_active: false }),
  });
  await loadRegionsWithStats();
}

function openBootstrapRegion(id) {
  const r = regions.find((x) => Number(x.id) === id);
  if (!r) return;
  document.getElementById('bootstrapRegionId').value = r.id;
  document.getElementById('bootstrapRegionName').textContent = `Регион: ${r.name_ru}`;
  let settings = r.settings;
  if (typeof settings === 'string') {
    try { settings = JSON.parse(settings); } catch { settings = {}; }
  }
  settings = settings || {};
  document.getElementById('bootstrapSeqIncoming').value = settings.seq_baseline_incoming ?? 0;
  document.getElementById('bootstrapSeqOutgoing').value = settings.seq_baseline_outgoing ?? 0;
  document.getElementById('bootstrapCopyCommissions').checked = true;
  const tpl = document.getElementById('bootstrapTemplateRegion');
  if (tpl) {
    const defaultTpl = regions.find((x) => Number(x.id) === 1)
      ? '1'
      : String(regions.find((x) => Number(x.id) !== id)?.id || regions[0]?.id || 1);
    tpl.value = defaultTpl;
  }
  bootstrapRegionModal.show();
}

async function loadAuditLogs(page = auditPage) {
  auditPage = page;
  const securityParam = auditSecurityOnly ? '&security=1' : '';
  const regionParam = auditRegionId ? `&region_id=${encodeURIComponent(auditRegionId)}` : '';
  const tbody = document.querySelector('#auditTable tbody');
  try {
    const data = await apiFetch(`${API}/audit_logs.php?page=${page}&limit=${auditLimit}${securityParam}${regionParam}`);
    auditItems = data.items || [];
    tbody.innerHTML = auditItems.length
    ? auditItems.map((row, idx) => {
      const op = row.operation || '';
      const isSecurity = op === 'EXPORT' || op === 'DOWNLOAD';
      const badgeClass = isSecurity ? 'bg-warning text-dark' : 'bg-light text-dark border';
      return `
      <tr class="audit-row${isSecurity ? ' table-warning' : ''}" data-audit-idx="${idx}" style="cursor:pointer;">
        <td class="text-nowrap small">${escapeHtml(formatAuditDate(row.created_at))}</td>
        <td>${escapeHtml(row.user_name || row.user_login || '—')}</td>
        <td><code>${escapeHtml(row.table_name || '')}</code></td>
        <td><span class="badge ${badgeClass}">${escapeHtml(op)}</span></td>
        <td>${row.record_id ?? '—'}</td>
        <td class="small text-muted">${escapeHtml(row.ip_address || '')}</td>
      </tr>`;
    }).join('')
    : '<tr><td colspan="6" class="text-center text-muted py-4">Записей нет</td></tr>';

  tbody.querySelectorAll('.audit-row').forEach((row) => {
    row.addEventListener('click', () => showAuditDetail(Number(row.dataset.auditIdx)));
  });

  const pagination = data.pagination || {};
  const total = pagination.total ?? 0;
  const pages = pagination.pages ?? 1;
  document.getElementById('auditPaginationInfo').textContent =
    `Страница ${auditPage} из ${pages} · всего ${total}`;
  document.getElementById('auditPrevBtn').disabled = auditPage <= 1;
  document.getElementById('auditNextBtn').disabled = auditPage >= pages;
  } catch (err) {
    console.error(err);
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">${escapeHtml(err.message || 'Ошибка загрузки аудита')}</td></tr>`;
    }
    document.getElementById('auditPaginationInfo').textContent = '—';
    document.getElementById('auditPrevBtn').disabled = true;
    document.getElementById('auditNextBtn').disabled = true;
  }
}

function formatAuditDate(value) {
  if (!value) return '';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString('ru-RU');
}

function formatAuditJson(value) {
  if (!value) return '—';
  if (typeof value === 'string') {
    try { return JSON.stringify(JSON.parse(value), null, 2); } catch { return value; }
  }
  return JSON.stringify(value, null, 2);
}

function showAuditDetail(index) {
  const row = auditItems[index];
  if (!row) return;
  const body = document.getElementById('auditDetailBody');
  body.innerHTML = `
    <dl class="row small mb-3">
      <dt class="col-sm-3">Дата</dt><dd class="col-sm-9">${escapeHtml(formatAuditDate(row.created_at))}</dd>
      <dt class="col-sm-3">Пользователь</dt><dd class="col-sm-9">${escapeHtml(row.user_name || row.user_login || '—')}</dd>
      <dt class="col-sm-3">Таблица</dt><dd class="col-sm-9"><code>${escapeHtml(row.table_name || '')}</code></dd>
      <dt class="col-sm-3">Операция</dt><dd class="col-sm-9">${escapeHtml(row.operation || '')}</dd>
      <dt class="col-sm-3">ID записи</dt><dd class="col-sm-9">${row.record_id ?? '—'}</dd>
      <dt class="col-sm-3">IP</dt><dd class="col-sm-9">${escapeHtml(row.ip_address || '')}</dd>
    </dl>
    <div class="mb-2 fw-semibold">Было</div>
    <pre class="audit-json-block">${escapeHtml(formatAuditJson(row.old_values))}</pre>
    <div class="mb-2 mt-3 fw-semibold">Стало</div>
    <pre class="audit-json-block">${escapeHtml(formatAuditJson(row.new_values))}</pre>
  `;
  auditDetailModal.show();
}

async function deactivateUser(id) {
  if (!confirm('Деактивировать пользователя?')) return;
  await apiFetch(`${API}/users.php?id=${id}`, { method: 'DELETE' });
  await loadUsers();
}

document.getElementById('adminTabs').addEventListener('click', (e) => {
  const btn = e.target.closest('[data-tab]');
  if (!btn) return;
  showTab(btn.dataset.tab);
  if (btn.dataset.tab === 'users') loadUsers();
  if (btn.dataset.tab === 'audit') loadAuditLogs(1);
});

document.getElementById('formRegion').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await apiFetch(`${API}/regions.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name_ru: document.getElementById('regionNameRu').value.trim(),
        name_kz: document.getElementById('regionNameKz').value.trim(),
        code: document.getElementById('regionCode').value.trim().toLowerCase(),
        is_active: true,
      }),
    });
    e.target.reset();
    await loadRegionsWithStats();
    alert('Регион создан');
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('formUser').addEventListener('submit', async (e) => {
  e.preventDefault();
  const role = document.getElementById('userRole').value;
  const regionId = document.getElementById('userRegionId').value;
  try {
    await apiFetch(`${API}/users.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        username: document.getElementById('userUsername').value.trim(),
        email: document.getElementById('userEmail').value.trim(),
        full_name: document.getElementById('userFullName').value.trim(),
        password: document.getElementById('userPassword').value,
        role,
        region_id: role === 'admin' ? null : (regionId || null),
      }),
    });
    e.target.reset();
    await loadUsers();
    alert('Пользователь создан');
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('formBootstrapRegion').addEventListener('submit', async (e) => {
  e.preventDefault();
  const regionId = Number(document.getElementById('bootstrapRegionId').value);
  try {
    const result = await apiFetch(`${API}/regions.php?action=bootstrap`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        region_id: regionId,
        template_region_id: Number(document.getElementById('bootstrapTemplateRegion').value) || 1,
        seq_baseline_incoming: Number(document.getElementById('bootstrapSeqIncoming').value) || 0,
        seq_baseline_outgoing: Number(document.getElementById('bootstrapSeqOutgoing').value) || 0,
        copy_commissions: document.getElementById('bootstrapCopyCommissions').checked,
      }),
    });
    bootstrapRegionModal.hide();
    await loadRegionsWithStats();
    alert(`Регион инициализирован. Скопировано комиссий: ${result.commissions_copied ?? 0}`);
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('auditRefreshBtn')?.addEventListener('click', () => loadAuditLogs(auditPage));
document.getElementById('auditSecurityOnly')?.addEventListener('change', (e) => {
  auditSecurityOnly = !!e.target.checked;
  loadAuditLogs(1);
});
document.getElementById('auditRegionFilter')?.addEventListener('change', (e) => {
  auditRegionId = e.target.value || '';
  loadAuditLogs(1);
});
document.getElementById('auditPrevBtn')?.addEventListener('click', () => {
  if (auditPage > 1) loadAuditLogs(auditPage - 1);
});
document.getElementById('auditNextBtn')?.addEventListener('click', () => {
  loadAuditLogs(auditPage + 1);
});

document.getElementById('formEditRegion').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = Number(document.getElementById('editRegionId').value);
  const existing = regions.find((r) => Number(r.id) === id);
  const prevSettings = parseRegionSettings(existing?.settings);
  try {
    await apiFetch(`${API}/regions.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id,
        name_ru: document.getElementById('editRegionNameRu').value.trim(),
        name_kz: document.getElementById('editRegionNameKz').value.trim(),
        code: document.getElementById('editRegionCode').value.trim(),
        is_active: document.getElementById('editRegionActive').checked,
        settings: {
          ...prevSettings,
          seq_baseline_incoming: Number(document.getElementById('editRegionSeqIncoming').value) || 0,
          seq_baseline_outgoing: Number(document.getElementById('editRegionSeqOutgoing').value) || 0,
        },
      }),
    });
    editRegionModal.hide();
    await loadRegionsWithStats();
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('formEditUser').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = Number(document.getElementById('editUserId').value);
  const role = document.getElementById('editUserRole').value;
  const regionId = document.getElementById('editUserRegionId').value;
  const payload = {
    id,
    username: document.getElementById('editUserUsername').value.trim(),
    email: document.getElementById('editUserEmail').value.trim(),
    full_name: document.getElementById('editUserFullName').value.trim(),
    role,
    region_id: role === 'admin' ? null : (regionId || null),
    is_active: document.getElementById('editUserActive').checked,
  };
  const password = document.getElementById('editUserPassword').value;
  if (password) payload.password = password;
  try {
    await apiFetch(`${API}/users.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    editUserModal.hide();
    await loadUsers();
    alert('Пользователь обновлён');
  } catch (err) {
    alert(err.message);
  }
});

document.getElementById('editUserRole')?.addEventListener('change', (e) => {
  document.getElementById('editUserRegionId').disabled = e.target.value === 'admin';
});

document.getElementById('userRole').addEventListener('change', (e) => {
  document.getElementById('userRegionId').disabled = e.target.value === 'admin';
});

document.getElementById('logoutBtn').addEventListener('click', async () => {
  try {
    await fetch(`${API}/auth.php`, { method: 'POST', body: new URLSearchParams({ action: 'logout' }) });
  } catch {}
  window.location.href = '/login.html';
});

document.addEventListener('DOMContentLoaded', async () => {
  editRegionModal = new bootstrap.Modal(document.getElementById('editRegionModal'));
  bootstrapRegionModal = new bootstrap.Modal(document.getElementById('bootstrapRegionModal'));
  editUserModal = new bootstrap.Modal(document.getElementById('editUserModal'));
  auditDetailModal = new bootstrap.Modal(document.getElementById('auditDetailModal'));
  const user = await ensureAdmin();
  if (!user) return;
  const welcome = document.getElementById('adminWelcome');
  if (welcome) welcome.textContent = `${user.full_name || user.username} · управление регионами и пользователями`;
  await loadRegionsWithStats();
  await loadUsers();
});
