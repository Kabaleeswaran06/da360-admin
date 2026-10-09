<?php
/**
 * DA360 — CSV-only FAQ Bulk Importer
 * Only .csv is accepted. No SimpleXLS/.xls/.xlsx support.
 */

require_once __DIR__ . '/config/db.php';

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
    11 => 'PGCP in Social Media Training & Influencer Marketing',
    12 => 'Certification Course for Beginners in AI for DM',
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
    10 => 'Belgaum',
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
    30 => 'Coimbatore',
    31 => 'Thiruvananthapuram',
    32 => 'Kochi',
    33 => 'Kozhikode',
    34 => 'Thrissur',
    35 => 'Palakkad',
    36 => 'Kannur',
    37 => 'Kerala',
    38 => 'Durgapur',
    39 => 'Siliguri',
    40 => 'Burdwan',
    41 => 'Kharagpur',
    42 => 'Bhubaneswar',
    43 => 'Rourkela',
    44 => 'Sambalpur',
    45 => 'Berhampur',
    46 => 'Balasore',
    47 => 'Nashik',
    48 => 'Aurangabad',
    49 => 'Nagpur',
    50 => 'Kolhapur',
    51 => 'Solapur',
    52 => 'Amravati',
    53 => 'Nanded',
    54 => 'Jalgaon',
    55 => 'Jabalpur',
    56 => 'Gwalior',
    57 => 'Ujjain',
    58 => 'Sagar',
    59 => 'Rewa',
    60 => 'Ratlam',
];

$categoryAliases = [
    'program'       => 'Program',
    'delivery'      => 'Delivery',
    'placement'     => 'Placement',
    'certification' => 'Certification',
    'fees'          => 'Fee',
    'fee'           => 'Fee',
];

$result = null;
$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadedName = $_FILES['xls_file']['name'] ?? '';
    $ext = strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION));

    if (empty($_FILES['xls_file']['tmp_name'])) {
        $errors[] = 'Please upload a CSV file.';
    }
    if (!empty($uploadedName) && $ext !== 'csv') {
        $errors[] = 'Only .csv files are accepted.';
    }

    if (empty($errors)) {
        $rows = parseCsvFile($_FILES['xls_file']['tmp_name'], $errors);
    }

    if (empty($errors)) {
        $blocks = extractCsvBlocksFromCsvRows($rows, $courses, $locations, $errors);
    }

    if (empty($errors)) {
        $result = importCsvBlocks($blocks, $categoryAliases, $success, $errors);
    }
}

function parseCsvFile(string $filePath, array &$errors): array
{
    $rows = [];
    if (($handle = fopen($filePath, 'r')) === false) {
        $errors[] = 'Unable to open the uploaded CSV file.';
        return [];
    }
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    if (count($rows) === 0) {
        $errors[] = 'The uploaded CSV file is empty.';
    }
    return $rows;
}

function extractCsvBlocksFromCsvRows(array $rows, array $courses, array $locations, array &$errors): array
{
    $blocks = [];
    $currentCourseId = null;
    $currentLocationId = null;
    $currentBlock = [];

    foreach ($rows as $rowIndex => $row) {
        $courseIdValue = trim((string)($row[0] ?? ''));
        $locationValue = trim((string)($row[1] ?? ''));
        $isNumericCourseId = ctype_digit($courseIdValue) && $courseIdValue !== '';
        $hasLocationValue = $locationValue !== '';

        if ($isNumericCourseId && $hasLocationValue) {
            if ($currentCourseId !== null && $currentLocationId !== null && !empty($currentBlock)) {
                $blocks[] = [
                    'course_id' => $currentCourseId,
                    'location_id' => $currentLocationId,
                    'rows' => $currentBlock,
                ];
                $currentBlock = [];
            }

            $foundCourse = null;
            $foundLocation = null;
            $courseId = (int)$courseIdValue;

            if (isset($courses[$courseId])) {
                $foundCourse = $courseId;
            } else {
                $errors[] = "Unable to map course_id '{$courseId}' on CSV row " . ($rowIndex + 1) . ".";
            }

            if (ctype_digit($locationValue)) {
                $locationId = (int)$locationValue;
                if (isset($locations[$locationId])) {
                    $foundLocation = $locationId;
                } else {
                    $errors[] = "Unable to map location_id '{$locationId}' on CSV row " . ($rowIndex + 1) . ".";
                }
            } else {
                foreach ($locations as $lid => $name) {
                    if (strcasecmp($name, $locationValue) === 0) {
                        $foundLocation = $lid;
                        break;
                    }
                }
                if ($foundLocation === null) {
                    $errors[] = "Unable to map location name '{$locationValue}' on CSV row " . ($rowIndex + 1) . ".";
                }
            }

            if ($foundCourse !== null && $foundLocation !== null) {
                $currentCourseId = $foundCourse;
                $currentLocationId = $foundLocation;
            }
            continue;
        }

        if ($currentCourseId !== null && $currentLocationId !== null) {
            $currentBlock[] = $row;
        }
    }

    if ($currentCourseId !== null && $currentLocationId !== null && !empty($currentBlock)) {
        $blocks[] = [
            'course_id' => $currentCourseId,
            'location_id' => $currentLocationId,
            'rows' => $currentBlock,
        ];
    }

    if (empty($blocks) && empty($errors)) {
        $errors[] = 'No course/location blocks were detected in the CSV file.';
    }

    return $blocks;
}

