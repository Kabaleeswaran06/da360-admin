<?php
// ── CORS ───────────────────────────────────────────────────────────────────
$allowedOrigins = ['https://www.digitalacademy360.com',    'https://digitalacademy360.com',    'https://dev2.digitalacademy360.com',
// 'http://localhost:3000', 'http://localhost'
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
    // GET /schemas_api.php?action=get_locations
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_locations') {
        $stmt = $db->prepare("
            SELECT id, label, slug
            FROM locations
            WHERE is_active = 1
            ORDER BY sort_order, label
        ");
        $stmt->execute();
        echo json_encode(['success' => true, 'locations' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET SCHEMA HTML  — loads editor data for admin UI
    // GET /schemas_api.php?action=get_schema_html&course_id=1&location_id=2
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_schema_html') {
        $courseId   = (int)($_GET['course_id']   ?? 0);
        $locationId = (int)($_GET['location_id'] ?? 0);

        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id or location_id']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT schema_json, updated_at, updated_by
            FROM course_schemas
            WHERE course_id = ? AND location_id = ?
            LIMIT 1
        ");
        $stmt->execute([$courseId, $locationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Pretty-print for the editor
        $json = '[]';
        if ($row && !empty($row['schema_json'])) {
            $decoded = json_decode($row['schema_json']);
            if ($decoded !== null) {
                $json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        echo json_encode([
            'success'    => true,
            'schema_json'=> $json,
            'updated_at' => $row['updated_at']  ?? null,
            'updated_by' => $row['updated_by']  ?? null,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET SCHEMAS JSON  — for Next.js frontend (keyed by location slug)
    // GET /schemas_api.php?action=get_schemas_json&course_id=1&api_key=XXX
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_schemas_json') {
        $courseId = (int)($_GET['course_id'] ?? 0);
        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT l.slug, l.label, cs.schema_json
            FROM locations l
            LEFT JOIN course_schemas cs
                ON cs.location_id = l.id AND cs.course_id = ?
            WHERE l.is_active = 1
            ORDER BY l.sort_order, l.label
        ");
        $stmt->execute([$courseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $slug = !empty($row['slug'])
                ? $row['slug']
                : strtolower(preg_replace('/\s+/', '_', trim($row['label'])));

            $schemas = [];
            if (!empty($row['schema_json'])) {
                $decoded = json_decode($row['schema_json'], true);
                if (is_array($decoded)) $schemas = $decoded;
            }

            $result[$slug] = $schemas;
        }

        echo json_encode(
            ['success' => true, 'schemas' => $result],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE SCHEMA  (course + location specific)
    // POST /schemas_api.php?action=save_schema
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_schema' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);
        $rawJson    = trim($_POST['schema_json']  ?? '');

        if (!$courseId || !$locationId) {
            echo json_encode(['success' => false, 'message' => 'Invalid course_id or location_id']);
            exit;
        }

        // Validate: must be a JSON array
        $decoded = json_decode($rawJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }
        if (!is_array($decoded)) {
            echo json_encode(['success' => false, 'message' => 'Schema must be a JSON array [ ... ]']);
            exit;
        }

        // Re-encode cleanly
        $cleanJson = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $updatedBy = $_SESSION['da360_user']['name']
                  ?? $_SESSION['da360_user']['username']
                  ?? 'unknown';

        $stmt = $db->prepare("
            INSERT INTO course_schemas (course_id, location_id, schema_json, updated_at, updated_by)
            VALUES (:course_id, :location_id, :schema_json, NOW(), :updated_by)
            ON DUPLICATE KEY UPDATE
                schema_json = VALUES(schema_json),
                updated_at  = NOW(),
                updated_by  = VALUES(updated_by)
        ");
        $stmt->execute([
            ':course_id'   => $courseId,
            ':location_id' => $locationId,
            ':schema_json' => $cleanJson,
            ':updated_by'  => $updatedBy,
        ]);

        // ── Trigger Next.js revalidation ──────────────────────────────────
        $revalidateUrl = 'https://your-nextjs-site.com/api/revalidate'; // ← update
        $secret        = 'your_strong_secret_here';                     // ← update

        $ch = curl_init($revalidateUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['tag' => 'schemas']),
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

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
