<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

// Must be logged in
define('VALID_API_KEYS', [
    '5678DA360',  
]);

function isAuthorized(): bool {
    if (isLoggedIn()) return true;

    // Authorization: Bearer <key>
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        return in_array($m[1], VALID_API_KEYS, true);
    }

    // Fallback: ?api_key=<key>  (simpler but less secure)
    $queryKey = $_GET['api_key'] ?? '';
    if ($queryKey !== '' && in_array($queryKey, VALID_API_KEYS, true)) {
        return true;
    }

    return false;
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
    'lifeatda360'            => ['label' => 'Life at DA360',             'icon' => '🏫', 'section' => 'Campus & Culture'],
    'trustedbylearners'      => ['label' => 'Trusted by Learners',       'icon' => '🤝', 'section' => 'Campus & Culture'],
    'coursehilightcatergory' => ['label' => 'Course Highlight Category', 'icon' => '⭐', 'section' => 'Course Info'],
    'toolsheading'           => ['label' => 'Tools Heading',             'icon' => '🛠️', 'section' => 'Course Info'],
    'toolsdescription'       => ['label' => 'Tools Description',         'icon' => '📝', 'section' => 'Course Info'],
    'aitoolsheading'         => ['label' => 'AI Tools Heading',          'icon' => '🤖', 'section' => 'AI & Technology'],
    'aitoolsdescription'     => ['label' => 'AI Tools Description',      'icon' => '💡', 'section' => 'AI & Technology'],
    'casestudiesheading'     => ['label' => 'Case Studies Heading',      'icon' => '📊', 'section' => 'Case Studies'],
    'casestudeiessubheading' => ['label' => 'Case Studies Subheading',   'icon' => '📋', 'section' => 'Case Studies'],
    'peoplesliderdesc'       => ['label' => 'People Slider Description', 'icon' => '👥', 'section' => 'Social Proof'],
    'latestblogheading'      => ['label' => 'Latest Blog Heading',       'icon' => '✍️', 'section' => 'Content'],
    'leadcapturetwotitle'    => ['label' => 'Lead Capture Title 2',      'icon' => '📣', 'section' => 'Lead Capture'],
    'leadcapturethirdtitle'  => ['label' => 'Lead Capture Title 3',      'icon' => '📢', 'section' => 'Lead Capture'],
    'leadcapturesubtitle'    => ['label' => 'Lead Capture Subtitle',     'icon' => '💬', 'section' => 'Lead Capture'],
    'cohortheading'          => ['label' => 'Cohort Heading',            'icon' => '📅', 'section' => 'Batches'],
    'storyheading'           => ['label' => 'Story Heading',             'icon' => '🌟', 'section' => 'Social Proof'],
    'storydesc'              => ['label' => 'Story Description',         'icon' => '💫', 'section' => 'Social Proof'],
    'roadmapheader'          => ['label' => 'Roadmap Header',            'icon' => '🗺️', 'section' => 'Learning Path'],
    'roadmapdesc'            => ['label' => 'Roadmap Description',       'icon' => '📍', 'section' => 'Learning Path'],
    'programskillheading'    => ['label' => 'Program Skills Heading',    'icon' => '🎯', 'section' => 'Skills'],
    'programskillsubheading' => ['label' => 'Program Skills Subheading', 'icon' => '🔧', 'section' => 'Skills'],
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
          <div class="stat-chip"><strong><?= $totalFields ?></strong>&nbsp;Total Fields</div>
          <div class="stat-chip"><strong><?= $filledFields ?></strong>&nbsp;Filled</div>
          <div class="stat-chip"><strong><?= $totalFields - $filledFields ?></strong>&nbsp;Empty</div>
          <div class="stat-chip"><strong><?= count($sections) ?></strong>&nbsp;Sections</div>
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
            <span class="save-hint">Changes are saved directly to the database.</span>
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
    if ($action === 'save_content' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);

        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id or location_id']);
            exit;
        }

        // Collect only whitelisted fields — never trust raw $_POST keys
        $fields = [];
        foreach (array_keys($fieldMeta) as $key) {
            $fields[$key] = trim($_POST[$key] ?? '');
        }

        // Build column and placeholder lists for INSERT
        $colList = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
        $phList  = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));

        // Build SET clause for ON DUPLICATE KEY UPDATE (skip course_id / location_id)
        $updateClauses = implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", array_keys($fields)));

        $sql = "
            INSERT INTO course_content
                (course_id, location_id, $colList, created_at, updated_at)
            VALUES
                (:course_id, :location_id, $phList, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                $updateClauses,
                updated_at = NOW()
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($fields, [
            'course_id'   => $courseId,
            'location_id' => $locationId,
        ]));

        echo json_encode(['success' => true, 'message' => 'Content saved successfully']);
        exit;
    }

    // ── Unknown action ────────────────────────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
