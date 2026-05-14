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

<main class="main-content" data-page="metatags">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Meta Tags Manager</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Meta Tags <span>Manager</span></h1>
        <p class="page-subtitle">Manage SEO meta tags, Open Graph and Twitter Card by course and location</p>
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
      Load Meta Tags
    </button>
  </div>

  <div id="result-area">
    <div class="state-placeholder">
      <span class="big-icon">🏷️</span>
      <h3>No meta tags loaded yet</h3>
      <p>Choose a course and location above to load the meta tag editor.</p>
    </div>
  </div>
</main>

<style>
/* ── Metatags Editor Styles ─────────────────────────────────────────────── */
.meta-editor { display: flex; flex-direction: column; gap: 24px; }

.meta-section {
  background: var(--card-bg, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 10px;
  overflow: hidden;
}

.meta-section-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 20px;
  background: var(--section-header-bg, #f9fafb);
  border-bottom: 1px solid var(--border, #e5e7eb);
  font-weight: 600;
  font-size: 0.95rem;
}

.meta-section-body { padding: 20px; display: flex; flex-direction: column; gap: 16px; }

.meta-field { display: flex; flex-direction: column; gap: 6px; }

.meta-field label {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text-muted, #6b7280);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.meta-field input,
.meta-field textarea,
.meta-field select {
  width: 100%;
  padding: 9px 12px;
  border: 1px solid var(--border, #d1d5db);
  border-radius: 7px;
  font-size: 0.9rem;
  font-family: inherit;
  background: var(--input-bg, #fff);
  color: var(--text, #111827);
  transition: border-color 0.15s;
  box-sizing: border-box;
}

.meta-field input:focus,
.meta-field textarea:focus,
.meta-field select:focus {
  outline: none;
  border-color: var(--primary, #6366f1);
  box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}

.meta-field textarea { resize: vertical; min-height: 72px; }

.meta-field .char-hint {
  font-size: 0.75rem;
  color: var(--text-muted, #9ca3af);
  text-align: right;
}

.meta-field .char-hint.warn { color: #f59e0b; }
.meta-field .char-hint.over { color: #ef4444; }

.meta-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 640px) { .meta-row { grid-template-columns: 1fr; } }

.keywords-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 8px;
  border: 1px solid var(--border, #d1d5db);
  border-radius: 7px;
  min-height: 44px;
  cursor: text;
  background: var(--input-bg, #fff);
}

.keyword-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 10px 2px 10px;
  background: var(--primary-light, #ede9fe);
  color: var(--primary, #6366f1);
  border-radius: 99px;
  font-size: 0.8rem;
  font-weight: 500;
}

.keyword-tag .rm {
  cursor: pointer;
  margin-left: 2px;
  opacity: 0.6;
  font-size: 1rem;
  line-height: 1;
}
.keyword-tag .rm:hover { opacity: 1; }

#kw-input {
  border: none;
  outline: none;
  flex: 1;
  min-width: 160px;
  font-size: 0.88rem;
  background: transparent;
  color: var(--text, #111827);
  padding: 2px 4px;
}

.meta-save-row {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  padding: 20px;
  border-top: 1px solid var(--border, #e5e7eb);
  background: var(--section-header-bg, #f9fafb);
}

.save-msg {
  font-size: 0.88rem;
  font-weight: 500;
  opacity: 0;
  transition: opacity 0.3s;
}
.save-msg.show { opacity: 1; }
.save-msg.ok  { color: #16a34a; }
.save-msg.err { color: #dc2626; }
</style>

<script>
(function () {
  const API = 'metatags_api.php';

  const courseSelect   = document.getElementById('course-select');
  const locationSelect = document.getElementById('location-select');
  const viewBtn        = document.getElementById('view-btn');
  const resultArea     = document.getElementById('result-area');

  let currentCourseId   = 0;
  let currentLocationId = 0;

  // ── Course change → load locations ────────────────────────────────────────
  courseSelect.addEventListener('change', async function () {
    const courseId = parseInt(this.value) || 0;
    locationSelect.innerHTML = '<option value="">— Select Location —</option>';
    locationSelect.disabled  = true;
    viewBtn.disabled         = true;
    resultArea.innerHTML     = placeholder('🏷️', 'No meta tags loaded yet', 'Choose a course and location above to load the meta tag editor.');

    if (!courseId) return;

    try {
      const r = await fetch(`${API}?action=get_locations&course_id=${courseId}`);
      const d = await r.json();
      if (!d.success) throw new Error(d.message);
      d.locations.forEach(loc => {
        const o = document.createElement('option');
        o.value       = loc.id;
        o.textContent = loc.label;
        locationSelect.appendChild(o);
      });
      locationSelect.disabled = false;
    } catch (e) {
      showError('Could not load locations: ' + e.message);
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

    resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon spin">⏳</span><h3>Loading…</h3></div>';

    try {
      const r = await fetch(`${API}?action=get_metatags_html&course_id=${courseId}&location_id=${locationId}`);
      const d = await r.json();
      if (!d.success) throw new Error(d.message);
      resultArea.innerHTML = buildEditorHTML(d.data);
      initEditor(d.data);
    } catch (e) {
      showError('Could not load meta tags: ' + e.message);
    }
  }

  // ── Build editor HTML ─────────────────────────────────────────────────────
  function buildEditorHTML(data) {
    return `
    <div class="meta-editor" id="meta-editor">

      <!-- Basic SEO -->
      <div class="meta-section">
        <div class="meta-section-header">🔍 Basic SEO</div>
        <div class="meta-section-body">
          <div class="meta-field">
            <label>Title <span style="font-weight:400;color:#9ca3af">(50–60 chars ideal)</span></label>
            <input type="text" id="f-title" maxlength="120" value="${esc(data.title ?? '')}" placeholder="Page title for search engines">
            <div class="char-hint" id="hint-title"></div>
          </div>
          <div class="meta-field">
            <label>Description <span style="font-weight:400;color:#9ca3af">(150–160 chars ideal)</span></label>
            <textarea id="f-description" maxlength="320" placeholder="Meta description shown in search results">${esc(data.description ?? '')}</textarea>
            <div class="char-hint" id="hint-desc"></div>
          </div>
          <div class="meta-field">
            <label>Keywords <span style="font-weight:400;color:#9ca3af">(press Enter or comma to add)</span></label>
            <div class="keywords-wrap" id="kw-wrap"></div>
          </div>
          <div class="meta-row">
            <div class="meta-field">
              <label>Robots</label>
              <select id="f-robots">
                <option value="index, follow, max-snippet:-1, max-video-preview:-1, max-image-preview:large">index, follow (default)</option>
                <option value="noindex, nofollow">noindex, nofollow</option>
                <option value="noindex, follow">noindex, follow</option>
                <option value="index, nofollow">index, nofollow</option>
              </select>
            </div>
            <div class="meta-field">
              <label>Canonical URL</label>
              <input type="url" id="f-canonical" value="${esc(data.canonical ?? '')}" placeholder="https://www.yoursite.com/page">
            </div>
          </div>
        </div>
      </div>

      <!-- Open Graph -->
      <div class="meta-section">
        <div class="meta-section-header">📘 Open Graph (Facebook / LinkedIn)</div>
        <div class="meta-section-body">
          <div class="meta-field">
            <label>OG Title</label>
            <input type="text" id="f-og-title" maxlength="120" value="${esc(data.og_title ?? '')}" placeholder="Leave blank to inherit from Title">
          </div>
          <div class="meta-field">
            <label>OG Description</label>
            <textarea id="f-og-description" maxlength="320" placeholder="Leave blank to inherit from Description">${esc(data.og_description ?? '')}</textarea>
          </div>
          <div class="meta-row">
            <div class="meta-field">
              <label>OG URL</label>
              <input type="url" id="f-og-url" value="${esc(data.og_url ?? '')}" placeholder="https://yoursite.com/page">
            </div>
            <div class="meta-field">
              <label>OG Site Name</label>
              <input type="text" id="f-og-sitename" value="${esc(data.og_site_name ?? 'Digital Academy 360')}" placeholder="Digital Academy 360">
            </div>
          </div>
          <div class="meta-row">
            <div class="meta-field">
              <label>OG Type</label>
              <select id="f-og-type">
                <option value="website">website</option>
                <option value="article">article</option>
                <option value="profile">profile</option>
              </select>
            </div>
            <div class="meta-field">
              <label>OG Locale</label>
              <input type="text" id="f-og-locale" value="${esc(data.og_locale ?? 'en_US')}" placeholder="en_US">
            </div>
          </div>
          <div class="meta-field">
            <label>OG Image URL</label>
            <input type="text" id="f-og-image" value="${esc(data.og_image ?? '/images/digital-academy-360-og.jpg')}" placeholder="/images/digital-academy-360-og.jpg">
          </div>
        </div>
      </div>

      <!-- Twitter Card -->
      <div class="meta-section">
        <div class="meta-section-header">🐦 Twitter Card</div>
        <div class="meta-section-body">
          <div class="meta-row">
            <div class="meta-field">
              <label>Card Type</label>
              <select id="f-tw-card">
                <option value="summary_large_image">summary_large_image</option>
                <option value="summary">summary</option>
                <option value="app">app</option>
              </select>
            </div>
            <div class="meta-field">
              <label>Twitter Image URL</label>
              <input type="text" id="f-tw-image" value="${esc(data.twitter_image ?? '/images/digital-academy-360-og.jpg')}" placeholder="/images/digital-academy-360-og.jpg">
            </div>
          </div>
          <div class="meta-field">
            <label>Twitter Title <span style="font-weight:400;color:#9ca3af">(leave blank to inherit from Title)</span></label>
            <input type="text" id="f-tw-title" maxlength="120" value="${esc(data.twitter_title ?? '')}" placeholder="Leave blank to inherit from Title">
          </div>
          <div class="meta-field">
            <label>Twitter Description <span style="font-weight:400;color:#9ca3af">(leave blank to inherit from Description)</span></label>
            <textarea id="f-tw-description" maxlength="320" placeholder="Leave blank to inherit from Description">${esc(data.twitter_description ?? '')}</textarea>
          </div>
        </div>
      </div>

      <!-- Save bar -->
      <div class="meta-save-row">
        <span class="save-msg" id="save-msg"></span>
        <button class="btn btn-primary" id="save-btn">💾 Save Meta Tags</button>
      </div>
    </div>`;
  }

  // ── Init editor interactivity ─────────────────────────────────────────────
  function initEditor(data) {
    // Robots select
    const robotsSel = document.getElementById('f-robots');
    robotsSel.value = data.robots ?? robotsSel.options[0].value;

    // OG type select
    const ogTypeSel = document.getElementById('f-og-type');
    ogTypeSel.value = data.og_type ?? 'website';

    // Twitter card select
    const twCardSel = document.getElementById('f-tw-card');
    twCardSel.value = data.twitter_card ?? 'summary_large_image';

    // Char counters
    charCounter('f-title',       'hint-title', 60);
    charCounter('f-description', 'hint-desc',  160);

    // Keywords
    let keywords = [];
    try { keywords = JSON.parse(data.keywords ?? '[]'); } catch (_) {}
    if (!Array.isArray(keywords)) keywords = keywords ? [keywords] : [];
    initKeywords(keywords);

    // Save button
    document.getElementById('save-btn').addEventListener('click', saveMetaTags);
  }

  // ── Char counter helper ───────────────────────────────────────────────────
  function charCounter(fieldId, hintId, ideal) {
    const field = document.getElementById(fieldId);
    const hint  = document.getElementById(hintId);
    function update() {
      const len = field.value.length;
      hint.textContent = `${len} chars${ideal ? ' (ideal ≤ ' + ideal + ')' : ''}`;
      hint.className   = 'char-hint' + (len > ideal * 1.2 ? ' over' : len > ideal ? ' warn' : '');
    }
    field.addEventListener('input', update);
    update();
  }

  // ── Keyword tag system ────────────────────────────────────────────────────
  let keywords = [];

  function initKeywords(initial) {
    keywords = [];
    const wrap = document.getElementById('kw-wrap');
    // remove old input if any
    wrap.innerHTML = '';
    const input = document.createElement('input');
    input.id          = 'kw-input';
    input.placeholder = 'Type keyword, press Enter or comma…';
    wrap.appendChild(input);

    initial.forEach(k => { if (k.trim()) addKeyword(k.trim()); });

    input.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        const val = input.value.replace(/,/g, '').trim();
        if (val) { addKeyword(val); input.value = ''; }
      } else if (e.key === 'Backspace' && !input.value && keywords.length) {
        removeKeyword(keywords[keywords.length - 1]);
      }
    });
    wrap.addEventListener('click', () => input.focus());
  }

  function addKeyword(kw) {
    if (keywords.includes(kw)) return;
    keywords.push(kw);
    renderKeywords();
  }

  function removeKeyword(kw) {
    keywords = keywords.filter(k => k !== kw);
    renderKeywords();
  }

  function renderKeywords() {
    const wrap  = document.getElementById('kw-wrap');
    const input = document.getElementById('kw-input');
    // clear everything except the input
    [...wrap.children].forEach(el => { if (el.id !== 'kw-input') el.remove(); });
    keywords.forEach(kw => {
      const tag = document.createElement('span');
      tag.className   = 'keyword-tag';
      tag.innerHTML   = `${esc(kw)} <span class="rm" data-kw="${esc(kw)}">×</span>`;
      tag.querySelector('.rm').addEventListener('click', e => {
        e.stopPropagation();
        removeKeyword(kw);
      });
      wrap.insertBefore(tag, input);
    });
  }

  // ── Save ──────────────────────────────────────────────────────────────────
  async function saveMetaTags() {
    const btn = document.getElementById('save-btn');
    const msg = document.getElementById('save-msg');
    btn.disabled     = true;
    btn.textContent  = '⏳ Saving…';
    msg.className    = 'save-msg';
    msg.textContent  = '';

    const body = new URLSearchParams({
      // action           : 'save_metatags',
      course_id        : currentCourseId,
      location_id      : currentLocationId,
      title            : document.getElementById('f-title').value.trim(),
      description      : document.getElementById('f-description').value.trim(),
      keywords         : JSON.stringify(keywords),
      robots           : document.getElementById('f-robots').value,
      canonical        : document.getElementById('f-canonical').value.trim(),
      og_title         : document.getElementById('f-og-title').value.trim(),
      og_description   : document.getElementById('f-og-description').value.trim(),
      og_url           : document.getElementById('f-og-url').value.trim(),
      og_site_name     : document.getElementById('f-og-sitename').value.trim(),
      og_type          : document.getElementById('f-og-type').value,
      og_locale        : document.getElementById('f-og-locale').value.trim(),
      og_image         : document.getElementById('f-og-image').value.trim(),
      twitter_card     : document.getElementById('f-tw-card').value,
      twitter_title    : document.getElementById('f-tw-title').value.trim(),
      twitter_description: document.getElementById('f-tw-description').value.trim(),
      twitter_image    : document.getElementById('f-tw-image').value.trim(),
    });

    try {
      const r = await fetch(`${API}?action=save_metatags`, { method: 'POST', body });
      const d = await r.json();

      msg.textContent = d.message ?? (d.success ? 'Saved!' : 'Error');
      msg.className   = 'save-msg show ' + (d.success ? 'ok' : 'err');
      setTimeout(() => { msg.className = 'save-msg'; }, 4000);

      if (d.success && d.revalidated === false) {
        console.warn('[metatags] Next.js revalidation failed — cache may be stale');
      }
    } catch (e) {
      msg.textContent = 'Network error: ' + e.message;
      msg.className   = 'save-msg show err';
    } finally {
      btn.disabled    = false;
      btn.textContent = '💾 Save Meta Tags';
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

  function showError(msg) {
    resultArea.innerHTML = placeholder('❌', 'Error', msg);
  }
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
