/**
 * UI членов ОС и комиссий.
 */
(function (window) {
  const api = () => window.AppCore?.API_BASE || '/api';

  let memberStatsModal = null;

  function t(key, fallback) {
    return window.AppI18n?.t(key) || fallback;
  }

  function memberLocalizedField(member, ruKey, kzKey) {
    const isKz = window.AppI18n?.lang === 'kz';
    if (isKz && member[kzKey]) return member[kzKey];
    return member[ruKey] || '';
  }

  async function initKazLlmTranslate() {
    if (!window.canWrite()) return;
    try {
      const resp = await fetch(`${api()}/translate.php?action=status`);
      const data = await resp.json().catch(() => ({}));
      if (!data.enabled) return;
      document.getElementById('kazllmMemberFields')?.classList.remove('d-none');
    } catch {
      /* KazLLM не настроен — блок скрыт */
    }
  }

  async function translateField(sourceId, targetId) {
    const source = document.getElementById(sourceId);
    const target = document.getElementById(targetId);
    if (!source || !target) return;
    const text = source.value.trim();
    if (!text) {
      window.showWarning?.(t('translate.empty', 'Сначала заполните поле на русском')) || alert('Сначала заполните поле');
      return;
    }
    const btn = sourceId === 'memberPosition' ? document.getElementById('btnTranslatePosition') : document.getElementById('btnTranslateOrganization');
    if (btn) btn.disabled = true;
    try {
      const resp = await fetch(`${api()}/translate.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text, source: 'ru', target: 'kk' }),
      });
      const data = await resp.json().catch(() => ({}));
      if (!resp.ok) throw new Error(data.error || `HTTP ${resp.status}`);
      target.value = data.text || '';
      window.showSuccess?.(t('translate.done', 'Перевод готов'));
    } catch (err) {
      window.showError?.(err.message) || alert(err.message);
    } finally {
      if (btn) btn.disabled = false;
    }
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
    document.getElementById('memberPositionKz').value = member.position_kz || '';
    document.getElementById('memberOrganization').value = member.organization || '';
    document.getElementById('memberOrganizationKz').value = member.organization_kz || '';
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
      const color = escapeHtml(member.commission_color || '#1D4ED8');
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
              <p class="member-role text-muted small mb-2">${escapeHtml(memberLocalizedField(member, 'position', 'position_kz') || t('members.default_role', 'Член совета'))}</p>
              ${commissionBadge}
              ${memberLocalizedField(member, 'organization', 'organization_kz') ? `<p class="small text-muted mt-2 mb-0">${escapeHtml(memberLocalizedField(member, 'organization', 'organization_kz'))}</p>` : ''}
              ${member.phone ? `<p class="small text-muted mb-0">${escapeHtml(member.phone)}</p>` : ''}
              <div class="mt-3 d-flex justify-content-center gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-info" data-stats-member="${member.id}" data-member-name="${escapeHtml(member.full_name || '')}" data-member-commission="${escapeHtml(member.commission_name || '')}">
                  <i class="bi bi-bar-chart"></i> ${t('members.stats', 'Статистика')}
                </button>
                ${window.canWrite() ? `
                  <button type="button" class="btn btn-sm btn-outline-primary" data-edit-member="${member.id}"><i class="bi bi-pencil"></i> ${t('action.edit', 'Изменить')}</button>
                  <label class="btn btn-sm btn-outline-secondary mb-0">
                    <i class="bi bi-camera"></i> ${t('members.photo', 'Фото')}
                    <input type="file" accept="image/jpeg,image/png" class="d-none" data-photo-upload="${member.id}">
                  </label>
                  ${window.canDelete() ? `<button type="button" class="btn btn-sm btn-outline-danger" data-delete-member="${member.id}"><i class="bi bi-trash"></i></button>` : ''}
                ` : ''}
              </div>
            </div>
          </div>
        </div>`;
    }).join('');

    grid.querySelectorAll('[data-stats-member]').forEach((btn) => {
      btn.addEventListener('click', () => openMemberStats(
        Number(btn.dataset.statsMember),
        btn.dataset.memberName,
        btn.dataset.memberCommission,
      ));
    });

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
      const color = escapeHtml(commission.color || '#1D4ED8');
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
          position_kz: document.getElementById('memberPositionKz').value.trim(),
          organization: document.getElementById('memberOrganization').value.trim(),
          organization_kz: document.getElementById('memberOrganizationKz').value.trim(),
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

    document.getElementById('btnTranslatePosition')?.addEventListener('click', () => {
      translateField('memberPosition', 'memberPositionKz');
    });
    document.getElementById('btnTranslateOrganization')?.addEventListener('click', () => {
      translateField('memberOrganization', 'memberOrganizationKz');
    });
    initKazLlmTranslate();
  }

  function openMemberStats(memberId, memberName, commissionName) {
    const modalEl = document.getElementById('memberStatsModal');
    if (!modalEl) return;
    if (!memberStatsModal) {
      memberStatsModal = new bootstrap.Modal(modalEl);
    }

    document.getElementById('memberStatsName').textContent = memberName || '—';
    document.getElementById('memberStatsCommission').textContent = commissionName || '';

    const avatarEl = document.getElementById('memberStatsAvatar');
    if (avatarEl) avatarEl.textContent = getInitials(memberName);

    const content = document.getElementById('memberStatsContent');
    if (content) {
      content.innerHTML = `<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>`;
    }

    memberStatsModal.show();

    fetch(`${api()}/member_stats.php?member_id=${memberId}`)
      .then((r) => r.json())
      .then((data) => {
        if (content) content.innerHTML = renderMemberStats(data);
      })
      .catch((err) => {
        if (content) content.innerHTML = `<div class="alert alert-danger">${escapeHtml(err.message)}</div>`;
      });
  }

  function renderMemberStats(s) {
    if (s.error) return `<div class="alert alert-danger">${escapeHtml(s.error)}</div>`;

    const rate = s.response_rate !== null ? s.response_rate : null;
    const rateBar = rate !== null
      ? `<div class="mt-3">
           <div class="d-flex justify-content-between small mb-1">
             <span>${t('members.stat_response_rate', 'Процент ответов')}</span>
             <strong>${rate}%</strong>
           </div>
           <div class="progress" style="height:8px">
             <div class="progress-bar ${rate >= 75 ? 'bg-success' : rate >= 40 ? 'bg-warning' : 'bg-danger'}"
                  role="progressbar" style="width:${rate}%"></div>
           </div>
         </div>`
      : '';

    const kpis = [
      { label: t('members.stat_incoming', 'Входящих'), value: s.assigned_incoming, cls: 'border-primary' },
      { label: t('members.stat_outgoing', 'Исходящих'), value: s.assigned_outgoing, cls: 'border-secondary' },
      { label: t('members.stat_closed', 'Отвечено'), value: s.closed_incoming, cls: 'border-success' },
      { label: t('members.stat_open', 'Открытых'), value: s.open_incoming, cls: 'border-warning' },
      { label: t('members.stat_overdue', 'Просроченных'), value: s.overdue_incoming, cls: s.overdue_incoming > 0 ? 'border-danger' : 'border-secondary' },
      { label: t('members.stat_lead', 'Ведущий по'), value: s.lead_count, cls: 'border-info' },
    ];

    const kpiHtml = kpis.map((k) => `
      <div class="col-6 col-sm-4">
        <div class="border-start border-3 ${k.cls} ps-3 py-1 mb-3">
          <div class="small text-muted">${k.label}</div>
          <div class="fs-4 fw-bold">${k.value}</div>
        </div>
      </div>`).join('');

    let recentHtml = '';
    if (s.recent_letters && s.recent_letters.length) {
      const rows = s.recent_letters.map((l) => {
        const statusBadge = l.is_closed
          ? `<span class="badge bg-success-subtle text-success">${t('members.stat_answered', 'Отвечено')}</span>`
          : `<span class="badge bg-warning-subtle text-warning">${t('members.stat_pending', 'Открыто')}</span>`;
        const leadBadge = l.is_lead ? `<span class="badge bg-info-subtle text-info ms-1">${t('members.stat_lead_badge', 'Ведущий')}</span>` : '';
        return `<tr>
          <td class="text-muted small">${escapeHtml(l.date || '')}</td>
          <td>${escapeHtml(l.organization || '')}</td>
          <td class="text-muted small">${escapeHtml(l.kk_number || '—')}</td>
          <td>${statusBadge}${leadBadge}</td>
        </tr>`;
      }).join('');
      recentHtml = `
        <h6 class="mt-4 mb-2">${t('members.stat_recent', 'Последние входящие письма')}</h6>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>${t('members.stat_date', 'Дата')}</th>
                <th>${t('members.stat_org', 'Организация')}</th>
                <th>${t('letters.kk_number', 'Рег. номер')}</th>
                <th>${t('common.status', 'Статус')}</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>`;
    } else {
      recentHtml = `<p class="text-muted mt-3 mb-0">${t('members.stat_no_letters', 'Писем не назначено')}</p>`;
    }

    return `<div class="row g-0">${kpiHtml}</div>${rateBar}${recentHtml}`;
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

  window.addEventListener('app:langchange', () => {
    if (document.getElementById('membersGrid')) renderMembersGrid();
  });
})(window);
