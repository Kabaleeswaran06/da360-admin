<?php
// partials/sidebar.php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$navItems = [
  ['slug' => 'dashboard',       'label' => 'Dashboard',        'icon' => 'grid',       'href' => 'dashboard.php'],
  ['slug' => 'content-manager', 'label' => 'Content Manager',  'icon' => 'layers',     'href' => 'content-manager.php'],
  ['slug' => 'faqs', 'label' => 'FAQs',  'icon' => 'faqs',     'href' => 'faqs.php'],
  ['slug' => 'courses',         'label' => 'Courses',          'icon' => 'book-open',  'href' => 'courses.php'],
  ['slug' => 'locations',       'label' => 'Locations',        'icon' => 'map-pin',    'href' => 'locations.php'],
];

$icons = [
  'grid'      => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
  'layers'    => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
  'book-open' => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
  'map-pin'   => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
  'faqs' => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>  <polyline points="14 2 14 8 20 8"/>  <path d="M10 12a2 2 0 0 1 3.83-1c0 1.5-2 2.5-2 3.5"/>  <path d="M12 18h.01"/></svg>',
  'register' => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">  <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>  <circle cx="9" cy="7" r="4"/>  <line x1="19" y1="8" x2="19" y2="14"/>  <line x1="22" y1="11" x2="16" y2="11"/></svg>',
];
$register="/da360-admin/register.php";
?>
<!-- ── SIDEBAR ────────────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">
  <nav class="sidebar-nav">
    <div class="nav-section-label">Main Menu</div>
    <ul class="nav-list">
      <?php foreach ($navItems as $item):
        $isActive = ($currentPage === $item['slug']);
      ?>
      <li class="nav-item <?= $isActive ? 'active' : '' ?>">
        <a class="nav-link" href="/da360-admin/<?= $item['href'] ?>" class="nav-link">
          <span class="nav-icon"><?= $icons[$item['icon']] ?></span>
          <span class="nav-label"><?= $item['label'] ?></span>
          <?php if ($isActive): ?>
            <span class="nav-active-dot"></span>
          <?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>

      <?php if (getCurrentUser()['role'] === 'Super Admin'): ?>
        <li class="nav-item"><a class="nav-link" href=<?=$register?> > <span class="nav-icon"><?= $icons['register'] ?></span><span class="nav-label">User Register</span></a></li>
      <?php endif; ?>
    </ul>

    <div class="nav-section-label" style="margin-top:28px">System</div>
    <ul class="nav-list">
      <li class="nav-item">
        <a href="/da360-admin/logout.php" class="nav-link nav-link-danger">
          <span class="nav-icon">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
            </svg>
          </span>
          <span class="nav-label">Logout</span>
        </a>
      </li>
    </ul>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-version">DA360 CMS v1.0</div>
  </div>
</aside>
