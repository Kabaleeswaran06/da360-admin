<?php
// partials/header.php
require_once __DIR__ . '/../config/auth.php';
requireLogin();
$user = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>DA360 Admin — <?= ucfirst($currentPage) ?></title>
   <link rel="icon" type="image/png" href="images/da360logo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/da360-admin/assets/style.css">
</head>
<body>

<!-- ── TOP HEADER ───────────────────────────────────────── -->
<header class="top-header">
  <div class="header-left">
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
      <span></span><span></span><span></span>
    </button>
    <div class="brand">
      <span class="brand-logo">DA<em>360</em></span>
      <span class="brand-divider"></span>
      <span class="brand-sub">Admin Panel</span>
    </div>
  </div>
  <div class="header-right">
    <div class="header-user">
      <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
      <div class="user-info">
        <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
        <span class="user-role"><?= htmlspecialchars($user['role']) ?></span>
      </div>
      <a href="/da360-admin/logout.php" class="logout-btn" title="Logout">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>
        </svg>
      </a>
    </div>
  </div>
</header>
<script>
  async function clearCache(tags) {
  const res = await fetch('http://www.localhost:3000/api/revalidate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      secret: 'my-secret-123',
      tags,
    }),
  });
  </script>
<!-- ── LAYOUT WRAPPER ────────────────────────────────────── -->
<div class="layout">
