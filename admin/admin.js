const API = '/api';

let regions = [];
let users = [];
let editRegionModal;

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

function populateRegionSelects() {
  const select = document.getElementById('userRegionId');
  if (!select) return;
  select.innerHTML = '<option value="">— без региона (админ) —</option>' +
    regions.filter((r) => r.is_active == 1 || r.is_active === true)
      .map((r) => `<option value="${r.id}">${escapeHtml(r.name_ru)}</option>`).join('');
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
      <td class="text-end">
        ${u.is_active == 1 || u.is_active === true
          ? `<button class="btn btn-sm btn-outline-danger" data-deactivate="${u.id}">Деактивировать</button>`
          : ''}
      </td>
    </tr>`).join('');

  tbody.querySelectorAll('[data-deactivate]').forEach((btn) => {
    btn.addEventListener('click', () => deactivateUser(Number(btn.dataset.deactivate)));
  });
}

function openEditRegion(id) {
  const r = regions.find((x) => Number(x.id) === id);
  if (!r) return;
  document.getElementById('editRegionId').value = r.id;
  document.getElementById('editRegionNameRu').value = r.name_ru || '';
  document.getElementById('editRegionNameKz').value = r.name_kz || '';
  document.getElementById('editRegionCode').value = r.code || '';
  document.getElementById('editRegionActive').checked = !!(r.is_active == 1 || r.is_active === true);
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

document.getElementById('formEditRegion').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = Number(document.getElementById('editRegionId').value);
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
      }),
    });
    editRegionModal.hide();
    await loadRegionsWithStats();
  } catch (err) {
    alert(err.message);
  }
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
  const user = await ensureAdmin();
  if (!user) return;
  await loadRegionsWithStats();
});
