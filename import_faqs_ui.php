<?php
/**
 * DA360 — FAQ Importer UI (Excel 97-2003 .xls)
 * Place in project root (same level as config/)
 *
 * REQUIREMENT — no composer needed, just one file:
 *   Download SimpleXLS.php from:
 *   https://raw.githubusercontent.com/shuchkin/simplexls/master/src/SimpleXLS.php
 *   Place it in the same folder as this file.
 *
 * EXCEL FORMAT (.xls):
 *   Row 1 : PROGRAM | | DELIVERY | | PLACEMENT | | CERTIFICATION | | FEES
 *   Row 2 : Question | Answer | Question | Answer | ...  (skipped)
 *   Row 3+: data (max 10 rows per category)
 */

require_once __DIR__ . '/config/db.php';

use Shuchkin\SimpleXLS;

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
    1  => 'Global',        2  => 'Bangalore',     3  => 'Jayanagar',
    4  => 'JP Nagar',      5  => 'Malleshwaram',   6  => 'Hubli',
    7  => 'Dharwad',       8  => 'Mysuru',         9  => 'Mangaluru',
    10 => 'Belagavi',      11 => 'Mumbai',         12 => 'Pune',
    13 => 'New Delhi',     14 => 'NCR',            15 => 'Hyderabad',
    16 => 'Visakhapatnam', 17 => 'Ahmedabad',      18 => 'Surat',
    19 => 'Vadodara',      20 => 'Chennai',        21 => 'Jaipur',
    22 => 'Lucknow',       23 => 'Kanpur',         24 => 'Varanasi',
    25 => 'Noida',         26 => 'Indore',         27 => 'Bhopal',
    28 => 'Kolkata',       29 => 'Howrah',
];

$categoryAliases = [
    'program'       => 'Program',
    'delivery'      => 'Delivery',
    'placement'     => 'Placement',
    'certification' => 'Certification',
    'fees'          => 'Fee',
    'fee'           => 'Fee',
];

// ── Check SimpleXLS is available ──────────────────────────────────────────────
$simpleXlsPath = __DIR__ . '/SimpleXLS.php';
$libMissing    = !file_exists($simpleXlsPath);

