const API = '/api';
const t = (key, vars) => AdminI18n.t(key, vars);

let regions = [];
let users = [];
let currentUser = null;
let activeRegionId = null;
let editRegionModal;
let bootstrapRegionModal;
let editUserModal;
let auditDetailModal;

let auditPage = 1;
let auditItems = [];
let auditSecurityOnly = false;
let auditRegionId = '';
let auditSearch = '';
const auditLimit = 50;

let usersPage = 1;
let usersSearch = '';
let usersRole = '';
let usersStatus = '';
const usersLimit = 25;
let usersTotal = 0;
let usersPages = 1;

let adminStats = null;
let searchDebounce = null;

/* ── Utilities ── */

function showToast(message, type = 'info') {
  const container = document.querySelector('.toast-container');
  if (!container) return;
  const bg = { success: 'success', error: 'danger', warning: 'warning', info: 'primary' }[type] || 'primary';
  const toast = document.createElement('div');
  toast.className = `toast align-items-center text-white bg-${bg} border-0`;
  toast.setAttribute('role', 'alert');
  toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${escapeHtml(message)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
  container.appendChild(toast);
  const bsToast = new bootstrap.Toast(toast, { delay: 3500 });
  bsToast.show();
  toast.addEventListener('hidden.bs.toast', () => toast.remove());
}

function showSuccess(msg) { showToast(msg, 'success'); }
function showError(msg) { showToast(msg, 'error'); }

function setLoading(on) {
  document.getElementById('adminLoading')?.classList.toggle('show', !!on);
}

async function apiFetch(url, options = {}) {
  const resp = await fetch(url, options);
  const contentType = resp.headers.get('content-type') || '';
  if (contentType.includes('application/json')) {
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || `HTTP ${resp.status}`);
    return data;
  }
  if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
  return resp;
}

function debounce(fn, ms = 350) {
  return (...args) => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => fn(...args), ms);
  };
}

function parseRegionSettings(raw) {
  if (typeof raw === 'string') {
    try { return JSON.parse(raw); } catch { return {}; }
  }
  return raw || {};
}

function formatDate(value) {
  if (!value) return '—';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  const loc = AdminI18n.getLang() === 'kk' ? 'kk-KZ' : 'ru-RU';
  return d.toLocaleString(loc);
}

function userInitials(name) {
  if (!name) return '?';
  return name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
}

/* ── Auth ── */

async function ensureAdmin() {
  const data = await apiFetch(`${API}/auth.php`);
  if (!data.authenticated || !data.user?.is_admin) {
    window.location.href = '/login.html';
    return null;
  }
  regions = data.regions || [];
  currentUser = data.user;
  activeRegionId = data.user.active_region_id || data.user.region_id || regions[0]?.id;
  return data.user;
}

/* ── Navigation ── */

const TAB_IDS = ['dashboard', 'regions', 'users', 'audit', 'system'];

function showTab(name) {
  TAB_IDS.forEach((tab) => {
    document.getElementById(`tab-${tab}`)?.classList.toggle('d-none', tab !== name);
  });
  document.querySelectorAll('.admin-nav-item').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.tab === name);
  });
  document.getElementById('adminSidebar')?.classList.remove('open');

  if (name === 'users') loadUsers(1);
  if (name === 'audit') loadAuditLogs(1);
  if (name === 'system') loadSystemTab();
  if (name === 'dashboard') loadDashboard();
}

/* ── Header ── */

function populateHeaderRegionSelect() {
  const select = document.getElementById('headerRegionSelect');
  if (!select) return;
  select.innerHTML = regions
    .filter((r) => r.is_active == 1 || r.is_active === true)
    .map((r) => `<option value="${r.id}">${escapeHtml(AdminI18n.regionName(r))}</option>`)
    .join('');
  if (activeRegionId) select.value = String(activeRegionId);
}

