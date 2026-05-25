<?php
/*
 * aitools_api.php — AI Tools CMS API  (course-scoped)
 *
 * ── Required DB tables ───────────────────────────────────────────────────────
 *
 * CREATE TABLE ai_tool_categories (
 *   id         INT AUTO_INCREMENT PRIMARY KEY,
 *   course_id  INT          NOT NULL,
 *   label      VARCHAR(100) NOT NULL,
 *   sort_order INT          NOT NULL DEFAULT 0,
 *   is_active  TINYINT      NOT NULL DEFAULT 1,
 *   updated_at DATETIME     DEFAULT NULL,
 *   updated_by VARCHAR(100) DEFAULT NULL,
 *   INDEX idx_atc_course (course_id)
 * );
 *
 * CREATE TABLE ai_tools (
 *   id          INT AUTO_INCREMENT PRIMARY KEY,
 *   category_id INT          NOT NULL,
 *   name        VARCHAR(100) NOT NULL,
 *   logo        VARCHAR(255) NOT NULL DEFAULT '',
 *   sort_order  INT          NOT NULL DEFAULT 0,
 *   is_active   TINYINT      NOT NULL DEFAULT 1,
 *   updated_at  DATETIME     DEFAULT NULL,
 *   updated_by  VARCHAR(100) DEFAULT NULL,
 *   CONSTRAINT fk_at_category
 *     FOREIGN KEY (category_id) REFERENCES ai_tool_categories(id)
 *     ON DELETE CASCADE
 * );
 *
 * ── GET actions ──────────────────────────────────────────────────────────────
 *   get_aitools_json   ?course_id=N&api_key=X   → Next.js JSON
 *   get_aitools_html   ?course_id=N             → CMS editor
 *
 * ── POST actions ─────────────────────────────────────────────────────────────
 *   save_category      course_id, category_id, label, sort_order
 *   delete_category    course_id, category_id
 *   save_tool          category_id, tool_id, name, logo | logo_file, sort_order
 *   delete_tool        category_id, tool_id
 */

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
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

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

// ── Image upload helper ───────────────────────────────────────────────────────
function handleImageUpload(string $fileKey, string $folder): string {
    if (empty($_FILES[$fileKey]['tmp_name'])) return '';
    $uploadDir = __DIR__ . '/uploads/' . $folder . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext     = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
    if (!in_array($ext, $allowed, true)) return '';
    $filename = uniqid($folder . '_', true) . '.' . $ext;
    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $uploadDir . $filename)) {
        return '/uploads/' . $folder . '/' . $filename;
    }
    return '';
}

$action = $_GET['action'] ?? '';

