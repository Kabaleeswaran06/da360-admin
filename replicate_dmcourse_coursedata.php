<?php
// ── Auth & DB ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

if (!isLoggedIn()) {
    header('Location: /da360-admin/login.php');
    exit;
}

$db = getDB();

// ── Fetch active locations ─────────────────────────────────────────────────────
$locations = $db->query(
    "SELECT id, slug, label FROM locations WHERE is_active = 1 ORDER BY sort_order"
)->fetchAll(PDO::FETCH_ASSOC);

$updatedBy = $_SESSION['da360_user']['name']
          ?? $_SESSION['da360_user']['username']
          ?? 'admin';

// ── Helper: fetch full section+course tree for a location ──────────────────────
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

$LOCS = array_column($locations, 'slug');

// ══════════════════════════════════════════════════════════════════════════════
// AJAX — handle POST actions
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ── Preview: fetch source data and return summary ──────────────────────
    if ($action === 'preview') {
        $source = trim($_POST['source'] ?? '');
        if (!in_array($source, $LOCS, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid source location']); exit;
        }
        $sections = fetchSectionsForLocation($db, $source);
        $summary  = [];
        foreach ($sections as $sec) {
            $summary[] = [
                'section_title'  => $sec['section_title'],
                'component_type' => $sec['component_type'],
                'course_count'   => count($sec['courses']),
                'courses'        => array_map(fn($c) => [
                    'title'    => $c['title'],
                    'tags'     => count($c['tags']),
                    'features' => count($c['features']),
                ], $sec['courses']),
            ];
        }
        echo json_encode(['success' => true, 'sections' => $summary]);
        exit;
    }

    // ── Copy: replicate course data from source → targets ──────────────────
    if ($action === 'copy') {
        $source  = trim($_POST['source'] ?? '');
        $targets = json_decode($_POST['targets'] ?? '[]', true);
        $opts    = [
            'sections' => (bool)($_POST['copy_sections'] ?? 1),
            'courses'  => (bool)($_POST['copy_courses']  ?? 1),
            'tags'     => (bool)($_POST['copy_tags']     ?? 1),
            'features' => (bool)($_POST['copy_features'] ?? 1),
        ];

        if (!in_array($source, $LOCS, true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid source location']); exit;
        }
        if (empty($targets) || !is_array($targets)) {
            echo json_encode(['success' => false, 'message' => 'No target locations selected']); exit;
        }

        $srcSections = fetchSectionsForLocation($db, $source);
        if (empty($srcSections)) {
            echo json_encode(['success' => false, 'message' => 'No course data found in source location']); exit;
        }

        $results = [];

        foreach ($targets as $target) {
            $target = trim($target);
            if (!in_array($target, $LOCS, true) || $target === $source) {
                $results[] = ['location' => $target, 'success' => false, 'message' => 'Invalid or same as source'];
                continue;
            }

            $db->beginTransaction();
            try {
                $sectionsCopied = 0;
                $coursesCopied  = 0;

                foreach ($srcSections as $sec) {
                    // Upsert section
                    $chk = $db->prepare("SELECT id FROM dm_sections WHERE location = ? AND section_id = ? LIMIT 1");
                    $chk->execute([$target, $sec['section_id']]);
                    $targetSecId = (int)$chk->fetchColumn();

                    if ($targetSecId) {
                        if ($opts['sections']) {
                            $db->prepare(
                                "UPDATE dm_sections SET section_title=?, component_type=?, sort_order=?, updated_at=NOW() WHERE id=?"
                            )->execute([$sec['section_title'], $sec['component_type'], $sec['sort_order'], $targetSecId]);
                        }
                    } else {
                        $db->prepare(
                            "INSERT INTO dm_sections (location, section_id, section_title, component_type, sort_order, updated_at)
                             VALUES (?,?,?,?,?,NOW())"
                        )->execute([$target, $sec['section_id'], $sec['section_title'], $sec['component_type'], $sec['sort_order']]);
                        $targetSecId = (int)$db->lastInsertId();
                    }
                    $sectionsCopied++;

                    if (!$opts['courses']) continue;

                    // Clear existing courses in this section for target
                    $old = $db->prepare("SELECT id FROM dm_courses WHERE section_db_id = ?");
                    $old->execute([$targetSecId]);
                    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
                        $db->prepare("DELETE FROM dm_course_tags     WHERE course_id=?")->execute([$oldId]);
                        $db->prepare("DELETE FROM dm_course_features WHERE course_id=?")->execute([$oldId]);
                    }
                    $db->prepare("DELETE FROM dm_courses WHERE section_db_id=?")->execute([$targetSecId]);

                    // Insert courses from source
                    foreach ($sec['courses'] as $c) {
                        $db->prepare(
                            "INSERT INTO dm_courses
                               (section_db_id, course_key, title, button_text, thumb, button_link, sort_order, updated_at, updated_by)
                             VALUES (?,?,?,?,?,?,?,NOW(),?)"
                        )->execute([
                            $targetSecId, $c['course_key'], $c['title'], $c['button_text'],
                            $c['thumb'], $c['button_link'], $c['sort_order'], $updatedBy
                        ]);
                        $newCid = (int)$db->lastInsertId();
                        $coursesCopied++;

                        if ($opts['tags']) {
                            $insTag = $db->prepare("INSERT INTO dm_course_tags (course_id, tag, sort_order) VALUES (?,?,?)");
                            foreach ($c['tags'] as $t) {
                                $v = trim($t['tag'] ?? '');
                                if ($v) $insTag->execute([$newCid, $v, (int)($t['sort_order'] ?? 1)]);
                            }
                        }

                        if ($opts['features']) {
                            $insFeat = $db->prepare("INSERT INTO dm_course_features (course_id, feature, sort_order) VALUES (?,?,?)");
                            foreach ($c['features'] as $f) {
                                $v = trim($f['feature'] ?? '');
                                if ($v) $insFeat->execute([$newCid, $v, (int)($f['sort_order'] ?? 1)]);
                            }
                        }
                    }
                }

                $db->commit();
                $results[] = [
                    'location'        => $target,
                    'success'         => true,
                    'sections_copied' => $sectionsCopied,
                    'courses_copied'  => $coursesCopied,
                ];

            } catch (Exception $e) {
                $db->rollBack();
                $results[] = ['location' => $target, 'success' => false, 'message' => $e->getMessage()];
            }
        }

        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// PAGE OUTPUT
// ══════════════════════════════════════════════════════════════════════════════
$pageTitle = 'Copy Course Data — DA360 Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: system-ui, -apple-system, sans-serif;
      background: #f1f5f9;
      color: #1e293b;
      min-height: 100vh;
    }

    /* ── Top nav ── */
    .topbar {
      background: #fff;
      border-bottom: 1px solid #e2e8f0;
      padding: 0 28px;
      height: 56px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .topbar-left { display: flex; align-items: center; gap: 16px; }
    .topbar-logo { font-size: 15px; font-weight: 700; color: #6366f1; letter-spacing: -.3px; }
    .topbar-sep  { color: #cbd5e1; font-size: 18px; }
    .topbar-title{ font-size: 14px; font-weight: 600; color: #334155; }
    .topbar-back {
      font-size: 13px; color: #6366f1; text-decoration: none; font-weight: 600;
      display: flex; align-items: center; gap: 5px;
    }
    .topbar-back:hover { opacity: .8; }

    /* ── Layout ── */
    .page { max-width: 900px; margin: 0 auto; padding: 32px 20px 60px; }

    .page-header { margin-bottom: 28px; }
    .page-header h1 { font-size: 22px; font-weight: 700; margin: 0 0 6px; color: #0f172a; }
    .page-header p  { margin: 0; font-size: 14px; color: #64748b; }

    /* ── Cards ── */
    .card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 24px;
      margin-bottom: 20px;
    }
    .card-title {
      font-size: 13px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .5px; color: #64748b; margin: 0 0 18px;
      display: flex; align-items: center; gap: 8px;
    }
    .card-title span { font-size: 16px; }

    /* ── Step badge ── */
    .step-badge {
      width: 26px; height: 26px; border-radius: 50%;
      background: #6366f1; color: #fff;
      font-size: 12px; font-weight: 700;
      display: inline-flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }

    /* ── Location selector (source) ── */
    label.field-label {
      display: block; font-size: 12px; font-weight: 600;
      color: #64748b; margin-bottom: 6px;
      text-transform: uppercase; letter-spacing: .4px;
    }
    select.loc-select {
      width: 100%; max-width: 340px;
      padding: 10px 14px;
      border: 1.5px solid #cbd5e1;
      border-radius: 8px;
      font-size: 14px; color: #1e293b;
      background: #fff; cursor: pointer;
      transition: border-color .15s;
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236366f1' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      padding-right: 32px;
    }
    select.loc-select:focus { border-color: #6366f1; outline: none; }

    /* ── Target chips ── */
    .chips-row {
      display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px;
    }
    .chip {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 9px 16px;
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      cursor: pointer;
      user-select: none;
      font-size: 13px; font-weight: 600;
      color: #475569;
      background: #f8fafc;
      transition: all .15s;
    }
    .chip:hover { border-color: #a5b4fc; background: #f5f3ff; color: #4338ca; }
    .chip.selected {
      border-color: #6366f1; background: #eef2ff; color: #4338ca;
    }
    .chip.disabled {
      opacity: .35; cursor: not-allowed; pointer-events: none;
    }
    .chip-check {
      width: 17px; height: 17px;
      border: 1.5px solid #cbd5e1;
      border-radius: 5px;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0; transition: all .15s;
    }
    .chip.selected .chip-check {
      background: #6366f1; border-color: #6366f1;
    }
    .chip-check svg { display: none; }
    .chip.selected .chip-check svg { display: block; }

    .chips-actions {
      display: flex; align-items: center; gap: 12px; margin-bottom: 10px;
    }
    .link-btn {
      font-size: 12px; color: #6366f1; font-weight: 600;
      background: none; border: none; cursor: pointer; padding: 0;
      text-decoration: underline; text-underline-offset: 2px;
    }
    .link-btn:hover { opacity: .75; }

    /* ── Options ── */
    .opts-grid {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      gap: 10px;
    }
    .opt-card {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 12px 14px;
      border: 1.5px solid #e2e8f0;
      border-radius: 10px;
      cursor: pointer; user-select: none;
      transition: all .15s;
      background: #f8fafc;
    }
    .opt-card:hover { border-color: #a5b4fc; background: #f5f3ff; }
    .opt-card.checked { border-color: #6366f1; background: #eef2ff; }
    .opt-card input[type=checkbox] { display: none; }
    .opt-icon {
      font-size: 20px; flex-shrink: 0; margin-top: 1px;
    }
    .opt-name { font-size: 13px; font-weight: 700; color: #334155; }
    .opt-desc { font-size: 11px; color: #94a3b8; margin-top: 2px; }
    .opt-card.checked .opt-name { color: #3730a3; }

    /* ── Warning banner ── */
    .warning-box {
      display: flex; gap: 10px; align-items: flex-start;
      background: #fffbeb; border: 1px solid #fcd34d;
      border-radius: 10px; padding: 12px 16px;
      font-size: 13px; color: #78350f;
      margin-bottom: 20px;
    }
    .warning-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }

    /* ── Buttons ── */
    .btn-row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }

    .btn-primary {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 11px 24px;
      background: #6366f1; color: #fff;
      border: none; border-radius: 9px;
      font-size: 14px; font-weight: 700;
      cursor: pointer; transition: opacity .15s;
    }
    .btn-primary:hover { opacity: .88; }
    .btn-primary:disabled { opacity: .45; cursor: not-allowed; }

    .btn-secondary {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 11px 20px;
      background: #f1f5f9; color: #475569;
      border: 1.5px solid #e2e8f0; border-radius: 9px;
      font-size: 14px; font-weight: 600;
      cursor: pointer; transition: all .15s;
    }
    .btn-secondary:hover { background: #e2e8f0; }
    .btn-secondary:disabled { opacity: .45; cursor: not-allowed; }

    /* ── Preview panel ── */
    .preview-panel {
      display: none;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 0;
      margin-top: 16px;
      overflow: hidden;
    }
    .preview-panel.visible { display: block; }
    .preview-header {
      background: #f1f5f9;
      border-bottom: 1px solid #e2e8f0;
      padding: 12px 18px;
      font-size: 13px; font-weight: 700; color: #334155;
    }
    .preview-body { padding: 16px 18px; }

    .sec-row {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 10px 0;
      border-bottom: 1px solid #e2e8f0;
    }
    .sec-row:last-child { border-bottom: none; }
    .sec-badge {
      font-size: 11px; font-weight: 700; padding: 3px 10px;
      border-radius: 20px; flex-shrink: 0; margin-top: 2px;
    }
    .sec-info { flex: 1; }
    .sec-title { font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 6px; }
    .course-list {
      display: flex; flex-direction: column; gap: 3px;
    }
    .course-item {
      font-size: 12px; color: #64748b;
      display: flex; align-items: center; gap: 6px;
    }
    .course-item::before { content: '•'; color: #a5b4fc; font-size: 10px; }
    .course-meta {
      display: inline-flex; gap: 4px; margin-left: 4px;
    }
    .meta-tag {
      font-size: 10px; padding: 1px 6px; border-radius: 99px;
      background: #ede9fe; color: #5b21b6; font-weight: 600;
    }

    /* ── Results panel ── */
    .results-panel {
      display: none;
    }
    .results-panel.visible { display: block; }

    .result-item {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 14px 18px;
      border-bottom: 1px solid #e2e8f0;
    }
    .result-item:last-child { border-bottom: none; }

    .result-icon { font-size: 22px; flex-shrink: 0; margin-top: 1px; }
    .result-loc  { font-size: 14px; font-weight: 700; color: #1e293b; }
    .result-detail { font-size: 13px; color: #64748b; margin-top: 2px; }
    .result-detail.err { color: #dc2626; }

    /* ── Status bar ── */
    .status-bar {
      display: none;
      align-items: center; gap: 12px;
      background: #eef2ff;
      border: 1px solid #c7d2fe;
      border-radius: 10px;
      padding: 14px 18px;
      font-size: 14px; color: #3730a3; font-weight: 600;
      margin-top: 16px;
    }
    .status-bar.visible { display: flex; }

    .spinner {
      width: 18px; height: 18px;
      border: 2.5px solid #c7d2fe;
      border-top-color: #6366f1;
      border-radius: 50%;
      animation: spin .7s linear infinite;
      flex-shrink: 0;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Success banner ── */
    .success-banner {
      display: none;
      align-items: center; gap: 12px;
      background: #f0fdf4;
      border: 1px solid #86efac;
      border-radius: 10px;
      padding: 14px 18px;
      font-size: 14px; color: #166534; font-weight: 600;
      margin-top: 16px;
    }
    .success-banner.visible { display: flex; }

    .error-banner {
      display: none;
      background: #fef2f2; border: 1px solid #fca5a5;
      border-radius: 10px; padding: 14px 18px;
      font-size: 14px; color: #991b1b; font-weight: 600;
      margin-top: 16px;
    }
    .error-banner.visible { display: block; }

    /* ── Section type colours ── */
    .type-Leadership    { background: #f5f3ff; color: #7c3aed; }
    .type-PostGraduate  { background: #eff6ff; color: #2563eb; }
    .type-Certification { background: #f0fdf4; color: #059669; }
    .type-College       { background: #fffbeb; color: #d97706; }

    /* ── Responsive ── */
    @media (max-width: 640px) {
      .page { padding: 20px 14px 40px; }
      .opts-grid { grid-template-columns: 1fr 1fr; }
    }
  </style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
  <div class="topbar-left">
    <span class="topbar-logo">DA360 Admin</span>
    <span class="topbar-sep">›</span>
    <span class="topbar-title">Copy Course Data</span>
  </div>
  <a class="topbar-back" href="/da360-admin/">← Back to Dashboard</a>
</div>

<!-- Page -->
<div class="page">

  <div class="page-header">
    <h1>📋 Copy Course Data</h1>
    <p>Replicate sections and courses from one location to one or more target locations. Existing course data in the targets will be replaced.</p>
  </div>

  <!-- Warning -->
  <div class="warning-box">
    <span class="warning-icon">⚠️</span>
    <div>
      <strong>This action overwrites existing course data.</strong>
      All courses in the selected sections of each target location will be deleted and replaced with the source data.
      Use the <strong>Preview</strong> button first to review what will be copied.
    </div>
  </div>

  <!-- ── Step 1: Source ── -->
  <div class="card">
    <div class="card-title">
      <div class="step-badge">1</div>
      Select source location
    </div>
    <label class="field-label">Copy course data from</label>
    <select class="loc-select" id="src-select">
      <option value="">— choose a location —</option>
      <?php foreach ($locations as $loc): ?>
      <option value="<?= htmlspecialchars($loc['slug']) ?>">
        <?= htmlspecialchars($loc['label']) ?>
      </option>
      <?php endforeach; ?>
    </select>

    <div class="preview-panel" id="preview-panel">
      <div class="preview-header" id="preview-header">Loading…</div>
      <div class="preview-body" id="preview-body"></div>
    </div>
  </div>

  <!-- ── Step 2: Targets ── -->
  <div class="card">
    <div class="card-title">
      <div class="step-badge">2</div>
      Select target locations
    </div>
    <div class="chips-actions">
      <button class="link-btn" id="btn-select-all">Select all</button>
      <button class="link-btn" id="btn-clear-all">Clear all</button>
      <span id="sel-count" style="font-size:12px;color:#94a3b8;font-weight:600;"></span>
    </div>
    <div class="chips-row" id="chips-row">
      <?php foreach ($locations as $loc): ?>
      <div class="chip" data-loc="<?= htmlspecialchars($loc['slug']) ?>">
        <div class="chip-check">
          <svg width="10" height="8" viewBox="0 0 10 8" fill="none">
            <path d="M1 4L4 7L9 1" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <?= htmlspecialchars($loc['label']) ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── Step 3: Options ── -->
  <div class="card">
    <div class="card-title">
      <div class="step-badge">3</div>
      What to copy
    </div>
    <div class="opts-grid">
      <label class="opt-card checked" id="opt-sections-card">
        <input type="checkbox" id="opt-sections" checked>
        <div>
          <div class="opt-icon">📂</div>
          <div class="opt-name">Section titles</div>
          <div class="opt-desc">Leadership, PG, Certification headings</div>
        </div>
      </label>
      <label class="opt-card checked" id="opt-courses-card">
        <input type="checkbox" id="opt-courses" checked>
        <div>
          <div class="opt-icon">📚</div>
          <div class="opt-name">Courses</div>
          <div class="opt-desc">Title, key, thumb, button text & link</div>
        </div>
      </label>
      <label class="opt-card checked" id="opt-tags-card">
        <input type="checkbox" id="opt-tags" checked>
        <div>
          <div class="opt-icon">🏷️</div>
          <div class="opt-name">Tags</div>
          <div class="opt-desc">Course duration, type labels</div>
        </div>
      </label>
      <label class="opt-card checked" id="opt-features-card">
        <input type="checkbox" id="opt-features" checked>
        <div>
          <div class="opt-icon">✨</div>
          <div class="opt-name">Features</div>
          <div class="opt-desc">Learning hours, placement details</div>
        </div>
      </label>
    </div>
  </div>

  <!-- ── Step 4: Actions ── -->
  <div class="card">
    <div class="card-title">
      <div class="step-badge">4</div>
      Run
    </div>
    <div class="btn-row">
      <button class="btn-primary" id="btn-copy" disabled>
        📋 Copy Course Data
      </button>
      <button class="btn-secondary" id="btn-reset">🔄 Reset</button>
    </div>

    <div class="status-bar" id="status-bar">
      <div class="spinner"></div>
      <span id="status-msg">Copying…</span>
    </div>

    <div class="success-banner" id="success-banner">
      ✅ Copy complete!
    </div>

    <div class="error-banner" id="error-banner"></div>

    <!-- Results -->
    <div class="results-panel" id="results-panel">
      <div style="margin-top:16px;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
        <div style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;padding:11px 18px;font-size:13px;font-weight:700;color:#334155;">
          Results
        </div>
        <div id="results-body"></div>
      </div>
    </div>
  </div>

</div><!-- /.page -->

<script>
(function () {

  var SELF = window.location.pathname;

  var srcSelect   = document.getElementById('src-select');
  var chips       = Array.from(document.querySelectorAll('.chip'));
  var btnCopy     = document.getElementById('btn-copy');
  var btnReset    = document.getElementById('btn-reset');
  var btnAll      = document.getElementById('btn-select-all');
  var btnClear    = document.getElementById('btn-clear-all');
  var selCount    = document.getElementById('sel-count');
  var previewPanel= document.getElementById('preview-panel');
  var previewHdr  = document.getElementById('preview-header');
  var previewBody = document.getElementById('preview-body');
  var statusBar   = document.getElementById('status-bar');
  var statusMsg   = document.getElementById('status-msg');
  var successBnr  = document.getElementById('success-banner');
  var errorBnr    = document.getElementById('error-banner');
  var resultsPanel= document.getElementById('results-panel');
  var resultsBody = document.getElementById('results-body');

  // ── Opt cards toggle ────────────────────────────────────────────────────────
  ['sections','courses','tags','features'].forEach(function(k){
    var card = document.getElementById('opt-'+k+'-card');
    var chk  = document.getElementById('opt-'+k);
    card.addEventListener('click', function(e){
      e.preventDefault();
      chk.checked = !chk.checked;
      card.classList.toggle('checked', chk.checked);
    });
  });

  // ── Update chip disabled state when source changes ──────────────────────────
  function syncChips() {
    var src = srcSelect.value;
    chips.forEach(function(c){
      if (c.dataset.loc === src) {
        c.classList.add('disabled');
        c.classList.remove('selected');
      } else {
        c.classList.remove('disabled');
      }
    });
    updateSelCount();
    updateCopyBtn();
  }

  function updateSelCount() {
    var n = getTargets().length;
    selCount.textContent = n ? n + ' location' + (n>1?'s':'') + ' selected' : '';
  }

  function getTargets() {
    return chips
      .filter(function(c){ return c.classList.contains('selected') && !c.classList.contains('disabled'); })
      .map(function(c){ return c.dataset.loc; });
  }

  function updateCopyBtn() {
    btnCopy.disabled = !(srcSelect.value && getTargets().length > 0);
  }

  // ── Chip clicks ─────────────────────────────────────────────────────────────
  chips.forEach(function(c){
    c.addEventListener('click', function(){
      if (c.classList.contains('disabled')) return;
      c.classList.toggle('selected');
      updateSelCount();
      updateCopyBtn();
    });
  });

  btnAll.addEventListener('click', function(){
    chips.forEach(function(c){ if(!c.classList.contains('disabled')) c.classList.add('selected'); });
    updateSelCount(); updateCopyBtn();
  });

  btnClear.addEventListener('click', function(){
    chips.forEach(function(c){ c.classList.remove('selected'); });
    updateSelCount(); updateCopyBtn();
  });

  // ── Source select: load preview ─────────────────────────────────────────────
  var previewXhr = null;
  srcSelect.addEventListener('change', function(){
    syncChips();
    clearResults();
    var src = srcSelect.value;
    if (!src) { previewPanel.classList.remove('visible'); return; }

    previewPanel.classList.add('visible');
    previewHdr.textContent = 'Loading preview…';
    previewBody.innerHTML  = '';

    if (previewXhr) previewXhr.abort();

    var fd = new FormData();
    fd.append('action', 'preview');
    fd.append('source', src);

    fetch(SELF, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res.success) { previewHdr.textContent = 'Error: ' + res.message; return; }
        var sections = res.sections;
        var totalCourses = sections.reduce(function(a,s){ return a + s.course_count; }, 0);
        previewHdr.innerHTML =
          '<strong>' + sections.length + ' sections</strong>, ' +
          '<strong>' + totalCourses + ' courses</strong> will be copied from <em>' + escHtml(srcSelect.options[srcSelect.selectedIndex].text) + '</em>';

        var html = '';
        sections.forEach(function(sec){
          var typeClass = 'type-' + sec.component_type;
          html += '<div class="sec-row">';
          html += '<span class="sec-badge '+typeClass+'">'+escHtml(sec.component_type)+'</span>';
          html += '<div class="sec-info">';
          html += '<div class="sec-title">'+escHtml(sec.section_title)+'</div>';
          html += '<div class="course-list">';
          sec.courses.forEach(function(c){
            html += '<div class="course-item">'+escHtml(c.title)+
              '<span class="course-meta">'+
              (c.tags    ? '<span class="meta-tag">'+c.tags+' tags</span>'    : '')+
              (c.features? '<span class="meta-tag">'+c.features+' features</span>':'')+
              '</span></div>';
          });
          html += '</div></div></div>';
        });
        previewBody.innerHTML = html;
      })
      .catch(function(){ previewHdr.textContent = 'Could not load preview.'; });
  });

  // ── Copy ────────────────────────────────────────────────────────────────────
  btnCopy.addEventListener('click', function(){
    var src     = srcSelect.value;
    var targets = getTargets();
    if (!src || !targets.length) return;

    if (!confirm('Copy course data from "' + srcSelect.options[srcSelect.selectedIndex].text + '" to ' + targets.length + ' location(s)?\n\nExisting courses in those locations will be replaced.')) return;

    clearResults();
    btnCopy.disabled = true;
    statusBar.classList.add('visible');
    statusMsg.textContent = 'Copying to ' + targets.length + ' location(s)…';

    var fd = new FormData();
    fd.append('action',         'copy');
    fd.append('source',         src);
    fd.append('targets',        JSON.stringify(targets));
    fd.append('copy_sections',  document.getElementById('opt-sections').checked ? '1' : '0');
    fd.append('copy_courses',   document.getElementById('opt-courses').checked  ? '1' : '0');
    fd.append('copy_tags',      document.getElementById('opt-tags').checked     ? '1' : '0');
    fd.append('copy_features',  document.getElementById('opt-features').checked ? '1' : '0');

    fetch(SELF, { method:'POST', body:fd })
      .then(function(r){ return r.json(); })
      .then(function(res){
        statusBar.classList.remove('visible');
        btnCopy.disabled = false;

        if (!res.success) {
          showError(res.message || 'Copy failed.');
          return;
        }

        var allOk  = res.results.every(function(r){ return r.success; });
        var anyOk  = res.results.some(function(r){ return r.success; });

        if (anyOk) successBnr.classList.add('visible');

        resultsPanel.classList.add('visible');
        var html = '';
        res.results.forEach(function(r){
          var icon   = r.success ? '✅' : '❌';
          var detail = r.success
            ? r.sections_copied + ' sections, ' + r.courses_copied + ' courses copied'
            : (r.message || 'Failed');
          var detailClass = r.success ? '' : 'err';
          html +=
            '<div class="result-item">'+
            '<span class="result-icon">'+icon+'</span>'+
            '<div>'+
              '<div class="result-loc">'+escHtml(r.location)+'</div>'+
              '<div class="result-detail '+detailClass+'">'+escHtml(detail)+'</div>'+
            '</div></div>';
        });
        resultsBody.innerHTML = html;
      })
      .catch(function(){
        statusBar.classList.remove('visible');
        btnCopy.disabled = false;
        showError('Network error — please try again.');
      });
  });

  // ── Reset ───────────────────────────────────────────────────────────────────
  btnReset.addEventListener('click', function(){
    srcSelect.value = '';
    chips.forEach(function(c){ c.classList.remove('selected','disabled'); });
    previewPanel.classList.remove('visible');
    previewBody.innerHTML = '';
    clearResults();
    updateSelCount();
    updateCopyBtn();
  });

  // ── Helpers ─────────────────────────────────────────────────────────────────
  function clearResults(){
    successBnr.classList.remove('visible');
    errorBnr.classList.remove('visible');
    errorBnr.textContent = '';
    resultsPanel.classList.remove('visible');
    resultsBody.innerHTML = '';
    statusBar.classList.remove('visible');
  }

  function showError(msg){
    errorBnr.textContent = '❌ ' + msg;
    errorBnr.classList.add('visible');
  }

  function escHtml(s){
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

})();
</script>
</body>
</html>