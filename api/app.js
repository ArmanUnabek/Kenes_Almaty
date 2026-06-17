const API_BASE = '/api';
const store = {
  incoming: [],
  outgoing: []
};
let sessionUser = null;
let membersCatalog = [];

function canWrite() {
  return !!(sessionUser?.can_write);
}

function canDelete() {
  return !!(sessionUser?.can_delete);
}

function applyPermissionsUI(user) {
  sessionUser = user || sessionUser;
  const show = canWrite();
  document.getElementById('memberFormCard')?.classList.toggle('d-none', !show);
  document.getElementById('commissionFormCard')?.classList.toggle('d-none', !show);
}

async function saveMember(payload, isEdit) {
  const url = `${API_BASE}/members.php`;
  const resp = await fetch(url, {
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

function resetMemberForm() {
  const form = document.getElementById('formMember');
  if (!form) return;
  form.reset();
  document.getElementById('memberEditId').value = '';
  document.getElementById('memberFormTitle').textContent = 'Добавить члена ОС';
  document.getElementById('memberStatus').value = 'active';
}

function resetCommissionForm() {
  const form = document.getElementById('formCommission');
  if (!form) return;
  form.reset();
  document.getElementById('commissionEditId').value = '';
  document.getElementById('commissionFormTitle').textContent = 'Добавить комиссию';
  document.getElementById('commissionColor').value = '#0d6efd';
  document.getElementById('commissionSortOrder').value = '0';
}

function populateMemberCommissionSelect() {
  const select = document.getElementById('memberCommissionId');
  if (!select) return;
  const current = select.value;
  select.innerHTML = '<option value="">— не назначена —</option>' +
    commissionsCatalog.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
  if (current) select.value = current;
}

function editMember(member) {
  if (!canWrite()) return;
  document.getElementById('memberEditId').value = member.id;
  document.getElementById('memberFullName').value = member.full_name || '';
  document.getElementById('memberCommissionId').value = member.commission_id || '';
  document.getElementById('memberPosition').value = member.position || '';
  document.getElementById('memberOrganization').value = member.organization || '';
  document.getElementById('memberPhone').value = member.phone || '';
  document.getElementById('memberEmail').value = member.email || '';
  document.getElementById('memberStatus').value = member.status || 'active';
  document.getElementById('memberFormTitle').textContent = 'Редактировать члена ОС';
  const collapse = document.getElementById('collapseMemberForm');
  if (collapse && !collapse.classList.contains('show')) {
    new bootstrap.Collapse(collapse, { toggle: true });
  }
}

function editCommission(commission) {
  if (!canWrite()) return;
  document.getElementById('commissionEditId').value = commission.id;
  document.getElementById('commissionName').value = commission.name || '';
  document.getElementById('commissionSortOrder').value = commission.sort_order || 0;
  document.getElementById('commissionColor').value = commission.color || '#0d6efd';
  document.getElementById('commissionFormTitle').textContent = 'Редактировать комиссию';
  const collapse = document.getElementById('collapseCommissionForm');
  if (collapse && !collapse.classList.contains('show')) {
    new bootstrap.Collapse(collapse, { toggle: true });
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
        await saveMember(payload, !!editId);
        resetMemberForm();
        await loadMembersCatalog();
        await loadCommissionsCatalog();
        populateMemberSelects();
        populateMembersCommissionFilter();
        populateMemberCommissionSelect();
        renderMembersGrid();
        renderCommissionsGrid();
        if (window.showSuccess) showSuccess(editId ? 'Член ОС обновлён' : 'Член ОС добавлен');
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
        await saveCommission(payload, !!editId);
        resetCommissionForm();
        await loadCommissionsCatalog();
        await loadMembersCatalog();
        populateMemberSelects();
        populateMembersCommissionFilter();
        populateMemberCommissionSelect();
        renderMembersGrid();
        renderCommissionsGrid();
        if (window.showSuccess) showSuccess(editId ? 'Комиссия обновлена' : 'Комиссия добавлена');
      } catch (err) {
        alert(err.message);
      }
    });
  }
  document.getElementById('commissionFormReset')?.addEventListener('click', resetCommissionForm);
}

window.applyPermissionsUI = applyPermissionsUI;

let commissionsCatalog = [];
const letterDetailsCache = {
  incoming: new Map(),
  outgoing: new Map()
};

function getSessionUser() {
  try {
    const raw = localStorage.getItem('user');
    return raw ? JSON.parse(raw) : null;
  } catch (error) {
    console.warn('Не удалось прочитать пользователя из localStorage', error);
    return null;
  }
}

// Утилиты форматирования
function formatDateISOtoRus(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  return d.toLocaleDateString("ru-RU");
}