try {
    $db = getDB();

    // ══════════════════════════════════════════════════════════════════════════
    // GET AI TOOLS JSON — for Next.js frontend
    // GET /aitools_api.php?action=get_aitools_json&course_id=1&api_key=XXX
    //
    // Returns:
    // {
    //   success: true,
    //   data: {
    //     All:     [{ name, logo }, …],
    //     Design:  [{ name, logo }, …],
    //     Website: [{ name, logo }, …],
    //     …
    //   }
    // }
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_aitools_json') {
        $base_url = 'https://confirmation.digitalacademy360.com/da360-admin';
        // $base_url = 'https://localhost/da360-admin';
        $courseId = (int)($_GET['course_id'] ?? 0);

        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']);
            exit;
        }

        $stmt = $db->prepare(
            "SELECT id, label
               FROM ai_tool_categories
              WHERE course_id = ? AND is_active = 1
              ORDER BY sort_order, id"
        );
        $stmt->execute([$courseId]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = ['All' => []];

        foreach ($categories as $cat) {
            $catId = (int)$cat['id'];
            $label = $cat['label'];

            $stmt2 = $db->prepare(
                "SELECT name, logo
                   FROM ai_tools
                  WHERE category_id = ? AND is_active = 1
                  ORDER BY sort_order, id"
            );
            $stmt2->execute([$catId]);
            $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $toolList = [];
            foreach ($rows as $t) {
                $entry = [
                    'name' => $t['name'],
                    'logo' => $base_url . $t['logo'],
                ];
                $toolList[]      = $entry;
                $result['All'][] = $entry;
            }

            $result[$label] = $toolList;
        }

        echo json_encode(['success' => true, 'data' => $result]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET AI TOOLS HTML — CMS admin editor
    // GET /aitools_api.php?action=get_aitools_html&course_id=1
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_aitools_html') {
        $courseId = (int)($_GET['course_id'] ?? 0);

        if (!$courseId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id']);
            exit;
        }

        // ── Course label ──────────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT label FROM courses WHERE id = ? LIMIT 1");
        $stmt->execute([$courseId]);
        $courseLabel = $stmt->fetchColumn() ?: '';

        // ── Fetch categories + tools ──────────────────────────────────────────
        $stmt = $db->prepare(
            "SELECT id, label, sort_order
               FROM ai_tool_categories
              WHERE course_id = ? AND is_active = 1
              ORDER BY sort_order, id"
        );
        $stmt->execute([$courseId]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $catData    = [];
        $totalTools = 0;
        foreach ($categories as $cat) {
            $catId = (int)$cat['id'];
            $stmt2 = $db->prepare(
                "SELECT id, name, logo, sort_order
                   FROM ai_tools
                  WHERE category_id = ? AND is_active = 1
                  ORDER BY sort_order, id"
            );
            $stmt2->execute([$catId]);
            $tools       = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $totalTools += count($tools);
            $catData[]   = [
                'id'         => $catId,
                'label'      => $cat['label'],
                'sort_order' => (int)$cat['sort_order'],
                'tools'      => $tools,
            ];
        }

        // ── Build HTML ────────────────────────────────────────────────────────
        $adminBase = '/da360-admin';
        ob_start(); ?>
<style>
.at *, .at *::before, .at *::after { box-sizing: border-box; }
.at { font-family: system-ui, sans-serif; color: #1e293b; }

/* ── Top bar ── */
.at .at-topbar {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.at .at-topbar h2 { font-size: 20px; font-weight: 700; color: #1e293b; margin: 0 0 4px; }
.at .at-topbar h2 span { color: #6366f1; }
.at .at-topbar .at-stats { font-size: 13px; color: #64748b; }
.at .at-topbar .at-stats em { color: #6366f1; font-style: normal; font-weight: 600; }

/* ── Buttons ── */
.at .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; border: none; border-radius: 6px;
    font-size: 13px; font-weight: 600; cursor: pointer; transition: opacity .15s;
}
.at .btn:hover { opacity: .85; }
.at .btn-primary   { background: #6366f1; color: #fff; }
.at .btn-secondary { background: #f1f5f9; color: #475569; border: 1.5px solid #e2e8f0; }
.at .btn-danger    { background: #ef4444; color: #fff; }
.at .btn-sm        { padding: 5px 10px; font-size: 12px; }
.at .btn-add-tool  {
    background: #e0f2fe; color: #0284c7;
    border: 1.5px dashed #7dd3fc;
    width: 100%; justify-content: center;
    margin-top: 14px; padding: 10px; border-radius: 8px;
}
.at .btn-icon {
    background: none; border: 1px solid #e2e8f0; border-radius: 5px;
    padding: 4px 7px; cursor: pointer; font-size: 13px; transition: background .15s;
}
.at .btn-icon:hover { background: #f1f5f9; }

/* ── Category blocks ── */
.at .cat-block {
    border: 1.5px solid #e2e8f0; border-radius: 12px;
    margin-bottom: 20px; background: #fff; overflow: hidden;
}
.at .cat-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; background: #f8fafc;
    border-bottom: 1.5px solid #e2e8f0; flex-wrap: wrap; gap: 10px;
}
.at .cat-title-wrap { display: flex; align-items: center; gap: 8px; }
.at .cat-label { font-size: 16px; font-weight: 700; color: #1e293b; }
.at .cat-badge {
    font-size: 11px; font-weight: 600; padding: 2px 9px;
    border-radius: 20px; background: #e0e7ff; color: #4338ca;
}
.at .cat-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.at .cat-body { padding: 18px; }

/* ── Tools grid ── */
.at .tools-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
    gap: 12px;
}
.at .tool-card {
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 12px 8px 10px;
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    background: #fafafa; text-align: center;
    transition: box-shadow .15s, border-color .15s;
}
.at .tool-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); border-color: #c7d2fe; }
.at .tool-logo {
    width: 60px; height: 60px; object-fit: contain;
    border-radius: 8px; background: #fff; border: 1px solid #e2e8f0; padding: 4px;
}
.at .tool-logo-ph {
    width: 60px; height: 60px; border-radius: 8px; background: #e0e7ff;
    display: flex; align-items: center; justify-content: center; font-size: 24px;
}
.at .tool-name {
    font-size: 11px; font-weight: 600; color: #475569;
    word-break: break-word; max-width: 100%; line-height: 1.3;
}
.at .tool-actions { display: flex; gap: 4px; }
.at .tools-empty {
    grid-column: 1 / -1; text-align: center;
    color: #94a3b8; font-size: 13px; padding: 20px 0;
}

/* ── Empty state ── */
.at .at-empty {
    text-align: center; padding: 56px 20px; color: #94a3b8;
    border: 2px dashed #e2e8f0; border-radius: 12px;
}
.at .at-empty .big-icon { font-size: 48px; display: block; margin-bottom: 12px; }
.at .at-empty h3 { font-size: 18px; color: #64748b; margin: 0 0 6px; }
.at .at-empty p  { margin: 0; font-size: 14px; }

/* ── Modals ── */
.at-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.45);
    z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;
}
.at-modal {
    background: #fff; border-radius: 14px; width: 100%; max-width: 440px;
    box-shadow: 0 20px 60px rgba(0,0,0,.22);
    display: flex; flex-direction: column; max-height: 90vh; overflow: hidden;
}
.at .modal-hd {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 20px 14px; border-bottom: 1px solid #e2e8f0;
}
.at .modal-hd h3 { font-size: 16px; font-weight: 700; color: #1e293b; margin: 0; }
.at .modal-close {
    background: none; border: none; font-size: 18px; cursor: pointer;
    color: #94a3b8; padding: 2px 6px; border-radius: 4px;
}
.at .modal-close:hover { background: #f1f5f9; color: #475569; }
.at .modal-bd { padding: 18px 20px; overflow-y: auto; flex: 1; }
.at .modal-ft {
    display: flex; justify-content: flex-end; gap: 8px;
    padding: 14px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc;
}

/* ── Form fields ── */
.at .field-row { margin-bottom: 14px; }
.at .field-row:last-child { margin-bottom: 0; }
.at label {
    display: block; font-size: 12px; font-weight: 600; color: #64748b;
    margin-bottom: 4px; text-transform: uppercase; letter-spacing: .4px;
}
.at input[type=text],
.at input[type=number] {
    width: 100%; padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px;
    font-size: 14px; color: #1e293b; background: #fff; transition: border-color .15s;
}
.at input[type=text]:focus,
.at input[type=number]:focus { border-color: #6366f1; outline: none; }
.at input[type=file] { font-size: 13px; color: #475569; }
.at .field-hint { font-size: 11px; color: #94a3b8; margin-top: 3px; }
.at .logo-prev-wrap { margin-top: 8px; }
.at .logo-prev-wrap img {
    max-width: 80px; max-height: 80px; border-radius: 8px;
    border: 1.5px solid #e2e8f0; padding: 4px; background: #f8fafc; object-fit: contain;
}
.at .btn.saving { opacity: .5; pointer-events: none; }
</style>

<div class="at">

  <!-- ── Top bar ── -->
  <div class="at-topbar">
    <div>
      <h2>AI Tools — <span><?= htmlspecialchars($courseLabel) ?></span></h2>
      <div class="at-stats">
        <?= count($catData) ?> <?= count($catData) === 1 ? 'category' : 'categories' ?>
        &middot; <?= $totalTools ?> tools total
        &middot; <em>All</em> tab is auto-generated from all categories
      </div>
    </div>
    <button class="btn btn-primary" onclick="atOpenCatModal(0)">+ Add Category</button>
  </div>

  <!-- ── Categories ── -->
  <div id="at-cats">
    <?php if (empty($catData)): ?>
    <div class="at-empty">
      <span class="big-icon">📂</span>
      <h3>No categories yet</h3>
      <p>Click "+ Add Category" to create your first AI tool category for this course.</p>
    </div>
    <?php else: ?>
    <?php foreach ($catData as $cat): ?>
    <div class="cat-block" id="at-cat-<?= $cat['id'] ?>">
      <div class="cat-header">
        <div class="cat-title-wrap">
          <span class="cat-label"><?= htmlspecialchars($cat['label']) ?></span>
          <span class="cat-badge"><?= count($cat['tools']) ?> tools</span>
        </div>
        <div class="cat-actions">
          <button class="btn btn-sm btn-secondary"
            onclick='atOpenCatModal(
              <?= $cat['id'] ?>,
              <?= htmlspecialchars(json_encode($cat['label']), ENT_QUOTES) ?>,
              <?= $cat['sort_order'] ?>
            )'>✏️ Edit</button>
          <button class="btn btn-sm btn-danger"
            onclick='atDeleteCat(
              <?= $cat['id'] ?>,
              <?= htmlspecialchars(json_encode($cat['label']), ENT_QUOTES) ?>
            )'>🗑️ Delete</button>
          <button class="btn btn-sm btn-primary"
            onclick="atOpenToolModal(0, <?= $cat['id'] ?>)">+ Add Tool</button>
        </div>
      </div>
      <div class="cat-body">
        <div class="tools-grid" id="at-tools-<?= $cat['id'] ?>">
          <?php if (empty($cat['tools'])): ?>
          <div class="tools-empty">No tools yet — click "+ Add Tool" to add one.</div>
          <?php else: ?>
          <?php foreach ($cat['tools'] as $tool): ?>
          <div class="tool-card" id="at-tool-<?= $tool['id'] ?>">
            <?php if (!empty($tool['logo'])): ?>
            <img class="tool-logo"
                 src="<?= $adminBase . htmlspecialchars($tool['logo']) ?>"
                 alt="<?= htmlspecialchars($tool['name']) ?>">
            <?php else: ?>
            <div class="tool-logo-ph">🔧</div>
            <?php endif; ?>
            <span class="tool-name"><?= htmlspecialchars($tool['name']) ?></span>
            <div class="tool-actions">
              <button class="btn-icon" title="Edit"
                onclick='atOpenToolModal(
                  <?= $tool['id'] ?>,
                  <?= $cat['id'] ?>,
                  <?= htmlspecialchars(json_encode($tool['name']), ENT_QUOTES) ?>,
                  <?= htmlspecialchars(json_encode($tool['logo']), ENT_QUOTES) ?>,
                  <?= (int)$tool['sort_order'] ?>
                )'>✏️</button>
              <button class="btn-icon" title="Delete"
                onclick='atDeleteTool(
                  <?= $tool['id'] ?>,
                  <?= $cat['id'] ?>,
                  <?= htmlspecialchars(json_encode($tool['name']), ENT_QUOTES) ?>
                )'>🗑️</button>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <button class="btn btn-add-tool"
          onclick="atOpenToolModal(0, <?= $cat['id'] ?>)">+ Add Tool</button>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div><!-- /.at -->

<!-- ════════════════════ Category modal ════════════════════ -->
<div id="at-cat-overlay" style="display:none">
  <div class="at-overlay" onclick="if(event.target===this)atCloseCatModal()">
    <div class="at at-modal">
      <div class="modal-hd">
        <h3 id="at-cat-modal-title">Add Category</h3>
        <button class="modal-close" onclick="atCloseCatModal()">✕</button>
      </div>
      <div class="modal-bd">
        <input type="hidden" id="at-cat-id" value="0">
        <div class="field-row">
          <label for="at-cat-label">Category Label</label>
          <input type="text" id="at-cat-label" placeholder="e.g. Design">
        </div>
        <div class="field-row">
          <label for="at-cat-sort">Sort Order</label>
          <input type="number" id="at-cat-sort" value="0" min="0">
          <div class="field-hint">Lower numbers appear first in the website tabs</div>
        </div>
      </div>
      <div class="modal-ft">
        <button class="btn btn-secondary" onclick="atCloseCatModal()">Cancel</button>
        <button class="btn btn-primary" id="at-cat-save-btn" onclick="atSaveCat()">Save Category</button>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════ Tool modal ════════════════════ -->
<div id="at-tool-overlay" style="display:none">
  <div class="at-overlay" onclick="if(event.target===this)atCloseToolModal()">
    <div class="at at-modal">
      <div class="modal-hd">
        <h3 id="at-tool-modal-title">Add Tool</h3>
        <button class="modal-close" onclick="atCloseToolModal()">✕</button>
      </div>
      <div class="modal-bd">
        <input type="hidden" id="at-tool-id"     value="0">
        <input type="hidden" id="at-tool-cat-id" value="0">
        <div class="field-row">
          <label for="at-tool-name">Tool Name</label>
          <input type="text" id="at-tool-name" placeholder="e.g. Canva">
        </div>
        <div class="field-row">
          <label for="at-tool-logo-path">Logo Path</label>
          <input type="text" id="at-tool-logo-path"
                 placeholder="/images/aitools/design/design-1.png">
          <div class="field-hint">Paste an existing path, or upload a new file below</div>
        </div>
        <div id="at-tool-logo-preview" class="logo-prev-wrap" style="display:none">
          <img id="at-tool-logo-img" src="" alt="Preview">
        </div>
        <div class="field-row" style="margin-top:12px">
          <label for="at-tool-logo-file">Upload New Logo</label>
          <input type="file" id="at-tool-logo-file" accept="image/*">
        </div>
        <div class="field-row">
          <label for="at-tool-sort">Sort Order</label>
          <input type="number" id="at-tool-sort" value="0" min="0">
        </div>
      </div>
      <div class="modal-ft">
        <button class="btn btn-secondary" onclick="atCloseToolModal()">Cancel</button>
        <button class="btn btn-primary" id="at-tool-save-btn" onclick="atSaveTool()">Save Tool</button>
      </div>
    </div>
  </div>
</div>
<?php
        $html = ob_get_clean();

        // ── JS — course_id baked in so reload stays scoped ──────────────────
        $js = 'window._atCourseId = ' . $courseId . ';'
            . 'window._atAdminBase = ' . json_encode($adminBase) . ';'
            . <<<'ENDJS'

(function () {
  var API       = '/da360-admin/aitools_api.php';
  var courseId  = window._atCourseId;
  var adminBase = window._atAdminBase;

  // ── Reload entire editor (preserves course scope) ───────────────────────
  function atReload() {
    var ra = document.getElementById('result-area');
    if (ra) {
      ra.innerHTML =
        '<div class="state-placeholder">' +
          '<span class="big-icon spin">⏳</span>' +
          '<h3>Refreshing…</h3>' +
        '</div>';
    }
    fetch(API + '?action=get_aitools_html&course_id=' + courseId)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success && ra) {
          ra.innerHTML = d.html;
          var s = document.createElement('script');
          s.textContent = d.js;
          document.body.appendChild(s);
        }
      });
  }
  window.atReload = atReload;

  // ════════════════════════════════════════════════════════════════════════
  // Category modal
  // ════════════════════════════════════════════════════════════════════════
  window.atOpenCatModal = function (id, label, sort) {
    document.getElementById('at-cat-id').value    = id    || 0;
    document.getElementById('at-cat-label').value = label || '';
    document.getElementById('at-cat-sort').value  = (sort !== undefined ? sort : 0);
    document.getElementById('at-cat-modal-title').textContent =
      id ? 'Edit Category' : 'Add Category';
    document.getElementById('at-cat-save-btn').classList.remove('saving');
    document.getElementById('at-cat-overlay').style.display = 'block';
    setTimeout(function () { document.getElementById('at-cat-label').focus(); }, 80);
  };

  window.atCloseCatModal = function () {
    document.getElementById('at-cat-overlay').style.display = 'none';
  };

  window.atSaveCat = function () {
    var id    = document.getElementById('at-cat-id').value;
    var label = document.getElementById('at-cat-label').value.trim();
    var sort  = document.getElementById('at-cat-sort').value;
    if (!label) { alert('Please enter a category label.'); return; }

    var btn = document.getElementById('at-cat-save-btn');
    btn.classList.add('saving');

    var fd = new FormData();
    fd.append('course_id',   courseId);
    fd.append('category_id', id);
    fd.append('label',       label);
    fd.append('sort_order',  sort);

    fetch(API + '?action=save_category', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) { atCloseCatModal(); atReload(); }
        else { alert(d.message || 'Error saving category.'); btn.classList.remove('saving'); }
      })
      .catch(function () { alert('Network error.'); btn.classList.remove('saving'); });
  };

  window.atDeleteCat = function (id, label) {
    if (!confirm(
      'Delete category "' + label + '" and ALL its tools?\n\nThis cannot be undone.'
    )) return;

    var fd = new FormData();
    fd.append('course_id',   courseId);
    fd.append('category_id', id);

    fetch(API + '?action=delete_category', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) atReload();
        else alert(d.message || 'Error deleting category.');
      })
      .catch(function () { alert('Network error.'); });
  };

  // ════════════════════════════════════════════════════════════════════════
  // Tool modal
  // ════════════════════════════════════════════════════════════════════════
  window.atOpenToolModal = function (id, catId, name, logo, sort) {
    document.getElementById('at-tool-id').value        = id    || 0;
    document.getElementById('at-tool-cat-id').value    = catId || 0;
    document.getElementById('at-tool-name').value      = name  || '';
    document.getElementById('at-tool-logo-path').value = logo  || '';
    document.getElementById('at-tool-logo-file').value = '';
    document.getElementById('at-tool-sort').value      = (sort !== undefined ? sort : 0);
    document.getElementById('at-tool-modal-title').textContent =
      id ? 'Edit Tool' : 'Add Tool';
    document.getElementById('at-tool-save-btn').classList.remove('saving');

    var prev = document.getElementById('at-tool-logo-preview');
    var img  = document.getElementById('at-tool-logo-img');
    if (logo) { img.src = adminBase + logo; prev.style.display = 'block'; }
    else       { img.src = '';              prev.style.display = 'none';  }

    document.getElementById('at-tool-overlay').style.display = 'block';
    setTimeout(function () { document.getElementById('at-tool-name').focus(); }, 80);
  };

  window.atCloseToolModal = function () {
    document.getElementById('at-tool-overlay').style.display = 'none';
  };

  // Live preview when logo path is typed
  var logoPathEl = document.getElementById('at-tool-logo-path');
  if (logoPathEl) {
    logoPathEl.addEventListener('input', function () {
      var prev = document.getElementById('at-tool-logo-preview');
      var img  = document.getElementById('at-tool-logo-img');
      if (this.value.trim()) { img.src = adminBase + this.value.trim(); prev.style.display = 'block'; }
      else                   { img.src = '';                            prev.style.display = 'none';  }
    });
  }

  // File-upload preview (clears manual path)
  var fileEl = document.getElementById('at-tool-logo-file');
  if (fileEl) {
    fileEl.addEventListener('change', function () {
      if (!this.files || !this.files[0]) return;
      var reader = new FileReader();
      reader.onload = function (e) {
        document.getElementById('at-tool-logo-img').src     = e.target.result;
        document.getElementById('at-tool-logo-preview').style.display = 'block';
        document.getElementById('at-tool-logo-path').value  = '';
      };
      reader.readAsDataURL(this.files[0]);
    });
  }

  window.atSaveTool = function () {
    var id    = document.getElementById('at-tool-id').value;
    var catId = document.getElementById('at-tool-cat-id').value;
    var name  = document.getElementById('at-tool-name').value.trim();
    var logo  = document.getElementById('at-tool-logo-path').value.trim();
    var file  = document.getElementById('at-tool-logo-file').files[0];
    var sort  = document.getElementById('at-tool-sort').value;

    if (!name)  { alert('Please enter the tool name.'); return; }
    if (!catId) { alert('Category not set.'); return; }

    var btn = document.getElementById('at-tool-save-btn');
    btn.classList.add('saving');

    var fd = new FormData();
    fd.append('tool_id',     id);
    fd.append('category_id', catId);
    fd.append('name',        name);
    fd.append('logo',        logo);
    fd.append('sort_order',  sort);
    if (file) fd.append('logo_file', file);

    fetch(API + '?action=save_tool', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) { atCloseToolModal(); atReload(); }
        else { alert(d.message || 'Error saving tool.'); btn.classList.remove('saving'); }
      })
      .catch(function () { alert('Network error.'); btn.classList.remove('saving'); });
  };

  window.atDeleteTool = function (id, catId, name) {
    if (!confirm('Delete tool "' + name + '"?')) return;

    var fd = new FormData();
    fd.append('tool_id',     id);
    fd.append('category_id', catId);

    fetch(API + '?action=delete_tool', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) atReload();
        else alert(d.message || 'Error deleting tool.');
      })
      .catch(function () { alert('Network error.'); });
  };

})();
ENDJS;

        echo json_encode(['success' => true, 'html' => $html, 'js' => $js]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE CATEGORY
    // POST /aitools_api.php?action=save_category
    // Body: course_id, category_id (0 = insert), label, sort_order
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $label      = trim($_POST['label']        ?? '');
        $sortOrder  = (int)($_POST['sort_order']  ?? 0);
        $updatedBy  = $_SESSION['da360_user']['name']
                   ?? $_SESSION['da360_user']['username']
                   ?? 'unknown';

        if (!$courseId || $label === '') {
            echo json_encode(['success' => false, 'message' => 'course_id and label are required']);
            exit;
        }

        if ($categoryId) {
            // Guard: only update categories that belong to this course
            $stmt = $db->prepare("
                UPDATE ai_tool_categories
                   SET label = ?, sort_order = ?, updated_at = NOW(), updated_by = ?
                 WHERE id = ? AND course_id = ?
                 LIMIT 1
            ");
            $stmt->execute([$label, $sortOrder, $updatedBy, $categoryId, $courseId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO ai_tool_categories
                       (course_id, label, sort_order, is_active, updated_at, updated_by)
                VALUES (?, ?, ?, 1, NOW(), ?)
            ");
            $stmt->execute([$courseId, $label, $sortOrder, $updatedBy]);
            $categoryId = (int)$db->lastInsertId();
        }

        echo json_encode(['success' => true, 'category_id' => $categoryId, 'message' => 'Category saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE CATEGORY
    // POST /aitools_api.php?action=delete_category
    // Body: course_id, category_id
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseId   = (int)($_POST['course_id']   ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);

        if (!$courseId || !$categoryId) {
            echo json_encode(['success' => false, 'message' => 'Missing course_id or category_id']);
            exit;
        }

        // Verify ownership before touching anything
        $stmt = $db->prepare("SELECT id FROM ai_tool_categories WHERE id = ? AND course_id = ? LIMIT 1");
        $stmt->execute([$categoryId, $courseId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Category not found for this course']);
            exit;
        }

        // Soft-delete child tools
        $db->prepare("UPDATE ai_tools SET is_active = 0 WHERE category_id = ?")
           ->execute([$categoryId]);

        // Soft-delete the category
        $stmt = $db->prepare("UPDATE ai_tool_categories SET is_active = 0 WHERE id = ? AND course_id = ? LIMIT 1");
        $stmt->execute([$categoryId, $courseId]);

        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Category deleted']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE TOOL
    // POST /aitools_api.php?action=save_tool
    // Body: category_id, tool_id (0 = insert), name, logo (path), sort_order
    // File: logo_file (optional — overrides logo path when provided)
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_tool' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $toolId     = (int)($_POST['tool_id']     ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $name       = trim($_POST['name']         ?? '');
        $logo       = trim($_POST['logo']         ?? '');
        $sortOrder  = (int)($_POST['sort_order']  ?? 0);
        $updatedBy  = $_SESSION['da360_user']['name']
                   ?? $_SESSION['da360_user']['username']
                   ?? 'unknown';

        if (!$categoryId || $name === '') {
            echo json_encode(['success' => false, 'message' => 'category_id and name are required']);
            exit;
        }

        // File upload takes priority over manual logo path
        $uploaded = handleImageUpload('logo_file', 'aitools');
        if ($uploaded !== '') $logo = $uploaded;

        if ($toolId) {
            $stmt = $db->prepare("
                UPDATE ai_tools
                   SET category_id = ?, name = ?, logo = ?,
                       sort_order = ?, updated_at = NOW(), updated_by = ?
                 WHERE id = ? AND category_id = ?
                 LIMIT 1
            ");
            $stmt->execute([$categoryId, $name, $logo, $sortOrder, $updatedBy, $toolId, $categoryId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO ai_tools
                       (category_id, name, logo, sort_order, is_active, updated_at, updated_by)
                VALUES (?, ?, ?, ?, 1, NOW(), ?)
            ");
            $stmt->execute([$categoryId, $name, $logo, $sortOrder, $updatedBy]);
            $toolId = (int)$db->lastInsertId();
        }

        echo json_encode(['success' => true, 'tool_id' => $toolId, 'logo' => $logo, 'message' => 'Tool saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE TOOL
    // POST /aitools_api.php?action=delete_tool
    // Body: category_id, tool_id
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_tool' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $toolId     = (int)($_POST['tool_id']     ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);

        if (!$toolId || !$categoryId) {
            echo json_encode(['success' => false, 'message' => 'Missing tool_id or category_id']);
            exit;
        }

        $stmt = $db->prepare("
            UPDATE ai_tools SET is_active = 0
             WHERE id = ? AND category_id = ?
             LIMIT 1
        ");
        $stmt->execute([$toolId, $categoryId]);

        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Tool deleted']);
        exit;
    }

    // ── Unknown action ────────────────────────────────────────────────────────
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}