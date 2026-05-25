<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();

// ── Fetch all menu items (course_id = 0 = global menu) ───────────────────────
$stmt = $db->query("SELECT id, category, title, duration, mode, on_click, sort_order FROM course_details_items WHERE course_id = 0 ORDER BY category, sort_order");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Attach list rows ──────────────────────────────────────────────────────────
$catGroups = ['leadership' => [], 'pgcp' => [], 'certification' => [], 'college' => []];
foreach ($rows as $row) {
    $s = $db->prepare("SELECT id, list_item, sort_order FROM course_details_list WHERE item_id = ? ORDER BY sort_order");
    $s->execute([(int)$row['id']]);
    $row['list'] = $s->fetchAll(PDO::FETCH_ASSOC);
    $catGroups[$row['category']][] = $row;
}

// ── Category display config ───────────────────────────────────────────────────
$catConfig = [
    'leadership'    => ['label' => 'Leadership',    'emoji' => '🎓', 'color' => '#7c3aed', 'light' => '#f5f3ff', 'badge' => '#ede9fe', 'badgeText' => '#5b21b6'],
    'pgcp'          => ['label' => 'PGCP',          'emoji' => '📜', 'color' => '#2563eb', 'light' => '#eff6ff', 'badge' => '#dbeafe', 'badgeText' => '#1e40af'],
    'certification' => ['label' => 'Certification', 'emoji' => '🏅', 'color' => '#059669', 'light' => '#f0fdf4', 'badge' => '#d1fae5', 'badgeText' => '#065f46'],
    'college'       => ['label' => 'College',       'emoji' => '🏛️', 'color' => '#d97706', 'light' => '#fffbeb', 'badge' => '#fef3c7', 'badgeText' => '#92400e'],
];

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content" data-page="menudetails">

  <!-- ── Page header ── -->
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Menu Details Manager</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Menu <span>Details</span></h1>
        <p class="page-subtitle">Manage course cards displayed in site navigation &amp; landing pages</p>
      </div>
    </div>
  </div>

  <style>
    /* ── Reset ── */
    .md-wrap *, .md-wrap *::before, .md-wrap *::after { box-sizing: border-box; }
    .md-wrap { font-family: system-ui, sans-serif; color: #1e293b; }

    /* ── Category section ── */
    .md-section { margin-bottom: 40px; }
    .md-section-header {
      display: flex; align-items: center; gap: 12px;
      margin-bottom: 18px; padding-bottom: 14px;
      border-bottom: 2px solid #e2e8f0;
    }
    .md-section-icon {
      width: 40px; height: 40px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; flex-shrink: 0;
    }
    .md-section-title { font-size: 18px; font-weight: 700; flex: 1; }
    .md-section-count {
      font-size: 12px; font-weight: 700; padding: 3px 10px;
      border-radius: 20px; background: #f1f5f9; color: #64748b;
    }
    .md-add-btn {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 7px 14px; border: 1.5px dashed; border-radius: 7px;
      font-size: 13px; font-weight: 600; cursor: pointer;
      background: transparent; transition: background .15s, opacity .15s;
    }
    .md-add-btn:hover { opacity: .8; }

    /* ── Cards grid ── */
    .md-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 18px;
    }

    /* ── Single card ── */
    .md-card {
      background: #fff;
      border: 1.5px solid #e2e8f0;
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 1px 4px rgba(0,0,0,.05);
      transition: box-shadow .2s;
      display: flex; flex-direction: column;
    }
    .md-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.10); }
    .md-card.md-editing { box-shadow: 0 0 0 2px var(--card-color, #6366f1); }

    .md-card-top {
      padding: 18px 18px 0;
      border-left: 4px solid var(--card-color, #6366f1);
    }
    .md-card-title {
      font-size: 14px; font-weight: 700; color: #0f172a;
      line-height: 1.4; margin-bottom: 12px;
    }
    .md-card-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
    .md-badge {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 4px 10px; border-radius: 20px;
      font-size: 11px; font-weight: 700; letter-spacing: .2px;
    }
    .md-card-link {
      font-size: 11px; color: #94a3b8; word-break: break-all;
      margin-bottom: 14px; display: block;
    }
    .md-card-link a { color: #6366f1; text-decoration: none; }
    .md-card-link a:hover { text-decoration: underline; }

    .md-card-list {
      list-style: none; padding: 0 18px 14px; margin: 0;
      border-left: 4px solid var(--card-color, #6366f1);
    }
    .md-card-list li {
      display: flex; align-items: flex-start; gap: 7px;
      font-size: 12px; color: #475569; padding: 3px 0;
    }
    .md-card-list li::before {
      content: '✓'; font-size: 11px; font-weight: 700; flex-shrink: 0; margin-top: 1px;
      color: var(--card-color, #6366f1);
    }

    /* ── Card actions ── */
    .md-card-actions {
      display: flex; gap: 8px; padding: 12px 18px;
      border-top: 1px solid #f1f5f9; margin-top: auto;
    }
    .md-btn {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 6px 12px; border: none; border-radius: 6px;
      font-size: 12px; font-weight: 600; cursor: pointer;
      transition: opacity .15s;
    }
    .md-btn:hover { opacity: .82; }
    .md-btn-edit   { background: #f1f5f9; color: #334155; flex: 1; justify-content: center; }
    .md-btn-save   { background: #22c55e; color: #fff; flex: 1; justify-content: center; }
    .md-btn-cancel { background: #e2e8f0; color: #475569; }
    .md-btn-del    { background: #fee2e2; color: #dc2626; }

    /* ── Edit panel (inside card) ── */
    .md-edit-panel { display: none; padding: 16px 18px; border-top: 1px solid #e2e8f0; background: #f8fafc; }
    .md-edit-panel.open { display: block; }

    .md-field { margin-bottom: 12px; }
    .md-field label {
      display: block; font-size: 11px; font-weight: 700; color: #64748b;
      text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px;
    }
    .md-field input, .md-field select {
      width: 100%; padding: 8px 11px;
      border: 1.5px solid #cbd5e1; border-radius: 6px;
      font-size: 13px; color: #1e293b; background: #fff;
      transition: border-color .15s;
    }
    .md-field input:focus, .md-field select:focus { border-color: #6366f1; outline: none; }
    .md-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

    /* ── List item rows inside edit ── */
    .md-list-row {
      display: flex; align-items: center; gap: 7px; margin-bottom: 6px;
    }
    .md-list-row input { flex: 1; padding: 7px 10px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13px; }
    .md-list-row input:focus { border-color: #6366f1; outline: none; }
    .md-btn-del-sm {
      width: 28px; height: 28px; border: none; border-radius: 5px;
      background: #fee2e2; color: #dc2626; font-size: 13px; cursor: pointer;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .md-btn-plus {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 5px 10px; border: 1.5px dashed #cbd5e1; border-radius: 6px;
      font-size: 12px; font-weight: 600; color: #64748b; background: transparent; cursor: pointer;
      transition: border-color .15s, color .15s;
    }
    .md-btn-plus:hover { border-color: #6366f1; color: #6366f1; }

    /* ── Add-new card placeholder ── */
    .md-card-new {
      border: 2px dashed #cbd5e1; border-radius: 14px;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      min-height: 160px; gap: 8px; cursor: pointer;
      background: #f8fafc; color: #94a3b8;
      font-size: 13px; font-weight: 600;
      transition: border-color .2s, color .2s, background .2s;
    }
    .md-card-new:hover { border-color: #6366f1; color: #6366f1; background: #f5f3ff; }
    .md-card-new span { font-size: 26px; }

    /* ── Toast ── */
    #md-toast {
      position: fixed; bottom: 28px; right: 28px; z-index: 9999;
      padding: 12px 20px; border-radius: 10px; font-size: 14px; font-weight: 600;
      background: #1e293b; color: #fff; box-shadow: 0 4px 20px rgba(0,0,0,.2);
      opacity: 0; transform: translateY(10px);
      transition: opacity .25s, transform .25s;
      pointer-events: none;
    }
    #md-toast.show { opacity: 1; transform: translateY(0); }

    /* ── Saving state ── */
    .md-card.md-saving { opacity: .55; pointer-events: none; }
    .md-card.md-saved  { outline: 2px solid #22c55e; }
  </style>

  <div class="md-wrap">

    <?php foreach ($catConfig as $catKey => $cat): ?>
    <?php $cards = $catGroups[$catKey]; $count = count($cards); ?>

    <!-- ══ <?= strtoupper($catKey) ?> SECTION ══ -->
    <div class="md-section" id="md-section-<?= $catKey ?>">

      <div class="md-section-header">
        <div class="md-section-icon" style="background:<?= $cat['light'] ?>;">
          <?= $cat['emoji'] ?>
        </div>
        <span class="md-section-title" style="color:<?= $cat['color'] ?>;">
          <?= $cat['label'] ?>
        </span>
        <span class="md-section-count" id="md-count-<?= $catKey ?>"><?= $count ?> course<?= $count !== 1 ? 's' : '' ?></span>
        <button class="md-add-btn"
                style="color:<?= $cat['color'] ?>;border-color:<?= $cat['color'] ?>;"
                data-action="add-card"
                data-category="<?= $catKey ?>"
                data-color="<?= $cat['color'] ?>"
                data-badge="<?= $cat['badge'] ?>"
                data-badge-text="<?= $cat['badgeText'] ?>">
          ＋ Add Course
        </button>
      </div>

      <div class="md-grid" id="md-grid-<?= $catKey ?>">

        <?php foreach ($cards as $ci => $card): ?>
        <?php $listJson = htmlspecialchars(json_encode(array_column($card['list'], 'list_item')), ENT_QUOTES); ?>

        <div class="md-card"
             data-item-id="<?= (int)$card['id'] ?>"
             data-category="<?= $catKey ?>"
             data-sort="<?= $ci + 1 ?>"
             style="--card-color: <?= $cat['color'] ?>;">

          <!-- ── View: top section ── -->
          <div class="md-card-top">
            <div class="md-card-title"><?= htmlspecialchars($card['title']) ?></div>
            <div class="md-card-badges">
              <span class="md-badge" style="background:<?= $cat['badge'] ?>;color:<?= $cat['badgeText'] ?>;">
                ⏱ <?= htmlspecialchars($card['duration']) ?>
              </span>
              <span class="md-badge" style="background:#f1f5f9;color:#475569;">
                📍 <?= htmlspecialchars($card['mode']) ?>
              </span>
            </div>
            <span class="md-card-link">
              <a href="<?= htmlspecialchars($card['on_click']) ?>" target="_blank"><?= htmlspecialchars($card['on_click']) ?></a>
            </span>
          </div>

          <!-- ── View: list ── -->
          <ul class="md-card-list" style="--card-color:<?= $cat['color'] ?>;">
            <?php foreach ($card['list'] as $li): ?>
            <li><?= htmlspecialchars($li['list_item']) ?></li>
            <?php endforeach; ?>
          </ul>

          <!-- ── View: action buttons ── -->
          <div class="md-card-actions md-view-actions">
            <button class="md-btn md-btn-edit"   data-action="toggle-edit">✏️ Edit</button>
            <button class="md-btn md-btn-del"     data-action="delete-card">🗑</button>
          </div>

          <!-- ── Edit panel ── -->
          <div class="md-edit-panel">
            <div class="md-field">
              <label>Category</label>
              <select class="md-cat">
                <option value="leadership"    <?= $catKey==='leadership'    ? 'selected':'' ?>>Leadership</option>
                <option value="pgcp"          <?= $catKey==='pgcp'          ? 'selected':'' ?>>PGCP</option>
                <option value="certification" <?= $catKey==='certification' ? 'selected':'' ?>>Certification</option>
                <option value="college"       <?= $catKey==='college'       ? 'selected':'' ?>>College</option>
              </select>
            </div>
            <div class="md-field">
              <label>Title</label>
              <input type="text" class="md-title" value="<?= htmlspecialchars($card['title']) ?>" placeholder="Course title">
            </div>
            <div class="md-field-row">
              <div class="md-field">
                <label>Duration</label>
                <input type="text" class="md-duration" value="<?= htmlspecialchars($card['duration']) ?>" placeholder="e.g. 12 Months">
              </div>
              <div class="md-field">
                <label>Mode</label>
                <input type="text" class="md-mode" value="<?= htmlspecialchars($card['mode']) ?>" placeholder="e.g. Classroom">
              </div>
            </div>
            <div class="md-field">
              <label>URL (onClick)</label>
              <input type="text" class="md-onclick" value="<?= htmlspecialchars($card['on_click']) ?>" placeholder="/course-slug">
            </div>
            <div class="md-field">
              <label>List Items</label>
              <div class="md-list-rows">
                <?php foreach ($card['list'] as $lii => $li): ?>
                <div class="md-list-row" data-list-id="<?= (int)$li['id'] ?>">
                  <input type="text" class="md-list-input" value="<?= htmlspecialchars($li['list_item']) ?>" placeholder="Bullet point">
                  <button class="md-btn-del-sm" data-action="del-list-row">✕</button>
                </div>
                <?php endforeach; ?>
              </div>
              <button class="md-btn-plus" data-action="add-list-row" style="margin-top:6px;">＋ Add bullet</button>
            </div>
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button class="md-btn md-btn-save"   data-action="save-card">💾 Save</button>
              <button class="md-btn md-btn-cancel" data-action="toggle-edit">Cancel</button>
            </div>
          </div>

        </div><!-- /.md-card -->
        <?php endforeach; ?>

        <!-- Add-new placeholder -->
        <div class="md-card-new"
             data-action="add-card"
             data-category="<?= $catKey ?>"
             data-color="<?= $cat['color'] ?>"
             data-badge="<?= $cat['badge'] ?>"
             data-badge-text="<?= $cat['badgeText'] ?>">
          <span>＋</span>
          Add <?= $cat['label'] ?> Course
        </div>

      </div><!-- /.md-grid -->
    </div><!-- /.md-section -->

    <?php endforeach; ?>

  </div><!-- /.md-wrap -->

  <!-- Toast -->
  <div id="md-toast"></div>

</main>

<script>
(function () {
    // ── Toast ──────────────────────────────────────────────────────────────────
    function toast(msg) {
        var t = document.getElementById('md-toast');
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    // ── Category config (mirrors PHP) ─────────────────────────────────────────
    var CAT_META = {
        leadership:    { color: '#7c3aed', badge: '#ede9fe', badgeText: '#5b21b6' },
        pgcp:          { color: '#2563eb', badge: '#dbeafe', badgeText: '#1e40af' },
        certification: { color: '#059669', badge: '#d1fae5', badgeText: '#065f46' },
        college:       { color: '#d97706', badge: '#fef3c7', badgeText: '#92400e' },
    };

    // ── Update a card's view mode from current edit inputs ────────────────────
    function refreshCardView(card) {
        var cat      = card.querySelector('.md-cat').value;
        var title    = card.querySelector('.md-title').value.trim();
        var duration = card.querySelector('.md-duration').value.trim();
        var mode     = card.querySelector('.md-mode').value.trim();
        var url      = card.querySelector('.md-onclick').value.trim();
        var meta     = CAT_META[cat] || CAT_META['leadership'];

        card.style.setProperty('--card-color', meta.color);
        card.querySelector('.md-card-title').textContent = title;

        var badges = card.querySelector('.md-card-badges');
        badges.innerHTML =
            '<span class="md-badge" style="background:' + meta.badge + ';color:' + meta.badgeText + ';">⏱ ' + duration + '</span>' +
            '<span class="md-badge" style="background:#f1f5f9;color:#475569;">📍 ' + mode + '</span>';

        var linkEl = card.querySelector('.md-card-link');
        linkEl.innerHTML = '<a href="' + url + '" target="_blank">' + url + '</a>';

        var ul = card.querySelector('.md-card-list');
        ul.style.setProperty('--card-color', meta.color);
        ul.innerHTML = '';
        card.querySelectorAll('.md-list-row .md-list-input').forEach(function (inp) {
            var text = inp.value.trim();
            if (!text) return;
            var li = document.createElement('li');
            li.textContent = text;
            ul.appendChild(li);
        });
    }

    // ── Build a blank new card and inject into grid ───────────────────────────
    function injectNewCard(grid, cat, color, badge, badgeText) {
        var meta = CAT_META[cat] || { color: color, badge: badge, badgeText: badgeText };
        var card = document.createElement('div');
        card.className = 'md-card md-editing';
        card.dataset.itemId  = '0';
        card.dataset.category = cat;
        card.dataset.sort    = '99';
        card.style.setProperty('--card-color', meta.color);

        card.innerHTML =
            '<div class="md-card-top">' +
              '<div class="md-card-title">New Course</div>' +
              '<div class="md-card-badges">' +
                '<span class="md-badge" style="background:' + meta.badge + ';color:' + meta.badgeText + ';">⏱ —</span>' +
                '<span class="md-badge" style="background:#f1f5f9;color:#475569;">📍 —</span>' +
              '</div>' +
              '<span class="md-card-link"></span>' +
            '</div>' +
            '<ul class="md-card-list" style="--card-color:' + meta.color + ';"></ul>' +
            '<div class="md-card-actions md-view-actions">' +
              '<button class="md-btn md-btn-edit" data-action="toggle-edit">✏️ Edit</button>' +
              '<button class="md-btn md-btn-del"  data-action="delete-card">🗑</button>' +
            '</div>' +
            '<div class="md-edit-panel open">' +
              '<div class="md-field"><label>Category</label>' +
                '<select class="md-cat">' +
                  '<option value="leadership"'    + (cat==='leadership'    ? ' selected':'') + '>Leadership</option>' +
                  '<option value="pgcp"'          + (cat==='pgcp'          ? ' selected':'') + '>PGCP</option>' +
                  '<option value="certification"' + (cat==='certification' ? ' selected':'') + '>Certification</option>' +
                  '<option value="college"'       + (cat==='college'       ? ' selected':'') + '>College</option>' +
                '</select>' +
              '</div>' +
              '<div class="md-field"><label>Title</label>' +
                '<input type="text" class="md-title" placeholder="Course title">' +
              '</div>' +
              '<div class="md-field-row">' +
                '<div class="md-field"><label>Duration</label><input type="text" class="md-duration" placeholder="e.g. 12 Months"></div>' +
                '<div class="md-field"><label>Mode</label><input type="text" class="md-mode" placeholder="e.g. Classroom"></div>' +
              '</div>' +
              '<div class="md-field"><label>URL (onClick)</label><input type="text" class="md-onclick" placeholder="/course-slug"></div>' +
              '<div class="md-field"><label>List Items</label>' +
                '<div class="md-list-rows"></div>' +
                '<button class="md-btn-plus" data-action="add-list-row" style="margin-top:6px;">＋ Add bullet</button>' +
              '</div>' +
              '<div style="display:flex;gap:8px;margin-top:4px;">' +
                '<button class="md-btn md-btn-save" data-action="save-card">💾 Save</button>' +
                '<button class="md-btn md-btn-cancel" data-action="delete-card">✕ Discard</button>' +
              '</div>' +
            '</div>';

        // Insert before the "Add new" placeholder (last child)
        var addPlaceholder = grid.querySelector('.md-card-new');
        grid.insertBefore(card, addPlaceholder);
        card.querySelector('.md-title').focus();
    }

    // ── Update count badge ─────────────────────────────────────────────────────
    function updateCount(cat) {
        var grid  = document.getElementById('md-grid-' + cat);
        var count = grid.querySelectorAll('.md-card[data-item-id]').length;
        var badge = document.getElementById('md-count-' + cat);
        if (badge) badge.textContent = count + ' course' + (count !== 1 ? 's' : '');
    }

    // ── Global click handler ──────────────────────────────────────────────────
    document.addEventListener('click', function (e) {

        // ── Toggle edit panel ─────────────────────────────────────────────────
        var toggleBtn = e.target.closest('[data-action="toggle-edit"]');
        if (toggleBtn) {
            var card  = toggleBtn.closest('.md-card');
            var panel = card.querySelector('.md-edit-panel');
            var isOpen = panel.classList.contains('open');
            panel.classList.toggle('open', !isOpen);
            card.classList.toggle('md-editing', !isOpen);
            return;
        }

        // ── Add new card ──────────────────────────────────────────────────────
        var addBtn = e.target.closest('[data-action="add-card"]');
        if (addBtn) {
            var cat   = addBtn.dataset.category;
            var grid  = document.getElementById('md-grid-' + cat);
            var color = addBtn.dataset.color     || '#6366f1';
            var badge = addBtn.dataset.badge     || '#ede9fe';
            var bText = addBtn.dataset.badgeText || '#5b21b6';
            injectNewCard(grid, cat, color, badge, bText);
            return;
        }

        // ── Add list bullet row ───────────────────────────────────────────────
        var addRow = e.target.closest('[data-action="add-list-row"]');
        if (addRow) {
            var listRows = addRow.closest('.md-edit-panel').querySelector('.md-list-rows');
            var row = document.createElement('div');
            row.className = 'md-list-row';
            row.dataset.listId = '0';
            row.innerHTML =
                '<input type="text" class="md-list-input" placeholder="Bullet point">' +
                '<button class="md-btn-del-sm" data-action="del-list-row">✕</button>';
            listRows.appendChild(row);
            row.querySelector('input').focus();
            return;
        }

        // ── Delete list bullet row ────────────────────────────────────────────
        var delRow = e.target.closest('[data-action="del-list-row"]');
        if (delRow) {
            delRow.closest('.md-list-row').remove();
            return;
        }

        // ── Save card ─────────────────────────────────────────────────────────
        var saveBtn = e.target.closest('[data-action="save-card"]');
        if (saveBtn) {
            var card   = saveBtn.closest('.md-card');
            var itemId = card.dataset.itemId || '0';
            var cat    = card.querySelector('.md-cat').value;
            var title  = card.querySelector('.md-title').value.trim();
            var dur    = card.querySelector('.md-duration').value.trim();
            var mode   = card.querySelector('.md-mode').value.trim();
            var url    = card.querySelector('.md-onclick').value.trim();

            if (!title) { toast('⚠️ Title is required.'); return; }

            var listItems = [];
            card.querySelectorAll('.md-list-row').forEach(function (row, idx) {
                var val = row.querySelector('.md-list-input').value.trim();
                if (val) listItems.push({ list_item: val, sort_order: idx + 1 });
            });

            var fd = new FormData();
            fd.append('item_id',    itemId);
            fd.append('category',   cat);
            fd.append('title',      title);
            fd.append('duration',   dur);
            fd.append('mode',       mode);
            fd.append('on_click',   url);
            fd.append('sort_order', card.dataset.sort || 1);
            fd.append('list',       JSON.stringify(listItems));

            card.classList.add('md-saving');
            fetch('/da360-admin/MenuDetails_api.php?action=save_menu_item', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    card.classList.remove('md-saving');
                    if (d.success) {
                        card.dataset.itemId  = d.item_id;
                        card.dataset.category = cat;
                        refreshCardView(card);
                        card.querySelector('.md-edit-panel').classList.remove('open');
                        card.classList.remove('md-editing');
                        card.classList.add('md-saved');
                        setTimeout(function () { card.classList.remove('md-saved'); }, 2200);
                        toast('✅ Saved!');
                        updateCount(cat);
                    } else {
                        toast('❌ ' + (d.message || 'Error saving'));
                    }
                })
                .catch(function () {
                    card.classList.remove('md-saving');
                    toast('❌ Network error');
                });
            return;
        }

        // ── Delete card ───────────────────────────────────────────────────────
        var delBtn = e.target.closest('[data-action="delete-card"]');
        if (delBtn) {
            var card   = delBtn.closest('.md-card');
            var itemId = card.dataset.itemId || '0';
            var cat    = card.dataset.category;

            if (itemId === '0') {
                // Unsaved new card — just remove from DOM
                card.remove();
                updateCount(cat);
                return;
            }

            if (!confirm('Delete this course card? This cannot be undone.')) return;

            var fd = new FormData();
            fd.append('item_id', itemId);
            fetch('/da360-admin/MenuDetails_api.php?action=delete_menu_item', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        card.remove();
                        toast('🗑️ Card deleted.');
                        updateCount(cat);
                    } else {
                        toast('❌ ' + (d.message || 'Delete failed'));
                    }
                })
                .catch(function () { toast('❌ Network error'); });
            return;
        }

    });

})();
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
