<?php
// ── CORS ──────────────────────────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost',
    // 'https://yourproductiondomain.com',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');

// ── Optional API key guard (same pattern as api.php) ─────────────────────────
define('VALID_API_KEYS', ['da360-secret-key-2024']);
$header   = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$queryKey = $_GET['api_key'] ?? '';
$bearerOk = preg_match('/^Bearer\s+(.+)$/i', $header, $m) && in_array($m[1], VALID_API_KEYS, true);
$queryOk  = $queryKey !== '' && in_array($queryKey, VALID_API_KEYS, true);
// Remove the next line if you want this endpoint fully public:
// if (!$bearerOk && !$queryOk) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

try {
    $db = getDB();

    $stmt = $db->query("
        SELECT
            slug,
            label,
            city,
            phone,
            imgsrc,
            direction_link,
            address_lines
        FROM locations
        WHERE is_active = 1
        ORDER BY sort_order, label
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $slug = $row['slug'];

        // Decode address_lines JSON array; fall back to empty array
        $addressLines = [];
        if (!empty($row['address_lines'])) {
            $decoded = json_decode($row['address_lines'], true);
            if (is_array($decoded)) {
                $addressLines = $decoded;
            }
        }

        $result[$slug] = [
            'location' => [
                'city'          => $row['city']          ?? '',
                'phone'         => $row['phone']         ?? '',
                'imgsrc'        => $row['imgsrc']        ?? '',
                'directionlink' => $row['direction_link'] ?? '',
                'addressLines'  => $addressLines,
            ],
        ];
    }

    echo json_encode([
        'success'   => true,
        'locations' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}