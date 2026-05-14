<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();
$courses = $db->query(
    "SELECT id, label FROM courses WHERE is_active = 1 ORDER BY id, label"
)->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content" data-page="schemas">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Schema Manager</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Schema <span>Manager</span></h1>
        <p class="page-subtitle">Manage JSON-LD structured data by course and location</p>
      </div>
    </div>
  </div>

  <div class="selector-card">
    <div class="field-group">
      <label for="course-select">📚 Course</label>
      <select id="course-select">
        <option value="">— Select Course —</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field-group">
      <label for="location-select">📍 Location</label>
      <select id="location-select" disabled>
        <option value="">— Select Location —</option>
      </select>
    </div>
    <button class="btn btn-primary" id="view-btn" disabled>
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
      </svg>
      Load Schema
    </button>
  </div>

  <div id="result-area">
    <div class="state-placeholder">
      <span class="big-icon">🧩</span>
      <h3>No schema loaded yet</h3>
      <p>Choose a course and location above to load the schema editor.</p>
    </div>
  </div>
</main>

<style>
.schema-editor { display: flex; flex-direction: column; gap: 0; }

.schema-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 12px 16px;
  background: var(--section-header-bg, #f9fafb);
  border: 1px solid var(--border, #e5e7eb);
  border-bottom: none;
  border-radius: 10px 10px 0 0;
}

.schema-toolbar-left  { display: flex; align-items: center; gap: 10px; }
.schema-toolbar-right { display: flex; align-items: center; gap: 10px; }

.schema-meta {
  font-size: 0.8rem;
  color: var(--text-muted, #9ca3af);
}

.schema-textarea {
  width: 100%;
  min-height: 560px;
  font-family: 'Fira Code', 'Cascadia Code', 'Courier New', monospace;
  font-size: 0.82rem;
  line-height: 1.65;
  padding: 16px;
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 0;
  background: #1e1e2e;
  color: #cdd6f4;
  resize: vertical;
  box-sizing: border-box;
  tab-size: 2;
  outline: none;
  transition: border-color 0.15s;
}
.schema-textarea:focus { border-color: var(--primary, #6366f1); }

.schema-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 12px 16px;
  background: var(--section-header-bg, #f9fafb);
  border: 1px solid var(--border, #e5e7eb);
  border-top: none;
  border-radius: 0 0 10px 10px;
}

.schema-footer-left  { display: flex; align-items: center; gap: 10px; }
.schema-footer-right { display: flex; align-items: center; gap: 10px; }

.json-status {
  font-size: 0.82rem;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 99px;
}
.json-status.ok   { background: #dcfce7; color: #15803d; }
.json-status.err  { background: #fef2f2; color: #dc2626; }
.json-status.idle { background: #f3f4f6; color: #6b7280; }

.save-msg {
  font-size: 0.88rem;
  font-weight: 500;
  opacity: 0;
  transition: opacity 0.3s;
}
.save-msg.show { opacity: 1; }
.save-msg.ok   { color: #16a34a; }
.save-msg.err  { color: #dc2626; }

.btn-sm {
  padding: 6px 14px;
  font-size: 0.82rem;
  border-radius: 6px;
}
</style>

<script>
(function () {
  const API = 'schemas_api.php';

  const courseSelect   = document.getElementById('course-select');
  const locationSelect = document.getElementById('location-select');
  const viewBtn        = document.getElementById('view-btn');
  const resultArea     = document.getElementById('result-area');

  let currentCourseId   = 0;
  let currentLocationId = 0;
  let validateTimer     = null;

  // ── Course change → load locations ────────────────────────────────────────
  courseSelect.addEventListener('change', async function () {
    const courseId = parseInt(this.value) || 0;
    locationSelect.innerHTML = '<option value="">— Select Location —</option>';
    locationSelect.disabled  = true;
    viewBtn.disabled         = true;
    resultArea.innerHTML     = placeholder('🧩', 'No schema loaded yet', 'Choose a course and location above.');

    if (!courseId) return;

    try {
      const r = await fetch(`${API}?action=get_locations`);
      const d = await r.json();
      if (!d.success) throw new Error(d.message);
      d.locations.forEach(loc => {
        const o = document.createElement('option');
        o.value = loc.id; o.textContent = loc.label;
        locationSelect.appendChild(o);
      });
      locationSelect.disabled = false;
    } catch (e) {
      resultArea.innerHTML = placeholder('❌', 'Error', e.message);
    }
  });

  locationSelect.addEventListener('change', function () {
    viewBtn.disabled = !this.value;
  });

  viewBtn.addEventListener('click', function () {
    const courseId   = parseInt(courseSelect.value)   || 0;
    const locationId = parseInt(locationSelect.value) || 0;
    if (!courseId || !locationId) return;
    loadEditor(courseId, locationId);
  });

  // ── Load editor ───────────────────────────────────────────────────────────
  async function loadEditor(courseId, locationId) {
    currentCourseId   = courseId;
    currentLocationId = locationId;
    resultArea.innerHTML = placeholder('⏳', 'Loading…', '');

    try {
      const r = await fetch(`${API}?action=get_schema_html&course_id=${courseId}&location_id=${locationId}`);
      const d = await r.json();
      if (!d.success) throw new Error(d.message);

      const updatedInfo = d.updated_at
        ? `Last saved: ${d.updated_at} by ${d.updated_by ?? '—'}`
        : 'No data saved yet — paste your schema array below.';

      resultArea.innerHTML = `
        <div class="schema-editor">
          <div class="schema-toolbar">
            <div class="schema-toolbar-left">
              <strong style="font-size:0.9rem">📋 JSON-LD Schema Array</strong>
              <span class="json-status idle" id="json-status">—</span>
            </div>
            <div class="schema-toolbar-right">
              <span class="schema-meta" id="schema-meta">${esc(updatedInfo)}</span>
              <button class="btn btn-secondary btn-sm" id="format-btn">⚡ Format</button>
            </div>
          </div>

          <textarea
            class="schema-textarea"
            id="schema-textarea"
            spellcheck="false"
            placeholder='[\n  {\n    "@context": "https://schema.org",\n    "@type": "Course",\n    ...\n  }\n]'
          >${esc(d.schema_json)}</textarea>

          <div class="schema-footer">
            <div class="schema-footer-left">
              <button class="btn btn-secondary btn-sm" id="count-btn">🔢 Count schemas</button>
            </div>
            <div class="schema-footer-right">
              <span class="save-msg" id="save-msg"></span>
              <button class="btn btn-primary" id="save-btn">💾 Save Schema</button>
            </div>
          </div>
        </div>`;

      // Init interactions
      const textarea = document.getElementById('schema-textarea');
      textarea.addEventListener('input', () => {
        clearTimeout(validateTimer);
        validateTimer = setTimeout(() => validateJson(textarea.value), 600);
      });
      validateJson(textarea.value);

      document.getElementById('format-btn').addEventListener('click', () => formatJson());
      document.getElementById('count-btn').addEventListener('click', () => countSchemas());
      document.getElementById('save-btn').addEventListener('click', () => saveSchema());

    } catch (e) {
      resultArea.innerHTML = placeholder('❌', 'Error', e.message);
    }
  }

  // ── JSON validation ───────────────────────────────────────────────────────
  function validateJson(val) {
    const statusEl = document.getElementById('json-status');
    if (!val.trim()) {
      statusEl.textContent = '—';
      statusEl.className   = 'json-status idle';
      return false;
    }
    try {
      const parsed = JSON.parse(val);
      if (!Array.isArray(parsed)) {
        statusEl.textContent = '⚠ Must be an array [ ]';
        statusEl.className   = 'json-status err';
        return false;
      }
      statusEl.textContent = `✓ Valid JSON — ${parsed.length} schema${parsed.length !== 1 ? 's' : ''}`;
      statusEl.className   = 'json-status ok';
      return true;
    } catch (e) {
      statusEl.textContent = '✗ ' + e.message.split('\n')[0].substring(0, 60);
      statusEl.className   = 'json-status err';
      return false;
    }
  }

  // ── Format ────────────────────────────────────────────────────────────────
  function formatJson() {
    const textarea = document.getElementById('schema-textarea');
    try {
      const parsed = JSON.parse(textarea.value);
      textarea.value = JSON.stringify(parsed, null, 2);
      validateJson(textarea.value);
    } catch (e) {
      alert('Cannot format — fix JSON errors first:\n' + e.message);
    }
  }

  // ── Count schemas ─────────────────────────────────────────────────────────
  function countSchemas() {
    const textarea = document.getElementById('schema-textarea');
    try {
      const parsed = JSON.parse(textarea.value);
      if (!Array.isArray(parsed)) { alert('Not an array.'); return; }
      const types = parsed.map(s => s['@type'] || s['@graph'] ? '@graph block' : '?').join(', ');
      alert(`${parsed.length} schema object(s):\n${types}`);
    } catch (e) {
      alert('Invalid JSON.');
    }
  }

  // ── Save ──────────────────────────────────────────────────────────────────
  async function saveSchema() {
    const textarea = document.getElementById('schema-textarea');
    const btn      = document.getElementById('save-btn');
    const msg      = document.getElementById('save-msg');

    if (!validateJson(textarea.value)) {
      msg.textContent = 'Fix JSON errors before saving.';
      msg.className   = 'save-msg show err';
      setTimeout(() => { msg.className = 'save-msg'; }, 3000);
      return;
    }

    btn.disabled    = true;
    btn.textContent = '⏳ Saving…';
    msg.className   = 'save-msg';

    const body = new URLSearchParams({
      course_id   : currentCourseId,
      location_id : currentLocationId,
      schema_json : textarea.value,
    });

    try {
      const r = await fetch(`${API}?action=save_schema`, { method: 'POST', body });
      const d = await r.json();
      msg.textContent = d.message ?? (d.success ? 'Saved!' : 'Error');
      msg.className   = 'save-msg show ' + (d.success ? 'ok' : 'err');

      if (d.success) {
        const now = new Date().toLocaleString();
        document.getElementById('schema-meta').textContent = `Last saved: ${now}`;
      }

      setTimeout(() => { msg.className = 'save-msg'; }, 4000);
    } catch (e) {
      msg.textContent = 'Network error: ' + e.message;
      msg.className   = 'save-msg show err';
    } finally {
      btn.disabled    = false;
      btn.textContent = '💾 Save Schema';
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  function esc(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function placeholder(icon, title, body) {
    return `<div class="state-placeholder">
      <span class="big-icon">${icon}</span>
      <h3>${title}</h3><p>${body}</p>
    </div>`;
  }
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
