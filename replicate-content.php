<?php
/**
 * replicate-content.php
 * Select Course → From Location → To Location → Replicate each section
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();

$courses = $db->query(
  "SELECT id, label FROM courses WHERE is_active=1 ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content" data-page="replicate-content">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Replicate Content</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Replicate <span>Content</span></h1>
        <p style="color:#64748b;margin-top:4px;font-size:14px;">
          Copy content from one location to another across all sections.
        </p>
      </div>
    </div>
  </div>

  <!-- ── Selector Card ─────────────────────────────────────────────────── -->
  <div class="selector-card" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
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
      <label for="from-location-select">📍 Replicate From</label>
      <select id="from-location-select" disabled>
        <option value="">— Select Source —</option>
      </select>
    </div>

    <div class="field-group">
      <label for="to-location-select">📍 Replicate To</label>
      <select id="to-location-select" disabled>
        <option value="">— Select Target —</option>
      </select>
    </div>
  </div>

  <!-- ── Replicate Sections ────────────────────────────────────────────── -->
  <div id="replicate-area" style="display:none;margin-top:24px;">

    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:10px;padding:14px 20px;margin-bottom:20px;font-size:14px;">
      ⚠️ <strong>Warning:</strong> Replicating will <strong>overwrite</strong> existing data in the target location. This action cannot be undone.<br>
      ⚠️ <strong>Note:</strong> Meta Tags and Schemas has to be Replicate manually 
    </div>

    <!-- Tabs -->
    <div class="tab-bar" style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
      <button class="tab-btn active" data-tab="content">📣 Content</button>
      <button class="tab-btn" data-tab="curriculum">📘 Curriculum</button>
      <button class="tab-btn" data-tab="specialisation">🎓 Specialisation</button>
      <button class="tab-btn" data-tab="faqs">❓ FAQs</button>
    </div>

    <!-- Content Tab -->
    <div class="tab-panel" id="tab-content">
      <div class="replicate-card">
        <div class="replicate-info">
          <h3>📣 Content Fields</h3>
          <p>Replicates all text fields (Lead Capture, Cohort, Stories, Skills, Tools, etc.) from the source location to the target location.</p>
          <ul class="replicate-list" id="content-preview"></ul>
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
          <p>Replicates the curriculum heading, description, batch timings and batch slots from the source location to the target location. <strong>Modules and topics are course-wide and are not location-specific.</strong></p>
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
          <p>Replicates the specialisation heading and description from the source location to the target location. <strong>Modules and topics are course-wide and are not location-specific.</strong></p>
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
          <p>Replicates all FAQ entries (Program, Delivery, Placement, Certification, Fee) from the source location to the target location. Existing FAQs in the target will be deleted and replaced.</p>
        </div>
        <button class="btn btn-primary replicate-btn" id="btn-replicate-faqs">
          <span class="btn-icon">🔁</span> Replicate FAQs
        </button>
        <div class="replicate-status" id="status-faqs"></div>
      </div>
    </div>

  </div>

  <div id="toast"></div>
</main>

<!-- ── Styles ──────────────────────────────────────────────────────────────── -->
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
.replicate-card h3 {
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 8px;
  color: #1e293b;
}
.replicate-card p {
  font-size: 14px;
  color: #64748b;
  margin-bottom: 20px;
  line-height: 1.6;
}
.replicate-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  padding: 12px 28px;
}
.replicate-btn:disabled {
  opacity: .6;
  cursor: not-allowed;
}
.replicate-status {
  margin-top: 16px;
  font-size: 14px;
  font-weight: 500;
  min-height: 24px;
}
.replicate-status.success { color: #16a34a; }
.replicate-status.error   { color: #dc2626; }
.replicate-status.loading { color: #6366f1; }
.replicate-list {
  list-style: none;
  padding: 0;
  margin: 0 0 20px;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.replicate-list li {
  background: #f1f5f9;
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 12px;
  color: #475569;
}
</style>

<!-- ── Scripts ─────────────────────────────────────────────────────────────── -->
<script>
const API = '/da360-admin/api.php';
const API_KEY = 'da360-secret-key-2024';

const courseEl    = document.getElementById('course-select');
const fromEl      = document.getElementById('from-location-select');
const toEl        = document.getElementById('to-location-select');
const replicateArea = document.getElementById('replicate-area');

// ── Load locations when course changes ───────────────────────────────────────
courseEl.addEventListener('change', async () => {
  const courseId = courseEl.value;
  fromEl.innerHTML = '<option value="">— Select Source —</option>';
  toEl.innerHTML   = '<option value="">— Select Target —</option>';
  fromEl.disabled  = true;
  toEl.disabled    = true;
  replicateArea.style.display = 'none';

  if (!courseId) return;

  const res  = await fetch(`${API}?action=get_locations&course_id=${courseId}&api_key=${API_KEY}`);
  const data = await res.json();
  if (!data.success) return;

  data.locations.forEach(loc => {
    const o1 = new Option(loc.label, loc.id);
    const o2 = new Option(loc.label, loc.id);
    fromEl.appendChild(o1);
    toEl.appendChild(o2);
  });

  fromEl.disabled = false;
  toEl.disabled   = false;
});

// ── Show replicate area when both locations selected ─────────────────────────
function checkSelections() {
  const courseId  = courseEl.value;
  const fromId    = fromEl.value;
  const toId      = toEl.value;

  if (courseId && fromId && toId && fromId !== toId) {
    replicateArea.style.display = 'block';
    clearAllStatuses();
  } else {
    replicateArea.style.display = 'none';
  }
}

fromEl.addEventListener('change', checkSelections);
toEl.addEventListener('change', checkSelections);

// ── Tabs ─────────────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).style.display = 'block';
  });
});

// ── Helper: set status ────────────────────────────────────────────────────────
function setStatus(id, msg, type) {
  const el = document.getElementById('status-' + id);
  el.textContent = msg;
  el.className = 'replicate-status ' + type;
}

function clearAllStatuses() {
  ['content','curriculum','specialisation','faqs'].forEach(id => {
    setStatus(id, '', '');
  });
}

// ── Generic replicate call ────────────────────────────────────────────────────
async function doReplicate(section, btnId) {
  const courseId = courseEl.value;
  const fromId   = fromEl.value;
  const toId     = toEl.value;
  const fromName = fromEl.options[fromEl.selectedIndex].text;
  const toName   = toEl.options[toEl.selectedIndex].text;

  if (!confirm(`Replicate ${section} from "${fromName}" → "${toName}"?\n\nThis will OVERWRITE existing data in the target location.`)) return;

  const btn = document.getElementById(btnId);
  btn.disabled = true;
  setStatus(section, '⏳ Replicating...', 'loading');

  try {
    const res = await fetch(`${API}?action=replicate_${section}&api_key=${API_KEY}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        course_id:        courseId,
        from_location_id: fromId,
        to_location_id:   toId,
      }),
    });
    const data = await res.json();
    if (data.success) {
      setStatus(section, `✅ ${data.message}`, 'success');
    } else {
      setStatus(section, `❌ ${data.message}`, 'error');
    }
  } catch (e) {
    setStatus(section, '❌ Network error. Please try again.', 'error');
  } finally {
    btn.disabled = false;
  }
}

// ── Wire up buttons ───────────────────────────────────────────────────────────
document.getElementById('btn-replicate-content')
  .addEventListener('click', () => doReplicate('content', 'btn-replicate-content'));

document.getElementById('btn-replicate-curriculum')
  .addEventListener('click', () => doReplicate('curriculum', 'btn-replicate-curriculum'));

document.getElementById('btn-replicate-specialisation')
  .addEventListener('click', () => doReplicate('specialisation', 'btn-replicate-specialisation'));

document.getElementById('btn-replicate-faqs')
  .addEventListener('click', () => doReplicate('faqs', 'btn-replicate-faqs'));
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
