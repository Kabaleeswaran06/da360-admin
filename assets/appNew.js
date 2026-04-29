/* ── DA360 Admin Panel — app.js ── */
/* Role: UI-only helpers. All data logic lives in PHP. */

// ── Sidebar Toggle ──────────────────────────────────────────────────────────
const sidebarToggle = document.getElementById('sidebarToggle');
if (sidebarToggle) {
  if (localStorage.getItem('da360_sidebar') === 'collapsed') {
    document.body.classList.add('sidebar-collapsed');
  }
  sidebarToggle.addEventListener('click', () => {
    document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem(
      'da360_sidebar',
      document.body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'
    );
  });
}

// ── Toast ───────────────────────────────────────────────────────────────────
function showToast(msg, duration = 2500) {
  let toast = document.getElementById('toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'toast';
    document.body.appendChild(toast);
  }
  toast.textContent = msg;
  toast.classList.add('show');
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => toast.classList.remove('show'), duration);
}

// ── Copy to clipboard ───────────────────────────────────────────────────────
function copyField(btn, text) {
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = '✓';
    btn.classList.add('copied');
    showToast('Copied to clipboard!');
    setTimeout(() => {
      btn.textContent = '📋';
      btn.classList.remove('copied');
    }, 1800);
  }).catch(() => showToast('Copy failed — try manually.'));
}

// ── Save form: show loading state on submit ─────────────────────────────────
(function () {
  const form    = document.getElementById('content-form');
  const saveBtn = document.getElementById('save-btn');
  if (!form || !saveBtn) return;

  form.addEventListener('submit', () => {
    saveBtn.disabled    = true;
    saveBtn.textContent = 'Saving…';
  });
})();

// ── Auto-dismiss PHP alert banners after 4 s ────────────────────────────────
(function () {
  const alert = document.querySelector('.alert');
  if (!alert) return;
  setTimeout(() => {
    alert.style.transition = 'opacity 0.5s';
    alert.style.opacity    = '0';
    setTimeout(() => alert.remove(), 500);
  }, 4000);
})();
