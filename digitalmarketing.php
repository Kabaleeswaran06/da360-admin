<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';

$db = getDB();
$locations = $db->query(
    "SELECT id, slug,label FROM locations WHERE is_active = 1 ORDER BY sort_order"
)->fetchAll(PDO::FETCH_ASSOC);
?>

<main class="main-content" data-page="digitalmarketing">
  <div class="page-header">
    <div class="breadcrumb">`
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Digital Marketing Course Manager</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Digital Marketing <span>Course</span></h1>
        <p class="page-subtitle">Manage content, course data and FAQs by location</p>
      </div>
    </div>
  </div>

  <div class="selector-card">
    <div class="field-group">
      <label for="location-select">📍 Location</label>
      <select id="location-select">
        <option value="">— Select Location —</option>
        <?php foreach ($locations as $l): ?>
          <option value="<?= htmlspecialchars($l['slug']) ?>">📍 <?= htmlspecialchars($l['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <button class="btn btn-primary" id="view-btn" disabled>
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
      </svg>
      Load Editor
    </button>
  </div>

  <div id="result-area">
    <div class="state-placeholder">
      <span class="big-icon">🎓</span>
      <h3>No data loaded yet</h3>
      <p>Choose a location above to load the digital marketing course editor.</p>
    </div>
  </div>
</main>

<script>
(function () {
  var locationSelect = document.getElementById('location-select');
  var viewBtn        = document.getElementById('view-btn');
  var resultArea     = document.getElementById('result-area');

  locationSelect.addEventListener('change', function () {
    viewBtn.disabled = !this.value;
    resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">🎓</span><h3>No data loaded yet</h3><p>Choose a location above to load the editor.</p></div>';
  });

  viewBtn.addEventListener('click', function () {
    var location = locationSelect.value;
    if (!location) return;

    resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon spin">⏳</span><h3>Loading…</h3></div>';

    fetch('/da360-admin/digitalmarketing_api.php?action=get_dm_html&location=' + encodeURIComponent(location))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          resultArea.innerHTML = d.html;
          var s = document.createElement('script');
          s.textContent = d.js;
          document.body.appendChild(s);
        } else {
          resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">⚠️</span><h3>' +
            (d.message || 'Error loading editor') + '</h3></div>';
        }
      })
      .catch(function () {
        resultArea.innerHTML = '<div class="state-placeholder"><span class="big-icon">❌</span><h3>Network error</h3></div>';
      });
  });
})();

// ── FAQ panel functions (called from onclick attrs in the returned HTML) ────
// Same pattern as faqs.php — defined at page level so they survive innerHTML injection.

function dmShowToast(msg) {
    var t = document.getElementById('dm-page-toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(function () { t.classList.remove('show'); }, 2600);
}

function dmToggleCatPanel(cat) {
    var panel = document.getElementById('dm-cat-panel-' + cat);
    if (panel) panel.classList.toggle('open');
}

function dmUpdateCatBadge(cat) {
    var tbody = document.getElementById('dm-tbody-' + cat);
    var badge = document.getElementById('dm-badge-' + cat);
    if (!tbody || !badge) return;
    var filled = tbody.querySelectorAll('.faq-row.has-data').length;
    badge.textContent = filled + '/10 filled';
}

function dmSaveFaqRow(btn) {
    var row      = btn.closest('tr');
    var question = row.querySelector('.faq-question').value.trim();
    var answer   = row.querySelector('.faq-answer').value.trim();
    var isActive = row.querySelector('.faq-active').checked ? '1' : '0';

    if (!question && !answer) {
        dmShowToast('⚠️ Question and answer are both empty — skipped.');
        return;
    }

    row.classList.add('saving');
    row.classList.remove('saved', 'errored');

    var fd = new FormData();
    fd.append('faq_id',    row.dataset.id    || '0');
    fd.append('location',  row.dataset.loc);
    fd.append('label',     row.dataset.cat);
    fd.append('sort_order',row.dataset.sort);
    fd.append('question',  question);
    fd.append('answer',    answer);
    fd.append('is_active', isActive);

    fetch('/da360-admin/digitalmarketing_api.php?action=save_dm_faq', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            row.classList.remove('saving');
            if (data.success) {
                row.classList.add('saved', 'has-data');
                row.classList.remove('empty-row');
                if (data.faq_id && !row.querySelector('.btn-delete-row')) {
                    row.dataset.id = data.faq_id;
                    var delBtn = document.createElement('button');
                    delBtn.className   = 'btn-delete-row';
                    delBtn.title       = 'Delete';
                    delBtn.innerHTML   = '🗑️ Del';
                    delBtn.setAttribute('onclick', 'dmDeleteFaqRow(this)');
                    row.querySelector('.td-actions').appendChild(delBtn);
                }
                dmUpdateCatBadge(row.dataset.cat);
                dmShowToast('✅ FAQ #' + row.dataset.sort + ' saved!');
                setTimeout(function () { row.classList.remove('saved'); }, 2200);
            } else {
                row.classList.add('errored');
                dmShowToast('❌ ' + (data.message || 'Save failed.'));
                setTimeout(function () { row.classList.remove('errored'); }, 3000);
            }
        })
        .catch(function () {
            row.classList.remove('saving');
            dmShowToast('❌ Network error.');
        });
}

function dmDeleteFaqRow(btn) {
    var row = btn.closest('tr');
    var id  = row.dataset.id;
    if (!id || id === '0') { dmShowToast('⚠️ Nothing to delete.'); return; }
    if (!confirm('Delete FAQ #' + row.dataset.sort + '?')) return;

    row.classList.add('saving');

    var fd = new FormData();
    fd.append('faq_id',   id);
    fd.append('location', row.dataset.loc);

    fetch('/da360-admin/digitalmarketing_api.php?action=delete_dm_faq', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
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
                dmUpdateCatBadge(row.dataset.cat);
                dmShowToast('🗑️ FAQ #' + row.dataset.sort + ' deleted.');
            } else {
                dmShowToast('❌ ' + (data.message || 'Delete failed.'));
            }
        })
        .catch(function () {
            row.classList.remove('saving');
            dmShowToast('❌ Network error.');
        });
}

function dmSaveAllInCategory(cat) {
    var rows    = document.querySelectorAll('#dm-tbody-' + cat + ' .faq-row');
    var saved   = 0;
    var skipped = 0;
    rows.forEach(function (row) {
        var q = row.querySelector('.faq-question').value.trim();
        var a = row.querySelector('.faq-answer').value.trim();
        if (!q && !a) { skipped++; return; }
        dmSaveFaqRow(row.querySelector('.btn-save-row'));
        saved++;
    });
    setTimeout(function () {
        dmShowToast('💾 Saving ' + saved + ' FAQs' + (skipped ? ' (' + skipped + ' empty skipped)' : '') + ' in ' + cat);
    }, 200);
}
</script>
<div id="dm-page-toast" style="position:fixed;bottom:28px;right:28px;z-index:9999;padding:12px 20px;border-radius:10px;font-size:14px;font-weight:600;background:#1e293b;color:#fff;box-shadow:0 4px 20px rgba(0,0,0,.2);opacity:0;transform:translateY(10px);transition:opacity .25s,transform .25s;pointer-events:none;"></div>
<style>#dm-page-toast.show{opacity:1;transform:translateY(0);}</style>

<?php include __DIR__ . '/partials/footer.php'; ?>
