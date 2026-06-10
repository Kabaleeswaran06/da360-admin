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
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); exit;
}

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json');

// ── Auth ─────────────────────────────────────────────────────────────────────
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$SECTIONS = ['programmes', 'company', 'resources', 'career', 'cities', 'legal'];

try {
    $db = getDB();

    // ══════════════════════════════════════════════════════════════════════════
    // GET FOOTER JSON — for Next.js frontend
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_footer_json') {

        // Settings
        $stmt = $db->query("SELECT setting_key, setting_value FROM footer_settings");
        $settingsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($settingsRaw as $s) $settings[$s['setting_key']] = $s['setting_value'];

        // Links grouped by section
        $stmt = $db->query("SELECT section, label, url FROM footer_links WHERE is_active = 1 ORDER BY section, sort_order");
        $linksRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $links = [];
        foreach ($SECTIONS as $sec) $links[$sec] = [];
        foreach ($linksRaw as $l) {
            if (isset($links[$l['section']])) {
                $links[$l['section']][] = ['label' => $l['label'], 'url' => $l['url']];
            }
        }

        // Social
        $stmt = $db->query("SELECT platform, url, icon_path FROM footer_social WHERE is_active = 1 ORDER BY sort_order");
        $social = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'    => true,
            'phone'      => $settings['phone']     ?? '',
            'email'      => $settings['email']     ?? '',
            'copyright'  => $settings['copyright'] ?? '',
            'programmes' => $links['programmes'],
            'company'    => $links['company'],
            'resources'  => $links['resources'],
            'career'     => $links['career'],
            'cities'     => $links['cities'],
            'legal'      => $links['legal'],
            'social'     => $social,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET FOOTER HTML — for CMS admin editor
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_footer_html') {

        // Settings
        $stmt = $db->query("SELECT setting_key, setting_value FROM footer_settings");
        $settingsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $settings = ['phone' => '', 'email' => '', 'copyright' => ''];
        foreach ($settingsRaw as $s) $settings[$s['setting_key']] = $s['setting_value'];

        // Links
        $stmt = $db->query("SELECT id, section, label, url, sort_order, is_active FROM footer_links ORDER BY section, sort_order");
        $allLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $linksBySection = [];
        foreach ($SECTIONS as $sec) $linksBySection[$sec] = [];
        foreach ($allLinks as $l) {
            if (isset($linksBySection[$l['section']])) $linksBySection[$l['section']][] = $l;
        }

        // Social
        $stmt = $db->query("SELECT id, platform, url, icon_path, sort_order, is_active FROM footer_social ORDER BY sort_order");
        $socialList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sectionMeta = [
            'programmes' => ['label' => '📋 Programmes', 'placeholder_label' => 'e.g. Leadership in Digital Marketing', 'placeholder_url' => '/digital-marketing-leadership-entrepreneurship-program'],
            'company'    => ['label' => '🏢 Company',    'placeholder_label' => 'e.g. About Us',   'placeholder_url' => '/about-digital-marketing-learning-structure-bangalore'],
            'resources'  => ['label' => '📚 Resources',  'placeholder_label' => 'e.g. Blogs',      'placeholder_url' => 'https://blog.digitalacademy360.com/'],
            'career'     => ['label' => '💼 Career',     'placeholder_label' => 'e.g. Hire From Us','placeholder_url' => '/hire-digital-marketer-fresher-intern'],
            'cities'     => ['label' => '🗺️ City Links', 'placeholder_label' => 'e.g. Digital Marketing Courses In Bangalore', 'placeholder_url' => '/digital-marketing-courses-bangalore'],
            'legal'      => ['label' => '⚖️ Legal Links','placeholder_label' => 'e.g. Privacy Policy', 'placeholder_url' => '/da360-privacy-policy'],
        ];

        ob_start(); ?>
<style>
.ft *, .ft *::before, .ft *::after { box-sizing: border-box; }
.ft { font-family: system-ui, sans-serif; color: #1e293b; }
.ft .tab-bar { display:flex; gap:4px; border-bottom:2px solid #e2e8f0; margin-bottom:20px; flex-wrap:wrap; }
.ft .tab-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:10px 18px; border:none; border-radius:8px 8px 0 0;
    font-size:13px; font-weight:600; cursor:pointer;
    background:#f1f5f9; color:#64748b;
    border-bottom:2px solid transparent; margin-bottom:-2px;
    transition:background .15s, color .15s;
}
.ft .tab-btn:hover { background:#e2e8f0; color:#1e293b; }
.ft .tab-btn.active { background:#fff; color:#6366f1; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0; border-top:2px solid #6366f1; border-bottom:2px solid #fff; }
.ft .tab-pane { display:none; }
.ft .tab-pane.active { display:block; }
.ft label { display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px; text-transform:uppercase; letter-spacing:.4px; }
.ft input[type=text], .ft input[type=url], .ft input[type=number], .ft textarea, .ft select {
    width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:6px;
    font-size:14px; color:#1e293b; background:#fff; transition:border-color .15s;
}
.ft input[type=text]:focus, .ft input[type=url]:focus, .ft input[type=number]:focus, .ft textarea:focus { border-color:#6366f1; outline:none; }
.ft .field-row   { margin-bottom:14px; }
.ft .field-2col  { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
.ft .field-3col  { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:14px; }
.ft .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:opacity .15s; }
.ft .btn:hover { opacity:.85; }
.ft .btn-primary { background:#6366f1; color:#fff; }
.ft .btn-success { background:#22c55e; color:#fff; }
.ft .btn-danger  { background:#ef4444; color:#fff; }
.ft .btn-sm      { padding:5px 10px; font-size:12px; }
.ft .btn-plus    { background:#e0f2fe; color:#0284c7; border:1.5px dashed #7dd3fc; }
.ft .item-block  { border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:10px; background:#fff; }
.ft .item-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:#f8fafc; border-radius:10px; }
.ft .item-num    { width:26px; height:26px; border-radius:50%; background:#6366f1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; margin-right:10px; }
.ft .item-title-text { font-size:14px; font-weight:600; color:#1e293b; flex:1; }
.ft .info-card       { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:18px; }
.ft .info-card-title { font-size:12px; font-weight:700; color:#6366f1; text-transform:uppercase; letter-spacing:.5px; margin-bottom:14px; }
.ft .inactive-badge  { font-size:11px; background:#fef3c7; color:#b45309; padding:2px 8px; border-radius:12px; margin-left:8px; }
.ft .saving  { opacity:.5; pointer-events:none; }
.ft .saved   { background:#dcfce7 !important; transition:background .4s; }
.ft .errored { background:#fee2e2 !important; }
.ft .divider { border:none; border-top:1px solid #e2e8f0; margin:16px 0; }
.ft .link-row { display:flex; align-items:center; gap:10px; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:8px; margin-bottom:8px; background:#fff; }
.ft .link-row input[type=text], .ft .link-row input[type=url] { margin-bottom:0; }
.ft .link-inputs { flex:1; display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.ft .toggle-active { display:flex; align-items:center; gap:6px; font-size:13px; color:#64748b; white-space:nowrap; }
</style>

<div class="ft" id="ft-root">

  <!-- ── Tab bar ── -->
  <div class="tab-bar">
    <button class="tab-btn active" data-tab="settings">⚙️ Contact & Copyright</button>
    <button class="tab-btn" data-tab="programmes">📋 Programmes</button>
    <button class="tab-btn" data-tab="company">🏢 Company</button>
    <button class="tab-btn" data-tab="resources">📚 Resources</button>
    <button class="tab-btn" data-tab="career">💼 Career</button>
    <button class="tab-btn" data-tab="cities">🗺️ City Links</button>
    <button class="tab-btn" data-tab="legal">⚖️ Legal</button>
    <button class="tab-btn" data-tab="social">📱 Social Media</button>
  </div>

  <!-- TAB 1 — CONTACT & COPYRIGHT -->
  <div class="tab-pane active" id="tab-pane-settings">
    <div class="info-card">
      <div class="info-card-title">⚙️ Contact & Copyright Settings</div>
      <div class="field-2col">
        <div class="field-row">
          <label>Phone Number</label>
          <input type="text" id="ft-phone" value="<?= htmlspecialchars($settings['phone']) ?>" placeholder="+91 7353 515 515">
        </div>
        <div class="field-row">
          <label>Email Address</label>
          <input type="text" id="ft-email" value="<?= htmlspecialchars($settings['email']) ?>" placeholder="info@da360.ai">
        </div>
      </div>
      <div class="field-row">
        <label>Copyright Text</label>
        <input type="text" id="ft-copyright" value="<?= htmlspecialchars($settings['copyright']) ?>" placeholder="Copyright © 2025 Digital Academy 360. All rights reserved.">
      </div>
      <button class="btn btn-primary" data-action="save-settings">💾 Save Settings</button>
    </div>
  </div>

  <?php foreach ($sectionMeta as $sec => $meta): ?>
  <!-- TAB — <?= strtoupper($sec) ?> -->
  <div class="tab-pane" id="tab-pane-<?= $sec ?>">
    <div class="info-card">
      <div class="info-card-title"><?= $meta['label'] ?> Links</div>
      <div id="ft-links-<?= $sec ?>">
        <?php foreach ($linksBySection[$sec] as $li => $link): ?>
        <div class="link-row" data-link-id="<?= (int)$link['id'] ?>" data-section="<?= $sec ?>" data-sort="<?= (int)$link['sort_order'] ?>">
          <div class="item-num"><?= $li+1 ?></div>
          <div class="link-inputs">
            <input type="text" class="ft-link-label" value="<?= htmlspecialchars($link['label']) ?>" placeholder="<?= htmlspecialchars($meta['placeholder_label']) ?>">
            <input type="url"  class="ft-link-url"   value="<?= htmlspecialchars($link['url']) ?>"   placeholder="<?= htmlspecialchars($meta['placeholder_url']) ?>">
          </div>
          <div class="sort-wrap" style="display:flex;flex-direction:column;align-items:center;gap:2px;min-width:56px;">
            <label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin:0;">Order</label>
            <input type="number" class="ft-link-sort" value="<?= (int)$link['sort_order'] ?>" min="1" max="9999"
              style="width:56px;padding:5px 6px;text-align:center;border:1.5px solid #cbd5e1;border-radius:6px;font-size:13px;font-weight:700;color:#6366f1;">
          </div>
          <label class="toggle-active">
            <input type="checkbox" class="ft-link-active" <?= $link['is_active'] ? 'checked' : '' ?>> Active
          </label>
          <button class="btn btn-success btn-sm" data-action="save-link">💾</button>
          <button class="btn btn-danger btn-sm"  data-action="delete-link">🗑</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-plus" style="margin-top:10px;" data-action="add-link" data-section="<?= $sec ?>"
        data-placeholder-label="<?= htmlspecialchars($meta['placeholder_label']) ?>"
        data-placeholder-url="<?= htmlspecialchars($meta['placeholder_url']) ?>">
        ＋ Add Link
      </button>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- TAB — SOCIAL MEDIA -->
  <div class="tab-pane" id="tab-pane-social">
    <div class="info-card">
      <div class="info-card-title">📱 Social Media Links</div>
      <div id="ft-social-container">
        <?php foreach ($socialList as $si => $soc): ?>
        <div class="item-block" data-social-id="<?= (int)$soc['id'] ?>" data-sort="<?= (int)$soc['sort_order'] ?>">
          <div class="item-header">
            <div style="display:flex;align-items:center;flex:1;">
              <div class="item-num"><?= $si+1 ?></div>
              <span class="item-title-text"><?= htmlspecialchars($soc['platform']) ?></span>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-success btn-sm" data-action="save-social">💾 Save</button>
              <button class="btn btn-danger btn-sm"  data-action="delete-social">🗑</button>
            </div>
          </div>
          <div style="padding:14px;">
            <div class="field-3col">
              <div class="field-row">
                <label>Platform Name</label>
                <input type="text" class="soc-platform" value="<?= htmlspecialchars($soc['platform']) ?>" placeholder="e.g. Facebook">
              </div>
              <div class="field-row">
                <label>Profile URL</label>
                <input type="url" class="soc-url" value="<?= htmlspecialchars($soc['url']) ?>" placeholder="https://www.facebook.com/...">
              </div>
              <div class="field-row">
                <label>Icon Path (in /images/social/)</label>
                <input type="text" class="soc-icon" value="<?= htmlspecialchars($soc['icon_path']) ?>" placeholder="/images/social/facebook.svg">
              </div>
            </div>
            <label class="toggle-active" style="display:inline-flex;">
              <input type="checkbox" class="soc-active" <?= $soc['is_active'] ? 'checked' : '' ?>> &nbsp;Active
            </label>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-plus" style="margin-top:10px;" data-action="add-social">＋ Add Social Link</button>
    </div>
  </div>

</div><!-- /ft-root -->
<?php
        $html = ob_get_clean();

        // ── JavaScript ───────────────────────────────────────────────────────
        ob_start(); ?>
(function () {
  var root  = document.getElementById('ft-root');
  var API   = '/da360-admin/footer_api.php';

  // ── Tab switching ─────────────────────────────────────────────────────────
  root.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      root.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
      root.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
      btn.classList.add('active');
      var pane = root.querySelector('#tab-pane-' + btn.dataset.tab);
      if (pane) pane.classList.add('active');
    });
  });

  // ── Helpers ───────────────────────────────────────────────────────────────
  function flash(el, ok) {
    el.classList.remove('saving');
    el.classList.add(ok ? 'saved' : 'errored');
    setTimeout(function () { el.classList.remove('saved', 'errored'); }, 1200);
  }

  function post(data, callback) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k] ?? ''); });
    fetch(API, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) { callback(d.success, d); })
      .catch(function () { callback(false, {}); });
  }

  function nextSort(container) {
    var rows = container.querySelectorAll('[data-sort]');
    var max  = 0;
    rows.forEach(function (r) { var s = parseInt(r.dataset.sort, 10) || 0; if (s > max) max = s; });
    return max + 1;
  }

  // ── Delegate click handler ────────────────────────────────────────────────
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.dataset.action;

    // ── Save Settings ─────────────────────────────────────────────────────
    if (action === 'save-settings') {
      var card = btn.closest('.info-card');
      card.classList.add('saving');
      var phone     = document.getElementById('ft-phone').value.trim();
      var email     = document.getElementById('ft-email').value.trim();
      var copyright = document.getElementById('ft-copyright').value.trim();

      var done = 0;
      var ok   = true;
      function finish(success) { ok = ok && success; if (++done === 3) { flash(card, ok); } }

      post({ action: 'save_settings', setting_key: 'phone',     setting_value: phone     }, function (s) { finish(s); });
      post({ action: 'save_settings', setting_key: 'email',     setting_value: email     }, function (s) { finish(s); });
      post({ action: 'save_settings', setting_key: 'copyright', setting_value: copyright }, function (s) { finish(s); });
      return;
    }

    // ── Save Link ─────────────────────────────────────────────────────────
    if (action === 'save-link') {
      var row     = btn.closest('.link-row');
      var linkId  = parseInt(row.dataset.linkId, 10) || 0;
      var section = row.dataset.section;
      var label   = row.querySelector('.ft-link-label').value.trim();
      var url     = row.querySelector('.ft-link-url').value.trim();
      var active  = row.querySelector('.ft-link-active').checked ? 1 : 0;

      // ✅ Read from the visible input, fallback to data-sort
      var sortInput = row.querySelector('.ft-link-sort');
      var sort      = sortInput ? (parseInt(sortInput.value, 10) || 1) : (parseInt(row.dataset.sort, 10) || 1);

      // ✅ Keep data-sort in sync so nextSort() stays accurate
      row.dataset.sort = sort;

      row.classList.add('saving');
      post({ action: 'save_link', link_id: linkId, section: section, label: label, url: url, is_active: active, sort_order: sort }, function (success, d) {
        if (success && !linkId) row.dataset.linkId = d.link_id || '';
        flash(row, success);
      });
      return;
    }

    // ── Delete Link ───────────────────────────────────────────────────────
    if (action === 'delete-link') {
      var row    = btn.closest('.link-row');
      var linkId = parseInt(row.dataset.linkId, 10) || 0;
      if (!linkId) { row.remove(); return; }
      if (!confirm('Delete this link?')) return;
      post({ action: 'delete_link', link_id: linkId }, function (success) {
        if (success) row.remove();
        else alert('Delete failed.');
      });
      return;
    }

    // ── Add Link ──────────────────────────────────────────────────────────
    if (action === 'add-link') {
      var section  = btn.dataset.section;
      var placeLbl = btn.dataset.placeholderLabel || 'Label';
      var placeUrl = btn.dataset.placeholderUrl   || 'https://';
      var container = document.getElementById('ft-links-' + section);
      var count     = container.querySelectorAll('.link-row').length + 1;
      var sort      = nextSort(container);
      var div = document.createElement('div');
      div.className = 'link-row';
      div.dataset.linkId  = '0';
      div.dataset.section = section;
      div.dataset.sort    = sort;
      div.innerHTML =
         '<div class="item-num">' + count + '</div>' +
          '<div class="link-inputs">' +
            '<input type="text" class="ft-link-label" placeholder="' + placeLbl + '">' +
            '<input type="url"  class="ft-link-url"   placeholder="' + placeUrl + '">' +
          '</div>' +
          '<div class="sort-wrap" style="display:flex;flex-direction:column;align-items:center;gap:2px;min-width:56px;">' +
            '<label style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin:0;">Order</label>' +
            '<input type="number" class="ft-link-sort" value="' + sort + '" min="1" max="9999" ' +
              'style="width:56px;padding:5px 6px;text-align:center;border:1.5px solid #cbd5e1;border-radius:6px;font-size:13px;font-weight:700;color:#6366f1;">' +
          '</div>' +
          '<label class="toggle-active"><input type="checkbox" class="ft-link-active" checked> Active</label>' +
          '<button class="btn btn-success btn-sm" data-action="save-link">💾</button>' +
          '<button class="btn btn-danger btn-sm"  data-action="delete-link">🗑</button>';
      container.appendChild(div);
      div.querySelector('.ft-link-label').focus();
      return;
    }

    // ── Save Social ───────────────────────────────────────────────────────
    if (action === 'save-social') {
      var block    = btn.closest('.item-block');
      var socialId = parseInt(block.dataset.socialId, 10) || 0;
      var platform = block.querySelector('.soc-platform').value.trim();
      var url      = block.querySelector('.soc-url').value.trim();
      var icon     = block.querySelector('.soc-icon').value.trim();
      var active   = block.querySelector('.soc-active').checked ? 1 : 0;
      var sort     = parseInt(block.dataset.sort, 10) || 1;

      block.classList.add('saving');
      post({ action: 'save_social', social_id: socialId, platform: platform, url: url, icon_path: icon, is_active: active, sort_order: sort }, function (success, d) {
        if (success && !socialId) block.dataset.socialId = d.social_id || '';
        block.querySelector('.item-title-text').textContent = platform;
        flash(block, success);
      });
      return;
    }

    // ── Delete Social ─────────────────────────────────────────────────────
    if (action === 'delete-social') {
      var block    = btn.closest('.item-block');
      var socialId = parseInt(block.dataset.socialId, 10) || 0;
      if (!socialId) { block.remove(); return; }
      if (!confirm('Delete this social link?')) return;
      post({ action: 'delete_social', social_id: socialId }, function (success) {
        if (success) block.remove();
        else alert('Delete failed.');
      });
      return;
    }

    // ── Add Social ────────────────────────────────────────────────────────
    if (action === 'add-social') {
      var container = document.getElementById('ft-social-container');
      var count     = container.querySelectorAll('.item-block').length + 1;
      var sort      = nextSort(container);
      var div = document.createElement('div');
      div.className = 'item-block';
      div.dataset.socialId = '0';
      div.dataset.sort     = sort;
      div.innerHTML =
        '<div class="item-header">' +
          '<div style="display:flex;align-items:center;flex:1;">' +
            '<div class="item-num">' + count + '</div>' +
            '<span class="item-title-text">New Platform</span>' +
          '</div>' +
          '<div style="display:flex;gap:8px;">' +
            '<button class="btn btn-success btn-sm" data-action="save-social">💾 Save</button>' +
            '<button class="btn btn-danger btn-sm"  data-action="delete-social">🗑</button>' +
          '</div>' +
        '</div>' +
        '<div style="padding:14px;">' +
          '<div class="field-3col">' +
            '<div class="field-row"><label>Platform Name</label><input type="text" class="soc-platform" placeholder="e.g. Facebook"></div>' +
            '<div class="field-row"><label>Profile URL</label><input type="url" class="soc-url" placeholder="https://www.facebook.com/..."></div>' +
            '<div class="field-row"><label>Icon Path</label><input type="text" class="soc-icon" placeholder="/images/social/facebook.svg"></div>' +
          '</div>' +
          '<label class="toggle-active" style="display:inline-flex;"><input type="checkbox" class="soc-active" checked> &nbsp;Active</label>' +
        '</div>';
      container.appendChild(div);
      div.querySelector('.soc-platform').focus();
      return;
    }
  });
})();
<?php
        $js = ob_get_clean();

        echo json_encode(['success' => true, 'html' => $html, 'js' => $js]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE SETTINGS
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $key       = trim($_POST['setting_key']   ?? '');
        $value     = trim($_POST['setting_value'] ?? '');
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        $allowed = ['phone', 'email', 'copyright'];
        if (!in_array($key, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid setting key']); exit;
        }

        $stmt = $db->prepare("INSERT INTO footer_settings (setting_key, setting_value, updated_at, updated_by)
                               VALUES (?, ?, NOW(), ?)
                               ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW(), updated_by=VALUES(updated_by)");
        $stmt->execute([$key, $value, $updatedBy]);
        echo json_encode(['success' => true, 'message' => 'Setting saved']); exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE LINK
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $linkId    = (int)($_POST['link_id']    ?? 0);
        $section   = trim($_POST['section']     ?? '');
        $label     = trim($_POST['label']       ?? '');
        $url       = trim($_POST['url']         ?? '');
        $isActive  = (int)($_POST['is_active']  ?? 1);
        $sortOrder = (int)($_POST['sort_order'] ?? 1);
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        $validSections = ['programmes', 'company', 'resources', 'career', 'cities', 'legal'];
        if (!in_array($section, $validSections)) {
            echo json_encode(['success' => false, 'message' => 'Invalid section']); exit;
        }

        if ($linkId) {
            $stmt = $db->prepare("UPDATE footer_links SET section=?, label=?, url=?, is_active=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=?");
            $stmt->execute([$section, $label, $url, $isActive, $sortOrder, $updatedBy, $linkId]);
        } else {
            $stmt = $db->prepare("INSERT INTO footer_links (section, label, url, is_active, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,NOW(),?)");
            $stmt->execute([$section, $label, $url, $isActive, $sortOrder, $updatedBy]);
            $linkId = (int)$db->lastInsertId();
        }
        echo json_encode(['success' => true, 'link_id' => $linkId, 'message' => 'Link saved']); exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE LINK
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        if (!$linkId) { echo json_encode(['success' => false, 'message' => 'Invalid link_id']); exit; }
        $stmt = $db->prepare("DELETE FROM footer_links WHERE id=? LIMIT 1");
        $stmt->execute([$linkId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Link deleted']); exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE SOCIAL
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_social' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $socialId  = (int)($_POST['social_id']  ?? 0);
        $platform  = trim($_POST['platform']    ?? '');
        $url       = trim($_POST['url']         ?? '');
        $iconPath  = trim($_POST['icon_path']   ?? '');
        $isActive  = (int)($_POST['is_active']  ?? 1);
        $sortOrder = (int)($_POST['sort_order'] ?? 1);
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';

        if ($socialId) {
            $stmt = $db->prepare("UPDATE footer_social SET platform=?, url=?, icon_path=?, is_active=?, sort_order=?, updated_at=NOW(), updated_by=? WHERE id=?");
            $stmt->execute([$platform, $url, $iconPath, $isActive, $sortOrder, $updatedBy, $socialId]);
        } else {
            $stmt = $db->prepare("INSERT INTO footer_social (platform, url, icon_path, is_active, sort_order, updated_at, updated_by) VALUES (?,?,?,?,?,NOW(),?)");
            $stmt->execute([$platform, $url, $iconPath, $isActive, $sortOrder, $updatedBy]);
            $socialId = (int)$db->lastInsertId();
        }
        echo json_encode(['success' => true, 'social_id' => $socialId, 'message' => 'Social link saved']); exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE SOCIAL
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_social' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $socialId = (int)($_POST['social_id'] ?? 0);
        if (!$socialId) { echo json_encode(['success' => false, 'message' => 'Invalid social_id']); exit; }
        $stmt = $db->prepare("DELETE FROM footer_social WHERE id=? LIMIT 1");
        $stmt->execute([$socialId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Social link deleted']); exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