function monthKey(iso) {
  const d = new Date(iso);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

function unique(values) {
  return Array.from(new Set(values));
}

const MONTHS_RU = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

function formatMonthLabel(key) {
  // key: YYYY-MM
  if (!key) return '';
  const [y, mm] = String(key).split('-');
  const m = Number(mm);
  if (!y || !Number.isFinite(m) || m < 1 || m > 12) return String(key);
  return `${MONTHS_RU[m - 1]} ${y}`;
}

function getMonthKeys(items) {
  return unique((items || []).map(x => monthKey(x.date)))
    .filter(Boolean)
    .sort((a, b) => a.localeCompare(b));
}

function periodKeyFromDate(d, period) {
  const dt = new Date(d);
  const y = dt.getFullYear();
  const m = dt.getMonth(); // 0-11

  if (period === 'month') {
    return `${y}-${String(m + 1).padStart(2, '0')}`;
  }
  if (period === 'quarter') {
    const q = Math.floor(m / 3) + 1;
    return `${y}-Q${q}`;
  }
  // year
  return String(y);
}

function periodSortValue(key, period) {
  if (!key) return 0;
  if (period === 'month') {
    const [y, m] = String(key).split('-');
    return Number(y) * 100 + Number(m);
  }
  if (period === 'quarter') {
    const [y, qPart] = String(key).split('-Q');
    const q = Number(qPart);
    return Number(y) * 10 + q;
  }
  return Number(key);
}

function formatPeriodLabel(key, period) {
  if (!key) return '';
  if (period === 'month') return formatMonthLabel(key);
  if (period === 'quarter') {
    const [y, qPart] = String(key).split('-Q');
    const q = Number(qPart);
    return `Квартал ${q} ${y}`;
  }
  if (period === 'year') return `Год ${key}`;
  return String(key);
}

function groupByPeriod(items, period, dateSelector) {
  const map = new Map();
  (items || []).forEach((it) => {
    const d = dateSelector(it);
    const key = periodKeyFromDate(d, period);
    if (!key) return;
    map.set(key, (map.get(key) || 0) + 1);
  });

  return Array.from(map.entries())
    .sort(([a], [b]) => periodSortValue(a, period) - periodSortValue(b, period));
}

function mergePeriodSeries(inc, out, period) {
  const map = new Map();
  (inc || []).forEach(([k, v]) => map.set(k, { in: v, out: 0 }));
  (out || []).forEach(([k, v]) => {
    const row = map.get(k) || { in: 0, out: 0 };
    row.out = v;
    map.set(k, row);
  });

  return Array.from(map.entries())
    .sort(([a], [b]) => periodSortValue(a, period) - periodSortValue(b, period));
}

let confirmDeleteModalInstance = null;
let confirmDeleteHandler = null;

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

window.confirmDelete = confirmDelete;

// KPI counters animation used by updateKpiValue().
window.enableKpiCounterAnimation = true;
function animateCounter(el, to) {
  const target = Number(to);
  if (!Number.isFinite(target)) return;
  const startRaw = Number(el.textContent || 0);
  const start = Number.isFinite(startRaw) ? startRaw : 0;
  const diff = target - start;
  const duration = 650;
  const t0 = performance.now();

  function tick(now) {
    const p = Math.min(1, (now - t0) / duration);
    const val = start + diff * (p < 1 ? (1 - Math.pow(1 - p, 3)) : 1);
    el.textContent = String(Math.round(val));
    if (p < 1) requestAnimationFrame(tick);
  }

  requestAnimationFrame(tick);
}

// Рабочие дни и отображение категорий
function parseToDate(value) {
  return value instanceof Date ? new Date(value.getTime()) : new Date(value);
}

function addWorkingDays(start, days) {
  let date = parseToDate(start);
  let remaining = Number(days) || 0;
  while (remaining > 0) {
    date.setDate(date.getDate() + 1);
    const day = date.getDay(); // 0=вс, 6=сб
    if (day !== 0 && day !== 6) {
      remaining -= 1;
    }
  }
  return date;
}

function subtractWorkingDays(start, days) {
  let date = parseToDate(start);
  let remaining = Number(days) || 0;
  while (remaining > 0) {
    date.setDate(date.getDate() - 1);
    const day = date.getDay();
    if (day !== 0 && day !== 6) {
      remaining -= 1;
    }
  }
  return date;
}

function categoryDisplay(cat) {
  if (cat === 'KK') return 'ҚК';
  if (cat === 'N') return '.Н';
  if (cat === 'JT') return 'ЖТ';
  if (cat === 'ZT') return 'ЗТ';
  if (!cat || cat === 'OTHER') return '—';
  return String(cat || '');
}

function categoryBadgeClass(cat) {
  if (cat === 'KK') return 'badge-type-kk';
  if (cat === 'N') return 'badge-type-n';
  if (cat === 'JT') return 'badge-type-jt';
  if (cat === 'ZT') return 'badge-type-zt';
  return 'badge-type-other';
}

function mapOutgoingTypeToCategory(type) {
  switch (type) {
    case 'jt':
      return 'JT';
    case 'zt':
      return 'ZT';
    case 'recommend':
      return 'N';
    case 'gov':
      return 'KK';
    default:
      return 'OTHER';
  }
}

function mapIncomingCategoryToOutgoingType(cat) {
  switch (cat) {
    case 'JT':
      return 'jt';
    case 'ZT':
      return 'zt';
    case 'N':
      return 'recommend';
    case 'KK':
    default:
      return 'gov';
  }
}

function getExtensionFromType(type, name) {
  const fromName = (name || '').split('.').pop();
  if (fromName && fromName !== name) return fromName.toLowerCase();
  if (!type) return '';
  const map = {
    'application/pdf': 'pdf',
    'application/msword': 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
    'application/vnd.ms-excel': 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
    'application/vnd.ms-powerpoint': 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'pptx',
    'video/mp4': 'mp4',
    'video/x-msvideo': 'avi',
    'video/quicktime': 'mov',
    'text/plain': 'txt',
    'application/zip': 'zip',
    'application/x-rar-compressed': 'rar'
  };
  if (map[type]) return map[type];
  const match = type.split('/').pop();
  return match || '';
}

function getRecipientOptions(items) {
  const set = new Set();
  items.forEach(item => {
    (item.recipients || []).forEach(rec => {
      if (rec) set.add(rec);
    });
  });
  return Array.from(set).sort((a, b) => a.localeCompare(b));
}

function updateRecipientFilterSelect(selectEl, options) {
  if (!selectEl) return;
  const prev = selectEl.value;
  selectEl.innerHTML = '';
  const def = document.createElement('option');
  def.value = 'all';
  def.textContent = 'Все адресаты';
  selectEl.appendChild(def);
  options.forEach(name => {
    const opt = document.createElement('option');
    opt.value = name;
    opt.textContent = name;
    selectEl.appendChild(opt);
  });
  if (prev && prev !== 'all' && options.includes(prev)) {
    selectEl.value = prev;
  } else {
    selectEl.value = 'all';
  }
}

// Функции для работы со сканами
async function compressImage(file, maxWidth = 1920, quality = 0.8) {
  return new Promise((resolve, reject) => {
    const type = file.type || '';
    if (!type.startsWith('image/')) {
      const reader = new FileReader();
      reader.onload = () => resolve({
        data: reader.result,
        type: type || 'application/octet-stream',
        name: file.name,
        size: file.size
      });
      reader.onerror = reject;
      reader.readAsDataURL(file);
      return;
    }

    const reader = new FileReader();
    reader.onload = (e) => {
      const img = new Image();
      img.onload = () => {
        const canvas = document.createElement('canvas');
        let width = img.width;
        let height = img.height;

        if (width > maxWidth) {
          height = (height * maxWidth) / width;
          width = maxWidth;
        }

        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, width, height);

        const compressedDataUrl = canvas.toDataURL('image/jpeg', quality);
        resolve({
          data: compressedDataUrl,
          type: 'image/jpeg',
          name: file.name,
          size: compressedDataUrl.length
        });
      };
      img.onerror = reject;
      img.src = e.target.result;
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function renderScanPreview(scan, container, isForm = false) {
  const preview = document.createElement('div');
  preview.className = `scan-preview ${isForm ? 'scan-preview-form' : 'scan-preview-small'}`;
  
  const mime = (scan.type || '').toLowerCase();
  const isImage = mime.startsWith('image/');
  const isPdf = mime === 'application/pdf';

  if (isPdf) {
    preview.innerHTML = `
      <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; background: #f0f0f0; flex-direction: column;">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="16" y1="13" x2="8" y2="13"></line>
          <line x1="16" y1="17" x2="8" y2="17"></line>
          <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        <small style="font-size: 8px; margin-top: 4px;">PDF</small>
      </div>
    `;
  } else if (isImage) {
    const img = document.createElement('img');
    img.src = scan.data;
    img.alt = scan.name || 'Скан';
    preview.appendChild(img);
  } else {
    const ext = getExtensionFromType(scan.type, scan.name).toUpperCase();
    preview.innerHTML = `
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;height:100%;background:#f8f9fa;border:1px dashed #dee2e6;border-radius:6px;">
        <div style="font-weight:600;font-size:12px;">${ext || 'FILE'}</div>
        <small class="text-muted">${escapeHtml(scan.name || '')}</small>
      </div>
    `;
  }

  if (isForm) {
    const removeBtn = document.createElement('button');
    removeBtn.className = 'remove-scan';
    removeBtn.innerHTML = '×';
    removeBtn.type = 'button';
    removeBtn.onclick = () => {
      preview.remove();
      if (container === incScansPreview) {
        pendingIncomingScans = pendingIncomingScans.filter(s => s !== scan);
      } else {
        pendingOutgoingScans = pendingOutgoingScans.filter(s => s !== scan);
      }
    };
    preview.appendChild(removeBtn);
  } else {
    preview.onclick = () => viewScans([scan], 0);
  }

  container.appendChild(preview);
}

function renderLetterScans(letter, type, container) {
  if (!container) return;
  const count = letter.scansCount || (letter.scans ? letter.scans.length : 0);
  if (!count) {
    container.innerHTML = '<span class="text-muted">—</span>';
    return;
  }
  container.innerHTML = '';
  const badge = document.createElement('span');
  badge.className = 'scan-badge';
  badge.innerHTML = `
    <span>📄</span>
    <span class="badge-count">${count}</span>
  `;
  badge.addEventListener('click', async () => {
    try {
      const detail = await fetchLetterDetail(type, Number(letter.id));
      const scans = (detail.scans || []).map(normalizeScanFromApi).filter(Boolean);
      if (!scans.length) {
        if (window.showWarning) {
          showWarning('Сканы не найдены');
        } else {
          alert('Сканы не найдены');
        }
        return;
      }
      currentViewingScans = scans;
      viewScans(scans, 0);
    } catch (error) {
      console.error('Ошибка загрузки сканов', error);
      if (window.showError) {
        showError('Не удалось загрузить сканы');
      } else {
        alert('Не удалось загрузить сканы');
      }
    }
  });
  container.appendChild(badge);
}

function renderMembersCell(members, container) {
  if (!container) return;
  if (!members || members.length === 0) {
    container.innerHTML = '<span class="text-muted">—</span>';
    return;
  }
  const chips = members.map(member => {
    const flag = member.is_lead ? '⭐ ' : '';
    return `<span class="badge rounded-pill bg-light text-dark border me-1">${flag}${escapeHtml(member.full_name)}</span>`;
  }).join('');
  container.innerHTML = `<div class="members-chips">${chips}</div>`;
}

function renderRecipientsCell(recipients, container, type, id) {
  if (!container) return;
  container.innerHTML = '';
  if (!recipients || recipients.length === 0) {
    container.innerHTML = '<span class="text-muted">—</span>';
    return;
  }
  const wrap = document.createElement('div');
  wrap.className = 'd-flex flex-wrap align-items-center gap-1';
  recipients.slice(0, 3).forEach(name => {
    const badge = document.createElement('span');
    badge.className = 'badge rounded-pill bg-light text-dark border';
    badge.textContent = name;
    wrap.appendChild(badge);
  });
  if (recipients.length > 3) {
    const more = document.createElement('span');
    more.className = 'text-muted small';
    more.textContent = `+${recipients.length - 3}`;
    wrap.appendChild(more);
  }
  container.appendChild(wrap);
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-sm btn-outline-secondary ms-1';
  btn.textContent = 'Просмотр';
  btn.addEventListener('click', () => viewLetterRecipients(type, id));
  container.appendChild(btn);
}

function normalizeScanFromApi(scan) {
  if (!scan) return null;
  const data = scan.scan_data || scan.data || scan.scan_url || null;
  return {
    data,
    type: scan.scan_type || scan.type || 'application/octet-stream',
    name: scan.file_name || scan.name || 'scan',
    size: scan.file_size || scan.size || null
  };
}

function viewScans(scans, startIndex = 0) {
  if (!scans || scans.length === 0) return;
  currentViewingScans = scans;
  
  scanViewerCarouselInner.innerHTML = '';
  scans.forEach((scan, index) => {
    const item = document.createElement('div');
    item.className = `carousel-item ${index === startIndex ? 'active' : ''}`;
    const mime = (scan.type || '').toLowerCase();
    const isImage = mime.startsWith('image/');
    const isPdf = mime === 'application/pdf';

    if (isPdf) {
      const safeSrc = (typeof scan.data === 'string' && scan.data.startsWith('data:')) ? scan.data : '';
      item.innerHTML = safeSrc
        ? `<iframe src="${safeSrc}" style="width: 100%; height: 70vh;"></iframe>`
        : '<p class="text-muted text-center py-5">Предпросмотр недоступен</p>';
    } else if (isImage) {
      const img = document.createElement('img');
      img.src = scan.data;
      img.className = 'd-block w-100';
      img.alt = scan.name || `Скан ${index + 1}`;
      item.appendChild(img);
    } else {
      const ext = getExtensionFromType(scan.type, scan.name).toUpperCase();
      item.innerHTML = `
        <div class="d-flex flex-column align-items-center justify-content-center" style="height:60vh;">
          <div class="display-5 fw-semibold">${ext || 'FILE'}</div>
          <div class="text-muted mb-3">${escapeHtml(scan.name || '')}</div>
          <button class="btn btn-outline-primary btn-sm" data-download="${index}">Скачать</button>
        </div>
      `;
      item.querySelector('button[data-download]').addEventListener('click', () => {
        const link = document.createElement('a');
        link.href = scan.data;
        const extLower = (ext || 'dat').toLowerCase();
        link.download = scan.name || `attachment.${extLower}`;
        link.click();
      });
    }
    
    scanViewerCarouselInner.appendChild(item);
  });

  const modal = new bootstrap.Modal(scanViewerModal);
  modal.show();
}

// обработчики сканов вешаем в bindEventListeners после инициализации DOM-элементов

// DOM элементы
const formIncoming = document.getElementById("formIncoming");
const incDate = document.getElementById("incDate");
const incType = document.getElementById("incType");
const incOrg = document.getElementById("incOrg");
const incNumber = document.getElementById("incNumber");
const incSeq = document.getElementById("incSeq");
const incLinkToggle = document.getElementById("incLinkToggle");
const incRespondsOutgoing = document.getElementById("incRespondsOutgoing");
const incSubject = document.getElementById("incSubject");
const incNote = document.getElementById("incNote");
const searchIncoming = document.getElementById("searchIncoming");
const filterYearIncoming = document.getElementById("filterYearIncoming");
const tableIncomingBody = document.querySelector("#tableIncoming tbody");

const formOutgoing = document.getElementById("formOutgoing");
const outDate = document.getElementById("outDate");
const outLinkedIncoming = document.getElementById("outLinkedIncoming");
const outType = document.getElementById("outType");
const outNumber = document.getElementById("outNumber");
const outSubject = document.getElementById("outSubject");
const outNote = document.getElementById("outNote");
const searchOutgoing = document.getElementById("searchOutgoing");
const filterYearOutgoing = document.getElementById("filterYearOutgoing");
const tableOutgoingBody = document.querySelector("#tableOutgoing tbody");

const exportJsonBtn = document.getElementById("exportJsonBtn");
const exportCsvBtn = document.getElementById("exportCsvBtn");
const importJsonInput = document.getElementById("importJsonInput");

// Элементы для сканов
const incScans = document.getElementById("incScans");
const incScansPreview = document.getElementById("incScansPreview");
const outScans = document.getElementById("outScans");
const outScansPreview = document.getElementById("outScansPreview");
const filterMonthIncoming = document.getElementById("filterMonthIncoming");
const filterStatusIncoming = document.getElementById("filterStatusIncoming");
const filterScansIncoming = document.getElementById("filterScansIncoming");
const filterMonthOutgoing = document.getElementById("filterMonthOutgoing");
const filterScansOutgoing = document.getElementById("filterScansOutgoing");
const filterRecipientIncoming = document.getElementById("filterRecipientIncoming");
const filterRecipientOutgoing = document.getElementById("filterRecipientOutgoing");
const scanViewerModal = document.getElementById("scanViewerModal");
const scanViewerCarouselInner = document.getElementById("scanViewerCarouselInner");
const downloadAllScansBtn = document.getElementById("downloadAllScansBtn");

// Хранилище для временных сканов перед отправкой формы
let pendingIncomingScans = [];
let pendingOutgoingScans = [];
let currentViewingScans = [];
let incomingRecipients = [];
let outgoingRecipients = [];
let linkOutgoingModalInstance = null;
let lastAutoIncomingNumber = '';
let linkModalContext = { type: 'incoming-table', incomingId: null };
let selectedOutgoingForIncoming = null;

// KPI элементы
const kpiIncoming = document.getElementById("kpiIncoming");
const kpiOutgoing = document.getElementById("kpiOutgoing");
const kpiClosed = document.getElementById("kpiClosed");
const kpiAvgDays = document.getElementById("kpiAvgDays");
const kpiIncomingMay = document.getElementById("kpiIncomingMay");
const kpiIncomingPeriodLabel = document.getElementById("kpiIncomingPeriodLabel");
const kpiWithScans = document.getElementById("kpiWithScans");
const kpiTotalScans = document.getElementById("kpiTotalScans");
const kpiDbSize = document.getElementById("kpiDbSize");

const dashboardPeriodSelect = document.getElementById('dashboardPeriodSelect');

// Chart.js графики
let chartLettersTrend, chartTopOrgs, chartPieGov, chartPieOS;

// Мероприятия DOM
const formEvent = document.getElementById('formEvent');
const evTitle = document.getElementById('evTitle');
const evDate = document.getElementById('evDate');
const evLocation = document.getElementById('evLocation');
const evNotes = document.getElementById('evNotes');
const kpiList = document.getElementById('kpiList');
const addKpiBtn = document.getElementById('addKpiBtn');
const tableEventsBody = document.querySelector('#tableEvents tbody');
const searchEvents = document.getElementById('searchEvents');
const attChecklist = document.getElementById('attChecklist');
const attSelectAll = document.getElementById('attSelectAll');
const attClear = document.getElementById('attClear');
const incRecipientInput = document.getElementById('incRecipientInput');
const incRecipientAdd = document.getElementById('incRecipientAdd');
const incRecipientsList = document.getElementById('incRecipientsList');
const outRecipientInput = document.getElementById('outRecipientInput');
const outRecipientAdd = document.getElementById('outRecipientAdd');
const outRecipientsList = document.getElementById('outRecipientsList');
const letterRecipientsModal = document.getElementById('letterRecipientsModal');
const letterRecipientsBody = document.getElementById('letterRecipientsBody');
const listKpiRecipientsIncoming = document.getElementById('listKpiRecipientsIncoming');
const listKpiRecipientsOutgoing = document.getElementById('listKpiRecipientsOutgoing');
const btnOpenOutgoingFromIncoming = document.getElementById('btnOpenOutgoingFromIncoming');
const linkOutgoingModalEl = document.getElementById('linkOutgoingModal');
const linkOutgoingList = document.getElementById('linkOutgoingList');
const linkOutgoingSearch = document.getElementById('linkOutgoingSearch');
const linkOutgoingConfirm = document.getElementById('linkOutgoingConfirm');

// Events store
store.events = [];

// Realtime (Pusher)
let pusherClient = null;
const lastKpiValues = {};

// Autocomplete setup
function setupAutocomplete(inputId, dataGetter, filterKey = null) {
  const input = document.getElementById(inputId);
  if (!input) return;

  // Wrap input in a relative container if not already
  if (!input.parentElement.classList.contains('search-container')) {
    const wrapper = document.createElement('div');
    wrapper.className = 'search-container position-relative';
    // Preserve classes from parent if it's just a div
    if (input.parentElement.tagName === 'DIV' && input.parentElement.classList.contains('d-flex')) {
       // If inside a flex container, we might need adjustment, but let's try simply wrapping
    }
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);
  }

  let currentFocus = -1;

  input.addEventListener('input', function(e) {
    const val = this.value;
    closeAllLists();
    if (!val) return false;

    currentFocus = -1;
    const list = document.createElement('div');
    list.setAttribute('id', this.id + 'autocomplete-list');
    list.setAttribute('class', 'autocomplete-suggestions');
    this.parentNode.appendChild(list);

    const data = dataGetter();
    const matches = new Set();

    // Simple heuristic: match start or contains
    data.forEach(item => {
        const text = filterKey ? item[filterKey] : item;
        if (!text) return;
        const str = String(text);
        if (str.toLowerCase().includes(val.toLowerCase())) {
            matches.add(str);
        }
    });

    // Limit suggestions
    const arr = Array.from(matches).slice(0, 10);

    arr.forEach(match => {
        const item = document.createElement('div');
        item.className = 'autocomplete-suggestion';
        // Highlight match — escape regex special chars to prevent ReDoS
        const safeVal = val.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${safeVal})`, 'gi');
        item.innerHTML = escapeHtml(match).replace(regex, '<strong>$1</strong>');
        item.innerHTML += `<input type='hidden' value='${escapeHtml(match)}'>`;
        
        item.addEventListener('click', function(e) {
            input.value = this.getElementsByTagName('input')[0].value;
            closeAllLists();
            // Trigger change/input event to update filters
            input.dispatchEvent(new Event('input'));
        });
        list.appendChild(item);
    });
  });

  input.addEventListener('keydown', function(e) {
      let x = document.getElementById(this.id + 'autocomplete-list');
      if (x) x = x.getElementsByTagName('div');
      if (e.keyCode == 40) { // DOWN
        currentFocus++;
        addActive(x);
      } else if (e.keyCode == 38) { // UP
        currentFocus--;
        addActive(x);
      } else if (e.keyCode == 13) { // ENTER
        e.preventDefault();
        if (currentFocus > -1) {
          if (x) x[currentFocus].click();
        }
      }
  });

  function addActive(x) {
    if (!x) return false;
    removeActive(x);
    if (currentFocus >= x.length) currentFocus = 0;
    if (currentFocus < 0) currentFocus = (x.length - 1);
    x[currentFocus].classList.add('autocomplete-active');
    // scroll style if needed
    x[currentFocus].style.backgroundColor = 'var(--color-bg)'; 
  }

  function removeActive(x) {
    for (let i = 0; i < x.length; i++) {
      x[i].classList.remove('autocomplete-active');
      x[i].style.backgroundColor = '';
    }
  }

  function closeAllLists(elmnt) {
    const x = document.getElementsByClassName('autocomplete-suggestions');
    for (let i = 0; i < x.length; i++) {
      if (elmnt != x[i] && elmnt != input) {
        x[i].parentNode.removeChild(x[i]);
      }
    }
  }

  document.addEventListener('click', function (e) {
      closeAllLists(e.target);
  });
}

// Предзаполняем дату сегодня
const todayISO = new Date().toISOString().slice(0, 10);
if (incDate) incDate.value = todayISO;
if (outDate) outDate.value = todayISO;
if (outType) outType.value = 'gov';
if (evDate) evDate.value = todayISO;
updateIncomingSeqPlaceholder();

bindEventListeners();

async function initializeApp() {
  try {
    if (window.showLoading) showLoading('Загрузка данных...');
    await loadMembersCatalog();
    await loadCommissionsCatalog();
    populateMemberSelects();
    populateMembersCommissionFilter();
    populateMemberCommissionSelect();
    bindMembersCommissionsForms();
    if (sessionUser) applyPermissionsUI(sessionUser);
    renderMembersGrid();
    renderCommissionsGrid();
    initAppNavigation();
    // Рендерим чеклист участников ОС при загрузке
    renderAttendeesChecklist([]);
    renderIncomingRecipients();
    renderOutgoingRecipients();
    await refreshLetters();
    await refreshEvents();
    initRealtime();
    renderAll();
    renderKpiExtra();
    updatePageHeader(document.querySelector('[data-bs-toggle="tab"].active')?.id || 'tab-dashboard');
    
    // Инициализация автодополнения после загрузки данных
    setupAutocomplete('searchIncoming', () => {
        return store.incoming.map(i => [i.organization, i.kkNumber, i.subject]).flat();
    });
    setupAutocomplete('searchOutgoing', () => {
        return store.outgoing.map(o => [o.outgoingNumber, o.subject]).flat();
    });
    setupAutocomplete('searchEvents', () => {
        return store.events.map(e => [e.title, e.location]).flat();
    });

    if (window.hideLoading) hideLoading();
    if (window.showSuccess) showSuccess('Данные успешно загружены');
  } catch (error) {
    console.error('Ошибка инициализации приложения', error);
    if (window.hideLoading) hideLoading();
    if (window.showError) {
      showError('Не удалось загрузить данные. Проверьте подключение к серверу.');
    } else {
      alert('Не удалось загрузить данные. Проверьте подключение к серверу.');
    }
  }
}

function bindEventListeners() {
  const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
  const confirmDeleteModal = document.getElementById('confirmDeleteModal');
  if (confirmDeleteBtn) {
    confirmDeleteBtn.addEventListener('click', async () => {
      const handler = confirmDeleteHandler;
      confirmDeleteHandler = null;
      if (confirmDeleteModalInstance) {
        confirmDeleteModalInstance.hide();
      }
      if (typeof handler === 'function') {
        await handler();
      }
    });
  }
  if (confirmDeleteModal) {
    confirmDeleteModal.addEventListener('hidden.bs.modal', () => {
      confirmDeleteHandler = null;
    });
  }

  if (formIncoming) {
    formIncoming.addEventListener('submit', handleIncomingSubmit);
    formIncoming.addEventListener('reset', () => {
      pendingIncomingScans = [];
      if (incScansPreview) incScansPreview.innerHTML = '';
      if (incMembers) clearMultiselect(incMembers);
      incomingRecipients = [];
      renderIncomingRecipients();
      if (incRecipientInput) incRecipientInput.value = '';
      if (incRespondsOutgoing) {
        incRespondsOutgoing.value = '';
        incRespondsOutgoing.classList.add('d-none');
      }
      if (incLinkToggle) incLinkToggle.checked = false;
      updateIncomingLinkVisibility();
      lastAutoIncomingNumber = '';
      updateIncomingSeqPlaceholder();
      delete formIncoming.dataset.editId;
      delete formIncoming.dataset.deleteScanIds;
      incDate.value = todayISO;
      if (incSeq) incSeq.value = '';
      if (incNumber) incNumber.value = '';
    });
  }

  if (formOutgoing) {
    formOutgoing.addEventListener('submit', handleOutgoingSubmit);
    formOutgoing.addEventListener('reset', () => {
      pendingOutgoingScans = [];
      if (outScansPreview) outScansPreview.innerHTML = '';
      if (outMembers) clearMultiselect(outMembers);
      outgoingRecipients = [];
      renderOutgoingRecipients();
      if (outRecipientInput) outRecipientInput.value = '';
      delete formOutgoing.dataset.editId;
      delete formOutgoing.dataset.deleteScanIds;
      outDate.value = todayISO;
      if (outLinkedIncoming) outLinkedIncoming.value = '';
      if (outType) outType.value = 'gov';
      if (outNumber) outNumber.value = '';
    });
  }

  if (incType) {
    const updatePlaceholder = () => {
      const t = incType.value;
      if (t === 'KK') incNumber.placeholder = '1345-ҚК';
      else if (t === 'N') incNumber.placeholder = '27.Н';
      else if (t === 'JT') incNumber.placeholder = 'ЖТ-55';
      else incNumber.placeholder = 'ЗТ-55';
    };
    updatePlaceholder();
    incType.addEventListener('change', updatePlaceholder);
  }

  if (outLinkedIncoming) {
    outLinkedIncoming.addEventListener('change', () => {
      updateOutgoingPreview();
      if (!formOutgoing?.dataset?.editId) {
        const incoming = store.incoming.find((i) => String(i.id) === String(outLinkedIncoming.value));
        outgoingRecipients = incoming && incoming.organization ? [incoming.organization] : [];
        renderOutgoingRecipients();
        if (incoming && outType) {
          outType.value = mapIncomingCategoryToOutgoingType(incoming.category || 'KK');
        } else if (outType) {
          outType.value = 'gov';
        }
      }
    });
  }

  if (incRecipientAdd) {
    incRecipientAdd.addEventListener('click', () => {
      const value = incRecipientInput?.value.trim();
      if (!value) return;
      if (!incomingRecipients.includes(value)) {
        incomingRecipients.push(value);
        renderIncomingRecipients();
      }
      if (incRecipientInput) incRecipientInput.value = '';
    });
  }
  if (incRecipientInput) {
    incRecipientInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        incRecipientAdd?.click();
      }
    });
  }
  if (incOrg) {
    incOrg.addEventListener('change', () => {
      const val = incOrg.value.trim();
      if (!val) return;
      if (incomingRecipients.length === 0) incomingRecipients.push(val);
      else incomingRecipients[0] = val;
      renderIncomingRecipients();
    });
  }

  if (incRespondsOutgoing) {
    incRespondsOutgoing.addEventListener('change', () => {
      updateIncomingLinkVisibility();
      autoFillIncomingNumber(true);
    });
  }
  if (incLinkToggle) {
    incLinkToggle.addEventListener('change', () => {
      if (!incRespondsOutgoing) return;
      if (incLinkToggle.checked) {
        incRespondsOutgoing.classList.remove('d-none');
        openLinkOutgoingModal({ type: 'incoming-form' });
      } else {
        incRespondsOutgoing.value = '';
        incRespondsOutgoing.classList.add('d-none');
        lastAutoIncomingNumber = '';
        autoFillIncomingNumber(true);
      }
    });
  }
  if (incSeq) {
    incSeq.addEventListener('input', () => autoFillIncomingNumber(false));
  }
  if (incNumber) {
    incNumber.addEventListener('input', () => {
      if (incNumber.value !== lastAutoIncomingNumber) {
        lastAutoIncomingNumber = '';
      }
    });
  }

  if (outRecipientAdd) {
    outRecipientAdd.addEventListener('click', () => {
      const value = outRecipientInput?.value.trim();
      if (!value) return;
      if (!outgoingRecipients.includes(value)) {
        outgoingRecipients.push(value);
        renderOutgoingRecipients();
      }
      if (outRecipientInput) outRecipientInput.value = '';
    });
  }
  if (outRecipientInput) {
    outRecipientInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        outRecipientAdd?.click();
      }
    });
  }
  if (btnOpenOutgoingFromIncoming) {
    btnOpenOutgoingFromIncoming.addEventListener('click', () => openOutgoingTab());
  }
  if (linkOutgoingSearch) {
    linkOutgoingSearch.addEventListener('input', debounce(() => renderLinkOutgoingOptions(), 200));
  }
  if (linkOutgoingConfirm) {
    linkOutgoingConfirm.addEventListener('click', () => {
      if (linkModalContext.type === 'incoming-form' && selectedOutgoingForIncoming) {
        applySelectedOutgoingForForm(selectedOutgoingForIncoming);
      }
    });
  }
  if (linkOutgoingModalEl) {
    linkOutgoingModalEl.addEventListener('hidden.bs.modal', () => {
      if (linkModalContext.type === 'incoming-form' && (!incRespondsOutgoing || !incRespondsOutgoing.value)) {
        if (incLinkToggle) incLinkToggle.checked = false;
        if (incRespondsOutgoing) incRespondsOutgoing.classList.add('d-none');
      }
      linkModalContext = { type: 'incoming-table', incomingId: null };
      selectedOutgoingForIncoming = null;
    });
  }

  // Загрузка сканов (входящие)
  if (incScans) {
    incScans.addEventListener('change', async (e) => {
      const files = Array.from(e.target.files);
      if (files.length === 0) return;

      incScansPreview.innerHTML = '<div class="text-muted">Обработка файлов...</div>';
      
      for (const file of files) {
        try {
          const prepared = await compressImage(file);
          pendingIncomingScans.push(prepared);
          renderScanPreview(prepared, incScansPreview, true);
        } catch (error) {
          console.error('Ошибка обработки файла:', error);
          alert(`Ошибка при обработке файла ${file.name}`);
        }
      }
      
      e.target.value = '';
    });
  }

  // Загрузка сканов (исходящие)
  if (outScans) {
    outScans.addEventListener('change', async (e) => {
      const files = Array.from(e.target.files);
      if (files.length === 0) return;

      outScansPreview.innerHTML = '<div class="text-muted">Обработка файлов...</div>';
      
      for (const file of files) {
        try {
          const prepared = await compressImage(file);
          pendingOutgoingScans.push(prepared);
          renderScanPreview(prepared, outScansPreview, true);
        } catch (error) {
          console.error('Ошибка обработки файла:', error);
          alert(`Ошибка при обработке файла ${file.name}`);
        }
      }
      
      e.target.value = '';
    });
  }

  if (downloadAllScansBtn) {
    downloadAllScansBtn.addEventListener('click', () => {
      if (currentViewingScans.length === 0) return;
      currentViewingScans.forEach((scan, index) => {
        const link = document.createElement('a');
        link.href = scan.data;
        const ext = getExtensionFromType(scan.type, scan.name);
        link.download = scan.name || `attachment_${index + 1}.${ext}`;
        link.click();
      });
    });
  }

  if (addKpiBtn) addKpiBtn.addEventListener('click', addKpiRow);
  if (formEvent) {
    formEvent.addEventListener('submit', handleEventSubmit);
    formEvent.addEventListener('reset', () => {
      kpiList.innerHTML = '';
      if (attChecklist) {
        attChecklist.innerHTML = '';
        renderAttendeesChecklist([]);
      }
      delete formEvent.dataset.editId;
    });
  }
  if (searchEvents) searchEvents.addEventListener('input', renderEvents);
  if (attSelectAll) attSelectAll.addEventListener('click', () => setAllAttendees(true));
  if (attClear) attClear.addEventListener('click', () => setAllAttendees(false));
}

async function loadKpi() {
  try {
    const response = await fetch(`${API_BASE}/kpi.php`);
    if (!response.ok) throw new Error('Ошибка загрузки KPI');
    return await response.json();
  } catch (e) {
    console.error('Ошибка KPI:', e);
    return { members: [], commissions: [], summary: { chair: null, chairs_commissions: [], others: [] } };
  }
}

async function renderKpiExtra() {
  const kpi = await loadKpi();
  const eventsMap = new Map((kpi.events_participation || []).map(e => [e.id, Number(e.events_attended || 0)]));
  // Таблица членов (топ 10)
  const tbodyMembers = document.querySelector('#tableKpiMembers tbody');
  if (tbodyMembers) {
    const rows = [...kpi.members].sort((a, b) => (b.outgoing_count - a.outgoing_count) || (b.incoming_count - a.incoming_count));
    tbodyMembers.innerHTML = rows.map(m => `
      <tr>
        <td>${escapeHtml(m.full_name || '')}</td>
        <td>${escapeHtml(m.commission_name || '')}</td>
        <td class="text-end">${Number(m.outgoing_count || 0)}</td>
        <td class="text-end">${Number(m.incoming_count || 0)}</td>
        <td class="text-end">${Number(m.lead_count || 0)}</td>
        <td class="text-end">${eventsMap.get(m.id) || 0}</td>
      </tr>
    `).join('');
  }

  // Таблица комиссий
  const tbodyComms = document.querySelector('#tableKpiCommissions tbody');
  if (tbodyComms) {
    const ordered = [...kpi.commissions].sort((a, b) => (b.outgoing_count - a.outgoing_count) || (b.incoming_count - a.incoming_count));
    tbodyComms.innerHTML = ordered.map(c => `
      <tr>
        <td><span class="badge" style="background:${c.color || '#1D4ED8'}">${escapeHtml(c.name || '')}</span></td>
        <td class="text-end">${Number(c.outgoing_count || 0)}</td>
        <td class="text-end">${Number(c.incoming_count || 0)}</td>
        <td class="text-end">${Number(c.events_count || 0)}</td>
      </tr>
    `).join('');
  }

  // Сводка ответственных
  const summary = document.getElementById('summaryResponsible');
  if (summary) {
    const lines = [];
    if (kpi.summary?.chair) {
      lines.push(`<div><strong>Председатель ОС:</strong> ${escapeHtml(kpi.summary.chair.full_name)}${kpi.summary.chair.commission_name ? ' — ' + escapeHtml(kpi.summary.chair.commission_name) : ''}</div>`);
    }
    (kpi.summary?.chairs_commissions || []).slice(0, 6).forEach((p, idx) => {
      lines.push(`<div>${idx + 1}. ${escapeHtml(p.full_name)} — ${escapeHtml(p.commission_name || '')}</div>`);
    });
    (kpi.summary?.others || []).slice(0, 7).forEach((o) => {
      lines.push(`<div>• ${escapeHtml(o.full_name)} — ${escapeHtml(o.commission_name || '')}</div>`);
    });
    summary.innerHTML = lines.join('');
  }

  if (listKpiRecipientsIncoming) {
    const list = (kpi.recipients?.incoming || []).slice(0, 10);
    listKpiRecipientsIncoming.innerHTML = list.length
      ? list.map(item => `<li class="list-group-item d-flex justify-content-between align-items-center">
            <span>${escapeHtml(item.recipient || '')}</span>
            <span class="badge bg-primary rounded-pill">${Number(item.total || 0)}</span>
        </li>`).join('')
      : '<li class="list-group-item text-muted">Нет данных</li>';
  }

  if (listKpiRecipientsOutgoing) {
    const list = (kpi.recipients?.outgoing || []).slice(0, 10);
    listKpiRecipientsOutgoing.innerHTML = list.length
      ? list.map(item => `<li class="list-group-item d-flex justify-content-between align-items-center">
            <span>${escapeHtml(item.recipient || '')}</span>
            <span class="badge bg-success rounded-pill">${Number(item.total || 0)}</span>
        </li>`).join('')
      : '<li class="list-group-item text-muted">Нет данных</li>';
  }

  await renderCommissionsHighlights(kpi);
}

async function loadMembersCatalog() {
  try {
    const response = await fetch(`${API_BASE}/members.php`);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    membersCatalog = await response.json();
  } catch (error) {
    console.error('Ошибка загрузки списка членов ОС', error);
    membersCatalog = [];
  }
}

async function loadCommissionsCatalog() {
  try {
    const response = await fetch(`${API_BASE}/commissions.php`);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    commissionsCatalog = await response.json();
  } catch (error) {
    console.error('Ошибка загрузки комиссий', error);
    commissionsCatalog = [];
  }
}

function getInitials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }
  return String(name || '?').slice(0, 2).toUpperCase();
}

function populateMembersCommissionFilter() {
  const select = document.getElementById('filterMembersCommission');
  if (!select) return;
  const prev = select.value;
  const seen = new Map();
  membersCatalog.forEach((member) => {
    if (member.commission_id && member.commission_name && !seen.has(String(member.commission_id))) {
      seen.set(String(member.commission_id), member.commission_name);
    }
  });
  select.innerHTML = '<option value="">Все комиссии</option>' +
    [...seen.entries()]
      .sort((a, b) => a[1].localeCompare(b[1], 'ru'))
      .map(([id, name]) => `<option value="${id}">${escapeHtml(name)}</option>`)
      .join('');
  if (prev && seen.has(prev)) {
    select.value = prev;
  }
}

function renderMembersGrid() {
  const grid = document.getElementById('membersGrid');
  const filter = document.getElementById('filterMembersCommission');
  if (!grid) return;

  let list = [...membersCatalog];
  if (filter?.value) {
    list = list.filter((m) => String(m.commission_id) === String(filter.value));
  }

  if (!list.length) {
    grid.innerHTML = `
      <div class="col-12">
        <div class="empty-state">
          <i class="bi bi-people"></i>
          <p class="mb-0">Нет членов для отображения</p>
        </div>
      </div>`;
    return;
  }

  grid.innerHTML = list.map((member) => {
    const color = member.commission_color || '#1D4ED8';
    const commissionBadge = member.commission_name
      ? `<span class="badge commission-badge" style="background:${color}20;color:${color}">${escapeHtml(member.commission_name)}</span>`
      : '';
    return `
      <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
        <div class="member-card card h-100">
          <div class="card-body text-center">
            <div class="member-avatar">${getInitials(member.full_name)}</div>
            <h6 class="member-name mt-3 mb-1">${escapeHtml(member.full_name || '')}</h6>
            <p class="member-role text-muted small mb-2">${escapeHtml(member.position || 'Член совета')}</p>
            ${commissionBadge}
            ${member.organization ? `<p class="small text-muted mt-2 mb-0">${escapeHtml(member.organization)}</p>` : ''}
            ${member.phone ? `<p class="small text-muted mb-0">${escapeHtml(member.phone)}</p>` : ''}
            ${canWrite() ? `<div class="mt-3"><button type="button" class="btn btn-sm btn-outline-primary" data-edit-member="${member.id}"><i class="bi bi-pencil"></i> Изменить</button></div>` : ''}
          </div>
        </div>
      </div>
    `;
  }).join('');

  if (canWrite()) {
    grid.querySelectorAll('[data-edit-member]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const member = membersCatalog.find((m) => String(m.id) === btn.dataset.editMember);
        if (member) editMember(member);
      });
    });
  }
}

function renderCommissionsGrid() {
  const grid = document.getElementById('commissionsGrid');
  if (!grid) return;

  if (!commissionsCatalog.length) {
    grid.innerHTML = '<div class="col-12 text-center text-muted py-5">Комиссии не найдены</div>';
    return;
  }

  const memberCounts = {};
  membersCatalog.forEach((member) => {
    if (member.commission_id) {
      memberCounts[member.commission_id] = (memberCounts[member.commission_id] || 0) + 1;
    }
  });

  grid.innerHTML = commissionsCatalog.map((commission) => {
    const color = commission.color || '#1D4ED8';
    const count = memberCounts[commission.id] || 0;
    return `
      <div class="col-12 col-md-6 col-xl-4">
        <div class="commission-card card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <span class="commission-dot" style="background:${color}"></span>
              <span class="badge commission-count">${count} ${count === 1 ? 'член' : count >= 2 && count <= 4 ? 'члена' : 'членов'}</span>
            </div>
            <h5 class="commission-title">${escapeHtml(commission.name || 'Комиссия')}</h5>
            <p class="text-muted small mb-0">${escapeHtml(commission.description || 'Комиссия Общественного Совета')}</p>
            ${canWrite() ? `<div class="mt-3"><button type="button" class="btn btn-sm btn-outline-primary" data-edit-commission="${commission.id}"><i class="bi bi-pencil"></i> Изменить</button></div>` : ''}
          </div>
        </div>
      </div>
    `;
  }).join('');

  if (canWrite()) {
    grid.querySelectorAll('[data-edit-commission]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const commission = commissionsCatalog.find((c) => String(c.id) === btn.dataset.editCommission);
        if (commission) editCommission(commission);
      });
    });
  }
}

function getPendingLettersSummary() {
  const today = new Date();
  let overdue = 0;
  let warning = 0;
  let pending = 0;
  (store.incoming || []).filter((item) => !item.linkedOutgoingId).forEach((item) => {
    pending += 1;
    const due = addWorkingDays(item.date, 15);
    const daysLeft = Math.ceil((due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
    if (daysLeft < 0) {
      overdue += 1;
      return;
    }
    const warnFrom = subtractWorkingDays(due, 3);
    if (today >= warnFrom && today <= due) warning += 1;
  });
  return { pending, overdue, warning };
}

function updateNotifyBadge() {
  const badge = document.getElementById('notifyBadge');
  const { overdue, warning, pending } = getPendingLettersSummary();
  const alertCount = overdue + warning;
  if (badge) {
    if (alertCount > 0) {
      badge.textContent = String(alertCount);
      badge.classList.remove('d-none');
      badge.classList.toggle('notify-badge--warning', overdue === 0 && warning > 0);
    } else {
      badge.classList.add('d-none');
      badge.classList.remove('notify-badge--warning');
    }
  }

  const alerts = document.getElementById('dashboardAlerts');
  if (!alerts) return;
  if (pending === 0) {
    alerts.classList.add('d-none');
    alerts.innerHTML = '';
    return;
  }
  alerts.classList.remove('d-none');
  alerts.innerHTML = `
    <span><strong>${pending}</strong> писем без ответа</span>
    ${overdue ? `<span class="badge badge-due-danger">${overdue} просрочено</span>` : ''}
    ${warning ? `<span class="badge badge-due">${warning} скоро срок</span>` : ''}
    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="dashViewPending">Открыть журнал</button>
  `;
  document.getElementById('dashViewPending')?.addEventListener('click', () => {
    document.getElementById('tab-incoming')?.click();
  });
}

function renderTableFooter(elementId, shown, total, label = 'записей') {
  const el = document.getElementById(elementId);
  if (!el) return;
  if (total === 0) {
    el.innerHTML = '<span class="text-muted">Нет записей для отображения</span>';
    return;
  }
  el.innerHTML = `<span>Показано <strong>${shown}</strong> из <strong>${total}</strong> ${label}</span>`;
}

function scrollToCollapse(targetSelector) {
  const target = document.querySelector(targetSelector);
  if (!target) return;
  if (target.classList.contains('collapse') && !target.classList.contains('show')) {
    bootstrap.Collapse.getOrCreateInstance(target).show();
  }
  target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function updatePageContextActions(tabId) {
  const container = document.getElementById('pageContextActions');
  if (!container) return;

  const configs = {
    'tab-incoming': [
      { id: 'ctxAddIncoming', label: '+ Добавить письмо', primary: true, target: '#collapseIncomingForm' },
      { id: 'ctxShowPending', label: 'Без ответа', primary: false, action: 'pending' }
    ],
    'tab-outgoing': [
      { id: 'ctxAddOutgoing', label: '+ Ответ ОС', primary: true, target: '#collapseOutgoingForm' }
    ],
    'tab-events': [
      { id: 'ctxAddEvent', label: '+ Мероприятие', primary: true, target: '#formEvent' }
    ]
  };

  const actions = configs[tabId] || [];
  container.innerHTML = actions.map((action) =>
    `<button type="button" class="btn btn-sm ${action.primary ? 'btn-primary' : 'btn-outline-secondary'}" id="${action.id}">${action.label}</button>`
  ).join('');

  document.getElementById('ctxAddIncoming')?.addEventListener('click', () => scrollToCollapse('#collapseIncomingForm'));
  document.getElementById('ctxAddOutgoing')?.addEventListener('click', () => scrollToCollapse('#collapseOutgoingForm'));
  document.getElementById('ctxAddEvent')?.addEventListener('click', () => {
    document.getElementById('formEvent')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  document.getElementById('ctxShowPending')?.addEventListener('click', () => {
    document.getElementById('notifyBtn')?.click();
  });
}

const PAGE_META = {
  'tab-dashboard': {
    title: 'Статистика обращений',
    subtitle: 'Ключевые показатели и динамика обращений'
  },
  'tab-incoming': { title: 'Входящий журнал', subtitle: '' },
  'tab-outgoing': { title: 'Исходящий журнал', subtitle: 'Ответы Общественного Совета' },
  'tab-members': { title: 'Члены Общественного Совета', subtitle: '' },
  'tab-commissions': { title: 'Комиссии', subtitle: 'Структура Общественного Совета' },
  'tab-kpi': { title: 'KPI', subtitle: 'Показатели работы членов и комиссий' },
  'tab-events': { title: 'Мероприятия', subtitle: 'Учёт участия членов ОС' }
};

function updatePageHeader(tabId) {
  const meta = PAGE_META[tabId] || { title: 'Журнал ОС', subtitle: '' };
  const titleEl = document.getElementById('pageTitle');
  const subtitleEl = document.getElementById('pageSubtitle');
  if (titleEl) titleEl.textContent = meta.title;

  let subtitle = meta.subtitle;
  if (tabId === 'tab-incoming') {
    subtitle = `${store.incoming.length} писем · срок ответа 15 рабочих дней`;
  } else if (tabId === 'tab-outgoing') {
    subtitle = `${store.outgoing.length} писем`;
  } else if (tabId === 'tab-members') {
    subtitle = `${membersCatalog.length} членов · ${commissionsCatalog.length} комиссий`;
  }
  if (subtitleEl) subtitleEl.textContent = subtitle;
  updatePageContextActions(tabId);
}

function initAppNavigation() {
  const sidebarEl = document.getElementById('sidebar');
  const backdropEl = document.getElementById('sidebarBackdrop');

  const closeSidebar = () => {
    sidebarEl?.classList.remove('show');
    backdropEl?.classList.remove('show');
  };

  const openSidebar = () => {
    sidebarEl?.classList.add('show');
    backdropEl?.classList.add('show');
  };

  document.querySelectorAll('[data-bs-toggle="tab"]').forEach((btn) => {
    btn.addEventListener('shown.bs.tab', (event) => {
      updatePageHeader(event.target.id);
      if (event.target.id === 'tab-members') renderMembersGrid();
      if (event.target.id === 'tab-commissions') renderCommissionsGrid();
      closeSidebar();
    });
  });

  document.getElementById('sidebarToggle')?.addEventListener('click', () => {
    if (sidebarEl?.classList.contains('show')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  });
  backdropEl?.addEventListener('click', closeSidebar);

  updatePageHeader('tab-dashboard');
}

async function renderCommissionsHighlights(kpi) {
  const grid = document.getElementById('commissionsKPI');
  const cardWrapper = document.getElementById('commissionsCard');
  if (!grid) return;
  let collection = Array.isArray(kpi?.commissions) ? [...kpi.commissions] : [];

  if (!collection.length && Array.isArray(kpi?.members)) {
    const map = new Map();
    kpi.members.forEach(member => {
      const key = member.commission_name || 'Без комиссии';
      if (!map.has(key)) {
        map.set(key, {
          name: key,
          outgoing_count: 0,
          incoming_count: 0,
          members_count: 0,
          lead_name: null,
          color: member.commission_color || '#4f46e5'
        });
      }
      const entry = map.get(key);
      entry.outgoing_count += Number(member.outgoing_count || 0);
      entry.incoming_count += Number(member.incoming_count || 0);
      entry.members_count += 1;
      if (!entry.lead_name && member.full_name) {
        entry.lead_name = member.full_name;
      }
      if ((!entry.color || entry.color === '#4f46e5') && member.commission_color) {
        entry.color = member.commission_color;
      }
    });
    collection = Array.from(map.values());
  }

  if (!collection.length) {
    try {
      const resp = await fetch(`${API_BASE}/commissions.php`);
      if (resp.ok) {
        const data = await resp.json();
        collection = Array.isArray(data) ? data.map(c => ({
          name: c.name,
          outgoing_count: Number(c.outgoing_count || 0),
          incoming_count: Number(c.incoming_count || 0),
          members_count: Number(c.members_count || 0),
          lead_name: c.lead_name || null,
          color: c.color || '#4f46e5'
        })) : [];
      }
    } catch (err) {
      console.warn('Не удалось загрузить комиссии', err);
    }
  }

  if (!collection.length) {
    grid.innerHTML = '<div class="text-center text-muted py-4">Нет данных по комиссиям</div>';
    if (cardWrapper) cardWrapper.classList.remove('d-none');
    return;
  }

  if (cardWrapper) cardWrapper.classList.remove('d-none');

  const ordered = collection.sort((a, b) => {
    const totalA = Number(a.outgoing_count || 0) + Number(a.incoming_count || 0);
    const totalB = Number(b.outgoing_count || 0) + Number(b.incoming_count || 0);
    return totalB - totalA;
  }).slice(0, 6);

  grid.innerHTML = ordered.map((comm) => {
    const outgoing = Number(comm.outgoing_count || 0);
    const incoming = Number(comm.incoming_count || 0);
    const members = Number(comm.members_count || comm.member_count || 0);
    const total = outgoing + incoming;
    const inPct = total ? Math.round((incoming / total) * 100) : 0;
    const outPct = total ? 100 - inPct : 0;
    const leader = comm.lead_name ? escapeHtml(comm.lead_name) : '—';
    return `
      <div class="dash-commission">
        <div class="dash-commission__head">
          <div>
            <div class="dash-commission__name">${escapeHtml(comm.name || 'Комиссия')}</div>
            <div class="dash-commission__meta">${members ? `${members} членов` : 'Без данных'} · ${leader}</div>
          </div>
          <span class="badge commission-count">${total} писем</span>
        </div>
        <div class="dash-commission__bar">
          ${total ? `<span class="dash-commission__seg dash-commission__seg--in" style="width:${inPct}%"></span><span class="dash-commission__seg dash-commission__seg--out" style="width:${outPct}%"></span>` : ''}
        </div>
        <div class="dash-commission__counts">
          <span>Входящих: ${incoming}</span>
          <span>Исходящих: ${outgoing}</span>
        </div>
      </div>
    `;
  }).join('');
}

function populateMemberSelect(selectEl) {
  if (!selectEl) return;
  const previousSelection = Array.from(selectEl.selectedOptions).map(opt => Number(opt.value));
  selectEl.innerHTML = '';
  const fragment = document.createDocumentFragment();
  membersCatalog.forEach(member => {
    const option = document.createElement('option');
    option.value = member.id;
    option.textContent = member.full_name + (member.commission_name ? ` — ${member.commission_name}` : '');
    if (previousSelection.includes(member.id)) option.selected = true;
    fragment.appendChild(option);
  });
  selectEl.appendChild(fragment);
}

function populateMemberSelects() {
  populateMemberSelect(document.getElementById('incMembers'));
  populateMemberSelect(document.getElementById('outMembers'));
}

function clearMultiselect(selectEl) {
  if (!selectEl) return;
  Array.from(selectEl.options).forEach(opt => opt.selected = false);
}

async function refreshLetters() {
  const [incoming, outgoing] = await Promise.all([fetchLetters('incoming'), fetchLetters('outgoing')]);
  store.incoming = incoming;
  store.outgoing = outgoing;
}

async function fetchLetters(type) {
  const response = await fetch(`${API_BASE}/letters.php?type=${type}`);
  if (!response.ok) throw new Error(`Не удалось загрузить ${type} письма`);
  const data = await response.json();
  return data.map(mapLetterFromApi(type));
}

function mapLetterFromApi(type) {
  return (item) => ({
    id: item.id,
    seq: Number(item.seq),
    date: item.date,
    organization: item.organization,
    kkNumber: item.kk_number ?? '',
    category: item.category ?? 'KK',
    subject: item.subject ?? '',
    note: item.note ?? '',
    scansCount: Number(item.scans_count ?? 0),
    scans: item.scans ?? null,
    members: item.members || [],
    linkedOutgoingId: item.linked_outgoing_id ?? null,
    incomingRefId: item.incoming_ref_id ?? null,
    outgoingNumber: item.outgoing_number ?? '',
    outgoingType: item.outgoing_type || 'gov',
    regionId: item.region_id ?? null,
    createdBy: item.created_by ?? null,
    letterType: type,
    recipients: item.recipients || []
  });
}

function getSelectedMembers(selectEl) {
  if (!selectEl) return [];
  return Array.from(selectEl.selectedOptions).map((option, index) => ({
    member_id: Number(option.value),
    is_lead: index === 0
  }));
}

function getNextIncomingSeq() {
  const maxSeq = store.incoming.reduce((max, item) => {
    const value = Number(item.seq) || 0;
    return value > max ? value : max;
  }, 0);
  return maxSeq + 1;
}

function updateIncomingSeqPlaceholder() {
  if (!incSeq) return;
  if (incSeq.value) return;
  const nextSeq = getNextIncomingSeq();
  incSeq.placeholder = incRespondsOutgoing?.value ? `Авто: ${nextSeq}` : 'Авто';
}

function autoFillIncomingNumber(force = false) {
  if (!incRespondsOutgoing || !incNumber) return;
  const selectedId = incRespondsOutgoing.value;
  if (!selectedId) {
    lastAutoIncomingNumber = '';
    updateIncomingSeqPlaceholder();
    return;
  }
  const outgoing = store.outgoing.find(o => String(o.id) === String(selectedId));
  if (!outgoing) return;
  const baseNumber = outgoing.outgoingNumber || `Исх.${outgoing.seq}`;
  const seqValue = incSeq?.value ? Number(incSeq.value) : getNextIncomingSeq();
  if (!seqValue || !baseNumber) return;
  const composed = `${seqValue}/${baseNumber}`;
  const shouldUpdate = force || !incNumber.value || incNumber.value === lastAutoIncomingNumber;
  if (shouldUpdate) {
    incNumber.value = composed;
    lastAutoIncomingNumber = composed;
  } else {
    lastAutoIncomingNumber = composed;
  }
  if (incSeq && !incSeq.value) {
    incSeq.value = seqValue;
  }
  updateIncomingSeqPlaceholder();
}

function mapMemberForUpdate(member) {
  if (!member) return null;
  const id = Number(member.member_id ?? member.id);
  if (!id) return null;
  return {
    member_id: id,
    is_lead: !!(member.is_lead && Number(member.is_lead))
  };
}

function updateIncomingLinkVisibility() {
  if (!incRespondsOutgoing || !incLinkToggle) return;
  const hasValue = !!incRespondsOutgoing.value;
  incLinkToggle.checked = hasValue;
  incRespondsOutgoing.classList.toggle('d-none', !hasValue);
}

function applySelectedOutgoingForForm(outgoingId) {
  if (!incRespondsOutgoing) return;
  incRespondsOutgoing.value = String(outgoingId);
  updateIncomingLinkVisibility();
  autoFillIncomingNumber(true);
  if (linkOutgoingModalInstance) {
    linkOutgoingModalInstance.hide();
  }
}

async function handleIncomingSubmit(event) {
  event.preventDefault();
  try {
    autoFillIncomingNumber(false);
    const respondsId = incRespondsOutgoing?.value ? Number(incRespondsOutgoing.value) : null;
    const seqValue = incSeq.value ? Number(incSeq.value) : getNextIncomingSeq();
    const payload = {
      seq: seqValue,
      date: incDate.value,
      organization: incOrg.value.trim(),
      kk_number: incNumber.value.trim(),
      category: incType?.value || 'KK',
      subject: incSubject.value.trim(),
      note: incNote.value.trim(),
      members: getSelectedMembers(document.getElementById('incMembers')),
      scans: [...pendingIncomingScans],
      responds_to_outgoing_id: respondsId
    };
    const recipientsList = incomingRecipients.length ? [...incomingRecipients] : (payload.organization ? [payload.organization] : []);
    payload.recipients = recipientsList;
    if (!payload.organization && recipientsList.length) {
      payload.organization = recipientsList[0];
    }
    const user = getSessionUser();
    if (user?.region?.id) {
      payload.region_id = user.region.id;
    }
    const editId = formIncoming?.dataset?.editId;
    if (editId) {
      payload.id = Number(editId);
      const del = JSON.parse(formIncoming.dataset.deleteScanIds || '[]');
      if (del.length) payload.delete_scan_ids = del;
      await updateLetter('incoming', payload);
    } else {
      await createLetter('incoming', payload);
    }
    formIncoming.reset();
    incDate.value = todayISO;
    pendingIncomingScans = [];
    if (incScansPreview) incScansPreview.innerHTML = '';
    clearMultiselect(document.getElementById('incMembers'));
    if (formIncoming) { delete formIncoming.dataset.editId; delete formIncoming.dataset.deleteScanIds; }
    incomingRecipients = [];
    renderIncomingRecipients();
    if (incRecipientInput) incRecipientInput.value = '';
    await refreshLetters();
    renderAll();
    if (window.showSuccess) {
      showSuccess('Входящее письмо успешно сохранено');
    }
  } catch (error) {
    console.error('Ошибка добавления входящего письма', error);
    if (window.showError) {
      showError('Не удалось сохранить входящее письмо');
    } else {
      alert('Не удалось сохранить входящее письмо');
    }
  }
}

async function handleOutgoingSubmit(event) {
  event.preventDefault();
  try {
    const incomingId = outLinkedIncoming.value;
    const incomingItem = store.incoming.find((i) => String(i.id) === String(incomingId));
    const manualNumber = outNumber.value.trim();
    const selectedType = outType?.value || 'gov';
    let recipientsList = outgoingRecipients.length ? [...outgoingRecipients] : [];
    if (!recipientsList.length && incomingItem?.organization) {
      recipientsList = [incomingItem.organization];
    }
    if (!recipientsList.length) {
      if (window.showWarning) {
        showWarning('Добавьте хотя бы одного адресата или укажите организацию');
      } else {
        alert('Добавьте хотя бы одного адресата или укажите организацию');
      }
      return;
    }
    const organization = (incomingItem?.organization || recipientsList[0] || '').trim();
    if (!organization) {
      if (window.showWarning) {
        showWarning('Укажите организацию получателя');
      } else {
        alert('Укажите организацию получателя');
      }
      return;
    }
    const payload = {
      seq: null,
      date: outDate.value,
      outgoing_number: manualNumber || null,
      organization,
      incoming_ref_id: incomingItem ? incomingItem.id : null,
      subject: outSubject.value.trim(),
      note: outNote.value.trim(),
      members: getSelectedMembers(document.getElementById('outMembers')),
      scans: [...pendingOutgoingScans],
      outgoing_type: selectedType
    };
    payload.recipients = recipientsList;
    const user = getSessionUser();
    if (user?.region?.id) {
      payload.region_id = user.region.id;
    }
    const editId = formOutgoing?.dataset?.editId;
    if (editId) {
      payload.id = Number(editId);
      const del = JSON.parse(formOutgoing.dataset.deleteScanIds || '[]');
      if (del.length) payload.delete_scan_ids = del;
      await updateLetter('outgoing', payload);
    } else {
      await createLetter('outgoing', payload);
    }
    formOutgoing.reset();
    outDate.value = todayISO;
    pendingOutgoingScans = [];
    if (outScansPreview) outScansPreview.innerHTML = '';
    clearMultiselect(document.getElementById('outMembers'));
    if (formOutgoing) { delete formOutgoing.dataset.editId; delete formOutgoing.dataset.deleteScanIds; }
    outgoingRecipients = incomingItem ? [incomingItem.organization].filter(Boolean) : [];
    renderOutgoingRecipients();
    if (outRecipientInput) outRecipientInput.value = '';
    await refreshLetters();
    renderAll();
    if (window.showSuccess) {
      showSuccess('Исходящее письмо успешно сохранено');
    }
  } catch (error) {
    console.error('Ошибка добавления исходящего письма', error);
    if (window.showError) {
      showError('Не удалось сохранить исходящее письмо');
    } else {
      alert('Не удалось сохранить исходящее письмо');
    }
  }
}

async function createLetter(type, payload) {
  const response = await fetch(`${API_BASE}/letters.php?type=${type}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  if (!response.ok) {
    const err = await response.json().catch(() => ({}));
    throw new Error(err.error || 'Ошибка сохранения письма');
  }
  letterDetailsCache[type].clear();
  return response.json();
}

async function deleteLetter(type, id) {
  const response = await fetch(`${API_BASE}/letters.php?type=${type}&id=${id}`, {
    method: 'DELETE'
  });
  if (!response.ok) {
    const err = await response.json().catch(() => ({}));
    throw new Error(err.error || 'Ошибка удаления письма');
  }
  letterDetailsCache[type].delete(Number(id));
  await refreshLetters();
  renderAll();
}

async function fetchLetterDetail(type, id) {
  const cache = letterDetailsCache[type];
  if (cache.has(id)) {
    return cache.get(id);
  }
  const response = await fetch(`${API_BASE}/letters.php?type=${type}&id=${id}`);
  if (!response.ok) throw new Error('Не удалось загрузить письмо');
  const data = await response.json();
  cache.set(id, data);
  return data;
}

async function updateLetter(type, payload) {
  const response = await fetch(`${API_BASE}/letters.php?type=${type}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  if (!response.ok) {
    const err = await response.json().catch(() => ({}));
    throw new Error(err.error || 'Ошибка обновления письма');
  }
  letterDetailsCache[type].delete(Number(payload.id));
  return response.json();
}

async function editIncoming(id) {
  try {
    const detail = await fetchLetterDetail('incoming', Number(id));
    if (!formIncoming) return;
    incSeq.value = String(detail.seq ?? '');
    incDate.value = detail.date?.slice(0,10) || todayISO;
    incOrg.value = detail.organization || '';
    incNumber.value = detail.kk_number || '';
    if (incType) incType.value = detail.category || 'KK';
    if (incRespondsOutgoing) incRespondsOutgoing.value = detail.responds_to_outgoing_id ? String(detail.responds_to_outgoing_id) : '';
    updateIncomingLinkVisibility();
    lastAutoIncomingNumber = '';
    updateIncomingSeqPlaceholder();
    incSubject.value = detail.subject || '';
    incNote.value = detail.note || '';
    const incMembersSelect = document.getElementById('incMembers');
    if (incMembersSelect) {
      Array.from(incMembersSelect.options).forEach(opt => {
        opt.selected = (detail.members || []).some(m => String(m.member_id || m.id) === String(opt.value));
      });
    }
    pendingIncomingScans = [];
    formIncoming.dataset.editId = String(id);
    formIncoming.dataset.deleteScanIds = JSON.stringify([]);
    incomingRecipients = (detail.recipients || []).slice();
    if (!incomingRecipients.length && detail.organization) incomingRecipients = [detail.organization];
    renderIncomingRecipients();
    if (incScansPreview) {
      incScansPreview.innerHTML = '';
      (detail.scans || []).forEach(scan => {
        const wrapper = document.createElement('div');
        wrapper.className = 'scan-preview scan-preview-form';
        if ((scan.scan_type || '').startsWith('application/pdf')) {
          wrapper.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:#f0f0f0;flex-direction:column;"><small>PDF</small></div>`;
        } else {
          const img = document.createElement('img');
          img.src = scan.scan_data;
          img.alt = scan.file_name || 'scan';
          wrapper.appendChild(img);
        }
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'remove-scan';
        rm.textContent = '×';
        rm.onclick = () => {
          const list = JSON.parse(formIncoming.dataset.deleteScanIds || '[]');
          if (!list.includes(scan.id)) list.push(scan.id);
          formIncoming.dataset.deleteScanIds = JSON.stringify(list);
          wrapper.remove();
        };
        wrapper.appendChild(rm);
        incScansPreview.appendChild(wrapper);
      });
    }
  } catch (e) {
    console.error('Ошибка редактирования входящего', e);
    alert('Не удалось загрузить письмо для редактирования');
  }
}

async function editOutgoing(id) {
  try {
    const detail = await fetchLetterDetail('outgoing', Number(id));
    if (!formOutgoing) return;
    outDate.value = detail.date?.slice(0,10) || todayISO;
    outLinkedIncoming.value = detail.incoming_ref_id ? String(detail.incoming_ref_id) : '';
    if (outType) outType.value = detail.outgoing_type || 'gov';
    outNumber.value = detail.outgoing_number || '';
    outSubject.value = detail.subject || '';
    outNote.value = detail.note || '';
    const outMembersSelect = document.getElementById('outMembers');
    if (outMembersSelect) {
      Array.from(outMembersSelect.options).forEach(opt => {
        opt.selected = (detail.members || []).some(m => String(m.member_id || m.id) === String(opt.value));
      });
    }
    pendingOutgoingScans = [];
    formOutgoing.dataset.editId = String(id);
    formOutgoing.dataset.deleteScanIds = JSON.stringify([]);
    outgoingRecipients = (detail.recipients || []).slice();
    if (!outgoingRecipients.length && detail.organization) outgoingRecipients = [detail.organization];
    renderOutgoingRecipients();
    if (outScansPreview) {
      outScansPreview.innerHTML = '';
      (detail.scans || []).forEach(scan => {
        const wrapper = document.createElement('div');
        wrapper.className = 'scan-preview scan-preview-form';
        if ((scan.scan_type || '').startsWith('application/pdf')) {
          wrapper.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:#f0f0f0;flex-direction:column;"><small>PDF</small></div>`;
        } else {
          const img = document.createElement('img');
          img.src = scan.scan_data;
          img.alt = scan.file_name || 'scan';
          wrapper.appendChild(img);
        }
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'remove-scan';
        rm.textContent = '×';
        rm.onclick = () => {
          const list = JSON.parse(formOutgoing.dataset.deleteScanIds || '[]');
          if (!list.includes(scan.id)) list.push(scan.id);
          formOutgoing.dataset.deleteScanIds = JSON.stringify(list);
          wrapper.remove();
        };
        wrapper.appendChild(rm);
        outScansPreview.appendChild(wrapper);
      });
    }
  } catch (e) {
    console.error('Ошибка редактирования исходящего', e);
    alert('Не удалось загрузить письмо для редактирования');
  }
}


// Debounce function for optimization
function debounce(func, wait) {
  let timeout;
  return function(...args) {
    const context = this;
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(context, args), wait);
  };
}

// Поиск / фильтры
if (searchIncoming) searchIncoming.addEventListener("input", debounce(() => renderIncoming(), 300));
if (searchOutgoing) searchOutgoing.addEventListener("input", debounce(() => renderOutgoing(), 300));
if (searchEvents) searchEvents.addEventListener("input", debounce(() => renderEvents(), 300));

if (filterYearIncoming) filterYearIncoming.addEventListener("change", () => renderIncoming());
if (filterYearOutgoing) filterYearOutgoing.addEventListener("change", () => renderOutgoing());
if (filterMonthIncoming) filterMonthIncoming.addEventListener("change", () => renderIncoming());
if (filterMonthOutgoing) filterMonthOutgoing.addEventListener("change", () => renderOutgoing());
if (filterStatusIncoming) filterStatusIncoming.addEventListener("change", () => renderIncoming());
if (filterScansIncoming) filterScansIncoming.addEventListener("change", () => renderIncoming());
if (filterScansOutgoing) filterScansOutgoing.addEventListener("change", () => renderOutgoing());
if (filterRecipientIncoming) filterRecipientIncoming.addEventListener("change", () => renderIncoming());
if (filterRecipientOutgoing) filterRecipientOutgoing.addEventListener("change", () => renderOutgoing());
if (dashboardPeriodSelect) dashboardPeriodSelect.addEventListener('change', () => renderCharts());
const filterMembersCommission = document.getElementById('filterMembersCommission');
if (filterMembersCommission) filterMembersCommission.addEventListener('change', () => renderMembersGrid());

// Экспорт / импорт
if (exportJsonBtn) exportJsonBtn.addEventListener("click", exportJson);
if (exportCsvBtn) exportCsvBtn.addEventListener("click", exportCsv);
if (importJsonInput) importJsonInput.addEventListener("change", importJson);

// Рендер
function renderAll() {
  renderIncomingOptions();
  renderIncoming();
  updateIncomingSeqPlaceholder();
  autoFillIncomingNumber(false);
  renderOutgoing();
  renderKPIs();
  renderCharts();
  renderEvents();
}

function renderIncomingOptions() {
  const sortedIncoming = [...store.incoming].sort((a, b) => a.seq - b.seq);
  if (outLinkedIncoming) {
    const prevValue = outLinkedIncoming.value;
    const options = [
      '<option value="">Без связи (самостоятельное письмо)</option>'
    ];
    sortedIncoming.forEach((i) => {
      const closed = i.linkedOutgoingId ? " (ответ отправлен)" : "";
      const cat = i.category || "KK";
      options.push(
        `<option value="${i.id}">[${cat}] Вх.${i.seq} • ${formatDateISOtoRus(i.date)} • ${i.organization} • ${i.kkNumber}${closed}</option>`
      );
    });
    outLinkedIncoming.innerHTML = options.join("");
    if (prevValue && sortedIncoming.some(i => String(i.id) === String(prevValue))) {
      outLinkedIncoming.value = prevValue;
    }
    updateOutgoingPreview();
  }

  if (incRespondsOutgoing) {
    const prev = incRespondsOutgoing.value;
    const options = ['<option value="">Не привязывать</option>'];
    const sortedOutgoing = [...store.outgoing].sort((a, b) => b.seq - a.seq);
    sortedOutgoing.forEach((o) => {
      const label = o.outgoingNumber || `Исх.${o.seq}`;
      options.push(`<option value="${o.id}">Исх.${o.seq} • ${formatDateISOtoRus(o.date)} • ${escapeHtml(o.organization || '')} • ${escapeHtml(label)}</option>`);
    });
    incRespondsOutgoing.innerHTML = options.join('');
    if (prev && sortedOutgoing.some(o => String(o.id) === String(prev))) {
      incRespondsOutgoing.value = prev;
    }
    updateIncomingLinkVisibility();
  }
}

function updateOutgoingPreview() {
  const incoming = store.incoming.find((i) => String(i.id) === String(outLinkedIncoming.value));
  if (!incoming) {
    return;
  }
  const nextSeq = store.outgoing.reduce((max, item) => Math.max(max, Number(item.seq) || 0), 0) + 1;
  if (outNumber) {
    outNumber.value = incoming.kkNumber ? `${nextSeq}/${incoming.kkNumber}` : String(nextSeq);
  }
}

function getYears(items) {
  const years = unique(items.map((x) => new Date(x.date).getFullYear()).filter(Boolean)).sort();
  return ["Все годы", ...years];
}

function parseRealtimePayload(payload) {
  if (!payload) return {};
  if (typeof payload === 'string') {
    try {
      return JSON.parse(payload);
    } catch (err) {
      console.warn('Failed to parse realtime payload', err);
      return {};
    }
  }
  return payload;
}

function showDeadlineNotification(data) {
  const seqLabel = data.seq ? `Вх.${data.seq}` : `ID ${data.id}`;
  const dueText = data.due_date ? new Date(data.due_date).toLocaleDateString('ru-RU') : null;
  let message = `${seqLabel}: `;
  if (data.status === 'overdue') {
    const days = Math.abs(Number(data.days_left || 0));
    message += `срок просрочен на ${days} дн.`;
  } else {
    const days = Math.max(0, Number(data.days_left || 0));
    message += `до дедлайна ${days} дн.`;
  }
  if (dueText) {
    message += ` (срок: ${dueText})`;
  }
  if (data.organization) {
    message += ` • ${data.organization}`;
  }
  if (window.showWarning) {
    showWarning(message);
  } else {
    alert(message);
  }
}

function initRealtime() {
  try {
    if (!window.Pusher || !window.PUSHER_KEY) {
      console.warn('Pusher is not configured');
      return;
    }
    pusherClient = new Pusher(window.PUSHER_KEY, {
      cluster: window.PUSHER_CLUSTER || 'eu',
      forceTLS: true
    });
    const documentsChannelName = window.PUSHER_CHANNEL_DOCUMENTS || 'council-documents';
    const eventsChannelName = window.PUSHER_CHANNEL_EVENTS || 'council-events';
    const deadlinesChannelName = window.PUSHER_CHANNEL_DEADLINES || 'council-deadlines';

    const docsChannel = pusherClient.subscribe(documentsChannelName);
    docsChannel.bind('documents-updated', async (payload) => {
      const data = parseRealtimePayload(payload);
      await refreshLetters();
      renderAll();
      if (data && data.action && window.showInfo) {
        const typeText = data.type === 'incoming' ? 'Входящее письмо' : 'Исходящее письмо';
        const actionText = data.action === 'create' ? 'добавлено' :
                           data.action === 'update' ? 'обновлено' : 'удалено';
        showInfo(`${typeText} ${actionText} другим пользователем`);
      }
    });

    const eventsChannel = pusherClient.subscribe(eventsChannelName);
    eventsChannel.bind('events-updated', async (payload) => {
      const data = parseRealtimePayload(payload);
      await refreshEvents();
      renderEvents();
      if (data && data.action && window.showInfo) {
        const actionText = data.action === 'create' ? 'создано' :
                           data.action === 'update' ? 'обновлено' : 'удалено';
        showInfo(`Мероприятие ${actionText} другим пользователем`);
      }
    });

    const deadlinesChannel = pusherClient.subscribe(deadlinesChannelName);
    deadlinesChannel.bind('deadline-warning', (payload) => {
      const data = parseRealtimePayload(payload);
      if (!data) return;
      showDeadlineNotification(data);
    });
  } catch (e) {
    console.warn('Realtime disabled:', e);
  }
}
// ====== Events API ======
async function refreshEvents() {
  const resp = await fetch(`${API_BASE}/events.php`);
  if (!resp.ok) throw new Error('Не удалось загрузить мероприятия');
  const data = await resp.json();
  store.events = Array.isArray(data) ? data : (data.items || []);
}

function kpiFromForm() {
  const rows = Array.from(kpiList.querySelectorAll('.kpi-row'));
  return rows.map(row => {
    const metric = row.querySelector('input[name="metric"]').value.trim();
    const valueNumeric = row.querySelector('input[name="value_numeric"]').value;
    const valueText = row.querySelector('input[name="value_text"]').value.trim();
    const vn = valueNumeric !== '' ? Number(valueNumeric) : null;
    return { metric, value_numeric: vn, value_text: valueText || null };
  }).filter(k => k.metric);
}

function addKpiRow(metric = '', valueNumeric = '', valueText = '') {
  const wrapper = document.createElement('div');
  wrapper.className = 'kpi-row col-12 d-flex gap-2';
  wrapper.innerHTML = `
    <input class="form-control form-control-sm" name="metric" placeholder="Метрика (например, «Регистрации»)" value="${metric}"/>
    <input class="form-control form-control-sm" name="value_numeric" type="number" step="0.01" placeholder="Число" value="${valueNumeric}"/>
    <input class="form-control form-control-sm" name="value_text" placeholder="Текст (опц.)" value="${valueText}"/>
    <button type="button" class="btn btn-sm btn-outline-danger">×</button>
  `;
  wrapper.querySelector('button').onclick = () => wrapper.remove();
  kpiList.appendChild(wrapper);
}

function attendeesFromForm() {
  if (!attChecklist) return [];
  const checks = Array.from(attChecklist.querySelectorAll('input.att-item'));
  return checks.map(ch => ({ full_name: ch.dataset.name, attended: ch.checked }));
}

function renderAttendeesChecklist(selectedNames = []) {
  if (!attChecklist) return;
  const byCommission = new Map();
  membersCatalog.forEach(m => {
    const key = m.commission_name || 'Без комиссии';
    if (!byCommission.has(key)) byCommission.set(key, []);
    byCommission.get(key).push(m);
  });
  attChecklist.innerHTML = '';
  byCommission.forEach((list, commission) => {
    const col = document.createElement('div');
    col.className = 'col-12 col-md-6';
    const card = document.createElement('div');
    card.className = 'p-2 border rounded';
    const title = document.createElement('div');
    title.className = 'mb-1 fw-semibold d-flex align-items-center gap-2';
    const color = list[0]?.commission_color || '#0d6efd';
    const dot = document.createElement('span');
    dot.style.width = '10px';
    dot.style.height = '10px';
    dot.style.borderRadius = '50%';
    dot.style.background = color;
    title.appendChild(dot);
    title.appendChild(document.createTextNode(commission));
    card.appendChild(title);
    list.sort((a,b)=> a.full_name.localeCompare(b.full_name)).forEach(m => {
      const id = `att_${m.id}`;
      const wrap = document.createElement('div');
      wrap.className = 'form-check';
      wrap.innerHTML = `
        <input class="form-check-input att-item" type="checkbox" id="${id}" data-name="${escapeHtml(m.full_name)}">
        <label class="form-check-label" for="${id}">${escapeHtml(m.full_name)}</label>
      `;
      const input = wrap.querySelector('input');
      input.checked = selectedNames.includes(m.full_name);
      card.appendChild(wrap);
    });
    col.appendChild(card);
    attChecklist.appendChild(col);
  });
}

function renderIncomingRecipients() {
  if (!incRecipientsList) return;
  incRecipientsList.innerHTML = '';
  if (incomingRecipients.length === 0) {
    incRecipientsList.innerHTML = '<span class="text-muted small">Нет адресатов</span>';
    return;
  }
  incomingRecipients.forEach((name, index) => {
    const chip = document.createElement('span');
    chip.className = 'badge bg-light text-dark border me-2 mb-2';
    chip.innerHTML = `${escapeHtml(name)} <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" data-index="${index}">×</button>`;
    incRecipientsList.appendChild(chip);
  });
  incRecipientsList.querySelectorAll('button[data-index]').forEach(btn => {
    btn.addEventListener('click', () => {
      const idx = Number(btn.dataset.index);
      incomingRecipients.splice(idx, 1);
      renderIncomingRecipients();
    });
  });
  if (incOrg) {
    const original = incOrg.value.trim();
    const target = incomingRecipients[0] || original;
    incOrg.value = target || '';
  }
}

function renderOutgoingRecipients() {
  if (!outRecipientsList) return;
  outRecipientsList.innerHTML = '';
  if (outgoingRecipients.length === 0) {
    outRecipientsList.innerHTML = '<span class="text-muted small">Нет адресатов</span>';
    return;
  }
  outgoingRecipients.forEach((name, index) => {
    const chip = document.createElement('span');
    chip.className = 'badge bg-light text-dark border me-2 mb-2';
    chip.innerHTML = `${escapeHtml(name)} <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1" data-index="${index}">×</button>`;
    outRecipientsList.appendChild(chip);
  });
  outRecipientsList.querySelectorAll('button[data-index]').forEach(btn => {
    btn.addEventListener('click', () => {
      const idx = Number(btn.dataset.index);
      outgoingRecipients.splice(idx, 1);
      renderOutgoingRecipients();
    });
  });
}

function setAllAttendees(checked) {
  if (!attChecklist) return;
  attChecklist.querySelectorAll('input.att-item').forEach(ch => ch.checked = !!checked);
}

function openOutgoingTab() {
  document.getElementById('tab-outgoing')?.click();
  document.getElementById('pane-outgoing')?.scrollIntoView({ behavior: 'smooth' });
  setTimeout(() => {
    outLinkedIncoming?.focus();
  }, 200);
}

async function handleEventSubmit(e) {
  e.preventDefault();
  const payload = {
    title: evTitle.value.trim(),
    event_date: evDate.value,
    location: evLocation.value.trim() || null,
    participants_total: 0,
    attendance_percent: 0,
    notes: evNotes.value.trim() || null,
    kpi: kpiFromForm(),
    attendees: attendeesFromForm()
  };
  // Автовычисление явки
  const totalInList = payload.attendees.length;
  const presentCount = payload.attendees.filter(a => a.attended).length;
  payload.participants_total = presentCount;
  payload.attendance_percent = totalInList ? (presentCount * 100 / totalInList) : 0;
  try {
    const editId = formEvent.dataset.editId;
    if (editId) {
      payload.id = Number(editId);
      const resp = await fetch(`${API_BASE}/events.php`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      if (!resp.ok) throw new Error('Ошибка обновления');
    } else {
      const resp = await fetch(`${API_BASE}/events.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      if (!resp.ok) throw new Error('Ошибка сохранения');
    }
    formEvent.reset();
    kpiList.innerHTML = '';
    renderAttendeesChecklist([]);
    renderIncomingRecipients();
    renderOutgoingRecipients();
    delete formEvent.dataset.editId;
    await refreshEvents();
    renderEvents();
    if (window.showSuccess) {
      showSuccess('Мероприятие успешно сохранено');
    }
  } catch (err) {
    console.error(err);
    if (window.showError) {
      showError('Не удалось сохранить мероприятие');
    } else {
      alert('Не удалось сохранить мероприятие');
    }
  }
}

function renderEvents() {
  if (!tableEventsBody) return;
  const q = (searchEvents?.value || '').toLowerCase();
  const rows = store.events
    .filter(ev => {
      const hay = `${ev.title} ${ev.location || ''} ${ev.notes || ''}`.toLowerCase();
      return hay.includes(q);
    })
    .map(ev => {
      const present = Number(ev.attendees_present ?? 0);
      const total = Number(ev.attendees_total ?? 0);
      return `
        <tr>
          <td>${formatDateISOtoRus(ev.event_date)}</td>
          <td>${escapeHtml(ev.title)}</td>
          <td>${escapeHtml(ev.location || '')}</td>
          <td class="text-end">${present}/${total}</td>
          <td class="text-end">${Number(ev.attendance_percent || 0).toFixed(2)}%</td>
          <td class="text-end table-actions">
            <button class="btn btn-sm btn-outline-secondary" title="Кто присутствовал" data-action="view-att" data-id="${ev.id}">
              <i class="bi bi-people"></i>
            </button>
            <button class="btn btn-sm btn-outline-primary" title="Изменить" data-action="edit-event" data-id="${ev.id}">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" title="Удалить" data-action="del-event" data-id="${ev.id}">
              <i class="bi bi-trash"></i>
            </button>
          </td>
        </tr>
      `;
    }).join('');
  tableEventsBody.innerHTML = rows || '<tr><td colspan="6" class="text-center text-muted">Нет мероприятий</td></tr>';

  tableEventsBody.querySelectorAll('[data-action="del-event"]').forEach(btn => {
    btn.addEventListener('click', () => deleteEvent(btn.dataset.id));
  });
  tableEventsBody.querySelectorAll('[data-action="edit-event"]').forEach(btn => {
    btn.addEventListener('click', () => editEvent(btn.dataset.id));
  });
  tableEventsBody.querySelectorAll('[data-action="view-att"]').forEach(btn => {
    btn.addEventListener('click', () => viewEventAttendees(btn.dataset.id));
  });
}

async function deleteEvent(id) {
  if (window.confirmDelete) {
    confirmDelete('Вы уверены, что хотите удалить это мероприятие?', async () => {
      const resp = await fetch(`${API_BASE}/events.php?id=${id}`, { method: 'DELETE' });
      if (!resp.ok) {
        if (window.showError) {
          showError('Не удалось удалить мероприятие');
        } else {
          alert('Не удалось удалить');
        }
        return;
      }
      await refreshEvents();
      renderEvents();
      if (window.showSuccess) {
        showSuccess('Мероприятие успешно удалено');
      }
    });
  } else {
    if (!confirm('Удалить мероприятие?')) return;
    const resp = await fetch(`${API_BASE}/events.php?id=${id}`, { method: 'DELETE' });
    if (!resp.ok) {
      alert('Не удалось удалить');
      return;
    }
    await refreshEvents();
    renderEvents();
  }
}

async function editEvent(id) {
  try {
    const resp = await fetch(`${API_BASE}/events.php?id=${id}`);
    if (!resp.ok) throw new Error('not found');
    const ev = await resp.json();
    evTitle.value = ev.title || '';
    evDate.value = (ev.event_date || todayISO).slice(0,10);
    evLocation.value = ev.location || '';
    evNotes.value = ev.notes || '';
    kpiList.innerHTML = '';
    (ev.kpi || []).forEach(k => addKpiRow(k.metric || '', k.value_numeric ?? '', k.value_text || ''));
    const selected = (ev.attendees || []).map(a => a.full_name).filter(Boolean);
    renderAttendeesChecklist(selected);
    formEvent.dataset.editId = String(ev.id);
    document.getElementById('tab-events')?.click();
  } catch (e) {
    console.error(e);
    alert('Не удалось загрузить мероприятие');
  }
}

async function viewEventAttendees(eventId) {
  try {
    const resp = await fetch(`${API_BASE}/events.php?id=${eventId}`);
    if (!resp.ok) throw new Error('not found');
    const ev = await resp.json();
    const body = document.getElementById('eventAttendeesBody');
    const present = (ev.attendees || []).filter(a => !!a.attended).map(a => a.full_name);
    const absent = (ev.attendees || []).filter(a => !a.attended).map(a => a.full_name);
    const blocks = [];
    blocks.push(`<div class="mb-2"><strong>${escapeHtml(ev.title || '')}</strong> — ${formatDateISOtoRus(ev.event_date)}</div>`);
    blocks.push(`<div class="mb-1"><span class="badge text-bg-success">Присутствовали: ${present.length}</span></div>`);
    if (present.length) {
      blocks.push(`<div class="small">${present.map(n => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(n)}</span>`).join(' ')}</div>`);
    }
    blocks.push(`<hr/>`);
    blocks.push(`<div class="mb-1"><span class="badge text-bg-secondary">Отсутствовали: ${absent.length}</span></div>`);
    if (absent.length) {
      blocks.push(`<div class="small">${absent.map(n => `<span class="badge bg-light text-dark border me-1 mb-1">${escapeHtml(n)}</span>`).join(' ')}</div>`);
    }
    body.innerHTML = blocks.join('');
    const modal = new bootstrap.Modal(document.getElementById('eventAttendeesModal'));
    modal.show();
  } catch (e) {
    console.error(e);
    alert('Не удалось показать список присутствующих');
  }
}

async function viewLetterRecipients(type, id) {
  if (!letterRecipientsModal || !letterRecipientsBody) return;
  try {
    const resp = await fetch(`${API_BASE}/letters.php?type=${type}&id=${id}`);
    if (!resp.ok) throw new Error('not found');
    const letter = await resp.json();
    const recipients = letter.recipients || [];
    if (!recipients.length) {
      letterRecipientsBody.innerHTML = '<div class="text-muted">Адресаты не указаны.</div>';
    } else {
      letterRecipientsBody.innerHTML = recipients.map(name => `<div class="mb-1"><span class="badge rounded-pill bg-light text-dark border">${escapeHtml(name)}</span></div>`).join('');
    }
    const modal = new bootstrap.Modal(letterRecipientsModal);
    modal.show();
  } catch (e) {
    console.error(e);
    alert('Не удалось загрузить адресатов');
  }
}
function renderIncoming() {
  if (!tableIncomingBody) return;
  // Фильтры
  const yearOptions = getYears(store.incoming);
  const prevYear = filterYearIncoming.value || "Все годы";
  filterYearIncoming.innerHTML = yearOptions.map((y) => `<option value="${y}">${y}</option>`).join("");
  filterYearIncoming.value = prevYear;
  const query = (searchIncoming.value || "").toLowerCase();
  const year = filterYearIncoming.value || "Все годы";
  let monthFilter = filterMonthIncoming?.value || "all";
  const scansFilter = filterScansIncoming?.value || "all";
  if (filterRecipientIncoming) {
    updateRecipientFilterSelect(filterRecipientIncoming, getRecipientOptions(store.incoming));
  }
  const recipientFilter = filterRecipientIncoming?.value || "all";

  // Dynamic month filter options based on data in store.
  if (filterMonthIncoming) {
    const baseItems = year === "Все годы"
      ? store.incoming
      : store.incoming.filter(i => new Date(i.date).getFullYear().toString() === year);
    const monthKeys = getMonthKeys(baseItems);
    const prev = monthFilter;
    filterMonthIncoming.innerHTML = [
      '<option value="all">Все месяцы</option>',
      ...monthKeys.map(k => `<option value="${k}">${formatMonthLabel(k)}</option>`),
    ].join('');
    monthFilter = (prev && monthKeys.includes(prev)) ? prev : 'all';
    filterMonthIncoming.value = monthFilter;
  }

  const statusFilter = filterStatusIncoming?.value || 'all';
  const overdueThreshold = new Date();
  overdueThreshold.setDate(overdueThreshold.getDate() - 21);

  const filteredIncoming = [...store.incoming]
    .sort((a, b) => a.seq - b.seq)
    .filter((i) => year === "Все годы" || new Date(i.date).getFullYear().toString() === year)
    .filter((i) => {
      if (monthFilter === 'all' || !monthFilter) return true;
      const d = new Date(i.date);
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
      return key === monthFilter;
    })
    .filter((i) => {
      if (statusFilter === 'all') return true;
      const isPending = !i.linkedOutgoingId;
      if (statusFilter === 'pending') return isPending;
      if (statusFilter === 'overdue') {
        return isPending && new Date(i.date) < overdueThreshold;
      }
      return true;
    })
    .filter((i) => {
      const hasScans = (i.scansCount || 0) > 0;
      if (scansFilter === "with-scans") return hasScans;
      if (scansFilter === "without-scans") return !hasScans;
      return true;
    })
    .filter((i) => {
      const membersNames = (i.members || []).map(m => m.full_name).join(' ');
      const recipientsNames = (i.recipients || []).join(' ');
      const hay = `${i.seq} ${i.organization} ${i.kkNumber} ${i.subject} ${i.note} ${i.category || ''} ${membersNames} ${recipientsNames}`.toLowerCase();
      return hay.includes(query);
    })
    .filter((i) => recipientFilter === 'all' || (i.recipients || []).includes(recipientFilter));

  const rowsData = filteredIncoming.map((i) => {
      const linked = i.linkedOutgoingId ? store.outgoing.find(o => o.id === i.linkedOutgoingId) : null;
      const linkHtml = linked
        ? `<span class="badge badge-outgoing rounded-pill">Ответ: ${linked.outgoingNumber || 'Исх.' + linked.seq}</span>`
        : `<div class="d-flex flex-column gap-1 align-items-start">
             <span class="badge badge-status-pending rounded-pill">Нет ответа</span>
             <div class="d-flex flex-wrap gap-2">
               <button class="btn btn-sm btn-outline-success" data-action="respond-incoming" data-id="${i.id}"><i class="bi bi-reply"></i> Ответ</button>
               <button class="btn btn-sm btn-outline-secondary" data-action="attach-outgoing" data-id="${i.id}"><i class="bi bi-link-45deg"></i> Привязать</button>
             </div>
           </div>`;
      const cat = i.category || "KK";
      const catClass = categoryBadgeClass(cat);
      const scansCellId = `scans-incoming-${i.id}`;
      const membersCellId = `members-incoming-${i.id}`;
      const recipientsCellId = `recipients-incoming-${i.id}`;
      // срок 15 рабочих дней с предупреждением за 3 дня
      const dueDate = addWorkingDays(i.date, 15);
      const warnFrom = subtractWorkingDays(dueDate, 3);
      const today = new Date();
      const isDanger = !i.linkedOutgoingId && today > dueDate;
      const isWarn = !i.linkedOutgoingId && today >= warnFrom && today <= dueDate;
      const dueBadgeClass = isDanger ? 'badge-due-danger' : (isWarn ? 'badge-due' : 'text-bg-secondary');
      const dueHtml = `<span class="badge ${dueBadgeClass} rounded-pill">Срок: ${formatDateISOtoRus(dueDate.toISOString().slice(0,10))}</span>`;

      return `
        <tr>
          <td class="text-nowrap">Вх.${i.seq}</td>
          <td>${formatDateISOtoRus(i.date)}</td>
          <td>${escapeHtml(i.organization)}</td>
          <td class="text-nowrap"><span class="badge ${catClass}">${categoryDisplay(cat)}</span></td>
          <td class="text-nowrap"><span class="badge badge-incoming">${escapeHtml(i.kkNumber)}</span></td>
          <td>${escapeHtml(i.subject || "")}</td>
          <td id="${recipientsCellId}"></td>
          <td>${dueHtml}</td>
          <td id="${membersCellId}"></td>
          <td id="${scansCellId}"></td>
          <td>${linkHtml}</td>
          <td class="text-end table-actions">
            <button class="btn btn-sm btn-outline-primary" data-action="edit-incoming" data-id="${i.id}" title="Изменить"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-danger" data-action="del-incoming" data-id="${i.id}" title="Удалить"><i class="bi bi-trash"></i></button>
          </td>
        </tr>`;
    });
  tableIncomingBody.innerHTML = rowsData.length
    ? rowsData.join("")
    : '<tr><td colspan="12" class="text-center text-muted py-4">По выбранным фильтрам писем не найдено</td></tr>';
  
  // Рендерим дополнительные ячейки после создания строк
  filteredIncoming.forEach((i) => {
    const scansCell = document.getElementById(`scans-incoming-${i.id}`);
    if (scansCell) {
      renderLetterScans(i, 'incoming', scansCell);
    }
    const membersCell = document.getElementById(`members-incoming-${i.id}`);
    if (membersCell) {
      renderMembersCell(i.members, membersCell);
    }
    const recipientsCell = document.getElementById(`recipients-incoming-${i.id}`);
    if (recipientsCell) {
      renderRecipientsCell(i.recipients, recipientsCell, 'incoming', i.id);
    }
  });
  
  tableIncomingBody.querySelectorAll("[data-action='del-incoming']").forEach((btn) => {
    btn.addEventListener("click", () => deleteIncoming(btn.dataset.id));
  });
  // обработчики редактирования
  tableIncomingBody.querySelectorAll("[data-action='edit-incoming']").forEach((btn) => {
    btn.addEventListener("click", () => editIncoming(btn.dataset.id));
  });
  tableIncomingBody.querySelectorAll("[data-action='respond-incoming']").forEach((btn) => {
    btn.addEventListener("click", () => startOutgoingResponse(btn.dataset.id));
  });
  tableIncomingBody.querySelectorAll("[data-action='attach-outgoing']").forEach((btn) => {
    btn.addEventListener("click", () => openLinkOutgoingModal({ type: 'incoming-table', incomingId: btn.dataset.id }));
  });

  renderTableFooter('incomingTableFooter', filteredIncoming.length, store.incoming.length, 'писем');
  updateNotifyBadge();
  updatePageHeader(document.querySelector('#sidebarNav .nav-link.active')?.id || 'tab-incoming');
}

function startOutgoingResponse(incomingId) {
  if (formOutgoing) {
    formOutgoing.reset();
  }
  const tabBtn = document.getElementById('tab-outgoing');
  if (tabBtn) {
    tabBtn.click();
  }
  setTimeout(() => {
    scrollToCollapse('#collapseOutgoingForm');
    if (outLinkedIncoming) {
      outLinkedIncoming.value = String(incomingId);
      outLinkedIncoming.dispatchEvent(new Event('change'));
    }
    outSubject?.focus();
  }, 150);
}

function openLinkOutgoingModal(target) {
  if (!linkOutgoingModalEl) return;
  if (typeof target === 'object' && target !== null) {
    linkModalContext = {
      type: target.type || 'incoming-table',
      incomingId: target.incomingId != null ? Number(target.incomingId) : null
    };
  } else {
    linkModalContext = {
      type: 'incoming-table',
      incomingId: target != null ? Number(target) : null
    };
  }
  selectedOutgoingForIncoming = linkModalContext.type === 'incoming-form'
    ? (incRespondsOutgoing?.value || null)
    : null;
  if (!linkOutgoingModalInstance) {
    linkOutgoingModalInstance = new bootstrap.Modal(linkOutgoingModalEl);
  }
  if (linkOutgoingSearch) {
    linkOutgoingSearch.value = '';
  }
  if (linkOutgoingConfirm) {
    if (linkModalContext.type === 'incoming-form') {
      linkOutgoingConfirm.classList.remove('d-none');
    } else {
      linkOutgoingConfirm.classList.add('d-none');
    }
  }
  renderLinkOutgoingOptions();
  linkOutgoingModalInstance.show();
  setTimeout(() => linkOutgoingSearch?.focus(), 200);
}

function renderLinkOutgoingOptions() {
  if (!linkOutgoingList) return;
  const query = (linkOutgoingSearch?.value || '').toLowerCase();
  const available = store.outgoing.filter(o => !o.incomingRefId);
  const filtered = available.filter(o => {
    const hay = `${o.outgoingNumber} ${o.organization || ''} ${o.subject || ''}`.toLowerCase();
    return hay.includes(query);
  }).slice(0, 50);
  if (!filtered.length) {
    linkOutgoingList.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Нет подходящих исходящих писем</td></tr>';
    return;
  }
  const isFormMode = linkModalContext.type === 'incoming-form';
  linkOutgoingList.innerHTML = filtered.map(o => {
    const numberLabel = o.outgoingNumber || `Исх.${o.seq}`;
    if (isFormMode) {
      const checked = String(selectedOutgoingForIncoming || '') === String(o.id);
      return `
        <tr data-select-outgoing="${o.id}">
          <td class="text-nowrap">${escapeHtml(numberLabel)}</td>
          <td>${formatDateISOtoRus(o.date)}</td>
          <td>${escapeHtml(o.organization || '')}</td>
          <td>${escapeHtml(o.subject || '')}</td>
          <td class="text-end">
            <input class="form-check-input" type="radio" name="incomingFormOutgoing" value="${o.id}" ${checked ? 'checked' : ''}>
          </td>
        </tr>
      `;
    }
    return `
      <tr>
        <td class="text-nowrap">${escapeHtml(numberLabel)}</td>
        <td>${formatDateISOtoRus(o.date)}</td>
        <td>${escapeHtml(o.organization || '')}</td>
        <td>${escapeHtml(o.subject || '')}</td>
        <td class="text-end">
          <button class="btn btn-sm btn-success" data-link-outgoing="${o.id}">Привязать</button>
        </td>
      </tr>
    `;
  }).join('');

  if (isFormMode) {
    linkOutgoingList.querySelectorAll('tr[data-select-outgoing]').forEach(row => {
      row.addEventListener('click', () => {
        selectedOutgoingForIncoming = row.getAttribute('data-select-outgoing');
        renderLinkOutgoingOptions();
      });
    });
  } else {
    linkOutgoingList.querySelectorAll('[data-link-outgoing]').forEach(btn => {
      btn.addEventListener('click', () => linkExistingOutgoing(btn.dataset.linkOutgoing));
    });
  }
}

async function linkExistingOutgoing(outgoingId) {
  if (linkModalContext.type === 'incoming-form') {
    applySelectedOutgoingForForm(outgoingId);
    return;
  }
  const incomingId = Number(linkModalContext.incomingId);
  if (!incomingId) return;
  try {
    const detail = await fetchLetterDetail('outgoing', Number(outgoingId));
    const payload = {
      id: Number(detail.id),
      seq: Number(detail.seq),
      date: detail.date?.slice(0, 10) || todayISO,
      outgoing_number: detail.outgoing_number || detail.outgoingNumber || '',
      organization: detail.organization || '',
      incoming_ref_id: incomingId,
      subject: detail.subject || '',
      note: detail.note || '',
      members: (detail.members || []).map(mapMemberForUpdate).filter(Boolean),
      recipients: (detail.recipients || []).slice(),
      outgoing_type: detail.outgoing_type || detail.outgoingType || 'gov'
    };
    await updateLetter('outgoing', payload);
    linkModalContext = { type: 'incoming-table', incomingId: null };
    if (linkOutgoingModalInstance) {
      linkOutgoingModalInstance.hide();
    }
    await refreshLetters();
    renderAll();
    if (window.showSuccess) {
      showSuccess('Исходящее письмо успешно привязано');
    }
  } catch (error) {
    console.error('Ошибка привязки исходящего', error);
    if (window.showError) {
      showError('Не удалось привязать исходящее письмо');
    } else {
      alert('Не удалось привязать исходящее письмо');
    }
  }
}

function renderOutgoing() {
  if (!tableOutgoingBody) return;
  // Фильтры
  const yearOptions = getYears(store.outgoing);
  const prevYear = filterYearOutgoing.value || "Все годы";
  filterYearOutgoing.innerHTML = yearOptions.map((y) => `<option value="${y}">${y}</option>`).join("");
  filterYearOutgoing.value = prevYear;
  const query = (searchOutgoing.value || "").toLowerCase();
  const year = filterYearOutgoing.value || "Все годы";
  let monthFilter = filterMonthOutgoing?.value || "all";
  const scansFilter = filterScansOutgoing?.value || "all";
  if (filterRecipientOutgoing) {
    updateRecipientFilterSelect(filterRecipientOutgoing, getRecipientOptions(store.outgoing));
  }
  const recipientFilter = filterRecipientOutgoing?.value || "all";

  // Dynamic month filter options based on data in store.
  if (filterMonthOutgoing) {
    const baseItems = year === "Все годы"
      ? store.outgoing
      : store.outgoing.filter(i => new Date(i.date).getFullYear().toString() === year);
    const monthKeys = getMonthKeys(baseItems);
    const prev = monthFilter;
    filterMonthOutgoing.innerHTML = [
      '<option value="all">Все месяцы</option>',
      ...monthKeys.map(k => `<option value="${k}">${formatMonthLabel(k)}</option>`),
    ].join('');
    monthFilter = (prev && monthKeys.includes(prev)) ? prev : 'all';
    filterMonthOutgoing.value = monthFilter;
  }

  const filteredOutgoing = [...store.outgoing]
    .sort((a, b) => a.seq - b.seq)
    .filter((i) => year === "Все годы" || new Date(i.date).getFullYear().toString() === year)
    .filter((i) => {
      if (monthFilter === 'all' || !monthFilter) return true;
      const d = new Date(i.date);
      const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
      return key === monthFilter;
    })
    .filter((i) => {
      const hasScans = (i.scansCount || 0) > 0;
      if (scansFilter === "with-scans") return hasScans;
      if (scansFilter === "without-scans") return !hasScans;
      return true;
    })
    .filter((i) => {
      const incoming = store.incoming.find((inc) => inc.id === i.incomingRefId);
      const membersNames = (i.members || []).map(m => m.full_name).join(' ');
      const recipientsNames = (i.recipients || []).join(' ');
      const fallbackCat = mapOutgoingTypeToCategory(i.outgoingType || 'gov');
      const hay = `${i.seq} ${i.outgoingNumber} ${i.subject} ${i.note} ${incoming?.organization ?? i.organization ?? ""} ${incoming?.kkNumber ?? ""} ${(incoming?.category || fallbackCat || '')} ${membersNames} ${recipientsNames}`.toLowerCase();
      return hay.includes(query);
    })
    .filter((i) => recipientFilter === 'all' || (i.recipients || []).includes(recipientFilter));

  const rows = filteredOutgoing.map((i) => {
      const incoming = store.incoming.find((inc) => inc.id === i.incomingRefId);
      const cat = incoming?.category || mapOutgoingTypeToCategory(i.outgoingType || 'gov');
      const catClass = categoryBadgeClass(cat);
      const linkInfo = incoming
        ? `Вх.${incoming.seq ?? "?"} — ${escapeHtml(incoming.kkNumber || "")}`
        : '<span class="text-muted">Без связи</span>';
      const numberLabel = i.outgoingNumber ? escapeHtml(i.outgoingNumber) : `Исх.${i.seq}`;
      const scansCellId = `scans-outgoing-${i.id}`;
      const membersCellId = `members-outgoing-${i.id}`;
      const recipientsCellId = `recipients-outgoing-${i.id}`;
      return `
        <tr>
          <td class="text-nowrap">Исх.${i.seq}</td>
          <td>${formatDateISOtoRus(i.date)}</td>
          <td class="text-nowrap"><span class="badge badge-outgoing">${numberLabel}</span></td>
          <td>${escapeHtml(incoming?.organization || i.organization || "")}</td>
          <td class="text-nowrap"><span class="badge ${catClass}">${categoryDisplay(cat)}</span></td>
          <td class="text-nowrap">${linkInfo}</td>
          <td>${escapeHtml(i.subject || "")}</td>
          <td id="${recipientsCellId}"></td>
          <td id="${membersCellId}"></td>
          <td id="${scansCellId}"></td>
          <td class="text-end table-actions">
            <button class="btn btn-sm btn-outline-primary" title="Изменить" data-action="edit-outgoing" data-id="${i.id}">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" title="Удалить" data-action="del-outgoing" data-id="${i.id}">
              <i class="bi bi-trash"></i>
            </button>
          </td>
        </tr>`;
    });
  tableOutgoingBody.innerHTML = rows.join("");
  
  // Рендерим сканы и ответственных после создания строк
  filteredOutgoing.forEach((i) => {
    const scansCell = document.getElementById(`scans-outgoing-${i.id}`);
    if (scansCell) {
      renderLetterScans(i, 'outgoing', scansCell);
    }
    const membersCell = document.getElementById(`members-outgoing-${i.id}`);
    if (membersCell) {
      renderMembersCell(i.members, membersCell);
    }
    const recipientsCell = document.getElementById(`recipients-outgoing-${i.id}`);
    if (recipientsCell) {
      renderRecipientsCell(i.recipients, recipientsCell, 'outgoing', i.id);
    }
  });
  
  tableOutgoingBody.querySelectorAll("[data-action='del-outgoing']").forEach((btn) => {
    btn.addEventListener("click", () => deleteOutgoing(btn.dataset.id));
  });
  tableOutgoingBody.querySelectorAll("[data-action='edit-outgoing']").forEach((btn) => {
    btn.addEventListener("click", () => editOutgoing(btn.dataset.id));
  });

  renderTableFooter('outgoingTableFooter', filteredOutgoing.length, store.outgoing.length, 'писем');
}

async function deleteIncoming(id) {
  if (window.confirmDelete) {
    confirmDelete('Вы уверены, что хотите удалить это входящее письмо?', async () => {
      try {
        await deleteLetter('incoming', id);
        if (window.showSuccess) {
          showSuccess('Входящее письмо успешно удалено');
        }
      } catch (error) {
        console.error('Ошибка удаления входящего письма', error);
        if (window.showError) {
          showError('Не удалось удалить входящее письмо');
        } else {
          alert('Не удалось удалить входящее письмо');
        }
      }
    });
  } else {
    if (!confirm('Удалить входящее письмо?')) return;
    try {
      await deleteLetter('incoming', id);
    } catch (error) {
      console.error('Ошибка удаления входящего письма', error);
      alert('Не удалось удалить входящее письмо');
    }
  }
}

async function deleteOutgoing(id) {
  if (window.confirmDelete) {
    confirmDelete('Вы уверены, что хотите удалить это исходящее письмо?', async () => {
      try {
        await deleteLetter('outgoing', id);
        if (window.showSuccess) {
          showSuccess('Исходящее письмо успешно удалено');
        }
      } catch (error) {
        console.error('Ошибка удаления исходящего письма', error);
        if (window.showError) {
          showError('Не удалось удалить исходящее письмо');
        } else {
          alert('Не удалось удалить исходящее письмо');
        }
      }
    });
  } else {
    if (!confirm('Удалить исходящее письмо?')) return;
    try {
      await deleteLetter('outgoing', id);
    } catch (error) {
      console.error('Ошибка удаления исходящего письма', error);
      alert('Не удалось удалить исходящее письмо');
    }
  }
}

async function renderKPIs() {
  const period = dashboardPeriodSelect?.value || 'month';

  function goToIncomingStatus(status) {
    if (filterStatusIncoming) filterStatusIncoming.value = status;
    if (filterYearIncoming) filterYearIncoming.value = 'Все годы';
    if (filterMonthIncoming) filterMonthIncoming.value = 'all';
    if (filterScansIncoming) filterScansIncoming.value = 'all';
    if (filterRecipientIncoming) filterRecipientIncoming.value = 'all';
    if (searchIncoming) searchIncoming.value = '';

    document.getElementById('tab-incoming')?.click();
    renderIncoming();
  }

  // 1) Server-side KPI values (no hardcoded client duplication).
  let stats = null;
  try {
    const resp = await fetch(`${API_BASE}/statistics.php`, { method: 'GET' });
    if (resp.ok) stats = await resp.json();
  } catch (e) {
    console.warn('statistics.php failed', e);
  }

  // 2) Fallbacks from store (keeps UI usable if statistics endpoint fails).
  const totalInc = store.incoming.length;
  const totalOut = store.outgoing.length;
  const closed = store.incoming.filter(i => i.linkedOutgoingId).length;

  const days = store.outgoing.map(o => {
    const inc = store.incoming.find(i => i.id === o.incomingRefId);
    if (!inc) return null;
    const diff = (new Date(o.date) - new Date(inc.date)) / (1000 * 3600 * 24);
    return diff >= 0 ? diff : null;
  }).filter(x => x !== null);
  const avgFallback = days.length ? (days.reduce((a, b) => a + b, 0) / days.length) : null;

  const overdueThreshold = new Date();
  overdueThreshold.setDate(overdueThreshold.getDate() - 21);
  const pendingFallback = store.incoming.filter(i => !i.linkedOutgoingId).length;
  const overdueFallback = store.incoming.filter(i => !i.linkedOutgoingId && new Date(i.date) < overdueThreshold).length;

  const withScansFallback = [...store.incoming, ...store.outgoing].filter(item => (item.scansCount || 0) > 0).length;
  const totalScansFallback = [...store.incoming, ...store.outgoing].reduce((sum, item) => sum + (item.scansCount || 0), 0);

  const dbSize = new Blob([JSON.stringify(store)]).size;
  const dbSizeMB = (dbSize / (1024 * 1024)).toFixed(2);
  const dbSizeKB = (dbSize / 1024).toFixed(1);

  // Values from statistics.php when available.
  const kpiIncomingVal = stats ? stats.total_incoming : totalInc;
  const kpiOutgoingVal = stats ? stats.total_outgoing : totalOut;
  const kpiClosedVal = stats ? stats.closed_letters : closed;
  const kpiAvgDaysVal = stats ? stats.avg_response_days : avgFallback;
  const pendingVal = stats ? stats.pending_letters : pendingFallback;
  const overdueVal = stats ? stats.overdue_letters : overdueFallback;
  const kpiWithScansVal = stats ? stats.letters_with_scans : withScansFallback;
  const kpiTotalScansVal = stats ? stats.total_scans : totalScansFallback;
  const membersCountVal = stats ? stats.members_count : membersCatalog.length;
  const commissionsCountVal = stats ? stats.commissions_count : commissionsCatalog.length;

  updateKpiValue(kpiIncoming, 'kpiIncoming', kpiIncomingVal);
  updateKpiValue(kpiOutgoing, 'kpiOutgoing', kpiOutgoingVal);
  updateKpiValue(kpiClosed, 'kpiClosed', kpiClosedVal);

  if (kpiWithScans) updateKpiValue(kpiWithScans, 'kpiWithScans', kpiWithScansVal);
  if (kpiTotalScans) updateKpiValue(kpiTotalScans, 'kpiTotalScans', kpiTotalScansVal);

  if (kpiAvgDays) {
    if (kpiAvgDaysVal === null || kpiAvgDaysVal === undefined || kpiAvgDaysVal === '' || !Number.isFinite(Number(kpiAvgDaysVal))) {
      updateKpiValue(kpiAvgDays, 'kpiAvgDays', '—', true);
    } else {
      updateKpiValue(kpiAvgDays, 'kpiAvgDays', Number(kpiAvgDaysVal), false);
    }
  }

  const closedPct = kpiIncomingVal ? Math.round((kpiClosedVal / kpiIncomingVal) * 100) : 0;
  const progressEl = document.getElementById('kpiClosedProgress');
  if (progressEl) progressEl.style.width = `${closedPct}%`;

  const pendingEl = document.getElementById('kpiPending');
  const overdueEl = document.getElementById('kpiOverdue');
  if (pendingEl) updateKpiValue(pendingEl, 'kpiPending', pendingVal);
  if (overdueEl) updateKpiValue(overdueEl, 'kpiOverdue', overdueVal);

  const membersEl = document.getElementById('kpiMembersCount');
  const commissionsEl = document.getElementById('kpiCommissionsCount');
  if (membersEl) membersEl.textContent = String(membersCountVal);
  if (commissionsEl) commissionsEl.textContent = String(commissionsCountVal);

  if (kpiDbSize) {
    if (dbSize > 1024 * 1024) kpiDbSize.textContent = `${dbSizeMB} МБ`;
    else kpiDbSize.textContent = `${dbSizeKB} КБ`;
  }

  // Latest period insight (replaces hardcoded "May 2024").
  if (kpiIncomingMay) {
    const incomingDates = store.incoming.map(i => new Date(i.date)).filter(d => !isNaN(d.valueOf()));
    const latestDate = incomingDates.length ? incomingDates.reduce((a, b) => (a > b ? a : b)) : null;

    const latestKey = latestDate ? periodKeyFromDate(latestDate, period) : null;
    const latestLabel = latestKey ? formatPeriodLabel(latestKey, period) : '';
    if (kpiIncomingPeriodLabel) kpiIncomingPeriodLabel.textContent = latestKey ? `За ${latestLabel}` : 'За период';

    const latestCount = latestKey
      ? store.incoming.filter(i => periodKeyFromDate(i.date, period) === latestKey).length
      : 0;
    updateKpiValue(kpiIncomingMay, 'kpiIncomingMay', latestCount);
  }

  const totalLetters = kpiIncomingVal + kpiOutgoingVal;
  const setNote = (id, text) => {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  };
  setNote('kpiOutgoingNote', kpiOutgoingVal ? `${Math.round((kpiOutgoingVal / Math.max(kpiIncomingVal, 1)) * 100)}% от входящих` : 'ответы ОС');
  setNote('kpiClosedNote', kpiIncomingVal ? `${closedPct}% от входящих` : '—');
  setNote('kpiWithScansNote', totalLetters ? `${Math.round((kpiWithScansVal / totalLetters) * 100)}% от всех писем` : '—');

  // Click on "Без ответа"/"Просрочено" -> open Incoming with filter.
  const pendingItem = pendingEl?.closest('li');
  const overdueItem = overdueEl?.closest('li');
  if (pendingItem) {
    pendingItem.classList.add('dash-insight--clickable');
    pendingItem.style.cursor = 'pointer';
    pendingItem.onclick = () => goToIncomingStatus('pending');
  }
  if (overdueItem) {
    overdueItem.classList.add('dash-insight--clickable');
    overdueItem.style.cursor = 'pointer';
    overdueItem.onclick = () => goToIncomingStatus('overdue');
  }

  updateNotifyBadge();
}

function updateKpiValue(element, key, value, nonNumeric = false) {
  if (!element) return;
  const numericValue = nonNumeric ? value : Number(value);
  if (!nonNumeric && !Number.isFinite(numericValue)) {
    element.textContent = '—';
    lastKpiValues[key] = null;
    return;
  }
  if (window.enableKpiCounterAnimation && !nonNumeric) {
    animateCounter(element, numericValue);
  } else {
    element.textContent = String(value);
  }
  const previous = lastKpiValues[key];
  if (previous !== undefined && previous !== numericValue) {
    element.classList.remove('kpi-pulse');
    void element.offsetWidth;
    element.classList.add('kpi-pulse');
  }
  lastKpiValues[key] = numericValue;
}

function topOrganizations() {
  const map = new Map();
  store.incoming.forEach((i) => {
    const key = i.organization.trim() || "(не указано)";
    map.set(key, (map.get(key) || 0) + 1);
  });
  return Array.from(map.entries()).sort((a, b) => b[1] - a[1]).slice(0, 10);
}

function renderCharts() {
  const period = dashboardPeriodSelect?.value || 'month';
  const inc = groupByPeriod(store.incoming, period, (x) => x.date);
  const out = groupByPeriod(store.outgoing, period, (x) => x.date);
  const merged = mergePeriodSeries(inc, out, period);
  const orgs = topOrganizations();

  const trendLabels = merged.map(([k]) => formatPeriodLabel(k, period));
  const trendIn = merged.map(([, v]) => v.in);
  const trendOut = merged.map(([, v]) => v.out);

  const orgLabels = orgs.map(([name]) => name);
  const orgData = orgs.map(([, v]) => v);

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: 'rgba(15, 27, 51, 0.92)',
        padding: 12,
        cornerRadius: 8
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: { borderDash: [2, 4], color: '#E5E7EB' },
        ticks: { color: '#6B7280', precision: 0 }
      },
      x: {
        grid: { display: false },
        ticks: { color: '#6B7280', maxRotation: 0 }
      }
    }
  };

  const ctxTrend = document.getElementById('chartLettersTrend');
  if (chartLettersTrend) chartLettersTrend.destroy();
  if (ctxTrend) {
    chartLettersTrend = new Chart(ctxTrend, {
      type: 'line',
      data: {
        labels: trendLabels,
        datasets: [
          {
            label: 'Входящие',
            data: trendIn,
            borderColor: '#1D4ED8',
            backgroundColor: 'rgba(29, 78, 216, 0.06)',
            pointBackgroundColor: '#fff',
            pointBorderColor: '#1D4ED8',
            pointRadius: 3,
            fill: true,
            tension: 0.35
          },
          {
            label: 'Исходящие',
            data: trendOut,
            borderColor: '#D9A521',
            backgroundColor: 'transparent',
            pointBackgroundColor: '#fff',
            pointBorderColor: '#D9A521',
            pointRadius: 3,
            borderDash: [],
            tension: 0.35
          }
        ]
      },
      options: chartOptions
    });
  }

  // Top organizations
  const ctx3 = document.getElementById("chartTopOrgs");
  if (chartTopOrgs) chartTopOrgs.destroy();
  if (ctx3) {
    chartTopOrgs = new Chart(ctx3, {
      type: "bar",
      data: {
        labels: orgLabels,
        datasets: [{ 
            label: "Письма", 
            data: orgData, 
            backgroundColor: "#1B2A4A",
            borderRadius: 4,
            hoverBackgroundColor: "#0F1B33"
        }]
      },
      options: { 
        indexAxis: 'y',
        maintainAspectRatio: false,
        responsive: true,
        animation: {
            duration: 700,
            easing: 'easeOutQuad'
        },
        plugins: { 
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(17, 24, 39, 0.9)',
                padding: 12,
                cornerRadius: 8
            }
        }, 
        scales: { 
            x: { 
                beginAtZero: true,
                grid: { borderDash: [2, 4], color: "#e2e8f0" },
                ticks: { color: "#64748b" }
            },
            y: {
                grid: { display: false },
                ticks: { color: "#64748b", autoSkip: false }
            }
        } 
      }
    });
  }

  // Pie (donut) charts
  const incomingKK = store.incoming.filter(i => (i.category || 'KK') === 'KK');
  const outgoingToKK = store.outgoing.filter(o => {
    const incRef = store.incoming.find(i => i.id === o.incomingRefId);
    return (incRef?.category || 'KK') === 'KK';
  });

  const pieGovData = [incomingKK.length, outgoingToKK.length];
  const ctxPieGov = document.getElementById("chartPieGov");
  if (chartPieGov) chartPieGov.destroy();
  if (ctxPieGov) {
    chartPieGov = new Chart(ctxPieGov, {
      type: 'doughnut',
      data: {
        labels: ["Входящие письма от гос. органов (ҚК)", "Ответы от ОС"],
        datasets: [{
          data: pieGovData,
          backgroundColor: ["#ffd966", "#10b981"],
          borderWidth: 0,
          hoverOffset: 10
        }]
      },
      options: { 
        cutout: '65%', 
        animation: {
            animateScale: true,
            animateRotate: true,
            duration: 2000
        },
        plugins: { legend: { display: false } } 
      }
    });
  }

  const totalOutgoing = store.outgoing.length;
  const totalIncomingGov = incomingKK.length; // трактуем как ответы от гос. органов
  const pieOSData = [totalOutgoing, totalIncomingGov];
  const ctxPieOS = document.getElementById("chartPieOS");
  if (chartPieOS) chartPieOS.destroy();
  if (ctxPieOS) {
    chartPieOS = new Chart(ctxPieOS, {
      type: 'doughnut',
      data: {
        labels: ["Исходящие письма от ОС", "Ответы от гос органов (ҚК)"],
        datasets: [{
          data: pieOSData,
          backgroundColor: ["#5eead4", "#003c97"],
          borderWidth: 0,
          hoverOffset: 10
        }]
      },
      options: { 
        cutout: '65%', 
        animation: {
            animateScale: true,
            animateRotate: true,
            duration: 2000
        },
        plugins: { legend: { display: false } } 
      }
    });
  }
}

