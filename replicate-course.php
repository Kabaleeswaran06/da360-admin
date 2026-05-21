<?php
/**
 * replicate-course.php
 * Select From Course → To Course → Replicate all sections across all locations
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();

$courses = $db->query(
  "SELECT id, label FROM courses WHERE is_active=1 ORDER BY sort_order"
)->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content" data-page="replicate-course">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Replicate Course</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Replicate <span>Course</span></h1>
        <p style="color:#64748b;margin-top:4px;font-size:14px;">
          Copy all content from one course to another across all matching locations.
        </p>
      </div>
    </div>
  </div>

  <!-- ── Selector Card ─────────────────────────────────────────────────── -->
  <div class="selector-card" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
    <div class="field-group">
      <label for="from-course-select">📚 Replicate From Course</label>
      <select id="from-course-select">
        <option value="">— Select Source Course —</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field-group">
      <label for="to-course-select">📚 Replicate To Course</label>
      <select id="to-course-select">
        <option value="">— Select Target Course —</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- ── Location Preview ──────────────────────────────────────────────── -->
  <div id="location-preview" style="display:none;margin-top:20px;">
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;">
      <p style="font-size:13px;font-weight:600;color:#64748b;margin-bottom:10px;">📍 Locations that will be replicated:</p>
      <div id="location-list" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
      <p id="location-warning" style="display:none;margin-top:10px;font-size:13px;color:#f59e0b;"></p>
    </div>
  </div>

  <!-- ── Replicate Area ────────────────────────────────────────────────── -->
  <div id="replicate-area" style="display:none;margin-top:24px;">

    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:10px;padding:14px 20px;margin-bottom:20px;font-size:14px;">
      ⚠️ <strong>Warning:</strong> Replicating will <strong>overwrite</strong> existing data in the target course for all matching locations. This action cannot be undone.<br>
      ⚠️ <strong>Note :</strong> Schemas has to be replicate manually
    </div>

    <!-- Tabs -->
    <div class="tab-bar" style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
      <button class="tab-btn active" data-tab="content">📣 Content</button>
      <button class="tab-btn" data-tab="curriculum">📘 Curriculum</button>
      <button class="tab-btn" data-tab="specialisation">🎓 Specialisation</button>
      <button class="tab-btn" data-tab="faqs">❓ FAQs</button>
      <button class="tab-btn" data-tab="aitools">🤖 AI Tools</button>
      <button class="tab-btn" data-tab="metatags">🏷️ Meta Tags</button>
      <button class="tab-btn" data-tab="coursewise">📚 Course Wise</button>
      <button class="tab-btn" data-tab="all">🔁 All Sections</button>
    </div>

    <!-- Content Tab -->
    <div class="tab-panel" id="tab-content">
      <div class="replicate-card">
        <div class="replicate-info">
          <h3>📣 Content Fields</h3>
          <p>Replicates all text fields across all matching locations from the source course to the target course.</p>
        </div>
        <button class="btn btn-primary replicate-btn" id="btn-replicate-content">
          <span class="btn-icon">🔁</span> Replicate Content
        </button>
        <div class="replicate-status" id="status-content"></div>
      </div>
    </div>

    <!-- Curriculum Tab -->
    <div class="tab-panel" id="tab-curriculum" style="display:none;">
      <div class="replicate-card">
        <div class="replicate-info">
          <h3>📘 Curriculum</h3>
          <p>Replicates curriculum heading, description, batches and slots across all matching locations.</p>
        </div>
        <button class="btn btn-primary replicate-btn" id="btn-replicate-curriculum">
          <span class="btn-icon">🔁</span> Replicate Curriculum
        </button>
        <div class="replicate-status" id="status-curriculum"></div>
      </div>
    </div>

    <!-- Specialisation Tab -->
    <div class="tab-panel" id="tab-specialisation" style="display:none;">
      <div class="replicate-card">
        <div class="replicate-info">
          <h3>🎓 Specialisation</h3>
          <p>Replicates specialisation heading and description across all matching locations.</p>
        </div>
        <button class="btn btn-primary replicate-btn" id="btn-replicate-specialisation">
          <span class="btn-icon">🔁</span> Replicate Specialisation
        </button>
        <div class="replicate-status" id="status-specialisation"></div>
      </div>
    </div>

    <!-- FAQs Tab -->
    <div class="tab-panel" id="tab-faqs" style="display:none;">
      <div class="replicate-card">
        <div class="replicate-info">
          <h3>❓ FAQs</h3>
          <p>Replicates all FAQ entries across all matching locations. Existing FAQs in the target will be replaced.</p>
        </div>
        <button class="btn btn-primary replicate-btn" id="btn-replicate-faqs">
          <span class="btn-icon">🔁</span> Replicate FAQs
        </button>
        <div class="replicate-status" id="status-faqs"></div>
      </div>
    </div>

    <!-- new tab panel — add after FAQs tab panel -->
    <div class="tab-panel" id="tab-aitools" style="display:none;">
      <div class="replicate-card">
        <div class="replicate-info">
          <h3>🤖 AI Tools</h3>
          <p>Replicates all AI tool categories and tools from the source course to the target course. Existing AI tools in the target will be replaced.</p>
        </div>
        <button class="btn btn-primary replicate-btn" id="btn-replicate-aitools">
          <span class="btn-icon">🔁</span> Replicate AI Tools
        </button>
        <div class="replicate-status" id="status-aitools"></div>
      </div>
    </div>

    <!-- add after AI Tools tab panel -->
    <div class="tab-panel" id="tab-metatags" style="display:none;">
      <div class="replicate-card">
        <div class="replicate-info">
          <h3>🏷️ Meta Tags</h3>
          <p>Replicates all meta tags (title, description, keywords, OG, Twitter) across all matching locations from the source course to the target course.</p>
        </div>
        <button class="btn btn-primary replicate-btn" id="btn-replicate-metatags">
          <span class="btn-icon">🔁</span> Replicate Meta Tags
        </button>
        <div class="replicate-status" id="status-metatags"></div>
      </div>
    </div>

    <!-- add after Meta Tags tab panel -->
    <div class="tab-panel" id="tab-coursewise" style="display:none;">
      <div class="replicate-card">
        <div class="replicate-info">
          <h3>📚 Course Wise</h3>
          <p>Replicates all course-wide data in one go — Highlights, Tools, Case Studies, Live Projects, Key Highlights, Course Info, Cohorts, Banner and Headings.</p>
        </div>
        <button class="btn btn-primary replicate-btn" id="btn-replicate-coursewise">
          <span class="btn-icon">🔁</span> Replicate Course Wise
        </button>
        <div class="replicate-status" id="status-coursewise"></div>
      </div>
    </div>

    <!-- All Sections Tab -->
    <div class="tab-panel" id="tab-all" style="display:none;">
      <div class="replicate-card">
        <div class="replicate-info">
          <h3>🔁 All Sections</h3>
          <p>Replicates <strong>Content + Curriculum + Specialisation + FAQs</strong> across all matching locations in one go.</p>
        </div>
        <button class="btn btn-danger replicate-btn" id="btn-replicate-all">
          <span class="btn-icon">🔁</span> Replicate Everything
        </button>
        <div class="replicate-status" id="status-all"></div>
      </div>
    </div>

  </div>
</main>

<style>
.tab-btn {
  padding: 10px 20px;
  border: 2px solid #e2e8f0;
  background: #fff;
  border-radius: 8px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
  color: #64748b;
  transition: all .2s;
}
.tab-btn.active {
  border-color: #6366f1;
  background: #6366f1;
  color: #fff;
}
.tab-btn:hover:not(.active) {
  border-color: #6366f1;
  color: #6366f1;
}
.replicate-card {
  background: #fff;
  border-radius: 12px;
  padding: 28px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 2px 8px rgba(0,0,0,.04);
}
.replicate-card h3 { font-size:18px;font-weight:600;margin-bottom:8px;color:#1e293b; }
.replicate-card p  { font-size:14px;color:#64748b;margin-bottom:20px;line-height:1.6; }
.replicate-btn     { display:inline-flex;align-items:center;gap:8px;font-size:15px;padding:12px 28px; }
.replicate-btn:disabled { opacity:.6;cursor:not-allowed; }
.replicate-status  { margin-top:16px;font-size:14px;font-weight:500;min-height:24px;white-space:pre-line; }
.replicate-status.success { color:#16a34a; }
.replicate-status.error   { color:#dc2626; }
.replicate-status.loading { color:#6366f1; }
.loc-badge {
  background:#e0f2fe;color:#0284c7;
  border-radius:6px;padding:4px 10px;
  font-size:12px;font-weight:500;
}
.loc-badge.missing {
  background:#fee2e2;color:#dc2626;
}
</style>

<script>
const API     = '/da360-admin/api.php';
const API_KEY = 'da360-secret-key-2024';

const fromCourseEl    = document.getElementById('from-course-select');
const toCourseEl      = document.getElementById('to-course-select');
const replicateArea   = document.getElementById('replicate-area');
const locationPreview = document.getElementById('location-preview');
const locationList    = document.getElementById('location-list');
const locationWarning = document.getElementById('location-warning');

let matchedLocations = []; // [{id, label}] — locations in both courses

// ── Load & compare locations when either course changes ──────────────────────
async function loadLocations() {
  const fromId = fromCourseEl.value;
  const toId   = toCourseEl.value;

  replicateArea.style.display   = 'none';
  locationPreview.style.display = 'none';
  matchedLocations = [];

  if (!fromId || !toId || fromId === toId) return;

  // Fetch locations for both courses in parallel
  const [fromRes, toRes] = await Promise.all([
    fetch(`${API}?action=get_locations&course_id=${fromId}&api_key=${API_KEY}`).then(r => r.json()),
    fetch(`${API}?action=get_locations&course_id=${toId}&api_key=${API_KEY}`).then(r => r.json()),
  ]);

  if (!fromRes.success || !toRes.success) return;

  const fromLocs = fromRes.locations; // [{id, label}]
  const toLocIds = new Set(toRes.locations.map(l => String(l.id)));

  locationList.innerHTML = '';
  const missing = [];

  fromLocs.forEach(loc => {
    const badge = document.createElement('span');
    if (toLocIds.has(String(loc.id))) {
      badge.className   = 'loc-badge';
      badge.textContent = '✓ ' + loc.label;
      matchedLocations.push(loc);
    } else {
      badge.className   = 'loc-badge missing';
      badge.textContent = '✗ ' + loc.label + ' (not in target)';
      missing.push(loc.label);
    }
    locationList.appendChild(badge);
  });

  locationPreview.style.display = 'block';

  if (missing.length) {
    locationWarning.style.display = 'block';
    locationWarning.textContent   =
      `⚠️ ${missing.length} location(s) not found in target course and will be skipped: ${missing.join(', ')}`;
  } else {
    locationWarning.style.display = 'none';
  }

  if (matchedLocations.length > 0) {
    replicateArea.style.display = 'block';
    clearAllStatuses();
  }
}

fromCourseEl.addEventListener('change', loadLocations);
toCourseEl.addEventListener('change', loadLocations);

// ── Tabs ─────────────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).style.display = 'block';
  });
});

// ── Status helpers ────────────────────────────────────────────────────────────
function setStatus(id, msg, type) {
  const el = document.getElementById('status-' + id);
  el.textContent = msg;
  el.className   = 'replicate-status ' + type;
}
function clearAllStatuses() {
  ['content','curriculum','specialisation','faqs','aitools','metatags','coursewise','all'].forEach(id => setStatus(id,'',''));
}

// ── Replicate one section across all matched locations ────────────────────────
async function doReplicateSection(section) {
  const fromCourseId = fromCourseEl.value;
  const toCourseId   = toCourseEl.value;
  const fromName     = fromCourseEl.options[fromCourseEl.selectedIndex].text;
  const toName       = toCourseEl.options[toCourseEl.selectedIndex].text;

  if (!confirm(`Replicate ${section} from "${fromName}" → "${toName}"?\n\nThis will OVERWRITE existing data.`)) return false;

  // ── Single call sections (no location loop) ───────────────────────
  if (section === 'aitools' || section === 'coursewise') {
    const actionName = section === 'aitools' ? 'replicate_course_aitools' : 'replicate_course_coursewise';
    try {
      const res = await fetch(`${API}?action=${actionName}&api_key=${API_KEY}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          course_id:    fromCourseId,
          to_course_id: toCourseId,
        }),
      });
      const data = await res.json();
      if (data.success) return { successCount: 1, failCount: 0, errors: [] };
      else return { successCount: 0, failCount: 1, errors: [data.message] };
    } catch {
      return { successCount: 0, failCount: 1, errors: ['Network error'] };
    }
  }

  // ── Location loop sections ────────────────────────────────────────
  let successCount = 0;
  let failCount    = 0;
  const errors     = [];

  for (const loc of matchedLocations) {
    try {
      const res = await fetch(`${API}?action=replicate_course_${section}&api_key=${API_KEY}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          course_id:        fromCourseId,
          from_location_id: loc.id,
          to_location_id:   loc.id,
          to_course_id:     toCourseId,
        }),
      });
      const data = await res.json();
      if (data.success) successCount++;
      else { failCount++; errors.push(`${loc.label}: ${data.message}`); }
    } catch {
      failCount++;
      errors.push(`${loc.label}: Network error`);
    }
  }

  return { successCount, failCount, errors };
}

// ── Generic replicate handler ─────────────────────────────────────────────────
async function doReplicate(section, btnId) {
  const btn = document.getElementById(btnId);
  btn.disabled = true;
  setStatus(section, '⏳ Replicating across locations...', 'loading');

  const result = await doReplicateSection(section);
  if (!result) { btn.disabled = false; setStatus(section,'',''); return; }

  const { successCount, failCount, errors } = result;

  if (failCount === 0) {
    setStatus(section, `✅ Done! ${successCount} location(s) replicated successfully.`, 'success');
  } else {
    setStatus(section,
      `⚠️ ${successCount} succeeded, ${failCount} failed:\n${errors.join('\n')}`,
      'error'
    );
  }

  btn.disabled = false;
}

// ── All sections ──────────────────────────────────────────────────────────────
document.getElementById('btn-replicate-all').addEventListener('click', async () => {
  const fromName = fromCourseEl.options[fromCourseEl.selectedIndex].text;
  const toName   = toCourseEl.options[toCourseEl.selectedIndex].text;

  if (!confirm(`Replicate ALL sections from "${fromName}" → "${toName}" across ${matchedLocations.length} location(s)?\n\nThis will OVERWRITE ALL existing data.`)) return;

  const btn = document.getElementById('btn-replicate-all');
  btn.disabled = true;
  setStatus('all', '⏳ Replicating all sections...', 'loading');

  const sections  = ['content','curriculum','specialisation','faqs','aitools','metatags','coursewise'];
  let totalOk     = 0;
  let totalFail   = 0;
  const allErrors = [];

  for (const section of sections) {
    setStatus('all', `⏳ Replicating ${section}...`, 'loading');
    const result = await doReplicateSection(section);
    if (result) {
      totalOk   += result.successCount;
      totalFail += result.failCount;
      result.errors.forEach(e => allErrors.push(`[${section}] ${e}`));
    }
  }

  if (totalFail === 0) {
    setStatus('all', `✅ All done! ${totalOk} operations completed successfully.`, 'success');
  } else {
    setStatus('all',
      `⚠️ ${totalOk} succeeded, ${totalFail} failed:\n${allErrors.join('\n')}`,
      'error'
    );
  }

  btn.disabled = false;
});

// ── Wire section buttons ──────────────────────────────────────────────────────
document.getElementById('btn-replicate-content')
  .addEventListener('click', () => doReplicate('content', 'btn-replicate-content'));
document.getElementById('btn-replicate-curriculum')
  .addEventListener('click', () => doReplicate('curriculum', 'btn-replicate-curriculum'));
document.getElementById('btn-replicate-specialisation')
  .addEventListener('click', () => doReplicate('specialisation', 'btn-replicate-specialisation'));
document.getElementById('btn-replicate-faqs')
  .addEventListener('click', () => doReplicate('faqs', 'btn-replicate-faqs'));
document.getElementById('btn-replicate-aitools')
  .addEventListener('click', () => doReplicate('aitools', 'btn-replicate-aitools'));
document.getElementById('btn-replicate-metatags')
  .addEventListener('click', () => doReplicate('metatags', 'btn-replicate-metatags'));
document.getElementById('btn-replicate-coursewise')
  .addEventListener('click', () => doReplicate('coursewise', 'btn-replicate-coursewise'));
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>