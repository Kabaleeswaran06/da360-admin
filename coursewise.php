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

<main class="main-content" data-page="coursewise">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Coursewise Manager</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Coursewise <span>Manager</span></h1>
        <p class="page-subtitle">Manage highlights, tools, case studies and live projects by course</p>
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

    <button class="btn btn-primary" id="view-btn" disabled>
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
      </svg>
      Load Coursewise
    </button>
  </div>

  <div id="result-area">
    <div class="state-placeholder">
      <span class="big-icon">📊</span>
      <h3>No coursewise data loaded yet</h3>
      <p>Choose a course and location above to load the coursewise editor.</p>
    </div>
  </div>
</main>

<script>
(function () {
  var courseSelect = document.getElementById('course-select');
  var viewBtn      = document.getElementById('view-btn');
  var resultArea   = document.getElementById('result-area');

  // ── Course change → enable button ──────────────────────────────────────────
  courseSelect.addEventListener('change', function () {
    viewBtn.disabled = !this.value;
    resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">📊</span><h3>No coursewise data loaded yet</h3><p>Choose a course above to load the coursewise editor.</p></div>';
  });

  // ── Load coursewise editor ─────────────────────────────────────────────────
  viewBtn.addEventListener('click', function () {
    var courseId = courseSelect.value;
    if (!courseId) return;

    resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon spin">⏳</span><h3>Loading…</h3></div>';

    fetch(
      '/da360-admin/coursewise_api.php?action=get_coursewise_html' +
      '&course_id=' + courseId
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
            (d.message || 'Error loading coursewise') + '</h3></div>';
        }
      })
      .catch(function () {
        resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">❌</span><h3>Network error</h3></div>';
      });
  });
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>