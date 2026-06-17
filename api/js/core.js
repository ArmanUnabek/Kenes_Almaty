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

  function syncUserSession(user) {
    sessionUser = user || null;
    if (user) {
      window.sessionUser = user;
      window.AppUtils?.syncUserToStorage?.(user);
    }
  }

  function getSessionUser() {
    return sessionUser;
  }

  function applyPermissionsUI(user) {
    syncUserSession(user || sessionUser);
    const showWrite = canWrite();
    const exportAllowed = canExport();

    document.getElementById('memberFormCard')?.classList.toggle('d-none', !showWrite);
    document.getElementById('commissionFormCard')?.classList.toggle('d-none', !showWrite);

    document.querySelectorAll('.export-admin-only').forEach((el) => {
      el.classList.toggle('d-none', !exportAllowed);
    });

    document.querySelectorAll('.write-admin-only').forEach((el) => {
      el.classList.toggle('d-none', !showWrite);
    });

    document.querySelectorAll('.write-disabled').forEach((el) => {
      if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement) {
        el.disabled = !showWrite;
      } else if (el instanceof HTMLButtonElement) {
        el.disabled = !showWrite;
      }
    });

    const ctx = document.getElementById('pageContextActions');
    if (ctx && !showWrite) {
      ctx.querySelectorAll('button').forEach((btn) => {
        btn.disabled = true;
        btn.classList.add('disabled');
      });
    }
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
    const tab = document.getElementById('tab-incoming');
    if (tab) tab.click();
    const filter = document.getElementById('filterStatusIncoming');
    if (filter) {
      filter.value = status;
      filter.dispatchEvent(new Event('change'));
    }
  }

  function confirmDelete(message, handler) {
    const modal = document.getElementById('confirmDeleteModal');
    const msgEl = document.getElementById('confirmDeleteMessage');
    if (!modal || !msgEl) {
      if (window.confirm(message)) handler();
      return;
    }
    msgEl.textContent = message;
    confirmDeleteHandler = handler;
    if (!confirmDeleteModalInstance) {
      confirmDeleteModalInstance = new bootstrap.Modal(modal);
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
    set sessionUser(v) { syncUserSession(v); },
    get membersCatalog() { return membersCatalog; },
    set membersCatalog(v) { membersCatalog = v; },
    get commissionsCatalog() { return commissionsCatalog; },
    set commissionsCatalog(v) { commissionsCatalog = v; },
    canWrite,
    canDelete,
    canExport,
    syncUserSession,
    getSessionUser,
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
  window.syncUserSession = syncUserSession;
  window.getSessionUser = getSessionUser;
  window.applyPermissionsUI = applyPermissionsUI;
  window.goToIncomingStatus = goToIncomingStatus;
  window.confirmDelete = confirmDelete;
  window.uploadMemberPhoto = uploadMemberPhoto;
  window.saveMember = saveMember;
  window.saveCommission = saveCommission;
  window.deleteMember = deleteMember;
  window.deleteCommission = deleteCommission;
})(window);
