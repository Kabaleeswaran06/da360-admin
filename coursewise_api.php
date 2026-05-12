<?php
// ── CORS ────────────────────────────────────────────────────────────────────
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

// ── Upload helper ────────────────────────────────────────────────────────────
function handleImageUpload(string $fileKey, string $folder): string {
    if (empty($_FILES[$fileKey]['tmp_name'])) return '';
    $uploadDir = __DIR__ . '/uploads/' . $folder . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext      = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    $allowed  = ['jpg','jpeg','png','gif','svg','webp'];
    if (!in_array($ext, $allowed, true)) return '';
    $filename = uniqid($folder . '_', true) . '.' . $ext;
    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $uploadDir . $filename)) {
        return '/uploads/' . $folder . '/' . $filename;
    }
    return '';
}

$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    // ══════════════════════════════════════════════════════════════════════════
    // GET LOCATIONS
    // GET /coursewise_api.php?action=get_locations&course_id=1
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_locations') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']); exit;
        }
        $stmt = $db->prepare("SELECT id, label FROM locations WHERE is_active = 1 ORDER BY sort_order, label");
        $stmt->execute();
        echo json_encode(['success' => true, 'locations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET COURSEWISE JSON — for Next.js frontend
    // GET /coursewise_api.php?action=get_coursewise_json&course_id=1&api_key=XXX
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_coursewise_json') {
        $base_url = 'https://confirmation.digitalacademy360.com/da360-admin';

        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']); exit;
        }

        // ── Highlights ────────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT icon, title, value FROM course_highlights WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $highlights = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($highlights as &$h) { $h['icon'] = $base_url . $h['icon']; } unset($h);

        // ── Tools ─────────────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT name, logo FROM course_tools WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tools as &$t) { $t['logo'] = $base_url . $t['logo']; } unset($t);

        // ── Case Studies ──────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT id, logo, title, description FROM course_casestudies WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $csRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $caseStudiesItems = [];
        foreach ($csRows as $cs) {
            $csId = (int)$cs['id'];
            $stmt2 = $db->prepare("SELECT tag FROM course_casestudy_tags WHERE casestudy_id = ? ORDER BY sort_order");
            $stmt2->execute([$csId]);
            $tags = $stmt2->fetchAll(PDO::FETCH_COLUMN);
            $caseStudiesItems[] = [
                'id'          => $csId,
                'logo'        => $base_url . $cs['logo'],
                'title'       => $cs['title'],
                'description' => $cs['description'],
                'tags'        => array_values($tags),
            ];
        }

        // ── Live Projects ─────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT id, title, duration, heading, note, bg_gradient, bg_solid FROM course_liveprojects WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $lpRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $liveProjectItems = [];
        $a=1;
        foreach ($lpRows as $lp) {
            $lpId = (int)$lp['id'];

            $stmt2 = $db->prepare("SELECT logo FROM course_liveproject_logos WHERE project_id = ? ORDER BY sort_order");
            $stmt2->execute([$lpId]);
            $logos = $stmt2->fetchAll(PDO::FETCH_COLUMN);
            $logos = array_map(fn($l) => $base_url . $l, $logos);

            $stmt2 = $db->prepare("SELECT detail FROM course_liveproject_details WHERE project_id = ? ORDER BY sort_order");
            $stmt2->execute([$lpId]);
            $details = $stmt2->fetchAll(PDO::FETCH_COLUMN);

            $stmt2 = $db->prepare("SELECT step FROM course_liveproject_steps WHERE project_id = ? ORDER BY sort_order");
            $stmt2->execute([$lpId]);
            $steps = $stmt2->fetchAll(PDO::FETCH_COLUMN);

            $liveProjectItems[] = [
                'id'         => $a,
                'title'      => $lp['title'],
                'duration'   => $lp['duration'],
                'heading'    => $lp['heading'],
                'note'       => $lp['note'],
                'bgGradient' => $lp['bg_gradient'],
                'bgsolid'    => $lp['bg_solid'],
                'logos'      => array_values($logos),
                'details'    => array_values($details),
                'steps'      => array_values($steps),
            ];
            $a++;
        }
        // ── Key Highlights (program skills) ──────────────────────────────────
        $stmt = $db->prepare("SELECT name FROM course_key_highlights WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $keyHighlights = array_values($stmt->fetchAll(PDO::FETCH_COLUMN));

        // ── Course Info ───────────────────────────────────────────────────  ← ADD
        $stmt = $db->prepare("SELECT course_id_slug, lead_tags FROM course_info WHERE course_id = ? LIMIT 1");
        $stmt->execute([$courseId]);
        $ciRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['course_id_slug' => '', 'lead_tags' => ''];
        $courseInfo = [
            'courseIdSlug' => $ciRow['course_id_slug'],
            'leadTags'     => array_values(array_filter(array_map('trim', explode("\n", $ciRow['lead_tags'])))),
        ];

        // ── Cohorts ───────────────────────────────────────────────────────  ← ADD
        $stmt = $db->prepare("SELECT date, mode, weekday, capacity, campus FROM course_cohorts WHERE course_id = ? ORDER BY sort_order, id");
        $stmt->execute([$courseId]);
        $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Locations ─────────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT id, label, slug FROM locations WHERE is_active = 1 ORDER BY sort_order, label");
        $stmt->execute();
        $locationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

       // ── Locations (location-specific headings only) ───────────────────
        $locations = [];
        foreach ($locationRows as $loc) {
            $lid  = (int)$loc['id'];
            $slug = !empty($loc['slug'])
                ? $loc['slug']
                : strtolower(preg_replace('/\s+/', '_', trim($loc['label'])));

            $stmt2 = $db->prepare("SELECT heading, subheading FROM course_casestudies_heading WHERE course_id = ? LIMIT 1");
            $stmt2->execute([$courseId]);
            $csHead = $stmt2->fetch(PDO::FETCH_ASSOC);

            $stmt2 = $db->prepare("SELECT section, heading, description FROM course_liveprojects_heading WHERE course_id = ? LIMIT 1");
            $stmt2->execute([$courseId]);
            $lpHead = $stmt2->fetch(PDO::FETCH_ASSOC);

            // ── Only location-specific heading data here ──────────────────
            $locations[$slug] = [
                'caseStudies'  => [
                    'heading'    => $csHead['heading']    ?? '',
                    'subheading' => $csHead['subheading'] ?? '',
                ],
                'liveProjects' => [
                    'section'     => $lpHead['section']     ?? '',
                    'heading'     => $lpHead['heading']     ?? '',
                    'description' => $lpHead['description'] ?? '',
                ],
            ];
        }

        // ── Course-wide data at top level ─────────────────────────────────
        echo json_encode([
            'success'          => true,
            'courseInfo'       => $courseInfo,
            'cohorts'          => $cohorts,
            'highlights'       => $highlights,       // ← moved to top level
            'toolstomaster'    => $tools,            // ← moved to top level
            'caseStudyItems'   => $caseStudiesItems, // ← moved to top level
            'liveProjectItems' => $liveProjectItems, // ← moved to top level
            'locations'        => $locations,        // ← now only headings
            'keyHighlights'    => $keyHighlights,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET COURSEWISE HTML — for the CMS admin editor
    // GET /coursewise_api.php?action=get_coursewise_html&course_id=1&location_id=2
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_coursewise_html') {
        $courseId   = (int)($_GET['course_id']   ?? 0);
        $locationId = (int)($_GET['location_id'] ?? 0); // optional, kept for back-compat
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']); exit;
        }

        $stmt = $db->prepare("SELECT label FROM courses WHERE id = ? LIMIT 1");
        $stmt->execute([$courseId]);
        $courseLabel = $stmt->fetchColumn() ?: '';

        $stmt = $db->prepare("SELECT label FROM locations WHERE id = ? LIMIT 1");
        $stmt->execute([$locationId]);
        $locationLabel = $stmt->fetchColumn() ?: '';

        // ── Highlights (course-wide) ──────────────────────────────────────
        $stmt = $db->prepare("SELECT id, icon, title, value, sort_order FROM course_highlights WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $highlights = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Tools (course-wide) ───────────────────────────────────────────
        $stmt = $db->prepare("SELECT id, name, logo, sort_order FROM course_tools WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Case Studies heading (location-specific) ──────────────────────
        $stmt = $db->prepare("SELECT heading, subheading, updated_by, updated_at FROM course_casestudies_heading WHERE course_id = ? LIMIT 1");
        $stmt->execute([$courseId]);
        $csHead = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── Case Studies items (course-wide) ──────────────────────────────
        $stmt = $db->prepare("SELECT id, logo, title, description, sort_order FROM course_casestudies WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $csRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $caseStudies = [];
        foreach ($csRows as $cs) {
            $csId = (int)$cs['id'];
            $stmt2 = $db->prepare("SELECT id, tag, sort_order FROM course_casestudy_tags WHERE casestudy_id = ? ORDER BY sort_order");
            $stmt2->execute([$csId]);
            $cs['tags'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $caseStudies[] = $cs;
        }

        // ── Live Projects heading (location-specific) ─────────────────────
        $stmt = $db->prepare("SELECT section, heading, description, updated_by, updated_at FROM course_liveprojects_heading WHERE course_id = ?  LIMIT 1");
        $stmt->execute([$courseId]);
        $lpHead = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── Live Projects items (course-wide) ─────────────────────────────
        $stmt = $db->prepare("SELECT id, title, duration, heading, note, bg_gradient, bg_solid, sort_order FROM course_liveprojects WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $lpRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $liveProjects = [];
        foreach ($lpRows as $lp) {
            $lpId = (int)$lp['id'];

            $stmt2 = $db->prepare("SELECT id, logo, sort_order FROM course_liveproject_logos WHERE project_id = ? ORDER BY sort_order");
            $stmt2->execute([$lpId]);
            $lp['logos'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $stmt2 = $db->prepare("SELECT id, detail, sort_order FROM course_liveproject_details WHERE project_id = ? ORDER BY sort_order");
            $stmt2->execute([$lpId]);
            $lp['details'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $stmt2 = $db->prepare("SELECT id, step, sort_order FROM course_liveproject_steps WHERE project_id = ? ORDER BY sort_order");
            $stmt2->execute([$lpId]);
            $lp['steps'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $liveProjects[] = $lp;
        }

        // ── Course Info (courseId slug + lead capture tags) ───────────────────
        $stmt = $db->prepare("SELECT course_id_slug, lead_tags FROM course_info WHERE course_id = ? LIMIT 1");
        $stmt->execute([$courseId]);
        $courseInfo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['course_id_slug' => '', 'lead_tags' => ''];
        $leadTagsList = array_filter(array_map('trim', explode("\n", $courseInfo['lead_tags'] ?? '')));

        // ── Key Highlights (course-wide) ─────────────────────────────────────
        $stmt = $db->prepare("SELECT id, name, sort_order FROM course_key_highlights WHERE course_id = ? ORDER BY sort_order");
        $stmt->execute([$courseId]);
        $keyHighlights = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Cohorts (course-wide) ─────────────────────────────────────────────
        $stmt = $db->prepare("SELECT id, date, mode, weekday, capacity, campus, sort_order FROM course_cohorts WHERE course_id = ? ORDER BY sort_order, id");
        $stmt->execute([$courseId]);
        $cohorts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_start(); ?>
    <style>
        .cw *, .cw *::before, .cw *::after { box-sizing: border-box; }
        .cw { font-family: system-ui, sans-serif; color: #1e293b; }

        /* ── Tabs ── */
        .cw .tab-bar { display:flex; gap:4px; border-bottom:2px solid #e2e8f0; margin-bottom:20px; flex-wrap:wrap; }
        .cw .tab-btn {
            display:inline-flex; align-items:center; gap:6px;
            padding:10px 18px; border:none; border-radius:8px 8px 0 0;
            font-size:13px; font-weight:600; cursor:pointer;
            background:#f1f5f9; color:#64748b;
            border-bottom:2px solid transparent; margin-bottom:-2px;
            transition:background .15s, color .15s;
        }
        .cw .tab-btn:hover { background:#e2e8f0; color:#1e293b; }
        .cw .tab-btn.active { background:#fff; color:#6366f1; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0; border-top:2px solid #6366f1; border-bottom:2px solid #fff; }
        .cw .tab-pane { display:none; }
        .cw .tab-pane.active { display:block; }

        /* kept for compat — section-card/section-body no longer collapse */
        .cw .section-card { background:transparent; border:none; margin-bottom:0; overflow:visible; }
        .cw .section-body { padding:0; display:block; }

        .cw .scope-badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; margin-left:10px; vertical-align:middle; }
        .cw .scope-location { background:#dbeafe; color:#1d4ed8; }
        .cw .scope-course   { background:#dcfce7; color:#15803d; }

        .cw label { display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px; text-transform:uppercase; letter-spacing:.4px; }
        .cw input[type=text], .cw textarea, .cw input[type=number] {
            width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:6px;
            font-size:14px; color:#1e293b; background:#fff; transition:border-color .15s;
        }
        .cw input[type=text]:focus, .cw textarea:focus, .cw input[type=number]:focus { border-color:#6366f1; outline:none; }
        .cw textarea { resize:vertical; min-height:80px; }
        .cw .field-row { margin-bottom:14px; }
        .cw .field-2col { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }

        .cw .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:opacity .15s; }
        .cw .btn:hover { opacity:.85; }
        .cw .btn-primary { background:#6366f1; color:#fff; }
        .cw .btn-success { background:#22c55e; color:#fff; }
        .cw .btn-danger  { background:#ef4444; color:#fff; }
        .cw .btn-sm      { padding:5px 10px; font-size:12px; }
        .cw .btn-plus    { background:#e0f2fe; color:#0284c7; border:1.5px dashed #7dd3fc; }

        /* ── Item blocks (highlights, tools, case studies, live projects) ── */
        .cw .item-block { border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:14px; background:#fff; }
        .cw .item-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; border-radius:10px 10px 0 0; cursor:pointer; }
        .cw .item-num { width:28px; height:28px; border-radius:50%; background:#6366f1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; }
        .cw .item-title-text { font-size:14px; font-weight:600; color:#1e293b; margin-left:10px; flex:1; }
        .cw .item-body { padding:16px; display:none; }
        .cw .item-body.open { display:block; }

        /* ── Image preview ── */
        .cw .img-preview { width:60px; height:60px; object-fit:contain; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:6px; background:#f8fafc; display:block; }
        .cw .img-preview-lg { width:120px; height:60px; object-fit:contain; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:6px; background:#f8fafc; display:block; }

        /* ── Tag / bullet rows ── */
        .cw .tag-row, .cw .detail-row, .cw .step-row, .cw .logo-row {
            display:flex; align-items:center; gap:8px; margin-bottom:6px;
        }
        .cw .tag-row input, .cw .detail-row input, .cw .step-row input { flex:1; }
        .cw .logo-row img { width:40px; height:40px; object-fit:contain; border:1px solid #e2e8f0; border-radius:4px; }

        /* ── color swatch ── */
        .cw .color-row { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
        .cw .color-swatch { width:36px; height:36px; border-radius:6px; border:1px solid #e2e8f0; flex-shrink:0; }

        .cw .saving { opacity:.5; pointer-events:none; }
        .cw .saved  { background:#dcfce7 !important; transition:background .4s; }
        .cw .errored { background:#fee2e2 !important; }
        .cw .divider { border:none; border-top:1px solid #e2e8f0; margin:16px 0; }

        /* ── Course Info & Cohort ── */
        .cw .info-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:18px; }
        .cw .info-card-title { font-size:12px; font-weight:700; color:#6366f1; text-transform:uppercase; letter-spacing:.5px; margin-bottom:14px; display:flex; align-items:center; gap:6px; }
        .cw .tag-pill-list { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }
        .cw .lead-tag-row { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
        .cw .lead-tag-row input { flex:1; }
        .cw .cohort-block { border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:14px; background:#fff; overflow:hidden; }
        .cw .cohort-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        .cw .cohort-num { width:26px; height:26px; border-radius:50%; background:#f59e0b; color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
        .cw .cohort-label { font-size:13px; font-weight:600; color:#1e293b; margin-left:10px; flex:1; }
        .cw .cohort-body { padding:16px; }
        .cw .field-3col { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:14px; }
    </style>

<div class="cw" id="cw-root" data-course="<?= $courseId ?>" data-location="<?= $locationId ?>">

  <div class="result-header animate-fadeup">
    <div class="result-title"></div>
    <div class="result-meta">
      <span class="meta-pill accent"><?= htmlspecialchars($courseLabel) ?></span>
      <?php if ($locationLabel): ?>
        <span class="meta-pill"><?= htmlspecialchars($locationLabel) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Tab bar ── -->
  <div class="tab-bar">
    <button class="tab-btn active" data-tab="courseinfo">🏷️ Course Info</button>
    <button class="tab-btn"        data-tab="cohort">📅 Cohort</button>
    <button class="tab-btn"        data-tab="highlights">🌟 Highlights</button>
    <button class="tab-btn"        data-tab="keyhighlights">✨ Key Highlights</button>
    <button class="tab-btn"        data-tab="tools">🛠️ Tools</button>
    <button class="tab-btn"        data-tab="casestudies">💼 Case Studies</button>
    <button class="tab-btn"        data-tab="liveprojects">🚀 Live Projects</button>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════
       TAB 1 — COURSE INFO (courseId + lead capture tags)
  ════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane active" id="tab-pane-courseinfo">
    <div class="info-card">
      <div class="info-card-title">🆔 Course Identifier</div>
      <div class="field-row">
        <label>courseId (slug used in frontend)</label>
        <input type="text" id="ci-course-id-slug" value="<?= htmlspecialchars($courseInfo['course_id_slug']) ?>" placeholder="e.g. pgidm_ea">
      </div>
      <button class="btn btn-primary btn-sm" data-action="save-course-info">💾 Save Course Info</button>
    </div>

    <div class="info-card">
      <div class="info-card-title">🏷️ Lead Capture Tags <span style="font-weight:400;color:#94a3b8;text-transform:none;letter-spacing:0;">(one per line → maps to leadCapture.tags[])</span></div>
      <div id="lead-tags-container">
<?php foreach (array_values($leadTagsList) as $lti => $ltag): ?>
        <div class="lead-tag-row" data-sort="<?= $lti+1 ?>">
          <input type="text" class="lead-tag-input" value="<?= htmlspecialchars($ltag) ?>" placeholder="Tag <?= $lti+1 ?>">
          <button class="btn btn-danger btn-sm" data-action="delete-lead-tag">✕</button>
        </div>
<?php endforeach; ?>
      </div>
      <button class="btn btn-plus btn-sm" style="margin-top:8px;" data-action="add-lead-tag">＋ Add Tag</button>
      <div style="margin-top:12px;">
        <button class="btn btn-primary btn-sm" data-action="save-course-info">💾 Save Course Info</button>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════
       TAB 2 — COHORT
  ════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-pane-cohort">
    <div id="cohorts-container">
<?php foreach ($cohorts as $chi => $cohort): ?>
      <div class="cohort-block" data-cohort-id="<?= (int)$cohort['id'] ?>" data-cohort-index="<?= $chi ?>" data-sort="<?= (int)$cohort['sort_order'] ?>">
        <div class="cohort-header">
          <div style="display:flex;align-items:center;flex:1;">
            <div class="cohort-num"><?= $chi+1 ?></div>
            <span class="cohort-label"><?= htmlspecialchars($cohort['date'] ?: 'Cohort ' . ($chi+1)) ?> — <?= htmlspecialchars($cohort['campus']) ?> <?= htmlspecialchars($cohort['mode']) ?></span>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-success btn-sm" data-action="save-cohort">💾 Save</button>
            <button class="btn btn-danger btn-sm" data-action="delete-cohort">🗑</button>
          </div>
        </div>
        <div class="cohort-body">
          <div class="field-3col">
            <div class="field-row">
              <label>Date</label>
              <input type="text" class="cohort-date" value="<?= htmlspecialchars($cohort['date']) ?>" placeholder="e.g. May 18th">
            </div>
            <div class="field-row">
              <label>Mode</label>
              <input type="text" class="cohort-mode" value="<?= htmlspecialchars($cohort['mode']) ?>" placeholder="e.g. Classroom">
            </div>
            <div class="field-row">
              <label>Weekday</label>
              <input type="text" class="cohort-weekday" value="<?= htmlspecialchars($cohort['weekday']) ?>" placeholder="e.g. (Mon-Fri)">
            </div>
          </div>
          <div class="field-2col">
            <div class="field-row">
              <label>Capacity</label>
              <input type="text" class="cohort-capacity" value="<?= htmlspecialchars($cohort['capacity']) ?>" placeholder="e.g. 30 Seats">
            </div>
            <div class="field-row">
              <label>Campus</label>
              <input type="text" class="cohort-campus" value="<?= htmlspecialchars($cohort['campus']) ?>" placeholder="e.g. Bengaluru">
            </div>
          </div>
        </div>
      </div>
<?php endforeach; ?>
    </div>
    <button class="btn btn-plus" data-action="add-cohort">＋ Add Cohort</button>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════
       TAB 3 — HIGHLIGHTS (course-wide)
  ════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-pane-highlights">
    <div class="section-card" id="sec-highlights">
      <div class="section-body" id="sec-body-highlights">
        <div id="highlights-container">
<?php foreach ($highlights as $hi => $hl): ?>
        <div class="item-block" data-item-id="<?= (int)$hl['id'] ?>" data-item-index="<?= $hi ?>" data-sort="<?= (int)$hl['sort_order'] ?>">
          <div class="item-header" data-toggle-item="hl-<?= $hi ?>">
            <div style="display:flex;align-items:center;flex:1;">
              <div class="item-num"><?= $hi+1 ?></div>
              <span class="item-title-text"><?= htmlspecialchars($hl['title']) ?></span>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-success btn-sm" data-action="save-highlight" data-item-index="<?= $hi ?>">💾 Save</button>
              <button class="btn btn-danger btn-sm" data-action="delete-highlight">🗑</button>
            </div>
          </div>
          <div class="item-body open" id="item-body-hl-<?= $hi ?>">
            <div class="field-2col">
              <div>
                <label>Icon Image</label>
                <?php if ($hl['icon']): ?>
                  <img src="/da360-admin/<?= htmlspecialchars($hl['icon']) ?>" class="img-preview cw-icon-preview" alt="icon">
                <?php else: ?>
                  <img src="" class="img-preview cw-icon-preview" style="display:none;" alt="icon">
                <?php endif; ?>
                <input type="file" class="hl-icon-file" accept="image/*" style="margin-top:4px;">
                <input type="hidden" class="hl-icon-path" value="<?= htmlspecialchars($hl['icon']) ?>">
              </div>
              <div>
                <div class="field-row">
                  <label>Title</label>
                  <input type="text" class="hl-title" value="<?= htmlspecialchars($hl['title']) ?>" placeholder="e.g. Course Duration">
                </div>
                <div class="field-row">
                  <label>Value</label>
                  <input type="text" class="hl-value" value="<?= htmlspecialchars($hl['value']) ?>" placeholder="e.g. 6 Months">
                </div>
              </div>
            </div>
          </div>
        </div>
<?php endforeach; ?>
        </div>
        <button class="btn btn-plus" data-action="add-highlight">＋ Add Highlight</button>
      </div>
    </div>
  </div>
    <!-- ═══════════════════════════════════════════════════════════════════
       TAB — KEY HIGHLIGHTS (program skills, course-wide)
  ════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-pane-keyhighlights">
    <div class="section-card">
      <div class="section-body">
        <div style="font-size:12px;font-weight:700;color:#15803d;margin-bottom:12px;text-transform:uppercase;">
          🌐 Key Highlight Skills (Course-wide)
        </div>
        <div id="keyhighlights-container">
<?php foreach ($keyHighlights as $khi => $kh): ?>
          <div class="kh-row" data-kh-id="<?= (int)$kh['id'] ?>" data-sort="<?= $khi+1 ?>" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <span style="width:24px;height:24px;border-radius:50%;background:#6366f1;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;"><?= $khi+1 ?></span>
            <input type="text" class="kh-name" value="<?= htmlspecialchars($kh['name']) ?>" placeholder="e.g. Digital Marketing" style="flex:1;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;">
            <button class="btn btn-success btn-sm" data-action="save-kh">💾 Save</button>
            <button class="btn btn-danger btn-sm" data-action="delete-kh">🗑</button>
          </div>
<?php endforeach; ?>
        </div>
        <button class="btn btn-plus" style="margin-top:8px;" data-action="add-kh">＋ Add Key Highlight</button>
      </div>
    </div>
  </div>
  <!-- ═══════════════════════════════════════════════════════════════════
       TAB 2 — TOOLS TO MASTER (course-wide)
  ════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-pane-tools">
    <div class="section-card" id="sec-tools">
      <div class="section-body" id="sec-body-tools">
        <div id="tools-container">
        <?php foreach ($tools as $ti => $tool): ?>
        <div class="item-block" data-item-id="<?= (int)$tool['id'] ?>" data-item-index="<?= $ti ?>" data-sort="<?= (int)$tool['sort_order'] ?>">
          <div class="item-header" data-toggle-item="tool-<?= $ti ?>">
            <div style="display:flex;align-items:center;flex:1;">
              <?php if ($tool['logo']): ?>
                <img src="/da360-admin/<?= htmlspecialchars($tool['logo']) ?>" style="width:28px;height:28px;object-fit:contain;margin-right:8px;border-radius:4px;" alt="">
              <?php endif; ?>
              <div class="item-num"><?= $ti+1 ?></div>
              <span class="item-title-text" style="margin-left:10px;"><?= htmlspecialchars($tool['name']) ?></span>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-success btn-sm" data-action="save-tool" data-item-index="<?= $ti ?>">💾 Save</button>
              <button class="btn btn-danger btn-sm" data-action="delete-tool">🗑</button>
            </div>
          </div>
          <div class="item-body open" id="item-body-tool-<?= $ti ?>">
            <div class="field-2col">
              <div>
                <label>Tool Logo</label>
                <?php if ($tool['logo']): ?>
                  <img src="/da360-admin/<?= htmlspecialchars($tool['logo']) ?>" class="img-preview cw-logo-preview" alt="logo">
                <?php else: ?>
                  <img src="" class="img-preview cw-logo-preview" style="display:none;" alt="logo">
                <?php endif; ?>
                <input type="file" class="tool-logo-file" accept="image/*" style="margin-top:4px;">
                <input type="hidden" class="tool-logo-path" value="<?= htmlspecialchars($tool['logo']) ?>">
              </div>
              <div>
                <div class="field-row">
                  <label>Tool Name</label>
                  <input type="text" class="tool-name" value="<?= htmlspecialchars($tool['name']) ?>" placeholder="e.g. Google Analytics 4">
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
        <button class="btn btn-plus" data-action="add-tool">＋ Add Tool</button>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════
       TAB 3 — CASE STUDIES
       Heading: location-specific | Items: course-wide
  ════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-pane-casestudies">
    <div class="section-card" id="sec-casestudies">
      <div class="section-body" id="sec-body-casestudies">

      <!-- Case Studies Heading (location-specific) -->
      <!-- <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:18px;">
        <div style="font-size:12px;font-weight:700;color:#6366f1;margin-bottom:12px;text-transform:uppercase;">📍 Section Heading (Location-specific)</div>
        <div class="field-row">
          <label>Heading</label>
          <input type="text" id="cs-heading" value="<?= htmlspecialchars($csHead['heading'] ?? '') ?>" placeholder="e.g. A Glimpse of Brand Case Studies">
        </div>
        <div class="field-row">
          <label>Subheading</label>
          <input type="text" id="cs-subheading" value="<?= htmlspecialchars($csHead['subheading'] ?? '') ?>" placeholder="e.g. Learn Through Real Business Challenges">
        </div>
        <button class="btn btn-primary btn-sm" data-action="save-cs-heading">💾 Save Heading</button>
      </div> -->

      <!-- Case Studies Items (course-wide) -->
      <!-- <div style="font-size:12px;font-weight:700;color:#15803d;margin-bottom:12px;text-transform:uppercase;">🌐 Case Study Items (Course-wide)</div> -->
      <div id="casestudies-container">
<?php foreach ($caseStudies as $ci => $cs): ?>
        <div class="item-block" data-item-id="<?= (int)$cs['id'] ?>" data-item-index="<?= $ci ?>" data-sort="<?= (int)$cs['sort_order'] ?>">
          <div class="item-header" data-toggle-item="cs-<?= $ci ?>">
            <div style="display:flex;align-items:center;flex:1;">
              <?php if ($cs['logo']): ?>
                <img src="/da360-admin/<?= htmlspecialchars($cs['logo']) ?>" style="width:28px;height:28px;object-fit:contain;margin-right:8px;" alt="">
              <?php endif; ?>
              <div class="item-num"><?= $ci+1 ?></div>
              <span class="item-title-text" style="margin-left:10px;"><?= htmlspecialchars($cs['title']) ?></span>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-success btn-sm" data-action="save-casestudy" data-item-index="<?= $ci ?>">💾 Save</button>
              <button class="btn btn-danger btn-sm" data-action="delete-casestudy">🗑</button>
            </div>
          </div>
          <div class="item-body open" id="item-body-cs-<?= $ci ?>">
            <div class="field-2col">
              <div>
                <label>Brand Logo</label>
                <?php if ($cs['logo']): ?>
                  <img src="/da360-admin/<?= htmlspecialchars($cs['logo']) ?>" class="img-preview-lg cw-cs-logo-preview" alt="logo">
                <?php else: ?>
                  <img src="" class="img-preview-lg cw-cs-logo-preview" style="display:none;" alt="logo">
                <?php endif; ?>
                <input type="file" class="cs-logo-file" accept="image/*" style="margin-top:4px;">
                <input type="hidden" class="cs-logo-path" value="<?= htmlspecialchars($cs['logo']) ?>">
              </div>
              <div>
                <div class="field-row">
                  <label>Title</label>
                  <input type="text" class="cs-title" value="<?= htmlspecialchars($cs['title']) ?>" placeholder="e.g. McDonald's India – Localizing a Global Brand">
                </div>
              </div>
            </div>
            <div class="field-row">
              <label>Description</label>
              <textarea class="cs-description" rows="3"><?= htmlspecialchars($cs['description']) ?></textarea>
            </div>
            <div class="field-row">
              <label>Tags</label>
              <div class="tags-list" id="tags-<?= $ci ?>">
<?php foreach ($cs['tags'] as $tgi => $tag): ?>
                <div class="tag-row" data-tag-id="<?= (int)$tag['id'] ?>" data-sort="<?= $tgi+1 ?>">
                  <input type="text" class="tag-input" value="<?= htmlspecialchars($tag['tag']) ?>" placeholder="Tag <?= $tgi+1 ?>">
                  <button class="btn btn-danger btn-sm" data-action="delete-tag">✕</button>
                </div>
<?php endforeach; ?>
              </div>
              <button class="btn btn-plus btn-sm" data-action="add-tag" data-cs-index="<?= $ci ?>">＋ Add Tag</button>
            </div>
          </div>
        </div>
<?php endforeach; ?>
      </div>
      <button class="btn btn-plus" data-action="add-casestudy">＋ Add Case Study</button>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════
       TAB 4 — LIVE PROJECTS
       Heading: location-specific | Items: course-wide
  ════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-pane-liveprojects">
    <div class="section-card" id="sec-liveprojects">
      <div class="section-body" id="sec-body-liveprojects">

      <!-- Live Projects Heading (location-specific) -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:18px;">
        <div style="font-size:12px;font-weight:700;color:#6366f1;margin-bottom:12px;text-transform:uppercase;">📍 Section Heading (Location-specific)</div>
        <div class="field-row">
          <label>Section ID</label>
          <input type="text" id="lp-section" value="<?= htmlspecialchars($lpHead['section'] ?? '') ?>" placeholder="e.g. section6">
        </div>
        <div class="field-row">
          <label>Heading</label>
          <input type="text" id="lp-heading" value="<?= htmlspecialchars($lpHead['heading'] ?? '') ?>" placeholder="e.g. Post Graduate Live Projects">
        </div>
        <div class="field-row">
          <label>Description</label>
          <textarea id="lp-description" rows="2"><?= htmlspecialchars($lpHead['description'] ?? '') ?></textarea>
        </div>
        <button class="btn btn-primary btn-sm" data-action="save-lp-heading">💾 Save Heading</button>
      </div>

      <!-- Live Projects Items (course-wide) -->
      <div style="font-size:12px;font-weight:700;color:#15803d;margin-bottom:12px;text-transform:uppercase;">🌐 Live Project Items (Course-wide)</div>
      <div id="liveprojects-container">
<?php foreach ($liveProjects as $lpi => $lp): ?>
        <div class="item-block" data-item-id="<?= (int)$lp['id'] ?>" data-item-index="<?= $lpi ?>" data-sort="<?= (int)$lp['sort_order'] ?>">
          <div class="item-header" data-toggle-item="lp-<?= $lpi ?>">
            <div style="display:flex;align-items:center;flex:1;">
              <div class="item-num"><?= $lpi+1 ?></div>
              <span class="item-title-text"><?= htmlspecialchars($lp['title']) ?></span>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-success btn-sm" data-action="save-liveproject" data-item-index="<?= $lpi ?>">💾 Save</button>
              <button class="btn btn-danger btn-sm" data-action="delete-liveproject">🗑</button>
            </div>
          </div>
          <div class="item-body open" id="item-body-lp-<?= $lpi ?>">
            <div class="field-2col">
              <div class="field-row">
                <label>Project Title</label>
                <input type="text" class="lp-title" value="<?= htmlspecialchars($lp['title']) ?>" placeholder="e.g. WordPress Website Development">
              </div>
              <div class="field-row">
                <label>Duration Label</label>
                <input type="text" class="lp-duration" value="<?= htmlspecialchars($lp['duration']) ?>" placeholder="e.g. Combined in 1 Month Live Project">
              </div>
            </div>
            <div class="field-row">
              <label>Project Heading / Intro</label>
              <textarea class="lp-heading" rows="2"><?= htmlspecialchars($lp['heading']) ?></textarea>
            </div>
            <div class="field-row">
              <label>Note (optional)</label>
              <input type="text" class="lp-note" value="<?= htmlspecialchars($lp['note']) ?>" placeholder="Optional note">
            </div>
            <div class="field-2col">
              <div class="field-row">
                <label>Background Gradient (CSS)</label>
                <input type="text" class="lp-bg-gradient" value="<?= htmlspecialchars($lp['bg_gradient']) ?>" placeholder="linear-gradient(...)">
              </div>
              <div class="field-row">
                <label>Background Solid Color</label>
                <input type="text" class="lp-bg-solid" value="<?= htmlspecialchars($lp['bg_solid']) ?>" placeholder="e.g. #CDFFDE">
              </div>
            </div>

            <hr class="divider">

            <!-- Logos -->
            <div class="field-row">
              <label>Tool Logos</label>
              <div class="logos-list" id="logos-<?= $lpi ?>">
<?php foreach ($lp['logos'] as $lgi => $logo): ?>
                <div class="logo-row" data-logo-id="<?= (int)$logo['id'] ?>" data-sort="<?= $lgi+1 ?>">
                  <?php if ($logo['logo']): ?>
                    <img src="/da360-admin/<?= htmlspecialchars($logo['logo']) ?>" class="cw-logo-thumb" alt="logo">
                  <?php else: ?>
                    <img src="" class="cw-logo-thumb" style="display:none;" alt="logo">
                  <?php endif; ?>
                  <input type="hidden" class="logo-path" value="<?= htmlspecialchars($logo['logo']) ?>">
                  <input type="file" class="logo-file-input" accept="image/*">
                  <button class="btn btn-danger btn-sm" data-action="delete-logo">✕</button>
                </div>
<?php endforeach; ?>
              </div>
              <button class="btn btn-plus btn-sm" data-action="add-logo" data-lp-index="<?= $lpi ?>">＋ Add Logo</button>
            </div>

            <hr class="divider">

            <!-- Details (top bullets) -->
            <div class="field-row">
              <label>Details — top bullet points</label>
              <div class="details-list" id="details-<?= $lpi ?>">
<?php foreach ($lp['details'] as $di => $det): ?>
                <div class="detail-row" data-detail-id="<?= (int)$det['id'] ?>" data-sort="<?= $di+1 ?>">
                  <input type="text" class="detail-input" value="<?= htmlspecialchars($det['detail']) ?>" placeholder="Detail <?= $di+1 ?>">
                  <button class="btn btn-danger btn-sm" data-action="delete-detail">✕</button>
                </div>
<?php endforeach; ?>
              </div>
              <button class="btn btn-plus btn-sm" data-action="add-detail" data-lp-index="<?= $lpi ?>">＋ Add Detail</button>
            </div>

            <!-- Steps (bottom bullets) -->
            <div class="field-row">
              <label>Steps — bottom bullet points</label>
              <div class="steps-list" id="steps-<?= $lpi ?>">
<?php foreach ($lp['steps'] as $si => $step): ?>
                <div class="step-row" data-step-id="<?= (int)$step['id'] ?>" data-sort="<?= $si+1 ?>">
                  <input type="text" class="step-input" value="<?= htmlspecialchars($step['step']) ?>" placeholder="Step <?= $si+1 ?>">
                  <button class="btn btn-danger btn-sm" data-action="delete-step">✕</button>
                </div>
<?php endforeach; ?>
              </div>
              <button class="btn btn-plus btn-sm" data-action="add-step" data-lp-index="<?= $lpi ?>">＋ Add Step</button>
            </div>

          </div>
        </div>
<?php endforeach; ?>
      </div>
      <button class="btn btn-plus" data-action="add-liveproject">＋ Add Live Project</button>
      </div>
    </div>
  </div>

</div><!-- /.cw -->
<?php
        $html = ob_get_clean();

        // ── Inline JS ─────────────────────────────────────────────────────────
        $js = <<<'JSCODE'
(function () {

    if (window._cwClickHandler) {
        document.removeEventListener('click', window._cwClickHandler, false);
    }
    if (window._cwChangeHandler) {
        document.removeEventListener('change', window._cwChangeHandler, false);
    }

    // ── Tab switching ─────────────────────────────────────────────────────────
    document.querySelectorAll('.cw .tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = this.dataset.tab;
            document.querySelectorAll('.cw .tab-btn').forEach(function(b) { b.classList.remove('active'); });
            document.querySelectorAll('.cw .tab-pane').forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
            var pane = document.getElementById('tab-pane-' + target);
            if (pane) pane.classList.add('active');
        });
    });

    // ── Image preview helper ──────────────────────────────────────────────────
    function previewFile(fileInput, imgEl) {
        var file = fileInput.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            imgEl.src = e.target.result;
            imgEl.style.display = 'block';
        };
        reader.readAsDataURL(file);
    }

    // ── Upload a single image, return URL promise ─────────────────────────────
    function uploadImage(fileInput, folder) {
      if (!fileInput || !fileInput.files || !fileInput.files[0]) return Promise.resolve(null);
      var fd = new FormData();
      fd.append('image', fileInput.files[0]);
      fd.append('folder', folder);
      return fetch('/da360-admin/coursewise_api.php?action=upload_image&api_key=da360-secret-key-2024', {
          method: 'POST',
          body: fd
      }).then(function(r) {
          return r.json();
      }).then(function(d) {
          console.log('Upload response:', d);  // ← check this in browser console
          if (d.success && d.url) {
              return d.url;  // returns string like "/da360-admin/uploads/highlights/xxx.png"
          }
          return null;
      }).catch(function(err) {
          console.error('Upload error:', err);
          return null;
      });
  }

    // ── File change → preview ─────────────────────────────────────────────────
    window._cwChangeHandler = function(e) {
        // Highlight icon preview
        if (e.target.classList.contains('hl-icon-file')) {
            var block = e.target.closest('.item-block');
            var img   = block.querySelector('.cw-icon-preview');
            previewFile(e.target, img);
        }
        // Tool logo preview
        if (e.target.classList.contains('tool-logo-file')) {
            var block = e.target.closest('.item-block');
            var img   = block.querySelector('.cw-logo-preview');
            previewFile(e.target, img);
        }
        // Case study logo preview
        if (e.target.classList.contains('cs-logo-file')) {
            var block = e.target.closest('.item-block');
            var img   = block.querySelector('.cw-cs-logo-preview');
            previewFile(e.target, img);
        }
        // Live project logo preview
        if (e.target.classList.contains('logo-file-input')) {
            var row   = e.target.closest('.logo-row');
            var img   = row.querySelector('.cw-logo-thumb');
            previewFile(e.target, img);
            uploadImage(e.target, 'liveprojects').then(function(newLogo) {
                if (newLogo) {
                    row.querySelector('.logo-path').value = newLogo;
                    img.src = '/da360-admin' + newLogo;
                    img.style.display = 'block';
                }
            });
        }
    };
    document.addEventListener('change', window._cwChangeHandler, false);

    // ── Root data ─────────────────────────────────────────────────────────────
    window._cwClickHandler = function(e) {
        var root = document.getElementById('cw-root');
        if (!root) return;
        var courseId = root.dataset.course;
        var locId    = root.dataset.location;

        // ── Item header toggle ────────────────────────────────────────────────
        var itemHdr = e.target.closest('[data-toggle-item]');
        if (itemHdr && !e.target.closest('button')) {
            var ibody = document.getElementById('item-body-' + itemHdr.dataset.toggleItem);
            if (ibody) ibody.classList.toggle('open');
            return;
        }

        // ══════════════════════════════════════════════════════════════════════
        // HIGHLIGHTS
        // ══════════════════════════════════════════════════════════════════════

        // ── SAVE HIGHLIGHT ────────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-highlight"]')) {
            var btn   = e.target.closest('[data-action="save-highlight"]');
            var block = btn.closest('.item-block');
            var itemId = block.dataset.itemId;
            var title  = block.querySelector('.hl-title').value.trim();
            var value  = block.querySelector('.hl-value').value.trim();
            var iconFile = block.querySelector('.hl-icon-file');
            if (!title) { showToast('⚠️ Title is required.'); return; }
            block.classList.add('saving');
            uploadImage(iconFile, 'highlights').then(function(newIcon) {
                var fd = new FormData();
                fd.append('highlight_id', itemId);
                fd.append('course_id',    courseId);
                fd.append('title',        title);
                fd.append('value',        value);
                fd.append('sort_order',   parseInt(block.dataset.itemIndex) + 1);
                if (newIcon) fd.append('icon', newIcon);
                else fd.append('icon', block.querySelector('.hl-icon-path').value);
                return fetch('/da360-admin/coursewise_api.php?action=save_highlight', { method:'POST', body:fd });
            }).then(function(r){ return r.json(); })
              .then(function(d) {
                block.classList.remove('saving');
                if (d.success) {
                    block.dataset.itemId = d.highlight_id;
                    block.querySelector('.item-title-text').textContent = title;
                    block.classList.add('saved');
                    showToast('✅ Highlight saved!');
                    setTimeout(function(){ block.classList.remove('saved'); }, 2200);
                } else { showToast('❌ ' + (d.message || 'Error')); }
              }).catch(function(){ block.classList.remove('saving'); showToast('❌ Network error.'); });
            return;
        }

        // ── DELETE HIGHLIGHT ──────────────────────────────────────────────────
        if (e.target.closest('[data-action="delete-highlight"]')) {
            var block  = e.target.closest('.item-block');
            var itemId = block.dataset.itemId;
            if (itemId && itemId !== '0') {
                if (!confirm('Delete this highlight?')) return;
                var fd = new FormData();
                fd.append('highlight_id', itemId);
                fd.append('course_id', courseId);
                fetch('/da360-admin/coursewise_api.php?action=delete_highlight', { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ if (d.success) { block.remove(); showToast('🗑️ Highlight removed.'); } else showToast('❌ ' + d.message); });
            } else { block.remove(); }
            return;
        }

        // ── ADD HIGHLIGHT ─────────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-highlight"]')) {
            var container = document.getElementById('highlights-container');
            var idx = container.querySelectorAll('.item-block').length;
            var block = document.createElement('div');
            block.className = 'item-block';
            block.dataset.itemId    = '0';
            block.dataset.itemIndex = idx;
            block.dataset.sort      = idx + 1;
            block.innerHTML =
                '<div class="item-header" data-toggle-item="hl-' + idx + '">' +
                  '<div style="display:flex;align-items:center;flex:1;">' +
                    '<div class="item-num">' + (idx+1) + '</div>' +
                    '<span class="item-title-text" style="margin-left:10px;">New Highlight</span>' +
                  '</div>' +
                  '<div style="display:flex;gap:8px;">' +
                    '<button class="btn btn-success btn-sm" data-action="save-highlight" data-item-index="' + idx + '">💾 Save</button>' +
                    '<button class="btn btn-danger btn-sm" data-action="delete-highlight">🗑</button>' +
                  '</div>' +
                '</div>' +
                '<div class="item-body open" id="item-body-hl-' + idx + '">' +
                  '<div class="field-2col">' +
                    '<div>' +
                      '<label>Icon Image</label>' +
                      '<img src="" class="img-preview cw-icon-preview" style="display:none;" alt="icon">' +
                      '<input type="file" class="hl-icon-file" accept="image/*" style="margin-top:4px;">' +
                      '<input type="hidden" class="hl-icon-path" value="">' +
                    '</div>' +
                    '<div>' +
                      '<div class="field-row"><label>Title</label><input type="text" class="hl-title" placeholder="e.g. Course Duration"></div>' +
                      '<div class="field-row"><label>Value</label><input type="text" class="hl-value" placeholder="e.g. 6 Months"></div>' +
                    '</div>' +
                  '</div>' +
                '</div>';
            container.appendChild(block);
            return;
        }

        // ══════════════════════════════════════════════════════════════════════
        // TOOLS
        // ══════════════════════════════════════════════════════════════════════

        // ── SAVE TOOL ─────────────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-tool"]')) {
            var btn    = e.target.closest('[data-action="save-tool"]');
            var block  = btn.closest('.item-block');
            var itemId = block.dataset.itemId;
            var name   = block.querySelector('.tool-name').value.trim();
            var logoFile = block.querySelector('.tool-logo-file');
            if (!name) { showToast('⚠️ Tool name is required.'); return; }
            block.classList.add('saving');
            uploadImage(logoFile, 'tools').then(function(newLogo) {
                var fd = new FormData();
                fd.append('tool_id',    itemId);
                fd.append('course_id',  courseId);
                fd.append('name',       name);
                fd.append('sort_order', parseInt(block.dataset.itemIndex) + 1);
                if (newLogo) fd.append('logo', newLogo);
                else fd.append('logo', block.querySelector('.tool-logo-path').value);
                return fetch('/da360-admin/coursewise_api.php?action=save_tool', { method:'POST', body:fd });
            }).then(function(r){ return r.json(); })
              .then(function(d) {
                block.classList.remove('saving');
                if (d.success) {
                    block.dataset.itemId = d.tool_id;
                    block.querySelector('.item-title-text').textContent = name;
                    block.classList.add('saved');
                    showToast('✅ Tool saved!');
                    setTimeout(function(){ block.classList.remove('saved'); }, 2200);
                } else { showToast('❌ ' + (d.message || 'Error')); }
              }).catch(function(){ block.classList.remove('saving'); showToast('❌ Network error.'); });
            return;
        }

        // ── DELETE TOOL ───────────────────────────────────────────────────────
        if (e.target.closest('[data-action="delete-tool"]')) {
            var block  = e.target.closest('.item-block');
            var itemId = block.dataset.itemId;
            if (itemId && itemId !== '0') {
                if (!confirm('Delete this tool?')) return;
                var fd = new FormData();
                fd.append('tool_id', itemId); fd.append('course_id', courseId);
                fetch('/da360-admin/coursewise_api.php?action=delete_tool', { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ if (d.success) { block.remove(); showToast('🗑️ Tool removed.'); } else showToast('❌ ' + d.message); });
            } else { block.remove(); }
            return;
        }

        // ── ADD TOOL ──────────────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-tool"]')) {
            var container = document.getElementById('tools-container');
            var idx = container.querySelectorAll('.item-block').length;
            var block = document.createElement('div');
            block.className = 'item-block';
            block.dataset.itemId = '0'; block.dataset.itemIndex = idx; block.dataset.sort = idx+1;
            block.innerHTML =
                '<div class="item-header" data-toggle-item="tool-' + idx + '">' +
                  '<div style="display:flex;align-items:center;flex:1;">' +
                    '<div class="item-num">' + (idx+1) + '</div>' +
                    '<span class="item-title-text" style="margin-left:10px;">New Tool</span>' +
                  '</div>' +
                  '<div style="display:flex;gap:8px;">' +
                    '<button class="btn btn-success btn-sm" data-action="save-tool" data-item-index="' + idx + '">💾 Save</button>' +
                    '<button class="btn btn-danger btn-sm" data-action="delete-tool">🗑</button>' +
                  '</div>' +
                '</div>' +
                '<div class="item-body open" id="item-body-tool-' + idx + '">' +
                  '<div class="field-2col">' +
                    '<div>' +
                      '<label>Tool Logo</label>' +
                      '<img src="" class="img-preview cw-logo-preview" style="display:none;" alt="logo">' +
                      '<input type="file" class="tool-logo-file" accept="image/*" style="margin-top:4px;">' +
                      '<input type="hidden" class="tool-logo-path" value="">' +
                    '</div>' +
                    '<div><div class="field-row"><label>Tool Name</label><input type="text" class="tool-name" placeholder="e.g. Google Analytics 4"></div></div>' +
                  '</div>' +
                '</div>';
            container.appendChild(block);
            return;
        }

        // ══════════════════════════════════════════════════════════════════════
        // CASE STUDIES
        // ══════════════════════════════════════════════════════════════════════

        // ── SAVE CS HEADING ───────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-cs-heading"]')) {
            var heading    = document.getElementById('cs-heading').value.trim();
            var subheading = document.getElementById('cs-subheading').value.trim();
            var fd = new FormData();
            fd.append('course_id', courseId); fd.append('location_id', locId);
            fd.append('heading', heading); fd.append('subheading', subheading);
            fetch('/da360-admin/coursewise_api.php?action=save_cs_heading', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(d){ showToast(d.success ? '✅ Case Studies heading saved!' : '❌ ' + (d.message||'Error')); })
                .catch(function(){ showToast('❌ Network error.'); });
            return;
        }

        // ── SAVE CASE STUDY ───────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-casestudy"]')) {
            var btn    = e.target.closest('[data-action="save-casestudy"]');
            var block  = btn.closest('.item-block');
            var ci     = block.dataset.itemIndex;
            var itemId = block.dataset.itemId;
            var title  = block.querySelector('.cs-title').value.trim();
            var desc   = block.querySelector('.cs-description').value.trim();
            var logoFile = block.querySelector('.cs-logo-file');
            if (!title) { showToast('⚠️ Title is required.'); return; }
            var tags = [];
            block.querySelectorAll('.tag-input').forEach(function(inp, idx) {
                var v = inp.value.trim();
                if (v) tags.push({ sort_order: idx+1, tag: v });
            });
            block.classList.add('saving');
            uploadImage(logoFile, 'casestudies').then(function(newLogo) {
                var fd = new FormData();
                fd.append('casestudy_id', itemId); fd.append('course_id', courseId);
                fd.append('title', title); fd.append('description', desc);
                fd.append('sort_order', parseInt(ci)+1);
                fd.append('tags', JSON.stringify(tags));
                if (newLogo) fd.append('logo', newLogo);
                else fd.append('logo', block.querySelector('.cs-logo-path').value);
                return fetch('/da360-admin/coursewise_api.php?action=save_casestudy', { method:'POST', body:fd });
            }).then(function(r){ return r.json(); })
              .then(function(d){
                block.classList.remove('saving');
                if (d.success) {
                    block.dataset.itemId = d.casestudy_id;
                    block.querySelector('.item-title-text').textContent = title;
                    block.classList.add('saved');
                    showToast('✅ Case Study saved!');
                    setTimeout(function(){ block.classList.remove('saved'); }, 2200);
                } else { showToast('❌ ' + (d.message||'Error')); }
              }).catch(function(){ block.classList.remove('saving'); showToast('❌ Network error.'); });
            return;
        }

        // ── DELETE CASE STUDY ─────────────────────────────────────────────────
        if (e.target.closest('[data-action="delete-casestudy"]')) {
            var block  = e.target.closest('.item-block');
            var itemId = block.dataset.itemId;
            if (itemId && itemId !== '0') {
                if (!confirm('Delete this case study for ALL locations?')) return;
                var fd = new FormData();
                fd.append('casestudy_id', itemId); fd.append('course_id', courseId);
                fetch('/da360-admin/coursewise_api.php?action=delete_casestudy', { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ if (d.success) { block.remove(); showToast('🗑️ Case Study removed.'); } else showToast('❌ ' + d.message); });
            } else { block.remove(); }
            return;
        }

        // ── ADD CASE STUDY ────────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-casestudy"]')) {
            var container = document.getElementById('casestudies-container');
            var idx = container.querySelectorAll('.item-block').length;
            var block = document.createElement('div');
            block.className = 'item-block';
            block.dataset.itemId = '0'; block.dataset.itemIndex = idx; block.dataset.sort = idx+1;
            block.innerHTML =
                '<div class="item-header" data-toggle-item="cs-' + idx + '">' +
                  '<div style="display:flex;align-items:center;flex:1;">' +
                    '<div class="item-num">' + (idx+1) + '</div>' +
                    '<span class="item-title-text" style="margin-left:10px;">New Case Study</span>' +
                  '</div>' +
                  '<div style="display:flex;gap:8px;">' +
                    '<button class="btn btn-success btn-sm" data-action="save-casestudy" data-item-index="' + idx + '">💾 Save</button>' +
                    '<button class="btn btn-danger btn-sm" data-action="delete-casestudy">🗑</button>' +
                  '</div>' +
                '</div>' +
                '<div class="item-body open" id="item-body-cs-' + idx + '">' +
                  '<div class="field-2col">' +
                    '<div>' +
                      '<label>Brand Logo</label>' +
                      '<img src="" class="img-preview-lg cw-cs-logo-preview" style="display:none;" alt="logo">' +
                      '<input type="file" class="cs-logo-file" accept="image/*" style="margin-top:4px;">' +
                      '<input type="hidden" class="cs-logo-path" value="">' +
                    '</div>' +
                    '<div><div class="field-row"><label>Title</label><input type="text" class="cs-title" placeholder="e.g. McDonald\'s India"></div></div>' +
                  '</div>' +
                  '<div class="field-row"><label>Description</label><textarea class="cs-description" rows="3"></textarea></div>' +
                  '<div class="field-row"><label>Tags</label>' +
                    '<div class="tags-list" id="tags-' + idx + '"></div>' +
                    '<button class="btn btn-plus btn-sm" data-action="add-tag" data-cs-index="' + idx + '">＋ Add Tag</button>' +
                  '</div>' +
                '</div>';
            container.appendChild(block);
            return;
        }

        // ── ADD / DELETE TAG ──────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-tag"]')) {
            var btn = e.target.closest('[data-action="add-tag"]');
            var ci  = btn.dataset.csIndex;
            var list = document.getElementById('tags-' + ci);
            var sort = list ? list.querySelectorAll('.tag-row').length + 1 : 1;
            var row = document.createElement('div');
            row.className = 'tag-row'; row.dataset.tagId = '0'; row.dataset.sort = sort;
            row.innerHTML = '<input type="text" class="tag-input" placeholder="Tag ' + sort + '">' +
                            '<button class="btn btn-danger btn-sm" data-action="delete-tag">✕</button>';
            if (list) list.appendChild(row);
            return;
        }
        if (e.target.closest('[data-action="delete-tag"]')) {
            e.target.closest('.tag-row').remove(); return;
        }

        // ══════════════════════════════════════════════════════════════════════
        // LIVE PROJECTS
        // ══════════════════════════════════════════════════════════════════════

        // ── SAVE LP HEADING ───────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-lp-heading"]')) {
            var section = document.getElementById('lp-section').value.trim();
            var heading = document.getElementById('lp-heading').value.trim();
            var desc    = document.getElementById('lp-description').value.trim();
            var fd = new FormData();
            fd.append('course_id', courseId); fd.append('location_id', locId);
            fd.append('section', section); fd.append('heading', heading); fd.append('description', desc);
            fetch('/da360-admin/coursewise_api.php?action=save_lp_heading', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(d){ showToast(d.success ? '✅ Live Projects heading saved!' : '❌ ' + (d.message||'Error')); })
                .catch(function(){ showToast('❌ Network error.'); });
            return;
        }

        // ── SAVE LIVE PROJECT ─────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-liveproject"]')) {
            var btn    = e.target.closest('[data-action="save-liveproject"]');
            var block  = btn.closest('.item-block');
            var lpi    = block.dataset.itemIndex;
            var itemId = block.dataset.itemId;
            var title  = block.querySelector('.lp-title').value.trim();
            if (!title) { showToast('⚠️ Project title is required.'); return; }

            var details = [], steps = [], logos = [];
            block.querySelectorAll('.detail-input').forEach(function(inp, i) {
                var v = inp.value.trim(); if (v) details.push({ sort_order: i+1, detail: v });
            });
            block.querySelectorAll('.step-input').forEach(function(inp, i) {
                var v = inp.value.trim(); if (v) steps.push({ sort_order: i+1, step: v });
            });

            // Collect existing logos (no new upload in this save — logos uploaded individually)
            block.querySelectorAll('.logo-path').forEach(function(inp, i) {
                var v = inp.value; if (v) logos.push({ sort_order: i+1, logo: v });
            });

            var fd = new FormData();
            fd.append('project_id',  itemId);
            fd.append('course_id',   courseId);
            fd.append('title',       title);
            fd.append('duration',    block.querySelector('.lp-duration').value.trim());
            fd.append('heading',     block.querySelector('.lp-heading').value.trim());
            fd.append('note',        block.querySelector('.lp-note').value.trim());
            fd.append('bg_gradient', block.querySelector('.lp-bg-gradient').value.trim());
            fd.append('bg_solid',    block.querySelector('.lp-bg-solid').value.trim());
            fd.append('sort_order',  parseInt(lpi)+1);
            fd.append('details',     JSON.stringify(details));
            fd.append('steps',       JSON.stringify(steps));
            fd.append('logos',       JSON.stringify(logos));

            block.classList.add('saving');
            fetch('/da360-admin/coursewise_api.php?action=save_liveproject', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    block.classList.remove('saving');
                    if (d.success) {
                        block.dataset.itemId = d.project_id;
                        block.querySelector('.item-title-text').textContent = title;
                        block.classList.add('saved');
                        showToast('✅ Live Project saved!');
                        setTimeout(function(){ block.classList.remove('saved'); }, 2200);
                    } else { showToast('❌ ' + (d.message||'Error')); }
                }).catch(function(){ block.classList.remove('saving'); showToast('❌ Network error.'); });
            return;
        }

        // ── DELETE LIVE PROJECT ───────────────────────────────────────────────
        if (e.target.closest('[data-action="delete-liveproject"]')) {
            var block  = e.target.closest('.item-block');
            var itemId = block.dataset.itemId;
            if (itemId && itemId !== '0') {
                if (!confirm('Delete this live project for ALL locations?')) return;
                var fd = new FormData();
                fd.append('project_id', itemId); fd.append('course_id', courseId);
                fetch('/da360-admin/coursewise_api.php?action=delete_liveproject', { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ if (d.success) { block.remove(); showToast('🗑️ Live Project removed.'); } else showToast('❌ ' + d.message); });
            } else { block.remove(); }
            return;
        }

        // ── ADD LIVE PROJECT ──────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-liveproject"]')) {
            var container = document.getElementById('liveprojects-container');
            var idx = container.querySelectorAll('.item-block').length;
            var block = document.createElement('div');
            block.className = 'item-block';
            block.dataset.itemId = '0'; block.dataset.itemIndex = idx; block.dataset.sort = idx+1;
            block.innerHTML =
                '<div class="item-header" data-toggle-item="lp-' + idx + '">' +
                  '<div style="display:flex;align-items:center;flex:1;">' +
                    '<div class="item-num">' + (idx+1) + '</div>' +
                    '<span class="item-title-text">New Live Project</span>' +
                  '</div>' +
                  '<div style="display:flex;gap:8px;">' +
                    '<button class="btn btn-success btn-sm" data-action="save-liveproject" data-item-index="' + idx + '">💾 Save</button>' +
                    '<button class="btn btn-danger btn-sm" data-action="delete-liveproject">🗑</button>' +
                  '</div>' +
                '</div>' +
                '<div class="item-body open" id="item-body-lp-' + idx + '">' +
                  '<div class="field-2col">' +
                    '<div class="field-row"><label>Project Title</label><input type="text" class="lp-title" placeholder="e.g. WordPress Development"></div>' +
                    '<div class="field-row"><label>Duration Label</label><input type="text" class="lp-duration" placeholder="e.g. 1 Month Live Project"></div>' +
                  '</div>' +
                  '<div class="field-row"><label>Project Heading / Intro</label><textarea class="lp-heading" rows="2"></textarea></div>' +
                  '<div class="field-row"><label>Note (optional)</label><input type="text" class="lp-note" placeholder="Optional note"></div>' +
                  '<div class="field-2col">' +
                    '<div class="field-row"><label>Background Gradient (CSS)</label><input type="text" class="lp-bg-gradient" placeholder="linear-gradient(...)"></div>' +
                    '<div class="field-row"><label>Background Solid Color</label><input type="text" class="lp-bg-solid" placeholder="e.g. #CDFFDE"></div>' +
                  '</div>' +
                  '<hr class="divider">' +
                  '<div class="field-row"><label>Tool Logos</label>' +
                    '<div class="logos-list" id="logos-' + idx + '"></div>' +
                    '<button class="btn btn-plus btn-sm" data-action="add-logo" data-lp-index="' + idx + '">＋ Add Logo</button>' +
                  '</div>' +
                  '<hr class="divider">' +
                  '<div class="field-row"><label>Details — top bullets</label>' +
                    '<div class="details-list" id="details-' + idx + '"></div>' +
                    '<button class="btn btn-plus btn-sm" data-action="add-detail" data-lp-index="' + idx + '">＋ Add Detail</button>' +
                  '</div>' +
                  '<div class="field-row"><label>Steps — bottom bullets</label>' +
                    '<div class="steps-list" id="steps-' + idx + '"></div>' +
                    '<button class="btn btn-plus btn-sm" data-action="add-step" data-lp-index="' + idx + '">＋ Add Step</button>' +
                  '</div>' +
                '</div>';
            container.appendChild(block);
            return;
        }

        // ── ADD / DELETE LOGO (live project) ──────────────────────────────────
        if (e.target.closest('[data-action="add-logo"]')) {
            var btn = e.target.closest('[data-action="add-logo"]');
            var lpi = btn.dataset.lpIndex;
            var list = document.getElementById('logos-' + lpi);
            var sort = list ? list.querySelectorAll('.logo-row').length + 1 : 1;
            var row = document.createElement('div');
            row.className = 'logo-row'; row.dataset.logoId = '0'; row.dataset.sort = sort;
            row.innerHTML = '<img src="" class="cw-logo-thumb" style="display:none;" alt="logo">' +
                            '<input type="hidden" class="logo-path" value="">' +
                            '<input type="file" class="logo-file-input" accept="image/*">' +
                            '<button class="btn btn-danger btn-sm" data-action="delete-logo">✕</button>';
            if (list) list.appendChild(row);
            return;
        }
        if (e.target.closest('[data-action="delete-logo"]')) {
            e.target.closest('.logo-row').remove(); return;
        }

        // ── ADD / DELETE DETAIL ───────────────────────────────────────────────
        if (e.target.closest('[data-action="add-detail"]')) {
            var btn = e.target.closest('[data-action="add-detail"]');
            var lpi = btn.dataset.lpIndex;
            var list = document.getElementById('details-' + lpi);
            var sort = list ? list.querySelectorAll('.detail-row').length + 1 : 1;
            var row = document.createElement('div');
            row.className = 'detail-row'; row.dataset.detailId = '0'; row.dataset.sort = sort;
            row.innerHTML = '<input type="text" class="detail-input" placeholder="Detail ' + sort + '">' +
                            '<button class="btn btn-danger btn-sm" data-action="delete-detail">✕</button>';
            if (list) list.appendChild(row);
            return;
        }
        if (e.target.closest('[data-action="delete-detail"]')) {
            e.target.closest('.detail-row').remove(); return;
        }

        // ── ADD / DELETE STEP ─────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-step"]')) {
            var btn = e.target.closest('[data-action="add-step"]');
            var lpi = btn.dataset.lpIndex;
            var list = document.getElementById('steps-' + lpi);
            var sort = list ? list.querySelectorAll('.step-row').length + 1 : 1;
            var row = document.createElement('div');
            row.className = 'step-row'; row.dataset.stepId = '0'; row.dataset.sort = sort;
            row.innerHTML = '<input type="text" class="step-input" placeholder="Step ' + sort + '">' +
                            '<button class="btn btn-danger btn-sm" data-action="delete-step">✕</button>';
            if (list) list.appendChild(row);
            return;
        }
        if (e.target.closest('[data-action="delete-step"]')) {
            e.target.closest('.step-row').remove(); return;
        }

        // ══════════════════════════════════════════════════════════════════════
        // KEY HIGHLIGHTS
        // ══════════════════════════════════════════════════════════════════════

        // ── SAVE KEY HIGHLIGHT ────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-kh"]')) {
            var row    = e.target.closest('.kh-row');
            var khId   = row.dataset.khId;
            var name   = row.querySelector('.kh-name').value.trim();
            var sort   = row.dataset.sort;
            if (!name) { showToast('⚠️ Name is required.'); return; }
            var fd = new FormData();
            fd.append('kh_id',      khId);
            fd.append('course_id',  courseId);
            fd.append('name',       name);
            fd.append('sort_order', sort);
            row.style.opacity = '0.5';
            fetch('/da360-admin/coursewise_api.php?action=save_key_highlight', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    row.style.opacity = '1';
                    if (d.success) {
                        row.dataset.khId = d.kh_id;
                        showToast('✅ Key Highlight saved!');
                    } else { showToast('❌ ' + (d.message || 'Error')); }
                }).catch(function(){ row.style.opacity = '1'; showToast('❌ Network error.'); });
            return;
        }

        // ── DELETE KEY HIGHLIGHT ──────────────────────────────────────────────
        if (e.target.closest('[data-action="delete-kh"]')) {
            var row  = e.target.closest('.kh-row');
            var khId = row.dataset.khId;
            if (khId && khId !== '0') {
                if (!confirm('Delete this key highlight?')) return;
                var fd = new FormData();
                fd.append('kh_id', khId); fd.append('course_id', courseId);
                fetch('/da360-admin/coursewise_api.php?action=delete_key_highlight', { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ if (d.success) { row.remove(); showToast('🗑️ Key Highlight removed.'); } else showToast('❌ ' + d.message); });
            } else { row.remove(); }
            return;
        }

        // ── ADD KEY HIGHLIGHT ─────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-kh"]')) {
            var container = document.getElementById('keyhighlights-container');
            var idx = container.querySelectorAll('.kh-row').length;
            var row = document.createElement('div');
            row.className = 'kh-row';
            row.dataset.khId = '0';
            row.dataset.sort = idx + 1;
            row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
            row.innerHTML =
                '<span style="width:24px;height:24px;border-radius:50%;background:#6366f1;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">' + (idx+1) + '</span>' +
                '<input type="text" class="kh-name" placeholder="e.g. SEO Auditing" style="flex:1;padding:8px 12px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:14px;">' +
                '<button class="btn btn-success btn-sm" data-action="save-kh">💾</button>' +
                '<button class="btn btn-danger btn-sm" data-action="delete-kh">🗑</button>';
            container.appendChild(row);
            row.querySelector('.kh-name').focus();
            return;
        }
            
        // ══════════════════════════════════════════════════════════════════════
        // COURSE INFO
        // ══════════════════════════════════════════════════════════════════════

        // ── ADD / DELETE LEAD TAG ─────────────────────────────────────────────
        if (e.target.closest('[data-action="add-lead-tag"]')) {
            var container = document.getElementById('lead-tags-container');
            var sort = container.querySelectorAll('.lead-tag-row').length + 1;
            var row = document.createElement('div');
            row.className = 'lead-tag-row'; row.dataset.sort = sort;
            row.innerHTML = '<input type="text" class="lead-tag-input" placeholder="Tag ' + sort + '">' +
                            '<button class="btn btn-danger btn-sm" data-action="delete-lead-tag">✕</button>';
            container.appendChild(row);
            return;
        }
        if (e.target.closest('[data-action="delete-lead-tag"]')) {
            e.target.closest('.lead-tag-row').remove(); return;
        }

        // ── SAVE COURSE INFO ──────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-course-info"]')) {
            var slugVal = document.getElementById('ci-course-id-slug').value.trim();
            var tags = [];
            document.querySelectorAll('#lead-tags-container .lead-tag-input').forEach(function(inp) {
                var v = inp.value.trim(); if (v) tags.push(v);
            });
            var fd = new FormData();
            fd.append('course_id', courseId);
            fd.append('course_id_slug', slugVal);
            fd.append('lead_tags', tags.join("\n"));
            fetch('/da360-admin/coursewise_api.php?action=save_course_info', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(d){ showToast(d.success ? '✅ Course Info saved!' : '❌ ' + (d.message||'Error')); })
                .catch(function(){ showToast('❌ Network error.'); });
            return;
        }

        // ══════════════════════════════════════════════════════════════════════
        // COHORT
        // ══════════════════════════════════════════════════════════════════════

        // ── SAVE COHORT ───────────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-cohort"]')) {
            var block  = e.target.closest('.cohort-block');
            var itemId = block.dataset.cohortId;
            var date     = block.querySelector('.cohort-date').value.trim();
            var mode     = block.querySelector('.cohort-mode').value.trim();
            var weekday  = block.querySelector('.cohort-weekday').value.trim();
            var capacity = block.querySelector('.cohort-capacity').value.trim();
            var campus   = block.querySelector('.cohort-campus').value.trim();
            if (!date) { showToast('⚠️ Date is required.'); return; }
            var fd = new FormData();
            fd.append('cohort_id',  itemId);
            fd.append('course_id',  courseId);
            fd.append('date',       date);
            fd.append('mode',       mode);
            fd.append('weekday',    weekday);
            fd.append('capacity',   capacity);
            fd.append('campus',     campus);
            fd.append('sort_order', parseInt(block.dataset.cohortIndex) + 1);
            block.classList.add('saving');
            fetch('/da360-admin/coursewise_api.php?action=save_cohort', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    block.classList.remove('saving');
                    if (d.success) {
                        block.dataset.cohortId = d.cohort_id;
                        block.querySelector('.cohort-label').textContent = date + ' — ' + campus + ' ' + mode;
                        block.classList.add('saved');
                        showToast('✅ Cohort saved!');
                        setTimeout(function(){ block.classList.remove('saved'); }, 2200);
                    } else { showToast('❌ ' + (d.message||'Error')); }
                }).catch(function(){ block.classList.remove('saving'); showToast('❌ Network error.'); });
            return;
        }

        // ── DELETE COHORT ─────────────────────────────────────────────────────
        if (e.target.closest('[data-action="delete-cohort"]')) {
            var block  = e.target.closest('.cohort-block');
            var itemId = block.dataset.cohortId;
            if (itemId && itemId !== '0') {
                if (!confirm('Delete this cohort?')) return;
                var fd = new FormData();
                fd.append('cohort_id', itemId); fd.append('course_id', courseId);
                fetch('/da360-admin/coursewise_api.php?action=delete_cohort', { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ if (d.success) { block.remove(); showToast('🗑️ Cohort removed.'); } else showToast('❌ ' + d.message); });
            } else { block.remove(); }
            return;
        }

        // ── ADD COHORT ────────────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-cohort"]')) {
            var container = document.getElementById('cohorts-container');
            var idx = container.querySelectorAll('.cohort-block').length;
            var block = document.createElement('div');
            block.className = 'cohort-block';
            block.dataset.cohortId    = '0';
            block.dataset.cohortIndex = idx;
            block.dataset.sort        = idx + 1;
            block.innerHTML =
                '<div class="cohort-header">' +
                  '<div style="display:flex;align-items:center;flex:1;">' +
                    '<div class="cohort-num">' + (idx+1) + '</div>' +
                    '<span class="cohort-label" style="margin-left:10px;">New Cohort</span>' +
                  '</div>' +
                  '<div style="display:flex;gap:8px;">' +
                    '<button class="btn btn-success btn-sm" data-action="save-cohort">💾 Save</button>' +
                    '<button class="btn btn-danger btn-sm" data-action="delete-cohort">🗑</button>' +
                  '</div>' +
                '</div>' +
                '<div class="cohort-body">' +
                  '<div class="field-3col">' +
                    '<div class="field-row"><label>Date</label><input type="text" class="cohort-date" placeholder="e.g. May 18th"></div>' +
                    '<div class="field-row"><label>Mode</label><input type="text" class="cohort-mode" placeholder="e.g. Classroom"></div>' +
                    '<div class="field-row"><label>Weekday</label><input type="text" class="cohort-weekday" placeholder="e.g. (Mon-Fri)"></div>' +
                  '</div>' +
                  '<div class="field-2col">' +
                    '<div class="field-row"><label>Capacity</label><input type="text" class="cohort-capacity" placeholder="e.g. 30 Seats"></div>' +
                    '<div class="field-row"><label>Campus</label><input type="text" class="cohort-campus" placeholder="e.g. Bengaluru"></div>' +
                  '</div>' +
                '</div>';
            container.appendChild(block);
            return;
        }

    };

    document.addEventListener('click', window._cwClickHandler, false);

})();
JSCODE;

        echo json_encode(['success' => true, 'html' => $html, 'js' => $js]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // UPLOAD IMAGE
    // POST /coursewise_api.php?action=upload_image
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $folder = preg_replace('/[^a-z0-9_-]/', '', strtolower($_POST['folder'] ?? 'misc'));
        $url    = handleImageUpload('image', $folder);
        if ($url) {
            echo json_encode(['success' => true, 'url' => $url]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Upload failed or invalid file type']);
        }
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE HIGHLIGHT
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_highlight' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $highlightId = (int)($_POST['highlight_id'] ?? 0);
        $courseId    = (int)($_POST['course_id']    ?? 0);
        $icon        = trim($_POST['icon']           ?? '');
        $title       = trim($_POST['title']          ?? '');
        $value       = trim($_POST['value']          ?? '');
        $sortOrder   = (int)($_POST['sort_order']    ?? 1);
        $updatedBy   = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        if (!$courseId || !$title) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']); exit;
        }

        if ($highlightId) {
            $stmt = $db->prepare("UPDATE course_highlights SET icon=?, title=?, value=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=? AND course_id=?");
            $stmt->execute([$icon, $title, $value, $sortOrder, $updatedBy, $highlightId, $courseId]);
        } else {
            $stmt = $db->prepare("INSERT INTO course_highlights (course_id, icon, title, value, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,NOW(),?)");
            $stmt->execute([$courseId, $icon, $title, $value, $sortOrder, $updatedBy]);
            $highlightId = (int)$db->lastInsertId();
        }
        echo json_encode(['success' => true, 'highlight_id' => $highlightId, 'message' => 'Highlight saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE HIGHLIGHT
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_highlight' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $highlightId = (int)($_POST['highlight_id'] ?? 0);
        $courseId    = (int)($_POST['course_id']    ?? 0);
        if (!$highlightId || !$courseId) { echo json_encode(['success' => false, 'message' => 'Invalid params']); exit; }
        $stmt = $db->prepare("DELETE FROM course_highlights WHERE id=? AND course_id=? LIMIT 1");
        $stmt->execute([$highlightId, $courseId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Highlight deleted']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE TOOL
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_tool' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $toolId    = (int)($_POST['tool_id']    ?? 0);
        $courseId  = (int)($_POST['course_id']  ?? 0);
        $name      = trim($_POST['name']        ?? '');
        $logo      = trim($_POST['logo']        ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 1);
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        if (!$courseId || !$name) { echo json_encode(['success' => false, 'message' => 'Missing required fields']); exit; }

        if ($toolId) {
            $stmt = $db->prepare("UPDATE course_tools SET name=?, logo=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=? AND course_id=?");
            $stmt->execute([$name, $logo, $sortOrder, $updatedBy, $toolId, $courseId]);
        } else {
            $stmt = $db->prepare("INSERT INTO course_tools (course_id, name, logo, sort_order, updated_at, updated_by) VALUES (?,?,?,?,NOW(),?)");
            $stmt->execute([$courseId, $name, $logo, $sortOrder, $updatedBy]);
            $toolId = (int)$db->lastInsertId();
        }
        echo json_encode(['success' => true, 'tool_id' => $toolId, 'message' => 'Tool saved']);
        exit;
    }
    

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE TOOL
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_tool' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $toolId   = (int)($_POST['tool_id']   ?? 0);
        $courseId = (int)($_POST['course_id'] ?? 0);
        if (!$toolId || !$courseId) { echo json_encode(['success' => false, 'message' => 'Invalid params']); exit; }
        $stmt = $db->prepare("DELETE FROM course_tools WHERE id=? AND course_id=? LIMIT 1");
        $stmt->execute([$toolId, $courseId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Tool deleted']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE KEY HIGHLIGHT
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_key_highlight' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $khId      = (int)($_POST['kh_id']      ?? 0);
        $courseId  = (int)($_POST['course_id']  ?? 0);
        $name      = trim($_POST['name']         ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 1);
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        if (!$courseId || !$name) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']); exit;
        }
        if ($khId) {
            $stmt = $db->prepare("UPDATE course_key_highlights SET name=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=? AND course_id=?");
            $stmt->execute([$name, $sortOrder, $updatedBy, $khId, $courseId]);
        } else {
            $stmt = $db->prepare("INSERT INTO course_key_highlights (course_id, name, sort_order, updated_at, updated_by) VALUES (?,?,?,NOW(),?)");
            $stmt->execute([$courseId, $name, $sortOrder, $updatedBy]);
            $khId = (int)$db->lastInsertId();
        }
        echo json_encode(['success' => true, 'kh_id' => $khId, 'message' => 'Key Highlight saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE KEY HIGHLIGHT
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_key_highlight' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $khId     = (int)($_POST['kh_id']     ?? 0);
        $courseId = (int)($_POST['course_id'] ?? 0);
        if (!$khId || !$courseId) {
            echo json_encode(['success' => false, 'message' => 'Invalid params']); exit;
        }
        $stmt = $db->prepare("DELETE FROM course_key_highlights WHERE id=? AND course_id=? LIMIT 1");
        $stmt->execute([$khId, $courseId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Key Highlight deleted']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE CASE STUDIES HEADING (location-specific)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_cs_heading' && $_SERVER['REQUEST_METHOD'] === 'POST') {
       
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $heading    = trim($_POST['heading']       ?? '');
        $subheading = trim($_POST['subheading']    ?? '');
        $updatedBy  = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        if (!$courseId) { echo json_encode(['success' => false, 'message' => 'Invalid params']); exit; }
        $stmt = $db->prepare("
            INSERT INTO course_casestudies_heading (course_id, heading, subheading, updated_at, updated_by)
            VALUES (?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE heading=VALUES(heading), subheading=VALUES(subheading), updated_at=NOW(), updated_by=VALUES(updated_by)
        ");
        $stmt->execute([$courseId, $heading, $subheading, $updatedBy]);
        echo json_encode(['success' => true, 'message' => 'Case Studies heading saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE CASE STUDY + TAGS (course-wide)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_casestudy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $csId      = (int)($_POST['casestudy_id'] ?? 0);
        $courseId  = (int)($_POST['course_id']    ?? 0);
        $logo      = trim($_POST['logo']           ?? '');
        $title     = trim($_POST['title']          ?? '');
        $desc      = trim($_POST['description']    ?? '');
        $sortOrder = (int)($_POST['sort_order']    ?? 1);
        $tagsJson  = $_POST['tags']                ?? '[]';
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';
        $tags      = json_decode($tagsJson, true) ?: [];

        if (!$courseId || !$title) { echo json_encode(['success' => false, 'message' => 'Missing required fields']); exit; }

        if ($csId) {
            $stmt = $db->prepare("UPDATE course_casestudies SET logo=?, title=?, description=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=? AND course_id=?");
            $stmt->execute([$logo, $title, $desc, $sortOrder, $updatedBy, $csId, $courseId]);
        } else {
            $stmt = $db->prepare("INSERT INTO course_casestudies (course_id, logo, title, description, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,NOW(),?)");
            $stmt->execute([$courseId, $logo, $title, $desc, $sortOrder, $updatedBy]);
            $csId = (int)$db->lastInsertId();
        }

        // Replace tags
        $db->prepare("DELETE FROM course_casestudy_tags WHERE casestudy_id=?")->execute([$csId]);
        $insTag = $db->prepare("INSERT INTO course_casestudy_tags (casestudy_id, tag, sort_order, updated_at, updated_by) VALUES (?,?,?,NOW(),?)");
        foreach ($tags as $t) {
            $tag = trim($t['tag'] ?? '');
            if ($tag === '') continue;
            $insTag->execute([$csId, $tag, (int)($t['sort_order'] ?? 1), $updatedBy]);
        }

        echo json_encode(['success' => true, 'casestudy_id' => $csId, 'message' => 'Case Study saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE CASE STUDY
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_casestudy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $csId     = (int)($_POST['casestudy_id'] ?? 0);
        $courseId = (int)($_POST['course_id']    ?? 0);
        if (!$csId || !$courseId) { echo json_encode(['success' => false, 'message' => 'Invalid params']); exit; }
        $db->prepare("DELETE FROM course_casestudy_tags WHERE casestudy_id=?")->execute([$csId]);
        $stmt = $db->prepare("DELETE FROM course_casestudies WHERE id=? AND course_id=? LIMIT 1");
        $stmt->execute([$csId, $courseId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Case Study deleted']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE LIVE PROJECTS HEADING (location-specific)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_lp_heading' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $section    = trim($_POST['section']       ?? '');
        $heading    = trim($_POST['heading']       ?? '');
        $desc       = trim($_POST['description']   ?? '');
        $updatedBy  = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        if (!$courseId) { echo json_encode(['success' => false, 'message' => 'Invalid params']); exit; }
        $stmt = $db->prepare("
            INSERT INTO course_liveprojects_heading (course_id, section, heading, description, updated_at, updated_by)
            VALUES (?,?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE section=VALUES(section), heading=VALUES(heading), description=VALUES(description), updated_at=NOW(), updated_by=VALUES(updated_by)
        ");
        $stmt->execute([$courseId, $section, $heading, $desc, $updatedBy]);
        echo json_encode(['success' => true, 'message' => 'Live Projects heading saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE LIVE PROJECT + LOGOS + DETAILS + STEPS (course-wide)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_liveproject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $projectId  = (int)($_POST['project_id']  ?? 0);
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $title      = trim($_POST['title']         ?? '');
        $duration   = trim($_POST['duration']      ?? '');
        $heading    = trim($_POST['heading']        ?? '');
        $note       = trim($_POST['note']           ?? '');
        $bgGradient = trim($_POST['bg_gradient']   ?? '');
        $bgSolid    = trim($_POST['bg_solid']      ?? '');
        $sortOrder  = (int)($_POST['sort_order']   ?? 1);
        $logosJson  = $_POST['logos']              ?? '[]';
        $detailsJson= $_POST['details']            ?? '[]';
        $stepsJson  = $_POST['steps']              ?? '[]';
        $updatedBy  = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        if (!$courseId || !$title) { echo json_encode(['success' => false, 'message' => 'Missing required fields']); exit; }

        $logos   = json_decode($logosJson,   true) ?: [];
        $details = json_decode($detailsJson, true) ?: [];
        $steps   = json_decode($stepsJson,   true) ?: [];

        if ($projectId) {
            $stmt = $db->prepare("UPDATE course_liveprojects SET title=?, duration=?, heading=?, note=?, bg_gradient=?, bg_solid=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=? AND course_id=?");
            $stmt->execute([$title, $duration, $heading, $note, $bgGradient, $bgSolid, $sortOrder, $updatedBy, $projectId, $courseId]);
        } else {
            $stmt = $db->prepare("INSERT INTO course_liveprojects (course_id, title, duration, heading, note, bg_gradient, bg_solid, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,?,?,?,NOW(),?)");
            $stmt->execute([$courseId, $title, $duration, $heading, $note, $bgGradient, $bgSolid, $sortOrder, $updatedBy]);
            $projectId = (int)$db->lastInsertId();
        }

        // Replace logos
        $db->prepare("DELETE FROM course_liveproject_logos WHERE project_id=?")->execute([$projectId]);
        $insLogo = $db->prepare("INSERT INTO course_liveproject_logos (project_id, logo, sort_order, updated_at, updated_by) VALUES (?,?,?,NOW(),?)");
        foreach ($logos as $l) {
            $logo = trim($l['logo'] ?? '');
            if ($logo === '') continue;
            $insLogo->execute([$projectId, $logo, (int)($l['sort_order'] ?? 1), $updatedBy]);
        }

        // Replace details
        $db->prepare("DELETE FROM course_liveproject_details WHERE project_id=?")->execute([$projectId]);
        $insDet = $db->prepare("INSERT INTO course_liveproject_details (project_id, detail, sort_order, updated_at, updated_by) VALUES (?,?,?,NOW(),?)");
        foreach ($details as $d) {
            $det = trim($d['detail'] ?? '');
            if ($det === '') continue;
            $insDet->execute([$projectId, $det, (int)($d['sort_order'] ?? 1), $updatedBy]);
        }

        // Replace steps
        $db->prepare("DELETE FROM course_liveproject_steps WHERE project_id=?")->execute([$projectId]);
        $insStep = $db->prepare("INSERT INTO course_liveproject_steps (project_id, step, sort_order, updated_at, updated_by) VALUES (?,?,?,NOW(),?)");
        foreach ($steps as $s) {
            $step = trim($s['step'] ?? '');
            if ($step === '') continue;
            $insStep->execute([$projectId, $step, (int)($s['sort_order'] ?? 1), $updatedBy]);
        }

        echo json_encode(['success' => true, 'project_id' => $projectId, 'message' => 'Live Project saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE LIVE PROJECT
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_liveproject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $courseId  = (int)($_POST['course_id']  ?? 0);
        if (!$projectId || !$courseId) { echo json_encode(['success' => false, 'message' => 'Invalid params']); exit; }
        $db->prepare("DELETE FROM course_liveproject_logos   WHERE project_id=?")->execute([$projectId]);
        $db->prepare("DELETE FROM course_liveproject_details WHERE project_id=?")->execute([$projectId]);
        $db->prepare("DELETE FROM course_liveproject_steps   WHERE project_id=?")->execute([$projectId]);
        $stmt = $db->prepare("DELETE FROM course_liveprojects WHERE id=? AND course_id=? LIMIT 1");
        $stmt->execute([$projectId, $courseId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Live Project deleted']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE COURSE INFO (courseId slug + lead capture tags)
    // POST /coursewise_api.php?action=save_course_info
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_course_info' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId     = (int)($_POST['course_id']      ?? 0);
        $slug         = trim($_POST['course_id_slug']  ?? '');
        $leadTags     = trim($_POST['lead_tags']        ?? '');
        $updatedBy    = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        if (!$courseId) { echo json_encode(['success' => false, 'message' => 'Missing course_id']); exit; }

        $stmt = $db->prepare("
            INSERT INTO course_info (course_id, course_id_slug, lead_tags, updated_at, updated_by)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                course_id_slug = VALUES(course_id_slug),
                lead_tags      = VALUES(lead_tags),
                updated_at     = NOW(),
                updated_by     = VALUES(updated_by)
        ");
        $stmt->execute([$courseId, $slug, $leadTags, $updatedBy]);
        echo json_encode(['success' => true, 'message' => 'Course Info saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE COHORT
    // POST /coursewise_api.php?action=save_cohort
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_cohort' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cohortId  = (int)($_POST['cohort_id']  ?? 0);
        $courseId  = (int)($_POST['course_id']  ?? 0);
        $date      = trim($_POST['date']         ?? '');
        $mode      = trim($_POST['mode']         ?? '');
        $weekday   = trim($_POST['weekday']      ?? '');
        $capacity  = trim($_POST['capacity']     ?? '');
        $campus    = trim($_POST['campus']       ?? '');
        $sortOrder = (int)($_POST['sort_order']  ?? 1);
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        if (!$courseId || !$date) { echo json_encode(['success' => false, 'message' => 'Missing required fields']); exit; }

        if ($cohortId) {
            $stmt = $db->prepare("UPDATE course_cohorts SET date=?, mode=?, weekday=?, capacity=?, campus=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=? AND course_id=?");
            $stmt->execute([$date, $mode, $weekday, $capacity, $campus, $sortOrder, $updatedBy, $cohortId, $courseId]);
        } else {
            $stmt = $db->prepare("INSERT INTO course_cohorts (course_id, date, mode, weekday, capacity, campus, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,?,?,NOW(),?)");
            $stmt->execute([$courseId, $date, $mode, $weekday, $capacity, $campus, $sortOrder, $updatedBy]);
            $cohortId = (int)$db->lastInsertId();
        }
        echo json_encode(['success' => true, 'cohort_id' => $cohortId, 'message' => 'Cohort saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE COHORT
    // POST /coursewise_api.php?action=delete_cohort
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_cohort' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $cohortId = (int)($_POST['cohort_id'] ?? 0);
        $courseId = (int)($_POST['course_id'] ?? 0);
        if (!$cohortId || !$courseId) { echo json_encode(['success' => false, 'message' => 'Invalid params']); exit; }
        $stmt = $db->prepare("DELETE FROM course_cohorts WHERE id=? AND course_id=? LIMIT 1");
        $stmt->execute([$cohortId, $courseId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Cohort deleted']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
