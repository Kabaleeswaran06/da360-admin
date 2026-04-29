<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();
$locations = $db->query("
    SELECT l.*, COUNT(cc.id) AS content_count
    FROM locations l
    LEFT JOIN course_content cc ON l.id = cc.location_id
    GROUP BY l.id
    ORDER BY l.sort_order, l.label
")->fetchAll();

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Locations</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">All <span>Locations</span></h1>
        <p class="page-subtitle">Overview of all locations configured in the system.</p>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title">📍 Location List</div>
      <span class="meta-pill"><?= count($locations) ?> locations</span>
    </div>
    <div class="card-body" style="padding:0;">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Location Name</th>
            <th>Slug</th>
            <th>Content Entries</th>
            <th>Sort Order</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($locations as $i => $loc): ?>
          <tr>
            <td style="color:var(--muted)"><?= $i + 1 ?></td>
            <td style="font-weight:500"><?= htmlspecialchars($loc['label']) ?></td>
            <td><code style="font-size:0.8rem; color:var(--muted)"><?= htmlspecialchars($loc['slug']) ?></code></td>
            <td>
              <span class="meta-pill accent" style="font-size:0.75rem"><?= $loc['content_count'] ?> entries</span>
            </td>
            <td style="color:var(--muted)"><?= $loc['sort_order'] ?></td>
            <td>
              <?php if ($loc['is_active']): ?>
                <span class="badge badge-green">Active</span>
              <?php else: ?>
                <span class="badge badge-gray">Inactive</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($locations)): ?>
          <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--muted)">No locations found. Run schema.sql to seed data.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
<div id="toast"></div>