function normalizeCategoryHeaderValue(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = trim((string)$value);
    $value = str_replace(["\r", "\n"], '', $value);
    $value = strtolower($value);
    return $value;
}

function importCsvBlocks(array $blocks, array $categoryAliases, array &$success, array &$errors): array
{
    $result = ['total' => 0, 'blocks' => []];

    foreach ($blocks as $blockIndex => $block) {
        $rows = $block['rows'];
        if (count($rows) < 3) {
            $errors[] = 'Each block must contain at least 3 rows: header, sub-header, and data.';
            continue;
        }

        $headerRowIndex = null;
        $catQuestionCol = [];

        foreach ($rows as $rowIdx => $row) {
            $header = array_map(fn($v) => normalizeCategoryHeaderValue((string)$v), $row);
            $detectedCols = [];

            foreach ($header as $colIdx => $cell) {
                if ($cell !== '' && isset($categoryAliases[$cell])) {
                    $cat = $categoryAliases[$cell];
                    if (!isset($detectedCols[$cat])) {
                        $detectedCols[$cat] = $colIdx;
                    }
                }
            }

            if (!empty($detectedCols)) {
                $headerRowIndex = $rowIdx;
                $catQuestionCol = $detectedCols;
                break;
            }
        }

        if (empty($catQuestionCol)) {
            $errors[] = 'No valid category headers were detected in block ' . ($blockIndex + 1) . '.';
            continue;
        }

        $grouped = [];
        for ($rowIdx = $headerRowIndex + 1; $rowIdx < count($rows); $rowIdx++) {
            $row = array_map(fn($v) => trim((string)$v), $rows[$rowIdx]);
            foreach ($catQuestionCol as $cat => $qCol) {
                $aCol = $qCol + 1;
                $question = $row[$qCol] ?? '';
                $answer = $row[$aCol] ?? '';
                if ($question === '' && $answer === '') {
                    continue;
                }
                $grouped[$cat][] = ['question' => $question, 'answer' => $answer];
            }
        }

        if (empty($grouped)) {
            $errors[] = 'Block ' . ($blockIndex + 1) . ' contained no FAQ rows.';
            continue;
        }

        try {
            $db = getDB();
            $sql = "
                INSERT INTO course_faqs
                    (course_id, location_id, category, sort_order, question, answer, is_active, created_at, updated_at)
                VALUES
                    (:course_id, :location_id, :category, :sort_order, :question, :answer, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    question = VALUES(question),
                    answer = VALUES(answer),
                    is_active = 1,
                    updated_at = NOW()
            ";
            $stmt = $db->prepare($sql);
            $total = 0;

            foreach ($grouped as $cat => $items) {
                foreach ($items as $sortOrder => $item) {
                    $stmt->execute([
                        'course_id' => $block['course_id'],
                        'location_id' => $block['location_id'],
                        'category' => $cat,
                        'sort_order' => $sortOrder + 1,
                        'question' => $item['question'],
                        'answer' => $item['answer'],
                    ]);
                    $total++;
                }
                $success[] = ['cat' => $cat, 'count' => count($items)];
            }

            $result['total'] += $total;
            $result['blocks'][] = [
                'course_id' => $block['course_id'],
                'location_id' => $block['location_id'],
                'count' => $total,
            ];
        } catch (Exception $e) {
            $errors[] = 'DB Error while importing block ' . ($blockIndex + 1) . ': ' . $e->getMessage();
        }
    }

    return $result;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FAQ Importer — CSV Only</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #f1f5f9; min-height: 100vh; display: flex; align-items: flex-start; justify-content: center; padding: 32px; }
  .card { width: 100%; max-width: 680px; background: #fff; border-radius: 18px; box-shadow: 0 24px 80px rgba(15,23,42,.08); overflow: hidden; }
  .card-header { padding: 30px 32px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; }
  .card-header h1 { font-size: 22px; margin-bottom: 8px; }
  .card-header p { font-size: 14px; color: rgba(255,255,255,.9); }
  .card-body { padding: 32px; }
  .field { margin-bottom: 22px; }
  label { display: block; margin-bottom: 8px; font-size: 12px; font-weight: 700; color: #475569; }
  .file-zone { border: 2px dashed #cbd5e1; border-radius: 14px; padding: 38px 24px; text-align: center; background: #f8fafc; position: relative; cursor: pointer; transition: all .15s ease; }
  .file-zone:hover, .file-zone.dragover { background: #eef2ff; border-color: #6366f1; }
  .file-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
  .fz-icon { font-size: 36px; margin-bottom: 10px; }
  .fz-label { font-size: 15px; font-weight: 700; color: #334155; }
  .fz-sub { font-size: 12px; color: #64748b; margin-top: 6px; }
  .fz-chosen { display: none; margin-top: 14px; font-size: 13px; color: #4338ca; }
  .hint { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 20px; margin-bottom: 24px; color: #334155; }
  .hint strong { display: block; margin-bottom: 10px; color: #0f172a; }
  .format-table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 12px; }
  .format-table td { padding: 6px 8px; border: 1px solid #e2e8f0; text-align: center; }
  .cat-head { background: #6366f1; color: #fff; font-weight: 700; }
  .sub-head { background: #e0e7ff; color: #4338ca; font-style: italic; }
  .hint-note { margin-top: 12px; font-size: 13px; line-height: 1.6; }
  .hint-pre { margin-top: 12px; padding: 14px; background: #eef2ff; border-radius: 10px; overflow-x: auto; font-size: 12px; }
  .btn-submit { width: 100%; padding: 14px 18px; border: none; border-radius: 12px; background: #6366f1; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
  .btn-submit:hover { opacity: .92; }
  .alert { border-radius: 12px; padding: 16px 18px; margin-bottom: 22px; font-size: 14px; }
  .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
  .alert-success { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; }
  .alert ul { margin-top: 10px; margin-left: 18px; }
  .alert li { margin-bottom: 6px; }
  .result-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-top: 24px; }
  .result-box h3 { margin-bottom: 14px; font-size: 16px; color: #0f172a; }
  .result-row { display: flex; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid #e2e8f0; color: #334155; }
  .result-row:last-child { border-bottom: none; }
  .badge { margin-left: auto; background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <h1>📥 CSV FAQ Bulk Importer</h1>
    <p>Only CSV is accepted. Course and location are read from the first row of each block.</p>
  </div>
  <div class="card-body">
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
      <?php foreach ($result['blocks'] as $block): ?>
      <div class="result-row">
        <span>✅</span>
        <span>Course <?= $block['course_id'] ?> / Location <?= $block['location_id'] ?></span>
        <span class="badge"><?= $block['count'] ?> FAQs</span>
      </div>
      <?php endforeach; ?>
      <div class="result-row">
        <strong>Total Imported</strong>
        <span class="badge"><?= $result['total'] ?> FAQs</span>
      </div>
    </div>
    <?php endif; ?>

    <div class="hint">
      <strong>📋 CSV format</strong>
      <div class="hint-note">Each FAQ block must begin with a metadata row containing <strong>course_id</strong> and <strong>location_id</strong> or <strong>location_name</strong>. The rows below that block belong to that course-location pair.</div>
      <div class="hint-note">Block structure:</div>
      <pre class="hint-pre">course_id,location_id_or_name
PROGRAM,DELIVERY,PLACEMENT,CERTIFICATION,FEES
Question,Answer,Question,Answer,Question,Answer,Question,Answer,Question,Answer
...FAQ rows...</pre>
      <div class="hint-note">Example:</div>
      <pre class="hint-pre">1,Pune
PROGRAM,DELIVERY,PLACEMENT,CERTIFICATION,FEES
Question,Answer,Question,Answer,Question,Answer,Question,Answer,Question,Answer
What is the program?,This program covers marketing fundamentals.,How is delivery handled?,Online + classroom,...</pre>
      <div class="hint-note">Repeat the metadata row for each course-location block in the same CSV file.</div>
    </div>

    <form method="POST" enctype="multipart/form-data">
      <div class="field">
        <label>CSV File</label>
        <div class="file-zone" id="file-zone">
          <input type="file" name="xls_file" accept=".csv" required>
          <div class="fz-icon">📄</div>
          <div class="fz-label">Click to upload or drag & drop</div>
          <div class="fz-sub">Only .csv supported</div>
        </div>
      </div>
      <button type="submit" class="btn-submit">⬆️ Import FAQs</button>
    </form>
  </div>
</div>
</body>
</html>
