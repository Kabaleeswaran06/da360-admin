<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content" data-page="guestfaculty-import">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Guest Faculty Import</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Import <span>Guest Faculty</span></h1>
        <p class="page-subtitle">Paste your TypeScript array to bulk-import guest faculty members</p>
      </div>
    </div>
  </div>

  <!-- Step 1: Paste -->
  <div class="import-card" id="step-paste">
    <div class="import-card-header"><span class="step-num">1</span> Paste your JS / TypeScript array</div>
    <div class="import-card-body">
      <p class="hint-text">
        Paste the array directly — with or without <code>export const … =</code>.
        Commented-out entries <code>// { ... }</code> are automatically skipped.
      </p>
      <textarea id="paste-area" spellcheck="false" placeholder="[&#10;  {&#10;    name: 'Rajesh Choudhury',&#10;    namePopup: 'Rajesh &lt;br/&gt; Choudhury',&#10;    title: '...',&#10;    ...&#10;  },&#10;  ...&#10;];"></textarea>
      <div class="import-actions" style="margin-top:12px;">
        <button class="btn btn-primary" id="parse-btn">🔍 Parse &amp; Preview</button>
        <span class="parse-err" id="parse-err"></span>
      </div>
    </div>
  </div>

  <!-- Step 2: Preview -->
  <div class="import-card hidden" id="step-preview">
    <div class="import-card-header"><span class="step-num">2</span> Preview — review before importing</div>
    <div class="import-card-body">
      <div class="table-wrap">
        <table class="preview-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Title</th>
              <th>Logos</th>
              <th>Profile Image</th>
            </tr>
          </thead>
          <tbody id="preview-body"></tbody>
        </table>
      </div>
      <div class="import-actions" style="margin-top:20px;">
        <button class="btn btn-secondary" id="back-btn">← Back</button>
        <button class="btn btn-success"   id="import-btn">⬆️ Import All</button>
        <span class="parse-err" id="import-err"></span>
      </div>
    </div>
  </div>

  <!-- Step 3: Result -->
  <div class="import-card hidden" id="step-result">
    <div class="import-card-header"><span class="step-num">3</span> Import Complete</div>
    <div class="import-card-body">
      <div id="result-list"></div>
      <div class="import-actions" style="margin-top:20px;">
        <a href="/da360-admin/globalwise.php" class="btn btn-secondary">← Back to Global Manager</a>
        <button class="btn btn-primary" id="reimport-btn">↩ Import Again</button>
      </div>
    </div>
  </div>
</main>

