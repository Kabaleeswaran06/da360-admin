<?php
/**
 * DA360 — FAQ Importer UI
 * Place in project root (same level as config/)
 * Access via: https://yoursite.com/da360-admin/import_faqs_ui.php
 *
 * CSV FORMAT (3 columns, no location/course needed — chosen via dropdowns):
 *   category,question,answer
 *   Program,"What is the best course?","The best course is..."
 *   Delivery,"How long is the course?","It takes 6 months."
 */

require_once __DIR__ . '/config/db.php';

// ── Data ──────────────────────────────────────────────────────────────────────
$courses = [
    1  => 'Leadership in Digital Marketing, AI & Entrepreneurship',
    2  => 'Social Content Creator & Video Production',
    3  => 'PGCP DM',
    4  => 'PGCP PM',
    5  => 'Skill Diploma Program',
    6  => 'Youtube & Instagram',
    7  => 'Performance Marketing & MarTech',
    8  => 'BBA',
    9  => 'MBA',
    10 => 'AI Automation Vibe Marketing',
];

$locations = [
    1  => 'Global',
    2  => 'Bangalore',
    3  => 'Jayanagar',
    4  => 'JP Nagar',
    5  => 'Malleshwaram',
    6  => 'Hubli',
    7  => 'Dharwad',
    8  => 'Mysuru',
    9  => 'Mangaluru',
    10 => 'Belagavi',
    11 => 'Mumbai',
    12 => 'Pune',
    13 => 'New Delhi',
    14 => 'NCR',
    15 => 'Hyderabad',
    16 => 'Visakhapatnam',
    17 => 'Ahmedabad',
    18 => 'Surat',
    19 => 'Vadodara',
    20 => 'Chennai',
    21 => 'Jaipur',
    22 => 'Lucknow',
    23 => 'Kanpur',
    24 => 'Varanasi',
    25 => 'Noida',
    26 => 'Indore',
    27 => 'Bhopal',
    28 => 'Kolkata',
    29 => 'Howrah',
];

$validCategories = ['Program', 'Delivery', 'Placement', 'Certification', 'Fee'];

$categoryAliases = [
    'program'       => 'Program',
    'delivery'      => 'Delivery',
    'placement'     => 'Placement',
    'certification' => 'Certification',
    'fees'          => 'Fee',
    'fee'           => 'Fee',
];