async function switchHeaderRegion(regionId) {
  await apiFetch(`${API}/auth.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'switch_region', region_id: String(regionId) }),
  });
  activeRegionId = regionId;
  showSuccess(t('header.activeRegion') + ': ' + AdminI18n.regionName(regions.find((r) => Number(r.id) === regionId)));
}

/* ── Dashboard ── */

async function loadDashboard() {
  try {
    const data = await apiFetch(`${API}/admin_stats.php`);
    adminStats = data;
    renderDashboardKpi(data.stats || {});
    renderDashboardRegions();
  } catch (err) {
    showError(err.message);
  }
}

function renderDashboardKpi(stats) {
  const grid = document.getElementById('dashboardKpi');
  if (!grid) return;
  const eq = stats.email_queue || {};
  grid.innerHTML = `
    <div class="kpi-card">
      <div class="kpi-label">${t('dashboard.regions')}</div>
      <div class="kpi-value">${stats.regions?.active ?? 0}</div>
      <div class="kpi-sub">${stats.regions?.inactive ?? 0} ${t('common.inactive').toLowerCase()}</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">${t('dashboard.users')}</div>
      <div class="kpi-value">${stats.users?.active ?? 0}</div>
      <div class="kpi-sub">${stats.users?.total ?? 0} ${t('common.all').toLowerCase()}</div>
    </div>
    <div class="kpi-card kpi-success">
      <div class="kpi-label">${t('dashboard.members')}</div>
      <div class="kpi-value">${stats.members ?? 0}</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">${t('dashboard.letters')}</div>
      <div class="kpi-value">${(stats.letters?.incoming ?? 0) + (stats.letters?.outgoing ?? 0)}</div>
      <div class="kpi-sub">${t('region.incoming')}: ${stats.letters?.incoming ?? 0} · ${t('region.outgoing')}: ${stats.letters?.outgoing ?? 0}</div>
    </div>
    <div class="kpi-card kpi-warning">
      <div class="kpi-label">${t('dashboard.audit24h')}</div>
      <div class="kpi-value">${stats.audit?.last_24h ?? 0}</div>
    </div>
    <div class="kpi-card kpi-danger">
      <div class="kpi-label">${t('dashboard.securityEvents')}</div>
      <div class="kpi-value">${stats.audit?.security_events ?? 0}</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">${t('dashboard.emailQueue')}</div>
      <div class="kpi-value">${eq.pending ?? 0}</div>
      <div class="kpi-sub">${t('system.sent')}: ${eq.sent ?? 0} · ${t('system.failed')}: ${eq.failed ?? 0}</div>
    </div>`;
}

function renderDashboardRegions() {
  const container = document.getElementById('dashboardRegions');
  if (!container) return;
  if (!regions.length) {
    container.innerHTML = `<p class="text-muted mb-0">${t('common.noData')}</p>`;
    return;
  }
  container.innerHTML = `<div class="row g-3">${regions.map((r) => {
    const s = r.stats || {};
    const name = AdminI18n.regionName(r);
    return `
      <div class="col-md-6 col-lg-4">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-2">${escapeHtml(name)}</div>
          <div class="small text-muted d-flex justify-content-between"><span>${t('region.members')}</span><strong>${s.members_count ?? 0}</strong></div>
          <div class="small text-muted d-flex justify-content-between"><span>${t('region.commissions')}</span><strong>${s.commissions_count ?? 0}</strong></div>
          <div class="small text-muted d-flex justify-content-between"><span>${t('region.incoming')}</span><strong>${s.incoming_letters_count ?? 0}</strong></div>
          <div class="small text-muted d-flex justify-content-between"><span>${t('region.outgoing')}</span><strong>${s.outgoing_letters_count ?? 0}</strong></div>
        </div>
      </div>`;
  }).join('')}</div>`;
}

/* ── Regions ── */

async function loadRegionsWithStats() {
  setLoading(true);
  try {
    const list = await apiFetch(`${API}/regions.php`);
    const detailed = await Promise.all(
      list.map(async (r) => {
        try { return await apiFetch(`${API}/regions.php?id=${r.id}`); }
        catch { return r; }
      })
    );
    regions = detailed;
    renderRegionsGrid();
    renderDashboardRegions();
    populateRegionSelects();
    populateAuditRegionFilter();
    populateHeaderRegionSelect();
  } finally {
    setLoading(false);
  }
}

function renderRegionsGrid() {
  const grid = document.getElementById('regionsGrid');
  if (!grid) return;
  if (!regions.length) {
    grid.innerHTML = `<div class="col-12 text-muted">${t('common.noData')}</div>`;
    return;
  }
  grid.innerHTML = regions.map((r) => {
    const stats = r.stats || {};
    const active = r.is_active == 1 || r.is_active === true;
    const name = AdminI18n.regionName(r);
    return `
      <div class="col-md-6 col-lg-4">
        <div class="card region-card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h5 class="card-title mb-0">${escapeHtml(name)}</h5>
              <span class="badge ${active ? 'bg-success' : 'bg-secondary'}">${active ? t('common.active') : t('common.inactive')}</span>
            </div>
            <div class="small text-muted mb-2"><code>${escapeHtml(r.code)}</code></div>
            <div class="small text-muted">
              ${t('region.members')}: ${stats.members_count ?? '—'} · ${t('region.commissions')}: ${stats.commissions_count ?? '—'}<br>
              ${t('region.incoming')}: ${stats.incoming_letters_count ?? '—'} · ${t('region.outgoing')}: ${stats.outgoing_letters_count ?? '—'}
            </div>
          </div>
          <div class="card-footer bg-white d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-primary" data-action="edit-region" data-id="${r.id}">${t('common.edit')}</button>
            <button class="btn btn-sm btn-outline-success" data-action="open-region" data-id="${r.id}">${t('region.openJournal')}</button>
            <button class="btn btn-sm btn-outline-warning" data-action="bootstrap-region" data-id="${r.id}">${t('region.init')}</button>
            <a class="btn btn-sm btn-outline-secondary" href="${API}/region_export.php?region_id=${r.id}&download=1">${t('region.export')}</a>
            ${active
              ? `<button class="btn btn-sm btn-outline-secondary" data-action="deactivate-region" data-id="${r.id}">${t('region.deactivate')}</button>`
              : `<button class="btn btn-sm btn-outline-secondary" data-action="activate-region" data-id="${r.id}">${t('region.activate')}</button>`}
          </div>
        </div>
      </div>`;
  }).join('');

  bindRegionActions(grid);
}

function bindRegionActions(container) {
  container.querySelectorAll('[data-action="edit-region"]').forEach((btn) => {
    btn.addEventListener('click', () => openEditRegion(Number(btn.dataset.id)));
  });
  container.querySelectorAll('[data-action="open-region"]').forEach((btn) => {
    btn.addEventListener('click', () => switchToRegion(Number(btn.dataset.id)));
  });
  container.querySelectorAll('[data-action="activate-region"]').forEach((btn) => {
    btn.addEventListener('click', () => activateRegion(Number(btn.dataset.id)));
  });
  container.querySelectorAll('[data-action="deactivate-region"]').forEach((btn) => {
    btn.addEventListener('click', () => deactivateRegion(Number(btn.dataset.id)));
  });
  container.querySelectorAll('[data-action="bootstrap-region"]').forEach((btn) => {
    btn.addEventListener('click', () => openBootstrapRegion(Number(btn.dataset.id)));
  });
}

function populateRegionSelects() {
  const options = `<option value="">${t('user.noRegion')}</option>` +
    regions.filter((r) => r.is_active == 1 || r.is_active === true)
      .map((r) => `<option value="${r.id}">${escapeHtml(AdminI18n.regionName(r))}</option>`).join('');
  ['userRegionId', 'editUserRegionId'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.innerHTML = options;
  });
  const tpl = document.getElementById('bootstrapTemplateRegion');
  if (tpl) {
    tpl.innerHTML = regions.map((r) => `<option value="${r.id}">${escapeHtml(AdminI18n.regionName(r))}</option>`).join('');
  }
}

function populateAuditRegionFilter() {
  const select = document.getElementById('auditRegionFilter');
  if (!select) return;
  const prev = select.value;
  select.innerHTML = `<option value="">${t('audit.allRegions')}</option>` +
    regions.map((r) => `<option value="${r.id}">${escapeHtml(AdminI18n.regionName(r))}</option>`).join('');
  if (prev) select.value = prev;
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

function openBootstrapRegion(id) {
  const r = regions.find((x) => Number(x.id) === id);
  if (!r) return;
  document.getElementById('bootstrapRegionId').value = r.id;
  document.getElementById('bootstrapRegionName').textContent = AdminI18n.regionName(r);
  const settings = parseRegionSettings(r.settings);
  document.getElementById('bootstrapSeqIncoming').value = settings.seq_baseline_incoming ?? 0;
  document.getElementById('bootstrapSeqOutgoing').value = settings.seq_baseline_outgoing ?? 0;
  document.getElementById('bootstrapCopyCommissions').checked = true;
  const tpl = document.getElementById('bootstrapTemplateRegion');
  if (tpl) {
    const defaultTpl = regions.find((x) => Number(x.id) === 1)
      ? '1' : String(regions.find((x) => Number(x.id) !== id)?.id || regions[0]?.id || 1);
    tpl.value = defaultTpl;
  }
  bootstrapRegionModal.show();
}

async function switchToRegion(regionId) {
  await switchHeaderRegion(regionId);
  window.location.href = '/api/';
}

async function activateRegion(id) {
  const r = regions.find((x) => Number(x.id) === id);
  if (!r) return;
  try {
    await apiFetch(`${API}/regions.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...r, id, is_active: true }),
    });
    showSuccess(t('region.updated'));
    await loadRegionsWithStats();
  } catch (err) {
    showError(err.message);
  }
}

