/**
 * UI членов ОС и комиссий.
 */
(function (window) {
  const api = () => window.AppCore?.API_BASE || '/api';

  function t(key, fallback) {
    return window.AppI18n?.t(key) || fallback;
  }

  function resetMemberForm() {
    const form = document.getElementById('formMember');
    if (!form) return;
    form.reset();
    document.getElementById('memberEditId').value = '';
    document.getElementById('memberFormTitle').textContent = t('members.add', 'Добавить члена ОС');
    document.getElementById('memberStatus').value = 'active';
  }

  function resetCommissionForm() {
    const form = document.getElementById('formCommission');
    if (!form) return;
    form.reset();
    document.getElementById('commissionEditId').value = '';
    document.getElementById('commissionFormTitle').textContent = t('commissions.add', 'Добавить комиссию');
    document.getElementById('commissionColor').value = '#0d6efd';
    document.getElementById('commissionSortOrder').value = '0';
  }

  function populateMemberCommissionSelect() {
    const select = document.getElementById('memberCommissionId');
    if (!select) return;
    const current = select.value;
    const catalog = window.commissionsCatalog || [];
    select.innerHTML = `<option value="">${t('members.not_assigned', '— не назначена —')}</option>` +
      catalog.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
    if (current) select.value = current;
  }

  function editMember(member) {
    if (!window.canWrite()) return;
    document.getElementById('memberEditId').value = member.id;
    document.getElementById('memberFullName').value = member.full_name || '';
    document.getElementById('memberCommissionId').value = member.commission_id || '';
    document.getElementById('memberPosition').value = member.position || '';
    document.getElementById('memberOrganization').value = member.organization || '';
    document.getElementById('memberPhone').value = member.phone || '';
    document.getElementById('memberEmail').value = member.email || '';
    document.getElementById('memberStatus').value = member.status || 'active';
    document.getElementById('memberFormTitle').textContent = t('members.edit', 'Редактировать члена ОС');
    const collapse = document.getElementById('collapseMemberForm');
    if (collapse && !collapse.classList.contains('show')) {
      new bootstrap.Collapse(collapse, { toggle: true });
    }
  }

  function editCommission(commission) {
    if (!window.canWrite()) return;
    document.getElementById('commissionEditId').value = commission.id;
    document.getElementById('commissionName').value = commission.name || '';
    document.getElementById('commissionSortOrder').value = commission.sort_order || 0;
    document.getElementById('commissionColor').value = commission.color || '#0d6efd';
    document.getElementById('commissionFormTitle').textContent = t('commissions.edit', 'Редактировать комиссию');
    const collapse = document.getElementById('collapseCommissionForm');
    if (collapse && !collapse.classList.contains('show')) {
      new bootstrap.Collapse(collapse, { toggle: true });
    }
  }

  async function loadMembersCatalog() {
    try {
      const response = await fetch(`${api()}/members.php?limit=500`);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      window.membersCatalog = await response.json();
    } catch (error) {
      console.error('Ошибка загрузки списка членов ОС', error);
      window.membersCatalog = [];
    }
  }

  async function loadCommissionsCatalog() {
    try {
      const response = await fetch(`${api()}/commissions.php`);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      window.commissionsCatalog = await response.json();
    } catch (error) {
      console.error('Ошибка загрузки комиссий', error);
      window.commissionsCatalog = [];
    }
  }

  function getInitials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return String(name || '?').slice(0, 2).toUpperCase();
  }

  function populateMembersCommissionFilter() {
    const select = document.getElementById('filterMembersCommission');
    if (!select) return;
    const prev = select.value;
    const seen = new Map();
    (window.membersCatalog || []).forEach((member) => {
      if (member.commission_id && member.commission_name && !seen.has(String(member.commission_id))) {
        seen.set(String(member.commission_id), member.commission_name);
      }
    });
    select.innerHTML = `<option value="">${t('members.all_commissions', 'Все комиссии')}</option>` +
      [...seen.entries()]
        .sort((a, b) => a[1].localeCompare(b[1], 'ru'))
        .map(([id, name]) => `<option value="${id}">${escapeHtml(name)}</option>`)
        .join('');
    if (prev && seen.has(prev)) select.value = prev;
  }

  function renderMembersGrid() {
    const grid = document.getElementById('membersGrid');
    const filter = document.getElementById('filterMembersCommission');
    if (!grid) return;

    let list = [...(window.membersCatalog || [])];
    if (filter?.value) {
      list = list.filter((m) => String(m.commission_id) === String(filter.value));
    }

    if (!list.length) {
      grid.innerHTML = `
        <div class="col-12">
          <div class="empty-state">
            <i class="bi bi-people"></i>
            <p class="mb-0">${t('members.empty', 'Нет членов для отображения')}</p>
          </div>
        </div>`;
      return;
    }

    grid.innerHTML = list.map((member) => {
      const color = member.commission_color || '#1D4ED8';
      const commissionBadge = member.commission_name
        ? `<span class="badge commission-badge" style="background:${color}20;color:${color}">${escapeHtml(member.commission_name)}</span>`
        : '';
      const avatarContent = member.photo_url
        ? `<img src="${escapeHtml(member.photo_url)}" alt="" class="member-avatar-img" loading="lazy" referrerpolicy="same-origin">`
        : getInitials(member.full_name);
      return `
        <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
          <div class="member-card card h-100">
            <div class="card-body text-center">
              <div class="member-avatar">${avatarContent}</div>
              <h6 class="member-name mt-3 mb-1">${escapeHtml(member.full_name || '')}</h6>
              <p class="member-role text-muted small mb-2">${escapeHtml(member.position || t('members.default_role', 'Член совета'))}</p>
              ${commissionBadge}
              ${member.organization ? `<p class="small text-muted mt-2 mb-0">${escapeHtml(member.organization)}</p>` : ''}
              ${member.phone ? `<p class="small text-muted mb-0">${escapeHtml(member.phone)}</p>` : ''}
              ${window.canWrite() ? `
                <div class="mt-3 d-flex justify-content-center gap-2 flex-wrap">
                  <button type="button" class="btn btn-sm btn-outline-primary" data-edit-member="${member.id}"><i class="bi bi-pencil"></i> ${t('action.edit', 'Изменить')}</button>
                  <label class="btn btn-sm btn-outline-secondary mb-0">
                    <i class="bi bi-camera"></i> ${t('members.photo', 'Фото')}
                    <input type="file" accept="image/jpeg,image/png" class="d-none" data-photo-upload="${member.id}">
                  </label>
                  ${window.canDelete() ? `<button type="button" class="btn btn-sm btn-outline-danger" data-delete-member="${member.id}"><i class="bi bi-trash"></i></button>` : ''}
                </div>` : ''}
            </div>
          </div>
        </div>`;
    }).join('');

    if (window.canWrite()) {
      grid.querySelectorAll('[data-edit-member]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const member = window.membersCatalog.find((m) => String(m.id) === btn.dataset.editMember);
          if (member) editMember(member);
        });
      });
      grid.querySelectorAll('[data-photo-upload]').forEach((input) => {
        input.addEventListener('change', async () => {
          const file = input.files?.[0];
          if (!file) return;
          try {
            await window.uploadMemberPhoto(input.dataset.photoUpload, file);
            renderMembersGrid();
            window.showSuccess?.(t('members.photo_ok', 'Фото успешно загружено'));
          } catch (err) {
            window.showError?.(err.message) || alert(err.message);
          } finally {
            input.value = '';
          }
        });
      });
      grid.querySelectorAll('[data-delete-member]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const memberId = btn.dataset.deleteMember;
          const member = window.membersCatalog.find((m) => String(m.id) === String(memberId));
          const name = member?.full_name || 'члена ОС';
          window.confirmDelete(`${t('members.delete_confirm', 'Удалить')} «${name}»?`, async () => {
            try {
              await window.deleteMember(memberId);
              window.membersCatalog = window.membersCatalog.filter((m) => String(m.id) !== String(memberId));
              renderMembersGrid();
              renderCommissionsGrid();
              window.showSuccess?.(t('members.deleted', 'Член ОС удалён'));
            } catch (err) {
              window.showError?.(err.message) || alert(err.message);
            }
          });
        });
      });
    }
  }

  function renderCommissionsGrid() {
    const grid = document.getElementById('commissionsGrid');
    if (!grid) return;
    const catalog = window.commissionsCatalog || [];
    if (!catalog.length) {
      grid.innerHTML = `<div class="col-12 text-center text-muted py-5">${t('commissions.empty', 'Комиссии не найдены')}</div>`;
      return;
    }

    const memberCounts = {};
    (window.membersCatalog || []).forEach((member) => {
      if (member.commission_id) {
        memberCounts[member.commission_id] = (memberCounts[member.commission_id] || 0) + 1;
      }
    });

    grid.innerHTML = catalog.map((commission) => {
      const color = commission.color || '#1D4ED8';
      const count = memberCounts[commission.id] || 0;
      return `
        <div class="col-12 col-md-6 col-xl-4">
          <div class="commission-card card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-3">
                <span class="commission-dot" style="background:${color}"></span>
                <span class="badge commission-count">${count}</span>
              </div>
              <h5 class="commission-title">${escapeHtml(commission.name || 'Комиссия')}</h5>
              <p class="text-muted small mb-0">${escapeHtml(commission.description || t('commissions.default_desc', 'Комиссия Общественного Совета'))}</p>
              ${window.canWrite() ? `<div class="mt-3 d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-primary" data-edit-commission="${commission.id}"><i class="bi bi-pencil"></i> ${t('action.edit', 'Изменить')}</button>
                ${window.canDelete() ? `<button type="button" class="btn btn-sm btn-outline-danger" data-delete-commission="${commission.id}"><i class="bi bi-trash"></i></button>` : ''}
              </div>` : ''}
            </div>
          </div>
        </div>`;
    }).join('');

    if (window.canWrite()) {
      grid.querySelectorAll('[data-edit-commission]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const commission = catalog.find((c) => String(c.id) === btn.dataset.editCommission);
          if (commission) editCommission(commission);
        });
      });
      grid.querySelectorAll('[data-delete-commission]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const commissionId = btn.dataset.deleteCommission;
          const commission = catalog.find((c) => String(c.id) === String(commissionId));
          const name = commission?.name || 'комиссию';
          window.confirmDelete(`${t('commissions.delete_confirm', 'Удалить')} «${name}»?`, async () => {
            try {
              await window.deleteCommission(commissionId);
              window.commissionsCatalog = catalog.filter((c) => String(c.id) !== String(commissionId));
              renderCommissionsGrid();
              populateMemberCommissionSelect();
              window.showSuccess?.(t('commissions.deleted', 'Комиссия удалена'));
            } catch (err) {
              window.showError?.(err.message) || alert(err.message);
            }
          });
        });
      });
    }
  }

  function bindMembersCommissionsForms() {
    const formMember = document.getElementById('formMember');
    if (formMember) {
      formMember.addEventListener('submit', async (e) => {
        e.preventDefault();
        const editId = document.getElementById('memberEditId').value;
        const payload = {
          full_name: document.getElementById('memberFullName').value.trim(),
          commission_id: document.getElementById('memberCommissionId').value || null,
          position: document.getElementById('memberPosition').value.trim(),
          organization: document.getElementById('memberOrganization').value.trim(),
          phone: document.getElementById('memberPhone').value.trim(),
          email: document.getElementById('memberEmail').value.trim(),
          status: document.getElementById('memberStatus').value,
        };
        if (editId) payload.id = Number(editId);
        try {
          await window.saveMember(payload, !!editId);
          resetMemberForm();
          await loadMembersCatalog();
          await loadCommissionsCatalog();
          if (typeof window.populateMemberSelects === 'function') window.populateMemberSelects();
          populateMembersCommissionFilter();
          populateMemberCommissionSelect();
          renderMembersGrid();
          renderCommissionsGrid();
          window.showSuccess?.(editId ? t('members.updated', 'Член ОС обновлён') : t('members.created', 'Член ОС добавлен'));
        } catch (err) {
          alert(err.message);
        }
      });
    }
    document.getElementById('memberFormReset')?.addEventListener('click', resetMemberForm);

    const formCommission = document.getElementById('formCommission');
    if (formCommission) {
      formCommission.addEventListener('submit', async (e) => {
        e.preventDefault();
        const editId = document.getElementById('commissionEditId').value;
        const payload = {
          name: document.getElementById('commissionName').value.trim(),
          sort_order: Number(document.getElementById('commissionSortOrder').value || 0),
          color: document.getElementById('commissionColor').value,
        };
        if (editId) payload.id = Number(editId);
        try {
          await window.saveCommission(payload, !!editId);
          resetCommissionForm();
          await loadCommissionsCatalog();
          await loadMembersCatalog();
          if (typeof window.populateMemberSelects === 'function') window.populateMemberSelects();
          populateMembersCommissionFilter();
          populateMemberCommissionSelect();
          renderMembersGrid();
          renderCommissionsGrid();
          window.showSuccess?.(editId ? t('commissions.updated', 'Комиссия обновлена') : t('commissions.created', 'Комиссия добавлена'));
        } catch (err) {
          alert(err.message);
        }
      });
    }
    document.getElementById('commissionFormReset')?.addEventListener('click', resetCommissionForm);
  }

  window.AppMembers = {
    loadMembersCatalog,
    loadCommissionsCatalog,
    renderMembersGrid,
    renderCommissionsGrid,
    populateMembersCommissionFilter,
    populateMemberCommissionSelect,
    bindMembersCommissionsForms,
    editMember,
    editCommission,
  };

  window.loadMembersCatalog = loadMembersCatalog;
  window.loadCommissionsCatalog = loadCommissionsCatalog;
  window.renderMembersGrid = renderMembersGrid;
  window.renderCommissionsGrid = renderCommissionsGrid;
  window.populateMembersCommissionFilter = populateMembersCommissionFilter;
  window.populateMemberCommissionSelect = populateMemberCommissionSelect;
  window.bindMembersCommissionsForms = bindMembersCommissionsForms;
})(window);