// Экспорт / импорт реализация
function exportJson() {
  const blob = new Blob([JSON.stringify(store, null, 2)], { type: "application/json;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `os_journal_${new Date().toISOString().slice(0,10)}.json`;
  a.click();
  URL.revokeObjectURL(url);
}

function exportCsv() {
  // Две вкладки: incoming, outgoing
  const incHeader = ["Тип","РегНомер","Дата","Организация","Категория","Номер","Тема","Примечание"].join(",");
  const incRows = store.incoming.map(i => [
    "Входящее",
    `Вх.${i.seq}`,
    formatDateISOtoRus(i.date),
    sanitizeCsv(i.organization),
    sanitizeCsv(i.category || "KK"),
    sanitizeCsv(i.kkNumber),
    sanitizeCsv(i.subject || ""),
    sanitizeCsv(i.note || "")
  ].join(","));

  const outHeader = ["Тип","Порядк№","Дата","Исходящий№","Организация","Категория","Связ.Входящее","Тема","Примечание"].join(",");
  const outRows = store.outgoing.map(o => {
    const inc = store.incoming.find(i => i.id === o.incomingRefId);
    return [
      "Исходящее",
      `Исх.${o.seq}`,
      formatDateISOtoRus(o.date),
      sanitizeCsv(o.outgoingNumber),
      sanitizeCsv(inc?.organization || ""),
      sanitizeCsv(inc?.category || "KK"),
      sanitizeCsv(`Вх.${inc?.seq ?? "?"} ${inc?.kkNumber ?? ""}`),
      sanitizeCsv(o.subject || ""),
      sanitizeCsv(o.note || "")
    ].join(",");
  });

  const csv = [incHeader, ...incRows, "", outHeader, ...outRows].join("\n");
  const blob = new Blob(["\ufeff" + csv], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `os_journal_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

function importJson(ev) {
  alert('Импорт через файл временно недоступен в новой версии — используйте API или добавляйте письма вручную.');
  if (ev?.target) ev.target.value = "";
}

// Вспомогательные
function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function sanitizeCsv(str) {
  const s = String(str ?? "");
  if (s.includes(",") || s.includes("\n") || s.includes('"')) {
    return '"' + s.replace(/"/g, '""') + '"';
  }
  return s;
}

// UI Notification Functions
function showLoading(message) {
  const overlay = document.getElementById('loadingOverlay');
  if (overlay) {
    overlay.style.display = 'flex';
    const text = overlay.querySelector('p');
    if (text) text.textContent = message || 'Загрузка...';
  }
}

function hideLoading() {
  const overlay = document.getElementById('loadingOverlay');
  if (overlay) {
    overlay.style.display = 'none';
  }
}

function showToast(message, type = 'info') {
  const container = document.querySelector('.toast-container');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} border-0`;
  toast.setAttribute('role', 'alert');
  toast.setAttribute('aria-live', 'assertive');
  toast.setAttribute('aria-atomic', 'true');

  toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${escapeHtml(message)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Закрыть"></button>
    </div>
  `;

  container.appendChild(toast);
  const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
  bsToast.show();

  toast.addEventListener('hidden.bs.toast', () => {
    toast.remove();
  });
}

function showSuccess(message) {
  showToast(message, 'success');
}

function showError(message) {
  showToast(message, 'error');
}

function showWarning(message) {
  showToast(message, 'warning');
}

// Expose functions to window for backward compatibility
window.showLoading = showLoading;
window.hideLoading = hideLoading;
window.showSuccess = showSuccess;
window.showError = showError;
window.showWarning = showWarning;