async function deactivateRegion(id) {
  const r = regions.find((x) => Number(x.id) === id);
  if (!r || !confirm(`${t('region.deactivateConfirm')} «${AdminI18n.regionName(r)}»?`)) return;
  try {
    await apiFetch(`${API}/regions.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...r, id, is_active: false }),
    });
    showSuccess(t('region.updated'));
    await loadRegionsWithStats();
  } catch (err) {
    showError(err.message);
  }
}

/* ── Users ── */

async function loadUsers(page = usersPage) {
  usersPage = page;
  const params = new URLSearchParams({ page: String(page), limit: String(usersLimit) });
  if (usersSearch) params.set('search', usersSearch);
  if (usersRole) params.set('role', usersRole);
  if (usersStatus) params.set('status', usersStatus);
  try {
    const data = await apiFetch(`${API}/users.php?${params}`);
    users = data.items || [];
    usersTotal = data.pagination?.total ?? users.length;
    usersPages = data.pagination?.pages ?? 1;
    renderUsersTable();
  } catch (err) {
    showError(err.message);
  }
}

function renderUsersTable() {
  const tbody = document.querySelector('#usersTable tbody');
  if (!tbody) return;
  tbody.innerHTML = users.length ? users.map((u) => {
    const active = u.is_active == 1 || u.is_active === true;
    return `
      <tr>
        <td>${escapeHtml(u.full_name)}</td>
        <td>${escapeHtml(u.username)}</td>
        <td><span class="badge bg-light text-dark border">${escapeHtml(AdminI18n.roleLabel(u.role))}</span></td>
        <td>${escapeHtml(u.region_name || '—')}</td>
        <td class="small text-muted">${formatDate(u.last_login)}</td>
        <td>${active ? t('common.yes') : t('common.no')}</td>
        <td class="text-end">
          <div class="d-flex gap-1 justify-content-end flex-wrap">
            <button class="btn btn-sm btn-outline-primary" data-edit-user="${u.id}">${t('common.edit')}</button>
            ${active
              ? `<button class="btn btn-sm btn-outline-danger" data-deactivate="${u.id}">${t('user.deactivate')}</button>`
              : `<button class="btn btn-sm btn-outline-success" data-reactivate="${u.id}">${t('user.reactivate')}</button>`}
          </div>
        </td>
      </tr>`;
  }).join('') : `<tr><td colspan="7" class="text-center text-muted py-4">${t('common.noData')}</td></tr>`;

  tbody.querySelectorAll('[data-deactivate]').forEach((btn) => {
    btn.addEventListener('click', () => deactivateUser(Number(btn.dataset.deactivate)));
  });
  tbody.querySelectorAll('[data-reactivate]').forEach((btn) => {
    btn.addEventListener('click', () => reactivateUser(Number(btn.dataset.reactivate)));
  });
  tbody.querySelectorAll('[data-edit-user]').forEach((btn) => {
    btn.addEventListener('click', () => openEditUser(Number(btn.dataset.editUser)));
  });

  document.getElementById('usersPaginationInfo').textContent =
    t('audit.pageInfo', { page: usersPage, pages: usersPages, total: usersTotal });
  document.getElementById('usersPrevBtn').disabled = usersPage <= 1;
  document.getElementById('usersNextBtn').disabled = usersPage >= usersPages;
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

async function deactivateUser(id) {
  if (!confirm(t('user.deactivateConfirm'))) return;
  try {
    await apiFetch(`${API}/users.php?id=${id}`, { method: 'DELETE' });
    showSuccess(t('user.deactivated'));
    await loadUsers(usersPage);
  } catch (err) {
    showError(err.message);
  }
}

async function reactivateUser(id) {
  try {
    await apiFetch(`${API}/users.php`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, is_active: true }),
    });
    showSuccess(t('user.reactivated'));
    await loadUsers(usersPage);
  } catch (err) {
    showError(err.message);
  }
}

/* ── Audit ── */

async function loadAuditLogs(page = auditPage) {
  auditPage = page;
  const params = new URLSearchParams({ page: String(page), limit: String(auditLimit) });
  if (auditSecurityOnly) params.set('security', '1');
  if (auditRegionId) params.set('region_id', auditRegionId);
  if (auditSearch) params.set('search', auditSearch);
  const tbody = document.querySelector('#auditTable tbody');
  try {
    const data = await apiFetch(`${API}/audit_logs.php?${params}`);
    auditItems = data.items || [];
    tbody.innerHTML = auditItems.length ? auditItems.map((row, idx) => {
      const op = row.operation || '';
      const isSecurity = op === 'EXPORT' || op === 'DOWNLOAD';
      const badgeClass = isSecurity ? 'bg-warning text-dark' : 'bg-light text-dark border';
      return `
        <tr class="audit-row${isSecurity ? ' table-warning' : ''}" data-audit-idx="${idx}" style="cursor:pointer;">
          <td class="text-nowrap small">${escapeHtml(formatDate(row.created_at))}</td>
          <td>${escapeHtml(row.user_name || row.user_login || '—')}</td>
          <td><code>${escapeHtml(row.table_name || '')}</code></td>
          <td><span class="badge ${badgeClass}">${escapeHtml(op)}</span></td>
          <td>${row.record_id ?? '—'}</td>
          <td class="small text-muted">${escapeHtml(row.ip_address || '')}</td>
        </tr>`;
    }).join('') : `<tr><td colspan="6" class="text-center text-muted py-4">${t('common.noData')}</td></tr>`;

    tbody.querySelectorAll('.audit-row').forEach((row) => {
      row.addEventListener('click', () => showAuditDetail(Number(row.dataset.auditIdx)));
    });

    const pagination = data.pagination || {};
    document.getElementById('auditPaginationInfo').textContent =
      t('audit.pageInfo', { page: auditPage, pages: pagination.pages ?? 1, total: pagination.total ?? 0 });
    document.getElementById('auditPrevBtn').disabled = auditPage <= 1;
    document.getElementById('auditNextBtn').disabled = auditPage >= (pagination.pages ?? 1);
  } catch (err) {
    if (tbody) tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">${escapeHtml(err.message)}</td></tr>`;
  }
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
  document.getElementById('auditDetailBody').innerHTML = `
    <dl class="row small mb-3">
      <dt class="col-sm-3">${t('audit.date')}</dt><dd class="col-sm-9">${escapeHtml(formatDate(row.created_at))}</dd>
      <dt class="col-sm-3">${t('audit.user')}</dt><dd class="col-sm-9">${escapeHtml(row.user_name || row.user_login || '—')}</dd>
      <dt class="col-sm-3">${t('audit.table')}</dt><dd class="col-sm-9"><code>${escapeHtml(row.table_name || '')}</code></dd>
      <dt class="col-sm-3">${t('audit.operation')}</dt><dd class="col-sm-9">${escapeHtml(row.operation || '')}</dd>
      <dt class="col-sm-3">${t('audit.recordId')}</dt><dd class="col-sm-9">${row.record_id ?? '—'}</dd>
      <dt class="col-sm-3">${t('audit.ip')}</dt><dd class="col-sm-9">${escapeHtml(row.ip_address || '')}</dd>
    </dl>
    <div class="mb-2 fw-semibold">${t('audit.was')}</div>
    <pre class="audit-json-block">${escapeHtml(formatAuditJson(row.old_values))}</pre>
    <div class="mb-2 mt-3 fw-semibold">${t('audit.became')}</div>
    <pre class="audit-json-block">${escapeHtml(formatAuditJson(row.new_values))}</pre>`;
  auditDetailModal.show();
}

function exportAuditCsv() {
  const params = new URLSearchParams({ format: 'csv', limit: '2000' });
  if (auditSecurityOnly) params.set('security', '1');
  if (auditRegionId) params.set('region_id', auditRegionId);
  if (auditSearch) params.set('search', auditSearch);
  window.location.href = `${API}/audit_logs.php?${params}`;
}

/* ── System ── */

async function loadSystemTab() {
  await Promise.all([loadHealthStatus(), loadEmailQueue()]);
}

async function loadHealthStatus() {
  const el = document.getElementById('healthStatus');
  if (!el) return;
  try {
    const data = await apiFetch(`${API}/health.php`);
    const ok = data.status === 'ok';
    const checks = data.checks || {};
    el.innerHTML = `
      <div class="mb-3"><span class="health-badge ${ok ? 'ok' : 'degraded'}">
        <i class="bi bi-${ok ? 'check-circle' : 'exclamation-triangle'}"></i>
        ${ok ? t('system.statusOk') : t('system.statusDegraded')}
      </span></div>
      <div class="small">
        <div class="d-flex justify-content-between py-1 border-bottom">
          <span>${t('system.database')}</span>
          <span class="${checks.database ? 'text-success' : 'text-danger'}">${checks.database ? '✓' : '✗'}</span>
        </div>
        <div class="d-flex justify-content-between py-1">
          <span>${t('system.uploads')}</span>
          <span class="${checks.uploads_writable ? 'text-success' : 'text-danger'}">${checks.uploads_writable ? '✓' : '✗'}</span>
        </div>
      </div>
      <div class="small text-muted mt-2">${formatDate(data.timestamp)}</div>`;
  } catch (err) {
    el.innerHTML = `<span class="text-danger">${escapeHtml(err.message)}</span>`;
  }
}

async function loadEmailQueue() {
  const statsEl = document.getElementById('emailQueueStats');
  const tbody = document.querySelector('#emailQueueTable tbody');
  try {
    if (adminStats?.stats?.email_queue) {
      const eq = adminStats.stats.email_queue;
      statsEl.innerHTML = `
        <div class="d-flex justify-content-between py-1"><span>${t('system.pending')}</span><strong>${eq.pending ?? 0}</strong></div>
        <div class="d-flex justify-content-between py-1"><span>${t('system.sent')}</span><strong class="text-success">${eq.sent ?? 0}</strong></div>
        <div class="d-flex justify-content-between py-1"><span>${t('system.failed')}</span><strong class="text-danger">${eq.failed ?? 0}</strong></div>`;
    }
    const data = await apiFetch(`${API}/notifications.php?limit=30`);
    const items = data.items || [];
    tbody.innerHTML = items.length ? items.map((row) => `
      <tr>
        <td>${row.id}</td>
        <td class="small">${escapeHtml(row.recipient_email || '')}</td>
        <td class="small">${escapeHtml(row.subject || '')}</td>
        <td><span class="badge ${row.status === 'sent' ? 'bg-success' : row.status === 'failed' ? 'bg-danger' : 'bg-secondary'}">${escapeHtml(row.status || '')}</span></td>
        <td class="small text-muted">${formatDate(row.created_at)}</td>
        <td class="small text-muted">${formatDate(row.sent_at)}</td>
        <td>${row.status === 'failed' ? `<button class="btn btn-sm btn-outline-warning" data-retry-email="${row.id}">${t('system.retry')}</button>` : ''}</td>
      </tr>`).join('') : `<tr><td colspan="7" class="text-center text-muted py-3">${t('common.noData')}</td></tr>`;

    tbody.querySelectorAll('[data-retry-email]').forEach((btn) => {
      btn.addEventListener('click', () => retryEmail(Number(btn.dataset.retryEmail)));
    });
  } catch (err) {
    if (statsEl) statsEl.innerHTML = `<span class="text-danger">${escapeHtml(err.message)}</span>`;
    if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-3">${escapeHtml(err.message)}</td></tr>`;
  }
}

async function retryEmail(id) {
  try {
    await apiFetch(`${API}/notifications.php`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, action: 'retry' }),
    });
    showSuccess(t('system.retryQueued'));
    await loadEmailQueue();
  } catch (err) {
    showError(err.message);
  }
}

