<?php
// ── CORS ─────────────────────────────────────────────────────────────────────
$allowedOrigins = [
    'https://www.digitalacademy360.com',
    'https://digitalacademy360.com',
    'https://dev2.digitalacademy360.com',
    // 'http://localhost:3000',
    // 'http://localhost',
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

// ── Auth ──────────────────────────────────────────────────────────────────────
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

// ── Helper: fetch items with their list rows ──────────────────────────────────
function fetchItemsWithList(PDO $db, string $where, array $params): array {
    $stmt = $db->prepare("
        SELECT id, category, title, duration, mode, on_click, sort_order
        FROM   course_details_items
        $where
        ORDER  BY category, sort_order
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $s = $db->prepare("SELECT id, list_item, sort_order FROM course_details_list WHERE item_id = ? ORDER BY sort_order");
        $s->execute([(int)$row['id']]);
        $row['list'] = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($row);
    return $rows;
}

try {
    $db = getDB();

    // ══════════════════════════════════════════════════════════════════════════
    // GET MENU JSON — for Next.js frontend
    // GET /MenuDetails_api.php?action=get_menu_json&api_key=XXX
    // Returns: { leadership:[...], pgcp:[...], certification:[...], college:[...] }
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_menu_json') {
        $rows = fetchItemsWithList($db, 'WHERE course_id = 0', []);

        $out = ['leadership' => [], 'pgcp' => [], 'certification' => [], 'college' => []];
        foreach ($rows as $row) {
            $cat = $row['category'];
            if (!array_key_exists($cat, $out)) $out[$cat] = [];
            $out[$cat][] = [
                'title'    => $row['title'],
                'duration' => $row['duration'],
                'mode'     => $row['mode'],
                'onClick'  => $row['on_click'],
                'list'     => array_values(array_column($row['list'], 'list_item')),
            ];
        }
        echo json_encode(['success' => true, 'data' => $out]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET ALL ITEMS — for admin page refresh
    // GET /MenuDetails_api.php?action=get_all
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_all') {
        $rows = fetchItemsWithList($db, 'WHERE course_id = 0', []);
        echo json_encode(['success' => true, 'items' => $rows]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE MENU ITEM
    // POST /MenuDetails_api.php?action=save_menu_item
    // Body: item_id, category, title, duration, mode, on_click, sort_order, list (JSON)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_menu_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $itemId    = (int)($_POST['item_id']    ?? 0);
        $category  = trim($_POST['category']    ?? '');
        $title     = trim($_POST['title']       ?? '');
        $duration  = trim($_POST['duration']    ?? '');
        $mode      = trim($_POST['mode']        ?? '');
        $onClick   = trim($_POST['on_click']    ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 1);
        $listJson  = trim($_POST['list']        ?? '[]');
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'admin';

        $allowed = ['leadership', 'pgcp', 'certification', 'college'];
        if (!$title || !in_array($category, $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Missing or invalid fields']); exit;
        }

        $listItems = json_decode($listJson, true);
        if (!is_array($listItems)) $listItems = [];

        if ($itemId) {
            $stmt = $db->prepare("
                UPDATE course_details_items
                SET category=?, title=?, duration=?, mode=?, on_click=?, sort_order=?, updated_at=NOW(), updated_by=?
                WHERE id=? AND course_id=0
            ");
            $stmt->execute([$category, $title, $duration, $mode, $onClick, $sortOrder, $updatedBy, $itemId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO course_details_items
                    (course_id, category, title, duration, mode, on_click, sort_order, updated_at, updated_by)
                VALUES (0, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([$category, $title, $duration, $mode, $onClick, $sortOrder, $updatedBy]);
            $itemId = (int)$db->lastInsertId();
        }

        // Atomically replace list items
        $db->prepare("DELETE FROM course_details_list WHERE item_id = ?")->execute([$itemId]);
        $ins = $db->prepare("INSERT INTO course_details_list (item_id, list_item, sort_order) VALUES (?,?,?)");
        foreach ($listItems as $i => $li) {
            $text = trim($li['list_item'] ?? $li ?? '');
            if ($text === '') continue;
            $ins->execute([$itemId, $text, $i + 1]);
        }

        echo json_encode(['success' => true, 'item_id' => $itemId, 'message' => 'Saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE MENU ITEM
    // POST /MenuDetails_api.php?action=delete_menu_item
    // Body: item_id
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_menu_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if (!$itemId) { echo json_encode(['success' => false, 'message' => 'Missing item_id']); exit; }

        $db->prepare("DELETE FROM course_details_list WHERE item_id = ?")->execute([$itemId]);
        $stmt = $db->prepare("DELETE FROM course_details_items WHERE id = ? AND course_id = 0 LIMIT 1");
        $stmt->execute([$itemId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Deleted']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
