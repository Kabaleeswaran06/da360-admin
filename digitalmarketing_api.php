<?php
// ── CORS ──────────────────────────────────────────────────────────────────────
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
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
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

$action   = $_GET['action'] ?? '';
$db = getDB();
$locations = $db->query(
    "SELECT id, slug, label FROM locations WHERE is_active = 1 ORDER BY sort_order"
)->fetchAll(PDO::FETCH_ASSOC);

// Build dynamic $LOCS from slug column
$LOCS = array_column($locations, 'slug');
// $LOCS     = ['global', 'bangalore', 'jayanagar', 'jpnagar', 'malleshwaram'];
// ── Default sections (auto-created on first load per location) ─────────────────
$DEFAULT_SECTIONS = [
    ['section_id' => 'leadership-programs',    'section_title' => 'Digital Marketing Leadership Programs',        'component_type' => 'Leadership',    'sort_order' => 1],
    ['section_id' => 'pg-courses',             'section_title' => 'Post Graduate Certification Program Courses',  'component_type' => 'PostGraduate',  'sort_order' => 2],
    ['section_id' => 'certification-courses',  'section_title' => 'Certification Courses',                       'component_type' => 'Certification', 'sort_order' => 3],
];

$SECTION_META = [
    'Leadership'    => ['emoji' => '🎓', 'color' => '#7c3aed', 'bg' => '#f5f3ff'],
    'PostGraduate'  => ['emoji' => '📜', 'color' => '#2563eb', 'bg' => '#eff6ff'],
    'Certification' => ['emoji' => '🏅', 'color' => '#059669', 'bg' => '#f0fdf4'],
    'College'       => ['emoji' => '🏛️', 'color' => '#d97706', 'bg' => '#fffbeb'],
];

