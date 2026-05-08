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

<main class="main-content" data-page="specialisation">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Specialisation Manager</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Specialisation <span>Manager</span></h1>
        <p class="page-subtitle">Manage heading, specialisation modules and topics by course and location</p>
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
      Load Specialisation
    </button>
  </div>

  <div id="result-area">
    <div class="state-placeholder">
      <span class="big-icon">🎯</span>
      <h3>No specialisation loaded yet</h3>
      <p>Choose a course and location above to load the specialisation editor.</p>
    </div>
  </div>
</main>

<script>
(function () {
  var courseSelect   = document.getElementById('course-select');
  var locationSelect = document.getElementById('location-select');
  var viewBtn        = document.getElementById('view-btn');
  var resultArea     = document.getElementById('result-area');

  // ── Load locations when course changes ─────────────────────────────────────
  courseSelect.addEventListener('change', function () {
    var courseId = this.value;
    locationSelect.innerHTML = '<option value="">— Select Location —</option>';
    locationSelect.disabled = true;
    viewBtn.disabled = true;

    if (!courseId) return;

    fetch('/da360-admin/specialisation_api.php?action=get_locations&course_id=' + courseId)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success && d.locations.length) {
          d.locations.forEach(function (loc) {
            var opt = document.createElement('option');
            opt.value       = loc.id;
            opt.textContent = loc.label;
            locationSelect.appendChild(opt);
          });
          locationSelect.disabled = false;
        } else {
          showToast('⚠️ No locations found for this course.');
        }
      })
      .catch(function () { showToast('❌ Failed to load locations.'); });
  });

  // ── Enable Load button when location selected ───────────────────────────────
  locationSelect.addEventListener('change', function () {
    viewBtn.disabled = !this.value;
  });

  // ── Load specialisation editor ──────────────────────────────────────────────
  viewBtn.addEventListener('click', function () {
    var courseId   = courseSelect.value;
    var locationId = locationSelect.value;
    if (!courseId || !locationId) return;

    resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon spin">⏳</span><h3>Loading…</h3></div>';

    fetch(
      '/da360-admin/specialisation_api.php?action=get_specialisation_html' +
      '&course_id='   + courseId +
      '&location_id=' + locationId
    )
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          resultArea.innerHTML = d.html;
          var s = document.createElement('script');
          s.textContent = d.js;
          document.body.appendChild(s);
        } else {
          resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">⚠️</span><h3>' +
            (d.message || 'Error loading specialisation') + '</h3></div>';
        }
      })
      .catch(function () {
        resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">❌</span><h3>Network error</h3></div>';
      });
  });

  // ── Global toast helper ─────────────────────────────────────────────────────
  window.showToast = window.showToast || function (msg) {
    var t = document.createElement('div');
    t.className   = 'toast-msg';
    t.textContent = msg;
    Object.assign(t.style, {
      position:'fixed', bottom:'24px', right:'24px', background:'#1e293b',
      color:'#fff', padding:'10px 18px', borderRadius:'8px', fontSize:'14px',
      zIndex:9999, boxShadow:'0 4px 12px rgba(0,0,0,.25)', opacity:'0',
      transition:'opacity .25s'
    });
    document.body.appendChild(t);
    setTimeout(function () { t.style.opacity = '1'; }, 10);
    setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 300); }, 3000);
  };
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
