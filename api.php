<?php
// ── CORS — allow your Next.js dev server to call this API ─────────────────────
// In production replace '*' / 'http://localhost:3000' with your actual domain.
$allowedOrigins = [
    'http://localhost:3000',   // Next.js dev (turbopack)
    'http://localhost',   // fallback port
    // 'https://yourproductiondomain.com',  ← uncomment when live
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');   // needed for session cookies
} else {
    // During local dev you can temporarily allow all origins by commenting
    // the block above and uncommenting the next line:
    // header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight (OPTIONS) request sent by the browser before POST
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

// ── Authentication ─────────────────────────────────────────────────────────
// Accepts: (1) valid session (web app), (2) Bearer token or ?api_key= (external)
define('VALID_API_KEYS', [
    'da360-secret-key-2024', // 🔑 Change this to any secret string you choose
]);

function isAuthorized(): bool {
    if (isLoggedIn()) return true;
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return in_array($m[1], VALID_API_KEYS, true);
    }
    $queryKey = $_GET['api_key'] ?? '';
    return $queryKey !== '' && in_array($queryKey, VALID_API_KEYS, true);
}

if (!isAuthorized()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── Field metadata ────────────────────────────────────────────────────────────
// Column names match schema.sql exactly.
// This is the single source of truth — used for rendering AND saving.
$fieldMeta = [
    'leadcaptureonetitle'    => ['label' => 'Lead Capture Title 1',      'icon' => '📣', 'section' => 'Lead Capture'],
    'leadcapturetwotitle'    => ['label' => 'Lead Capture Title 2',      'icon' => '📣', 'section' => 'Lead Capture'],
    'leadcapturethirdtitle'  => ['label' => 'Lead Capture Title 3',      'icon' => '📢', 'section' => 'Lead Capture'],
    'leadcapturesubtitle'    => ['label' => 'Lead Capture Subtitle',     'icon' => '💬', 'section' => 'Lead Capture'],
    'cohortheading'          => ['label' => 'Cohort Heading',            'icon' => '📅', 'section' => 'Batches'],
    'storyheading'           => ['label' => 'Story Heading',             'icon' => '🌟', 'section' => 'Real Stories'],
    'storydesc'              => ['label' => 'Story Description',         'icon' => '💫', 'section' => 'Real Stories'],
    'lifeatda360'            => ['label' => 'Life at DA360',             'icon' => '🏫', 'section' => 'Campus & Culture'],
    'trustedbylearners'      => ['label' => 'Trusted by Learners',       'icon' => '🤝', 'section' => 'Campus & Culture'],
    'coursehilightcatergory' => ['label' => 'Course Highlight Category', 'icon' => '⭐', 'section' => 'Course Info'],
    'programskillheading'    => ['label' => 'Key Highlight Heading',    'icon' => '🎯', 'section' => 'Key Highlight'],
    'programskillsubheading' => ['label' => 'Key Highlight Subheading', 'icon' => '🔧', 'section' => 'Key Highlight'],
    'toolsheading'           => ['label' => 'Tools Heading',             'icon' => '🛠️', 'section' => 'Tools'],
    'toolsdescription'       => ['label' => 'Tools Description',         'icon' => '📝', 'section' => 'Tools'],
    'aitoolsheading'         => ['label' => 'AI Tools Heading',          'icon' => '🤖', 'section' => 'AI & Technology'],
    'aitoolsdescription'     => ['label' => 'AI Tools Description',      'icon' => '💡', 'section' => 'AI & Technology'],
    'roadmapheader'          => ['label' => 'Roadmap Header',            'icon' => '🗺️', 'section' => 'Road Map'],
    'roadmapdesc'            => ['label' => 'Roadmap Description',       'icon' => '📍', 'section' => 'Road Map'],
    'casestudiesheading'     => ['label' => 'Case Studies Heading',      'icon' => '📊', 'section' => 'Case Studies'],
    'casestudeiessubheading' => ['label' => 'Case Studies Subheading',   'icon' => '📋', 'section' => 'Case Studies'],
    'peoplesliderdesc'       => ['label' => 'Success stories Subheading', 'icon' => '👥', 'section' => 'Success Stories'],
    'latestblogheading'      => ['label' => 'Latest Blog Heading',       'icon' => '✍️', 'section' => 'Content'],
    'feestructureheading'    => ['label' => 'Fee Structure Heading',    'icon' => '🎯', 'section' => 'BBA & MBA'],
    'feestructuresubheading' => ['label' => 'Fee Structure Subheading', 'icon' => '🔧', 'section' => 'BBA & MBA'],
];

$sectionAccents = [
    'Campus & Culture' => '#f97316',
    'Course Info'      => '#0ea5e9',
    'AI & Technology'  => '#8b5cf6',
    'Case Studies'     => '#22c55e',
    'Social Proof'     => '#ec4899',
    'Content'          => '#f59e0b',
    'Lead Capture'     => '#f43f5e',
    'Batches'          => '#14b8a6',
    'Learning Path'    => '#64748b',
    'Skills'           => '#ca8a04',
];

try {
    $db = getDB();

    // ── GET LOCATIONS ─────────────────────────────────────────────────────────
    // Returns all active locations (original behaviour kept as-is)
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
        echo json_encode(['success' => true, 'locations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    // ── GET CONTENT (original — kept for backward compatibility) ──────────────
    if ($action === 'get_content') {
        $courseId   = (int)($_GET['course_id']   ?? 0);
        $locationId = (int)($_GET['location_id'] ?? 0);
        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']);
            exit;
        }
        $stmt = $db->prepare("
            SELECT cc.*, c.label AS course_label, l.label AS location_label
            FROM course_content cc
            JOIN courses   c ON cc.course_id   = c.id
            JOIN locations l ON cc.location_id = l.id
            WHERE cc.course_id = :cid AND cc.location_id = :lid
            LIMIT 1
        ");
        $stmt->execute([':cid' => $courseId, ':lid' => $locationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo json_encode([
                'success'        => true,
                'content'        => $row,
                'course_label'   => $row['course_label'],
                'location_label' => $row['location_label'],
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No content found for this combination.']);
        }
        exit;
    }

    // ── GET ALL LOCATIONS CONTENT FOR A COURSE (single API call) ─────────────
    // Returns every location's content for the given course_id in one response.
    // The location slug (lowercased label, spaces replaced with _) is used as
    // the key so the Next.js side can map it directly to LocationKey.
    //
    // GET /api.php?action=get_course_content&course_id=1&api_key=XXX
    //
    // Response shape:
    // {
    //   "success": true,
    //   "course_label": "...",
    //   "locations": {
    //     "global":       { ...fields },
    //     "bangalore":    { ...fields },
    //     "jayanagar":    { ...fields },
    //     ...
    //   }
    // }
    if ($action === 'get_course_content') {
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

        // Fetch all active locations with their content (LEFT JOIN so locations
        // with no content row still appear with empty strings)
        $contentFields = implode(', ', array_map(fn($k) => "cc.`$k`", array_keys($fieldMeta)));
        $stmt = $db->prepare("
            SELECT
                l.id            AS location_id,
                l.label         AS location_label,
                l.slug          AS location_slug,
                $contentFields
            FROM locations l
            LEFT JOIN course_content cc
                   ON cc.course_id   = :cid
                  AND cc.location_id = l.id
            WHERE l.is_active = 1
            ORDER BY l.sort_order, l.label
        ");
        $stmt->execute([':cid' => $courseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build keyed object — use slug if available, else derive from label
        $locations = [];
        foreach ($rows as $row) {
            // Use DB slug column if present, otherwise derive from label
            $slug = !empty($row['location_slug'])
                ? $row['location_slug']
                : strtolower(preg_replace('/\s+/', '_', trim($row['location_label'])));

            $entry = [];
            foreach (array_keys($fieldMeta) as $field) {
                $entry[$field] = $row[$field] ?? '';
            }
            $entry['location_id']    = (int)$row['location_id'];
            $entry['location_label'] = $row['location_label'];

            $locations[$slug] = $entry;
        }

        echo json_encode([
            'success'      => true,
            'course_label' => $courseLabel,
            'locations'    => $locations,
        ]);
        exit;
    }

    // ── DASHBOARD STATS (original — unchanged) ────────────────────────────────
    if ($action === 'dashboard_stats') {
        $stats              = [];
        $stats['courses']   = $db->query("SELECT COUNT(*) FROM courses   WHERE is_active = 1")->fetchColumn();
        $stats['locations'] = $db->query("SELECT COUNT(*) FROM locations WHERE is_active = 1")->fetchColumn();
        $stats['content']   = $db->query("SELECT COUNT(*) FROM course_content")->fetchColumn();
        $possible           = (int)$stats['courses'] * (int)$stats['locations'];
        $stats['coverage']  = $possible > 0 ? round((int)$stats['content'] / $possible * 100) : 0;
        echo json_encode(['success' => true, 'stats' => $stats]);
        exit;
    }

    // ── GET CONTENT HTML ──────────────────────────────────────────────────────
    // Fetches the course_content row, builds a full editable form in PHP,
    // and returns it as a JSON { success, html } string.
    // app.js injects data.html directly into #result-area.
    if ($action === 'get_content_html') {
        $courseId   = (int)($_GET['course_id']   ?? 0);
        $locationId = (int)($_GET['location_id'] ?? 0);

        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id or location_id']);
            exit;
        }

        // Course label
        $stmt = $db->prepare("SELECT label FROM courses WHERE id = ? LIMIT 1");
        $stmt->execute([$courseId]);
        $courseLabel = $stmt->fetchColumn() ?: '';

        // Location label
        $stmt = $db->prepare("SELECT label FROM locations WHERE id = ? LIMIT 1");
        $stmt->execute([$locationId]);
        $locationLabel = $stmt->fetchColumn() ?: '';

        // Content row — empty array means no row yet (form will INSERT on save)
        $stmt = $db->prepare("
            SELECT * FROM course_content
            WHERE course_id = ? AND location_id = ?
            LIMIT 1
        ");
        $stmt->execute([$courseId, $locationId]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $updatedBy  = $content['updated_by'] ?? null;
        $updatedAt  = $content['updated_at'] ?? null;
        // Stats
        $totalFields  = count($fieldMeta);
        $filledFields = count(array_filter(
            array_keys($fieldMeta),
            fn($k) => isset($content[$k]) && trim($content[$k]) !== ''
        ));

        // Group fields into sections
        $sections = [];
        foreach ($fieldMeta as $key => $meta) {
            $sections[$meta['section']][] = array_merge(['key' => $key], $meta);
        }

        // Build full HTML form in PHP via output buffer
        ob_start(); ?>

        <div class="result-header animate-fadeup">
          <div class="result-title">
            <?= empty($content) ? 'New Content Entry' : 'Edit Content' ?>
          </div>
          <div class="result-meta">
            <span class="meta-pill accent"><?= htmlspecialchars($courseLabel) ?></span>
            <span class="meta-pill"><?= htmlspecialchars($locationLabel) ?></span>
          </div>
        </div>

        <div class="stats-bar">
            <div class="stat-chip"><b><?= $totalFields ?></b>&nbsp;Total Fields</div>
            <div class="stat-chip"><b><?= $filledFields ?></b>&nbsp;Filled</div>
            <div class="stat-chip"><b><?= $totalFields - $filledFields ?></b>&nbsp;Empty</div>
            <div class="stat-chip"><b><?= count($sections) ?></b>&nbsp;Sections</div>

            <?php if ($updatedBy): ?>
            <div class="stat-chip">
                ✏️ Last updated by &nbsp;<b><?= htmlspecialchars($updatedBy) ?></b>
                <?php if ($updatedAt): ?>
                &nbsp;on&nbsp;<b><?= htmlspecialchars($updatedAt) ?></b>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            </div>

        <form id="content-form">
          <input type="hidden" name="course_id"   value="<?= $courseId ?>">
          <input type="hidden" name="location_id" value="<?= $locationId ?>">

          <?php foreach ($sections as $sectionName => $fields):
            $accent = $sectionAccents[$sectionName] ?? '#64748b'; ?>

            <div class="section-block animate-fadeup">
              <div class="section-label"><?= htmlspecialchars($sectionName) ?></div>
              <div class="fields-grid">

                <?php foreach ($fields as $field):
                  $key     = $field['key'];
                  $val     = $content[$key] ?? '';
                  $isEmpty = trim($val) === ''; ?>

                  <div class="field-card" style="--accent-color:<?= $accent ?>">
                    <div class="field-icon-label">
                      <span class="field-icon"><?= $field['icon'] ?></span>
                      <label class="field-label-text" for="field_<?= $key ?>">
                        <?= htmlspecialchars($field['label']) ?>
                      </label>
                    </div>

                    <textarea
                      id="field_<?= $key ?>"
                      name="<?= $key ?>"
                      class="field-textarea <?= $isEmpty ? 'empty' : '' ?>"
                      rows="3"
                      placeholder="Not configured — type to add content…"
                    ><?= htmlspecialchars($val) ?></textarea>

                    <?php if (!$isEmpty): ?>
                      <button type="button" class="copy-btn"
                        onclick="copyField(this, <?= json_encode($val) ?>)">📋</button>
                    <?php endif; ?>
                  </div>

                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="save-bar">
            <button type="submit" class="btn btn-primary btn-save" id="save-btn">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                <polyline points="17 21 17 13 7 13 7 21"/>
                <polyline points="7 3 7 8 15 8"/>
              </svg>
              <?= empty($content) ? 'Create Content' : 'Save Changes' ?>
            </button>
          </div>
        </form>

        <?php
        $html = ob_get_clean();
        echo json_encode(['success' => true, 'html' => $html]);
        exit;
    }

    // ── SAVE CONTENT ──────────────────────────────────────────────────────────
    // Uses INSERT ... ON DUPLICATE KEY UPDATE, which is safe because
    // course_content has UNIQUE KEY (course_id, location_id) in schema.sql.
    // Only the 21 whitelisted field keys from $fieldMeta are ever written.
    // ── SAVE CONTENT ──────────────────────────────────────────────────────────
    if ($action === 'save_content' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);

        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id or location_id']);
            exit;
        }

        // ✅ Capture who is saving
        $updatedBy = $_SESSION['da360_user']['name'] 
          ?? $_SESSION['da360_user']['username'] 
          ?? 'unknown';

        // Collect only whitelisted fields
        $fields = [];
        foreach (array_keys($fieldMeta) as $key) {
            $fields[$key] = trim($_POST[$key] ?? '');
        }

        $colList = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
        $phList  = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        $updateClauses = implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", array_keys($fields)));

        $sql = "
            INSERT INTO course_content
                (course_id, location_id, $colList, created_at, updated_at, updated_by)
            VALUES
                (:course_id, :location_id, $phList, NOW(), NOW(), :updated_by)
            ON DUPLICATE KEY UPDATE
                $updateClauses,
                updated_at = NOW(),
                updated_by = VALUES(updated_by)
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($fields, [
            'course_id'   => $courseId,
            'location_id' => $locationId,
            'updated_by'  => $updatedBy,   // ✅ only passed once now
        ]));
        

        echo json_encode([
            'success'    => true,
            'message'    => 'Content saved successfully',
            'updated_by' => $updatedBy,    // ✅ return it so UI can show it
        ]);
        exit;
    }

    if ($action === 'replicate_content' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']        ?? 0);
        $fromId     = (int)($_POST['from_location_id'] ?? 0);
        $toId       = (int)($_POST['to_location_id']   ?? 0);

        if (!$courseId || !$fromId || !$toId || $fromId === $toId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        // Fetch source row
        $stmt = $db->prepare("
            SELECT * FROM course_content
            WHERE course_id = ? AND location_id = ?
            LIMIT 1
        ");
        $stmt->execute([$courseId, $fromId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$source) {
            echo json_encode(['success' => false, 'message' => 'No content found in source location']);
            exit;
        }

        // Build insert/update using only whitelisted fields
        $fields = [];
        foreach (array_keys($fieldMeta) as $key) {
            $fields[$key] = $source[$key] ?? '';
        }

        $updatedBy     = $_SESSION['da360_user']['name']
                    ?? $_SESSION['da360_user']['username']
                    ?? 'unknown';

        $colList       = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
        $phList        = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        $updateClauses = implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", array_keys($fields)));

        $sql = "
            INSERT INTO course_content
                (course_id, location_id, $colList, created_at, updated_at, updated_by)
            VALUES
                (:course_id, :location_id, $phList, NOW(), NOW(), :updated_by)
            ON DUPLICATE KEY UPDATE
                $updateClauses,
                updated_at = NOW(),
                updated_by = VALUES(updated_by)
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($fields, [
            'course_id'   => $courseId,
            'location_id' => $toId,
            'updated_by'  => $updatedBy,
        ]));

        echo json_encode(['success' => true, 'message' => 'Content replicated successfully']);
        exit;
    }


    // ── REPLICATE CURRICULUM ──────────────────────────────────────────
    // Copies course_curriculum heading/description + batches/slots
    // from source location to target location.
    // Modules/topics are course-wide — not replicated.
    if ($action === 'replicate_curriculum' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId = (int)($_POST['course_id']        ?? 0);
        $fromId   = (int)($_POST['from_location_id'] ?? 0);
        $toId     = (int)($_POST['to_location_id']   ?? 0);

        if (!$courseId || !$fromId || !$toId || $fromId === $toId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        // ── 1. Replicate heading + description ───────────────────────
        $stmt = $db->prepare("
            SELECT heading, description FROM course_curriculum
            WHERE course_id = ? AND location_id = ?
            LIMIT 1
        ");
        $stmt->execute([$courseId, $fromId]);
        $curr = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($curr) {
            $stmt = $db->prepare("
                INSERT INTO course_curriculum (course_id, location_id, heading, description)
                VALUES (:cid, :lid, :heading, :description)
                ON DUPLICATE KEY UPDATE
                    heading     = VALUES(heading),
                    description = VALUES(description)
            ");
            $stmt->execute([
                'cid'         => $courseId,
                'lid'         => $toId,
                'heading'     => $curr['heading'],
                'description' => $curr['description'],
            ]);
        }

        // ── 2. Replicate batches + slots ─────────────────────────────

        // Delete existing batches in target (cascade deletes slots if FK set,
        // otherwise delete slots first)
        $stmt = $db->prepare("
            SELECT id FROM course_batches
            WHERE course_id = ? AND location_id = ?
        ");
        $stmt->execute([$courseId, $toId]);
        $existingBatchIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($existingBatchIds)) {
            $inList = implode(',', array_map('intval', $existingBatchIds));
            $db->exec("DELETE FROM course_batch_slots WHERE batch_id IN ($inList)");
            $db->exec("DELETE FROM course_batches WHERE id IN ($inList)");
        }

        // Fetch source batches
        $stmt = $db->prepare("
            SELECT id, label, sort_order FROM course_batches
            WHERE course_id = ? AND location_id = ?
            ORDER BY sort_order
        ");
        $stmt->execute([$courseId, $fromId]);
        $sourceBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sourceBatches as $batch) {
            // Insert new batch for target location
            $stmt = $db->prepare("
                INSERT INTO course_batches (course_id, location_id, label, sort_order)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$courseId, $toId, $batch['label'], $batch['sort_order']]);
            $newBatchId = (int)$db->lastInsertId();

            // Fetch and insert slots
            $stmt2 = $db->prepare("
                SELECT slot, sort_order FROM course_batch_slots
                WHERE batch_id = ?
                ORDER BY sort_order
            ");
            $stmt2->execute([$batch['id']]);
            $slots = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            foreach ($slots as $slot) {
                $stmt3 = $db->prepare("
                    INSERT INTO course_batch_slots (batch_id, slot, sort_order)
                    VALUES (?, ?, ?)
                ");
                $stmt3->execute([$newBatchId, $slot['slot'], $slot['sort_order']]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Curriculum replicated successfully']);
        exit;
    }


    // ── REPLICATE SPECIALISATION ──────────────────────────────────────
    // Copies course_specialisation heading/description only.
    // Modules/topics are course-wide — not replicated.
    if ($action === 'replicate_specialisation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId = (int)($_POST['course_id']        ?? 0);
        $fromId   = (int)($_POST['from_location_id'] ?? 0);
        $toId     = (int)($_POST['to_location_id']   ?? 0);

        if (!$courseId || !$fromId || !$toId || $fromId === $toId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT heading, description FROM course_specialisation
            WHERE course_id = ? AND location_id = ?
            LIMIT 1
        ");
        $stmt->execute([$courseId, $fromId]);
        $spec = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$spec) {
            echo json_encode(['success' => false, 'message' => 'No specialisation found in source location']);
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO course_specialisation (course_id, location_id, heading, description)
            VALUES (:cid, :lid, :heading, :description)
            ON DUPLICATE KEY UPDATE
                heading     = VALUES(heading),
                description = VALUES(description)
        ");
        $stmt->execute([
            'cid'         => $courseId,
            'lid'         => $toId,
            'heading'     => $spec['heading'],
            'description' => $spec['description'],
        ]);

        echo json_encode(['success' => true, 'message' => 'Specialisation replicated successfully']);
        exit;
    }


    // ── REPLICATE FAQs ────────────────────────────────────────────────
    // Deletes all FAQs in target location and copies from source.
    if ($action === 'replicate_faqs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId = (int)($_POST['course_id']        ?? 0);
        $fromId   = (int)($_POST['from_location_id'] ?? 0);
        $toId     = (int)($_POST['to_location_id']   ?? 0);

        if (!$courseId || !$fromId || !$toId || $fromId === $toId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        // Fetch source FAQs
        $stmt = $db->prepare("
            SELECT category, sort_order, question, answer, is_active
            FROM course_faqs
            WHERE course_id = ? AND location_id = ?
            ORDER BY category, sort_order
        ");
        $stmt->execute([$courseId, $fromId]);
        $sourceFaqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sourceFaqs)) {
            echo json_encode(['success' => false, 'message' => 'No FAQs found in source location']);
            exit;
        }

        // Delete existing FAQs in target
        $stmt = $db->prepare("
            DELETE FROM course_faqs
            WHERE course_id = ? AND location_id = ?
        ");
        $stmt->execute([$courseId, $toId]);

        // Insert source FAQs into target
        $stmt = $db->prepare("
            INSERT INTO course_faqs (course_id, location_id, category, sort_order, question, answer, is_active)
            VALUES (:cid, :lid, :category, :sort_order, :question, :answer, :is_active)
        ");

        foreach ($sourceFaqs as $faq) {
            $stmt->execute([
                'cid'        => $courseId,
                'lid'        => $toId,
                'category'   => $faq['category'],
                'sort_order' => $faq['sort_order'],
                'question'   => $faq['question'],
                'answer'     => $faq['answer'],
                'is_active'  => $faq['is_active'],
            ]);
        }

        $count = count($sourceFaqs);
        echo json_encode(['success' => true, 'message' => "$count FAQs replicated successfully"]);
        exit;
    }
    
    // ── COURSE REPLICATION ACTIONS ────────────────────────────────────
    require_once __DIR__ . '/replicate-course-api-actions.php';

    // ── Unknown action ────────────────────────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
