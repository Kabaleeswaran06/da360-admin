<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

// ── HARDCODE COURSE ID HERE ────────────────────────────────────────────────
const IMPORT_COURSE_ID = 10;   // ← change to your target course id
// ──────────────────────────────────────────────────────────────────────────

$db = getDB();

$stmt = $db->prepare("SELECT label FROM courses WHERE id = ? LIMIT 1");
$stmt->execute([IMPORT_COURSE_ID]);
$courseLabel = $stmt->fetchColumn() ?: '(Course #' . IMPORT_COURSE_ID . ')';

$stmt = $db->query("SELECT id, slug, label FROM locations WHERE is_active = 1 ORDER BY sort_order, label");
$locationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$slugMap = [];
foreach ($locationRows as $loc) {
    $slug = !empty($loc['slug'])
        ? $loc['slug']
        : strtolower(preg_replace('/\s+/', '_', trim($loc['label'])));
    $slugMap[$slug] = ['id' => (int)$loc['id'], 'label' => $loc['label']];
}

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content" data-page="schemas-import">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <a href="/da360-admin/schemas.php">Schema Manager</a>
      <span class="breadcrumb-sep">/</span>
      <span>Import</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Import <span>Schemas</span></h1>
        <p class="page-subtitle">
          Paste your TypeScript schema object — importing into
          <strong><?= htmlspecialchars($courseLabel) ?></strong>
          <span class="badge-course">course_id = <?= IMPORT_COURSE_ID ?></span>
        </p>
      </div>
    </div>
  </div>

  <!-- Step 1: Paste -->
  <div class="import-card" id="step-paste">
    <div class="import-card-header"><span class="step-num">1</span> Paste your TypeScript / JS object</div>
    <div class="import-card-body">
      <p class="hint-text">
        Paste the entire exported object. Keys must match your location slugs:
        <code><?= implode(', ', array_keys($slugMap)) ?></code><br>
        Each key's value must be a <strong>JSON array</strong> <code>[ {...}, {...} ]</code>
      </p>
      <textarea id="paste-area" spellcheck="false" placeholder="{&#10;  global: [ { &quot;@context&quot;: &quot;https://schema.org&quot;, ... }, ... ],&#10;  bangalore: [ ... ],&#10;  ...&#10;}"></textarea>
      <div class="import-actions">
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
              <th>Slug</th>
              <th>Location</th>
              <th>Status</th>
              <th>Schema count</th>
              <th>Types found</th>
            </tr>
          </thead>
          <tbody id="preview-body"></tbody>
        </table>
      </div>
      <div class="import-actions" style="margin-top:20px">
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
      <div class="import-actions" style="margin-top:20px">
        <a href="/da360-admin/schemas.php" class="btn btn-secondary">← Back to Schema Manager</a>
        <button class="btn btn-primary" id="reimport-btn">↩ Import Again</button>
      </div>
    </div>
  </div>
</main>

