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