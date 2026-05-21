<?php
/**
 * faqs.php
 * Responsibilities: Auth check, render page shell + course dropdown.
 * Everything else (locations, FAQ table, save, delete) is handled by faq_api.php via AJAX.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();

$courses = $db->query(
    "SELECT id, label FROM courses WHERE is_active = 1 ORDER BY sort_order"
)->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content" data-page="faqs">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>FAQ Manager</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">FAQ <span>Manager</span></h1>
        <p class="page-subtitle">Manage FAQs by course and location — 5 categories × 10 questions each</p>
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
      Load FAQs
    </button>
  </div>

  <div id="result-area">
    <div class="state-placeholder">
      <span class="big-icon">❓</span>
      <h3>No FAQs loaded yet</h3>
      <p>Choose a course and location above to load FAQ categories.</p>
    </div>
  </div>
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
<div id="toast"></div>

<script>
// ── All FAQ functions live here in faqs.php so they execute on page load.
// faq_api.php returns only HTML (no <script>). The onclick attributes in
// that HTML call these functions which are already defined on window. ──────

function toggleCatPanel(cat) {
    var panel = document.getElementById('cat-panel-' + cat);
    if (!panel) return;
    panel.classList.toggle('open');
}

function saveFaqRow(btn) {
    var row      = btn.closest('tr');
    var question = row.querySelector('.faq-question').value.trim();
    var answer   = row.querySelector('.faq-answer').value.trim();
    var isActive = row.querySelector('.faq-active').checked ? '1' : '0';

    if (!question && !answer) {
        showToast('⚠️ Question and answer are both empty — skipped.');
        return;
    }

    row.classList.add('saving');
    row.classList.remove('saved', 'errored');

    var fd = new FormData();
    fd.append('course_id',   row.dataset.course);
    fd.append('location_id', row.dataset.location);
    fd.append('category',    row.dataset.cat);
    fd.append('sort_order',  row.dataset.sort);
    fd.append('question',    question);
    fd.append('answer',      answer);
    fd.append('is_active',   isActive);

    fetch('/da360-admin/faq_api.php?action=save_faq', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            row.classList.remove('saving');
            if (data.success) {
                row.classList.add('saved', 'has-data');
                row.classList.remove('empty-row');
                if (data.id && !row.querySelector('.btn-delete-row')) {
                    row.dataset.id = data.id;
                    var delBtn = document.createElement('button');
                    delBtn.className   = 'btn-delete-row';
                    delBtn.title       = 'Delete';
                    delBtn.innerHTML   = '🗑️ Del';
                    delBtn.setAttribute('onclick', 'deleteFaqRow(this)');
                    row.querySelector('.td-actions').appendChild(delBtn);
                }
                updateCatBadge(row.dataset.cat);
                showToast('✅ FAQ #' + row.dataset.sort + ' saved!');
                setTimeout(function() { row.classList.remove('saved'); }, 2200);
            } else {
                row.classList.add('errored');
                showToast('❌ ' + (data.message || 'Save failed.'));
                setTimeout(function() { row.classList.remove('errored'); }, 3000);
            }
        })
        .catch(function() {
            row.classList.remove('saving');
            row.classList.add('errored');
            showToast('❌ Network error.');
        });
}

function deleteFaqRow(btn) {
    var row = btn.closest('tr');
    var id  = row.dataset.id;
    if (!id || id === '0') { showToast('⚠️ Nothing to delete.'); return; }
    if (!confirm('Delete FAQ #' + row.dataset.sort + '?')) return;

    row.classList.add('saving');

    var fd = new FormData();
    fd.append('id',          id);
    fd.append('course_id',   row.dataset.course);
    fd.append('location_id', row.dataset.location);

    fetch('/da360-admin/faq_api.php?action=delete_faq', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            row.classList.remove('saving');
            if (data.success) {
                row.querySelector('.faq-question').value = '';
                row.querySelector('.faq-answer').value   = '';
                row.querySelector('.faq-active').checked = true;
                row.classList.remove('has-data', 'saved');
                row.classList.add('empty-row');
                row.dataset.id = '0';
                var delBtn = row.querySelector('.btn-delete-row');
                if (delBtn) delBtn.remove();
                updateCatBadge(row.dataset.cat);
                showToast('🗑️ FAQ #' + row.dataset.sort + ' deleted.');
            } else {
                showToast('❌ ' + (data.message || 'Delete failed.'));
            }
        })
        .catch(function() {
            row.classList.remove('saving');
            showToast('❌ Network error.');
        });
}

function saveAllInCategory(cat) {
    var rows    = document.querySelectorAll('#tbody-' + cat + ' .faq-row');
    var saved   = 0;
    var skipped = 0;
    rows.forEach(function(row) {
        var q = row.querySelector('.faq-question').value.trim();
        var a = row.querySelector('.faq-answer').value.trim();
        if (!q && !a) { skipped++; return; }
        saveFaqRow(row.querySelector('.btn-save-row'));
        saved++;
    });
    setTimeout(function() {
        showToast('✅ Saving ' + saved + ' FAQs' + (skipped ? ' (' + skipped + ' empty skipped)' : '') + ' in ' + cat);
    }, 200);
}

function updateCatBadge(cat) {
    var tbody = document.getElementById('tbody-' + cat);
    var badge = document.getElementById('badge-' + cat);
    if (!tbody || !badge) return;
    var filled = tbody.querySelectorAll('.faq-row.has-data').length;
    badge.textContent = filled + '/10 filled';
}
</script>