// ── Process upload ────────────────────────────────────────────────────────────
$result  = null;
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId   = (int)($_POST['course_id']   ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);

    if (!$courseId || !isset($courses[$courseId])) {
        $errors[] = 'Please select a valid Course.';
    }
    if (!$locationId || !isset($locations[$locationId])) {
        $errors[] = 'Please select a valid Location.';
    }
    if (empty($_FILES['csv_file']['tmp_name'])) {
        $errors[] = 'Please upload a CSV file.';
    }

    $ext = strtolower(pathinfo($_FILES['csv_file']['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        $errors[] = 'Only .csv files are accepted.';
    }

    if (empty($errors)) {
        // ── Parse CSV ─────────────────────────────────────────────────────────
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header = null;
        $rows   = [];
        $rowNum = 1;

        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rowNum++;
            if ($header === null) {
                $header = array_map(fn($h) => strtolower(trim($h)), $line);
                continue;
            }
            if (count($line) < 3) continue;
            $rows[] = array_combine(
                array_slice($header, 0, 3),
                array_map('trim', array_slice($line, 0, 3))
            );
        }
        fclose($handle);

        // Validate header columns
        $required = ['category', 'question', 'answer'];
        $missing  = array_diff($required, $header ?? []);
        if (!empty($missing)) {
            $errors[] = 'Missing CSV columns: ' . implode(', ', $missing) . '. Required: category, question, answer';
        }

        if (empty($errors) && !empty($rows)) {
            // ── Import ────────────────────────────────────────────────────────
            try {
                $db  = getDB();
                $sql = "
                    INSERT INTO course_faqs
                        (course_id, location_id, category, sort_order, question, answer, is_active, created_at, updated_at)
                    VALUES
                        (:course_id, :location_id, :category, :sort_order, :question, :answer, 1, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        question   = VALUES(question),
                        answer     = VALUES(answer),
                        is_active  = 1,
                        updated_at = NOW()
                ";
                $stmt = $db->prepare($sql);

                // Group by category for sort_order tracking
                $grouped   = [];
                $skipped   = [];

                foreach ($rows as $i => $row) {
                    $catKey = strtolower($row['category'] ?? '');
                    $cat    = $categoryAliases[$catKey] ?? null;

                    if (!$cat) {
                        $skipped[] = "Row " . ($i + 2) . ": Unknown category '{$row['category']}'";
                        continue;
                    }
                    if (trim($row['question']) === '' && trim($row['answer']) === '') continue;

                    $grouped[$cat][] = [
                        'question' => $row['question'],
                        'answer'   => $row['answer'],
                    ];
                }

                $total = 0;
                foreach ($grouped as $cat => $items) {
                    $items     = array_slice($items, 0, 10);
                    $sortOrder = 1;
                    foreach ($items as $item) {
                        $stmt->execute([
                            'course_id'   => $courseId,
                            'location_id' => $locationId,
                            'category'    => $cat,
                            'sort_order'  => $sortOrder,
                            'question'    => $item['question'],
                            'answer'      => $item['answer'],
                        ]);
                        $sortOrder++;
                        $total++;
                    }
                    $success[] = "[{$cat}] — " . count($items) . " FAQs imported";
                }

                $result = [
                    'total'    => $total,
                    'course'   => $courses[$courseId],
                    'location' => $locations[$locationId],
                    'skipped'  => $skipped,
                ];

            } catch (Exception $e) {
                $errors[] = 'DB Error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FAQ Importer — DA360</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; }
  .card { background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.08); width: 100%; max-width: 620px; overflow: hidden; }
  .card-header { background: linear-gradient(135deg, #6366f1, #8b5cf6); padding: 28px 32px; }
  .card-header h1 { color: #fff; font-size: 20px; font-weight: 700; }
  .card-header p  { color: rgba(255,255,255,.75); font-size: 13px; margin-top: 4px; }
  .card-body { padding: 32px; }

  .field { margin-bottom: 20px; }
  label  { display: block; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 6px; }
  select, input[type=file] {
    width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1;
    border-radius: 8px; font-size: 14px; color: #1e293b; background: #fff;
    transition: border-color .15s;
  }
  select:focus { border-color: #6366f1; outline: none; }

  .file-zone {
    border: 2px dashed #cbd5e1; border-radius: 10px; padding: 28px;
    text-align: center; cursor: pointer; transition: border-color .2s, background .2s;
    position: relative;
  }
  .file-zone:hover, .file-zone.dragover { border-color: #6366f1; background: #f5f3ff; }
  .file-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer;
    width: 100%; height: 100%; padding: 0; border: none;
  }
  .file-zone .icon  { font-size: 32px; margin-bottom: 8px; }
  .file-zone .label { font-size: 14px; color: #475569; font-weight: 600; }
  .file-zone .sub   { font-size: 12px; color: #94a3b8; margin-top: 4px; }
  .file-zone .chosen { font-size: 13px; color: #6366f1; font-weight: 600; margin-top: 8px; display: none; }

  .btn-submit {
    width: 100%; padding: 13px; background: #6366f1; color: #fff;
    border: none; border-radius: 8px; font-size: 15px; font-weight: 700;
    cursor: pointer; transition: opacity .15s; margin-top: 8px;
  }
  .btn-submit:hover { opacity: .88; }
  .btn-submit:disabled { opacity: .5; cursor: default; }

  /* Format hint */
  .hint { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; margin-bottom: 24px; font-size: 12px; color: #475569; }
  .hint strong { color: #1e293b; }
  .hint code { background: #e2e8f0; padding: 1px 5px; border-radius: 4px; font-size: 11px; }

  /* Alerts */
  .alert { border-radius: 8px; padding: 14px 16px; margin-bottom: 20px; font-size: 13px; }
  .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
  .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
  .alert ul { margin: 6px 0 0 16px; }
  .alert li { margin-bottom: 3px; }

  /* Results */
  .result-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-top: 24px; }
  .result-box h3 { font-size: 14px; font-weight: 700; color: #1e293b; margin-bottom: 12px; }
  .result-meta { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
  .meta-pill { font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 20px; background: #e0e7ff; color: #4338ca; }
  .result-row { display: flex; align-items: center; gap: 8px; padding: 7px 0; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #374151; }
  .result-row:last-child { border-bottom: none; }
  .badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; background: #dcfce7; color: #15803d; margin-left: auto; }
  .total-row { font-size: 15px; font-weight: 700; color: #1e293b; margin-top: 12px; padding-top: 12px; border-top: 2px solid #e2e8f0; display: flex; justify-content: space-between; }
  .skip-row { font-size: 12px; color: #f59e0b; padding: 4px 0; }
</style>
</head>
<body>
<div class="card">

  <div class="card-header">
    <h1>📥 FAQ Bulk Importer</h1>
    <p>Upload a CSV file to import FAQs into any course and location</p>
  </div>

  <div class="card-body">

    <!-- ── Error alert ── -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <strong>❌ Please fix the following:</strong>
      <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <!-- ── Success results ── -->
    <?php if ($result): ?>
    <div class="alert alert-success">✅ Import completed successfully!</div>
    <div class="result-box">
      <h3>📊 Import Summary</h3>
      <div class="result-meta">
        <span class="meta-pill">📚 <?= htmlspecialchars($result['course']) ?></span>
        <span class="meta-pill">📍 <?= htmlspecialchars($result['location']) ?></span>
      </div>
      <?php foreach ($success as $line):
        preg_match('/\[(.+?)\].*?(\d+)/', $line, $m); ?>
      <div class="result-row">
        <span>✅</span>
        <span><?= htmlspecialchars($m[1] ?? $line) ?></span>
        <span class="badge"><?= ($m[2] ?? '') ?> FAQs</span>
      </div>
      <?php endforeach; ?>
      <?php if (!empty($result['skipped'])): ?>
        <?php foreach ($result['skipped'] as $s): ?>
        <div class="skip-row">⚠️ <?= htmlspecialchars($s) ?></div>
        <?php endforeach; ?>
      <?php endif; ?>
      <div class="total-row">
        <span>Total Imported</span>
        <span><?= $result['total'] ?> FAQs</span>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── CSV format hint ── -->
    <div class="hint">
      <strong>CSV format</strong> — 3 columns, header row required:<br><br>
      <code>category,question,answer</code><br>
      <code>Program,"What is the best course?","The best course is..."</code><br>
      <code>Delivery,"How long is the course?","It takes 6 months."</code><br><br>
      Valid categories: <code>Program</code> &nbsp;<code>Delivery</code> &nbsp;<code>Placement</code> &nbsp;<code>Certification</code> &nbsp;<code>Fee</code>
    </div>

    <!-- ── Form ── -->
    <form method="POST" enctype="multipart/form-data" id="import-form">

      <div class="field">
        <label>Course</label>
        <select name="course_id" required>
          <option value="">— Select Course —</option>
          <?php foreach ($courses as $id => $label): ?>
          <option value="<?= $id ?>" <?= (isset($_POST['course_id']) && $_POST['course_id'] == $id) ? 'selected' : '' ?>>
            <?= $id ?>. <?= htmlspecialchars($label) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label>Location</label>
        <select name="location_id" required>
          <option value="">— Select Location —</option>
          <?php foreach ($locations as $id => $label): ?>
          <option value="<?= $id ?>" <?= (isset($_POST['location_id']) && $_POST['location_id'] == $id) ? 'selected' : '' ?>>
            <?= $id ?>. <?= htmlspecialchars($label) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label>CSV File</label>
        <div class="file-zone" id="file-zone">
          <input type="file" name="csv_file" accept=".csv" id="csv-input" required>
          <div class="icon">📄</div>
          <div class="label">Click to upload or drag & drop</div>
          <div class="sub">CSV files only</div>
          <div class="chosen" id="file-chosen"></div>
        </div>
      </div>

      <button type="submit" class="btn-submit" id="submit-btn">
        ⬆️ Import FAQs
      </button>
    </form>

  </div>
</div>

<script>
  // Show chosen filename
  const input   = document.getElementById('csv-input');
  const chosen  = document.getElementById('file-chosen');
  const zone    = document.getElementById('file-zone');
  const btnSub  = document.getElementById('submit-btn');

  input.addEventListener('change', () => {
    if (input.files[0]) {
      chosen.textContent = '📎 ' + input.files[0].name;
      chosen.style.display = 'block';
    }
  });

  // Drag & drop highlight
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop',      e => { e.preventDefault(); zone.classList.remove('dragover'); });

  // Disable button on submit
  document.getElementById('import-form').addEventListener('submit', () => {
    btnSub.disabled    = true;
    btnSub.textContent = '⏳ Importing...';
  });
</script>
</body>
</html>
