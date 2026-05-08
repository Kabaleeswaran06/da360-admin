<?php
// ── CORS (mirrors api.php) ─────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost',
    // 'https://yourproductiondomain.com',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

// ── Auth — session OR Bearer/api_key (same as api.php) ────────────────────
define('VALID_API_KEYS', ['da360-secret-key-2024']);

function isAuthorized(): bool {
    if (isLoggedIn()) return true;
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m))
        return in_array($m[1], VALID_API_KEYS, true);
    $q = $_GET['api_key'] ?? '';
    return $q !== '' && in_array($q, VALID_API_KEYS, true);
}

if (!isAuthorized()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

$categories = array('Program', 'Delivery', 'Placement', 'Certification', 'Fee');

try {
    $db = getDB();

    //JSON
    // ── GET FAQ JSON (for Next.js) ─────────────────────────────────────────────
// GET /faq_api.php?action=get_faq_json&course_id=1&api_key=XXX
//
// Response shape:
// {
//   "success": true,
//   "course_label": "...",
//   "locations": {
//     "global":    { "Program": [{question,answer}, ...], "Delivery": [...], ... },
//     "bangalore": { ... },
//     ...
//   }
// }
    if ($action === 'get_faq_json') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']);
            exit;
        }

        // Course label
        $stmt = $db->prepare("SELECT label FROM courses WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$courseId]);
        $courseLabel = $stmt->fetchColumn();
        if (!$courseLabel) {
            echo json_encode(['success' => false, 'message' => 'Course not found']);
            exit;
        }

        // All active locations
        $stmt = $db->prepare("
            SELECT id, label, slug
            FROM locations
            WHERE is_active = 1
            ORDER BY sort_order, label
        ");
        $stmt->execute();
        $locationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // All active FAQs for this course (all locations at once)
        $stmt = $db->prepare("
            SELECT location_id, category, sort_order, question, answer
            FROM course_faqs
            WHERE course_id = ? AND is_active = 1
            ORDER BY location_id, category, sort_order
        ");
        $stmt->execute([$courseId]);
        $faqRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Index FAQs by location_id → category → sort_order
        $indexed = [];
        foreach ($faqRows as $row) {
            $indexed[$row['location_id']][$row['category']][$row['sort_order']] = [
                'question' => $row['question'],
                'answer'   => $row['answer'],
            ];
        }

        $validCategories = ['Program', 'Delivery', 'Placement', 'Certification', 'Fee'];

        // Build final structure keyed by location slug
        $locations = [];
        foreach ($locationRows as $loc) {
            $slug = !empty($loc['slug'])
                ? $loc['slug']
                : strtolower(preg_replace('/\s+/', '_', trim($loc['label'])));

            $lid = (int)$loc['id'];
            $categoryData = [];

            foreach ($validCategories as $cat) {
                $items = [];
                $catFaqs = $indexed[$lid][$cat] ?? [];
                ksort($catFaqs); // ensure sort_order sequence
                foreach ($catFaqs as $faq) {
                    if ($faq['question'] !== '' || $faq['answer'] !== '') {
                        $items[] = [
                            'question' => $faq['question'],
                            'answer'   => $faq['answer'],
                        ];
                    }
                }
                if (!empty($items)) {
                    $categoryData[$cat] = $items;
                }
            }

            $locations[$slug] = $categoryData;
        }

        echo json_encode([
            'success'      => true,
            'course_label' => $courseLabel,
            'locations'    => $locations,
        ]);
        exit;
    }
    // ── GET FAQ HTML ──────────────────────────────────────────────────────────
    if ($action === 'get_faq_html') {
        $courseId   = (int)($_GET['course_id']   ?? 0);
        $locationId = (int)($_GET['location_id'] ?? 0);

        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id or location_id']);
            exit;
        }

        $stmt = $db->prepare("SELECT label FROM courses WHERE id = ? LIMIT 1");
        $stmt->execute([$courseId]);
        $courseLabel = $stmt->fetchColumn() ?: '';

        $stmt = $db->prepare("SELECT label FROM locations WHERE id = ? LIMIT 1");
        $stmt->execute([$locationId]);
        $locationLabel = $stmt->fetchColumn() ?: '';

        $stmt = $db->prepare("
            SELECT * FROM course_faqs
            WHERE course_id = ? AND location_id = ?
            ORDER BY category, sort_order
        ");
        $stmt->execute([$courseId, $locationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $faqData = array();
        $lastUpdated = null;
        foreach ($rows as $row) {
            $faqData[$row['category']][$row['sort_order']] = $row;
            if (!empty($row['updated_by'])) {
                $lastUpdated = $row;  // last row will be most recent
            }
        }
        $lastRow   = !empty($rows) ? end($rows) : null;
        $updatedBy = $lastRow['updated_by'] ?? null;
        $updatedAt = $lastRow['updated_at'] ?? null;

        $totalFaqs  = count($categories) * 10;
        $filledFaqs = count($rows);

        $catAccents = array(
            'Program'       => '#0ea5e9',
            'Delivery'      => '#8b5cf6',
            'Placement'     => '#22c55e',
            'Certification' => '#f59e0b',
            'Fee'           => '#f43f5e',
        );

        $catIcons = array(
            'Program'       => '📚',
            'Delivery'      => '🚚',
            'Placement'     => '🎯',
            'Certification' => '🏅',
            'Fee'           => '💳',
        );

        ob_start(); ?>

        <style>
        /* ── Scoped to .fmr (faq-manager-root) ─────────────────────────────────── */
        .fmr *, .fmr *::before, .fmr *::after { box-sizing: border-box; }
        .fmr { font-family: 'Segoe UI', system-ui, sans-serif; color: #e2e8f0; }

        /* Stats bar */
        .fmr .stats-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:24px; }
        .fmr .stat-chip {
            background:#fff; border:1px solid #334155; border-radius:8px;
            padding:8px 16px; font-size:13px; color:#000;
            display:flex; align-items:center; gap:6px;
        }
        .fmr .stat-chip strong { color:#f1f5f9; font-size:15px; }

        /* ── Category toggle panels ─────────────────────────────────────────────── */
        .fmr .faq-categories { display:flex; flex-direction:column; gap:12px; }

        .fmr .cat-panel {
            border-radius:14px;
            border:1.5px solid #2d3f55;
            overflow:hidden;
            transition: box-shadow .2s;
        }
        .fmr .cat-panel.open {
            box-shadow: 0 6px 28px rgba(0,0,0,.35);
            border-color: color-mix(in srgb, var(--cat-accent, #64748b) 40%, #2d3f55);
        }

        /* Category header — the clickable toggle */
        .fmr .cat-header {
            display:flex;
            align-items:center;
            gap:14px;
            padding:16px 20px;
            cursor:pointer;
            user-select:none;
            background:#1e293b;
            transition: background .15s;
            border-left: 4px solid var(--cat-accent, #64748b);
        }
        .fmr .cat-header:hover { background:#243047; }
        .fmr .cat-panel.open .cat-header {
            background: color-mix(in srgb, var(--cat-accent, #64748b) 12%, #1e293b);
        }

        .fmr .cat-icon { font-size:20px; line-height:1; flex-shrink:0; }

        .fmr .cat-title {
            font-size:15px;
            font-weight:700;
            color:#f1f5f9;
            flex:1;
            letter-spacing:.2px;
        }

        .fmr .cat-badge {
            font-size:11px;
            font-weight:700;
            padding:3px 10px;
            border-radius:20px;
            background: color-mix(in srgb, var(--cat-accent, #64748b) 18%, #0f172a);
            color: var(--cat-accent, #94a3b8);
            border: 1px solid color-mix(in srgb, var(--cat-accent, #64748b) 35%, transparent);
            letter-spacing:.3px;
        }

        .fmr .cat-chevron {
            width:20px; height:20px;
            color:#475569;
            transition: transform .25s ease, color .2s;
            flex-shrink:0;
        }
        .fmr .cat-panel.open .cat-chevron {
            transform: rotate(180deg);
            color: var(--cat-accent, #94a3b8);
        }

        /* Category body — collapsible via max-height */
        .fmr .cat-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height .38s cubic-bezier(.4,0,.2,1);
            background: #fff;
        }
        .fmr .cat-panel.open .cat-body {
            max-height: 4000px;
        }

        /* ── FAQ table inside each panel ────────────────────────────────────────── */
        .fmr .faq-table-wrap { overflow-x:auto; padding:16px 16px 0; }

        .fmr .faq-table {
            width:100%;
            border-collapse:collapse;
            font-size:13px;
        }
        .fmr .faq-table thead th {
            background:#fff;
            color:#64748b;
            font-size:11px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.6px;
            padding:10px 12px;
            text-align:left;
            border-bottom:1px solid #1e293b;
        }

        .fmr .faq-row { transition: background .15s; }
        .fmr .faq-row:hover { background:rgba(255,255,255,.025); }
        .fmr .faq-row td {
            padding:10px 12px;
            border-bottom:1px solid #1e293b;
            vertical-align:top;
        }
        .fmr .faq-row:last-child td { border-bottom:none; }
        .fmr .faq-row.has-data { border-left:3px solid var(--cat-accent, #64748b); }
        .fmr .faq-row.saving   { opacity:.55; pointer-events:none; }
        .fmr .faq-row.saved    { background: rgba(34,197,94,.07) !important; }
        .fmr .faq-row.errored  { background: rgba(239,68,68,.07) !important; }

        .fmr .sort-num {
            color: var(--cat-accent, #64748b);
            font-weight:700; font-size:13px;
            width:32px; text-align:center; padding-top:14px;
        }

        .fmr .faq-textarea {
            width:100%;
            background:#fff;
            border:1.5px solid #f97316;
            border-radius:8px;
            color:#000;
            font-size:13px;
            line-height:1.6;
            padding:8px 12px;
            resize:vertical;
            font-family:inherit;
            transition: border-color .2s, box-shadow .2s;
        }
        .fmr .faq-textarea:focus {
            outline:none;
            border-color: var(--cat-accent, #64748b);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--cat-accent, #64748b) 18%, transparent);
        }
        .fmr .faq-textarea::placeholder { color:#475569; }

        /* Toggle switch */
        .fmr .toggle-switch { position:relative; display:inline-block; width:40px; height:22px; }
        .fmr .toggle-switch input { opacity:0; width:0; height:0; }
        .fmr .toggle-slider {
            position:absolute; inset:0;
            background:#334155; border-radius:34px; cursor:pointer; transition:background .2s;
        }
        .fmr .toggle-slider::before {
            content:''; position:absolute;
            width:16px; height:16px; left:3px; bottom:3px;
            background:#64748b; border-radius:50%;
            transition: transform .2s, background .2s;
        }
        .fmr .toggle-switch input:checked + .toggle-slider { background:rgba(34,197,94,.2); }
        .fmr .toggle-switch input:checked + .toggle-slider::before {
            transform:translateX(18px); background:#22c55e;
        }

        /* Action buttons */
        .fmr .td-actions { text-align:center; white-space:nowrap; }

        .fmr .btn-save-row,
        .fmr .btn-delete-row {
            display:inline-flex; align-items:center; gap:4px;
            padding:5px 11px; border-radius:7px; border:none;
            font-size:12px; font-weight:600; cursor:pointer;
            transition: all .18s;
            margin:2px 0;
        }
        .fmr .btn-save-row {
            background: color-mix(in srgb, var(--cat-accent, #0ea5e9) 18%, #1e293b);
            color: var(--cat-accent, #0ea5e9);
            border: 1px solid color-mix(in srgb, var(--cat-accent, #0ea5e9) 30%, transparent);
        }
        .fmr .btn-save-row:hover {
            background: var(--cat-accent, #0ea5e9); color:#fff;
        }
        .fmr .btn-delete-row {
            background:rgba(239,68,68,.1); color:#f87171;
            border:1px solid rgba(239,68,68,.22);
        }
        .fmr .btn-delete-row:hover { background:#ef4444; color:#fff; }

        /* Bulk save bar */
        .fmr .faq-bulk-bar {
            display:flex; align-items:center; gap:14px;
            padding:14px 20px;
            background:#0f172a;
            border-top:1px solid #1e293b;
        }
        .fmr .btn-bulk-save {
            display:inline-flex; align-items:center; gap:7px;
            padding:8px 20px; border-radius:8px; border:none;
            font-size:13px; font-weight:700; cursor:pointer;
            background: var(--cat-accent, #0ea5e9);
            color:#fff;
            transition: opacity .2s, transform .1s;
        }
        .fmr .btn-bulk-save:hover { opacity:.85; transform:translateY(-1px); }
        .fmr .save-hint { font-size:12px; color:#475569; }
        </style>

<div class="fmr">

  <div class="result-header animate-fadeup">
    <div class="result-title"></div>
    <div class="result-meta">
      <span class="meta-pill accent"><?= htmlspecialchars($courseLabel) ?></span>
      <span class="meta-pill"><?= htmlspecialchars($locationLabel) ?></span>
    </div>
  </div>

  <div class="stats-bar">
    <div class="stat-chip"><b><?= $totalFaqs ?></b>&nbsp;Total Slots</div>
    <div class="stat-chip"><b><?= $filledFaqs ?></b>&nbsp;Filled</div>
    <div class="stat-chip"><b><?= $totalFaqs - $filledFaqs ?></b>&nbsp;Empty</div>
    <div class="stat-chip"><b><?= count($categories) ?></b>&nbsp;Categories</div>

    <?php if ($lastUpdated && !empty($lastUpdated['updated_by'])): ?>
    <div class="stat-chip">
        ✏️ Last updated by <b>&nbsp;<?= htmlspecialchars($lastUpdated['updated_by']) ?></b>
        &nbsp;on&nbsp;<b><?= htmlspecialchars($lastUpdated['updated_at']) ?></b>
    </div>
    <?php endif; ?>
  </div>

  <div class="faq-categories">

    <?php foreach ($categories as $i => $cat):
        $accent = $catAccents[$cat] ?? '#64748b';
        $icon   = $catIcons[$cat]   ?? '📁';
        $filled = isset($faqData[$cat]) ? count($faqData[$cat]) : 0;
        $isOpen = ($i === 0);
    ?>
    <div class="cat-panel <?= $isOpen ? 'open' : '' ?>"
         id="cat-panel-<?= $cat ?>"
         style="--cat-accent:<?= $accent ?>">

      <!-- ── Toggle header ── -->
      <div class="cat-header" onclick="toggleCatPanel('<?= $cat ?>')">
        <span class="cat-icon"><?= $icon ?></span>
        <span class="cat-title"><?= $cat ?></span>
        <span class="cat-badge" id="badge-<?= $cat ?>"><?= $filled ?>/10 filled</span>
        <svg class="cat-chevron" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
      </div>

      <!-- ── Collapsible body ── -->
      <div class="cat-body">

        <div class="faq-table-wrap">
          <table class="faq-table">
            <thead>
              <tr>
                <th style="width:36px">#</th>
                <th>Question</th>
                <th>Answer</th>
                <th style="width:70px;text-align:center">Active</th>
                <th style="width:90px;text-align:center">Actions</th>
              </tr>
            </thead>
            <tbody id="tbody-<?= $cat ?>">

            <?php for ($n = 1; $n <= 10; $n++):
                $faq      = $faqData[$cat][$n] ?? null;
                $faqId    = $faq ? (int)$faq['id'] : 0;
                $question = $faq ? htmlspecialchars($faq['question']) : '';
                $answer   = $faq ? htmlspecialchars($faq['answer'])   : '';
                $active   = $faq ? (int)$faq['is_active'] : 1;
                $hasData  = $faq !== null;
            ?>
              <tr class="faq-row <?= $hasData ? 'has-data' : 'empty-row' ?>"
                  style="--cat-accent:<?= $accent ?>"
                  data-id="<?= $faqId ?>"
                  data-sort="<?= $n ?>"
                  data-cat="<?= $cat ?>"
                  data-course="<?= $courseId ?>"
                  data-location="<?= $locationId ?>">

                <td class="sort-num"><?= $n ?></td>

                <td>
                  <textarea class="faq-textarea faq-question" rows="3"
                    placeholder="Enter question <?= $n ?>…"><?= $question ?></textarea>
                </td>

                <td>
                  <textarea class="faq-textarea faq-answer" rows="3"
                    placeholder="Enter answer <?= $n ?>…"><?= $answer ?></textarea>
                </td>

                <td style="text-align:center;padding-top:14px;">
                  <label class="toggle-switch">
                    <input type="checkbox" class="faq-active" <?= $active ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                  </label>
                </td>

                <td class="td-actions">
                  <button class="btn-save-row" data-action="save-row" title="Save">💾 Save</button>
                  <?php if ($hasData): ?>
                  <button class="btn-delete-row" data-action="delete-row" title="Delete">🗑️ Del</button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endfor; ?>

            </tbody>
          </table>
        </div>

        <div class="faq-bulk-bar" style="--cat-accent:<?= $accent ?>">
          <button class="btn-bulk-save" data-action="bulk-save" data-cat="<?= $cat ?>">
            💾 Save All in <?= $cat ?>
          </button>
          <span class="save-hint">Saves all 10 rows in this section at once.</span>
        </div>

      </div><!-- /.cat-body -->
    </div><!-- /.cat-panel -->
    <?php endforeach; ?>

  </div><!-- /.faq-categories -->
</div><!-- /.fmr -->

        <?php
        $html = ob_get_clean();

        // JS is returned separately so the caller can execute it properly.
        // Scripts injected via innerHTML are silently ignored by browsers.
        $js = <<<'JSCODE'
(function () {
    // Guard: only register once even if FAQ panel is reloaded
    if (window._fmrListenerAttached) return;
    window._fmrListenerAttached = true;

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

        fetch('/da360-admin/faq_api.php?action=save_faq', { method:'POST', body:fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                row.classList.remove('saving');
                if (data.success) {
                    row.classList.add('saved', 'has-data');
                    row.classList.remove('empty-row');
                    if (data.id && !row.querySelector('.btn-delete-row')) {
                        row.dataset.id = data.id;
                        var delBtn = document.createElement('button');
                        delBtn.className = 'btn-delete-row';
                        delBtn.title = 'Delete';
                        delBtn.innerHTML = '🗑️ Del';
                        delBtn.dataset.action = 'delete-row';
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

        fetch('/da360-admin/faq_api.php?action=delete_faq', { method:'POST', body:fd })
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
            saveFaqRow(row.querySelector('[data-action="save-row"]'));
            saved++;
        });
        setTimeout(function() {
            showToast('✅ Saving ' + saved + ' FAQ(s) in ' + cat +
                      (skipped ? ' (' + skipped + ' empty skipped)' : ''));
        }, 200);
    }

    function updateCatBadge(cat) {
        var tbody = document.getElementById('tbody-' + cat);
        var badge = document.getElementById('badge-' + cat);
        if (!tbody || !badge) return;
        var filled = tbody.querySelectorAll('.faq-row.has-data').length;
        badge.textContent = filled + '/10 filled';
    }

    // Single delegated listener — works for all current & future injected buttons
    document.addEventListener('click', function (e) {
        var header = e.target.closest('[data-toggle-cat]');
        if (header) {
            var panel = document.getElementById('cat-panel-' + header.dataset.toggleCat);
            if (panel) panel.classList.toggle('open');
            return;
        }
        var saveBtn = e.target.closest('[data-action="save-row"]');
        if (saveBtn) { saveFaqRow(saveBtn); return; }

        var delBtn = e.target.closest('[data-action="delete-row"]');
        if (delBtn) { deleteFaqRow(delBtn); return; }

        var bulkBtn = e.target.closest('[data-action="bulk-save"]');
        if (bulkBtn) { saveAllInCategory(bulkBtn.dataset.cat); return; }
    }, false);
})();
JSCODE;

        echo json_encode(['success' => true, 'html' => $html, 'js' => $js]);
        exit;
    }

    // ── SAVE ONE FAQ ROW ──────────────────────────────────────────────────────
    if ($action === 'save_faq' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);
        $category   = trim($_POST['category']     ?? '');
        $sortOrder  = (int)($_POST['sort_order']  ?? 0);
        $question   = trim($_POST['question']     ?? '');
        $answer     = trim($_POST['answer']        ?? '');
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        // ✅ Capture who is saving
        $user      = $_SESSION['da360_user'] ?? [];
        $updatedBy = $_SESSION['da360_user']['name'] 
          ?? $_SESSION['da360_user']['username'] 
          ?? 'unknown';

        if (!$courseId || !$locationId || !in_array($category, $categories) || $sortOrder < 1 || $sortOrder > 10) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        if ($question === '' && $answer === '') {
            echo json_encode(['success' => false, 'message' => 'Question and answer cannot both be empty']);
            exit;
        }

        $sql = "
            INSERT INTO course_faqs
                (course_id, location_id, category, sort_order, question, answer, is_active, created_at, updated_at, updated_by)
            VALUES
                (:course_id, :location_id, :category, :sort_order, :question, :answer, :is_active, NOW(), NOW(), :updated_by)
            ON DUPLICATE KEY UPDATE
                question   = VALUES(question),
                answer     = VALUES(answer),
                is_active  = VALUES(is_active),
                updated_at = NOW(),
                updated_by = VALUES(updated_by)
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(array(
            'course_id'   => $courseId,
            'location_id' => $locationId,
            'category'    => $category,
            'sort_order'  => $sortOrder,
            'question'    => $question,
            'answer'      => $answer,
            'is_active'   => $isActive,
            'updated_by'  => $updatedBy,  
        ));

        $newId    = $db->lastInsertId();
        $affected = $stmt->rowCount();

        echo json_encode(array(
            'success'    => true,
            'message'    => 'FAQ saved',
            'id'         => $newId ?: null,
            'affected'   => $affected,
            'updated_by' => $updatedBy,   
        ));
        exit;
    }

    // ── DELETE ONE FAQ ROW ────────────────────────────────────────────────────
    if ($action === 'delete_faq' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id         = (int)($_POST['id']          ?? 0);
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);

        if (!$id || !$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        $stmt = $db->prepare("
            DELETE FROM course_faqs
            WHERE id = ? AND course_id = ? AND location_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $courseId, $locationId]);

        echo json_encode(array(
            'success' => $stmt->rowCount() > 0,
            'message' => $stmt->rowCount() > 0 ? 'FAQ deleted' : 'Row not found',
        ));
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}