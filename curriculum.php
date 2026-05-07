<?php
/**
 * curriculum.php
 * Responsibilities: Auth check, render page shell + course/location dropdowns.
 * Everything else (heading, batches, modules) is handled by curriculum_api.php via AJAX.
 */

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

<main class="main-content" data-page="curriculum">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Curriculum Manager</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Curriculum <span>Manager</span></h1>
        <p class="page-subtitle">Manage heading, batch timings, modules and topics by course and location</p>
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
      Load Curriculum
    </button>
  </div>

  <div id="result-area">
    <div class="state-placeholder">
      <span class="big-icon">📦</span>
      <h3>No curriculum loaded yet</h3>
      <p>Choose a course and location above to load the curriculum editor.</p>
    </div>
  </div>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
<div id="toast"></div>

<script>
// ── Course → Locations ────────────────────────────────────────────────────────
var courseSelect   = document.getElementById('course-select');
var locationSelect = document.getElementById('location-select');
var viewBtn        = document.getElementById('view-btn');
var resultArea     = document.getElementById('result-area');

courseSelect.addEventListener('change', function () {
    var courseId = this.value;
    locationSelect.innerHTML = '<option value="">— Loading… —</option>';
    locationSelect.disabled  = true;
    viewBtn.disabled         = true;

    if (!courseId) {
        locationSelect.innerHTML = '<option value="">— Select Location —</option>';
        return;
    }

    fetch('/da360-admin/api.php?action=get_locations&course_id=' + courseId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            locationSelect.innerHTML = '<option value="">— Select Location —</option>';
            if (data.success && data.locations.length) {
                data.locations.forEach(function (loc) {
                    var opt = document.createElement('option');
                    opt.value       = loc.id;
                    opt.textContent = loc.label;
                    locationSelect.appendChild(opt);
                });
                locationSelect.disabled = false;
            } else {
                locationSelect.innerHTML = '<option value="">No locations found</option>';
            }
        })
        .catch(function () {
            locationSelect.innerHTML = '<option value="">Error loading</option>';
        });
});

locationSelect.addEventListener('change', function () {
    viewBtn.disabled = !this.value;
});

// ── Load curriculum HTML from API ─────────────────────────────────────────────
viewBtn.addEventListener('click', function () {
    var courseId   = courseSelect.value;
    var locationId = locationSelect.value;
    if (!courseId || !locationId) return;

    resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">⏳</span><h3>Loading…</h3></div>';

    fetch('/da360-admin/curriculum_api.php?action=get_curriculum_html&course_id=' + courseId + '&location_id=' + locationId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                resultArea.innerHTML = data.html;
                if (data.js) {
                    var s = document.createElement('script');
                    s.textContent = data.js;
                    document.body.appendChild(s);
                }
            } else {
                resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">❌</span><h3>' + (data.message || 'Error') + '</h3></div>';
            }
        })
        .catch(function () {
            resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">❌</span><h3>Network error</h3></div>';
        });
});

// ── Toast helper (used by injected JS) ───────────────────────────────────────
function showToast(msg) {
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.className   = 'toast show';
    clearTimeout(t._tid);
    t._tid = setTimeout(function () { t.className = 'toast'; }, 3000);
}
</script>
