/**
 * Ядро SPA: состояние, права, API-хелперы, confirmDelete.
 */
(function (window) {
  const API_BASE = '/api';

  const store = { incoming: [], outgoing: [] };
  let sessionUser = null;
  let membersCatalog = [];
  let commissionsCatalog = [];

  let confirmDeleteModalInstance = null;
  let confirmDeleteHandler = null;

  function canWrite() {
    return !!(sessionUser?.can_write);
  }

  function canDelete() {
    return !!(sessionUser?.can_delete);
  }

  function canExport() {
    return !!(sessionUser?.can_export);
  }

  function applyPermissionsUI(user) {
    sessionUser = user || sessionUser;
    const show = canWrite();
    const exportAllowed = canExport();
    document.getElementById('memberFormCard')?.classList.toggle('d-none', !show);
    document.getElementById('commissionFormCard')?.classList.toggle('d-none', !show);
    document.querySelectorAll('.export-admin-only').forEach((el) => {
      el.classList.toggle('d-none', !exportAllowed);
    });
  }

  async function uploadMemberPhoto(memberId, file) {
    if (!canWrite() || !file) return;
    const formData = new FormData();
    formData.append('member_id', String(memberId));
    formData.append('photo', file);
    const resp = await fetch(`${API_BASE}/upload_photo.php`, { method: 'POST', body: formData });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || data.message || 'Не удалось загрузить фото');
    const photoUrl = data.data?.photo_url || data.photo_url;
    if (photoUrl) {
      const member = membersCatalog.find((m) => String(m.id) === String(memberId));
      if (member) {
        member.photo_url = photoUrl;
        member.photo_path = photoUrl.replace(/^\//, '');
      }
    }
    return photoUrl;
  }

  async function saveMember(payload, isEdit) {
    const resp = await fetch(`${API_BASE}/members.php`, {
      method: isEdit ? 'PUT' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || 'Ошибка сохранения');
    return data;
  }

  async function saveCommission(payload, isEdit) {
    const resp = await fetch(`${API_BASE}/commissions.php`, {
      method: isEdit ? 'PUT' : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || 'Ошибка сохранения');
    return data;
  }

  async function deleteMember(id) {
    const resp = await fetch(`${API_BASE}/members.php?id=${id}`, { method: 'DELETE' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || 'Не удалось удалить');
  }

  async function deleteCommission(id) {
    const resp = await fetch(`${API_BASE}/commissions.php?id=${id}`, { method: 'DELETE' });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(data.error || 'Не удалось удалить');
  }

  function goToIncomingStatus(status) {
    const els = {
      status: document.getElementById('filterStatusIncoming'),
      year: document.getElementById('filterYearIncoming'),
      month: document.getElementById('filterMonthIncoming'),
      scans: document.getElementById('filterScansIncoming'),
      recipient: document.getElementById('filterRecipientIncoming'),
      search: document.getElementById('searchIncoming'),
    };
    if (els.status) els.status.value = status;
    if (els.year) els.year.value = 'Все годы';
    if (els.month) els.month.value = 'all';
    if (els.scans) els.scans.value = 'all';
    if (els.recipient) els.recipient.value = 'all';
    if (els.search) els.search.value = '';
    document.getElementById('tab-incoming')?.click();
    if (typeof window.renderIncoming === 'function') window.renderIncoming();
  }

  function confirmDelete(message, onConfirm) {
    const modalEl = document.getElementById('confirmDeleteModal');
    const msgEl = document.getElementById('confirmDeleteMessage');
    const btnEl = document.getElementById('confirmDeleteBtn');
    if (!modalEl || !msgEl || !btnEl) {
      if (window.confirm(message)) onConfirm();
      return;
    }
    msgEl.textContent = message || 'Вы уверены, что хотите удалить этот элемент?';
    confirmDeleteHandler = onConfirm;
    if (!confirmDeleteModalInstance) {
      confirmDeleteModalInstance = new bootstrap.Modal(modalEl);
    }
    confirmDeleteModalInstance.show();
  }

  document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('confirmDeleteBtn');
    const modal = document.getElementById('confirmDeleteModal');
    btn?.addEventListener('click', async () => {
      const handler = confirmDeleteHandler;
      confirmDeleteHandler = null;
      if (confirmDeleteModalInstance) confirmDeleteModalInstance.hide();
      if (handler) await handler();
    });
    modal?.addEventListener('hidden.bs.modal', () => {
      confirmDeleteHandler = null;
    });
  });

  const core = {
    API_BASE,
    store,
    get sessionUser() { return sessionUser; },
    set sessionUser(v) { sessionUser = v; },
    get membersCatalog() { return membersCatalog; },
    set membersCatalog(v) { membersCatalog = v; },
    get commissionsCatalog() { return commissionsCatalog; },
    set commissionsCatalog(v) { commissionsCatalog = v; },
    canWrite,
    canDelete,
    canExport,
    applyPermissionsUI,
    uploadMemberPhoto,
    saveMember,
    saveCommission,
    deleteMember,
    deleteCommission,
    goToIncomingStatus,
    confirmDelete,
  };

  window.AppCore = core;
  window.API_BASE = API_BASE;
  window.store = store;
  window.canWrite = canWrite;
  window.canDelete = canDelete;
  window.canExport = canExport;
  window.applyPermissionsUI = applyPermissionsUI;
  window.goToIncomingStatus = goToIncomingStatus;
  window.confirmDelete = confirmDelete;
  window.uploadMemberPhoto = uploadMemberPhoto;
  window.saveMember = saveMember;
  window.saveCommission = saveCommission;
  window.deleteMember = deleteMember;
  window.deleteCommission = deleteCommission;
  window.enableKpiCounterAnimation = true;

  Object.defineProperty(window, 'sessionUser', {
    get() { return sessionUser; },
    set(v) { sessionUser = v; },
    configurable: true,
  });
  Object.defineProperty(window, 'membersCatalog', {
    get() { return membersCatalog; },
    set(v) { membersCatalog = v; },
    configurable: true,
  });
  Object.defineProperty(window, 'commissionsCatalog', {
    get() { return commissionsCatalog; },
    set(v) { commissionsCatalog = v; },
    configurable: true,
  });
})(window);