<style>
.badge-course { display:inline-block; background:var(--primary-light,#ede9fe); color:var(--primary,#6366f1); font-size:.75rem; font-weight:600; padding:2px 10px; border-radius:99px; margin-left:8px; vertical-align:middle; }
.import-card { background:var(--card-bg,#fff); border:1px solid var(--border,#e5e7eb); border-radius:10px; overflow:hidden; margin-bottom:24px; }
.import-card.hidden { display:none; }
.import-card-header { display:flex; align-items:center; gap:10px; padding:14px 20px; background:var(--section-header-bg,#f9fafb); border-bottom:1px solid var(--border,#e5e7eb); font-weight:600; font-size:.95rem; }
.step-num { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; background:var(--primary,#6366f1); color:#fff; border-radius:50%; font-size:.78rem; font-weight:700; flex-shrink:0; }
.import-card-body { padding:20px; }
.hint-text { font-size:.88rem; color:var(--text-muted,#6b7280); margin-bottom:14px; }
.hint-text code { background:var(--section-header-bg,#f3f4f6); padding:1px 6px; border-radius:4px; font-size:.83rem; }
#paste-area { width:100%; height:380px; font-family:'Fira Code','Cascadia Code',monospace; font-size:.82rem; padding:12px; border:1px solid var(--border,#d1d5db); border-radius:7px; resize:vertical; background:#1e1e2e; color:#cdd6f4; box-sizing:border-box; line-height:1.6; }
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
.status-ok   { display:inline-block; background:#dcfce7; color:#15803d; padding:2px 10px; border-radius:99px; font-size:.75rem; font-weight:600; }
.status-warn { display:inline-block; background:#fef9c3; color:#a16207; padding:2px 10px; border-radius:99px; font-size:.75rem; font-weight:600; }
.result-item { display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:7px; margin-bottom:8px; font-size:.9rem; }
.result-item.ok   { background:#f0fdf4; border:1px solid #bbf7d0; }
.result-item.fail { background:#fef2f2; border:1px solid #fecaca; }
.result-item.skip { background:#f9fafb; border:1px solid #e5e7eb; }
.result-icon { font-size:1.1rem; }
.result-slug { font-weight:700; min-width:110px; }
.result-msg  { color:var(--text-muted,#6b7280); font-size:.83rem; }
</style>

<script>
(function () {
  const API       = 'schemas_api.php';
  const COURSE_ID = <?= IMPORT_COURSE_ID ?>;
  const SLUG_MAP  = <?= json_encode($slugMap, JSON_UNESCAPED_UNICODE) ?>;

  let parsed = {};

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
    parsed = {};
  });

  // ── Parse ─────────────────────────────────────────────────────────────────
  function doParse() {
    const errEl = document.getElementById('parse-err');
    errEl.textContent = '';
    let raw = document.getElementById('paste-area').value.trim();
    if (!raw) { errEl.textContent = 'Nothing to parse.'; return; }

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

    if (typeof parsed !== 'object' || Array.isArray(parsed)) {
      errEl.textContent = 'Parsed value is not an object.';
      return;
    }

    buildPreview(parsed);
    stepPaste.classList.add('hidden');
    stepPreview.classList.remove('hidden');
  }

  // ── Preview ───────────────────────────────────────────────────────────────
  function buildPreview(data) {
    const tbody = document.getElementById('preview-body');
    tbody.innerHTML = '';

    Object.keys(data).forEach(slug => {
      const arr     = data[slug];
      const locInfo = SLUG_MAP[slug];
      const tr      = document.createElement('tr');

      let statusHtml, locationCell, countCell, typesCell;

      if (!locInfo) {
        statusHtml   = '<span class="status-warn">⚠ No DB match</span>';
        locationCell = '<span style="color:#9ca3af">—</span>';
      } else {
        statusHtml   = '<span class="status-ok">✓ Ready</span>';
        locationCell = esc(locInfo.label);
      }

      if (!Array.isArray(arr)) {
        countCell = '<span style="color:#dc2626">not an array</span>';
        typesCell = '—';
      } else {
        countCell = arr.length + ' schema' + (arr.length !== 1 ? 's' : '');
        const types = [...new Set(arr.map(s => s['@type'] || (s['@graph'] ? '@graph' : '?')))];
        typesCell = esc(types.join(', '));
      }

      tr.innerHTML = `
        <td><code>${esc(slug)}</code></td>
        <td>${locationCell}</td>
        <td>${statusHtml}</td>
        <td>${countCell}</td>
        <td style="font-size:.78rem;color:var(--text-muted,#6b7280)">${typesCell}</td>
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

    for (const slug of Object.keys(parsed)) {
      const locInfo = SLUG_MAP[slug];
      if (!locInfo) {
        results.push({ slug, status: 'skip', msg: 'Slug not found in locations table — skipped.' });
        continue;
      }

      const arr = parsed[slug];
      if (!Array.isArray(arr)) {
        results.push({ slug, status: 'fail', msg: 'Value is not an array — skipped.' });
        continue;
      }

      const body = new URLSearchParams({
        course_id   : COURSE_ID,
        location_id : locInfo.id,
        schema_json : JSON.stringify(arr),
      });

      try {
        const r    = await fetch(`${API}?action=save_schema`, { method: 'POST', body });
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); }
        catch (_) {
          results.push({ slug, status: 'fail', msg: 'Bad API response: ' + text.substring(0, 120) });
          continue;
        }
        results.push({ slug, status: d.success ? 'ok' : 'fail', msg: d.message ?? '' });
      } catch (e) {
        results.push({ slug, status: 'fail', msg: 'Network error: ' + e.message });
      }
    }

    btn.disabled    = false;
    btn.textContent = '⬆️ Import All';
    buildResultView(results);
    stepPreview.classList.add('hidden');
    stepResult.classList.remove('hidden');
  }

  // ── Result ────────────────────────────────────────────────────────────────
  function buildResultView(results) {
    const list = document.getElementById('result-list');
    const ok   = results.filter(r => r.status === 'ok').length;
    const fail = results.filter(r => r.status === 'fail').length;
    const skip = results.filter(r => r.status === 'skip').length;

    const summary = `
      <div style="margin-bottom:16px;font-size:.9rem;color:var(--text-muted,#6b7280)">
        <strong style="color:var(--text,#111)">${results.length} slugs processed</strong> —
        <span style="color:#16a34a">✓ ${ok} saved</span>
        ${fail ? ` &nbsp;·&nbsp; <span style="color:#dc2626">✗ ${fail} failed</span>` : ''}
        ${skip ? ` &nbsp;·&nbsp; <span style="color:#9ca3af">⊘ ${skip} skipped</span>` : ''}
      </div>`;

    const items = results.map(r => `
      <div class="result-item ${r.status}">
        <span class="result-icon">${r.status === 'ok' ? '✅' : r.status === 'skip' ? '⊘' : '❌'}</span>
        <span class="result-slug">${esc(r.slug)}</span>
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
