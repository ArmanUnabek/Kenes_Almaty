(function (window) {
  const ALLOWED_TYPES = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif',
  ];
  const ALLOWED_EXTENSIONS = ['.pdf', '.jpg', '.jpeg', '.png', '.webp', '.gif'];
  const MAX_FILE_SIZE = 50 * 1024 * 1024;

  let dragCounter = 0;

  function fileAllowed(file) {
    if (file.size > MAX_FILE_SIZE) return false;
    if (ALLOWED_TYPES.includes(file.type)) return true;
    const ext = '.' + file.name.split('.').pop().toLowerCase();
    return ALLOWED_EXTENSIONS.includes(ext);
  }

  function getLetterIdFromRow(el) {
    const row = el.closest('tr');
    if (!row) return null;
    const btn = row.querySelector('[data-action^="edit-"][data-id]');
    if (btn) return { id: Number(btn.dataset.id), type: btn.dataset.action.replace('edit-', '') };
    const anyBtn = row.querySelector('[data-id]');
    if (anyBtn) {
      const action = anyBtn.dataset.action || '';
      if (action.includes('incoming')) return { id: Number(anyBtn.dataset.id), type: 'incoming' };
      if (action.includes('outgoing')) return { id: Number(anyBtn.dataset.id), type: 'outgoing' };
    }
    return null;
  }

  function readFileAsDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve({
        data: reader.result,
        type: file.type || 'application/octet-stream',
        name: file.name,
        size: file.size,
      });
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }

  function showUploadProgress(row, fileName) {
    let overlay = row.querySelector('.dd-upload-progress');
    if (overlay) overlay.remove();

    overlay = document.createElement('div');
    overlay.className = 'dd-upload-progress';
    overlay.innerHTML = `
      <div class="dd-upload-progress__inner">
        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
        <span class="dd-upload-progress__text">Загрузка: ${fileName}</span>
      </div>
    `;
    row.style.position = 'relative';
    row.appendChild(overlay);
    return overlay;
  }

  function removeUploadProgress(row) {
    const overlay = row.querySelector('.dd-upload-progress');
    if (overlay) overlay.remove();
  }

  async function uploadScanToLetter(letterInfo, file) {
    const scanData = await readFileAsDataUrl(file);

    const payload = {
      id: letterInfo.id,
      field: 'scans',
      value: [scanData],
    };

    const API_BASE = window.AppCore?.API_BASE || '/api';
    const csrfToken = window.AppCore?.csrfToken || window.csrfToken || '';
    const headers = { 'Content-Type': 'application/json' };
    if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

    const resp = await fetch(`${API_BASE}/letters.php?type=${letterInfo.type}`, {
      method: 'PATCH',
      headers,
      body: JSON.stringify(payload),
    });

    if (!resp.ok) {
      const err = await resp.json().catch(() => ({}));
      throw new Error(err.error || 'Ошибка загрузки файла');
    }

    return resp.json();
  }

  function handleDragEnter(e) {
    e.preventDefault();
    const row = e.target.closest('tr');
    if (!row) return;
    const letterInfo = getLetterIdFromRow(row);
    if (!letterInfo) return;

    dragCounter++;
    row.classList.add('dd-drop-active');
  }

  function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
  }

  function handleDragLeave(e) {
    const row = e.target.closest('tr');
    if (!row) return;
    dragCounter--;
    if (dragCounter <= 0) {
      dragCounter = 0;
      row.classList.remove('dd-drop-active');
    }
  }

  async function handleDrop(e) {
    e.preventDefault();
    dragCounter = 0;

    const row = e.target.closest('tr');
    if (!row) return;

    row.classList.remove('dd-drop-active');

    const letterInfo = getLetterIdFromRow(row);
    if (!letterInfo) {
      window.showWarning?.('Перетащите файл на строку письма');
      return;
    }

    const files = Array.from(e.dataTransfer.files).filter(fileAllowed);
    if (files.length === 0) {
      window.showWarning?.('Поддерживаются PDF и изображения (до 50 МБ)');
      return;
    }

    const canWrite = window.canWrite?.() ?? false;
    if (!canWrite) {
      window.showError?.('Недостаточно прав для загрузки файлов');
      return;
    }

    const progress = showUploadProgress(row, files[0].name);

    try {
      for (const file of files) {
        progress.querySelector('.dd-upload-progress__text').textContent = `Загрузка: ${file.name}`;
        await uploadScanToLetter(letterInfo, file);
      }

      window.showSuccess?.(`Файл${files.length > 1 ? 'ы' : ''} прикреплен${files.length > 1 ? 'ы' : ''} к письму`);

      if (typeof window.refreshLetters === 'function') {
        await window.refreshLetters();
      }
    } catch (err) {
      console.error('Drag-drop upload error:', err);
      window.showError?.(err.message || 'Ошибка загрузки файла');
    } finally {
      removeUploadProgress(row);
    }
  }

  function init() {
    document.addEventListener('dragenter', handleDragEnter);
    document.addEventListener('dragover', handleDragOver);
    document.addEventListener('dragleave', handleDragLeave);
    document.addEventListener('drop', handleDrop);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.AppDragDrop = { init, uploadScanToLetter };
})(window);
