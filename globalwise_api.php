<?php
// ── CORS ────────────────────────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:3000',
    'http://localhost',
    'https://www.digitalacademy360.com',
    'https://digitalacademy360.com',
    'https://dev2.digitalacademy360.com',
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

// ── Auth ────────────────────────────────────────────────────────────────────
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

// ── Upload helper ────────────────────────────────────────────────────────────
function handleImageUpload(string $fileKey, string $folder): string {
    if (empty($_FILES[$fileKey]['tmp_name'])) return '';
    $uploadDir = __DIR__ . '/uploads/' . $folder . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext     = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','svg','webp'];
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
    // GET GLOBALWISE JSON — for Next.js frontend
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_globalwise_json') {
        $base_url = 'https://confirmation.digitalacademy360.com/da360-admin';

        // ── Hero Counts ───────────────────────────────────────────────────
        $stmt = $db->query("SELECT slot, count_value, count_label FROM global_hero_counts ORDER BY slot");
        $heroCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Video URL ─────────────────────────────────────────────────────
        $stmt = $db->query("SELECT video_url FROM global_video LIMIT 1");
        $videoRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $videoUrl = $videoRow['video_url'] ?? '';

        // ── Success Stories ───────────────────────────────────────────────
        $stmt = $db->query("SELECT name, company_name, previous_role, package_lpa, new_role, profile_image, company_logo FROM global_success_stories ORDER BY sort_order");
        $stories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stories as &$s) {
            $s['profileImage'] = $s['profile_image'] ? $base_url . $s['profile_image'] : '';
            $s['companyLogo']  = $s['company_logo']  ? $base_url . $s['company_logo']  : '';
            $s['companyName']  = $s['company_name'];
            $s['previousRole'] = $s['previous_role'];
            $s['packageLPA']   = $s['package_lpa'];
            $s['newRole']      = $s['new_role'];
            unset($s['profile_image'], $s['company_logo'], $s['company_name'], $s['previous_role'], $s['package_lpa'], $s['new_role']);
        } unset($s);

        // ── Life @ DA360 Videos ───────────────────────────────────────────
        $stmt = $db->query("SELECT slot, title, img, video_url FROM global_life_videos ORDER BY slot");
        $lifeVideos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lifeVideos as &$lv) {
            $lv['img'] = $lv['img'] ? $base_url . $lv['img'] : '';
        } unset($lv);

        // ── Guest Faculty ─────────────────────────────────────────────────
        $stmt = $db->query("SELECT id, name, name_popup, title, expertise, description, profile_image, profile_image_popup FROM guest_faculty ORDER BY sort_order, id");
        $gfRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $guestFaculty = [];
        foreach ($gfRows as $gf) {
            $gfId = (int)$gf['id'];
            $stmt2 = $db->prepare("SELECT logo FROM guest_faculty_logos WHERE faculty_id = ? ORDER BY sort_order");
            $stmt2->execute([$gfId]);
            $logos = array_map(fn($l) => $base_url . $l, $stmt2->fetchAll(PDO::FETCH_COLUMN));
            $guestFaculty[] = [
                'name'              => $gf['name'],
                'namePopup'         => $gf['name_popup'],
                'title'             => $gf['title'],
                'expertise'         => $gf['expertise'],
                'description'       => $gf['description'],
                'profileImage'      => $gf['profile_image']       ? $base_url . $gf['profile_image']       : '',
                'profileImagePopup' => $gf['profile_image_popup'] ? $base_url . $gf['profile_image_popup'] : '',
                'logos'             => array_values($logos),
            ];
        }

        // ── Faculty ───────────────────────────────────────────────────────
        $stmt = $db->query("SELECT name, role, experience, img, linkedin_link, tab, text_color, icon, icon_img, icon_position FROM global_faculty ORDER BY sort_order");
        $faculty = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($faculty as &$f) {
            $f['img']          = $f['img']      ? $base_url . $f['img']      : '';
            $f['iconImg']      = $f['icon_img'] ? $base_url . $f['icon_img'] : '';
            $f['linkedinLink'] = $f['linkedin_link'];
            $f['textColor']    = $f['text_color'];
            $f['iconPosition'] = $f['icon_position'];
            unset($f['linkedin_link'], $f['text_color'], $f['icon_img'], $f['icon_position']);
        } unset($f);

        // ── Banners ───────────────────────────────────────────────────────
        $stmt = $db->query("SELECT image_url FROM global_banners ORDER BY sort_order");
        $banners = array_map(fn($r) => $base_url . $r['image_url'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        // ── Blog Posts ────────────────────────────────────────────────────
        $stmt = $db->query("SELECT img_src, category, title, link FROM global_blog_posts ORDER BY sort_order");
        $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($blogs as &$b) {
            $b['imgSrc'] = $b['img_src'] ? $base_url . $b['img_src'] : '';
            unset($b['img_src']);
        } unset($b);

        // ── Media & Awards ────────────────────────────────────────────────
        $stmt = $db->query("SELECT src, alt FROM global_media ORDER BY sort_order");
        $media = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($media as &$m) {
            $m['src'] = $m['src'] ? $base_url . $m['src'] : '';
        } unset($m);

        echo json_encode([
            'success'        => true,
            'heroCounts'     => $heroCounts,
            'videoUrl'       => $videoUrl,
            'successStories' => $stories,
            'lifeVideos'     => $lifeVideos,
            'guestFaculty'   => $guestFaculty,
            'faculty'        => $faculty,
            'banners'        => $banners,
            'blogPosts'      => $blogs,
            'mediaLogos'     => $media,
        ]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET GLOBALWISE HTML — for CMS admin editor
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'get_globalwise_html') {

        // Hero Counts (3 fixed slots)
        $stmt = $db->query("SELECT id, slot, count_value, count_label FROM global_hero_counts ORDER BY slot");
        $heroCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hcMap = [];
        foreach ($heroCounts as $hc) $hcMap[(int)$hc['slot']] = $hc;
        for ($i = 1; $i <= 3; $i++) {
            if (!isset($hcMap[$i])) $hcMap[$i] = ['id' => 0, 'slot' => $i, 'count_value' => '', 'count_label' => ''];
        }
        ksort($hcMap);

        // Video URL
        $stmt = $db->query("SELECT video_url FROM global_video LIMIT 1");
        $videoRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $videoUrl = $videoRow['video_url'] ?? '';

        // Success Stories
        $stmt = $db->query("SELECT id, name, company_name, previous_role, package_lpa, new_role, profile_image, company_logo, sort_order FROM global_success_stories ORDER BY sort_order");
        $stories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Life Videos (4 fixed slots)
        $stmt = $db->query("SELECT id, slot, title, img, video_url FROM global_life_videos ORDER BY slot");
        $lifeVideos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $lvMap = [];
        foreach ($lifeVideos as $lv) $lvMap[(int)$lv['slot']] = $lv;
        for ($i = 1; $i <= 4; $i++) {
            if (!isset($lvMap[$i])) $lvMap[$i] = ['id' => 0, 'slot' => $i, 'title' => '', 'img' => '', 'video_url' => ''];
        }
        ksort($lvMap);

        // Guest Faculty
        $stmt = $db->query("SELECT id, name, name_popup, title, expertise, description, profile_image, profile_image_popup, sort_order FROM guest_faculty ORDER BY sort_order, id");
        $gfRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $guestFacultyList = [];
        foreach ($gfRows as $gf) {
            $gfId = (int)$gf['id'];
            $stmt2 = $db->prepare("SELECT id, logo, sort_order FROM guest_faculty_logos WHERE faculty_id = ? ORDER BY sort_order");
            $stmt2->execute([$gfId]);
            $gf['logos'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $guestFacultyList[] = $gf;
        }

        // Faculty
        $stmt = $db->query("SELECT id, name, role, experience, img, linkedin_link, tab, text_color, icon, icon_img, icon_position, sort_order FROM global_faculty ORDER BY sort_order");
        $faculty = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Banners
        $stmt = $db->query("SELECT id, image_url, sort_order FROM global_banners ORDER BY sort_order");
        $banners = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Blog Posts
        $stmt = $db->query("SELECT id, img_src, category, title, link, sort_order FROM global_blog_posts ORDER BY sort_order");
        $blogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Media
        $stmt = $db->query("SELECT id, src, alt, sort_order FROM global_media ORDER BY sort_order");
        $media = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_start(); ?>
<style>
.gw *, .gw *::before, .gw *::after { box-sizing: border-box; }
.gw { font-family: system-ui, sans-serif; color: #1e293b; }
.gw .tab-bar { display:flex; gap:4px; border-bottom:2px solid #e2e8f0; margin-bottom:20px; flex-wrap:wrap; }
.gw .tab-btn {
    display:inline-flex; align-items:center; gap:6px;
    padding:10px 18px; border:none; border-radius:8px 8px 0 0;
    font-size:13px; font-weight:600; cursor:pointer;
    background:#f1f5f9; color:#64748b;
    border-bottom:2px solid transparent; margin-bottom:-2px;
    transition:background .15s, color .15s;
}
.gw .tab-btn:hover { background:#e2e8f0; color:#1e293b; }
.gw .tab-btn.active { background:#fff; color:#6366f1; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0; border-top:2px solid #6366f1; border-bottom:2px solid #fff; }
.gw .tab-pane { display:none; }
.gw .tab-pane.active { display:block; }
.gw .section-card { background:transparent; border:none; margin-bottom:0; overflow:visible; }
.gw .section-body  { padding:0; display:block; }
.gw label { display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:4px; text-transform:uppercase; letter-spacing:.4px; }
.gw input[type=text], .gw input[type=url], .gw input[type=number], .gw textarea, .gw select {
    width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:6px;
    font-size:14px; color:#1e293b; background:#fff; transition:border-color .15s;
}
.gw input[type=text]:focus, .gw input[type=url]:focus,
.gw input[type=number]:focus, .gw textarea:focus, .gw select:focus { border-color:#6366f1; outline:none; }
.gw textarea { resize:vertical; min-height:80px; }
.gw .field-row   { margin-bottom:14px; }
.gw .field-2col  { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
.gw .field-3col  { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:14px; }
.gw .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; transition:opacity .15s; }
.gw .btn:hover { opacity:.85; }
.gw .btn-primary { background:#6366f1; color:#fff; }
.gw .btn-success { background:#22c55e; color:#fff; }
.gw .btn-danger  { background:#ef4444; color:#fff; }
.gw .btn-sm      { padding:5px 10px; font-size:12px; }
.gw .btn-plus    { background:#e0f2fe; color:#0284c7; border:1.5px dashed #7dd3fc; }
.gw .item-block  { border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:14px; background:#fff; }
.gw .item-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; border-radius:10px 10px 0 0; cursor:pointer; }
.gw .item-num    { width:28px; height:28px; border-radius:50%; background:#6366f1; color:#fff; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:700; flex-shrink:0; }
.gw .item-title-text { font-size:14px; font-weight:600; color:#1e293b; margin-left:10px; flex:1; }
.gw .item-body   { padding:16px; display:none; }
.gw .item-body.open { display:block; }
.gw .img-preview    { width:60px; height:60px; object-fit:contain; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:6px; background:#f8fafc; display:block; }
.gw .img-preview-lg { width:120px; height:60px; object-fit:contain; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:6px; background:#f8fafc; display:block; }
.gw .info-card       { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:18px; }
.gw .info-card-title { font-size:12px; font-weight:700; color:#6366f1; text-transform:uppercase; letter-spacing:.5px; margin-bottom:14px; }
.gw .slot-label      { font-size:11px; font-weight:700; color:#9333ea; text-transform:uppercase; letter-spacing:.4px; margin-bottom:8px; }
.gw .saving  { opacity:.5; pointer-events:none; }
.gw .saved   { background:#dcfce7 !important; transition:background .4s; }
.gw .errored { background:#fee2e2 !important; }
.gw .divider { border:none; border-top:1px solid #e2e8f0; margin:16px 0; }
</style>

<div class="gw" id="gw-root">

  <!-- ── Tab bar ── -->
  <div class="tab-bar">
    <button class="tab-btn active" data-tab="herocount">🔢 Hero Counts</button>
    <button class="tab-btn"        data-tab="video">🎬 Campus Tour</button>
    <button class="tab-btn"        data-tab="stories">🏆 Success Stories</button>
    <button class="tab-btn"        data-tab="lifevideo">🎥 Life @ DA360</button>
    <button class="tab-btn"        data-tab="guestfaculty">🎓 Guest Faculty</button>
    <button class="tab-btn"        data-tab="faculty">👩‍🏫 Faculty</button>
    <button class="tab-btn"        data-tab="banners">🖼️ Banners</button>
    <button class="tab-btn"        data-tab="blogs">📝 Blog Posts</button>
    <button class="tab-btn"        data-tab="media">🏅 Media &amp; Awards</button>
  </div>

  <!-- TAB 1 — HERO COUNTS -->
  <div class="tab-pane active" id="tab-pane-herocount">
    <div class="info-card">
      <div class="info-card-title">🔢 Hero Section Counts (3 fixed slots)</div>
      <?php foreach ($hcMap as $slot => $hc): ?>
      <div style="border:1.5px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:14px;" data-hc-slot="<?= $slot ?>" data-hc-id="<?= (int)$hc['id'] ?>">
        <div class="slot-label">Slot <?= $slot ?></div>
        <div class="field-2col">
          <div class="field-row"><label>Count Value</label><input type="text" class="hc-count-value" value="<?= htmlspecialchars($hc['count_value']) ?>" placeholder="e.g. 10,000+"></div>
          <div class="field-row"><label>Label</label><input type="text" class="hc-count-label" value="<?= htmlspecialchars($hc['count_label']) ?>" placeholder="e.g. Students Trained"></div>
        </div>
        <button class="btn btn-success btn-sm" data-action="save-herocount">💾 Save Slot <?= $slot ?></button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- TAB 2 — VIDEO URL -->
  <div class="tab-pane" id="tab-pane-video">
    <div class="info-card">
      <div class="info-card-title">🎬 Main Video URL</div>
      <div class="field-row">
        <label>Video URL (YouTube / Vimeo embed or direct)</label>
        <input type="url" id="gw-video-url" value="<?= htmlspecialchars($videoUrl) ?>" placeholder="https://www.youtube.com/embed/...">
      </div>
      <button class="btn btn-primary" data-action="save-video">💾 Save Video URL</button>
    </div>
  </div>

  <!-- TAB 3 — SUCCESS STORIES -->
  <div class="tab-pane" id="tab-pane-stories">
    <div id="stories-container">
      <?php foreach ($stories as $si => $story): ?>
      <div class="item-block" data-item-id="<?= (int)$story['id'] ?>" data-item-index="<?= $si ?>" data-sort="<?= (int)$story['sort_order'] ?>">
        <div class="item-header" data-toggle-item="story-<?= $si ?>">
          <div style="display:flex;align-items:center;flex:1;">
            <div class="item-num"><?= $si+1 ?></div>
            <span class="item-title-text"><?= htmlspecialchars($story['name']) ?> — <?= htmlspecialchars($story['company_name']) ?></span>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-success btn-sm" data-action="save-story">💾 Save</button>
            <button class="btn btn-danger btn-sm"  data-action="delete-story">🗑</button>
          </div>
        </div>
        <div class="item-body open" id="item-body-story-<?= $si ?>">
          <div class="field-3col">
            <div class="field-row"><label>Name</label><input type="text" class="st-name" value="<?= htmlspecialchars($story['name']) ?>" placeholder="Student name"></div>
            <div class="field-row"><label>Company Name</label><input type="text" class="st-company-name" value="<?= htmlspecialchars($story['company_name']) ?>" placeholder="e.g. Jeet"></div>
            <div class="field-row"><label>Package LPA</label><input type="text" class="st-package-lpa" value="<?= htmlspecialchars($story['package_lpa']) ?>" placeholder="e.g. 5 LPA"></div>
          </div>
          <div class="field-2col">
            <div class="field-row"><label>Previous Role</label><input type="text" class="st-previous-role" value="<?= htmlspecialchars($story['previous_role']) ?>" placeholder="e.g. SEO Analyst"></div>
            <div class="field-row"><label>New Role</label><input type="text" class="st-new-role" value="<?= htmlspecialchars($story['new_role']) ?>" placeholder="e.g. Fresher"></div>
          </div>
          <div class="field-2col">
            <div>
              <label>Profile Image</label>
              <?php if ($story['profile_image']): ?><img src="/da360-admin<?= htmlspecialchars($story['profile_image']) ?>" class="img-preview st-profile-preview" alt="profile"><?php else: ?><img src="" class="img-preview st-profile-preview" style="display:none;" alt="profile"><?php endif; ?>
              <input type="file" class="st-profile-file" accept="image/*" style="margin-top:4px;">
              <input type="hidden" class="st-profile-path" value="<?= htmlspecialchars($story['profile_image']) ?>">
            </div>
            <div>
              <label>Company Logo</label>
              <?php if ($story['company_logo']): ?><img src="/da360-admin<?= htmlspecialchars($story['company_logo']) ?>" class="img-preview st-logo-preview" alt="logo"><?php else: ?><img src="" class="img-preview st-logo-preview" style="display:none;" alt="logo"><?php endif; ?>
              <input type="file" class="st-logo-file" accept="image/*" style="margin-top:4px;">
              <input type="hidden" class="st-logo-path" value="<?= htmlspecialchars($story['company_logo']) ?>">
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-plus" data-action="add-story">＋ Add Success Story</button>
  </div>

  <!-- TAB 4 — LIFE @ DA360 -->
  <div class="tab-pane" id="tab-pane-lifevideo">
    <div class="info-card">
      <div class="info-card-title">🎥 Life @ DA360 Videos (4 fixed slots)</div>
      <?php foreach ($lvMap as $slot => $lv): ?>
      <div style="border:1.5px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:14px;" data-lv-slot="<?= $slot ?>" data-lv-id="<?= (int)$lv['id'] ?>">
        <div class="slot-label">Slide <?= $slot ?></div>
        <div class="field-row"><label>Title</label><input type="text" class="lv-title" value="<?= htmlspecialchars($lv['title']) ?>" placeholder="e.g. AI IN DIGITAL MARKETING BOOTCAMP"></div>
        <div class="field-row"><label>Video URL</label><input type="url" class="lv-video-url" value="<?= htmlspecialchars($lv['video_url']) ?>" placeholder="https://asset.digitalacademy360.com/..."></div>
        <div class="field-row">
          <label>Thumbnail Image</label>
          <?php if ($lv['img']): ?><img src="/da360-admin<?= htmlspecialchars($lv['img']) ?>" class="img-preview-lg lv-img-preview" alt="thumb"><?php else: ?><img src="" class="img-preview-lg lv-img-preview" style="display:none;" alt="thumb"><?php endif; ?>
          <input type="file" class="lv-img-file" accept="image/*" style="margin-top:4px;">
          <input type="hidden" class="lv-img-path" value="<?= htmlspecialchars($lv['img']) ?>">
        </div>
        <button class="btn btn-success btn-sm" data-action="save-lifevideo">💾 Save Slide <?= $slot ?></button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- TAB 5 — GUEST FACULTY -->
  <div class="tab-pane" id="tab-pane-guestfaculty">
    <div id="guestfaculty-container">
      <?php foreach ($guestFacultyList as $gfi => $gf): ?>
      <div class="item-block" data-item-id="<?= (int)$gf['id'] ?>" data-item-index="<?= $gfi ?>" data-sort="<?= (int)$gf['sort_order'] ?>">
        <div class="item-header" data-toggle-item="gf-<?= $gfi ?>">
          <div style="display:flex;align-items:center;flex:1;gap:10px;">
            <?php if ($gf['profile_image']): ?><img src="/da360-admin<?= htmlspecialchars($gf['profile_image']) ?>" style="width:34px;height:34px;border-radius:50%;object-fit:cover;border:1.5px solid #e2e8f0;" alt=""><?php endif; ?>
            <div class="item-num"><?= $gfi+1 ?></div>
            <span class="item-title-text"><?= htmlspecialchars($gf['name']) ?></span>
            <span style="font-size:12px;color:#94a3b8;"><?= htmlspecialchars($gf['title']) ?></span>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-success btn-sm" data-action="save-guestfaculty">💾 Save</button>
            <button class="btn btn-danger btn-sm"  data-action="delete-guestfaculty">🗑</button>
          </div>
        </div>
        <div class="item-body open" id="item-body-gf-<?= $gfi ?>">
          <div class="field-2col">
            <div>
              <label>Profile Image (Card)</label>
              <?php if ($gf['profile_image']): ?><img src="/da360-admin<?= htmlspecialchars($gf['profile_image']) ?>" class="img-preview gf-profile-preview" alt="profile"><?php else: ?><img src="" class="img-preview gf-profile-preview" style="display:none;" alt="profile"><?php endif; ?>
              <input type="file" class="gf-profile-file" accept="image/*" style="margin-top:4px;">
              <input type="hidden" class="gf-profile-path" value="<?= htmlspecialchars($gf['profile_image']) ?>">
            </div>
            <div>
              <label>Profile Image (Popup)</label>
              <?php if ($gf['profile_image_popup']): ?><img src="/da360-admin<?= htmlspecialchars($gf['profile_image_popup']) ?>" class="img-preview gf-popup-preview" alt="popup"><?php else: ?><img src="" class="img-preview gf-popup-preview" style="display:none;" alt="popup"><?php endif; ?>
              <input type="file" class="gf-popup-file" accept="image/*" style="margin-top:4px;">
              <input type="hidden" class="gf-popup-path" value="<?= htmlspecialchars($gf['profile_image_popup']) ?>">
            </div>
          </div>
          <div class="field-2col">
            <div class="field-row"><label>Name</label><input type="text" class="gf-name" value="<?= htmlspecialchars($gf['name']) ?>" placeholder="e.g. Rajesh Choudhury"></div>
            <div class="field-row"><label>Name Popup <span style="font-weight:400;color:#94a3b8;">(use &lt;br/&gt; for line break)</span></label><input type="text" class="gf-name-popup" value="<?= htmlspecialchars($gf['name_popup']) ?>" placeholder="e.g. Rajesh &lt;br/&gt; Choudhury"></div>
          </div>
          <div class="field-row"><label>Title / Role</label><input type="text" class="gf-title" value="<?= htmlspecialchars($gf['title']) ?>" placeholder="e.g. DGM Digital Marketing at Purvankara"></div>
          <div class="field-row"><label>Expertise</label><textarea class="gf-expertise" rows="2"><?= htmlspecialchars($gf['expertise']) ?></textarea></div>
          <div class="field-row"><label>Description</label><textarea class="gf-description" rows="3"><?= htmlspecialchars($gf['description']) ?></textarea></div>
          <div class="field-row">
            <label>Company Logos</label>
            <div class="gf-logos-list" id="gf-logos-<?= $gfi ?>">
              <?php foreach ($gf['logos'] as $lgi => $logo): ?>
              <div class="gf-logo-row" data-logo-id="<?= (int)$logo['id'] ?>" data-sort="<?= $lgi+1 ?>" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <?php if ($logo['logo']): ?><img src="/da360-admin<?= htmlspecialchars($logo['logo']) ?>" class="gf-logo-thumb" style="width:40px;height:40px;object-fit:contain;border:1px solid #e2e8f0;border-radius:4px;" alt="logo"><?php else: ?><img src="" class="gf-logo-thumb" style="width:40px;height:40px;object-fit:contain;border:1px solid #e2e8f0;border-radius:4px;display:none;" alt="logo"><?php endif; ?>
                <input type="hidden" class="gf-logo-path" value="<?= htmlspecialchars($logo['logo']) ?>">
                <input type="file" class="gf-logo-file" accept="image/*">
                <button class="btn btn-danger btn-sm" data-action="delete-gf-logo">✕</button>
              </div>
              <?php endforeach; ?>
            </div>
            <button class="btn btn-plus btn-sm" data-action="add-gf-logo" data-gf-index="<?= $gfi ?>" style="margin-top:4px;">＋ Add Logo</button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-plus" data-action="add-guestfaculty">＋ Add Guest Faculty</button>
  </div>

  <!-- TAB 6 — FACULTY -->
  <div class="tab-pane" id="tab-pane-faculty">
    <div id="faculty-container">
      <?php foreach ($faculty as $fi => $fac): ?>
      <div class="item-block" data-item-id="<?= (int)$fac['id'] ?>" data-item-index="<?= $fi ?>" data-sort="<?= (int)$fac['sort_order'] ?>">
        <div class="item-header" data-toggle-item="fac-<?= $fi ?>">
          <div style="display:flex;align-items:center;flex:1;">
            <div class="item-num"><?= $fi+1 ?></div>
            <span class="item-title-text"><?= htmlspecialchars($fac['name']) ?> — <?= htmlspecialchars($fac['tab']) ?></span>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-success btn-sm" data-action="save-faculty">💾 Save</button>
            <button class="btn btn-danger btn-sm"  data-action="delete-faculty">🗑</button>
          </div>
        </div>
        <div class="item-body open" id="item-body-fac-<?= $fi ?>">
          <div class="field-3col">
            <div class="field-row"><label>Name</label><input type="text" class="fac-name" value="<?= htmlspecialchars($fac['name']) ?>" placeholder="e.g. Deepak"></div>
            <div class="field-row"><label>Role</label><input type="text" class="fac-role" value="<?= htmlspecialchars($fac['role']) ?>" placeholder="e.g. Head of Academics"></div>
            <div class="field-row"><label>Experience</label><input type="text" class="fac-experience" value="<?= htmlspecialchars($fac['experience']) ?>" placeholder="e.g. 12 years Experience"></div>
          </div>
          <div class="field-2col">
            <div class="field-row"><label>LinkedIn URL</label><input type="url" class="fac-linkedin" value="<?= htmlspecialchars($fac['linkedin_link']) ?>" placeholder="https://linkedin.com/in/..."></div>
            <div class="field-row">
              <label>Tab Group</label>
              <select class="fac-tab">
                <?php foreach (['DA360 Faculty','Project Advisors','Placement / Support'] as $tabOpt): ?>
                  <option value="<?= htmlspecialchars($tabOpt) ?>" <?= $fac['tab'] === $tabOpt ? 'selected' : '' ?>><?= htmlspecialchars($tabOpt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="field-3col">
            <div class="field-row"><label>Text Color (hex)</label><input type="text" class="fac-text-color" value="<?= htmlspecialchars($fac['text_color']) ?>" placeholder="e.g. #FFF12D"></div>
            <div class="field-row">
              <label>Icon</label>
              <select class="fac-icon">
                <?php foreach (['star','sparkle','bolt'] as $iconOpt): ?>
                  <option value="<?= $iconOpt ?>" <?= $fac['icon'] === $iconOpt ? 'selected' : '' ?>><?= ucfirst($iconOpt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field-row">
              <label>Icon Position</label>
              <select class="fac-icon-position">
                <option value="" <?= !$fac['icon_position'] ? 'selected' : '' ?>>— none —</option>
                <option value="left"  <?= $fac['icon_position'] === 'left'  ? 'selected' : '' ?>>Left</option>
                <option value="right" <?= $fac['icon_position'] === 'right' ? 'selected' : '' ?>>Right</option>
              </select>
            </div>
          </div>
          <div class="field-2col">
            <div>
              <label>Profile Image</label>
              <?php if ($fac['img']): ?><img src="/da360-admin<?= htmlspecialchars($fac['img']) ?>" class="img-preview fac-img-preview" alt="photo"><?php else: ?><img src="" class="img-preview fac-img-preview" style="display:none;" alt="photo"><?php endif; ?>
              <input type="file" class="fac-img-file" accept="image/*" style="margin-top:4px;">
              <input type="hidden" class="fac-img-path" value="<?= htmlspecialchars($fac['img']) ?>">
            </div>
            <div>
              <label>Icon Image (optional)</label>
              <?php if ($fac['icon_img']): ?><img src="/da360-admin<?= htmlspecialchars($fac['icon_img']) ?>" class="img-preview fac-iconimg-preview" alt="icon"><?php else: ?><img src="" class="img-preview fac-iconimg-preview" style="display:none;" alt="icon"><?php endif; ?>
              <input type="file" class="fac-iconimg-file" accept="image/*" style="margin-top:4px;">
              <input type="hidden" class="fac-iconimg-path" value="<?= htmlspecialchars($fac['icon_img']) ?>">
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-plus" data-action="add-faculty">＋ Add Faculty Member</button>
  </div>

  <!-- TAB 7 — BANNERS -->
  <div class="tab-pane" id="tab-pane-banners">
    <div id="banners-container">
      <?php foreach ($banners as $bi => $banner): ?>
      <div class="item-block" data-item-id="<?= (int)$banner['id'] ?>" data-item-index="<?= $bi ?>" data-sort="<?= (int)$banner['sort_order'] ?>">
        <div class="item-header">
          <div style="display:flex;align-items:center;flex:1;">
            <div class="item-num"><?= $bi+1 ?></div>
            <span class="item-title-text">Banner <?= $bi+1 ?></span>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-success btn-sm" data-action="save-banner">💾 Save</button>
            <button class="btn btn-danger btn-sm"  data-action="delete-banner">🗑</button>
          </div>
        </div>
        <div class="item-body open" style="display:block;">
          <?php if ($banner['image_url']): ?><img src="/da360-admin<?= htmlspecialchars($banner['image_url']) ?>" class="img-preview-lg bn-img-preview" alt="banner"><?php else: ?><img src="" class="img-preview-lg bn-img-preview" style="display:none;" alt="banner"><?php endif; ?>
          <input type="file" class="bn-img-file" accept="image/*" style="margin-top:4px;">
          <input type="hidden" class="bn-img-path" value="<?= htmlspecialchars($banner['image_url']) ?>">
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-plus" data-action="add-banner">＋ Add Banner</button>
  </div>

  <!-- TAB 8 — BLOG POSTS -->
  <div class="tab-pane" id="tab-pane-blogs">
    <div id="blogs-container">
      <?php foreach ($blogs as $bli => $blog): ?>
      <div class="item-block" data-item-id="<?= (int)$blog['id'] ?>" data-item-index="<?= $bli ?>" data-sort="<?= (int)$blog['sort_order'] ?>">
        <div class="item-header" data-toggle-item="blog-<?= $bli ?>">
          <div style="display:flex;align-items:center;flex:1;">
            <div class="item-num"><?= $bli+1 ?></div>
            <span class="item-title-text"><?= htmlspecialchars($blog['title'] ?: 'Blog ' . ($bli+1)) ?></span>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-success btn-sm" data-action="save-blog">💾 Save</button>
            <button class="btn btn-danger btn-sm"  data-action="delete-blog">🗑</button>
          </div>
        </div>
        <div class="item-body open" id="item-body-blog-<?= $bli ?>">
          <div class="field-2col">
            <div class="field-row"><label>Category</label><input type="text" class="bl-category" value="<?= htmlspecialchars($blog['category']) ?>" placeholder="e.g. Digital Marketing"></div>
            <div class="field-row"><label>Title</label><input type="text" class="bl-title" value="<?= htmlspecialchars($blog['title']) ?>" placeholder="Blog post title"></div>
          </div>
          <div class="field-row"><label>Link (full URL)</label><input type="url" class="bl-link" value="<?= htmlspecialchars($blog['link']) ?>" placeholder="https://blog.digitalacademy360.com/..."></div>
          <div>
            <label>Blog Image</label>
            <?php if ($blog['img_src']): ?><img src="/da360-admin<?= htmlspecialchars($blog['img_src']) ?>" class="img-preview-lg bl-img-preview" alt="blog"><?php else: ?><img src="" class="img-preview-lg bl-img-preview" style="display:none;" alt="blog"><?php endif; ?>
            <input type="file" class="bl-img-file" accept="image/*" style="margin-top:4px;">
            <input type="hidden" class="bl-img-path" value="<?= htmlspecialchars($blog['img_src']) ?>">
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-plus" data-action="add-blog">＋ Add Blog Post</button>
  </div>

  <!-- TAB 9 — MEDIA & AWARDS -->
  <div class="tab-pane" id="tab-pane-media">
    <div id="media-container">
      <?php foreach ($media as $mi => $med): ?>
      <div class="item-block" data-item-id="<?= (int)$med['id'] ?>" data-item-index="<?= $mi ?>" data-sort="<?= (int)$med['sort_order'] ?>">
        <div class="item-header">
          <div style="display:flex;align-items:center;flex:1;">
            <div class="item-num"><?= $mi+1 ?></div>
            <span class="item-title-text"><?= htmlspecialchars($med['alt'] ?: 'Media ' . ($mi+1)) ?></span>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-success btn-sm" data-action="save-media">💾 Save</button>
            <button class="btn btn-danger btn-sm"  data-action="delete-media">🗑</button>
          </div>
        </div>
        <div class="item-body open" style="display:block;">
          <div class="field-row"><label>Alt Text</label><input type="text" class="md-alt" value="<?= htmlspecialchars($med['alt']) ?>" placeholder="e.g. The Times of India"></div>
          <?php if ($med['src']): ?><img src="/da360-admin<?= htmlspecialchars($med['src']) ?>" class="img-preview md-img-preview" alt="media"><?php else: ?><img src="" class="img-preview md-img-preview" style="display:none;" alt="media"><?php endif; ?>
          <input type="file" class="md-img-file" accept="image/*" style="margin-top:4px;">
          <input type="hidden" class="md-img-path" value="<?= htmlspecialchars($med['src']) ?>">
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-plus" data-action="add-media">＋ Add Media Logo</button>
  </div>

</div><!-- /gw-root -->
<?php
        $html = ob_get_clean();

        $js = <<<'GWJS'
(function () {
  var root = document.getElementById('gw-root');
  if (!root) return;

  // ── Tab switching ──────────────────────────────────────────────────────────
  root.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tab = this.dataset.tab;
      root.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
      root.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
      this.classList.add('active');
      var pane = root.querySelector('#tab-pane-' + tab);
      if (pane) pane.classList.add('active');
    });
  });

  // ── Item collapse toggle ───────────────────────────────────────────────────
  root.addEventListener('click', function (e) {
    var hdr = e.target.closest('[data-toggle-item]');
    if (hdr && !e.target.closest('button')) {
      var key  = hdr.dataset.toggleItem;
      var body = root.querySelector('#item-body-' + key);
      if (body) body.classList.toggle('open');
    }
  });

  // ── Image file → preview + hidden path ────────────────────────────────────
  function wireFileInput(block, fileClass, previewClass, pathClass) {
    var fileInput = block.querySelector(fileClass);
    if (!fileInput) return;
    fileInput.addEventListener('change', function () {
      var file = this.files[0];
      if (!file) return;
      var fd = new FormData();
      fd.append('file', file);
      fd.append('folder', 'global');
      fetch('/da360-admin/globalwise_api.php?action=upload_image', { method:'POST', body:fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.success && d.path) {
            block.querySelector(pathClass).value = d.path;
            var prev = block.querySelector(previewClass);
            if (prev) { prev.src = '/da360-admin' + d.path; prev.style.display = ''; }
          }
        });
    });
  }

  // ── Guest faculty logo upload helper ──────────────────────────────────────
  function wireGfLogoFile(row) {
    var fileInput = row.querySelector('.gf-logo-file');
    if (!fileInput) return;
    fileInput.addEventListener('change', function () {
      var file = this.files[0]; if (!file) return;
      var fd = new FormData();
      fd.append('file', file); fd.append('folder', 'guestfaculty');
      fetch('/da360-admin/globalwise_api.php?action=upload_image', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (d.success && d.path) {
            row.querySelector('.gf-logo-path').value = d.path;
            var img = row.querySelector('.gf-logo-thumb');
            img.src = '/da360-admin' + d.path; img.style.display = '';
          }
        });
    });
  }

  function wireGfLogoFiles(block) {
    block.querySelectorAll('.gf-logo-row').forEach(function(row){ wireGfLogoFile(row); });
  }

  // ── API helper ────────────────────────────────────────────────────────────
  function apiPost(params, btn, onSuccess) {
    var fd = new FormData();
    Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
    var origText = btn ? btn.innerHTML : '';
    if (btn) { btn.classList.add('saving'); btn.innerHTML = '⏳'; }
    fetch('/da360-admin/globalwise_api.php?action=' + params._action, { method:'POST', body:fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (btn) {
          btn.classList.remove('saving');
          btn.classList.add(d.success ? 'saved' : 'errored');
          btn.innerHTML = d.success ? '✅' : '❌';
          setTimeout(function () { btn.classList.remove('saved','errored'); btn.innerHTML = origText; }, 1800);
        }
        if (d.success && onSuccess) onSuccess(d);
      })
      .catch(function () {
        if (btn) { btn.classList.remove('saving'); btn.innerHTML = '❌'; setTimeout(function(){ btn.innerHTML = origText; }, 1800); }
      });
  }

  // ══════════════════════════════════════════════════════════════════════════
  // DELEGATED CLICK HANDLER
  // ══════════════════════════════════════════════════════════════════════════
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.dataset.action;

    // ── HERO COUNT ────────────────────────────────────────────────────────
    if (action === 'save-herocount') {
      var slotEl = btn.closest('[data-hc-slot]');
      apiPost({ _action:'save_herocount', slot:slotEl.dataset.hcSlot, count_value:slotEl.querySelector('.hc-count-value').value, count_label:slotEl.querySelector('.hc-count-label').value }, btn, function (d) { slotEl.dataset.hcId = d.id || slotEl.dataset.hcId; });
    }

    // ── VIDEO URL ─────────────────────────────────────────────────────────
    if (action === 'save-video') {
      apiPost({ _action:'save_video', video_url: root.querySelector('#gw-video-url').value }, btn);
    }

    // ── SUCCESS STORY SAVE ────────────────────────────────────────────────
    if (action === 'save-story') {
      var block = btn.closest('.item-block');
      apiPost({ _action:'save_story', story_id:block.dataset.itemId||0, sort_order:block.dataset.sort||1, name:block.querySelector('.st-name').value, company_name:block.querySelector('.st-company-name').value, package_lpa:block.querySelector('.st-package-lpa').value, previous_role:block.querySelector('.st-previous-role').value, new_role:block.querySelector('.st-new-role').value, profile_image:block.querySelector('.st-profile-path').value, company_logo:block.querySelector('.st-logo-path').value }, btn, function (d) {
        if (d.story_id) block.dataset.itemId = d.story_id;
        var num = block.querySelector('.item-title-text');
        if (num) num.textContent = block.querySelector('.st-name').value + ' — ' + block.querySelector('.st-company-name').value;
      });
    }

    if (action === 'delete-story') {
      var block = btn.closest('.item-block');
      if (!confirm('Delete this success story?')) return;
      apiPost({ _action:'delete_story', story_id: block.dataset.itemId }, btn, function () { block.remove(); });
    }

    if (action === 'add-story') {
      var container = root.querySelector('#stories-container');
      var idx = container.querySelectorAll('.item-block').length;
      var div = document.createElement('div');
      div.className = 'item-block'; div.dataset.itemId = '0'; div.dataset.itemIndex = idx; div.dataset.sort = idx+1;
      div.innerHTML = '<div class="item-header" data-toggle-item="story-new-'+idx+'"><div style="display:flex;align-items:center;flex:1;"><div class="item-num">'+(idx+1)+'</div><span class="item-title-text">New Story</span></div><div style="display:flex;gap:8px;"><button class="btn btn-success btn-sm" data-action="save-story">💾 Save</button><button class="btn btn-danger btn-sm" data-action="delete-story">🗑</button></div></div>'
        +'<div class="item-body open" id="item-body-story-new-'+idx+'">'
        +'<div class="field-3col"><div class="field-row"><label>Name</label><input type="text" class="st-name" placeholder="Student name"></div><div class="field-row"><label>Company Name</label><input type="text" class="st-company-name" placeholder="e.g. Jeet"></div><div class="field-row"><label>Package LPA</label><input type="text" class="st-package-lpa" placeholder="e.g. 5 LPA"></div></div>'
        +'<div class="field-2col"><div class="field-row"><label>Previous Role</label><input type="text" class="st-previous-role" placeholder="e.g. SEO Analyst"></div><div class="field-row"><label>New Role</label><input type="text" class="st-new-role" placeholder="e.g. Fresher"></div></div>'
        +'<div class="field-2col"><div><label>Profile Image</label><img src="" class="img-preview st-profile-preview" style="display:none;" alt="profile"><input type="file" class="st-profile-file" accept="image/*" style="margin-top:4px;"><input type="hidden" class="st-profile-path" value=""></div>'
        +'<div><label>Company Logo</label><img src="" class="img-preview st-logo-preview" style="display:none;" alt="logo"><input type="file" class="st-logo-file" accept="image/*" style="margin-top:4px;"><input type="hidden" class="st-logo-path" value=""></div></div>'
        +'</div>';
      container.appendChild(div);
      wireFileInput(div, '.st-profile-file', '.st-profile-preview', '.st-profile-path');
      wireFileInput(div, '.st-logo-file', '.st-logo-preview', '.st-logo-path');
    }

    // ── LIFE VIDEO ────────────────────────────────────────────────────────
    if (action === 'save-lifevideo') {
      var slotEl = btn.closest('[data-lv-slot]');
      apiPost({ _action:'save_lifevideo', slot:slotEl.dataset.lvSlot, title:slotEl.querySelector('.lv-title').value, img:slotEl.querySelector('.lv-img-path').value, video_url:slotEl.querySelector('.lv-video-url').value }, btn, function (d) { slotEl.dataset.lvId = d.id || slotEl.dataset.lvId; });
    }

    // ── GUEST FACULTY ─────────────────────────────────────────────────────
    if (action === 'save-guestfaculty') {
      var block = btn.closest('.item-block');
      var logos = [];
      block.querySelectorAll('.gf-logo-path').forEach(function (inp, i) {
        var v = inp.value; if (v) logos.push({ sort_order: i+1, logo: v });
      });
      apiPost({
        _action:            'save_guest_faculty',
        faculty_id:          block.dataset.itemId || 0,
        sort_order:          block.dataset.sort || 1,
        name:                block.querySelector('.gf-name').value,
        name_popup:          block.querySelector('.gf-name-popup').value,
        title:               block.querySelector('.gf-title').value,
        expertise:           block.querySelector('.gf-expertise').value,
        description:         block.querySelector('.gf-description').value,
        profile_image:       block.querySelector('.gf-profile-path').value,
        profile_image_popup: block.querySelector('.gf-popup-path').value,
        logos:               JSON.stringify(logos),
      }, btn, function (d) {
        if (d.faculty_id) block.dataset.itemId = d.faculty_id;
        var t = block.querySelector('.item-title-text');
        if (t) t.textContent = block.querySelector('.gf-name').value;
      });
    }

    if (action === 'delete-guestfaculty') {
      var block = btn.closest('.item-block');
      if (!confirm('Delete this guest faculty member?')) return;
      apiPost({ _action:'delete_guest_faculty', faculty_id: block.dataset.itemId }, btn, function () { block.remove(); });
    }

    if (action === 'add-guestfaculty') {
      var container = root.querySelector('#guestfaculty-container');
      var idx = container.querySelectorAll('.item-block').length;
      var div = document.createElement('div');
      div.className = 'item-block'; div.dataset.itemId = '0'; div.dataset.itemIndex = idx; div.dataset.sort = idx+1;
      div.innerHTML =
        '<div class="item-header" data-toggle-item="gf-new-'+idx+'">'
          +'<div style="display:flex;align-items:center;flex:1;gap:10px;"><div class="item-num">'+(idx+1)+'</div><span class="item-title-text">New Guest Faculty</span></div>'
          +'<div style="display:flex;gap:8px;"><button class="btn btn-success btn-sm" data-action="save-guestfaculty">💾 Save</button><button class="btn btn-danger btn-sm" data-action="delete-guestfaculty">🗑</button></div>'
        +'</div>'
        +'<div class="item-body open" id="item-body-gf-new-'+idx+'">'
          +'<div class="field-2col">'
            +'<div><label>Profile Image (Card)</label><img src="" class="img-preview gf-profile-preview" style="display:none;" alt="profile"><input type="file" class="gf-profile-file" accept="image/*" style="margin-top:4px;"><input type="hidden" class="gf-profile-path" value=""></div>'
            +'<div><label>Profile Image (Popup)</label><img src="" class="img-preview gf-popup-preview" style="display:none;" alt="popup"><input type="file" class="gf-popup-file" accept="image/*" style="margin-top:4px;"><input type="hidden" class="gf-popup-path" value=""></div>'
          +'</div>'
          +'<div class="field-2col"><div class="field-row"><label>Name</label><input type="text" class="gf-name" placeholder="e.g. Rajesh Choudhury"></div><div class="field-row"><label>Name Popup</label><input type="text" class="gf-name-popup" placeholder="e.g. Rajesh &lt;br/&gt; Choudhury"></div></div>'
          +'<div class="field-row"><label>Title / Role</label><input type="text" class="gf-title" placeholder="e.g. DGM Digital Marketing at Purvankara"></div>'
          +'<div class="field-row"><label>Expertise</label><textarea class="gf-expertise" rows="2"></textarea></div>'
          +'<div class="field-row"><label>Description</label><textarea class="gf-description" rows="3"></textarea></div>'
          +'<div class="field-row"><label>Company Logos</label><div class="gf-logos-list" id="gf-logos-'+idx+'"></div><button class="btn btn-plus btn-sm" data-action="add-gf-logo" data-gf-index="'+idx+'" style="margin-top:4px;">＋ Add Logo</button></div>'
        +'</div>';
      container.appendChild(div);
      wireFileInput(div, '.gf-profile-file', '.gf-profile-preview', '.gf-profile-path');
      wireFileInput(div, '.gf-popup-file',   '.gf-popup-preview',   '.gf-popup-path');
      wireGfLogoFiles(div);
    }

    if (action === 'add-gf-logo') {
      var gfi  = btn.dataset.gfIndex;
      var list = root.querySelector('#gf-logos-' + gfi);
      var sort = list ? list.querySelectorAll('.gf-logo-row').length + 1 : 1;
      var row  = document.createElement('div');
      row.className = 'gf-logo-row'; row.dataset.logoId = '0'; row.dataset.sort = sort;
      row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;';
      row.innerHTML = '<img src="" class="gf-logo-thumb" style="width:40px;height:40px;object-fit:contain;border:1px solid #e2e8f0;border-radius:4px;display:none;" alt="logo"><input type="hidden" class="gf-logo-path" value=""><input type="file" class="gf-logo-file" accept="image/*"><button class="btn btn-danger btn-sm" data-action="delete-gf-logo">✕</button>';
      if (list) list.appendChild(row);
      wireGfLogoFile(row);
    }

    if (action === 'delete-gf-logo') {
      btn.closest('.gf-logo-row').remove();
    }

    // ── FACULTY SAVE ──────────────────────────────────────────────────────
    if (action === 'save-faculty') {
      var block = btn.closest('.item-block');
      apiPost({ _action:'save_faculty', faculty_id:block.dataset.itemId||0, sort_order:block.dataset.sort||1, name:block.querySelector('.fac-name').value, role:block.querySelector('.fac-role').value, experience:block.querySelector('.fac-experience').value, linkedin_link:block.querySelector('.fac-linkedin').value, tab:block.querySelector('.fac-tab').value, text_color:block.querySelector('.fac-text-color').value, icon:block.querySelector('.fac-icon').value, icon_position:block.querySelector('.fac-icon-position').value, img:block.querySelector('.fac-img-path').value, icon_img:block.querySelector('.fac-iconimg-path').value }, btn, function (d) {
        if (d.faculty_id) block.dataset.itemId = d.faculty_id;
        var t = block.querySelector('.item-title-text');
        if (t) t.textContent = block.querySelector('.fac-name').value + ' — ' + block.querySelector('.fac-tab').value;
      });
    }

    if (action === 'delete-faculty') {
      var block = btn.closest('.item-block');
      if (!confirm('Delete this faculty member?')) return;
      apiPost({ _action:'delete_faculty', faculty_id: block.dataset.itemId }, btn, function () { block.remove(); });
    }

    if (action === 'add-faculty') {
      var container = root.querySelector('#faculty-container');
      var idx = container.querySelectorAll('.item-block').length;
      var tabOpts = ['DA360 Faculty','Project Advisors','Placement / Support'].map(function(t){return '<option value="'+t+'">'+t+'</option>';}).join('');
      var iconOpts = ['star','sparkle','bolt'].map(function(i){return '<option value="'+i+'">'+i.charAt(0).toUpperCase()+i.slice(1)+'</option>';}).join('');
      var div = document.createElement('div');
      div.className = 'item-block'; div.dataset.itemId = '0'; div.dataset.itemIndex = idx; div.dataset.sort = idx+1;
      div.innerHTML = '<div class="item-header" data-toggle-item="fac-new-'+idx+'"><div style="display:flex;align-items:center;flex:1;"><div class="item-num">'+(idx+1)+'</div><span class="item-title-text">New Faculty</span></div><div style="display:flex;gap:8px;"><button class="btn btn-success btn-sm" data-action="save-faculty">💾 Save</button><button class="btn btn-danger btn-sm" data-action="delete-faculty">🗑</button></div></div>'
        +'<div class="item-body open" id="item-body-fac-new-'+idx+'">'
        +'<div class="field-3col"><div class="field-row"><label>Name</label><input type="text" class="fac-name" placeholder="Name"></div><div class="field-row"><label>Role</label><input type="text" class="fac-role" placeholder="Role"></div><div class="field-row"><label>Experience</label><input type="text" class="fac-experience" placeholder="e.g. 5 years"></div></div>'
        +'<div class="field-2col"><div class="field-row"><label>LinkedIn URL</label><input type="url" class="fac-linkedin" placeholder="https://linkedin.com/in/..."></div><div class="field-row"><label>Tab Group</label><select class="fac-tab">'+tabOpts+'</select></div></div>'
        +'<div class="field-3col"><div class="field-row"><label>Text Color</label><input type="text" class="fac-text-color" placeholder="#FFFFFF"></div><div class="field-row"><label>Icon</label><select class="fac-icon">'+iconOpts+'</select></div><div class="field-row"><label>Icon Position</label><select class="fac-icon-position"><option value="">— none —</option><option value="left">Left</option><option value="right">Right</option></select></div></div>'
        +'<div class="field-2col"><div><label>Profile Image</label><img src="" class="img-preview fac-img-preview" style="display:none;" alt="photo"><input type="file" class="fac-img-file" accept="image/*" style="margin-top:4px;"><input type="hidden" class="fac-img-path" value=""></div>'
        +'<div><label>Icon Image (optional)</label><img src="" class="img-preview fac-iconimg-preview" style="display:none;" alt="icon"><input type="file" class="fac-iconimg-file" accept="image/*" style="margin-top:4px;"><input type="hidden" class="fac-iconimg-path" value=""></div></div>'
        +'</div>';
      container.appendChild(div);
      wireFileInput(div, '.fac-img-file', '.fac-img-preview', '.fac-img-path');
      wireFileInput(div, '.fac-iconimg-file', '.fac-iconimg-preview', '.fac-iconimg-path');
    }

    // ── BANNER ────────────────────────────────────────────────────────────
    if (action === 'save-banner') {
      var block = btn.closest('.item-block');
      apiPost({ _action:'save_banner', banner_id:block.dataset.itemId||0, sort_order:block.dataset.sort||1, image_url:block.querySelector('.bn-img-path').value }, btn, function (d) { if (d.banner_id) block.dataset.itemId = d.banner_id; });
    }
    if (action === 'delete-banner') {
      var block = btn.closest('.item-block');
      if (!confirm('Delete this banner?')) return;
      apiPost({ _action:'delete_banner', banner_id: block.dataset.itemId }, btn, function () { block.remove(); });
    }
    if (action === 'add-banner') {
      var container = root.querySelector('#banners-container');
      var idx = container.querySelectorAll('.item-block').length;
      var div = document.createElement('div');
      div.className = 'item-block'; div.dataset.itemId = '0'; div.dataset.itemIndex = idx; div.dataset.sort = idx+1;
      div.innerHTML = '<div class="item-header"><div style="display:flex;align-items:center;flex:1;"><div class="item-num">'+(idx+1)+'</div><span class="item-title-text">Banner '+(idx+1)+'</span></div><div style="display:flex;gap:8px;"><button class="btn btn-success btn-sm" data-action="save-banner">💾 Save</button><button class="btn btn-danger btn-sm" data-action="delete-banner">🗑</button></div></div>'
        +'<div class="item-body open" style="display:block;padding:16px;"><img src="" class="img-preview-lg bn-img-preview" style="display:none;" alt="banner"><input type="file" class="bn-img-file" accept="image/*" style="margin-top:4px;"><input type="hidden" class="bn-img-path" value=""></div>';
      container.appendChild(div);
      wireFileInput(div, '.bn-img-file', '.bn-img-preview', '.bn-img-path');
    }

    // ── BLOG ──────────────────────────────────────────────────────────────
    if (action === 'save-blog') {
      var block = btn.closest('.item-block');
      apiPost({ _action:'save_blog', blog_id:block.dataset.itemId||0, sort_order:block.dataset.sort||1, category:block.querySelector('.bl-category').value, title:block.querySelector('.bl-title').value, link:block.querySelector('.bl-link').value, img_src:block.querySelector('.bl-img-path').value }, btn, function (d) {
        if (d.blog_id) block.dataset.itemId = d.blog_id;
        var t = block.querySelector('.item-title-text');
        if (t) t.textContent = block.querySelector('.bl-title').value || 'Blog';
      });
    }
    if (action === 'delete-blog') {
      var block = btn.closest('.item-block');
      if (!confirm('Delete this blog post?')) return;
      apiPost({ _action:'delete_blog', blog_id: block.dataset.itemId }, btn, function () { block.remove(); });
    }
    if (action === 'add-blog') {
      var container = root.querySelector('#blogs-container');
      var idx = container.querySelectorAll('.item-block').length;
      var div = document.createElement('div');
      div.className = 'item-block'; div.dataset.itemId = '0'; div.dataset.itemIndex = idx; div.dataset.sort = idx+1;
      div.innerHTML = '<div class="item-header" data-toggle-item="blog-new-'+idx+'"><div style="display:flex;align-items:center;flex:1;"><div class="item-num">'+(idx+1)+'</div><span class="item-title-text">New Blog Post</span></div><div style="display:flex;gap:8px;"><button class="btn btn-success btn-sm" data-action="save-blog">💾 Save</button><button class="btn btn-danger btn-sm" data-action="delete-blog">🗑</button></div></div>'
        +'<div class="item-body open" id="item-body-blog-new-'+idx+'">'
        +'<div class="field-2col"><div class="field-row"><label>Category</label><input type="text" class="bl-category" placeholder="e.g. Digital Marketing"></div><div class="field-row"><label>Title</label><input type="text" class="bl-title" placeholder="Blog post title"></div></div>'
        +'<div class="field-row"><label>Link (full URL)</label><input type="url" class="bl-link" placeholder="https://blog.digitalacademy360.com/..."></div>'
        +'<div><label>Blog Image</label><img src="" class="img-preview-lg bl-img-preview" style="display:none;" alt="blog"><input type="file" class="bl-img-file" accept="image/*" style="margin-top:4px;"><input type="hidden" class="bl-img-path" value=""></div>'
        +'</div>';
      container.appendChild(div);
      wireFileInput(div, '.bl-img-file', '.bl-img-preview', '.bl-img-path');
    }

    // ── MEDIA ─────────────────────────────────────────────────────────────
    if (action === 'save-media') {
      var block = btn.closest('.item-block');
      apiPost({ _action:'save_media', media_id:block.dataset.itemId||0, sort_order:block.dataset.sort||1, alt:block.querySelector('.md-alt').value, src:block.querySelector('.md-img-path').value }, btn, function (d) {
        if (d.media_id) block.dataset.itemId = d.media_id;
        var t = block.querySelector('.item-title-text');
        if (t) t.textContent = block.querySelector('.md-alt').value || 'Media';
      });
    }
    if (action === 'delete-media') {
      var block = btn.closest('.item-block');
      if (!confirm('Delete this media logo?')) return;
      apiPost({ _action:'delete_media', media_id: block.dataset.itemId }, btn, function () { block.remove(); });
    }
    if (action === 'add-media') {
      var container = root.querySelector('#media-container');
      var idx = container.querySelectorAll('.item-block').length;
      var div = document.createElement('div');
      div.className = 'item-block'; div.dataset.itemId = '0'; div.dataset.itemIndex = idx; div.dataset.sort = idx+1;
      div.innerHTML = '<div class="item-header"><div style="display:flex;align-items:center;flex:1;"><div class="item-num">'+(idx+1)+'</div><span class="item-title-text">New Media Logo</span></div><div style="display:flex;gap:8px;"><button class="btn btn-success btn-sm" data-action="save-media">💾 Save</button><button class="btn btn-danger btn-sm" data-action="delete-media">🗑</button></div></div>'
        +'<div class="item-body open" style="display:block;padding:16px;"><div class="field-row"><label>Alt Text</label><input type="text" class="md-alt" placeholder="e.g. The Times of India"></div><img src="" class="img-preview md-img-preview" style="display:none;" alt="media"><input type="file" class="md-img-file" accept="image/*" style="margin-top:4px;"><input type="hidden" class="md-img-path" value=""></div>';
      container.appendChild(div);
      wireFileInput(div, '.md-img-file', '.md-img-preview', '.md-img-path');
    }

  }); // end delegated click

  // ── Wire file inputs for existing blocks on page load ─────────────────────
  root.querySelectorAll('[data-lv-slot]').forEach(function (b) {
    wireFileInput(b, '.lv-img-file', '.lv-img-preview', '.lv-img-path');
  });
  root.querySelectorAll('#stories-container .item-block').forEach(function (b) {
    wireFileInput(b, '.st-profile-file', '.st-profile-preview', '.st-profile-path');
    wireFileInput(b, '.st-logo-file', '.st-logo-preview', '.st-logo-path');
  });
  root.querySelectorAll('#guestfaculty-container .item-block').forEach(function (b) {
    wireFileInput(b, '.gf-profile-file', '.gf-profile-preview', '.gf-profile-path');
    wireFileInput(b, '.gf-popup-file',   '.gf-popup-preview',   '.gf-popup-path');
    wireGfLogoFiles(b);
  });
  root.querySelectorAll('#faculty-container .item-block').forEach(function (b) {
    wireFileInput(b, '.fac-img-file', '.fac-img-preview', '.fac-img-path');
    wireFileInput(b, '.fac-iconimg-file', '.fac-iconimg-preview', '.fac-iconimg-path');
  });
  root.querySelectorAll('#banners-container .item-block').forEach(function (b) {
    wireFileInput(b, '.bn-img-file', '.bn-img-preview', '.bn-img-path');
  });
  root.querySelectorAll('#blogs-container .item-block').forEach(function (b) {
    wireFileInput(b, '.bl-img-file', '.bl-img-preview', '.bl-img-path');
  });
  root.querySelectorAll('#media-container .item-block').forEach(function (b) {
    wireFileInput(b, '.md-img-file', '.md-img-preview', '.md-img-path');
  });

})();
GWJS;

        echo json_encode(['success' => true, 'html' => $html, 'js' => $js]);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // UPLOAD IMAGE
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $folder = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_POST['folder'] ?? 'global'));
        if ($folder === '') $folder = 'global';
        $path = handleImageUpload('file', $folder);
        echo $path
            ? json_encode(['success' => true,  'path'    => $path])
            : json_encode(['success' => false, 'message' => 'Upload failed or invalid file type']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE HERO COUNT
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_herocount' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $slot       = (int)($_POST['slot']        ?? 0);
        $countValue = trim($_POST['count_value']  ?? '');
        $countLabel = trim($_POST['count_label']  ?? '');
        $updatedBy  = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';
        if ($slot < 1 || $slot > 3) { echo json_encode(['success' => false, 'message' => 'Invalid slot']); exit; }
        $stmt = $db->prepare("INSERT INTO global_hero_counts (slot, count_value, count_label, updated_at, updated_by) VALUES (?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE count_value=VALUES(count_value), count_label=VALUES(count_label), updated_at=NOW(), updated_by=VALUES(updated_by)");
        $stmt->execute([$slot, $countValue, $countLabel, $updatedBy]);
        echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId() ?: null, 'message' => 'Hero count saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE VIDEO URL
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_video' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $videoUrl  = trim($_POST['video_url'] ?? '');
        $updatedBy = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';
        $stmt = $db->prepare("INSERT INTO global_video (id, video_url, updated_at, updated_by) VALUES (1, ?, NOW(), ?) ON DUPLICATE KEY UPDATE video_url=VALUES(video_url), updated_at=NOW(), updated_by=VALUES(updated_by)");
        $stmt->execute([$videoUrl, $updatedBy]);
        echo json_encode(['success' => true, 'message' => 'Video URL saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE / DELETE SUCCESS STORY
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_story' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $storyId=$id=(int)($_POST['story_id']??0); $sortOrder=(int)($_POST['sort_order']??1);
        $name=trim($_POST['name']??''); $companyName=trim($_POST['company_name']??'');
        $previousRole=trim($_POST['previous_role']??''); $packageLpa=trim($_POST['package_lpa']??'');
        $newRole=trim($_POST['new_role']??''); $profileImage=trim($_POST['profile_image']??'');
        $companyLogo=trim($_POST['company_logo']??'');
        $updatedBy=$_SESSION['da360_user']['name']??$_SESSION['da360_user']['username']??'unknown';
        if ($storyId) {
            $stmt=$db->prepare("UPDATE global_success_stories SET name=?,company_name=?,previous_role=?,package_lpa=?,new_role=?,profile_image=?,company_logo=?,sort_order=?,updated_at=NOW(),updated_by=? WHERE id=?");
            $stmt->execute([$name,$companyName,$previousRole,$packageLpa,$newRole,$profileImage,$companyLogo,$sortOrder,$updatedBy,$storyId]);
        } else {
            $stmt=$db->prepare("INSERT INTO global_success_stories (name,company_name,previous_role,package_lpa,new_role,profile_image,company_logo,sort_order,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,NOW(),?)");
            $stmt->execute([$name,$companyName,$previousRole,$packageLpa,$newRole,$profileImage,$companyLogo,$sortOrder,$updatedBy]);
            $storyId=(int)$db->lastInsertId();
        }
        echo json_encode(['success'=>true,'story_id'=>$storyId,'message'=>'Story saved']); exit;
    }
    if ($action === 'delete_story' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $storyId=(int)($_POST['story_id']??0);
        if (!$storyId){echo json_encode(['success'=>false,'message'=>'Invalid story_id']);exit;}
        $stmt=$db->prepare("DELETE FROM global_success_stories WHERE id=? LIMIT 1"); $stmt->execute([$storyId]);
        echo json_encode(['success'=>$stmt->rowCount()>0,'message'=>'Story deleted']); exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE LIFE VIDEO
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_lifevideo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $slot=(int)($_POST['slot']??0); $title=trim($_POST['title']??'');
        $img=trim($_POST['img']??''); $videoUrl=trim($_POST['video_url']??'');
        $updatedBy=$_SESSION['da360_user']['name']??$_SESSION['da360_user']['username']??'unknown';
        if ($slot<1||$slot>4){echo json_encode(['success'=>false,'message'=>'Invalid slot']);exit;}
        $stmt=$db->prepare("INSERT INTO global_life_videos (slot,title,img,video_url,updated_at,updated_by) VALUES (?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE title=VALUES(title),img=VALUES(img),video_url=VALUES(video_url),updated_at=NOW(),updated_by=VALUES(updated_by)");
        $stmt->execute([$slot,$title,$img,$videoUrl,$updatedBy]);
        echo json_encode(['success'=>true,'id'=>(int)$db->lastInsertId()?:null,'message'=>'Life video saved']); exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE GUEST FACULTY
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_guest_faculty' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $facultyId         = (int)($_POST['faculty_id']           ?? 0);
        $name              = trim($_POST['name']                   ?? '');
        $namePopup         = trim($_POST['name_popup']             ?? '');
        $title             = trim($_POST['title']                  ?? '');
        $expertise         = trim($_POST['expertise']              ?? '');
        $description       = trim($_POST['description']           ?? '');
        $profileImage      = trim($_POST['profile_image']          ?? '');
        $profileImagePopup = trim($_POST['profile_image_popup']    ?? '');
        $sortOrder         = (int)($_POST['sort_order']            ?? 1);
        $logosJson         = $_POST['logos']                       ?? '[]';
        $updatedBy         = $_SESSION['da360_user']['name'] ?? $_SESSION['da360_user']['username'] ?? 'unknown';
        $logos             = json_decode($logosJson, true) ?: [];

        if (!$name) { echo json_encode(['success' => false, 'message' => 'Name is required']); exit; }

        if ($facultyId) {
            $stmt = $db->prepare("UPDATE guest_faculty SET name=?,name_popup=?,title=?,expertise=?,description=?,profile_image=?,profile_image_popup=?,sort_order=?,updated_at=NOW(),updated_by=? WHERE id=?");
            $stmt->execute([$name,$namePopup,$title,$expertise,$description,$profileImage,$profileImagePopup,$sortOrder,$updatedBy,$facultyId]);
        } else {
            $stmt = $db->prepare("INSERT INTO guest_faculty (name,name_popup,title,expertise,description,profile_image,profile_image_popup,sort_order,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,NOW(),?)");
            $stmt->execute([$name,$namePopup,$title,$expertise,$description,$profileImage,$profileImagePopup,$sortOrder,$updatedBy]);
            $facultyId = (int)$db->lastInsertId();
        }

        $db->prepare("DELETE FROM guest_faculty_logos WHERE faculty_id=?")->execute([$facultyId]);
        $insLogo = $db->prepare("INSERT INTO guest_faculty_logos (faculty_id,logo,sort_order,updated_at,updated_by) VALUES (?,?,?,NOW(),?)");
        foreach ($logos as $i => $l) {
            $logo = trim($l['logo'] ?? '');
            if ($logo === '') continue;
            $insLogo->execute([$facultyId, $logo, (int)($l['sort_order'] ?? $i+1), $updatedBy]);
        }

        echo json_encode(['success' => true, 'faculty_id' => $facultyId, 'message' => 'Guest Faculty saved']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DELETE GUEST FACULTY
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'delete_guest_faculty' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $facultyId = (int)($_POST['faculty_id'] ?? 0);
        if (!$facultyId) { echo json_encode(['success' => false, 'message' => 'Invalid faculty_id']); exit; }
        $db->prepare("DELETE FROM guest_faculty_logos WHERE faculty_id=?")->execute([$facultyId]);
        $stmt = $db->prepare("DELETE FROM guest_faculty WHERE id=? LIMIT 1");
        $stmt->execute([$facultyId]);
        echo json_encode(['success' => $stmt->rowCount() > 0, 'message' => 'Guest Faculty deleted']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE / DELETE FACULTY
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_faculty' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $facultyId=(int)($_POST['faculty_id']??0); $sortOrder=(int)($_POST['sort_order']??1);
        $name=trim($_POST['name']??''); $role=trim($_POST['role']??''); $experience=trim($_POST['experience']??'');
        $img=trim($_POST['img']??''); $linkedinLink=trim($_POST['linkedin_link']??'');
        $tab=trim($_POST['tab']??''); $textColor=trim($_POST['text_color']??'');
        $icon=trim($_POST['icon']??'star'); $iconImg=trim($_POST['icon_img']??''); $iconPosition=trim($_POST['icon_position']??'');
        $updatedBy=$_SESSION['da360_user']['name']??$_SESSION['da360_user']['username']??'unknown';
        if ($facultyId) {
            $stmt=$db->prepare("UPDATE global_faculty SET name=?,role=?,experience=?,img=?,linkedin_link=?,tab=?,text_color=?,icon=?,icon_img=?,icon_position=?,sort_order=?,updated_at=NOW(),updated_by=? WHERE id=?");
            $stmt->execute([$name,$role,$experience,$img,$linkedinLink,$tab,$textColor,$icon,$iconImg,$iconPosition,$sortOrder,$updatedBy,$facultyId]);
        } else {
            $stmt=$db->prepare("INSERT INTO global_faculty (name,role,experience,img,linkedin_link,tab,text_color,icon,icon_img,icon_position,sort_order,updated_at,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?)");
            $stmt->execute([$name,$role,$experience,$img,$linkedinLink,$tab,$textColor,$icon,$iconImg,$iconPosition,$sortOrder,$updatedBy]);
            $facultyId=(int)$db->lastInsertId();
        }
        echo json_encode(['success'=>true,'faculty_id'=>$facultyId,'message'=>'Faculty saved']); exit;
    }
    if ($action === 'delete_faculty' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $facultyId=(int)($_POST['faculty_id']??0);
        if (!$facultyId){echo json_encode(['success'=>false,'message'=>'Invalid faculty_id']);exit;}
        $stmt=$db->prepare("DELETE FROM global_faculty WHERE id=? LIMIT 1"); $stmt->execute([$facultyId]);
        echo json_encode(['success'=>$stmt->rowCount()>0,'message'=>'Faculty deleted']); exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE / DELETE BANNER
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_banner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $bannerId=(int)($_POST['banner_id']??0); $sortOrder=(int)($_POST['sort_order']??1);
        $imageUrl=trim($_POST['image_url']??'');
        $updatedBy=$_SESSION['da360_user']['name']??$_SESSION['da360_user']['username']??'unknown';
        if ($bannerId) {
            $stmt=$db->prepare("UPDATE global_banners SET image_url=?,sort_order=?,updated_at=NOW(),updated_by=? WHERE id=?");
            $stmt->execute([$imageUrl,$sortOrder,$updatedBy,$bannerId]);
        } else {
            $stmt=$db->prepare("INSERT INTO global_banners (image_url,sort_order,updated_at,updated_by) VALUES (?,?,NOW(),?)");
            $stmt->execute([$imageUrl,$sortOrder,$updatedBy]); $bannerId=(int)$db->lastInsertId();
        }
        echo json_encode(['success'=>true,'banner_id'=>$bannerId,'message'=>'Banner saved']); exit;
    }
    if ($action === 'delete_banner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $bannerId=(int)($_POST['banner_id']??0);
        if (!$bannerId){echo json_encode(['success'=>false,'message'=>'Invalid banner_id']);exit;}
        $stmt=$db->prepare("DELETE FROM global_banners WHERE id=? LIMIT 1"); $stmt->execute([$bannerId]);
        echo json_encode(['success'=>$stmt->rowCount()>0,'message'=>'Banner deleted']); exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE / DELETE BLOG POST
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_blog' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $blogId=(int)($_POST['blog_id']??0); $sortOrder=(int)($_POST['sort_order']??1);
        $imgSrc=trim($_POST['img_src']??''); $category=trim($_POST['category']??'');
        $title=trim($_POST['title']??''); $link=trim($_POST['link']??'');
        $updatedBy=$_SESSION['da360_user']['name']??$_SESSION['da360_user']['username']??'unknown';
        if ($blogId) {
            $stmt=$db->prepare("UPDATE global_blog_posts SET img_src=?,category=?,title=?,link=?,sort_order=?,updated_at=NOW(),updated_by=? WHERE id=?");
            $stmt->execute([$imgSrc,$category,$title,$link,$sortOrder,$updatedBy,$blogId]);
        } else {
            $stmt=$db->prepare("INSERT INTO global_blog_posts (img_src,category,title,link,sort_order,updated_at,updated_by) VALUES (?,?,?,?,?,NOW(),?)");
            $stmt->execute([$imgSrc,$category,$title,$link,$sortOrder,$updatedBy]); $blogId=(int)$db->lastInsertId();
        }
        echo json_encode(['success'=>true,'blog_id'=>$blogId,'message'=>'Blog post saved']); exit;
    }
    if ($action === 'delete_blog' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $blogId=(int)($_POST['blog_id']??0);
        if (!$blogId){echo json_encode(['success'=>false,'message'=>'Invalid blog_id']);exit;}
        $stmt=$db->prepare("DELETE FROM global_blog_posts WHERE id=? LIMIT 1"); $stmt->execute([$blogId]);
        echo json_encode(['success'=>$stmt->rowCount()>0,'message'=>'Blog post deleted']); exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SAVE / DELETE MEDIA
    // ══════════════════════════════════════════════════════════════════════════
    if ($action === 'save_media' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mediaId=(int)($_POST['media_id']??0); $sortOrder=(int)($_POST['sort_order']??1);
        $src=trim($_POST['src']??''); $alt=trim($_POST['alt']??'');
        $updatedBy=$_SESSION['da360_user']['name']??$_SESSION['da360_user']['username']??'unknown';
        if ($mediaId) {
            $stmt=$db->prepare("UPDATE global_media SET src=?,alt=?,sort_order=?,updated_at=NOW(),updated_by=? WHERE id=?");
            $stmt->execute([$src,$alt,$sortOrder,$updatedBy,$mediaId]);
        } else {
            $stmt=$db->prepare("INSERT INTO global_media (src,alt,sort_order,updated_at,updated_by) VALUES (?,?,?,NOW(),?)");
            $stmt->execute([$src,$alt,$sortOrder,$updatedBy]); $mediaId=(int)$db->lastInsertId();
        }
        echo json_encode(['success'=>true,'media_id'=>$mediaId,'message'=>'Media saved']); exit;
    }
    if ($action === 'delete_media' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $mediaId=(int)($_POST['media_id']??0);
        if (!$mediaId){echo json_encode(['success'=>false,'message'=>'Invalid media_id']);exit;}
        $stmt=$db->prepare("DELETE FROM global_media WHERE id=? LIMIT 1"); $stmt->execute([$mediaId]);
        echo json_encode(['success'=>$stmt->rowCount()>0,'message'=>'Media deleted']); exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}