/* ── Event listeners ── */

document.getElementById('adminNav')?.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-tab]');
  if (!btn) return;
  showTab(btn.dataset.tab);
});

document.querySelectorAll('.lang-toggle [data-lang]').forEach((btn) => {
  btn.addEventListener('click', () => {
    AdminI18n.setLang(btn.dataset.lang);
    renderRegionsGrid();
    renderUsersTable();
    renderDashboardKpi(adminStats?.stats || {});
    renderDashboardRegions();
    populateRegionSelects();
    populateAuditRegionFilter();
    populateHeaderRegionSelect();
  });
});

document.getElementById('adminSidebarToggle')?.addEventListener('click', () => {
  document.getElementById('adminSidebar')?.classList.toggle('open');
});

document.getElementById('headerRegionSelect')?.addEventListener('change', async (e) => {
  const regionId = Number(e.target.value);
  if (regionId > 0) {
    try {
      await switchHeaderRegion(regionId);
    } catch (err) {
      showError(err.message);
    }
  }
});

document.getElementById('formRegion')?.addEventListener('submit', async (e) => {
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
    showSuccess(t('region.created'));
    await loadRegionsWithStats();
  } catch (err) {
    showError(err.message);
  }
});

document.getElementById('formUser')?.addEventListener('submit', async (e) => {
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
    showSuccess(t('user.created'));
    await loadUsers(1);
  } catch (err) {
    showError(err.message);
  }
});