// ── Process upload ────────────────────────────────────────────────────────────
$result  = null;
$errors  = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId   = (int)($_POST['course_id']   ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);

    if (!$courseId   || !isset($courses[$courseId]))     $errors[] = 'Please select a valid Course.';
    if (!$locationId || !isset($locations[$locationId])) $errors[] = 'Please select a valid Location.';
    if (empty($_FILES['xls_file']['tmp_name']))           $errors[] = 'Please upload an Excel file.';

    $uploadedName = $_FILES['xls_file']['name'] ?? '';
    $ext = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));
    if (!empty($uploadedName) && $ext !== 'xls') {
        $errors[] = 'Only .xls (Excel 97-2003) files are accepted.';
    }

    if ($libMissing) {
        $errors[] = 'SimpleXLS.php not found — see the setup instructions above.';
    }

    if (empty($errors)) {
        require_once $simpleXlsPath;

        if (!class_exists(SimpleXLS::class)) {
            $errors[] = 'SimpleXLS.php was loaded, but the class could not be found.';
        } else {
            $xls = SimpleXLS::parse($_FILES['xls_file']['tmp_name']);

            if (!$xls) {
                $errors[] = 'Could not read the .xls file: ' . SimpleXLS::parseError();
            } else {
                // rows() returns 0-indexed 2D array
                $data = $xls->rows();

                if (count($data) < 3) {
                    $errors[] = 'Excel file must have at least 3 rows (category header, sub-header, data).';
                } else {
                    // ── Row 0: category headers ───────────────────────────────────
                    $categoryRow    = array_map(fn($v) => strtolower(trim((string)$v)), $data[0]);
                    $catQuestionCol = []; // category => question col index

                    foreach ($categoryRow as $colIdx => $cellVal) {
                        if ($cellVal !== '' && isset($categoryAliases[$cellVal])) {
                            $cat = $categoryAliases[$cellVal];
                            if (!isset($catQuestionCol[$cat])) {
                                $catQuestionCol[$cat] = $colIdx;
                            }
                        }
                    }

                    if (empty($catQuestionCol)) {
                        $errors[] = 'No valid category headers found in Row 1. Expected: PROGRAM, DELIVERY, PLACEMENT, CERTIFICATION, FEES';
                    } else {
                        // ── Rows 2+: data ─────────────────────────────────────────
                        $grouped = [];
                        for ($rowIdx = 2; $rowIdx < count($data); $rowIdx++) {
                            $row = array_map(fn($v) => trim((string)$v), $data[$rowIdx]);
                            foreach ($catQuestionCol as $cat => $qCol) {
                                $aCol     = $qCol + 1;
                                $question = $row[$qCol] ?? '';
                                $answer   = $row[$aCol] ?? '';
                                if ($question === '' && $answer === '') continue;
                                $grouped[$cat][] = ['question' => $question, 'answer' => $answer];
                            }
                        }

                        if (empty($grouped)) {
                            $errors[] = 'No data rows found after the header rows.';
                        } else {
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
                                $stmt  = $db->prepare($sql);
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
                                    $success[] = ['cat' => $cat, 'count' => count($items)];
                                }

                                $result = [
                                    'total'    => $total,
                                    'course'   => $courses[$courseId],
                                    'location' => $locations[$locationId],
                                ];
                            } catch (Exception $e) {
                                $errors[] = 'DB Error: ' . $e->getMessage();
                            }
                        }
                    }
                }
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

  .card { background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,.08); width: 100%; max-width: 640px; overflow: hidden; }
  .card-header { background: linear-gradient(135deg, #6366f1, #8b5cf6); padding: 28px 32px; }
  .card-header h1 { color: #fff; font-size: 20px; font-weight: 700; }
  .card-header p  { color: rgba(255,255,255,.75); font-size: 13px; margin-top: 4px; }
  .card-body { padding: 32px; }

  /* Setup banner */
  .setup-banner { background: #fffbeb; border: 1.5px solid #fcd34d; border-radius: 10px; padding: 16px 18px; margin-bottom: 24px; }
  .setup-banner h3 { font-size: 13px; font-weight: 700; color: #92400e; margin-bottom: 8px; }
  .setup-banner ol { margin-left: 18px; font-size: 12px; color: #78350f; line-height: 1.8; }
  .setup-banner code { background: #fef3c7; padding: 1px 6px; border-radius: 4px; font-size: 11px; font-family: monospace; }
  .setup-banner a { color: #d97706; word-break: break-all; }

  .field { margin-bottom: 20px; }
  label  { display: block; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 6px; }
  select { width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; color: #1e293b; background: #fff; transition: border-color .15s; }
  select:focus { border-color: #6366f1; outline: none; }

  .file-zone {
    border: 2px dashed #cbd5e1; border-radius: 10px; padding: 36px 20px;
    text-align: center; cursor: pointer; transition: all .2s; position: relative; background: #fafafa;
  }
  .file-zone:hover, .file-zone.dragover { border-color: #6366f1; background: #f5f3ff; }
  .file-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; padding: 0; border: none;
  }
  .file-zone .fz-icon  { font-size: 36px; margin-bottom: 8px; }
  .file-zone .fz-label { font-size: 14px; color: #475569; font-weight: 600; }
  .file-zone .fz-sub   { font-size: 12px; color: #94a3b8; margin-top: 4px; }
  .fz-chosen { font-size: 13px; color: #6366f1; font-weight: 600; margin-top: 10px; background: #e0e7ff; padding: 6px 12px; border-radius: 6px; display: none; }

  .hint { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 24px; font-size: 12px; color: #475569; }
  .hint strong { color: #1e293b; display: block; margin-bottom: 8px; }
  .format-table { width: 100%; border-collapse: collapse; font-size: 11px; }
  .format-table td { padding: 4px 6px; border: 1px solid #e2e8f0; text-align: center; }
  .cat-head  { background: #6366f1; color: #fff; font-weight: 700; }
  .sub-head  { background: #e0e7ff; color: #4338ca; font-style: italic; }
  .data-row  { background: #fff; color: #475569; }
  .hint-note { margin-top: 8px; font-size: 11px; color: #94a3b8; }

  .btn-submit { width: 100%; padding: 13px; background: #6366f1; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; transition: opacity .15s; margin-top: 8px; }
  .btn-submit:hover    { opacity: .88; }
  .btn-submit:disabled { opacity: .5; cursor: default; }

  .alert { border-radius: 8px; padding: 14px 16px; margin-bottom: 20px; font-size: 13px; }
  .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
  .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
  .alert ul { margin: 6px 0 0 16px; }
  .alert li { margin-bottom: 3px; }

  .result-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-top: 24px; }
  .result-box h3 { font-size: 14px; font-weight: 700; color: #1e293b; margin-bottom: 12px; }
  .result-meta { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
  .meta-pill { font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; background: #e0e7ff; color: #4338ca; }
  .result-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #374151; }
  .result-row:last-of-type { border-bottom: none; }
  .badge { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: #dcfce7; color: #15803d; margin-left: auto; }
  .total-row { font-size: 15px; font-weight: 700; color: #1e293b; margin-top: 12px; padding-top: 12px; border-top: 2px solid #e2e8f0; display: flex; justify-content: space-between; }
</style>
</head>
<body>
<div class="card">

  <div class="card-header">
    <h1>📥 FAQ Bulk Importer</h1>
    <p>Upload an Excel 97-2003 (.xls) file — no composer required</p>
  </div>

  <div class="card-body">

    <!-- ── One-time setup banner ── -->
    <?php if ($libMissing): ?>
    <div class="setup-banner">
      <h3>⚙️ One-time setup — download 1 file</h3>
      <ol>
        <li>Open this URL in your browser:<br>
          <a href="https://raw.githubusercontent.com/shuchkin/simplexls/master/src/SimpleXLS.php" target="_blank">
            https://raw.githubusercontent.com/shuchkin/simplexls/master/src/SimpleXLS.php
          </a>
        </li>
        <li>Save it as <code>SimpleXLS.php</code> in your project root (same folder as this file)</li>
        <li>Refresh this page — setup banner will disappear ✅</li>
      </ol>
    </div>
    <?php else: ?>
    <div style="font-size:12px;color:#15803d;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 12px;margin-bottom:20px;">
      ✅ SimpleXLS ready
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <strong>❌ Please fix the following:</strong>
      <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <?php if ($result): ?>
    <div class="alert alert-success">✅ Import completed successfully!</div>
    <div class="result-box">
      <h3>📊 Import Summary</h3>
      <div class="result-meta">
        <span class="meta-pill">📚 <?= htmlspecialchars($result['course']) ?></span>
        <span class="meta-pill">📍 <?= htmlspecialchars($result['location']) ?></span>
      </div>
      <?php foreach ($success as $row): ?>
      <div class="result-row">
        <span>✅</span>
        <span><?= htmlspecialchars($row['cat']) ?></span>
        <span class="badge"><?= $row['count'] ?> FAQs</span>
      </div>
      <?php endforeach; ?>
      <div class="total-row">
        <span>Total Imported</span>
        <span><?= $result['total'] ?> FAQs</span>
      </div>
    </div>
    <?php endif; ?>

    <!-- Format hint -->
    <div class="hint">
      <strong>📋 Excel Format — Sheet 1</strong>
      <table class="format-table">
        <tr>
          <td class="cat-head" colspan="2">PROGRAM</td>
          <td class="cat-head" colspan="2">DELIVERY</td>
          <td class="cat-head" colspan="2">PLACEMENT</td>
          <td class="cat-head" colspan="2">CERTIFICATION</td>
          <td class="cat-head" colspan="2">FEES</td>
        </tr>
        <tr>
          <td class="sub-head">Question</td><td class="sub-head">Answer</td>
          <td class="sub-head">Question</td><td class="sub-head">Answer</td>
          <td class="sub-head">Question</td><td class="sub-head">Answer</td>
          <td class="sub-head">Question</td><td class="sub-head">Answer</td>
          <td class="sub-head">Question</td><td class="sub-head">Answer</td>
        </tr>
        <tr class="data-row">
          <td>Q1…</td><td>A1…</td><td>Q1…</td><td>A1…</td>
          <td>Q1…</td><td>A1…</td><td>Q1…</td><td>A1…</td><td>Q1…</td><td>A1…</td>
        </tr>
        <tr class="data-row">
          <td>Q2…</td><td>A2…</td><td>Q2…</td><td>A2…</td>
          <td>Q2…</td><td>A2…</td><td>Q2…</td><td>A2…</td><td>Q2…</td><td>A2…</td>
        </tr>
      </table>
      <div class="hint-note">Max 10 rows per category. Commas inside cells are fine.</div>
    </div>

    <!-- Form -->
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
        <label>Excel File (.xls)</label>
        <div class="file-zone" id="file-zone">
          <input type="file" name="xls_file" accept=".xls" id="xls-input" required>
          <div class="fz-icon">📊</div>
          <div class="fz-label">Click to upload or drag & drop</div>
          <div class="fz-sub">.xls (Excel 97-2003) only</div>
          <div class="fz-chosen" id="file-chosen"></div>
        </div>
      </div>

      <button type="submit" class="btn-submit" id="submit-btn" <?= $libMissing ? 'disabled title="Install SimpleXLS.php first"' : '' ?>>
        ⬆️ Import FAQs
      </button>
    </form>

  </div>
</div>

<script>
  const input  = document.getElementById('xls-input');
  const chosen = document.getElementById('file-chosen');
  const zone   = document.getElementById('file-zone');
  const btn    = document.getElementById('submit-btn');

  input.addEventListener('change', () => {
    if (input.files[0]) {
      chosen.textContent   = '📎 ' + input.files[0].name;
      chosen.style.display = 'block';
    }
  });

  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', ()  => zone.classList.remove('dragover'));
  zone.addEventListener('drop',      e  => { e.preventDefault(); zone.classList.remove('dragover'); });

  document.getElementById('import-form').addEventListener('submit', () => {
    btn.disabled    = true;
    btn.textContent = '⏳ Importing...';
  });
</script>
</body>
</html>