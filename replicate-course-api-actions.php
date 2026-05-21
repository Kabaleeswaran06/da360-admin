<?php
// ═══════════════════════════════════════════════════════════════════
// replicate-course-api-actions.php
// Course-to-course replication across all matching locations
// Actions: replicate_course_content, replicate_course_curriculum,
//          replicate_course_specialisation, replicate_course_faqs
// ═══════════════════════════════════════════════════════════════════


// ── REPLICATE COURSE CONTENT ──────────────────────────────────────
if ($action === 'replicate_course_content' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromCourseId = (int)($_POST['course_id']        ?? 0);
    $toCourseId   = (int)($_POST['to_course_id']     ?? 0);
    $fromId       = (int)($_POST['from_location_id'] ?? 0);
    $toId         = (int)($_POST['to_location_id']   ?? 0);

    if (!$fromCourseId || !$toCourseId || !$fromId || !$toId) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }

    // Fetch source row
    $stmt = $db->prepare("
        SELECT * FROM course_content
        WHERE course_id = ? AND location_id = ?
        LIMIT 1
    ");
    $stmt->execute([$fromCourseId, $fromId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        echo json_encode(['success' => false, 'message' => 'No content found in source']);
        exit;
    }

    // Build only whitelisted fields
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
        'course_id'   => $toCourseId,  // ← target course
        'location_id' => $toId,
        'updated_by'  => $updatedBy,
    ]));

    echo json_encode(['success' => true, 'message' => 'Content replicated successfully']);
    exit;
}


