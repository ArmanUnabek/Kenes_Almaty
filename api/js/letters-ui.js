/**
 * UI писем: входящие/исходящие, сканы, CRUD, фильтры.
 */
(function (window) {
  const API_BASE = window.API_BASE;
  const store = window.store;
  const t = (k, fb) => window.AppI18n?.t(k, fb) ?? fb;
  const canWrite = () => window.canWrite?.() ?? false;
  const canDelete = () => window.canDelete?.() ?? false;
  const confirmDelete = (...args) => window.confirmDelete?.(...args);
  const debounce = window.AppUtils?.debounce || window.debounce || ((fn, w) => {
    let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), w); };
  });

  function unique(values) {
    return Array.from(new Set(values));
  }

  function monthKey(iso) {
    const d = new Date(iso);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
  }

  const MONTHS_RU = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];

  function formatMonthLabel(key) {
    if (window.AppI18n?.formatMonthLabel) return window.AppI18n.formatMonthLabel(key);
    if (!key) return '';
    const [y, mm] = String(key).split('-');
    const m = Number(mm);
    if (!y || !Number.isFinite(m) || m < 1 || m > 12) return String(key);
    return `${MONTHS_RU[m - 1]} ${y}`;
  }

  function getMonthKeys(items) {
    return unique((items || []).map(x => monthKey(x.date))).filter(Boolean).sort((a, b) => a.localeCompare(b));
  }

const letterDetailsCache = {
  incoming: new Map(),
  outgoing: new Map()
};
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
  def.textContent = window.AppI18n?.t('filter.all_recipients', 'Все адресаты') || 'Все адресаты';
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
          showWarning(t('letters.no_scans', 'Сканы не найдены'));
        } else {
          alert(t('letters.no_scans', 'Сканы не найдены'));
        }
        return;
      }
      currentViewingScans = scans;
      viewScans(scans, 0);
    } catch (error) {
      console.error('Ошибка загрузки сканов', error);
      if (window.showError) {
        showError(t('letters.scans_load_error', 'Не удалось загрузить сканы'));
      } else {
        alert(t('letters.scans_load_error', 'Не удалось загрузить сканы'));
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
  btn.textContent = t('letters.view', 'Просмотр');
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
const formIncoming = document.getElementById("formIncoming");
const incDate = document.getElementById("incDate");
const incType = document.getElementById("incType");
const incOrg = document.getElementById("incOrg");
const incNumber = document.getElementById("incNumber");
const incSeq = document.getElementById("incSeq");
const incMembers = document.getElementById('incMembers');
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
const outMembers = document.getElementById('outMembers');
const searchOutgoing = document.getElementById("searchOutgoing");
const filterYearOutgoing = document.getElementById("filterYearOutgoing");
const tableOutgoingBody = document.querySelector("#tableOutgoing tbody");

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
const LETTERS_PAGE_SIZE = 50;
let incomingPage = 1;
let outgoingPage = 1;

function buildPaginationHtml(type, page, totalPages) {
  if (totalPages <= 1) return '';
  const prev = Math.max(1, page - 1);
  const next = Math.min(totalPages, page + 1);
  return ` <div class="btn-group btn-group-sm ms-2" role="group">
    <button type="button" class="btn btn-outline-secondary" data-letter-page="${type}" data-page="${prev}" ${page <= 1 ? 'disabled' : ''}>‹</button>
    <span class="btn btn-outline-secondary disabled">${page}/${totalPages}</span>
    <button type="button" class="btn btn-outline-secondary" data-letter-page="${type}" data-page="${next}" ${page >= totalPages ? 'disabled' : ''}>›</button>
  </div>`;
}

function bindPaginationHandlers(type) {
  const footerId = type === 'incoming' ? 'incomingTableFooter' : 'outgoingTableFooter';
  document.getElementById(footerId)?.querySelectorAll('[data-letter-page]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const p = Number(btn.dataset.page);
      if (type === 'incoming') { incomingPage = p; renderIncoming(); }
      else { outgoingPage = p; renderOutgoing(); }
    });
  });
}

const incRecipientInput = document.getElementById('incRecipientInput');
const incRecipientAdd = document.getElementById('incRecipientAdd');
const incRecipientsList = document.getElementById('incRecipientsList');
const outRecipientInput = document.getElementById('outRecipientInput');
const outRecipientAdd = document.getElementById('outRecipientAdd');
const outRecipientsList = document.getElementById('outRecipientsList');
const letterRecipientsModal = document.getElementById('letterRecipientsModal');
const letterRecipientsBody = document.getElementById('letterRecipientsBody');
const btnOpenOutgoingFromIncoming = document.getElementById('btnOpenOutgoingFromIncoming');
const linkOutgoingModalEl = document.getElementById('linkOutgoingModal');
const linkOutgoingList = document.getElementById('linkOutgoingList');
const linkOutgoingSearch = document.getElementById('linkOutgoingSearch');
const linkOutgoingConfirm = document.getElementById('linkOutgoingConfirm');