document.getElementById('formBootstrapRegion')?.addEventListener('submit', async (e) => {
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
    showSuccess(`${t('region.bootstrapped')}: ${result.commissions_copied ?? 0}`);
    await loadRegionsWithStats();
  } catch (err) {
    showError(err.message);
  }
});

document.getElementById('formEditRegion')?.addEventListener('submit', async (e) => {
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
    showSuccess(t('region.updated'));
    await loadRegionsWithStats();
  } catch (err) {
    showError(err.message);
  }
});

document.getElementById('formEditUser')?.addEventListener('submit', async (e) => {
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
    showSuccess(t('user.updated'));
    await loadUsers(usersPage);
  } catch (err) {
    showError(err.message);
  }
});

document.getElementById('editUserRole')?.addEventListener('change', (e) => {
  document.getElementById('editUserRegionId').disabled = e.target.value === 'admin';
});
document.getElementById('userRole')?.addEventListener('change', (e) => {
  document.getElementById('userRegionId').disabled = e.target.value === 'admin';
});

document.getElementById('auditRefreshBtn')?.addEventListener('click', () => loadAuditLogs(auditPage));
document.getElementById('auditExportBtn')?.addEventListener('click', exportAuditCsv);
document.getElementById('auditSecurityOnly')?.addEventListener('change', (e) => {
  auditSecurityOnly = !!e.target.checked;
  loadAuditLogs(1);
});
document.getElementById('auditRegionFilter')?.addEventListener('change', (e) => {
  auditRegionId = e.target.value || '';
  loadAuditLogs(1);
});
document.getElementById('auditSearch')?.addEventListener('input', debounce((e) => {
  auditSearch = e.target.value.trim();
  loadAuditLogs(1);
}));
document.getElementById('auditPrevBtn')?.addEventListener('click', () => {
  if (auditPage > 1) loadAuditLogs(auditPage - 1);
});
document.getElementById('auditNextBtn')?.addEventListener('click', () => {
  loadAuditLogs(auditPage + 1);
});