// ── REPLICATE COURSE CURRICULUM ───────────────────────────────────
if ($action === 'replicate_course_curriculum' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromCourseId = (int)($_POST['course_id']        ?? 0);
    $toCourseId   = (int)($_POST['to_course_id']     ?? 0);
    $fromId       = (int)($_POST['from_location_id'] ?? 0);
    $toId         = (int)($_POST['to_location_id']   ?? 0);

    if (!$fromCourseId || !$toCourseId || !$fromId || !$toId) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }

    // ── 1. Replicate heading + description ───────────────────────
    $stmt = $db->prepare("
        SELECT heading, description FROM course_curriculum
        WHERE course_id = ? AND location_id = ?
        LIMIT 1
    ");
    $stmt->execute([$fromCourseId, $fromId]);
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
            'cid'         => $toCourseId,
            'lid'         => $toId,
            'heading'     => $curr['heading'],
            'description' => $curr['description'],
        ]);
    }

    // ── 2. Delete existing batches in target ─────────────────────
    $stmt = $db->prepare("
        SELECT id FROM course_batches
        WHERE course_id = ? AND location_id = ?
    ");
    $stmt->execute([$toCourseId, $toId]);
    $existingBatchIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($existingBatchIds)) {
        $inList = implode(',', array_map('intval', $existingBatchIds));
        $db->exec("DELETE FROM course_batch_slots WHERE batch_id IN ($inList)");
        $db->exec("DELETE FROM course_batches WHERE id IN ($inList)");
    }

    // ── 3. Copy batches + slots from source ──────────────────────
    $stmt = $db->prepare("
        SELECT id, label, sort_order FROM course_batches
        WHERE course_id = ? AND location_id = ?
        ORDER BY sort_order
    ");
    $stmt->execute([$fromCourseId, $fromId]);
    $sourceBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sourceBatches as $batch) {
        $db->prepare("
            INSERT INTO course_batches (course_id, location_id, label, sort_order)
            VALUES (?, ?, ?, ?)
        ")->execute([$toCourseId, $toId, $batch['label'], $batch['sort_order']]);

        $newBatchId = (int)$db->lastInsertId();

        $stmt2 = $db->prepare("
            SELECT slot, sort_order FROM course_batch_slots
            WHERE batch_id = ?
            ORDER BY sort_order
        ");
        $stmt2->execute([$batch['id']]);

        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $slot) {
            $db->prepare("
                INSERT INTO course_batch_slots (batch_id, slot, sort_order)
                VALUES (?, ?, ?)
            ")->execute([$newBatchId, $slot['slot'], $slot['sort_order']]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Curriculum replicated successfully']);
    exit;
}


// ── REPLICATE COURSE SPECIALISATION ──────────────────────────────
if ($action === 'replicate_course_specialisation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromCourseId = (int)($_POST['course_id']        ?? 0);
    $toCourseId   = (int)($_POST['to_course_id']     ?? 0);
    $fromId       = (int)($_POST['from_location_id'] ?? 0);
    $toId         = (int)($_POST['to_location_id']   ?? 0);

    if (!$fromCourseId || !$toCourseId || !$fromId || !$toId) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT heading, description FROM course_specialisation
        WHERE course_id = ? AND location_id = ?
        LIMIT 1
    ");
    $stmt->execute([$fromCourseId, $fromId]);
    $spec = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$spec) {
        echo json_encode(['success' => false, 'message' => 'No specialisation found in source']);
        exit;
    }

    $db->prepare("
        INSERT INTO course_specialisation (course_id, location_id, heading, description)
        VALUES (:cid, :lid, :heading, :description)
        ON DUPLICATE KEY UPDATE
            heading     = VALUES(heading),
            description = VALUES(description)
    ")->execute([
        'cid'         => $toCourseId,
        'lid'         => $toId,
        'heading'     => $spec['heading'],
        'description' => $spec['description'],
    ]);

    echo json_encode(['success' => true, 'message' => 'Specialisation replicated successfully']);
    exit;
}


// ── REPLICATE COURSE FAQs ─────────────────────────────────────────
if ($action === 'replicate_course_faqs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromCourseId = (int)($_POST['course_id']        ?? 0);
    $toCourseId   = (int)($_POST['to_course_id']     ?? 0);
    $fromId       = (int)($_POST['from_location_id'] ?? 0);
    $toId         = (int)($_POST['to_location_id']   ?? 0);

    if (!$fromCourseId || !$toCourseId || !$fromId || !$toId) {
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
    $stmt->execute([$fromCourseId, $fromId]);
    $sourceFaqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sourceFaqs)) {
        echo json_encode(['success' => false, 'message' => 'No FAQs found in source']);
        exit;
    }

    // Delete existing FAQs in target
    $db->prepare("
        DELETE FROM course_faqs
        WHERE course_id = ? AND location_id = ?
    ")->execute([$toCourseId, $toId]);

    // Insert source FAQs into target
    $ins = $db->prepare("
        INSERT INTO course_faqs
            (course_id, location_id, category, sort_order, question, answer, is_active)
        VALUES
            (:cid, :lid, :category, :sort_order, :question, :answer, :is_active)
    ");

    foreach ($sourceFaqs as $faq) {
        $ins->execute([
            'cid'        => $toCourseId,
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

// ── REPLICATE COURSE AI TOOLS ─────────────────────────────────────
if ($action === 'replicate_course_aitools' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromCourseId = (int)($_POST['course_id']    ?? 0);
    $toCourseId   = (int)($_POST['to_course_id'] ?? 0);

    if (!$fromCourseId || !$toCourseId || $fromCourseId === $toCourseId) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }

    $updatedBy = $_SESSION['da360_user']['name']
              ?? $_SESSION['da360_user']['username']
              ?? 'unknown';

    // ── 1. Soft-delete existing categories + tools in target course ───
    $stmt = $db->prepare("
        SELECT id FROM ai_tool_categories
        WHERE course_id = ? AND is_active = 1
    ");
    $stmt->execute([$toCourseId]);
    $existingCatIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($existingCatIds)) {
        $inList = implode(',', array_map('intval', $existingCatIds));
        $db->exec("UPDATE ai_tools SET is_active = 0 WHERE category_id IN ($inList)");
        $db->exec("UPDATE ai_tool_categories SET is_active = 0 WHERE id IN ($inList)");
    }

    // ── 2. Fetch source categories ────────────────────────────────────
    $stmt = $db->prepare("
        SELECT id, label, sort_order FROM ai_tool_categories
        WHERE course_id = ? AND is_active = 1
        ORDER BY sort_order, id
    ");
    $stmt->execute([$fromCourseId]);
    $sourceCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sourceCategories)) {
        echo json_encode(['success' => false, 'message' => 'No AI tool categories found in source course']);
        exit;
    }

    $totalTools = 0;

    foreach ($sourceCategories as $cat) {
        // Insert new category for target course
        $stmt = $db->prepare("
            INSERT INTO ai_tool_categories
                (course_id, label, sort_order, is_active, updated_at, updated_by)
            VALUES (?, ?, ?, 1, NOW(), ?)
        ");
        $stmt->execute([$toCourseId, $cat['label'], $cat['sort_order'], $updatedBy]);
        $newCatId = (int)$db->lastInsertId();

        // Fetch tools from source category
        $stmt2 = $db->prepare("
            SELECT name, logo, sort_order FROM ai_tools
            WHERE category_id = ? AND is_active = 1
            ORDER BY sort_order, id
        ");
        $stmt2->execute([$cat['id']]);
        $tools = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tools as $tool) {
            $db->prepare("
                INSERT INTO ai_tools
                    (category_id, name, logo, sort_order, is_active, updated_at, updated_by)
                VALUES (?, ?, ?, ?, 1, NOW(), ?)
            ")->execute([$newCatId, $tool['name'], $tool['logo'], $tool['sort_order'], $updatedBy]);
            $totalTools++;
        }
    }

    $catCount = count($sourceCategories);
    echo json_encode([
        'success' => true,
        'message' => "$catCount categories and $totalTools tools replicated successfully"
    ]);
    exit;
}

// ── REPLICATE COURSE META TAGS ────────────────────────────────────
if ($action === 'replicate_course_metatags' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromCourseId = (int)($_POST['course_id']        ?? 0);
    $toCourseId   = (int)($_POST['to_course_id']     ?? 0);
    $fromId       = (int)($_POST['from_location_id'] ?? 0);
    $toId         = (int)($_POST['to_location_id']   ?? 0);

    if (!$fromCourseId || !$toCourseId || !$fromId || !$toId) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }

    // Fetch source meta tags
    $stmt = $db->prepare("
        SELECT title, description, keywords, robots, canonical_url,
               og_title, og_description, og_url, og_site_name, og_type,
               og_locale, og_image, twitter_card, twitter_title,
               twitter_description, twitter_image
        FROM course_metatags
        WHERE course_id = ? AND location_id = ?
        LIMIT 1
    ");
    $stmt->execute([$fromCourseId, $fromId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        echo json_encode(['success' => false, 'message' => 'No meta tags found in source']);
        exit;
    }

    $updatedBy = $_SESSION['da360_user']['name']
              ?? $_SESSION['da360_user']['username']
              ?? 'unknown';

    $stmt = $db->prepare("
        INSERT INTO course_metatags (
            course_id, location_id,
            title, description, keywords, robots, canonical_url,
            og_title, og_description, og_url, og_site_name, og_type,
            og_locale, og_image, twitter_card, twitter_title,
            twitter_description, twitter_image,
            updated_at, updated_by
        ) VALUES (
            :course_id, :location_id,
            :title, :description, :keywords, :robots, :canonical_url,
            :og_title, :og_description, :og_url, :og_site_name, :og_type,
            :og_locale, :og_image, :twitter_card, :twitter_title,
            :twitter_description, :twitter_image,
            NOW(), :updated_by
        )
        ON DUPLICATE KEY UPDATE
            title               = VALUES(title),
            description         = VALUES(description),
            keywords            = VALUES(keywords),
            robots              = VALUES(robots),
            canonical_url       = VALUES(canonical_url),
            og_title            = VALUES(og_title),
            og_description      = VALUES(og_description),
            og_url              = VALUES(og_url),
            og_site_name        = VALUES(og_site_name),
            og_type             = VALUES(og_type),
            og_locale           = VALUES(og_locale),
            og_image            = VALUES(og_image),
            twitter_card        = VALUES(twitter_card),
            twitter_title       = VALUES(twitter_title),
            twitter_description = VALUES(twitter_description),
            twitter_image       = VALUES(twitter_image),
            updated_at          = NOW(),
            updated_by          = VALUES(updated_by)
    ");

    $stmt->execute([
        ':course_id'           => $toCourseId,
        ':location_id'         => $toId,
        ':title'               => $source['title'],
        ':description'         => $source['description'],
        ':keywords'            => $source['keywords'],
        ':robots'              => $source['robots'],
        ':canonical_url'       => $source['canonical_url'],
        ':og_title'            => $source['og_title'],
        ':og_description'      => $source['og_description'],
        ':og_url'              => $source['og_url'],
        ':og_site_name'        => $source['og_site_name'],
        ':og_type'             => $source['og_type'],
        ':og_locale'           => $source['og_locale'],
        ':og_image'            => $source['og_image'],
        ':twitter_card'        => $source['twitter_card'],
        ':twitter_title'       => $source['twitter_title'],
        ':twitter_description' => $source['twitter_description'],
        ':twitter_image'       => $source['twitter_image'],
        ':updated_by'          => $updatedBy,
    ]);

    echo json_encode(['success' => true, 'message' => 'Meta tags replicated successfully']);
    exit;
}

// ── REPLICATE COURSE WISE ─────────────────────────────────────────
if ($action === 'replicate_course_coursewise' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fromCourseId = (int)($_POST['course_id']    ?? 0);
    $toCourseId   = (int)($_POST['to_course_id'] ?? 0);

    if (!$fromCourseId || !$toCourseId || $fromCourseId === $toCourseId) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }

    $updatedBy = $_SESSION['da360_user']['name']
              ?? $_SESSION['da360_user']['username']
              ?? 'unknown';

    // ── 1. Highlights ─────────────────────────────────────────────
    $db->prepare("DELETE FROM course_highlights WHERE course_id = ?")
       ->execute([$toCourseId]);

    $stmt = $db->prepare("SELECT icon, title, value, sort_order FROM course_highlights WHERE course_id = ? ORDER BY sort_order");
    $stmt->execute([$fromCourseId]);
    $ins = $db->prepare("INSERT INTO course_highlights (course_id, icon, title, value, sort_order) VALUES (?,?,?,?,?)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ins->execute([$toCourseId, $row['icon'], $row['title'], $row['value'], $row['sort_order']]);
    }

    // ── 2. Tools ──────────────────────────────────────────────────
    $db->prepare("DELETE FROM course_tools WHERE course_id = ?")
       ->execute([$toCourseId]);

    $stmt = $db->prepare("SELECT name, logo, sort_order FROM course_tools WHERE course_id = ? ORDER BY sort_order");
    $stmt->execute([$fromCourseId]);
    $ins = $db->prepare("INSERT INTO course_tools (course_id, name, logo, sort_order) VALUES (?,?,?,?)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ins->execute([$toCourseId, $row['name'], $row['logo'], $row['sort_order']]);
    }

    // ── 3. Case Studies ───────────────────────────────────────────

    // Delete existing case studies + tags in target
    $stmt = $db->prepare("SELECT id FROM course_casestudies WHERE course_id = ?");
    $stmt->execute([$toCourseId]);
    $existingCsIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($existingCsIds)) {
        $inList = implode(',', array_map('intval', $existingCsIds));
        $db->exec("DELETE FROM course_casestudy_tags WHERE casestudy_id IN ($inList)");
        $db->exec("DELETE FROM course_casestudies WHERE id IN ($inList)");
    }

    $stmt = $db->prepare("SELECT id, logo, title, description, sort_order FROM course_casestudies WHERE course_id = ? ORDER BY sort_order");
    $stmt->execute([$fromCourseId]);
    $sourceCaseStudies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $insCsStudy = $db->prepare("INSERT INTO course_casestudies (course_id, logo, title, description, sort_order) VALUES (?,?,?,?,?)");
    $insCsTag   = $db->prepare("INSERT INTO course_casestudy_tags (casestudy_id, tag, sort_order) VALUES (?,?,?)");

    foreach ($sourceCaseStudies as $cs) {
        $insCsStudy->execute([$toCourseId, $cs['logo'], $cs['title'], $cs['description'], $cs['sort_order']]);
        $newCsId = (int)$db->lastInsertId();

        $stmt2 = $db->prepare("SELECT tag, sort_order FROM course_casestudy_tags WHERE casestudy_id = ? ORDER BY sort_order");
        $stmt2->execute([$cs['id']]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $tag) {
            $insCsTag->execute([$newCsId, $tag['tag'], $tag['sort_order']]);
        }
    }

    // ── 4. Live Projects ──────────────────────────────────────────

    // Delete existing live projects + child tables in target
    $stmt = $db->prepare("SELECT id FROM course_liveprojects WHERE course_id = ?");
    $stmt->execute([$toCourseId]);
    $existingLpIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($existingLpIds)) {
        $inList = implode(',', array_map('intval', $existingLpIds));
        $db->exec("DELETE FROM course_liveproject_logos   WHERE project_id IN ($inList)");
        $db->exec("DELETE FROM course_liveproject_details WHERE project_id IN ($inList)");
        $db->exec("DELETE FROM course_liveproject_steps   WHERE project_id IN ($inList)");
        $db->exec("DELETE FROM course_liveprojects        WHERE id          IN ($inList)");
    }

    $stmt = $db->prepare("SELECT id, title, duration, heading, note, bg_gradient, bg_solid, sort_order FROM course_liveprojects WHERE course_id = ? ORDER BY sort_order");
    $stmt->execute([$fromCourseId]);
    $sourceLiveProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $insLp      = $db->prepare("INSERT INTO course_liveprojects (course_id, title, duration, heading, note, bg_gradient, bg_solid, sort_order) VALUES (?,?,?,?,?,?,?,?)");
    $insLpLogo  = $db->prepare("INSERT INTO course_liveproject_logos   (project_id, logo,   sort_order) VALUES (?,?,?)");
    $insLpDet   = $db->prepare("INSERT INTO course_liveproject_details (project_id, detail, sort_order) VALUES (?,?,?)");
    $insLpStep  = $db->prepare("INSERT INTO course_liveproject_steps   (project_id, step,   sort_order) VALUES (?,?,?)");

    foreach ($sourceLiveProjects as $lp) {
        $insLp->execute([$toCourseId, $lp['title'], $lp['duration'], $lp['heading'], $lp['note'], $lp['bg_gradient'], $lp['bg_solid'], $lp['sort_order']]);
        $newLpId = (int)$db->lastInsertId();

        $stmt2 = $db->prepare("SELECT logo, sort_order FROM course_liveproject_logos WHERE project_id = ? ORDER BY sort_order");
        $stmt2->execute([$lp['id']]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $insLpLogo->execute([$newLpId, $row['logo'], $row['sort_order']]);
        }

        $stmt2 = $db->prepare("SELECT detail, sort_order FROM course_liveproject_details WHERE project_id = ? ORDER BY sort_order");
        $stmt2->execute([$lp['id']]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $insLpDet->execute([$newLpId, $row['detail'], $row['sort_order']]);
        }

        $stmt2 = $db->prepare("SELECT step, sort_order FROM course_liveproject_steps WHERE project_id = ? ORDER BY sort_order");
        $stmt2->execute([$lp['id']]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $insLpStep->execute([$newLpId, $row['step'], $row['sort_order']]);
        }
    }

    // ── 5. Key Highlights ─────────────────────────────────────────
    $db->prepare("DELETE FROM course_key_highlights WHERE course_id = ?")
       ->execute([$toCourseId]);

    $stmt = $db->prepare("SELECT name, sort_order FROM course_key_highlights WHERE course_id = ? ORDER BY sort_order");
    $stmt->execute([$fromCourseId]);
    $ins = $db->prepare("INSERT INTO course_key_highlights (course_id, name, sort_order) VALUES (?,?,?)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ins->execute([$toCourseId, $row['name'], $row['sort_order']]);
    }

    // ── 6. Course Info ────────────────────────────────────────────
    $stmt = $db->prepare("SELECT course_id_slug, lead_tags FROM course_info WHERE course_id = ? LIMIT 1");
    $stmt->execute([$fromCourseId]);
    $ci = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ci) {
        $db->prepare("
            INSERT INTO course_info (course_id, course_id_slug, lead_tags)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                course_id_slug = VALUES(course_id_slug),
                lead_tags      = VALUES(lead_tags)
        ")->execute([$toCourseId, $ci['course_id_slug'], $ci['lead_tags']]);
    }

    // ── 7. Cohorts ────────────────────────────────────────────────
    $db->prepare("DELETE FROM course_cohorts WHERE course_id = ?")
       ->execute([$toCourseId]);

    $stmt = $db->prepare("SELECT date, mode, weekday, capacity, campus, sort_order FROM course_cohorts WHERE course_id = ? ORDER BY sort_order, id");
    $stmt->execute([$fromCourseId]);
    $ins = $db->prepare("INSERT INTO course_cohorts (course_id, date, mode, weekday, capacity, campus, sort_order) VALUES (?,?,?,?,?,?,?)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ins->execute([$toCourseId, $row['date'], $row['mode'], $row['weekday'], $row['capacity'], $row['campus'], $row['sort_order']]);
    }

    // ── 8. Banner ─────────────────────────────────────────────────
    $stmt = $db->prepare("SELECT desktop_banner, mobile_banner FROM course_banners WHERE course_id = ? LIMIT 1");
    $stmt->execute([$fromCourseId]);
    $banner = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($banner) {
        $db->prepare("
            INSERT INTO course_banners (course_id, desktop_banner, mobile_banner)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                desktop_banner = VALUES(desktop_banner),
                mobile_banner  = VALUES(mobile_banner)
        ")->execute([$toCourseId, $banner['desktop_banner'], $banner['mobile_banner']]);
    }

    // ── 9. Case Studies Heading ───────────────────────────────────
    $stmt = $db->prepare("SELECT heading, subheading FROM course_casestudies_heading WHERE course_id = ? LIMIT 1");
    $stmt->execute([$fromCourseId]);
    $csHead = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($csHead) {
        $db->prepare("
            INSERT INTO course_casestudies_heading (course_id, heading, subheading)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                heading    = VALUES(heading),
                subheading = VALUES(subheading)
        ")->execute([$toCourseId, $csHead['heading'], $csHead['subheading']]);
    }

    // ── 10. Live Projects Heading ─────────────────────────────────
    $stmt = $db->prepare("SELECT section, heading, description FROM course_liveprojects_heading WHERE course_id = ? LIMIT 1");
    $stmt->execute([$fromCourseId]);
    $lpHead = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lpHead) {
        $db->prepare("
            INSERT INTO course_liveprojects_heading (course_id, section, heading, description)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                section     = VALUES(section),
                heading     = VALUES(heading),
                description = VALUES(description)
        ")->execute([$toCourseId, $lpHead['section'], $lpHead['heading'], $lpHead['description']]);
    }

    echo json_encode(['success' => true, 'message' => 'Course wise data replicated successfully']);
    exit;
}