// ── Helper: fetch full section tree for a location ────────────────────────────
function fetchSectionsForLocation(PDO $db, string $location): array {
    $stmt = $db->prepare("SELECT * FROM dm_sections WHERE location = ? ORDER BY sort_order");
    $stmt->execute([$location]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sections as &$sec) {
        $s = $db->prepare("SELECT * FROM dm_courses WHERE section_db_id = ? ORDER BY sort_order");
        $s->execute([$sec['id']]);
        $courses = $s->fetchAll(PDO::FETCH_ASSOC);

        foreach ($courses as &$course) {
            $s2 = $db->prepare("SELECT * FROM dm_course_tags WHERE course_id = ? ORDER BY sort_order");
            $s2->execute([$course['id']]);
            $course['tags'] = $s2->fetchAll(PDO::FETCH_ASSOC);

            $s2 = $db->prepare("SELECT * FROM dm_course_features WHERE course_id = ? ORDER BY sort_order");
            $s2->execute([$course['id']]);
            $course['features'] = $s2->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($course);
        $sec['courses'] = $courses;
    }
    unset($sec);
    return $sections;
}

try {
    $db = getDB();

    // ══════════════════════════════════════════════════════════════════════════
    // GET DM JSON — for Next.js frontend
    // GET ?action=get_dm_json&location=bangalore&api_key=XXX
    // Returns: { success, content, courseData, faqs }
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_dm_json') {
        $location = trim($_GET['location'] ?? '');
        if (!in_array($location, $LOCS, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid location']); exit;
        }

        // Content
        $stmt = $db->prepare("SELECT * FROM dm_content WHERE location = ? LIMIT 1");
        $stmt->execute([$location]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $content = [
            'leadershipsubheading'    => $row['leadershipsubheading']    ?? '',
            'postgraduationsubheading'=> $row['postgraduationsubheading']?? '',
            'certificationsubheading' => $row['certificationsubheading'] ?? '',
            'bannersubheading'        => $row['bannersubheading']        ?? '',
            'successstoriesheading'   => $row['successstoriesheading']   ?? '',
            'successstoriessubheading'=> $row['successstoriessubheading']?? '',
            'guestfaculty'            => $row['guestfaculty']            ?? '',
            'communitymeetupslider'   => $row['communitymeetupslider']   ?? '',
            'lastestblog'             => $row['lastestblog']             ?? '',
        ];

        // Course Data
        $sections = fetchSectionsForLocation($db, $location);
        $courseData = [];
        foreach ($sections as $sec) {
            $courses = [];
            foreach ($sec['courses'] as $c) {
                $courses[] = [
                    'id'         => $c['course_key'],
                    'title'      => $c['title'],
                    'tags'       => array_values(array_column($c['tags'], 'tag')),
                    'features'   => array_values(array_column($c['features'], 'feature')),
                    'buttonText' => $c['button_text'],
                    'thumb'      => $c['thumb'],
                    'buttonLink' => $c['button_link'],
                ];
            }
            $courseData[] = [
                'sectionId'     => $sec['section_id'],
                'sectionTitle'  => $sec['section_title'],
                'componentType' => $sec['component_type'],
                'courses'       => $courses,
            ];
        }

        // FAQs
        // FAQs — grouped by label in the order the FAQTabs component expects
        $stmt = $db->prepare("SELECT label, question, answer FROM dm_faqs WHERE location = ? AND is_active = 1 ORDER BY sort_order");
        $stmt->execute([$location]);
        $faqRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labelOrder  = ['Program', 'Delivery', 'Placement', 'Certification', 'Fee'];
        $faqByLabel  = [];
        foreach ($faqRows as $fRow) {
            $faqByLabel[$fRow['label']][] = ['question' => $fRow['question'], 'answer' => $fRow['answer']];
        }
        $faqGroups = [];
        foreach ($labelOrder as $lbl) {
            if (!empty($faqByLabel[$lbl])) {
                $faqGroups[] = ['label' => $lbl, 'items' => $faqByLabel[$lbl]];
            }
        }

        echo json_encode(['success' => true, 'content' => $content, 'courseData' => $courseData, 'faqs' => $faqGroups, 'schemas' => (function() use ($db, $location) {
            $s = $db->prepare("SELECT schema_json FROM dm_schemas WHERE location = ? LIMIT 1");
            $s->execute([$location]);
            $r = $s->fetchColumn();
            if (!$r) return [];
            $d = json_decode($r, true);
            return is_array($d) ? $d : [];
        })()]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET DM HTML — admin editor
    // GET ?action=get_dm_html&location=bangalore
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_dm_html') {
        $location = trim($_GET['location'] ?? '');
        if (!in_array($location, $LOCS, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid location']); exit;
        }

        // ── Auto-create default sections if first time ────────────────────
        $stmt = $db->prepare("SELECT COUNT(*) FROM dm_sections WHERE location = ?");
        $stmt->execute([$location]);
        if ((int)$stmt->fetchColumn() === 0) {
            $ins = $db->prepare("INSERT INTO dm_sections (location, section_id, section_title, component_type, sort_order, updated_at) VALUES (?,?,?,?,?,NOW())");
            foreach ($DEFAULT_SECTIONS as $ds) {
                $ins->execute([$location, $ds['section_id'], $ds['section_title'], $ds['component_type'], $ds['sort_order']]);
            }
        }

        // ── Fetch content ─────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT * FROM dm_content WHERE location = ? LIMIT 1");
        $stmt->execute([$location]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // ── Fetch sections + courses ──────────────────────────────────────
        $sections = fetchSectionsForLocation($db, $location);

        // ── Fetch FAQs ────────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT id, label, question, answer, is_active, sort_order FROM dm_faqs WHERE location = ? ORDER BY label, sort_order");
        $stmt->execute([$location]);
        $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $locLabel = ucfirst($location === 'jpnagar' ? 'JP Nagar' : $location);

        ob_start(); ?>
<style>
    .dm *, .dm *::before, .dm *::after { box-sizing: border-box; }
    .dm { font-family: system-ui, sans-serif; color: #1e293b; }

    /* ── Tab bar ── */
    .dm .tab-bar { display:flex; gap:4px; border-bottom:2px solid #e2e8f0; margin-bottom:20px; flex-wrap:wrap; }
    .dm .tab-btn { padding:9px 18px; border:none; background:none; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; border-radius:4px 4px 0 0; transition:color .15s,border-color .15s; }
    .dm .tab-btn.active { color:#6366f1; border-bottom-color:#6366f1; }
    .dm .tab-pane { display:none; } .dm .tab-pane.active { display:block; }

    /* ── Section cards ── */
    .dm .section-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:18px; overflow:hidden; }
    .dm .section-body { padding:18px; }

    /* ── Fields ── */
    .dm label { display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px; text-transform:uppercase; letter-spacing:.4px; }
    .dm input[type=text], .dm textarea { width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:14px; color:#1e293b; background:#fff; transition:border-color .15s; }
    .dm input[type=text]:focus, .dm textarea:focus { border-color:#6366f1; outline:none; }
    .dm textarea { resize:vertical; min-height:72px; }
    .dm .field-row { margin-bottom:14px; }
    .dm .field-2col { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }

    /* ── Buttons ── */
    .dm .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:opacity .15s; }
    .dm .btn:hover { opacity:.85; }
    .dm .btn-primary { background:#6366f1; color:#fff; }
    .dm .btn-success { background:#22c55e; color:#fff; }
    .dm .btn-danger  { background:#ef4444; color:#fff; }
    .dm .btn-sm      { padding:5px 10px; font-size:12px; }
    .dm .btn-plus    { background:#e0f2fe; color:#0284c7; border:1.5px dashed #7dd3fc; }

    /* ── Course section blocks ── */
    .dm .cs-section { border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:22px; overflow:hidden; }
    .dm .cs-section-header { display:flex; align-items:center; gap:12px; padding:14px 18px; border-bottom:1px solid #e2e8f0; }
    .dm .cs-section-emoji { font-size:20px; }
    .dm .cs-section-type-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; }
    .dm .cs-section-title-input { flex:1; padding:7px 11px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:14px; color:#1e293b; }
    .dm .cs-section-title-input:focus { border-color:#6366f1; outline:none; }

    /* ── Course item blocks ── */
    .dm .item-block { border:1px solid #e2e8f0; border-radius:8px; margin:10px 18px; background:#fff; }
    .dm .item-header { display:flex; align-items:center; padding:11px 14px; cursor:pointer; gap:10px; }
    .dm .item-num { width:28px; height:28px; border-radius:50%; background:#6366f1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; }
    .dm .item-title-text { font-size:14px; font-weight:600; flex:1; }
    .dm .item-body { padding:16px; border-top:1px solid #f1f5f9; display:none; }
    .dm .item-body.open { display:block; }

    /* ── Tag / Feature / FAQ rows ── */
    .dm .detail-row { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
    .dm .detail-row input { flex:1; padding:7px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px; }
    .dm .detail-row input:focus { border-color:#6366f1; outline:none; }
    .dm .btn-del-sm { width:28px; height:28px; border:none; border-radius:5px; background:#fee2e2; color:#dc2626; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0; }

    /* ── FAQ block ── */
    .dm .faq-block { border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:12px; background:#fff; }
    .dm .faq-num { font-size:12px; font-weight:700; color:#6366f1; margin-bottom:10px; }

    /* ── States ── */
    .dm .saving { opacity:.5; pointer-events:none; }
    .dm .saved   { outline:2px solid #22c55e; }

    /* ── Schema editor (tab 4) ── */
    .dm .schema-editor { display:flex; flex-direction:column; gap:0; margin-top:4px; }
    .dm .schema-toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:12px 16px; background:#f9fafb; border:1px solid #e5e7eb; border-bottom:none; border-radius:10px 10px 0 0; }
    .dm .schema-toolbar-left  { display:flex; align-items:center; gap:10px; }
    .dm .schema-toolbar-right { display:flex; align-items:center; gap:10px; }
    .dm .schema-meta { font-size:0.8rem; color:#9ca3af; }
    .dm .schema-textarea { width:100%; min-height:560px; font-family:'Fira Code','Cascadia Code','Courier New',monospace; font-size:0.82rem; line-height:1.65; padding:16px; border:1px solid #e5e7eb; border-radius:0; background:#1e1e2e; color:#cdd6f4; resize:vertical; box-sizing:border-box; tab-size:2; outline:none; transition:border-color .15s; }
    .dm .schema-textarea:focus { border-color:#6366f1; }
    .dm .schema-footer { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; padding:12px 16px; background:#f9fafb; border:1px solid #e5e7eb; border-top:none; border-radius:0 0 10px 10px; }
    .dm .schema-footer-left  { display:flex; align-items:center; gap:10px; }
    .dm .schema-footer-right { display:flex; align-items:center; gap:10px; }
    .dm .json-status { font-size:0.82rem; font-weight:600; padding:3px 10px; border-radius:99px; }
    .dm .json-status.ok   { background:#dcfce7; color:#15803d; }
    .dm .json-status.err  { background:#fef2f2; color:#dc2626; }
    .dm .json-status.idle { background:#f3f4f6; color:#6b7280; }
    .dm .btn-secondary { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
    .dm .btn-sm { padding:6px 14px; font-size:0.82rem; border-radius:6px; }

    /* ── Toast ── */
    #dm-toast { position:fixed; bottom:28px; right:28px; z-index:9999; padding:12px 20px; border-radius:10px; font-size:14px; font-weight:600; background:#1e293b; color:#fff; box-shadow:0 4px 20px rgba(0,0,0,.2); opacity:0; transform:translateY(10px); transition:opacity .25s,transform .25s; pointer-events:none; }
    #dm-toast.show { opacity:1; transform:translateY(0); }
</style>

<div class="dm" id="dm-root" data-location="<?= htmlspecialchars($location) ?>">

  <div class="result-header animate-fadeup">
    <div class="result-meta">
      <span class="meta-pill accent">Digital Marketing Course</span>
      <span class="meta-pill"><?= htmlspecialchars($locLabel) ?></span>
    </div>
  </div>

  <!-- ── Tab bar ── -->
  <div class="tab-bar">
    <button class="tab-btn active" data-tab="content">📝 Content</button>
    <button class="tab-btn"        data-tab="coursedata">📚 Course Data</button>
    <button class="tab-btn"        data-tab="faqs">❓ FAQs</button>
    <button class="tab-btn"        data-tab="schema">🧩 Schema</button>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════════
       TAB 1 — CONTENT (subheadings)
  ═══════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane active" id="tab-pane-content">
    <div class="section-card">
      <div class="section-body">

        <div class="field-row">
          <label>Leadership Subheading</label>
          <textarea id="dm-leadershipsubheading" rows="2"><?= htmlspecialchars($content['leadershipsubheading'] ?? '') ?></textarea>
        </div>
        <div class="field-row">
          <label>Post Graduation Subheading</label>
          <textarea id="dm-postgraduationsubheading" rows="2"><?= htmlspecialchars($content['postgraduationsubheading'] ?? '') ?></textarea>
        </div>
        <div class="field-row">
          <label>Certification Subheading</label>
          <textarea id="dm-certificationsubheading" rows="2"><?= htmlspecialchars($content['certificationsubheading'] ?? '') ?></textarea>
        </div>
        <div class="field-row">
          <label>Banner Subheading</label>
          <textarea id="dm-bannersubheading" rows="2"><?= htmlspecialchars($content['bannersubheading'] ?? '') ?></textarea>
        </div>
        <div class="field-2col">
          <div class="field-row">
            <label>Success Stories Heading</label>
            <input type="text" id="dm-successstoriesheading" value="<?= htmlspecialchars($content['successstoriesheading'] ?? '') ?>" placeholder="e.g. Our Alumni Achievements">
          </div>
          <div class="field-row">
            <label>Latest Blog Heading</label>
            <input type="text" id="dm-lastestblog" value="<?= htmlspecialchars($content['lastestblog'] ?? '') ?>" placeholder="e.g. Latest Blogs on Digital Marketing Courses">
          </div>
        </div>
        <div class="field-row">
          <label>Success Stories Subheading</label>
          <textarea id="dm-successstoriessubheading" rows="2"><?= htmlspecialchars($content['successstoriessubheading'] ?? '') ?></textarea>
        </div>
        <div class="field-row">
          <label>Guest Faculty</label>
          <textarea id="dm-guestfaculty" rows="2"><?= htmlspecialchars($content['guestfaculty'] ?? '') ?></textarea>
        </div>
        <div class="field-row">
          <label>Community Meetup Slider</label>
          <textarea id="dm-communitymeetupslider" rows="3"><?= htmlspecialchars($content['communitymeetupslider'] ?? '') ?></textarea>
        </div>

        <button class="btn btn-primary" data-action="save-content">💾 Save Content</button>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════════
       TAB 2 — COURSE DATA
  ═══════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-pane-coursedata">
<?php foreach ($sections as $si => $sec):
    $ct   = $sec['component_type'];
    $meta = $SECTION_META[$ct] ?? ['emoji' => '📁', 'color' => '#6366f1', 'bg' => '#f5f3ff'];
?>
    <div class="cs-section" data-section-db-id="<?= (int)$sec['id'] ?>">
      <div class="cs-section-header" style="background:<?= $meta['bg'] ?>;">
        <span class="cs-section-emoji"><?= $meta['emoji'] ?></span>
        <span class="cs-section-type-badge" style="background:<?= $meta['color'] ?>22;color:<?= $meta['color'] ?>;"><?= htmlspecialchars($ct) ?></span>
        <input type="text" class="cs-section-title-input" value="<?= htmlspecialchars($sec['section_title']) ?>" placeholder="Section title">
        <button class="btn btn-success btn-sm" data-action="save-section">💾 Save Title</button>
      </div>

      <div style="padding:6px 0 12px;">
<?php foreach ($sec['courses'] as $ci => $course):
    $tagsJson = htmlspecialchars(json_encode(array_column($course['tags'], 'tag')), ENT_QUOTES);
    $featsJson = htmlspecialchars(json_encode(array_column($course['features'], 'feature')), ENT_QUOTES);
?>
        <div class="item-block" data-course-id="<?= (int)$course['id'] ?>" data-sort="<?= $ci+1 ?>">
          <div class="item-header" data-toggle-course="<?= $ci ?>-<?= $si ?>">
            <div class="item-num"><?= $ci+1 ?></div>
            <span class="item-title-text"><?= htmlspecialchars($course['title']) ?></span>
            <div style="display:flex;gap:8px;flex-shrink:0;">
              <button class="btn btn-success btn-sm" data-action="save-course">💾 Save</button>
              <button class="btn btn-danger btn-sm"  data-action="delete-course">🗑</button>
            </div>
          </div>
          <div class="item-body" id="course-body-<?= $ci ?>-<?= $si ?>">
            <div class="field-2col">
              <div class="field-row">
                <label>Course Key (ID)</label>
                <input type="text" class="dc-key" value="<?= htmlspecialchars($course['course_key']) ?>" placeholder="e.g. digital-marketing-ai-entrepreneurship">
              </div>
              <div class="field-row">
                <label>Button Text</label>
                <input type="text" class="dc-btntext" value="<?= htmlspecialchars($course['button_text']) ?>" placeholder="View Course">
              </div>
            </div>
            <div class="field-row">
              <label>Title</label>
              <input type="text" class="dc-title" value="<?= htmlspecialchars($course['title']) ?>" placeholder="Course title">
            </div>
            <div class="field-2col">
              <div class="field-row">
                <label>Thumbnail URL</label>
                <input type="text" class="dc-thumb" value="<?= htmlspecialchars($course['thumb']) ?>" placeholder="/images/course-list/...">
              </div>
              <div class="field-row">
                <label>Button Link</label>
                <input type="text" class="dc-link" value="<?= htmlspecialchars($course['button_link']) ?>" placeholder="/course-slug">
              </div>
            </div>
            <div class="field-row">
              <label>Tags</label>
              <div class="dc-tags-list">
<?php foreach ($course['tags'] as $ti => $tag): ?>
                <div class="detail-row dc-tag-row" data-tag-id="<?= (int)$tag['id'] ?>">
                  <input type="text" class="dc-tag-input" value="<?= htmlspecialchars($tag['tag']) ?>" placeholder="e.g. 12 Months">
                  <button class="btn-del-sm" data-action="del-tag">✕</button>
                </div>
<?php endforeach; ?>
              </div>
              <button class="btn btn-plus btn-sm" data-action="add-tag" style="margin-top:6px;">＋ Add Tag</button>
            </div>
            <div class="field-row">
              <label>Features</label>
              <div class="dc-features-list">
<?php foreach ($course['features'] as $fi => $feat): ?>
                <div class="detail-row dc-feat-row" data-feat-id="<?= (int)$feat['id'] ?>">
                  <input type="text" class="dc-feat-input" value="<?= htmlspecialchars($feat['feature']) ?>" placeholder="e.g. 240+ Hours of Learning">
                  <button class="btn-del-sm" data-action="del-feat">✕</button>
                </div>
<?php endforeach; ?>
              </div>
              <button class="btn btn-plus btn-sm" data-action="add-feat" style="margin-top:6px;">＋ Add Feature</button>
            </div>
          </div>
        </div>
<?php endforeach; ?>

        <div style="padding:4px 18px 6px;">
          <button class="btn btn-plus btn-sm" data-action="add-course" data-section-db-id="<?= (int)$sec['id'] ?>">＋ Add Course</button>
        </div>
      </div>
    </div>
<?php endforeach; ?>
  </div>

  <!-- ══════════════════════════════════════════════════════════════════════
       TAB 3 — FAQs  (same panel structure as faq_api.php)
  ═══════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-pane-faqs">

    <style>
    /* ── scoped FAQ panel styles (mirrors faq_api.php .fmr) ── */
    .dm-fmr *, .dm-fmr *::before, .dm-fmr *::after { box-sizing:border-box; }
    .dm-fmr { font-family:system-ui,sans-serif; }
    .dm-fmr .faq-categories { display:flex; flex-direction:column; gap:12px; }
    .dm-fmr .cat-panel { border-radius:12px; border:1.5px solid #e2e8f0; overflow:hidden; transition:box-shadow .2s; }
    .dm-fmr .cat-panel.open { box-shadow:0 4px 20px rgba(0,0,0,.08); border-color:var(--cat-accent,#6366f1); }
    .dm-fmr .cat-header { display:flex; align-items:center; gap:14px; padding:14px 18px; cursor:pointer; user-select:none; background:#f8fafc; border-left:4px solid var(--cat-accent,#6366f1); transition:background .15s; }
    .dm-fmr .cat-header:hover { background:#f1f5f9; }
    .dm-fmr .cat-panel.open .cat-header { background:color-mix(in srgb,var(--cat-accent,#6366f1) 8%,#f8fafc); }
    .dm-fmr .cat-icon { font-size:18px; flex-shrink:0; }
    .dm-fmr .cat-title { font-size:14px; font-weight:700; color:#1e293b; flex:1; }
    .dm-fmr .cat-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; background:color-mix(in srgb,var(--cat-accent,#6366f1) 12%,#fff); color:var(--cat-accent,#6366f1); border:1px solid color-mix(in srgb,var(--cat-accent,#6366f1) 30%,transparent); }
    .dm-fmr .cat-chevron { width:18px; height:18px; color:#94a3b8; transition:transform .25s,color .2s; flex-shrink:0; }
    .dm-fmr .cat-panel.open .cat-chevron { transform:rotate(180deg); color:var(--cat-accent,#6366f1); }
    .dm-fmr .cat-body { max-height:0; overflow:hidden; transition:max-height .38s cubic-bezier(.4,0,.2,1); background:#fff; }
    .dm-fmr .cat-panel.open .cat-body { max-height:5000px; }
    .dm-fmr .faq-table-wrap { overflow-x:auto; padding:14px 14px 0; }
    .dm-fmr .faq-table { width:100%; border-collapse:collapse; font-size:13px; }
    .dm-fmr .faq-table thead th { background:#f8fafc; color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; padding:9px 12px; text-align:left; border-bottom:1px solid #e2e8f0; }
    .dm-fmr .faq-row td { padding:9px 12px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    .dm-fmr .faq-row:last-child td { border-bottom:none; }
    .dm-fmr .faq-row.has-data { border-left:3px solid var(--cat-accent,#6366f1); }
    .dm-fmr .faq-row.saving { opacity:.5; pointer-events:none; }
    .dm-fmr .faq-row.saved  { background:rgba(34,197,94,.06) !important; }
    .dm-fmr .faq-row.errored{ background:rgba(239,68,68,.06) !important; }
    .dm-fmr .sort-num { color:var(--cat-accent,#6366f1); font-weight:700; font-size:13px; width:32px; text-align:center; padding-top:14px; }
    .dm-fmr .faq-textarea { width:100%; background:#fff; border:1.5px solid #cbd5e1; border-radius:7px; color:#1e293b; font-size:13px; line-height:1.6; padding:8px 11px; resize:vertical; font-family:inherit; transition:border-color .15s; }
    .dm-fmr .faq-textarea:focus { outline:none; border-color:var(--cat-accent,#6366f1); }
    .dm-fmr .toggle-switch { position:relative; display:inline-block; width:38px; height:20px; }
    .dm-fmr .toggle-switch input { opacity:0; width:0; height:0; }
    .dm-fmr .toggle-slider { position:absolute; inset:0; background:#cbd5e1; border-radius:34px; cursor:pointer; transition:background .2s; }
    .dm-fmr .toggle-slider::before { content:''; position:absolute; width:14px; height:14px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:transform .2s; }
    .dm-fmr .toggle-switch input:checked + .toggle-slider { background:#22c55e; }
    .dm-fmr .toggle-switch input:checked + .toggle-slider::before { transform:translateX(18px); }
    .dm-fmr .td-actions { text-align:center; white-space:nowrap; }
    .dm-fmr .btn-save-row, .dm-fmr .btn-delete-row { display:inline-flex; align-items:center; gap:4px; padding:5px 10px; border-radius:6px; border:none; font-size:12px; font-weight:600; cursor:pointer; transition:all .15s; margin:2px 0; }
    .dm-fmr .btn-save-row { background:color-mix(in srgb,var(--cat-accent,#6366f1) 12%,#fff); color:var(--cat-accent,#6366f1); border:1px solid color-mix(in srgb,var(--cat-accent,#6366f1) 25%,transparent); }
    .dm-fmr .btn-save-row:hover { background:var(--cat-accent,#6366f1); color:#fff; }
    .dm-fmr .btn-delete-row { background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; }
    .dm-fmr .btn-delete-row:hover { background:#dc2626; color:#fff; }
    .dm-fmr .faq-bulk-bar { display:flex; align-items:center; gap:14px; padding:12px 18px; background:#f8fafc; border-top:1px solid #e2e8f0; }
    .dm-fmr .btn-bulk-save { display:inline-flex; align-items:center; gap:6px; padding:8px 18px; border-radius:7px; border:none; font-size:13px; font-weight:700; cursor:pointer; background:var(--cat-accent,#6366f1); color:#fff; transition:opacity .2s; }
    .dm-fmr .btn-bulk-save:hover { opacity:.85; }
    .dm-fmr .save-hint { font-size:12px; color:#94a3b8; }
    .dm-fmr .dm-faq-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
    .dm-fmr .stat-chip { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:7px 14px; font-size:13px; color:#334155; display:flex; align-items:center; gap:5px; }
    </style>

    <div class="dm-fmr">
<?php
    $labels     = ['Program','Delivery','Placement','Certification','Fee'];
    $catAccents = ['Program'=>'#0ea5e9','Delivery'=>'#8b5cf6','Placement'=>'#22c55e','Certification'=>'#f59e0b','Fee'=>'#f43f5e'];
    $catIcons   = ['Program'=>'📚','Delivery'=>'🚚','Placement'=>'🎯','Certification'=>'🏅','Fee'=>'💳'];

    // Build faqGrid[label][sort_order] = row
    $faqGrid = [];
    foreach ($faqs as $faqRow) {
        $faqGrid[$faqRow['label']][$faqRow['sort_order']] = $faqRow;
    }
    $totalSlots  = count($labels) * 10;
    $filledSlots = count($faqs);
?>
      <div class="dm-faq-stats">
        <div class="stat-chip"><b><?= $totalSlots ?></b>&nbsp;Total Slots</div>
        <div class="stat-chip"><b><?= $filledSlots ?></b>&nbsp;Filled</div>
        <div class="stat-chip"><b><?= $totalSlots - $filledSlots ?></b>&nbsp;Empty</div>
        <div class="stat-chip"><b><?= count($labels) ?></b>&nbsp;Categories</div>
      </div>

      <div class="faq-categories">
      <?php foreach ($labels as $li => $label):
          $accent  = $catAccents[$label] ?? '#6366f1';
          $icon    = $catIcons[$label]   ?? '📁';
          $filled  = isset($faqGrid[$label]) ? count($faqGrid[$label]) : 0;
          $isOpen  = ($li === 0);
      ?>
        <div class="cat-panel <?= $isOpen ? 'open' : '' ?>"
             id="dm-cat-panel-<?= $label ?>"
             style="--cat-accent:<?= $accent ?>">

          <div class="cat-header" onclick="dmToggleCatPanel('<?= $label ?>')">
            <span class="cat-icon"><?= $icon ?></span>
            <span class="cat-title"><?= $label ?></span>
            <span class="cat-badge" id="dm-badge-<?= $label ?>"><?= $filled ?>/10 filled</span>
            <svg class="cat-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </div>

          <div class="cat-body">
            <div class="faq-table-wrap">
              <table class="faq-table">
                <thead>
                  <tr>
                    <th style="width:36px">#</th>
                    <th>Question</th>
                    <th>Answer</th>
                    <th style="width:70px;text-align:center">Active</th>
                    <th style="width:110px;text-align:center">Actions</th>
                  </tr>
                </thead>
                <tbody id="dm-tbody-<?= $label ?>">
<?php for ($n = 1; $n <= 10; $n++):
    $fRow     = $faqGrid[$label][$n] ?? null;
    $faqId    = $fRow ? (int)$fRow['id'] : 0;
    $question = $fRow ? htmlspecialchars($fRow['question']) : '';
    $answer   = $fRow ? htmlspecialchars($fRow['answer'])   : '';
    $active   = $fRow ? (int)($fRow['is_active'] ?? 1) : 1;
    $hasData  = $fRow !== null;
?>
                  <tr class="faq-row <?= $hasData ? 'has-data' : 'empty-row' ?>"
                      style="--cat-accent:<?= $accent ?>"
                      data-id="<?= $faqId ?>"
                      data-loc="<?= htmlspecialchars($location) ?>"
                      data-cat="<?= $label ?>"
                      data-sort="<?= $n ?>">
                    <td class="sort-num"><?= $n ?></td>
                    <td><textarea class="faq-textarea faq-question" rows="3" placeholder="Question <?= $n ?>…"><?= $question ?></textarea></td>
                    <td><textarea class="faq-textarea faq-answer"   rows="3" placeholder="Answer <?= $n ?>…"><?= $answer ?></textarea></td>
                    <td style="text-align:center;padding-top:14px;">
                      <label class="toggle-switch">
                        <input type="checkbox" class="faq-active" <?= $active ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                      </label>
                    </td>
                    <td class="td-actions">
                      <button class="btn-save-row" onclick="dmSaveFaqRow(this)" title="Save">💾 Save</button>
                      <?php if ($hasData): ?>
                      <button class="btn-delete-row" onclick="dmDeleteFaqRow(this)" title="Delete">🗑️ Del</button>
                      <?php endif; ?>
                    </td>
                  </tr>
<?php endfor; ?>
                </tbody>
              </table>
            </div>
            <div class="faq-bulk-bar" style="--cat-accent:<?= $accent ?>">
              <button class="btn-bulk-save" onclick="dmSaveAllInCategory('<?= $label ?>')">
                💾 Save All in <?= $label ?>
              </button>
              <span class="save-hint">Saves all 10 rows in this section at once.</span>
            </div>
          </div><!-- /.cat-body -->
        </div><!-- /.cat-panel -->
      <?php endforeach; ?>
      </div><!-- /.faq-categories -->
    </div><!-- /.dm-fmr -->
  </div>

  <!-- ══════════════════════════════════════════════════════════════════════
       TAB 4 — SCHEMA (JSON-LD)
  ═══════════════════════════════════════════════════════════════════════ -->
  <div class="tab-pane" id="tab-pane-schema">
<?php
    // Load existing schema for this location
    $schStmt = $db->prepare("SELECT schema_json, updated_at, updated_by FROM dm_schemas WHERE location = ? LIMIT 1");
    $schStmt->execute([$location]);
    $schRow     = $schStmt->fetch(PDO::FETCH_ASSOC);
    $schJson    = '[]';
    if ($schRow && !empty($schRow['schema_json'])) {
        $decoded = json_decode($schRow['schema_json']);
        if ($decoded !== null) {
            $schJson = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
    $schMeta = $schRow
        ? 'Last saved: ' . htmlspecialchars($schRow['updated_at']) . ' by ' . htmlspecialchars($schRow['updated_by'] ?? '—')
        : 'No schema saved yet — paste your JSON-LD array below.';
?>
    <div class="schema-editor" style="margin-top:4px;">

      <div class="schema-toolbar">
        <div class="schema-toolbar-left">
          <strong style="font-size:0.9rem">📋 JSON-LD Schema Array</strong>
          <span class="json-status idle" id="dm-schema-status">—</span>
        </div>
        <div class="schema-toolbar-right">
          <span class="schema-meta" id="dm-schema-meta"><?= $schMeta ?></span>
          <button class="btn btn-secondary btn-sm" id="dm-schema-format-btn">⚡ Format</button>
        </div>
      </div>

      <textarea
        class="schema-textarea"
        id="dm-schema-textarea"
        spellcheck="false"
        placeholder='[\n  {\n    "@context": "https://schema.org",\n    "@type": "Course",\n    ...\n  }\n]'
      ><?= htmlspecialchars($schJson) ?></textarea>

      <div class="schema-footer">
        <div class="schema-footer-left">
          <button class="btn btn-secondary btn-sm" id="dm-schema-count-btn">🔢 Count schemas</button>
        </div>
        <div class="schema-footer-right">
          <button class="btn btn-primary" id="dm-schema-save-btn">💾 Save Schema</button>
        </div>
      </div>

    </div><!-- /.schema-editor -->
  </div><!-- /#tab-pane-schema -->

</div><!-- /.dm -->
<div id="dm-toast"></div>
<?php
        $html = ob_get_clean();

        // ── Inline JS ─────────────────────────────────────────────────────
        $locationJs = json_encode($location);
        $js = <<<JSCODE
(function () {

    var location = {$locationJs};

    // ── Remove previous listener if re-loaded ─────────────────────────────
    if (window._dmClickHandler) {
        document.removeEventListener('click', window._dmClickHandler, false);
    }

    // ── Toast ─────────────────────────────────────────────────────────────
    function showToast(msg) {
        var t = document.getElementById('dm-toast');
        if (!t) return;
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.classList.remove('show'); }, 2600);
    }

    // ── Tab switching ─────────────────────────────────────────────────────
    document.querySelectorAll('.dm .tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.dm .tab-btn').forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.dm .tab-pane').forEach(function (p) { p.classList.remove('active'); });
            this.classList.add('active');
            var pane = document.getElementById('tab-pane-' + this.dataset.tab);
            if (pane) pane.classList.add('active');
        });
    });

    // ── Course body toggle ────────────────────────────────────────────────
    document.querySelectorAll('.dm .item-header[data-toggle-course]').forEach(function (hdr) {
        hdr.addEventListener('click', function (e) {
            if (e.target.closest('.btn')) return;
            var key  = this.dataset.toggleCourse;
            var body = document.getElementById('course-body-' + key);
            if (body) body.classList.toggle('open');
        });
    });

    window._dmClickHandler = function (e) {

        // ── SAVE CONTENT ──────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-content"]')) {
            var fd = new FormData();
            fd.append('location', location);
            ['leadershipsubheading','postgraduationsubheading','certificationsubheading',
             'bannersubheading','successstoriesheading','successstoriessubheading',
             'guestfaculty','communitymeetupslider','lastestblog'].forEach(function(k) {
                var el = document.getElementById('dm-' + k);
                fd.append(k, el ? el.value.trim() : '');
            });
            var btn = e.target.closest('[data-action="save-content"]');
            btn.classList.add('saving');
            fetch('/da360-admin/digitalmarketing_api.php?action=save_dm_content', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    btn.classList.remove('saving');
                    if (d.success) { btn.classList.add('saved'); showToast('✅ Content saved!'); setTimeout(function(){ btn.classList.remove('saved'); }, 2200); }
                    else showToast('❌ ' + (d.message || 'Error'));
                }).catch(function(){ btn.classList.remove('saving'); showToast('❌ Network error'); });
            return;
        }

        // ── SAVE SECTION TITLE ────────────────────────────────────────────
        if (e.target.closest('[data-action="save-section"]')) {
            var secBlock = e.target.closest('.cs-section');
            var secId    = secBlock.dataset.sectionDbId;
            var title    = secBlock.querySelector('.cs-section-title-input').value.trim();
            if (!title) { showToast('⚠️ Section title is required'); return; }
            var fd = new FormData();
            fd.append('section_db_id', secId);
            fd.append('section_title', title);
            fd.append('location', location);
            fetch('/da360-admin/digitalmarketing_api.php?action=save_dm_section', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(d){ if (d.success) showToast('✅ Section title saved!'); else showToast('❌ ' + (d.message||'Error')); });
            return;
        }

        // ── SAVE COURSE ───────────────────────────────────────────────────
        if (e.target.closest('[data-action="save-course"]')) {
            var block    = e.target.closest('.item-block');
            var courseId = block.dataset.courseId;
            var secBlock = block.closest('.cs-section');
            var secDbId  = secBlock.dataset.sectionDbId;
            var title    = block.querySelector('.dc-title').value.trim();
            if (!title) { showToast('⚠️ Title is required'); return; }

            var tags = [];
            block.querySelectorAll('.dc-tag-input').forEach(function(inp, i) {
                var v = inp.value.trim(); if (v) tags.push({ tag: v, sort_order: i+1 });
            });
            var feats = [];
            block.querySelectorAll('.dc-feat-input').forEach(function(inp, i) {
                var v = inp.value.trim(); if (v) feats.push({ feature: v, sort_order: i+1 });
            });

            var fd = new FormData();
            fd.append('course_id',     courseId);
            fd.append('section_db_id', secDbId);
            fd.append('location',      location);
            fd.append('course_key',    block.querySelector('.dc-key').value.trim());
            fd.append('title',         title);
            fd.append('button_text',   block.querySelector('.dc-btntext').value.trim() || 'View Course');
            fd.append('thumb',         block.querySelector('.dc-thumb').value.trim());
            fd.append('button_link',   block.querySelector('.dc-link').value.trim());
            fd.append('sort_order',    block.dataset.sort || 1);
            fd.append('tags',          JSON.stringify(tags));
            fd.append('features',      JSON.stringify(feats));

            block.classList.add('saving');
            fetch('/da360-admin/digitalmarketing_api.php?action=save_dm_course', { method:'POST', body:fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    block.classList.remove('saving');
                    if (d.success) {
                        block.dataset.courseId = d.course_id;
                        block.querySelector('.item-title-text').textContent = title;
                        block.classList.add('saved');
                        showToast('✅ Course saved!');
                        setTimeout(function(){ block.classList.remove('saved'); }, 2200);
                    } else showToast('❌ ' + (d.message||'Error'));
                }).catch(function(){ block.classList.remove('saving'); showToast('❌ Network error'); });
            return;
        }

        // ── DELETE COURSE ─────────────────────────────────────────────────
        if (e.target.closest('[data-action="delete-course"]')) {
            var block    = e.target.closest('.item-block');
            var courseId = block.dataset.courseId;
            if (courseId && courseId !== '0') {
                if (!confirm('Delete this course?')) return;
                var fd = new FormData();
                fd.append('course_id', courseId);
                fd.append('location',  location);
                fetch('/da360-admin/digitalmarketing_api.php?action=delete_dm_course', { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ if (d.success) { block.remove(); showToast('🗑️ Course deleted'); } else showToast('❌ ' + d.message); });
            } else { block.remove(); }
            return;
        }

        // ── ADD COURSE ────────────────────────────────────────────────────
        if (e.target.closest('[data-action="add-course"]')) {
            var btn      = e.target.closest('[data-action="add-course"]');
            var secBlock = btn.closest('.cs-section');
            var secDbId  = btn.dataset.sectionDbId;
            var container = secBlock.querySelector('.item-block:last-of-type')?.parentNode || btn.parentNode;
            var idx = secBlock.querySelectorAll('.item-block').length;

            var block = document.createElement('div');
            block.className = 'item-block';
            block.dataset.courseId = '0';
            block.dataset.sort = idx + 1;
            var uid = Date.now();
            block.innerHTML =
                '<div class="item-header" data-toggle-course="new-' + uid + '">' +
                  '<div class="item-num">' + (idx+1) + '</div>' +
                  '<span class="item-title-text">New Course</span>' +
                  '<div style="display:flex;gap:8px;flex-shrink:0;">' +
                    '<button class="btn btn-success btn-sm" data-action="save-course">💾 Save</button>' +
                    '<button class="btn btn-danger btn-sm" data-action="delete-course">🗑</button>' +
                  '</div>' +
                '</div>' +
                '<div class="item-body open" id="course-body-new-' + uid + '">' +
                  '<div class="field-2col">' +
                    '<div class="field-row"><label>Course Key (ID)</label><input type="text" class="dc-key" placeholder="e.g. course-slug-id"></div>' +
                    '<div class="field-row"><label>Button Text</label><input type="text" class="dc-btntext" value="View Course"></div>' +
                  '</div>' +
                  '<div class="field-row"><label>Title</label><input type="text" class="dc-title" placeholder="Course title"></div>' +
                  '<div class="field-2col">' +
                    '<div class="field-row"><label>Thumbnail URL</label><input type="text" class="dc-thumb" placeholder="/images/course-list/..."></div>' +
                    '<div class="field-row"><label>Button Link</label><input type="text" class="dc-link" placeholder="/course-slug"></div>' +
                  '</div>' +
                  '<div class="field-row"><label>Tags</label>' +
                    '<div class="dc-tags-list"></div>' +
                    '<button class="btn btn-plus btn-sm" data-action="add-tag" style="margin-top:6px;">＋ Add Tag</button>' +
                  '</div>' +
                  '<div class="field-row"><label>Features</label>' +
                    '<div class="dc-features-list"></div>' +
                    '<button class="btn btn-plus btn-sm" data-action="add-feat" style="margin-top:6px;">＋ Add Feature</button>' +
                  '</div>' +
                '</div>';
            btn.parentNode.insertBefore(block, btn);

            block.querySelector('.item-header').addEventListener('click', function(ev) {
                if (ev.target.closest('.btn')) return;
                var body = document.getElementById('course-body-new-' + uid);
                if (body) body.classList.toggle('open');
            });
            return;
        }

        // ── ADD / DELETE TAG ──────────────────────────────────────────────
        if (e.target.closest('[data-action="add-tag"]')) {
            var list = e.target.closest('.field-row').querySelector('.dc-tags-list');
            var row = document.createElement('div');
            row.className = 'detail-row dc-tag-row';
            row.dataset.tagId = '0';
            row.innerHTML = '<input type="text" class="dc-tag-input" placeholder="e.g. 12 Months"><button class="btn-del-sm" data-action="del-tag">✕</button>';
            list.appendChild(row);
            return;
        }
        if (e.target.closest('[data-action="del-tag"]')) { e.target.closest('.dc-tag-row').remove(); return; }

        // ── ADD / DELETE FEATURE ──────────────────────────────────────────
        if (e.target.closest('[data-action="add-feat"]')) {
            var list = e.target.closest('.field-row').querySelector('.dc-features-list');
            var row = document.createElement('div');
            row.className = 'detail-row dc-feat-row';
            row.dataset.featId = '0';
            row.innerHTML = '<input type="text" class="dc-feat-input" placeholder="e.g. 240+ Hours of Learning"><button class="btn-del-sm" data-action="del-feat">✕</button>';
            list.appendChild(row);
            return;
        }
        if (e.target.closest('[data-action="del-feat"]')) { e.target.closest('.dc-feat-row').remove(); return; }

        // ── FAQ actions handled by dmSaveFaqRow / dmDeleteFaqRow / dmSaveAllInCategory
        // defined at page level in digitalmarketing.php (onclick attrs, no delegation needed)

    };

    document.addEventListener('click', window._dmClickHandler, false);

    // ── Init schema tab ───────────────────────────────────────────────────
    dmInitSchemaTab(location);

})();
JSCODE;

        echo json_encode(['success' => true, 'html' => $html, 'js' => $js]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE DM CONTENT
    // POST ?action=save_dm_content
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_dm_content' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $location = trim($_POST['location'] ?? '');
        if (!in_array($location, $LOCS, true)) { echo json_encode(['success'=>false,'message'=>'Invalid location']); exit; }

        $fields = ['leadershipsubheading','postgraduationsubheading','certificationsubheading',
                   'bannersubheading','successstoriesheading','successstoriessubheading',
                   'guestfaculty','communitymeetupslider','lastestblog'];
        $values = [];
        foreach ($fields as $f) $values[$f] = trim($_POST[$f] ?? '');

        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'admin';

        $stmt = $db->prepare("SELECT id FROM dm_content WHERE location = ? LIMIT 1");
        $stmt->execute([$location]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            $sets = implode(', ', array_map(fn($f) => "$f = ?", $fields));
            $stmt = $db->prepare("UPDATE dm_content SET $sets, updated_at=NOW(), updated_by=? WHERE location=?");
            $stmt->execute([...array_values($values), $updatedBy, $location]);
        } else {
            $cols = implode(', ', $fields);
            $phs  = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = $db->prepare("INSERT INTO dm_content (location, $cols, updated_at, updated_by) VALUES (?, $phs, NOW(), ?)");
            $stmt->execute([$location, ...array_values($values), $updatedBy]);
        }

        echo json_encode(['success' => true, 'message' => 'Content saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE DM SECTION TITLE
    // POST ?action=save_dm_section
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_dm_section' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $secDbId = (int)($_POST['section_db_id'] ?? 0);
        $title   = trim($_POST['section_title']  ?? '');
        $loc     = trim($_POST['location']        ?? '');
        if (!$secDbId || !$title || !in_array($loc, $LOCS, true)) {
            echo json_encode(['success'=>false,'message'=>'Invalid params']); exit;
        }
        $stmt = $db->prepare("UPDATE dm_sections SET section_title=?, updated_at=NOW() WHERE id=? AND location=?");
        $stmt->execute([$title, $secDbId, $loc]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE DM COURSE
    // POST ?action=save_dm_course
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_dm_course' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId  = (int)($_POST['course_id']     ?? 0);
        $secDbId   = (int)($_POST['section_db_id'] ?? 0);
        $loc       = trim($_POST['location']        ?? '');
        $courseKey = trim($_POST['course_key']       ?? '');
        $title     = trim($_POST['title']           ?? '');
        $btnText   = trim($_POST['button_text']      ?? 'View Course');
        $thumb     = trim($_POST['thumb']            ?? '');
        $btnLink   = trim($_POST['button_link']      ?? '');
        $sortOrder = (int)($_POST['sort_order']      ?? 1);
        $tagsJson  = trim($_POST['tags']             ?? '[]');
        $featsJson = trim($_POST['features']          ?? '[]');
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'admin';

        if (!$title || !$secDbId || !in_array($loc, $LOCS, true)) {
            echo json_encode(['success'=>false,'message'=>'Missing required fields']); exit;
        }

        $tags  = json_decode($tagsJson,  true) ?: [];
        $feats = json_decode($featsJson, true) ?: [];

        if ($courseId) {
            $stmt = $db->prepare("UPDATE dm_courses SET course_key=?, title=?, button_text=?, thumb=?, button_link=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=? AND section_db_id=?");
            $stmt->execute([$courseKey, $title, $btnText, $thumb, $btnLink, $sortOrder, $updatedBy, $courseId, $secDbId]);
        } else {
            $stmt = $db->prepare("INSERT INTO dm_courses (section_db_id, course_key, title, button_text, thumb, button_link, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,?,?,NOW(),?)");
            $stmt->execute([$secDbId, $courseKey, $title, $btnText, $thumb, $btnLink, $sortOrder, $updatedBy]);
            $courseId = (int)$db->lastInsertId();
        }

        // Atomically replace tags
        $db->prepare("DELETE FROM dm_course_tags WHERE course_id=?")->execute([$courseId]);
        $insTag = $db->prepare("INSERT INTO dm_course_tags (course_id, tag, sort_order) VALUES (?,?,?)");
        foreach ($tags as $t) {
            $v = trim($t['tag'] ?? ''); if ($v) $insTag->execute([$courseId, $v, (int)($t['sort_order']??1)]);
        }

        // Atomically replace features
        $db->prepare("DELETE FROM dm_course_features WHERE course_id=?")->execute([$courseId]);
        $insFeat = $db->prepare("INSERT INTO dm_course_features (course_id, feature, sort_order) VALUES (?,?,?)");
        foreach ($feats as $f) {
            $v = trim($f['feature'] ?? ''); if ($v) $insFeat->execute([$courseId, $v, (int)($f['sort_order']??1)]);
        }

        echo json_encode(['success' => true, 'course_id' => $courseId]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE DM COURSE
    // POST ?action=delete_dm_course
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_dm_course' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if (!$courseId) { echo json_encode(['success'=>false,'message'=>'Missing course_id']); exit; }
        $db->prepare("DELETE FROM dm_course_tags     WHERE course_id=?")->execute([$courseId]);
        $db->prepare("DELETE FROM dm_course_features WHERE course_id=?")->execute([$courseId]);
        $stmt = $db->prepare("DELETE FROM dm_courses WHERE id=? LIMIT 1");
        $stmt->execute([$courseId]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE DM FAQ  (slot-based, mirrors faq_api.php save_faq)
    // POST ?action=save_dm_faq
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_dm_faq' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $faqId     = (int)($_POST['faq_id']    ?? 0);
        $loc       = trim($_POST['location']   ?? '');
        $label     = trim($_POST['label']      ?? '');
        $question  = trim($_POST['question']   ?? '');
        $answer    = trim($_POST['answer']     ?? '');
        $sortOrder = (int)($_POST['sort_order']?? 1);
        $isActive  = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'admin';

        $validLabels = ['Program','Delivery','Placement','Certification','Fee'];
        if (!$question || !in_array($loc, $LOCS, true) || !in_array($label, $validLabels, true)) {
            echo json_encode(['success'=>false,'message'=>'Missing required fields']); exit;
        }
        if ($sortOrder < 1 || $sortOrder > 10) $sortOrder = max(1, min(10, $sortOrder));

        if ($faqId) {
            $stmt = $db->prepare("UPDATE dm_faqs SET question=?, answer=?, is_active=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=? AND location=? AND label=?");
            $stmt->execute([$question, $answer, $isActive, $sortOrder, $updatedBy, $faqId, $loc, $label]);
        } else {
            // Check if slot already occupied
            $chk = $db->prepare("SELECT id FROM dm_faqs WHERE location=? AND label=? AND sort_order=? LIMIT 1");
            $chk->execute([$loc, $label, $sortOrder]);
            $existing = (int)$chk->fetchColumn();
            if ($existing) {
                $stmt = $db->prepare("UPDATE dm_faqs SET question=?, answer=?, is_active=?, updated_at=NOW(), updated_by=? WHERE id=?");
                $stmt->execute([$question, $answer, $isActive, $updatedBy, $existing]);
                $faqId = $existing;
            } else {
                $stmt = $db->prepare("INSERT INTO dm_faqs (location, label, question, answer, is_active, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,?,NOW(),?)");
                $stmt->execute([$loc, $label, $question, $answer, $isActive, $sortOrder, $updatedBy]);
                $faqId = (int)$db->lastInsertId();
            }
        }

        echo json_encode(['success' => true, 'faq_id' => $faqId]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE DM FAQ
    // POST ?action=delete_dm_faq
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_dm_faq' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $faqId = (int)($_POST['faq_id'] ?? 0);
        $loc   = trim($_POST['location'] ?? '');
        if (!$faqId || !in_array($loc, $LOCS, true)) { echo json_encode(['success'=>false,'message'=>'Invalid params']); exit; }
        $stmt = $db->prepare("DELETE FROM dm_faqs WHERE id=? AND location=? LIMIT 1");
        $stmt->execute([$faqId, $loc]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET DM SCHEMA  — load schema JSON for admin editor
    // GET ?action=get_dm_schema&location=bangalore
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_dm_schema') {
        $location = trim($_GET['location'] ?? '');
        if (!in_array($location, $LOCS, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid location']); exit;
        }

        $stmt = $db->prepare("
            SELECT schema_json, updated_at, updated_by
            FROM dm_schemas
            WHERE location = ?
            LIMIT 1
        ");
        $stmt->execute([$location]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $json = '[]';
        if ($row && !empty($row['schema_json'])) {
            $decoded = json_decode($row['schema_json']);
            if ($decoded !== null) {
                $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        echo json_encode([
            'success'     => true,
            'schema_json' => $json,
            'updated_at'  => $row['updated_at'] ?? null,
            'updated_by'  => $row['updated_by'] ?? null,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE DM SCHEMA
    // POST ?action=save_dm_schema
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_dm_schema' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $location = trim($_POST['location'] ?? '');
        $rawJson  = trim($_POST['schema_json'] ?? '');

        if (!in_array($location, $LOCS, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid location']); exit;
        }

        $decoded = json_decode($rawJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]); exit;
        }
        if (!is_array($decoded)) {
            echo json_encode(['success' => false, 'message' => 'Schema must be a JSON array [ ... ]']); exit;
        }

        $cleanJson = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $updatedBy = $_SESSION['da360_user']['name']
                  ?? $_SESSION['da360_user']['username']
                  ?? 'unknown';

        $stmt = $db->prepare("
            INSERT INTO dm_schemas (location, schema_json, updated_at, updated_by)
            VALUES (:location, :schema_json, NOW(), :updated_by)
            ON DUPLICATE KEY UPDATE
                schema_json = VALUES(schema_json),
                updated_at  = NOW(),
                updated_by  = VALUES(updated_by)
        ");
        $stmt->execute([
            ':location'   => $location,
            ':schema_json'=> $cleanJson,
            ':updated_by' => $updatedBy,
        ]);

        // ── Trigger Next.js revalidation ──────────────────────────────────
        $revalidateUrl = 'https://your-nextjs-site.com/api/revalidate'; // ← update
        $secret        = 'your_strong_secret_here';                     // ← update
        $ch = curl_init($revalidateUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['tag' => 'dm-schemas']),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-revalidate-secret: ' . $secret,
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $curlError   = curl_error($ch);
        $revalidated = !$curlError;
        curl_close($ch);
        // ─────────────────────────────────────────────────────────────────

        echo json_encode([
            'success'     => true,
            'message'     => 'Schema saved successfully.',
            'revalidated' => $revalidated,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET DM SCHEMAS JSON  — for Next.js frontend (all locations)
    // GET ?action=get_dm_schemas_json&api_key=XXX
    // Returns: { success, schemas: { bangalore: [...], mysuru: [...], ... } }
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_dm_schemas_json') {
        $stmt = $db->prepare("
            SELECT l.slug, ds.schema_json
            FROM locations l
            LEFT JOIN dm_schemas ds ON ds.location = l.slug
            WHERE l.is_active = 1
            ORDER BY l.sort_order, l.label
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $schemas = [];
            if (!empty($row['schema_json'])) {
                $decoded = json_decode($row['schema_json'], true);
                if (is_array($decoded)) $schemas = $decoded;
            }
            $result[$row['slug']] = $schemas;
        }

        echo json_encode(
            ['success' => true, 'schemas' => $result],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/*
══════════════════════════════════════════════════════════════════════════════
SQL MIGRATION — run once
══════════════════════════════════════════════════════════════════════════════

CREATE TABLE dm_content (
  id                        INT AUTO_INCREMENT PRIMARY KEY,
  location                  VARCHAR(50)  NOT NULL,
  leadershipsubheading      TEXT         NOT NULL DEFAULT '',
  postgraduationsubheading  TEXT         NOT NULL DEFAULT '',
  certificationsubheading   TEXT         NOT NULL DEFAULT '',
  bannersubheading          TEXT         NOT NULL DEFAULT '',
  successstoriesheading     VARCHAR(500) NOT NULL DEFAULT '',
  successstoriessubheading  TEXT         NOT NULL DEFAULT '',
  guestfaculty              TEXT         NOT NULL DEFAULT '',
  communitymeetupslider     TEXT         NOT NULL DEFAULT '',
  lastestblog               VARCHAR(500) NOT NULL DEFAULT '',
  updated_at                DATETIME,
  updated_by                VARCHAR(100),
  UNIQUE KEY uq_location (location)
);

CREATE TABLE dm_sections (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  location        VARCHAR(50)  NOT NULL,
  section_id      VARCHAR(100) NOT NULL,
  section_title   VARCHAR(500) NOT NULL DEFAULT '',
  component_type  ENUM('Leadership','PostGraduate','Certification','College') NOT NULL,
  sort_order      INT          NOT NULL DEFAULT 1,
  updated_at      DATETIME,
  UNIQUE KEY uq_loc_sec (location, section_id)
);

CREATE TABLE dm_courses (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  section_db_id  INT          NOT NULL,
  course_key     VARCHAR(100) NOT NULL DEFAULT '',
  title          VARCHAR(500) NOT NULL DEFAULT '',
  button_text    VARCHAR(100) NOT NULL DEFAULT 'View Course',
  thumb          VARCHAR(500) NOT NULL DEFAULT '',
  button_link    VARCHAR(500) NOT NULL DEFAULT '',
  sort_order     INT          NOT NULL DEFAULT 1,
  updated_at     DATETIME,
  updated_by     VARCHAR(100),
  INDEX (section_db_id)
);

CREATE TABLE dm_course_tags (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  course_id  INT          NOT NULL,
  tag        VARCHAR(200) NOT NULL DEFAULT '',
  sort_order INT          NOT NULL DEFAULT 1,
  INDEX (course_id)
);

CREATE TABLE dm_course_features (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  course_id  INT          NOT NULL,
  feature    VARCHAR(500) NOT NULL DEFAULT '',
  sort_order INT          NOT NULL DEFAULT 1,
  INDEX (course_id)
);

CREATE TABLE dm_faqs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  location   VARCHAR(50)  NOT NULL,
  question   TEXT         NOT NULL,
  answer     TEXT         NOT NULL DEFAULT '',
  sort_order INT          NOT NULL DEFAULT 1,
  updated_at DATETIME,
  updated_by VARCHAR(100),
  INDEX (location)
);

CREATE TABLE dm_schemas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  location    VARCHAR(50)  NOT NULL,
  schema_json LONGTEXT     NOT NULL DEFAULT '[]',
  updated_at  DATETIME,
  updated_by  VARCHAR(100),
  UNIQUE KEY uq_location (location)
);

*/
