<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();
$courses = $db->query("
    SELECT c.*, COUNT(cc.id) AS content_count
    FROM courses c
    LEFT JOIN course_content cc ON c.id = cc.course_id
    GROUP BY c.id
    ORDER BY c.sort_order, c.label
")->fetchAll();

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Courses</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">All <span>Courses</span></h1>
        <p class="page-subtitle">Overview of all courses in the system.</p>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title">📚 Course List</div>
      <span class="meta-pill"><?= count($courses) ?> courses</span>
    </div>
    <div class="card-body" style="padding:0;">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Course Name</th>
            <th>Slug</th>
            <th>Content Entries</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($courses as $i => $c): ?>
          <tr>
            <td style="color:var(--muted)"><?= $i + 1 ?></td>
            <td style="font-weight:500"><?= htmlspecialchars($c['label']) ?></td>
            <td><code style="font-size:0.8rem; color:var(--muted)"><?= htmlspecialchars($c['slug']) ?></code></td>
            <td>
              <span class="meta-pill accent" style="font-size:0.75rem"><?= $c['content_count'] ?> entries</span>
            </td>
            <td>
              <?php if ($c['is_active']): ?>
                <span class="badge badge-green">Active</span>
              <?php else: ?>
                <span class="badge badge-gray">Inactive</span>
              <?php endif; ?>
            </td>
            <td style="color:var(--muted); font-size:0.82rem"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($courses)): ?>
          <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--muted)">No courses found. Run schema.sql to seed data.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
<div id="toast"></div>
