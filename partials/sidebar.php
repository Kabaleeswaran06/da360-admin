<?php
// partials/sidebar.php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

$navItems = [
  ['slug' => 'dashboard',       'label' => 'Dashboard',        'icon' => 'grid',       'href' => 'dashboard.php'],
  ['slug' => 'content-manager', 'label' => 'Content Manager',  'icon' => 'layers',     'href' => 'content-manager.php'],
  ['slug' => 'faqs', 'label' => 'FAQs',  'icon' => 'faqs',     'href' => 'faqs.php'],
  ['slug' => 'curriculum',       'label' => 'Curriculum',  'icon' => 'curriculum',     'href' => 'curriculum.php'],
  ['slug' => 'specialisation',   'label' => 'Specialisation',  'icon' => 'specialisation',     'href' => 'specialisation.php'],
  ['slug' => 'aitools',          'label' => 'AI Tools',  'icon' => 'aitools',     'href' => 'aitools.php'],
  ['slug' => 'metatags',         'label' => 'Meta Tags',  'icon' => 'metatags',     'href' => 'metatags.php'],
  ['slug' => 'schemas',          'label' => 'Schemas',  'icon' => 'schemas',     'href' => 'schemas.php'],
  ['slug' => 'coursewise',       'label' => 'Course section',  'icon' => 'coursewise',     'href' => 'coursewise.php'],
  ['slug' => 'globalwise',      'label' => 'Global section',  'icon' => 'globalwise',     'href' => 'globalwise.php'],
  ['slug' => 'courses Details', 'label' => 'Courses',          'icon' => 'book-open',  'href' => 'MenuDetails.php'],
  ['slug' => 'courses',         'label' => 'Courses',          'icon' => 'book-open',  'href' => 'courses.php'],
  ['slug' => 'locations',       'label' => 'Locations',        'icon' => 'map-pin',    'href' => 'locations.php'],
];

$icons = [
  'grid'      => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
  'layers'    => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>',
  'book-open' => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
  'map-pin'   => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
  'faqs' => '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>  <polyline points="14 2 14 8 20 8"/>  <path d="M10 12a2 2 0 0 1 3.83-1c0 1.5-2 2.5-2 3.5"/>  <path d="M12 18h.01"/></svg>',
  'curriculum'=>'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">  <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />  <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" /></svg>',
  'specialisation' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">  <path d="M4 19.5V4a2 2 0 0 1 2-2h14v18H6.5a2.5 2.5 0 0 1-2.5-2Z" />  <path d="M12 2v20" />  <path d="M16 6h4v8h-4z"/></svg>',
  'aitools' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>',
  'metatags' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7l-2 5 2 5"/><path d="M20 7l2 5-2 5"/><rect width="8" height="10" x="8" y="7" rx="1"/><path d="M12 11v2"/></svg>',
  'schemas' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18"/><path d="M3 19h18"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M6 5v4"/><path d="M18 5v4"/><path d="M6 15v4"/><path d="M18 15v4"/></svg>',
  'coursewise' =>  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">  <path d="M12 3L2 9l10 6 10-6-10-6Z"/>  <path d="M2 15l10 6 10-6"/>  <path d="M2 9v6"/>  <path d="M22 9v6"/></svg>',
  'globalwise' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">  <circle cx="12" cy="12" r="10" />  <line x1="2" y1="12" x2="22" y2="12" />  <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" /></svg>',
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