const todayISO = new Date().toISOString().slice(0, 10);
function populateMemberSelect(selectEl) {
  if (!selectEl) return;
  const previousSelection = Array.from(selectEl.selectedOptions).map(opt => Number(opt.value));
  selectEl.innerHTML = '';
  const fragment = document.createDocumentFragment();
  (window.membersCatalog || []).forEach(member => {
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
  if (!canWrite()) {
    window.showError?.(t('letters.no_permission', 'Недостаточно прав для сохранения'));
    return;
  }
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
    const user = window.getSessionUser?.() || window.sessionUser;
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
      showSuccess(t('letters.incoming_saved', 'Входящее письмо успешно сохранено'));
    }
  } catch (error) {
    console.error('Ошибка добавления входящего письма', error);
    if (window.showError) {
      showError(t('letters.save_incoming_error', 'Не удалось сохранить входящее письмо'));
    } else {
      alert(t('letters.save_incoming_error', 'Не удалось сохранить входящее письмо'));
    }
  }
}

async function handleOutgoingSubmit(event) {
  event.preventDefault();
  if (!canWrite()) {
    window.showError?.(t('letters.no_permission', 'Недостаточно прав для сохранения'));
    return;
  }
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
        showWarning(t('letters.add_recipient_warn', 'Добавьте хотя бы одного адресата или укажите организацию'));
      } else {
        alert(t('letters.add_recipient_warn', 'Добавьте хотя бы одного адресата или укажите организацию'));
      }
      return;
    }
    const organization = (incomingItem?.organization || recipientsList[0] || '').trim();
    if (!organization) {
      if (window.showWarning) {
        showWarning(t('letters.org_required', 'Укажите организацию получателя'));
      } else {
        alert(t('letters.org_required', 'Укажите организацию получателя'));
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
    const user = window.getSessionUser?.() || window.sessionUser;
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
      showSuccess(t('letters.outgoing_saved', 'Исходящее письмо успешно сохранено'));
    }
  } catch (error) {
    console.error('Ошибка добавления исходящего письма', error);
    if (window.showError) {
      showError(t('letters.save_outgoing_error', 'Не удалось сохранить исходящее письмо'));
    } else {
      alert(t('letters.save_outgoing_error', 'Не удалось сохранить исходящее письмо'));
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
  if (!canWrite()) return;
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
  if (!canWrite()) return;
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
        `<option value="${i.id}">[${escapeHtml(cat)}] Вх.${i.seq} • ${formatDateISOtoRus(i.date)} • ${escapeHtml(i.organization)} • ${escapeHtml(i.kkNumber)}${closed}</option>`
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
async function viewLetterDetail(type, id) {
  const modalEl = document.getElementById('letterRecipientsModal');
  const body = document.getElementById('letterRecipientsBody');
  const titleEl = modalEl?.querySelector('.modal-title');
  if (!modalEl || !body) return;
  try {
    const letter = await fetchLetterDetail(type, Number(id));
    const label = type === 'incoming' ? t('letters.type_incoming', 'Входящее') : t('letters.type_outgoing', 'Исходящее');
    if (titleEl) titleEl.textContent = `${label} №${letter.seq || id}`;
    const members = (letter.members || []).map((m) => m.full_name).join(', ') || '—';
    const recipients = (letter.recipients || []).length
      ? (letter.recipients || []).map((name) => `<span class="badge rounded-pill bg-light text-dark border me-1 mb-1">${escapeHtml(name)}</span>`).join('')
      : '<span class="text-muted">—</span>';
    body.innerHTML = `
      <dl class="row small mb-0">
        <dt class="col-sm-4">Дата</dt><dd class="col-sm-8">${escapeHtml((letter.date || '').slice(0, 10))}</dd>
        <dt class="col-sm-4">Организация</dt><dd class="col-sm-8">${escapeHtml(letter.organization || '—')}</dd>
        <dt class="col-sm-4">Тема</dt><dd class="col-sm-8">${escapeHtml(letter.subject || '—')}</dd>
        <dt class="col-sm-4">Ответственные</dt><dd class="col-sm-8">${escapeHtml(members)}</dd>
        <dt class="col-sm-4">Адресаты</dt><dd class="col-sm-8">${recipients}</dd>
        ${letter.note ? `<dt class="col-sm-4">Примечание</dt><dd class="col-sm-8">${escapeHtml(letter.note)}</dd>` : ''}
      </dl>`;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
  } catch (e) {
    console.error(e);
    window.showError?.('Не удалось загрузить письмо') || alert('Не удалось загрузить письмо');
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
  const isOverdue = (i) => window.AppUtils?.isLetterOverdue?.(i) ?? false;

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
        return isPending && isOverdue(i);
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

  const totalFiltered = filteredIncoming.length;
  const totalPages = Math.max(1, Math.ceil(totalFiltered / LETTERS_PAGE_SIZE));
  if (incomingPage > totalPages) incomingPage = totalPages;
  const pageItems = filteredIncoming.slice((incomingPage - 1) * LETTERS_PAGE_SIZE, incomingPage * LETTERS_PAGE_SIZE);

  const rowsData = pageItems.map((i) => {
      const linked = i.linkedOutgoingId ? store.outgoing.find(o => o.id === i.linkedOutgoingId) : null;
      const linkHtml = linked
        ? `<span class="badge badge-outgoing rounded-pill">${t('letters.linked_reply', 'Ответ')}: ${linked.outgoingNumber ? escapeHtml(linked.outgoingNumber) : 'Исх.' + escapeHtml(String(linked.seq))}</span>`
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
      const dueDate = window.addWorkingDays(i.date, 15);
      const warnFrom = window.subtractWorkingDays(dueDate, 3);
      const today = new Date();
      const isDanger = !i.linkedOutgoingId && today > dueDate;
      const isWarn = !i.linkedOutgoingId && today >= warnFrom && today <= dueDate;
      const dueBadgeClass = isDanger ? 'badge-due-danger' : (isWarn ? 'badge-due' : 'text-bg-secondary');
      const dueHtml = `<span class="badge ${dueBadgeClass} rounded-pill">Срок: ${formatDateISOtoRus(dueDate.toISOString().slice(0,10))}</span>`;

      return `
        <tr>
          <td class="batch-checkbox-col"><input type="checkbox" class="form-check-input batch-check-incoming" data-id="${i.id}"></td>
          <td class="text-nowrap" data-label="Рег. №">Вх.${i.seq}</td>
          <td data-label="Дата">${formatDateISOtoRus(i.date)}</td>
          <td data-label="Организация">${escapeHtml(i.organization)}</td>
          <td class="text-nowrap" data-label="Категория"><span class="badge ${catClass}">${categoryDisplay(cat)}</span></td>
          <td class="text-nowrap" data-label="Номер"><span class="badge badge-incoming">${escapeHtml(i.kkNumber)}</span></td>
          <td data-label="Тема">${escapeHtml(i.subject || "")}</td>
          <td data-label="Адресаты" id="${recipientsCellId}"></td>
          <td data-label="Срок">${dueHtml}</td>
          <td data-label="Ответственные" id="${membersCellId}"></td>
          <td data-label="Сканы" id="${scansCellId}"></td>
          <td data-label="Ответ">${linkHtml}</td>
          <td class="text-end table-actions" data-label="">
            <button class="btn btn-sm btn-outline-info" data-action="detail-incoming" data-id="${i.id}" title="Детали / Комментарии"><i class="bi bi-chat-dots"></i></button>
            <button class="btn btn-sm btn-outline-secondary" data-action="print-incoming" data-id="${i.id}" title="Печать"><i class="bi bi-printer"></i></button>
            <button class="btn btn-sm btn-outline-primary" data-action="edit-incoming" data-id="${i.id}" title="Изменить"><i class="bi bi-pencil"></i></button>
            ${canDelete() ? `<button class="btn btn-sm btn-outline-danger" data-action="del-incoming" data-id="${i.id}" title="Удалить"><i class="bi bi-trash"></i></button>` : ''}
          </td>
        </tr>`;
    });
  tableIncomingBody.innerHTML = rowsData.length
    ? rowsData.join("")
    : '<tr><td colspan="13" class="text-center text-muted py-4">По выбранным фильтрам писем не найдено</td></tr>';
  
  // Рендерим дополнительные ячейки после создания строк
  pageItems.forEach((i) => {
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
  
  tableIncomingBody.querySelectorAll("[data-action='detail-incoming']").forEach((btn) => {
    btn.addEventListener('click', () => openLetterDetailTabs('incoming', btn.dataset.id));
  });
  tableIncomingBody.querySelectorAll("[data-action='del-incoming']").forEach((btn) => {
    btn.addEventListener("click", () => deleteIncoming(btn.dataset.id));
  });
  tableIncomingBody.querySelectorAll("[data-action='edit-incoming']").forEach((btn) => {
    btn.addEventListener("click", () => editIncoming(btn.dataset.id));
  });
  tableIncomingBody.querySelectorAll("[data-action='print-incoming']").forEach((btn) => {
    btn.addEventListener("click", () => window.open(`/api/letter_print.php?type=incoming&id=${btn.dataset.id}`, '_blank'));
  });
  tableIncomingBody.querySelectorAll("[data-action='respond-incoming']").forEach((btn) => {
    btn.addEventListener("click", () => startOutgoingResponse(btn.dataset.id));
  });
  tableIncomingBody.querySelectorAll("[data-action='attach-outgoing']").forEach((btn) => {
    btn.addEventListener("click", () => openLinkOutgoingModal({ type: 'incoming-table', incomingId: btn.dataset.id }));
  });
  // Batch checkboxes
  tableIncomingBody.querySelectorAll('.batch-check-incoming').forEach((cb) => {
    cb.addEventListener('change', () => updateBatchBar('incoming'));
  });
  document.getElementById('selectAllIncoming')?.addEventListener('change', (e) => {
    tableIncomingBody.querySelectorAll('.batch-check-incoming').forEach((cb) => { cb.checked = e.target.checked; });
    updateBatchBar('incoming');
  });

  if (typeof window.renderTableFooter === 'function') {
    window.renderTableFooter(
      'incomingTableFooter',
      pageItems.length,
      totalFiltered,
      'писем',
      buildPaginationHtml('incoming', incomingPage, totalPages)
    );
    bindPaginationHandlers('incoming');
  }
  if (typeof window.updateNotifyBadge === 'function') window.updateNotifyBadge();
  if (typeof window.updatePageHeader === 'function') {
    window.updatePageHeader(document.querySelector('#sidebarNav .nav-link.active')?.id || 'tab-incoming');
  }
}

function openOutgoingTab() {
  document.getElementById('tab-outgoing')?.click();
  document.getElementById('pane-outgoing')?.scrollIntoView({ behavior: 'smooth' });
  setTimeout(() => outLinkedIncoming?.focus(), 200);
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
    if (typeof window.scrollToCollapse === 'function') {
      window.scrollToCollapse('#collapseOutgoingForm');
    }
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
      showSuccess(t('letters.linked_ok', 'Исходящее письмо успешно привязано'));
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

  const totalFilteredOut = filteredOutgoing.length;
  const totalPagesOut = Math.max(1, Math.ceil(totalFilteredOut / LETTERS_PAGE_SIZE));
  if (outgoingPage > totalPagesOut) outgoingPage = totalPagesOut;
  const pageItemsOut = filteredOutgoing.slice((outgoingPage - 1) * LETTERS_PAGE_SIZE, outgoingPage * LETTERS_PAGE_SIZE);

  const rows = pageItemsOut.map((i) => {
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
          <td class="batch-checkbox-col"><input type="checkbox" class="form-check-input batch-check-outgoing" data-id="${i.id}"></td>
          <td class="text-nowrap" data-label="Порядк. №">Исх.${i.seq}</td>
          <td data-label="Дата">${formatDateISOtoRus(i.date)}</td>
          <td class="text-nowrap" data-label="Исходящий №"><span class="badge badge-outgoing">${numberLabel}</span></td>
          <td data-label="Организация">${escapeHtml(incoming?.organization || i.organization || "")}</td>
          <td class="text-nowrap" data-label="Категория"><span class="badge ${catClass}">${categoryDisplay(cat)}</span></td>
          <td class="text-nowrap" data-label="Связь">${linkInfo}</td>
          <td data-label="Тема">${escapeHtml(i.subject || "")}</td>
          <td data-label="Адресаты" id="${recipientsCellId}"></td>
          <td data-label="Ответственные" id="${membersCellId}"></td>
          <td data-label="Сканы" id="${scansCellId}"></td>
          <td class="text-end table-actions" data-label="">
            <button class="btn btn-sm btn-outline-info" title="Детали / Комментарии" data-action="detail-outgoing" data-id="${i.id}">
              <i class="bi bi-chat-dots"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary" title="Печать" data-action="print-outgoing" data-id="${i.id}">
              <i class="bi bi-printer"></i>
            </button>
            <button class="btn btn-sm btn-outline-primary" title="Изменить" data-action="edit-outgoing" data-id="${i.id}">
              <i class="bi bi-pencil"></i>
            </button>
            ${canDelete() ? `<button class="btn btn-sm btn-outline-danger" title="Удалить" data-action="del-outgoing" data-id="${i.id}">
              <i class="bi bi-trash"></i>
            </button>` : ''}
          </td>
        </tr>`;
    });
  tableOutgoingBody.innerHTML = rows.join("");
  
  // Рендерим сканы и ответственных после создания строк
  pageItemsOut.forEach((i) => {
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
  
  tableOutgoingBody.querySelectorAll("[data-action='detail-outgoing']").forEach((btn) => {
    btn.addEventListener('click', () => openLetterDetailTabs('outgoing', btn.dataset.id));
  });
  tableOutgoingBody.querySelectorAll("[data-action='del-outgoing']").forEach((btn) => {
    btn.addEventListener("click", () => deleteOutgoing(btn.dataset.id));
  });
  tableOutgoingBody.querySelectorAll("[data-action='edit-outgoing']").forEach((btn) => {
    btn.addEventListener("click", () => editOutgoing(btn.dataset.id));
  });
  tableOutgoingBody.querySelectorAll("[data-action='print-outgoing']").forEach((btn) => {
    btn.addEventListener("click", () => window.open(`/api/letter_print.php?type=outgoing&id=${btn.dataset.id}`, '_blank'));
  });
  // Batch checkboxes
  tableOutgoingBody.querySelectorAll('.batch-check-outgoing').forEach((cb) => {
    cb.addEventListener('change', () => updateBatchBar('outgoing'));
  });
  document.getElementById('selectAllOutgoing')?.addEventListener('change', (e) => {
    tableOutgoingBody.querySelectorAll('.batch-check-outgoing').forEach((cb) => { cb.checked = e.target.checked; });
    updateBatchBar('outgoing');
  });

  if (typeof window.renderTableFooter === 'function') {
    window.renderTableFooter(
      'outgoingTableFooter',
      pageItemsOut.length,
      totalFilteredOut,
      'писем',
      buildPaginationHtml('outgoing', outgoingPage, totalPagesOut)
    );
    bindPaginationHandlers('outgoing');
  }
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


  function bindLettersEventListeners() {
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

      const statusEl = document.createElement('div');
      statusEl.className = 'text-muted small mb-2';
      statusEl.textContent = 'Обработка файлов...';
      incScansPreview.appendChild(statusEl);

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
      statusEl.remove();
      
      e.target.value = '';
    });
  }

  // Загрузка сканов (исходящие)
  if (outScans) {
    outScans.addEventListener('change', async (e) => {
      const files = Array.from(e.target.files);
      if (files.length === 0) return;

      const statusEl = document.createElement('div');
      statusEl.className = 'text-muted small mb-2';
      statusEl.textContent = 'Обработка файлов...';
      outScansPreview.appendChild(statusEl);

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
      statusEl.remove();
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

  }

  function bindLettersFilters() {
    const resetIncoming = () => { incomingPage = 1; renderIncoming(); };
    const resetOutgoing = () => { outgoingPage = 1; renderOutgoing(); };
    if (searchIncoming) searchIncoming.addEventListener('input', debounce(resetIncoming, 300));
    if (searchOutgoing) searchOutgoing.addEventListener('input', debounce(resetOutgoing, 300));
    if (filterYearIncoming) filterYearIncoming.addEventListener('change', resetIncoming);
    if (filterYearOutgoing) filterYearOutgoing.addEventListener('change', resetOutgoing);
    if (filterMonthIncoming) filterMonthIncoming.addEventListener('change', resetIncoming);
    if (filterMonthOutgoing) filterMonthOutgoing.addEventListener('change', resetOutgoing);
    if (filterStatusIncoming) filterStatusIncoming.addEventListener('change', resetIncoming);
    if (filterScansIncoming) filterScansIncoming.addEventListener('change', resetIncoming);
    if (filterScansOutgoing) filterScansOutgoing.addEventListener('change', resetOutgoing);
    if (filterRecipientIncoming) filterRecipientIncoming.addEventListener('change', resetIncoming);
    if (filterRecipientOutgoing) filterRecipientOutgoing.addEventListener('change', resetOutgoing);
  }

  // ── Batch operations ──────────────────────────────────────────────────────

  function getSelectedIds(type) {
    const sel = type === 'incoming' ? '.batch-check-incoming' : '.batch-check-outgoing';
    return Array.from(document.querySelectorAll(sel + ':checked')).map((cb) => Number(cb.dataset.id));
  }

  function updateBatchBar(type) {
    const ids = getSelectedIds(type);
    const bar = document.getElementById(type === 'incoming' ? 'batchBarIncoming' : 'batchBarOutgoing');
    const count = document.getElementById(type === 'incoming' ? 'batchCountIncoming' : 'batchCountOutgoing');
    if (!bar) return;
    bar.classList.toggle('d-none', ids.length === 0);
    if (count) count.textContent = `${ids.length} выбрано`;
  }

  function batchExportSelected(type) {
    const ids = getSelectedIds(type);
    if (!ids.length) return;
    const items = type === 'incoming'
      ? store.incoming.filter((i) => ids.includes(Number(i.id)))
      : store.outgoing.filter((i) => ids.includes(Number(i.id)));
    const payload = type === 'incoming'
      ? { incoming: items, outgoing: [] }
      : { incoming: [], outgoing: items };
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${type}_selected_${new Date().toISOString().slice(0, 10)}.json`;
    a.click();
    URL.revokeObjectURL(url);
  }

  function bindBatchActions() {
    document.getElementById('batchExportIncoming')?.addEventListener('click', () => batchExportSelected('incoming'));
    document.getElementById('batchExportOutgoing')?.addEventListener('click', () => batchExportSelected('outgoing'));
    document.getElementById('batchClearIncoming')?.addEventListener('click', () => {
      document.querySelectorAll('.batch-check-incoming').forEach((cb) => { cb.checked = false; });
      const all = document.getElementById('selectAllIncoming');
      if (all) all.checked = false;
      updateBatchBar('incoming');
    });
    document.getElementById('batchClearOutgoing')?.addEventListener('click', () => {
      document.querySelectorAll('.batch-check-outgoing').forEach((cb) => { cb.checked = false; });
      const all = document.getElementById('selectAllOutgoing');
      if (all) all.checked = false;
      updateBatchBar('outgoing');
    });
  }

  function initLettersUI() {
    if (incDate) incDate.value = todayISO;
    if (outDate) outDate.value = todayISO;
    if (outType) outType.value = 'gov';
    updateIncomingSeqPlaceholder();
    bindLettersEventListeners();
    bindLettersFilters();
    bindBatchActions();
  }

  window.editIncoming = editIncoming;
  window.editOutgoing = editOutgoing;
  window.viewLetterDetail = viewLetterDetail;
  window.renderIncoming = renderIncoming;
  window.renderOutgoing = renderOutgoing;
  window.refreshLetters = refreshLetters;
  window.renderIncomingOptions = renderIncomingOptions;
  window.bindLettersEventListeners = bindLettersEventListeners;
  window.initLettersUI = initLettersUI;
  window.populateMemberSelects = populateMemberSelects;
  window.renderIncomingRecipients = renderIncomingRecipients;
  window.renderOutgoingRecipients = renderOutgoingRecipients;

  // ── Letter Detail Tabs Modal (Comments + History) ─────────────────────────

  let _detailModal = null;
  let _detailType = null;
  let _detailId = null;

  function getDetailModal() {
    if (!_detailModal) {
      const el = document.getElementById('letterDetailTabsModal');
      if (el) _detailModal = new bootstrap.Modal(el);
    }
    return _detailModal;
  }

  async function openLetterDetailTabs(type, id) {
    _detailType = type;
    _detailId = id;

    const items = type === 'incoming' ? (store.incoming || []) : (store.outgoing || []);
    const letter = items.find((x) => String(x.id) === String(id));

    const titleEl = document.getElementById('letterDetailTabsTitle');
    if (titleEl) {
      const prefix = type === 'incoming' ? 'Вх.' : 'Исх.';
      titleEl.textContent = letter
        ? `${prefix}${letter.seq} — ${letter.organization || ''}`.trim()
        : `${prefix}${id}`;
    }

    // Info pane
    const infoEl = document.getElementById('ldInfoBody');
    if (infoEl && letter) {
      const members = (letter.members || []).map((m) => m.full_name).join(', ') || '—';
      infoEl.innerHTML = `
        <dl class="row mb-0">
          <dt class="col-sm-4">Дата</dt><dd class="col-sm-8">${formatDateISOtoRus(letter.date)}</dd>
          <dt class="col-sm-4">Организация</dt><dd class="col-sm-8">${escapeHtml(letter.organization || '—')}</dd>
          <dt class="col-sm-4">Тема</dt><dd class="col-sm-8">${escapeHtml(letter.subject || '—')}</dd>
          <dt class="col-sm-4">Ответственные</dt><dd class="col-sm-8">${escapeHtml(members)}</dd>
          ${letter.note ? `<dt class="col-sm-4">Примечание</dt><dd class="col-sm-8">${escapeHtml(letter.note)}</dd>` : ''}
        </dl>`;
    }

    getDetailModal()?.show();

    // Reset to info tab
    const infoTab = document.getElementById('ldtab-info');
    if (infoTab) bootstrap.Tab.getOrCreateInstance(infoTab).show();

    // Bind comment submit once per open
    const submitBtn = document.getElementById('ldCommentSubmit');
    if (submitBtn) {
      submitBtn.onclick = () => submitComment();
    }

    // Bind tab switches for lazy loading
    const commentsTabBtn = document.getElementById('ldtab-comments');
    if (commentsTabBtn) {
      commentsTabBtn.onclick = () => loadComments(_detailType, _detailId);
    }
    const historyTabBtn = document.getElementById('ldtab-history');
    if (historyTabBtn) {
      historyTabBtn.onclick = () => loadHistory(_detailType, _detailId);
    }
  }

  async function loadComments(type, id) {
    const el = document.getElementById('ldCommentsList');
    if (!el) return;
    el.innerHTML = '<div class="text-muted small text-center py-3">Загрузка...</div>';
    try {
      const data = await apiFetch(`${API_BASE}/comments.php?letter_type=${encodeURIComponent(type)}&letter_id=${encodeURIComponent(id)}`);
      const comments = data.comments || data || [];
      const badge = document.getElementById('ldCommentsBadge');
      if (badge) badge.textContent = comments.length;

      if (!comments.length) {
        el.innerHTML = '<p class="text-muted small text-center py-3">Комментариев пока нет.</p>';
        return;
      }
      el.innerHTML = comments.map((c) => {
        const canDel = window.sessionUser?.role === 'admin' || window.sessionUser?.role === 'manager'
          || String(c.user_id) === String(window.sessionUser?.id);
        return `<div class="border rounded p-2 mb-2 comment-item" data-comment-id="${c.id}">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <strong class="small">${escapeHtml(c.user_name || '—')}</strong>
              <span class="text-muted small ms-2">${formatDateISOtoRus((c.created_at || '').slice(0,10))}</span>
            </div>
            ${canDel ? `<button class="btn btn-sm btn-link text-danger p-0 ms-1 delete-comment-btn" data-id="${c.id}" title="Удалить"><i class="bi bi-x-lg"></i></button>` : ''}
          </div>
          <div class="small mt-1">${escapeHtml(c.comment)}</div>
        </div>`;
      }).join('');

      el.querySelectorAll('.delete-comment-btn').forEach((btn) => {
        btn.addEventListener('click', () => deleteComment(btn.dataset.id, type, id));
      });
    } catch (err) {
      el.innerHTML = `<div class="text-danger small py-2">${escapeHtml(err.message)}</div>`;
    }
  }

  async function submitComment() {
    const input = document.getElementById('ldCommentInput');
    if (!input) return;
    const text = input.value.trim();
    if (!text) return;
    try {
      await apiFetch(`${API_BASE}/comments.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ letter_type: _detailType, letter_id: _detailId, comment: text }),
      });
      input.value = '';
      await loadComments(_detailType, _detailId);
    } catch (err) {
      window.showError?.(err.message);
    }
  }

  async function deleteComment(commentId, type, id) {
    if (!confirm('Удалить комментарий?')) return;
    try {
      await apiFetch(`${API_BASE}/comments.php?id=${encodeURIComponent(commentId)}`, { method: 'DELETE' });
      await loadComments(type, id);
    } catch (err) {
      window.showError?.(err.message);
    }
  }

  async function loadHistory(type, id) {
    const el = document.getElementById('ldHistoryBody');
    if (!el) return;
    el.innerHTML = '<div class="text-muted small text-center py-3">Загрузка...</div>';
    try {
      const tableName = type === 'incoming' ? 'incoming_letters' : 'outgoing_letters';
      const data = await apiFetch(`${API_BASE}/audit_logs.php?table_name=${encodeURIComponent(tableName)}&record_id=${encodeURIComponent(id)}&limit=100`);
      const items = data.items || [];
      if (!items.length) {
        el.innerHTML = '<p class="text-muted small text-center py-3">История изменений отсутствует.</p>';
        return;
      }
      el.innerHTML = items.map((row) => {
        const op = row.operation || row.action || '';
        const opColor = op === 'DELETE' ? 'text-danger' : op === 'INSERT' || op === 'CREATE' ? 'text-success' : 'text-primary';
        const dt = row.created_at ? formatDateISOtoRus(row.created_at.slice(0, 10)) : '—';
        const user = row.user_name || row.user_login || '—';
        let diffHtml = '';
        if (row.old_values || row.new_values) {
          const oldV = typeof row.old_values === 'object' ? row.old_values : {};
          const newV = typeof row.new_values === 'object' ? row.new_values : {};
          const keys = Array.from(new Set([...Object.keys(oldV || {}), ...Object.keys(newV || {})]));
          const changed = keys.filter((k) => String(oldV[k] ?? '') !== String(newV[k] ?? ''));
          if (changed.length) {
            diffHtml = '<ul class="list-unstyled mb-0 mt-1 small">' + changed.map((k) =>
              `<li><code>${escapeHtml(k)}</code>: <span class="text-muted">${escapeHtml(String(oldV[k] ?? ''))}</span> → <strong>${escapeHtml(String(newV[k] ?? ''))}</strong></li>`
            ).join('') + '</ul>';
          }
        }
        return `<div class="border-start border-3 ps-3 mb-3 ${opColor.replace('text-', 'border-')}">
          <div class="small fw-semibold ${opColor}">${escapeHtml(op)}</div>
          <div class="small text-muted">${escapeHtml(dt)} · ${escapeHtml(user)}</div>
          ${diffHtml}
        </div>`;
      }).join('');
    } catch (err) {
      el.innerHTML = `<div class="text-danger small py-2">${escapeHtml(err.message)}</div>`;
    }
  }

  window.openLetterDetailTabs = openLetterDetailTabs;

  // ── My Letters ────────────────────────────────────────────────────────────

  function renderMyLetters() {
    const tbody = document.getElementById('myLettersBody');
    const badge = document.getElementById('myLettersBadge');
    if (!tbody) return;

    const memberId = window.sessionUser?.member_id;
    if (!memberId) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Нет привязки к члену ОС</td></tr>';
      if (badge) badge.textContent = '0';
      return;
    }

    const filterByMember = (letters) => (letters || []).filter((l) =>
      (l.members || []).some((m) => m.member_id === memberId || m.id === memberId)
    );

    const mine = [
      ...filterByMember(store.incoming).map((l) => ({ ...l, _type: 'incoming' })),
      ...filterByMember(store.outgoing).map((l) => ({ ...l, _type: 'outgoing' })),
    ].sort((a, b) => (b.date || '').localeCompare(a.date || ''));

    if (badge) badge.textContent = mine.length;

    if (!mine.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Нет писем</td></tr>';
      return;
    }

    const today = new Date();
    tbody.innerHTML = mine.map((l) => {
      const isInc = l._type === 'incoming';
      const typeBadge = isInc
        ? '<span class="badge badge-incoming">Вх.</span>'
        : '<span class="badge badge-outgoing">Исх.</span>';
      const seq = isInc ? `Вх.${l.seq}` : `Исх.${l.seq}`;
      const due = isInc ? window.AppUtils?.getLetterDueDate?.(l.date) : null;
      let statusHtml = '';
      if (due) {
        const overdue = window.AppUtils?.isLetterOverdue?.(l, today);
        const soon = window.AppUtils?.isLetterDueSoon?.(l, today);
        statusHtml = overdue
          ? '<span class="badge bg-danger">Просрочено</span>'
          : soon
            ? '<span class="badge bg-warning text-dark">Скоро срок</span>'
            : '<span class="badge bg-success">В срок</span>';
      }
      return `<tr style="cursor:pointer" onclick="openLetterDetailTabs('${l._type}', '${l.id}')">
        <td>${typeBadge}</td>
        <td class="text-nowrap">${escapeHtml(seq)}</td>
        <td class="text-nowrap">${formatDateISOtoRus(l.date)}</td>
        <td>${escapeHtml(l.organization || '—')}</td>
        <td class="text-truncate" style="max-width:200px">${escapeHtml(l.subject || '—')}</td>
        <td>${statusHtml}</td>
      </tr>`;
    }).join('');
  }

  window.renderMyLetters = renderMyLetters;

  document.addEventListener('shown.bs.tab', (e) => {
    if (e.target?.id === 'tab-my-letters') renderMyLetters();
  });

  // ── Archive ───────────────────────────────────────────────────────────────

  let _archiveType = 'incoming';

  async function loadArchive(type) {
    _archiveType = type || _archiveType;
    const tbody = document.getElementById('archiveBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Загрузка...</td></tr>';

    document.querySelectorAll('[data-archive-type]').forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.archiveType === _archiveType);
      btn.classList.toggle('btn-outline-primary', btn.dataset.archiveType === 'incoming');
      btn.classList.toggle('btn-outline-success', btn.dataset.archiveType === 'outgoing');
    });

    try {
      const data = await apiFetch(`${API_BASE}/letters.php?type=${_archiveType}&archived=1&limit=200`);
      const items = data.items || data || [];
      if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Архив пуст</td></tr>';
        return;
      }
      const isAdmin = window.sessionUser?.role === 'admin';
      tbody.innerHTML = items.map((l) => `
        <tr>
          <td class="text-nowrap">${_archiveType === 'incoming' ? 'Вх.' : 'Исх.'}${escapeHtml(String(l.seq || ''))}</td>
          <td class="text-nowrap">${formatDateISOtoRus(l.date)}</td>
          <td>${escapeHtml(l.organization || '—')}</td>
          <td class="text-truncate" style="max-width:200px">${escapeHtml(l.subject || '—')}</td>
          <td class="text-end">${isAdmin ? `<button class="btn btn-sm btn-outline-success restore-btn" data-id="${l.id}" data-type="${_archiveType}"><i class="bi bi-arrow-counterclockwise"></i> Восстановить</button>` : ''}</td>
        </tr>`).join('');
      tbody.querySelectorAll('.restore-btn').forEach((btn) => {
        btn.addEventListener('click', () => restoreArchived(btn.dataset.type, btn.dataset.id));
      });
    } catch (err) {
      tbody.innerHTML = `<tr><td colspan="5" class="text-danger py-2">${escapeHtml(err.message)}</td></tr>`;
    }
  }

  async function restoreArchived(type, id) {
    if (!confirm('Восстановить письмо из архива?')) return;
    try {
      await apiFetch(`${API_BASE}/letters.php?type=${type}&action=restore`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: Number(id) }),
      });
      window.showSuccess?.('Письмо восстановлено');
      await loadArchive(_archiveType);
      await refreshLetters();
    } catch (err) {
      window.showError?.(err.message);
    }
  }

  window.loadArchive = loadArchive;

  document.addEventListener('shown.bs.tab', (e) => {
    if (e.target?.id === 'tab-archive') loadArchive(_archiveType);
  });

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-archive-type]');
    if (btn && document.getElementById('pane-archive')?.classList.contains('active')) {
      loadArchive(btn.dataset.archiveType);
    }
  });

  // ── Templates ─────────────────────────────────────────────────────────────

  let _templatePickerModal = null;
  let _templateTargetType = null;

  function getTemplatePickerModal() {
    if (!_templatePickerModal) {
      const el = document.getElementById('templatePickerModal');
      if (el) _templatePickerModal = new bootstrap.Modal(el);
    }
    return _templatePickerModal;
  }

  async function openTemplatePicker(type) {
    _templateTargetType = type;
    const listEl = document.getElementById('templatePickerList');
    if (!listEl) return;
    listEl.innerHTML = '<div class="text-muted text-center py-3">Загрузка шаблонов...</div>';
    getTemplatePickerModal()?.show();
    try {
      const data = await apiFetch(`${API_BASE}/templates.php?letter_type=${encodeURIComponent(type)}`);
      const templates = data.templates || data || [];
      if (!templates.length) {
        listEl.innerHTML = '<div class="text-muted text-center py-3">Шаблоны не найдены</div>';
        return;
      }
      listEl.innerHTML = templates.map((tpl) =>
        `<button class="list-group-item list-group-item-action" data-tpl-id="${tpl.id}">
          <div class="fw-semibold">${escapeHtml(tpl.name)}</div>
          <div class="small text-muted">${escapeHtml(tpl.organization || '')}${tpl.subject ? ` · ${escapeHtml(tpl.subject)}` : ''}</div>
        </button>`
      ).join('');
      listEl.querySelectorAll('[data-tpl-id]').forEach((btn) => {
        const tplId = btn.dataset.tplId;
        btn.addEventListener('click', () => {
          const tpl = templates.find((t) => String(t.id) === String(tplId));
          if (tpl) applyTemplate(tpl, _templateTargetType);
          getTemplatePickerModal()?.hide();
        });
      });
    } catch (err) {
      listEl.innerHTML = `<div class="text-danger py-2">${escapeHtml(err.message)}</div>`;
    }
  }

  function applyTemplate(tpl, type) {
    if (type === 'incoming') {
      const orgEl = document.getElementById('incOrg');
      const subjEl = document.getElementById('incSubject');
      const noteEl = document.getElementById('incNote');
      const catEl = document.getElementById('incType');
      if (orgEl && tpl.organization) orgEl.value = tpl.organization;
      if (subjEl && tpl.subject) subjEl.value = tpl.subject;
      if (noteEl && tpl.note) noteEl.value = tpl.note;
      if (catEl && tpl.category) catEl.value = tpl.category;
    } else {
      const orgEl = document.getElementById('outOrg');
      const subjEl = document.getElementById('outSubject');
      const noteEl = document.getElementById('outNote');
      if (orgEl && tpl.organization) orgEl.value = tpl.organization;
      if (subjEl && tpl.subject) subjEl.value = tpl.subject;
      if (noteEl && tpl.note) noteEl.value = tpl.note;
    }
    window.showSuccess?.('Шаблон применён');
  }

  async function saveCurrentFormAsTemplate(type) {
    const name = prompt('Название шаблона:');
    if (!name || !name.trim()) return;
    let organization, subject, note, category;
    if (type === 'incoming') {
      organization = document.getElementById('incOrg')?.value.trim() || '';
      subject = document.getElementById('incSubject')?.value.trim() || '';
      note = document.getElementById('incNote')?.value.trim() || '';
      category = document.getElementById('incType')?.value || 'KK';
    } else {
      organization = document.getElementById('outOrg')?.value.trim() || '';
      subject = document.getElementById('outSubject')?.value.trim() || '';
      note = document.getElementById('outNote')?.value.trim() || '';
      category = 'KK';
    }
    try {
      await apiFetch(`${API_BASE}/templates.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name.trim(), letter_type: type, organization, subject, note, category }),
      });
      window.showSuccess?.('Шаблон сохранён');
    } catch (err) {
      window.showError?.(err.message);
    }
  }

  window.openTemplatePicker = openTemplatePicker;
  window.saveCurrentFormAsTemplate = saveCurrentFormAsTemplate;
})(window);
