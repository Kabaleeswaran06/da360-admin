<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();
$totalCourses   = $db->query("SELECT COUNT(*) FROM courses WHERE is_active=1")->fetchColumn();
$totalLocations = $db->query("SELECT COUNT(*) FROM locations WHERE is_active=1")->fetchColumn();
$totalContent   = $db->query("SELECT COUNT(*) FROM course_content")->fetchColumn();
$possible       = (int)$totalCourses * (int)$totalLocations;
$coverage       = $possible > 0 ? round((int)$totalContent / $possible * 100) : 0;

$recentContent = $db->query("
    SELECT cc.updated_at, c.label AS course, l.label AS location
    FROM course_content cc
    JOIN courses c ON cc.course_id = c.id
    JOIN locations l ON cc.location_id = l.id
    ORDER BY cc.updated_at DESC
    LIMIT 8
")->fetchAll();

$user = getCurrentUser();
include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="breadcrumb">
      <span>Home</span><span class="breadcrumb-sep">/</span><span>Dashboard</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Welcome, <span><?= htmlspecialchars(explode(' ', $user['name'])[0]) ?></span> 👋</h1>
        <p class="page-subtitle">Here's an overview of your content management system.</p>
      </div>
    </div>
  </div>

  <!-- Stat Cards -->
  <div class="stat-cards">
    <div class="stat-card" style="--stat-color:#ff4f1f">
      <div class="stat-label">Active Courses</div>
      <div class="stat-value"><?= $totalCourses ?></div>
      <div class="stat-icon">📚</div>
    </div>
    <div class="stat-card" style="--stat-color:#0ea5e9">
      <div class="stat-label">Locations</div>
      <div class="stat-value"><?= $totalLocations ?></div>
      <div class="stat-icon">📍</div>
    </div>
    <div class="stat-card" style="--stat-color:#22c55e">
      <div class="stat-label">Content Entries</div>
      <div class="stat-value"><?= $totalContent ?></div>
      <div class="stat-icon">📄</div>
    </div>
    <div class="stat-card" style="--stat-color:#8b5cf6">
      <div class="stat-label">Coverage</div>
      <div class="stat-value"><?= $coverage ?>%</div>
      <div class="stat-icon">🎯</div>
    </div>
  </div>

  <!-- Quick Links -->
  <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:28px;">

    <!-- Quick Access -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">⚡ Quick Access</div>
      </div>
      <div class="card-body" style="display:flex; flex-direction:column; gap:10px;">
        <a href="/da360-admin/content-manager.php" class="btn btn-primary" style="justify-content:center;">
          Open Content Manager
        </a>
        <a href="/da360-admin/courses.php" class="btn btn-secondary" style="justify-content:center;">
          Manage Courses
        </a>
        <a href="/da360-admin/locations.php" class="btn btn-secondary" style="justify-content:center;">
          Manage Locations
        </a>
      </div>
    </div>

    <!-- Recent Updates -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">🕐 Recent Content Updates</div>
      </div>
      <div class="card-body" style="padding:0;">
        <table class="data-table">
          <thead>
            <tr><th>Course</th><th>Location</th><th>Updated</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentContent as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['course']) ?></td>
              <td><?= htmlspecialchars($row['location']) ?></td>
              <td style="color:var(--muted); font-size:0.8rem;"><?= date('d M, H:i', strtotime($row['updated_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentContent)): ?>
            <tr><td colspan="3" style="text-align:center; color:var(--muted); padding:20px;">No content yet</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
<div id="toast"></div>
