<?php
// ── CORS ───────────────────────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost',
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

// ── Auth ───────────────────────────────────────────────────────────────────
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
    // GET CURRICULUM JSON  — for Next.js frontend
    // GET /curriculum_api.php?action=get_curriculum_json&course_id=1&api_key=XXX
    //
    // Heading + description  → course + location specific
    // Batch timings          → course + location specific
    // Modules                → course-wide (shared across all locations)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_curriculum_json') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']);
            exit;
        }

        // ── Modules are course-wide — fetch once ──────────────────────────
        $stmt = $db->prepare("
            SELECT id, number, title
            FROM course_modules
            WHERE course_id = ?
            ORDER BY sort_order, number
        ");
        $stmt->execute([$courseId]);
        $moduleRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $modules = [];
        foreach ($moduleRows as $mod) {
            $mid = (int)$mod['id'];

            $stmt2 = $db->prepare("
                SELECT type, count
                FROM course_module_badges
                WHERE module_id = ?
                ORDER BY FIELD(type,'live','assignment','casestudy','assesment')
            ");
            $stmt2->execute([$mid]);
            $badgeRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $badges = [];
            $badgeLabelMap = [
                'live'       => 'Live Sessions',
                'assignment' => 'Assignments',
                'casestudy'  => 'Case Study',
                'assesment'  => 'Assessments',
            ];
            foreach ($badgeRows as $b) {
                if ((int)$b['count'] > 0) {
                    $badges[] = [
                        'type'  => $b['type'],
                        'label' => $b['count'] . ' ' . ($badgeLabelMap[$b['type']] ?? $b['type']),
                    ];
                }
            }

            $stmt2 = $db->prepare("
                SELECT topic
                FROM course_module_topics
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
                FROM course_curriculum
                WHERE course_id = ? AND location_id = ?
                LIMIT 1
            ");
            $stmt->execute([$courseId, $lid]);
            $curr = $stmt->fetch(PDO::FETCH_ASSOC);

            // ── Batches + slots (location-specific) ───────────────────────
            $stmt = $db->prepare("
                SELECT id, label
                FROM course_batches
                WHERE course_id = ? AND location_id = ?
                ORDER BY sort_order
            ");
            $stmt->execute([$courseId, $lid]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $batchTimings = [];
            foreach ($batches as $b) {
                $stmt2 = $db->prepare("
                    SELECT slot
                    FROM course_batch_slots
                    WHERE batch_id = ?
                    ORDER BY sort_order
                ");
                $stmt2->execute([$b['id']]);
                $slots = $stmt2->fetchAll(PDO::FETCH_COLUMN);

                $batchTimings[] = [
                    'label' => $b['label'],
                    'slots' => $slots,
                ];
            }

            // Modules are shared — same for every location
            $locations[$slug] = [
                'heading'      => $curr['heading']     ?? '',
                'description'  => $curr['description'] ?? '',
                'batchTimings' => $batchTimings,
                'modules'      => $modules,   // course-wide, not location-specific
            ];
        }

        echo json_encode(['success' => true, 'locations' => $locations]);
        exit;
    }
    if ($action === 'get_curriculum_html') {
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
            FROM course_curriculum
            WHERE course_id = ? AND location_id = ?
            LIMIT 1
        ");
        $stmt->execute([$courseId, $locationId]);
        $currRow = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── Batches (location-specific) ───────────────────────────────────
        $stmt = $db->prepare("
            SELECT id, label, sort_order
            FROM course_batches
            WHERE course_id = ? AND location_id = ?
            ORDER BY sort_order
        ");
        $stmt->execute([$courseId, $locationId]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $batchData = [];
        foreach ($batches as $b) {
            $stmt2 = $db->prepare("
                SELECT id, slot, sort_order
                FROM course_batch_slots
                WHERE batch_id = ?
                ORDER BY sort_order
            ");
            $stmt2->execute([$b['id']]);
            $batchData[] = [
                'id'         => $b['id'],
                'label'      => $b['label'],
                'sort_order' => $b['sort_order'],
                'slots'      => $stmt2->fetchAll(PDO::FETCH_ASSOC),
            ];
        }

        // ── Modules (course-wide — NO location_id) ────────────────────────
        $stmt = $db->prepare("
            SELECT id, number, title, sort_order
            FROM course_modules
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
                FROM course_module_badges
                WHERE module_id = ?
            ");
            $stmt2->execute([$mid]);
            $badges = [];
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $badges[$b['type']] = (int)$b['count'];
            }

            $stmt2 = $db->prepare("
                SELECT id, topic, sort_order
                FROM course_module_topics
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
        .cmr *, .cmr *::before, .cmr *::after { box-sizing: border-box; }
        .cmr { font-family: system-ui, sans-serif; color: #1e293b; }

        /* ── Top meta bar ── */
        .cmr .meta-bar { display:flex; align-items:center; justify-content:space-between; padding:10px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:18px; font-size:13px; color:#64748b; }
        .cmr .meta-bar strong { color:#334155; }

        /* ── Section cards ── */
        .cmr .section-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:18px; overflow:hidden; }
        .cmr .section-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; background:#f1f5f9; border-bottom:1px solid #e2e8f0; cursor:pointer; user-select:none; }
        .cmr .section-header h3 { margin:0; font-size:15px; font-weight:600; color:#1e293b; }
        .cmr .section-body { padding:18px; display:none; }
        .cmr .section-body.open { display:block; }

        /* ── Section scope badge ── */
        .cmr .scope-badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; margin-left:10px; vertical-align:middle; }
        .cmr .scope-location { background:#dbeafe; color:#1d4ed8; }
        .cmr .scope-course   { background:#dcfce7; color:#15803d; }

        /* ── Fields ── */
        .cmr label { display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px; text-transform:uppercase; letter-spacing:.4px; }
        .cmr input[type=text], .cmr textarea, .cmr select {
            width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:6px;
            font-size:14px; color:#1e293b; background:#fff; transition:border-color .15s;
        }
        .cmr input[type=text]:focus, .cmr textarea:focus { border-color:#6366f1; outline:none; }
        .cmr textarea { resize:vertical; min-height:80px; }
        .cmr .field-row { margin-bottom:14px; }
        .cmr .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }

        /* ── Buttons ── */
        .cmr .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:opacity .15s; }
        .cmr .btn:hover { opacity:.85; }
        .cmr .btn-primary   { background:#6366f1; color:#fff; }
        .cmr .btn-success   { background:#22c55e; color:#fff; }
        .cmr .btn-danger    { background:#ef4444; color:#fff; }
        .cmr .btn-outline   { background:#fff; color:#475569; border:1.5px solid #cbd5e1; }
        .cmr .btn-sm        { padding:5px 10px; font-size:12px; }
        .cmr .btn-plus      { background:#e0f2fe; color:#0284c7; border:1.5px dashed #7dd3fc; }

        /* ── Batch section ── */
        .cmr .batch-block { border:1px solid #e2e8f0; border-radius:8px; padding:14px; margin-bottom:12px; background:#fafafa; }
        .cmr .batch-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .cmr .batch-header strong { font-size:14px; color:#334155; }
        .cmr .slot-row { display:flex; align-items:center; gap:8px; margin-bottom:7px; }
        .cmr .slot-row input { flex:1; }
        .cmr .slots-list { margin-bottom:8px; }

        /* ── Module block ── */
        .cmr .module-block { border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:14px; background:#fff; }
        .cmr .module-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; border-radius:10px 10px 0 0; cursor:pointer; }
        .cmr .module-num { width:32px; height:32px; border-radius:50%; background:#6366f1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:700; flex-shrink:0; }
        .cmr .module-title-text { font-size:14px; font-weight:600; color:#1e293b; margin-left:10px; flex:1; }
        .cmr .module-body { padding:16px; display:none; }
        .cmr .module-body.open { display:block; }

        /* ── Badges grid ── */
        .cmr .badges-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }
        .cmr .badge-field { text-align:center; }
        .cmr .badge-field label { font-size:11px; }
        .cmr .badge-field input { text-align:center; }

        /* ── Topics ── */
        .cmr .topic-row { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
        .cmr .topic-row input { flex:1; }
        .cmr .topics-list { margin-bottom:8px; }

        /* ── Save flash ── */
        .cmr .saving { opacity:.5; pointer-events:none; }
        .cmr .saved   { background:#dcfce7 !important; transition:background .4s; }
        .cmr .errored { background:#fee2e2 !important; }
    </style>

    <?php
        $updatedBy = $currRow['updated_by'] ?? null;
        $updatedAt = $currRow['updated_at'] ?? null;
    ?>

<div class="cmr" id="cmr-root" data-course="<?= $courseId ?>" data-location="<?= $locationId ?>">
    <div class="result-header animate-fadeup">
        <div class="result-title"></div>
        <div class="result-meta">
        <span class="meta-pill accent"><?= htmlspecialchars($courseLabel) ?></span>
        <span class="meta-pill"><?= htmlspecialchars($locationLabel) ?></span>
        </div>
    </div>
  <!-- Meta bar -->
  <div class="stats-bar">
    <div class="stat-chip"><b><?= count($batchData) ?></b>&nbsp;Total Batch</div>
    <div class="stat-chip"><b><?= count($moduleRows) ?></b>&nbsp;Module</div>

    <?php if ($updatedBy): ?>
            <div class="stat-chip">
                ✏️ Last updated by &nbsp;<b><?= htmlspecialchars($updatedBy) ?></b>
                <?php if ($updatedAt): ?>
                &nbsp;on&nbsp;<b><?= htmlspecialchars($updatedAt) ?></b>
                <?php endif; ?>
            </div>
            <?php endif; ?>
  </div>

  <!-- ① Heading + Description — course + location specific ─────────────── -->
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
        <input type="text" id="curr-heading" value="<?= htmlspecialchars($currRow['heading'] ?? '') ?>" placeholder="e.g. Latest Curriculum Co-Created by Industry Leaders">
      </div>
      <div class="field-row">
        <label>Description</label>
        <textarea id="curr-desc" rows="4"><?= htmlspecialchars($currRow['description'] ?? '') ?></textarea>
      </div>
      <button class="btn btn-primary" data-action="save-heading">💾 Save Heading &amp; Description</button>
    </div>
  </div>

  <!-- ② Batch Timings — course + location specific ─────────────────────── -->
  <div class="section-card" id="sec-batches">
    <div class="section-header" data-toggle-sec="batches">
      <h3>⏰ Batch Timings
        <span class="scope-badge scope-location">📍 Location-specific</span>
      </h3>
      <span>▼</span>
    </div>
    <div class="section-body open" id="sec-body-batches">
      <div id="batches-container">
<?php
        if (empty($batchData)) {
            $batchData = [['id' => 0, 'label' => 'Offline', 'sort_order' => 1, 'slots' => []]];
        }
        foreach ($batchData as $bi => $batch):
            $batchId = (int)$batch['id'];
            $slots   = $batch['slots'];
            if (empty($slots)) $slots = [['id' => 0, 'slot' => '', 'sort_order' => 1]];
?>
        <div class="batch-block" data-batch-id="<?= $batchId ?>" data-batch-index="<?= $bi ?>">
          <div class="batch-header">
            <div style="display:flex;align-items:center;gap:10px;">
              <label style="margin:0;font-size:13px;">Type:</label>
              <select class="batch-label-select" style="width:130px;">
                <option value="Offline" <?= $batch['label']==='Offline'?'selected':'' ?>>Offline</option>
                <option value="Online"  <?= $batch['label']==='Online' ?'selected':'' ?>>Online</option>
              </select>
            </div>
            <button class="btn btn-danger btn-sm" data-action="delete-batch">🗑 Remove Batch</button>
          </div>
          <div class="slots-list" id="slots-<?= $bi ?>">
<?php foreach ($slots as $si => $slot): ?>
            <div class="slot-row" data-slot-id="<?= (int)$slot['id'] ?>" data-sort="<?= $si+1 ?>">
              <input type="text" class="slot-input" value="<?= htmlspecialchars($slot['slot']) ?>" placeholder="e.g. 9:00AM to 11:00AM">
              <button class="btn btn-danger btn-sm" data-action="delete-slot">✕</button>
            </div>
<?php endforeach; ?>
          </div>
          <button class="btn btn-plus btn-sm" data-action="add-slot" data-batch-index="<?= $bi ?>">＋ Add Slot</button>
          &nbsp;
          <button class="btn btn-success btn-sm" data-action="save-batch" data-batch-index="<?= $bi ?>">💾 Save Batch</button>
        </div>
<?php endforeach; ?>
      </div>
      <br>
      <button class="btn btn-plus" data-action="add-batch">＋ Add Batch (Offline / Online)</button>
    </div>
  </div>

  <!-- ③ Modules — course-wide (shared across all locations) ──────────────── -->
  <div class="section-card" id="sec-modules">
    <div class="section-header" data-toggle-sec="modules">
      <h3>📦 Modules
        <span class="scope-badge scope-course">🌐 Course-wide (all locations)</span>
      </h3>
      <span>▼</span>
    </div>
    <div class="section-body open" id="sec-body-modules">
      <div id="modules-container">
<?php
        if (empty($moduleData)) {
            for ($mn = 1; $mn <= 8; $mn++) {
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
              <span class="module-title-text"><?= htmlspecialchars($mod['title'] ?: 'Module ' . $mod['number']) ?></span>
            </div>
            <div style="display:flex;gap:8px;" >
              <button class="btn btn-success btn-sm" data-action="save-module" data-module-index="<?= $mi ?>">💾 Save</button>
              <button class="btn btn-danger btn-sm" data-action="delete-module">🗑</button>
            </div>
          </div>
          <div class="module-body" id="mod-body-<?= $mi ?>">
            <div class="field-row">
              <label>Module Title</label>
              <input type="text" class="mod-title" value="<?= htmlspecialchars($mod['title']) ?>" placeholder="e.g. Digital Marketing Foundation">
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
      <button class="btn btn-plus" data-action="add-module">＋ Add Module</button>
    </div>
  </div>

</div><!-- /.cmr -->
<?php
        $html = ob_get_clean();

// ── Inline JS ─────────────────────────────────────────────────────
        $js = <<<'JSCODE'
(function () {

    // ✅ Remove previous listener if Load was clicked again
    if (window._cmrClickHandler) {
        document.removeEventListener('click', window._cmrClickHandler, false);
    }

    window._cmrClickHandler = function (e) {

        // ✅ Read fresh on every click — guaranteed cmr-root exists
        var root = document.getElementById('cmr-root');
        if (!root) return;
        var courseId = root.dataset.course;
        var locId    = root.dataset.location;

        // ══════════════════════════════════════════════════════════════════
        // ✅ BUTTON ACTIONS FIRST — before any toggle handlers intercept them
        // ══════════════════════════════════════════════════════════════════

       // ── SAVE MODULE ───────────────────────────────────────────────────
        var saveMod = e.target.closest('[data-action="save-module"]');
        if (saveMod) {
            var mi       = saveMod.dataset.moduleIndex;
            var modBlock = saveMod.closest('.module-block');   // ← renamed
            var mid      = modBlock.dataset.moduleId;
            var num      = modBlock.dataset.number;
            var title    = modBlock.querySelector('.mod-title').value.trim();

            if (!title) { showToast('⚠️ Module title is required.'); return; }

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
            fetch('/da360-admin/curriculum_api.php?action=save_module', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    modBlock.classList.remove('saving');
                    if (d.success) {
                        modBlock.dataset.moduleId = d.module_id;
                        modBlock.querySelector('.module-title-text').textContent = title;
                        modBlock.classList.add('saved');
                        showToast('✅ Module ' + num + ' saved!');
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
            var delModBlock = delMod.closest('.module-block');   // ← renamed
            var mid         = delModBlock.dataset.moduleId;
            if (mid && mid !== '0') {
                if (!confirm('Delete this module for ALL locations?')) return;
                var fd = new FormData();
                fd.append('module_id', mid);
                fd.append('course_id', courseId);
                fetch('/da360-admin/curriculum_api.php?action=delete_module', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success) { delModBlock.remove(); showToast('🗑️ Module removed.'); }
                        else showToast('❌ ' + (d.message || 'Error'));
                    });
            } else { delModBlock.remove(); }
            return;
        }

        // ── DELETE BATCH ──────────────────────────────────────────────────
        var delBatch = e.target.closest('[data-action="delete-batch"]');
        if (delBatch) {
            var delBatchBlock = delBatch.closest('.batch-block');   // ← renamed
            var bid           = delBatchBlock.dataset.batchId;
            if (bid && bid !== '0') {
                if (!confirm('Delete this batch and all its slots?')) return;
                var fd = new FormData();
                fd.append('batch_id',    bid);
                fd.append('course_id',   courseId);
                fd.append('location_id', locId);
                fetch('/da360-admin/curriculum_api.php?action=delete_batch', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success) { delBatchBlock.remove(); showToast('🗑️ Batch removed.'); }
                        else showToast('❌ ' + (d.message || 'Error'));
                    });
            } else { delBatchBlock.remove(); }
            return;
        }

        // ── SAVE BATCH ────────────────────────────────────────────────────
        var saveBatch = e.target.closest('[data-action="save-batch"]');
        if (saveBatch) {
            var saveBatchBlock = saveBatch.closest('.batch-block');   // ← renamed
            var bid            = saveBatchBlock.dataset.batchId;
            var label          = saveBatchBlock.querySelector('.batch-label-select').value;
            var slots          = [];
            saveBatchBlock.querySelectorAll('.slot-input').forEach(function(inp) {
                var v = inp.value.trim(); if (v) slots.push(v);
            });
            var fd = new FormData();
            fd.append('batch_id',    bid);
            fd.append('course_id',   courseId);
            fd.append('location_id', locId);
            fd.append('label',       label);
            fd.append('slots',       JSON.stringify(slots));
            fetch('/da360-admin/curriculum_api.php?action=save_batch', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) { saveBatchBlock.dataset.batchId = d.batch_id; showToast('✅ Batch saved!'); }
                    else showToast('❌ ' + (d.message || 'Error'));
                })
                .catch(function() { showToast('❌ Network error.'); });
            return;
        }

        // ── DELETE SLOT ───────────────────────────────────────────────────
        var delSlot = e.target.closest('[data-action="delete-slot"]');
        if (delSlot) { delSlot.closest('.slot-row').remove(); return; }

        // ── DELETE TOPIC ──────────────────────────────────────────────────
        var delTopic = e.target.closest('[data-action="delete-topic"]');
        if (delTopic) { delTopic.closest('.topic-row').remove(); return; }

        // ── SAVE HEADING ──────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-heading"]')) {
            var heading = document.getElementById('curr-heading').value.trim();
            var desc    = document.getElementById('curr-desc').value.trim();
            var fd = new FormData();
            fd.append('course_id',   courseId);
            fd.append('location_id', locId);
            fd.append('heading',     heading);
            fd.append('description', desc);
            fetch('/da360-admin/curriculum_api.php?action=save_heading', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) { showToast(d.success ? '✅ Heading saved!' : '❌ ' + (d.message || 'Error')); })
                .catch(function() { showToast('❌ Network error.'); });
            return;
        }

        // ══════════════════════════════════════════════════════════════════
        // TOGGLE HANDLERS — after all button checks
        // ══════════════════════════════════════════════════════════════════

        // ── Section toggle ────────────────────────────────────────────────
        var secHdr = e.target.closest('[data-toggle-sec]');
        if (secHdr) {
            var body = document.getElementById('sec-body-' + secHdr.dataset.toggleSec);
            if (body) body.classList.toggle('open');
            return;
        }

        // ── Module header toggle — only if NOT clicking a button ──────────
        var modHdr = e.target.closest('[data-toggle-module]');
        if (modHdr && !e.target.closest('button')) {
            var mbody = document.getElementById('mod-body-' + modHdr.dataset.toggleModule);
            if (mbody) mbody.classList.toggle('open');
            return;
        }

        // ══════════════════════════════════════════════════════════════════
        // ADD ACTIONS
        // ══════════════════════════════════════════════════════════════════

        // ── ADD BATCH ─────────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-batch"]')) {
            var container = document.getElementById('batches-container');
            var idx = container.querySelectorAll('.batch-block').length;
            var block = document.createElement('div');
            block.className = 'batch-block';
            block.dataset.batchId    = '0';
            block.dataset.batchIndex = idx;
            block.innerHTML =
                '<div class="batch-header">' +
                  '<div style="display:flex;align-items:center;gap:10px;">' +
                    '<label style="margin:0;font-size:13px;">Type:</label>' +
                    '<select class="batch-label-select" style="width:130px;">' +
                      '<option value="Offline">Offline</option>' +
                      '<option value="Online">Online</option>' +
                    '</select>' +
                  '</div>' +
                  '<button class="btn btn-danger btn-sm" data-action="delete-batch">🗑 Remove Batch</button>' +
                '</div>' +
                '<div class="slots-list" id="slots-' + idx + '">' +
                  '<div class="slot-row" data-slot-id="0" data-sort="1">' +
                    '<input type="text" class="slot-input" placeholder="e.g. 9:00AM to 11:00AM">' +
                    '<button class="btn btn-danger btn-sm" data-action="delete-slot">✕</button>' +
                  '</div>' +
                '</div>' +
                '<button class="btn btn-plus btn-sm" data-action="add-slot" data-batch-index="' + idx + '">＋ Add Slot</button>' +
                '&nbsp;' +
                '<button class="btn btn-success btn-sm" data-action="save-batch" data-batch-index="' + idx + '">💾 Save Batch</button>';
            container.appendChild(block);
            return;
        }

        // ── ADD SLOT ──────────────────────────────────────────────────────
        var addSlot = e.target.closest('[data-action="add-slot"]');
        if (addSlot) {
            var bi   = addSlot.dataset.batchIndex;
            var list = document.getElementById('slots-' + bi);
            var sort = list.querySelectorAll('.slot-row').length + 1;
            var row  = document.createElement('div');
            row.className = 'slot-row';
            row.dataset.slotId = '0';
            row.dataset.sort   = sort;
            row.innerHTML = '<input type="text" class="slot-input" placeholder="e.g. 11:30AM to 1:00PM">' +
                            '<button class="btn btn-danger btn-sm" data-action="delete-slot">✕</button>';
            list.appendChild(row);
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
                    '<span class="module-title-text">Module ' + num + '</span>' +
                  '</div>' +
                  '<div style="display:flex;gap:8px;" >' +
                    '<button class="btn btn-success btn-sm" data-action="save-module" data-module-index="' + mi + '">💾 Save</button>' +
                    '<button class="btn btn-danger btn-sm" data-action="delete-module">🗑</button>' +
                  '</div>' +
                '</div>' +
                '<div class="module-body open" id="mod-body-' + mi + '">' +
                  '<div class="field-row">' +
                    '<label>Module Title</label>' +
                    '<input type="text" class="mod-title" placeholder="e.g. Digital Marketing Foundation">' +
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

    // ✅ Register the named handler
    document.addEventListener('click', window._cmrClickHandler, false);

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
            INSERT INTO course_curriculum (course_id, location_id, heading, description, updated_at, updated_by)
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
    // SAVE BATCH + SLOTS  (course + location specific)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $batchId    = (int)($_POST['batch_id']    ?? 0);
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);
        $label      = trim($_POST['label']        ?? '');
        $slotsJson  = $_POST['slots']             ?? '[]';
        $updatedBy  = $_SESSION['da360_user']['name']
                   ?? $_SESSION['da360_user']['username']
                   ?? 'unknown';

        if (!$courseId || !$locationId || !in_array($label, ['Offline','Online'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        $slots = json_decode($slotsJson, true) ?: [];

        if ($batchId) {
            $stmt = $db->prepare("
                UPDATE course_batches
                SET label=?, updated_at=NOW(), updated_by=?
                WHERE id=? AND course_id=? AND location_id=?
            ");
            $stmt->execute([$label, $updatedBy, $batchId, $courseId, $locationId]);
        } else {
            $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM course_batches WHERE course_id=? AND location_id=?");
            $stmt->execute([$courseId, $locationId]);
            $sortOrder = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("
                INSERT INTO course_batches (course_id, location_id, label, sort_order, updated_at, updated_by)
                VALUES (?,?,?,?,NOW(),?)
            ");
            $stmt->execute([$courseId, $locationId, $label, $sortOrder, $updatedBy]);
            $batchId = (int)$db->lastInsertId();
        }

        $db->prepare("DELETE FROM course_batch_slots WHERE batch_id=?")->execute([$batchId]);
        $insSlot = $db->prepare("
            INSERT INTO course_batch_slots (batch_id, slot, sort_order, updated_at, updated_by)
            VALUES (?,?,?,NOW(),?)
        ");
        foreach ($slots as $si => $slot) {
            $insSlot->execute([$batchId, trim($slot), $si+1, $updatedBy]);
        }

        echo json_encode(['success' => true, 'batch_id' => $batchId, 'message' => 'Batch saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE BATCH (course + location specific)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $batchId    = (int)($_POST['batch_id']    ?? 0);
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);

        if (!$batchId || !$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        $db->prepare("DELETE FROM course_batch_slots WHERE batch_id=?")->execute([$batchId]);
        $stmt = $db->prepare("DELETE FROM course_batches WHERE id=? AND course_id=? AND location_id=? LIMIT 1");
        $stmt->execute([$batchId, $courseId, $locationId]);

        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Batch deleted']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE MODULE + BADGES + TOPICS  (course-wide — NO location_id)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_module' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $moduleId   = (int)($_POST['module_id']  ?? 0);
        $courseId   = (int)($_POST['course_id']  ?? 0);
        // location_id intentionally NOT used for modules
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
        $validBadgeTypes = ['live','assignment','casestudy','assesment'];

        if ($moduleId) {
            // Update — verify it belongs to this course
            $stmt = $db->prepare("
                UPDATE course_modules
                SET number=?, title=?, sort_order=?, updated_at=NOW(), updated_by=?
                WHERE id=? AND course_id=?
            ");
            $stmt->execute([$number, $title, $sortOrder, $updatedBy, $moduleId, $courseId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO course_modules (course_id, number, title, sort_order, updated_at, updated_by)
                VALUES (?,?,?,?,NOW(),?)
            ");
            $stmt->execute([$courseId, $number, $title, $sortOrder, $updatedBy]);
            $moduleId = (int)$db->lastInsertId();
        }

        // Upsert badges
        $insBadge = $db->prepare("
            INSERT INTO course_module_badges (module_id, type, count, updated_at, updated_by)
            VALUES (?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE count=VALUES(count), updated_at=NOW(), updated_by=VALUES(updated_by)
        ");
        foreach ($validBadgeTypes as $type) {
            $count = isset($badges[$type]) ? (int)$badges[$type] : 0;
            $insBadge->execute([$moduleId, $type, $count, $updatedBy]);
        }

        // Replace topics
        $db->prepare("DELETE FROM course_module_topics WHERE module_id=?")->execute([$moduleId]);
        $insTopic = $db->prepare("
            INSERT INTO course_module_topics (module_id, topic, sort_order, updated_at, updated_by)
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
        // location_id intentionally NOT used for modules

        if (!$moduleId || !$courseId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        $db->prepare("DELETE FROM course_module_topics WHERE module_id=?")->execute([$moduleId]);
        $db->prepare("DELETE FROM course_module_badges WHERE module_id=?")->execute([$moduleId]);
        $stmt = $db->prepare("DELETE FROM course_modules WHERE id=? AND course_id=? LIMIT 1");
        $stmt->execute([$moduleId, $courseId]);

        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Module deleted']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}