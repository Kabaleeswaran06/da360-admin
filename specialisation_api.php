<?php
// ── CORS ────────────────────────────────────────────────────────────────────
$allowedOrigins = [
    'https://www.digitalacademy360.com',
    'https://digitalacademy360.com',
    'https://dev2.digitalacademy360.com',
    // 'http://localhost:3000',
    // 'http://localhost',
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

// ── Auth ────────────────────────────────────────────────────────────────────
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

try {
    $db = getDB();

    // ══════════════════════════════════════════════════════════════════════════
    // GET LOCATIONS — for the admin UI location dropdown
    // GET /specialisation_api.php?action=get_locations&course_id=1
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_locations') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT id, label
            FROM locations
            WHERE is_active = 1
            ORDER BY sort_order, label
        ");
        $stmt->execute();
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'locations' => $locations]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET SPECIALISATION JSON  — for Next.js frontend
    // GET /specialisation_api.php?action=get_specialisation_json&course_id=1&api_key=XXX
    //
    // Heading + description  → course + location specific
    // Modules                → course-wide (shared across all locations)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_specialisation_json') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']);
            exit;
        }

        // ── Modules are course-wide — fetch once ──────────────────────────
        $stmt = $db->prepare("
            SELECT id, number, title
            FROM course_specialisation_modules
            WHERE course_id = ?
            ORDER BY sort_order, number
        ");
        $stmt->execute([$courseId]);
        $moduleRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $modules = [];
        $badgeLabelMap = [
            'live'       => 'Live Sessions',
            'assignment' => 'Assignments',
            'casestudy'  => 'Case Study',
            'assesment'  => 'Assessments',
        ];

        foreach ($moduleRows as $mod) {
            $mid = (int)$mod['id'];

            $stmt2 = $db->prepare("
                SELECT type, count
                FROM course_specialisation_module_badges
                WHERE module_id = ?
                ORDER BY FIELD(type,'live','assignment','casestudy','assesment')
            ");
            $stmt2->execute([$mid]);
            $badgeRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $badges = [];
            foreach ($badgeRows as $b) {
                if ((int)$b['count'] > 0) {
                    $badges[] = [
                        'icon'  => $b['type'],
                        'label' => $b['count'] . ' ' . ($badgeLabelMap[$b['type']] ?? $b['type']),
                    ];
                }
            }

            $stmt2 = $db->prepare("
                SELECT topic
                FROM course_specialisation_module_topics
                WHERE module_id = ?
                ORDER BY sort_order
            ");
            $stmt2->execute([$mid]);
            $topics = $stmt2->fetchAll(PDO::FETCH_COLUMN);
            $topics = array_values(array_filter($topics, fn($t) => trim($t) !== ''));

            $modules[] = [
                'number' => (int)$mod['number'],
                'title'  => $mod['title'],
                'badges' => $badges,
                'topics' => $topics,
            ];
        }

        // ── All active locations ───────────────────────────────────────────
        $stmt = $db->prepare("
            SELECT id, label, slug
            FROM locations
            WHERE is_active = 1
            ORDER BY sort_order, label
        ");
        $stmt->execute();
        $locationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $locations = [];

        foreach ($locationRows as $loc) {
            $lid  = (int)$loc['id'];
            $slug = !empty($loc['slug'])
                ? $loc['slug']
                : strtolower(preg_replace('/\s+/', '_', trim($loc['label'])));

            // ── Heading + description (location-specific) ─────────────────
            $stmt = $db->prepare("
                SELECT heading, description
                FROM course_specialisation
                WHERE course_id = ? AND location_id = ?
                LIMIT 1
            ");
            $stmt->execute([$courseId, $lid]);
            $specRow = $stmt->fetch(PDO::FETCH_ASSOC);

            // Modules are shared — same for every location
            $locations[$slug] = [
                'specialisationHeading'     => $specRow['heading']     ?? '',
                'specialisationDescription' => $specRow['description'] ?? '',
                'specialisationmodulesData' => $modules,
            ];
        }

        echo json_encode(['success' => true, 'locations' => $locations]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET SPECIALISATION HTML  — for the CMS admin editor
    // GET /specialisation_api.php?action=get_specialisation_html&course_id=1&location_id=2
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_specialisation_html') {
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

        // ── Heading + description (location-specific) ─────────────────────
        $stmt = $db->prepare("
            SELECT heading, description, updated_by, updated_at
            FROM course_specialisation
            WHERE course_id = ? AND location_id = ?
            LIMIT 1
        ");
        $stmt->execute([$courseId, $locationId]);
        $specRow = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── Modules (course-wide — NO location_id) ────────────────────────
        $stmt = $db->prepare("
            SELECT id, number, title, sort_order
            FROM course_specialisation_modules
            WHERE course_id = ?
            ORDER BY sort_order, number
        ");
        $stmt->execute([$courseId]);
        $moduleRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $moduleData = [];
        foreach ($moduleRows as $mod) {
            $mid = (int)$mod['id'];

            $stmt2 = $db->prepare("
                SELECT type, count
                FROM course_specialisation_module_badges
                WHERE module_id = ?
            ");
            $stmt2->execute([$mid]);
            $badges = [];
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $badges[$b['type']] = (int)$b['count'];
            }

            $stmt2 = $db->prepare("
                SELECT id, topic, sort_order
                FROM course_specialisation_module_topics
                WHERE module_id = ?
                ORDER BY sort_order
            ");
            $stmt2->execute([$mid]);
            $topics = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $moduleData[] = [
                'id'         => $mid,
                'number'     => (int)$mod['number'],
                'title'      => $mod['title'],
                'sort_order' => $mod['sort_order'],
                'badges'     => $badges,
                'topics'     => $topics,
            ];
        }

        ob_start(); ?>
    <style>
        .spr *, .spr *::before, .spr *::after { box-sizing: border-box; }
        .spr { font-family: system-ui, sans-serif; color: #1e293b; }

        /* ── Section cards ── */
        .spr .section-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:18px; overflow:hidden; }
        .spr .section-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; background:#f1f5f9; border-bottom:1px solid #e2e8f0; cursor:pointer; user-select:none; }
        .spr .section-header h3 { margin:0; font-size:15px; font-weight:600; color:#1e293b; }
        .spr .section-body { padding:18px; display:none; }
        .spr .section-body.open { display:block; }

        /* ── Section scope badge ── */
        .spr .scope-badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; margin-left:10px; vertical-align:middle; }
        .spr .scope-location { background:#dbeafe; color:#1d4ed8; }
        .spr .scope-course   { background:#dcfce7; color:#15803d; }

        /* ── Fields ── */
        .spr label { display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px; text-transform:uppercase; letter-spacing:.4px; }
        .spr input[type=text], .spr textarea {
            width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:6px;
            font-size:14px; color:#1e293b; background:#fff; transition:border-color .15s;
        }
        .spr input[type=text]:focus, .spr textarea:focus { border-color:#6366f1; outline:none; }
        .spr textarea { resize:vertical; min-height:80px; }
        .spr .field-row { margin-bottom:14px; }

        /* ── Buttons ── */
        .spr .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:opacity .15s; }
        .spr .btn:hover { opacity:.85; }
        .spr .btn-primary { background:#6366f1; color:#fff; }
        .spr .btn-success { background:#22c55e; color:#fff; }
        .spr .btn-danger  { background:#ef4444; color:#fff; }
        .spr .btn-sm      { padding:5px 10px; font-size:12px; }
        .spr .btn-plus    { background:#e0f2fe; color:#0284c7; border:1.5px dashed #7dd3fc; }

        /* ── Module block ── */
        .spr .module-block { border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:14px; background:#fff; }
        .spr .module-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; border-radius:10px 10px 0 0; cursor:pointer; }
        .spr .module-num { width:32px; height:32px; border-radius:50%; background:#6366f1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; flex-shrink:0; }
        .spr .module-title-text { font-size:14px; font-weight:600; color:#1e293b; margin-left:10px; flex:1; }
        .spr .module-body { padding:16px; display:none; }
        .spr .module-body.open { display:block; }

        /* ── Badges grid ── */
        .spr .badges-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }
        .spr .badge-field { text-align:center; }
        .spr .badge-field label { font-size:11px; }
        .spr .badge-field input { text-align:center; }

        /* ── Topics ── */
        .spr .topic-row { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
        .spr .topic-row input { flex:1; }
        .spr .topics-list { margin-bottom:8px; }

        /* ── States ── */
        .spr .saving { opacity:.5; pointer-events:none; }
        .spr .saved  { background:#dcfce7 !important; transition:background .4s; }
        .spr .errored { background:#fee2e2 !important; }
    </style>

    <?php
        $updatedBy = $specRow['updated_by'] ?? null;
        $updatedAt = $specRow['updated_at'] ?? null;
    ?>

<div class="spr" id="spr-root" data-course="<?= $courseId ?>" data-location="<?= $locationId ?>">

  <div class="result-header animate-fadeup">
    <div class="result-title"></div>
    <div class="result-meta">
      <span class="meta-pill accent"><?= htmlspecialchars($courseLabel) ?></span>
      <span class="meta-pill"><?= htmlspecialchars($locationLabel) ?></span>
    </div>
  </div>

  <div class="stats-bar">
    <div class="stat-chip"><b><?= count($moduleData) ?></b>&nbsp;Specialisations</div>
    <?php if ($updatedBy): ?>
      <div class="stat-chip">
        ✏️ Last updated by &nbsp;<b><?= htmlspecialchars($updatedBy) ?></b>
        <?php if ($updatedAt): ?>
          &nbsp;on&nbsp;<b><?= htmlspecialchars($updatedAt) ?></b>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ① Heading + Description — location-specific ─────────────────────────── -->
  <div class="section-card" id="sec-heading">
    <div class="section-header" data-toggle-sec="heading">
      <h3>📝 Heading &amp; Description
        <span class="scope-badge scope-location">📍 Location-specific</span>
      </h3>
      <span>▼</span>
    </div>
    <div class="section-body open" id="sec-body-heading">
      <div class="field-row">
        <label>Heading</label>
        <input type="text" id="spec-heading" value="<?= htmlspecialchars($specRow['heading'] ?? '') ?>" placeholder="e.g. Fastrack Your AI Digital Marketing Career">
      </div>
      <div class="field-row">
        <label>Description</label>
        <textarea id="spec-desc" rows="4"><?= htmlspecialchars($specRow['description'] ?? '') ?></textarea>
      </div>
      <button class="btn btn-primary" data-action="save-heading">💾 Save Heading &amp; Description</button>
    </div>
  </div>

  <!-- ② Specialisation Modules — course-wide ──────────────────────────────── -->
  <div class="section-card" id="sec-modules">
    <div class="section-header" data-toggle-sec="modules">
      <h3>🎯 Specialisation Modules
        <span class="scope-badge scope-course">🌐 Course-wide (all locations)</span>
      </h3>
      <span>▼</span>
    </div>
    <div class="section-body open" id="sec-body-modules">
      <div id="modules-container">
<?php
        if (empty($moduleData)) {
            for ($mn = 1; $mn <= 10; $mn++) {
                $moduleData[] = [
                    'id'     => 0,
                    'number' => $mn,
                    'title'  => '',
                    'badges' => ['live'=>0,'assignment'=>0,'casestudy'=>0,'assesment'=>0],
                    'topics' => [],
                ];
            }
        }
        foreach ($moduleData as $mi => $mod):
            $mid    = (int)$mod['id'];
            $badges = $mod['badges'] + ['live'=>0,'assignment'=>0,'casestudy'=>0,'assesment'=>0];
            $topics = !empty($mod['topics']) ? $mod['topics'] : [['id'=>0,'topic'=>'','sort_order'=>1]];
?>
        <div class="module-block" data-module-id="<?= $mid ?>" data-module-index="<?= $mi ?>" data-number="<?= $mod['number'] ?>">
          <div class="module-header" data-toggle-module="<?= $mi ?>">
            <div style="display:flex;align-items:center;flex:1;">
              <div class="module-num"><?= $mod['number'] ?></div>
              <span class="module-title-text"><?= htmlspecialchars($mod['title'] ?: 'Specialisation ' . $mod['number']) ?></span>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-success btn-sm" data-action="save-module" data-module-index="<?= $mi ?>">💾 Save</button>
              <button class="btn btn-danger btn-sm" data-action="delete-module">🗑</button>
            </div>
          </div>
          <div class="module-body" id="mod-body-<?= $mi ?>">
            <div class="field-row">
              <label>Specialisation Title</label>
              <input type="text" class="mod-title" value="<?= htmlspecialchars($mod['title']) ?>" placeholder="e.g. Specialisation 1 - SEO Mastery with AI">
            </div>
            <label>Badges (enter count, 0 = hidden)</label>
            <div class="badges-grid" style="margin-top:6px;">
              <div class="badge-field">
                <label>🎥 Live</label>
                <input type="number" class="badge-input" data-type="live" min="0" value="<?= $badges['live'] ?>">
              </div>
              <div class="badge-field">
                <label>📝 Assignments</label>
                <input type="number" class="badge-input" data-type="assignment" min="0" value="<?= $badges['assignment'] ?>">
              </div>
              <div class="badge-field">
                <label>💼 Case Study</label>
                <input type="number" class="badge-input" data-type="casestudy" min="0" value="<?= $badges['casestudy'] ?>">
              </div>
              <div class="badge-field">
                <label>✅ Assessment</label>
                <input type="number" class="badge-input" data-type="assesment" min="0" value="<?= $badges['assesment'] ?>">
              </div>
            </div>
            <div class="field-row" style="margin-top:12px;">
              <label>Topics</label>
              <div class="topics-list" id="topics-<?= $mi ?>">
<?php foreach ($topics as $ti => $topic): ?>
                <div class="topic-row" data-topic-id="<?= (int)$topic['id'] ?>" data-sort="<?= $ti+1 ?>">
                  <input type="text" class="topic-input" value="<?= htmlspecialchars($topic['topic']) ?>" placeholder="Topic <?= $ti+1 ?>">
                  <button class="btn btn-danger btn-sm" data-action="delete-topic">✕</button>
                </div>
<?php endforeach; ?>
              </div>
              <button class="btn btn-plus btn-sm" data-action="add-topic" data-module-index="<?= $mi ?>">＋ Add Topic</button>
            </div>
          </div>
        </div>
<?php endforeach; ?>
      </div>
      <br>
      <button class="btn btn-plus" data-action="add-module">＋ Add Specialisation Module</button>
    </div>
  </div>

</div><!-- /.spr -->
<?php
        $html = ob_get_clean();

        // ── Inline JS ─────────────────────────────────────────────────────
        $js = <<<'JSCODE'
(function () {

    // Remove previous listener if Load was clicked again
    if (window._sprClickHandler) {
        document.removeEventListener('click', window._sprClickHandler, false);
    }

    window._sprClickHandler = function (e) {

        var root = document.getElementById('spr-root');
        if (!root) return;
        var courseId = root.dataset.course;
        var locId    = root.dataset.location;

        // ── SAVE MODULE ───────────────────────────────────────────────────
        var saveMod = e.target.closest('[data-action="save-module"]');
        if (saveMod) {
            var mi       = saveMod.dataset.moduleIndex;
            var modBlock = saveMod.closest('.module-block');
            var mid      = modBlock.dataset.moduleId;
            var num      = modBlock.dataset.number;
            var title    = modBlock.querySelector('.mod-title').value.trim();

            if (!title) { showToast('⚠️ Specialisation title is required.'); return; }

            var badges = {};
            modBlock.querySelectorAll('.badge-input').forEach(function(inp) {
                badges[inp.dataset.type] = parseInt(inp.value) || 0;
            });

            var topics = [];
            modBlock.querySelectorAll('.topic-input').forEach(function(inp, idx) {
                var v = inp.value.trim();
                if (v) topics.push({ sort_order: idx + 1, topic: v });
            });

            var fd = new FormData();
            fd.append('module_id',  mid);
            fd.append('course_id',  courseId);
            fd.append('number',     num);
            fd.append('sort_order', parseInt(mi) + 1);
            fd.append('title',      title);
            fd.append('badges',     JSON.stringify(badges));
            fd.append('topics',     JSON.stringify(topics));

            modBlock.classList.add('saving');
            fetch('/da360-admin/specialisation_api.php?action=save_module', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    modBlock.classList.remove('saving');
                    if (d.success) {
                        modBlock.dataset.moduleId = d.module_id;
                        modBlock.querySelector('.module-title-text').textContent = title;
                        modBlock.classList.add('saved');
                        showToast('✅ Specialisation ' + num + ' saved!');
                        setTimeout(function() { modBlock.classList.remove('saved'); }, 2200);
                    } else {
                        showToast('❌ ' + (d.message || 'Error'));
                    }
                })
                .catch(function() {
                    modBlock.classList.remove('saving');
                    showToast('❌ Network error.');
                });
            return;
        }

        // ── DELETE MODULE ─────────────────────────────────────────────────
        var delMod = e.target.closest('[data-action="delete-module"]');
        if (delMod) {
            var delModBlock = delMod.closest('.module-block');
            var mid         = delModBlock.dataset.moduleId;
            if (mid && mid !== '0') {
                if (!confirm('Delete this specialisation module for ALL locations?')) return;
                var fd = new FormData();
                fd.append('module_id', mid);
                fd.append('course_id', courseId);
                fetch('/da360-admin/specialisation_api.php?action=delete_module', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success) { delModBlock.remove(); showToast('🗑️ Module removed.'); }
                        else showToast('❌ ' + (d.message || 'Error'));
                    });
            } else { delModBlock.remove(); }
            return;
        }

        // ── DELETE TOPIC ──────────────────────────────────────────────────
        var delTopic = e.target.closest('[data-action="delete-topic"]');
        if (delTopic) { delTopic.closest('.topic-row').remove(); return; }

        // ── SAVE HEADING ──────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-heading"]')) {
            var heading = document.getElementById('spec-heading').value.trim();
            var desc    = document.getElementById('spec-desc').value.trim();
            var fd = new FormData();
            fd.append('course_id',   courseId);
            fd.append('location_id', locId);
            fd.append('heading',     heading);
            fd.append('description', desc);
            fetch('/da360-admin/specialisation_api.php?action=save_heading', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) { showToast(d.success ? '✅ Heading saved!' : '❌ ' + (d.message || 'Error')); })
                .catch(function() { showToast('❌ Network error.'); });
            return;
        }

        // ── Section toggle ────────────────────────────────────────────────
        var secHdr = e.target.closest('[data-toggle-sec]');
        if (secHdr) {
            var body = document.getElementById('sec-body-' + secHdr.dataset.toggleSec);
            if (body) body.classList.toggle('open');
            return;
        }

        // ── Module header toggle ──────────────────────────────────────────
        var modHdr = e.target.closest('[data-toggle-module]');
        if (modHdr && !e.target.closest('button')) {
            var mbody = document.getElementById('mod-body-' + modHdr.dataset.toggleModule);
            if (mbody) mbody.classList.toggle('open');
            return;
        }

        // ── ADD MODULE ────────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-module"]')) {
            var container = document.getElementById('modules-container');
            var mi  = container.querySelectorAll('.module-block').length;
            var num = mi + 1;
            var block = document.createElement('div');
            block.className = 'module-block';
            block.dataset.moduleId    = '0';
            block.dataset.moduleIndex = mi;
            block.dataset.number      = num;
            block.innerHTML =
                '<div class="module-header" data-toggle-module="' + mi + '">' +
                  '<div style="display:flex;align-items:center;flex:1;">' +
                    '<div class="module-num">' + num + '</div>' +
                    '<span class="module-title-text">Specialisation ' + num + '</span>' +
                  '</div>' +
                  '<div style="display:flex;gap:8px;">' +
                    '<button class="btn btn-success btn-sm" data-action="save-module" data-module-index="' + mi + '">💾 Save</button>' +
                    '<button class="btn btn-danger btn-sm" data-action="delete-module">🗑</button>' +
                  '</div>' +
                '</div>' +
                '<div class="module-body open" id="mod-body-' + mi + '">' +
                  '<div class="field-row">' +
                    '<label>Specialisation Title</label>' +
                    '<input type="text" class="mod-title" placeholder="e.g. Specialisation ' + num + ' - Title">' +
                  '</div>' +
                  '<label>Badges (enter count, 0 = hidden)</label>' +
                  '<div class="badges-grid" style="margin-top:6px;">' +
                    '<div class="badge-field"><label>🎥 Live</label><input type="number" class="badge-input" data-type="live" min="0" value="0"></div>' +
                    '<div class="badge-field"><label>📝 Assignments</label><input type="number" class="badge-input" data-type="assignment" min="0" value="0"></div>' +
                    '<div class="badge-field"><label>💼 Case Study</label><input type="number" class="badge-input" data-type="casestudy" min="0" value="0"></div>' +
                    '<div class="badge-field"><label>✅ Assessment</label><input type="number" class="badge-input" data-type="assesment" min="0" value="0"></div>' +
                  '</div>' +
                  '<div class="field-row" style="margin-top:12px;">' +
                    '<label>Topics</label>' +
                    '<div class="topics-list" id="topics-' + mi + '">' +
                      '<div class="topic-row" data-topic-id="0" data-sort="1">' +
                        '<input type="text" class="topic-input" placeholder="Topic 1">' +
                        '<button class="btn btn-danger btn-sm" data-action="delete-topic">✕</button>' +
                      '</div>' +
                    '</div>' +
                    '<button class="btn btn-plus btn-sm" data-action="add-topic" data-module-index="' + mi + '">＋ Add Topic</button>' +
                  '</div>' +
                '</div>';
            container.appendChild(block);
            return;
        }

        // ── ADD TOPIC ─────────────────────────────────────────────────────
        var addTopic = e.target.closest('[data-action="add-topic"]');
        if (addTopic) {
            var mi   = addTopic.dataset.moduleIndex;
            var list = document.getElementById('topics-' + mi);
            var sort = list.querySelectorAll('.topic-row').length + 1;
            var row  = document.createElement('div');
            row.className = 'topic-row';
            row.dataset.topicId = '0';
            row.dataset.sort    = sort;
            row.innerHTML = '<input type="text" class="topic-input" placeholder="Topic ' + sort + '">' +
                            '<button class="btn btn-danger btn-sm" data-action="delete-topic">✕</button>';
            list.appendChild(row);
            return;
        }

    };

    document.addEventListener('click', window._sprClickHandler, false);

})();
JSCODE;

        echo json_encode(['success' => true, 'html' => $html, 'js' => $js]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE HEADING + DESCRIPTION  (course + location specific)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_heading' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);
        $heading    = trim($_POST['heading']       ?? '');
        $desc       = trim($_POST['description']   ?? '');
        $updatedBy  = $_SESSION['da360_user']['name']
                   ?? $_SESSION['da360_user']['username']
                   ?? 'unknown';

        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO course_specialisation (course_id, location_id, heading, description, updated_at, updated_by)
            VALUES (:course_id, :location_id, :heading, :description, NOW(), :updated_by)
            ON DUPLICATE KEY UPDATE
                heading     = VALUES(heading),
                description = VALUES(description),
                updated_at  = NOW(),
                updated_by  = VALUES(updated_by)
        ");
        $stmt->execute([
            'course_id'   => $courseId,
            'location_id' => $locationId,
            'heading'     => $heading,
            'description' => $desc,
            'updated_by'  => $updatedBy,
        ]);

        echo json_encode(['success' => true, 'message' => 'Heading saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE MODULE + BADGES + TOPICS  (course-wide — NO location_id)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_module' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $moduleId   = (int)($_POST['module_id']  ?? 0);
        $courseId   = (int)($_POST['course_id']  ?? 0);
        $number     = (int)($_POST['number']     ?? 0);
        $sortOrder  = (int)($_POST['sort_order'] ?? 1);
        $title      = trim($_POST['title']       ?? '');
        $badgesJson = $_POST['badges']           ?? '{}';
        $topicsJson = $_POST['topics']           ?? '[]';
        $updatedBy  = $_SESSION['da360_user']['name']
                   ?? $_SESSION['da360_user']['username']
                   ?? 'unknown';

        if (!$courseId || !$title) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit;
        }

        $badges = json_decode($badgesJson, true) ?: [];
        $topics = json_decode($topicsJson, true) ?: [];
        $validBadgeTypes = ['live', 'assignment', 'casestudy', 'assesment'];

        if ($moduleId) {
            $stmt = $db->prepare("
                UPDATE course_specialisation_modules
                SET number=?, title=?, sort_order=?, updated_at=NOW(), updated_by=?
                WHERE id=? AND course_id=?
            ");
            $stmt->execute([$number, $title, $sortOrder, $updatedBy, $moduleId, $courseId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO course_specialisation_modules (course_id, number, title, sort_order, updated_at, updated_by)
                VALUES (?,?,?,?,NOW(),?)
            ");
            $stmt->execute([$courseId, $number, $title, $sortOrder, $updatedBy]);
            $moduleId = (int)$db->lastInsertId();
        }

        // Upsert badges
        $insBadge = $db->prepare("
            INSERT INTO course_specialisation_module_badges (module_id, type, count, updated_at, updated_by)
            VALUES (?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE count=VALUES(count), updated_at=NOW(), updated_by=VALUES(updated_by)
        ");
        foreach ($validBadgeTypes as $type) {
            $count = isset($badges[$type]) ? (int)$badges[$type] : 0;
            $insBadge->execute([$moduleId, $type, $count, $updatedBy]);
        }

        // Replace topics
        $db->prepare("DELETE FROM course_specialisation_module_topics WHERE module_id=?")->execute([$moduleId]);
        $insTopic = $db->prepare("
            INSERT INTO course_specialisation_module_topics (module_id, topic, sort_order, updated_at, updated_by)
            VALUES (?,?,?,NOW(),?)
        ");
        foreach ($topics as $t) {
            $topic = trim($t['topic'] ?? '');
            if ($topic === '') continue;
            $insTopic->execute([$moduleId, $topic, (int)($t['sort_order'] ?? 1), $updatedBy]);
        }

        echo json_encode(['success' => true, 'module_id' => $moduleId, 'message' => 'Module saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE MODULE (course-wide — NO location_id)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_module' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $courseId = (int)($_POST['course_id'] ?? 0);

        if (!$moduleId || !$courseId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        $db->prepare("DELETE FROM course_specialisation_module_topics WHERE module_id=?")->execute([$moduleId]);
        $db->prepare("DELETE FROM course_specialisation_module_badges WHERE module_id=?")->execute([$moduleId]);
        $stmt = $db->prepare("DELETE FROM course_specialisation_modules WHERE id=? AND course_id=? LIMIT 1");
        $stmt->execute([$moduleId, $courseId]);

        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Module deleted']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