document.getElementById('usersRefreshBtn')?.addEventListener('click', () => loadUsers(usersPage));
document.getElementById('usersSearch')?.addEventListener('input', debounce((e) => {
  usersSearch = e.target.value.trim();
  loadUsers(1);
}));
document.getElementById('usersRoleFilter')?.addEventListener('change', (e) => {
  usersRole = e.target.value;
  loadUsers(1);
});
document.getElementById('usersStatusFilter')?.addEventListener('change', (e) => {
  usersStatus = e.target.value;
  loadUsers(1);
});
document.getElementById('usersPrevBtn')?.addEventListener('click', () => {
  if (usersPage > 1) loadUsers(usersPage - 1);
});
document.getElementById('usersNextBtn')?.addEventListener('click', () => {
  loadUsers(usersPage + 1);
});

document.getElementById('healthCheckBtn')?.addEventListener('click', loadHealthStatus);
document.getElementById('emailQueueRefreshBtn')?.addEventListener('click', loadEmailQueue);

document.getElementById('logoutBtn')?.addEventListener('click', async () => {
  try {
    await fetch(`${API}/auth.php`, { method: 'POST', body: new URLSearchParams({ action: 'logout' }) });
  } catch {}
  window.location.href = '/login.html';
});

/* ── Init ── */

document.addEventListener('DOMContentLoaded', async () => {
  editRegionModal = new bootstrap.Modal(document.getElementById('editRegionModal'));
  bootstrapRegionModal = new bootstrap.Modal(document.getElementById('bootstrapRegionModal'));
  editUserModal = new bootstrap.Modal(document.getElementById('editUserModal'));
  auditDetailModal = new bootstrap.Modal(document.getElementById('auditDetailModal'));

  AdminI18n.apply();

  const user = await ensureAdmin();
  if (!user) return;

  document.getElementById('adminUserName').textContent = user.full_name || user.username;
  document.getElementById('adminUserAvatar').textContent = userInitials(user.full_name || user.username);

  await loadRegionsWithStats();
  await loadDashboard();
  showTab('dashboard');
});