<style>
.import-card { background:var(--card-bg,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; overflow:hidden; margin-bottom:24px; }
.import-card.hidden { display:none; }
.import-card-header { display:flex; align-items:center; gap:10px; padding:14px 20px; background:var(--section-header-bg,#f9fafb); border-bottom:1px solid var(--border,#e5e7eb); font-weight:600; font-size:.95rem; }
.step-num { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; background:var(--primary,#6366f1); color:#fff; border-radius:50%; font-size:.78rem; font-weight:700; flex-shrink:0; }
.import-card-body { padding:20px; }
.hint-text { font-size:.88rem; color:var(--text-muted,#6b7280); margin-bottom:14px; }
.hint-text code { background:var(--section-header-bg,#f3f4f6); padding:1px 6px; border-radius:4px; font-size:.83rem; }
#paste-area { width:100%; height:360px; font-family:'Fira Code','Cascadia Code',monospace; font-size:.82rem; padding:12px; border:1px solid var(--border,#d1d5db); border-radius:7px; resize:vertical; background:#1e1e2e; color:#cdd6f4; box-sizing:border-box; line-height:1.6; }
#paste-area:focus { outline:none; border-color:var(--primary,#6366f1); }
.import-actions { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.parse-err { color:#dc2626; font-size:.85rem; font-weight:500; }
.btn-success { background:#16a34a; color:#fff; border:none; padding:9px 20px; border-radius:7px; font-size:.9rem; font-weight:600; cursor:pointer; }
.btn-success:hover { background:#15803d; }
.btn-success:disabled { background:#86efac; cursor:not-allowed; }
.table-wrap { overflow-x:auto; }
.preview-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.preview-table th { background:var(--section-header-bg,#f9fafb); padding:10px 14px; text-align:left; font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted,#6b7280); border-bottom:2px solid var(--border,#e5e7eb); }
.preview-table td { padding:10px 14px; border-bottom:1px solid var(--border,#f3f4f6); vertical-align:top; }
.preview-table tr:last-child td { border-bottom:none; }
.result-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:7px; margin-bottom:8px; font-size:.9rem; }
.result-item.ok   { background:#f0fdf4; border:1px solid #bbf7d0; }
.result-item.fail { background:#fef2f2; border:1px solid #fecaca; }
.result-icon { font-size:1.1rem; }
.result-name { font-weight:700; min-width:160px; }
.result-msg  { color:var(--text-muted,#6b7280); font-size:.83rem; }
</style>

<script>
(function () {
  const API = 'globalwise_api.php';
  let parsed = [];

  const stepPaste   = document.getElementById('step-paste');
  const stepPreview = document.getElementById('step-preview');
  const stepResult  = document.getElementById('step-result');

  document.getElementById('parse-btn').addEventListener('click', doParse);
  document.getElementById('back-btn').addEventListener('click', () => {
    stepPreview.classList.add('hidden');
    stepPaste.classList.remove('hidden');
  });
  document.getElementById('import-btn').addEventListener('click', doImport);
  document.getElementById('reimport-btn').addEventListener('click', () => {
    stepResult.classList.add('hidden');
    stepPaste.classList.remove('hidden');
    document.getElementById('paste-area').value = '';
    parsed = [];
  });

  // ── Parse ─────────────────────────────────────────────────────────────────
  function doParse() {
    const errEl = document.getElementById('parse-err');
    errEl.textContent = '';
    let raw = document.getElementById('paste-area').value.trim();
    if (!raw) { errEl.textContent = 'Nothing to paste.'; return; }

    // Strip export / const declaration
    raw = raw
      .replace(/^export\s+default\s+/, '')
      .replace(/^export\s+const\s+\w+\s*=\s*/, '')
      .replace(/^const\s+\w+\s*=\s*/, '')
      .replace(/;$/, '')
      .trim();

    try {
      parsed = (new Function('return (' + raw + ')'))();
    } catch (e) {
      errEl.textContent = 'Parse error: ' + e.message;
      return;
    }

    if (!Array.isArray(parsed)) {
      errEl.textContent = 'Expected an array [ ... ].';
      return;
    }

    buildPreview();
    stepPaste.classList.add('hidden');
    stepPreview.classList.remove('hidden');
  }

  // ── Preview ───────────────────────────────────────────────────────────────
  function buildPreview() {
    const tbody = document.getElementById('preview-body');
    tbody.innerHTML = '';
    parsed.forEach((item, i) => {
      const tr = document.createElement('tr');
      const logos = (item.logos || []).length;
      tr.innerHTML = `
        <td>${i + 1}</td>
        <td><strong>${esc(item.name || '—')}</strong><br><small style="color:#94a3b8">${esc(item.namePopup || '')}</small></td>
        <td>${esc(item.title || '—')}</td>
        <td>${logos} logo${logos !== 1 ? 's' : ''}</td>
        <td style="font-size:.75rem;color:#94a3b8;">${esc((item.profileImage || '').split('/').pop())}</td>
      `;
      tbody.appendChild(tr);
    });
  }

  // ── Import ────────────────────────────────────────────────────────────────
  async function doImport() {
    const btn   = document.getElementById('import-btn');
    const errEl = document.getElementById('import-err');
    errEl.textContent = '';
    btn.disabled      = true;
    btn.textContent   = '⏳ Importing…';

    const results = [];

    for (let i = 0; i < parsed.length; i++) {
      const item = parsed[i];

      // Build logos JSON — store paths as-is from the static file
      const logos = (item.logos || []).map((l, idx) => ({ sort_order: idx + 1, logo: l }));

      const body = new URLSearchParams({
        faculty_id:          0,
        sort_order:          i + 1,
        name:                item.name               || '',
        name_popup:          item.namePopup           || '',
        title:               item.title              || '',
        expertise:           item.expertise          || '',
        description:         item.description        || '',
        profile_image:       item.profileImage        || '',
        profile_image_popup: item.profileImagePopup   || '',
        logos:               JSON.stringify(logos),
      });

      try {
        const r    = await fetch(`${API}?action=save_guest_faculty`, { method: 'POST', body });
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); }
        catch (_) {
          results.push({ name: item.name, status: 'fail', msg: 'Bad response: ' + text.substring(0, 100) });
          continue;
        }
        results.push({ name: item.name, status: d.success ? 'ok' : 'fail', msg: d.message || '' });
      } catch (e) {
        results.push({ name: item.name, status: 'fail', msg: 'Network error: ' + e.message });
      }
    }

    btn.disabled    = false;
    btn.textContent = '⬆️ Import All';
    buildResult(results);
    stepPreview.classList.add('hidden');
    stepResult.classList.remove('hidden');
  }

  // ── Result ────────────────────────────────────────────────────────────────
  function buildResult(results) {
    const list = document.getElementById('result-list');
    const ok   = results.filter(r => r.status === 'ok').length;
    const fail = results.filter(r => r.status === 'fail').length;

    const summary = `<div style="margin-bottom:16px;font-size:.9rem;color:var(--text-muted,#6b7280)">
      <strong style="color:var(--text,#111)">${results.length} members processed</strong> —
      <span style="color:#16a34a">✓ ${ok} saved</span>
      ${fail ? ` &nbsp;·&nbsp; <span style="colorgu:#dc2626">✗ ${fail} failed</span>` : ''}
    </div>`;

    const items = results.map(r => `
      <div class="result-item ${r.status}">
        <span class="result-icon">${r.status === 'ok' ? '✅' : '❌'}</span>
        <span class="result-name">${esc(r.name)}</span>
        <span class="result-msg">${esc(r.msg)}</span>
      </div>`).join('');

    list.innerHTML = summary + items;
  }

  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>