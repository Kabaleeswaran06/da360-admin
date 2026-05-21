<?php

// At the top of each replicate action, replace:
$courseId = (int)($_POST['course_id'] ?? 0);

// With this pattern:
$fromCourseId = (int)($_POST['course_id']     ?? 0);
$toCourseId   = (int)($_POST['to_course_id']  ?? 0);
if (!$toCourseId) $toCourseId = $fromCourseId; // backwards compatible

// ═══════════════════════════════════════════════════════════════════
// ADD THESE 4 BLOCKS TO api.php — paste before the "Unknown action" line
// ═══════════════════════════════════════════════════════════════════


// ── REPLICATE CONTENT ─────────────────────────────────────────────
// Copies all course_content fields from one location to another.
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
