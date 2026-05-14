<?php
// ── CORS ───────────────────────────────────────────────────────────────────
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

// ── Auth ───────────────────────────────────────────────────────────────────
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

$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    // ══════════════════════════════════════════════════════════════════════════
    // GET LOCATIONS  — used by the UI selector
    // GET /metatags_api.php?action=get_locations&course_id=1
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_locations') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT id, label, slug
            FROM locations
            WHERE is_active = 1
            ORDER BY sort_order, label
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'locations' => $rows]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET META TAGS HTML  — loads editor data for admin UI
    // GET /metatags_api.php?action=get_metatags_html&course_id=1&location_id=2
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_metatags_html') {
        $courseId   = (int)($_GET['course_id']   ?? 0);
        $locationId = (int)($_GET['location_id'] ?? 0);

        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id or location_id']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT
                title, description, keywords, robots, canonical_url,
                og_title, og_description, og_url, og_site_name,
                og_type, og_locale, og_image,
                twitter_card, twitter_title, twitter_description, twitter_image
            FROM course_metatags
            WHERE course_id = ? AND location_id = ?
            LIMIT 1
        ");
        $stmt->execute([$courseId, $locationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Return empty defaults if no row yet
        $data = [
            'title'               => $row['title']               ?? '',
            'description'         => $row['description']         ?? '',
            'keywords'            => $row['keywords']            ?? '[]',
            'robots'              => $row['robots']              ?? 'index, follow, max-snippet:-1, max-video-preview:-1, max-image-preview:large',
            'canonical'           => $row['canonical_url']       ?? '',
            'og_title'            => $row['og_title']            ?? '',
            'og_description'      => $row['og_description']      ?? '',
            'og_url'              => $row['og_url']              ?? '',
            'og_site_name'        => $row['og_site_name']        ?? 'Digital Academy 360',
            'og_type'             => $row['og_type']             ?? 'website',
            'og_locale'           => $row['og_locale']           ?? 'en_US',
            'og_image'            => $row['og_image']            ?? '/images/digital-academy-360-og.jpg',
            'twitter_card'        => $row['twitter_card']        ?? 'summary_large_image',
            'twitter_title'       => $row['twitter_title']       ?? '',
            'twitter_description' => $row['twitter_description'] ?? '',
            'twitter_image'       => $row['twitter_image']       ?? '/images/digital-academy-360-og.jpg',
        ];

        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET META TAGS JSON  — for Next.js frontend (keyed by location slug)
    // GET /metatags_api.php?action=get_metatags_json&course_id=1&api_key=XXX
    //
    // Returns the same shape as your static .ts file
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_metatags_json') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']);
            exit;
        }

        // All active locations
        $stmt = $db->prepare("
            SELECT id, slug, label
            FROM locations
            WHERE is_active = 1
            ORDER BY sort_order, label
        ");
        $stmt->execute();
        $locationRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($locationRows as $loc) {
            $lid  = (int)$loc['id'];
            $slug = !empty($loc['slug'])
                ? $loc['slug']
                : strtolower(preg_replace('/\s+/', '_', trim($loc['label'])));

            $stmt = $db->prepare("
                SELECT
                    title, description, keywords, robots, canonical_url,
                    og_title, og_description, og_url, og_site_name,
                    og_type, og_locale, og_image,
                    twitter_card, twitter_title, twitter_description, twitter_image
                FROM course_metatags
                WHERE course_id = ? AND location_id = ?
                LIMIT 1
            ");
            $stmt->execute([$courseId, $lid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Decode keywords JSON array
            $keywords = [];
            if (!empty($row['keywords'])) {
                $decoded = json_decode($row['keywords'], true);
                if (is_array($decoded)) $keywords = $decoded;
            }

            // Resolve OG / Twitter fallbacks (same logic as your .ts file)
            $title       = $row['title']       ?? '';
            $description = $row['description'] ?? '';

            $result[$slug] = [
                'title'       => $title,
                'description' => $description,
                'keywords'    => $keywords,
                'robots'      => $row['robots'] ?? 'index, follow, max-snippet:-1, max-video-preview:-1, max-image-preview:large',
                'alternates'  => [
                    'canonical' => $row['canonical_url'] ?? '',
                ],
                'openGraph'   => [
                    'title'       => !empty($row['og_title'])       ? $row['og_title']       : $title,
                    'description' => !empty($row['og_description'])  ? $row['og_description'] : $description,
                    'url'         => $row['og_url']      ?? '',
                    'siteName'    => $row['og_site_name'] ?? 'Digital Academy 360',
                    'locale'      => $row['og_locale']    ?? 'en_US',
                    'type'        => $row['og_type']      ?? 'website',
                    'images'      => [ !empty($row['og_image']) ? $row['og_image'] : '/images/digital-academy-360-og.jpg' ],
                ],
                'twitter'     => [
                    'card'        => $row['twitter_card']  ?? 'summary_large_image',
                    'title'       => !empty($row['twitter_title'])       ? $row['twitter_title']       : $title,
                    'description' => !empty($row['twitter_description']) ? $row['twitter_description'] : $description,
                    'images'      => [ !empty($row['twitter_image']) ? $row['twitter_image'] : '/images/digital-academy-360-og.jpg' ],
                ],
            ];
        }

        echo json_encode(
            ['success' => true, 'metatags' => $result],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE META TAGS  (course + location specific)
    // POST /metatags_api.php   action=save_metatags
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_metatags' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);

        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Invalid course_id or location_id']);
            exit;
        }

        $updatedBy = $_SESSION['da360_user']['name']
                  ?? $_SESSION['da360_user']['username']
                  ?? 'unknown';

        // Keywords come in as a JSON string from JS
        $keywordsRaw = $_POST['keywords'] ?? '[]';
        $keywordsArr = json_decode($keywordsRaw, true);
        if (!is_array($keywordsArr)) $keywordsArr = [];
        $keywordsJson = json_encode(array_values(array_filter(array_map('trim', $keywordsArr))));

        $stmt = $db->prepare("
            INSERT INTO course_metatags (
                course_id, location_id,
                title, description, keywords, robots, canonical_url,
                og_title, og_description, og_url, og_site_name, og_type, og_locale, og_image,
                twitter_card, twitter_title, twitter_description, twitter_image,
                updated_at, updated_by
            ) VALUES (
                :course_id, :location_id,
                :title, :description, :keywords, :robots, :canonical_url,
                :og_title, :og_description, :og_url, :og_site_name, :og_type, :og_locale, :og_image,
                :twitter_card, :twitter_title, :twitter_description, :twitter_image,
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
            ':course_id'          => $courseId,
            ':location_id'        => $locationId,
            ':title'              => trim($_POST['title']               ?? ''),
            ':description'        => trim($_POST['description']         ?? ''),
            ':keywords'           => $keywordsJson,
            ':robots'             => trim($_POST['robots']              ?? ''),
            ':canonical_url'      => trim($_POST['canonical']           ?? ''),
            ':og_title'           => trim($_POST['og_title']            ?? ''),
            ':og_description'     => trim($_POST['og_description']      ?? ''),
            ':og_url'             => trim($_POST['og_url']              ?? ''),
            ':og_site_name'       => trim($_POST['og_site_name']        ?? 'Digital Academy 360'),
            ':og_type'            => trim($_POST['og_type']             ?? 'website'),
            ':og_locale'          => trim($_POST['og_locale']           ?? 'en_US'),
            ':og_image'           => trim($_POST['og_image']            ?? ''),
            ':twitter_card'       => trim($_POST['twitter_card']        ?? 'summary_large_image'),
            ':twitter_title'      => trim($_POST['twitter_title']       ?? ''),
            ':twitter_description'=> trim($_POST['twitter_description'] ?? ''),
            ':twitter_image'      => trim($_POST['twitter_image']       ?? ''),
            ':updated_by'         => $updatedBy,
        ]);

        // ── Trigger Next.js revalidation ──────────────────────────────────
        $revalidated   = null;
        $revalidateUrl = 'https://your-nextjs-site.com/api/revalidate'; // ← update this
        $secret        = 'your_strong_secret_here';                     // ← match .env.local

        $ch = curl_init($revalidateUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['tag' => 'metatags']),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-revalidate-secret: ' . $secret,
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $curlError   = curl_error($ch);
        $revalidated = !$curlError;
        curl_close($ch);
        // ─────────────────────────────────────────────────────────────────

        echo json_encode([
            'success'     => true,
            'message'     => 'Meta tags saved successfully.',
            'revalidated' => $revalidated,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
