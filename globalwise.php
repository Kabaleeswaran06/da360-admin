<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';

?>

<main class="main-content" data-page="globalwise">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Global Content Manager</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Global <span>Manager</span></h1>
        <p class="page-subtitle">Manage hero counts, videos, success stories, faculty, banners, blogs and media</p>
      </div>
    </div>
  </div>

  <div id="result-area">
    <div class="state-placeholder">
      <span class="big-icon spin">⏳</span>
      <h3>Loading global editor…</h3>
    </div>
  </div>
</main>

<script>
(function () {
  var resultArea = document.getElementById('result-area');

  fetch('/da360-admin/globalwise_api.php?action=get_globalwise_html')
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d.success) {
        resultArea.innerHTML = d.html;
        var s = document.createElement('script');
        s.textContent = d.js;
        document.body.appendChild(s);
      } else {
        resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">⚠️</span><h3>' +
          (d.message || 'Error loading global editor') + '</h3></div>';
      }
    })
    .catch(function () {
      resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">❌</span><h3>Network error</h3></div>';
    });
})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
