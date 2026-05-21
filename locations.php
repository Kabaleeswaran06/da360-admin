<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';
requireLogin();

$db = getDB();

// Handle AJAX save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_location') {
    header('Content-Type: application/json');
    $id            = (int)($_POST['id'] ?? 0);
    $phone         = trim($_POST['phone'] ?? '');
    $imgsrc        = trim($_POST['imgsrc'] ?? '');
    $directionLink = trim($_POST['direction_link'] ?? '');
    $addressLines  = array_values(array_filter(array_map('trim', explode("\n", $_POST['address_lines'] ?? ''))));

    if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

    $stmt = $db->prepare("
        UPDATE locations SET
            phone          = :phone,
            imgsrc         = :imgsrc,
            direction_link = :direction_link,
            address_lines  = :address_lines,
            updated_at     = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':phone'          => $phone,
        ':imgsrc'         => $imgsrc,
        ':direction_link' => $directionLink,
        ':address_lines'  => json_encode($addressLines),
        ':id'             => $id,
    ]);
    echo json_encode(['success' => true, 'message' => 'Location updated successfully.']);
    echo '<script type="text/javascript">location.reload();</script>';

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_show_details') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

    // Flip the current value
    $stmt = $db->prepare("
        UPDATE locations
        SET show_details = IF(show_details = 1, 0, 1),
            updated_at   = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);

    // Return the NEW value so the UI can update immediately
    $newVal = (int)$db->query("SELECT show_details FROM locations WHERE id = $id")->fetchColumn();
    echo json_encode(['success' => true, 'show_details' => $newVal]);
    exit;
}

$locations = $db->query("
    SELECT l.*, COUNT(cc.id) AS content_count
    FROM locations l
    LEFT JOIN course_content cc ON l.id = cc.location_id
    GROUP BY l.id
    ORDER BY l.sort_order, l.label
")->fetchAll();

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Locations</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">All <span>Locations</span></h1>
        <p class="page-subtitle">Overview of all locations configured in the system.</p>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title">📍 Location List</div>
      <span class="meta-pill"><?= count($locations) ?> locations</span>
    </div>
    <div class="card-body" style="padding:0;">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Location Name</th>
            <th>Slug</th>
            <th>Content Entries</th>
            <th>Sort Order</th>
            <th>Status</th>
            <th>Display</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($locations as $i => $loc): ?>
          <?php
            $addressLines = [];
            if (!empty($loc['address_lines'])) {
                $decoded = json_decode($loc['address_lines'], true);
                if (is_array($decoded)) $addressLines = $decoded;
            }
          ?>
          <tr>
            <td style="color:var(--muted)"><?= $i + 1 ?></td>
            <td style="font-weight:500"><?= htmlspecialchars($loc['label']) ?></td>
            <td><code style="font-size:0.8rem; color:var(--muted)"><?= htmlspecialchars($loc['slug']) ?></code></td>
            <td>
              <span class="meta-pill accent" style="font-size:0.75rem"><?= $loc['content_count'] ?> entries</span>
            </td>
            <td style="color:var(--muted)"><?= $loc['sort_order'] ?></td>
            <td>
              <?php if ($loc['is_active']): ?>
                <span class="badge badge-green">Active</span>
              <?php else: ?>
                <span class="badge badge-gray">Inactive</span>
              <?php endif; ?>
            </td>
            <td>
              <button
                class="toggle-btn"
                data-id="<?= $loc['id'] ?>"
                data-state="<?= $loc['show_details'] ?>"
                onclick="toggleShowDetails(this)"
                style="
                  background: <?= $loc['show_details'] ? 'var(--accent, #e85d2f)' : 'var(--surface, #e5e5e5)' ?>;
                  border: none;
                  border-radius: 999px;
                  width: 44px;
                  height: 24px;
                  cursor: pointer;
                  position: relative;
                  transition: background 0.2s;
                  flex-shrink: 0;
                  display: inline-block;
                "
                title="<?= $loc['show_details'] ? 'Hide location details' : 'Show location details' ?>"
              >
                <span style="
                  position: absolute;
                  top: 3px;
                  left: <?= $loc['show_details'] ? '23px' : '3px' ?>;
                  width: 18px;
                  height: 18px;
                  border-radius: 50%;
                  background: #fff;
                  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
                  transition: left 0.2s;
                  display: block;
                "></span>
              </button>
            </td>
            <td>
              <button
                class="btn btn-sm btn-outline"
                onclick='openEditModal(<?= json_encode([
                  "id"             => $loc["id"],
                  "label"          => $loc["label"],
                  "phone"          => $loc["phone"] ?? "",
                  "imgsrc"         => $loc["imgsrc"] ?? "",
                  "direction_link" => $loc["direction_link"] ?? "",
                  "address_lines"  => $addressLines,
                ]) ?>)'
              >✏️ Edit</button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($locations)): ?>
          <tr><td colspan="7" style="text-align:center; padding:32px; color:var(--muted)">No locations found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- Edit Modal -->
<div id="editModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1000; align-items:center; justify-content:center; padding:16px;">
  <div style="background:var(--card-bg, #fff); border-radius:16px; width:100%; max-width:520px; border:1px solid rgba(0,0,0,0.08); box-shadow:0 4px 24px rgba(0,0,0,0.12); overflow:hidden; display:flex; flex-direction:column;">

    <!-- Header -->
    <div style="padding:18px 24px 16px; border-bottom:1px solid var(--border, #f0f0f0); display:flex; align-items:center; justify-content:space-between; gap:12px;">
      <div style="display:flex; align-items:center; gap:12px;">
        <div style="width:36px; height:36px; border-radius:10px; background:var(--surface, #f5f5f5); border:1px solid var(--border, #eee); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted)"><path d="M12 21s-8-5.4-8-11a8 8 0 1 1 16 0c0 5.6-8 11-8 11z"/><circle cx="12" cy="10" r="3"/></svg>
        </div>
        <div>
          <div style="font-size:15px; font-weight:600; color:var(--text, #111);">Edit location</div>
          <div id="modalLabel" style="font-size:12px; color:var(--muted); margin-top:1px;"></div>
        </div>
      </div>
      <button onclick="closeEditModal()" style="background:none; border:none; cursor:pointer; color:var(--muted); padding:6px; border-radius:8px; line-height:1; display:flex; align-items:center; justify-content:center;" aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <!-- Body -->
    <div style="padding:20px 24px; display:flex; flex-direction:column; gap:16px; max-height:65vh; overflow-y:auto;">
      <input type="hidden" id="editId">

      <!-- Phone -->
      <div>
        <label style="display:block; font-size:11px; font-weight:600; color:var(--muted); letter-spacing:0.06em; text-transform:uppercase; margin-bottom:6px;">Phone</label>
        <div style="position:relative;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.41 2 2 0 0 1 3.6 1.21h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.77a16 16 0 0 0 6.06 6.06l.61-.61a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          <input type="text" id="editPhone" placeholder="+91 XXXXX XXXXX" style="width:100%; padding:9px 12px 9px 36px; font-size:14px; border:1px solid var(--border, #e5e5e5); border-radius:9px; background:var(--surface, #fafafa); color:var(--text, #111); outline:none; box-sizing:border-box;" onfocus="this.style.borderColor='var(--accent, #e85d2f)'" onblur="this.style.borderColor='var(--border, #e5e5e5)'">
        </div>
      </div>

      <!-- Image Path -->
      <div>
        <label style="display:block; font-size:11px; font-weight:600; color:var(--muted); letter-spacing:0.06em; text-transform:uppercase; margin-bottom:6px;">Image path</label>
        <div style="position:relative;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--muted); pointer-events:none;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <input type="text" id="editImgsrc" placeholder="/images/contactus-min.jpg" style="width:100%; padding:9px 12px 9px 36px; font-size:14px; border:1px solid var(--border, #e5e5e5); border-radius:9px; background:var(--surface, #fafafa); color:var(--text, #111); outline:none; box-sizing:border-box;" onfocus="this.style.borderColor='var(--accent, #e85d2f)'" onblur="this.style.borderColor='var(--border, #e5e5e5)'">
        </div>
      </div>

      <!-- Maps Link -->
      <div>
        <label style="display:block; font-size:11px; font-weight:600; color:var(--muted); letter-spacing:0.06em; text-transform:uppercase; margin-bottom:6px;">Google maps embed link</label>
        <textarea id="editDirectionLink" rows="3" placeholder="https://www.google.com/maps/embed?pb=..." style="width:100%; padding:9px 12px; font-size:13px; border:1px solid var(--border, #e5e5e5); border-radius:9px; background:var(--surface, #fafafa); color:var(--text, #111); outline:none; resize:vertical; box-sizing:border-box; line-height:1.5; font-family:inherit;" onfocus="this.style.borderColor='var(--accent, #e85d2f)'" onblur="this.style.borderColor='var(--border, #e5e5e5)'"></textarea>
      </div>

      <!-- Address Lines -->
      <div>
        <div style="display:flex; align-items:baseline; gap:6px; margin-bottom:6px;">
          <label style="font-size:11px; font-weight:600; color:var(--muted); letter-spacing:0.06em; text-transform:uppercase;">Address lines</label>
          <span style="font-size:11px; color:var(--muted);">— one line per row</span>
        </div>
        <textarea id="editAddressLines" rows="4" placeholder="Line 1&#10;Line 2&#10;Line 3" style="width:100%; padding:9px 12px; font-size:13px; border:1px solid var(--border, #e5e5e5); border-radius:9px; background:var(--surface, #fafafa); color:var(--text, #111); outline:none; resize:vertical; box-sizing:border-box; line-height:1.7; font-family:inherit;" onfocus="this.style.borderColor='var(--accent, #e85d2f)'" onblur="this.style.borderColor='var(--border, #e5e5e5)'"></textarea>
      </div>
    </div>

    <!-- Footer -->
    <div style="padding:14px 24px; border-top:1px solid var(--border, #f0f0f0); display:flex; gap:8px; justify-content:flex-end;">
      <button onclick="closeEditModal()" style="background:none; border:1px solid var(--border, #e5e5e5); border-radius:9px; padding:8px 18px; font-size:13px; font-weight:500; color:var(--muted); cursor:pointer;">Cancel</button>
      <button onclick="saveLocation()" id="saveBtn" style="background:var(--accent, #e85d2f); border:none; border-radius:9px; padding:8px 20px; font-size:13px; font-weight:600; color:#fff; cursor:pointer; display:flex; align-items:center; gap:6px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save changes
      </button>
    </div>
  </div>
</div>

<div id="toast"></div>

<script>
function openEditModal(data) {
  document.getElementById('editId').value           = data.id;
  document.getElementById('modalLabel').textContent = data.label;
  document.getElementById('editPhone').value        = data.phone || '';
  document.getElementById('editImgsrc').value       = data.imgsrc || '';
  document.getElementById('editDirectionLink').value = data.direction_link || '';
  document.getElementById('editAddressLines').value = Array.isArray(data.address_lines)
    ? data.address_lines.join('\n')
    : '';

  const modal = document.getElementById('editModal');
  modal.style.display = 'flex';
}

function closeEditModal() {
  document.getElementById('editModal').style.display = 'none';
}

// Close on backdrop click
document.getElementById('editModal').addEventListener('click', function(e) {
  if (e.target === this) closeEditModal();
});

async function saveLocation() {
  const btn = document.getElementById('saveBtn');
  btn.disabled = true;
  btn.textContent = 'Saving…';

  const body = new FormData();
  body.append('action',         'update_location');
  body.append('id',             document.getElementById('editId').value);
  body.append('phone',          document.getElementById('editPhone').value);
  body.append('imgsrc',         document.getElementById('editImgsrc').value);
  body.append('direction_link', document.getElementById('editDirectionLink').value);
  body.append('address_lines',  document.getElementById('editAddressLines').value);

  try {
    const res  = await fetch(window.location.href, { method: 'POST', body });
    const data = await res.json();
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) closeEditModal();
  } catch (err) {
    showToast('Something went wrong.', 'error');
  } finally {
    btn.disabled    = false;
    btn.textContent = 'Save Changes';
  }
}

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent  = msg;
  t.className    = 'toast toast-' + type;
  t.style.display = 'block';
  setTimeout(() => { t.style.display = 'none'; }, 3000);
}
async function toggleShowDetails(btn) {
  btn.disabled = true;
  const id = btn.dataset.id;

  const body = new FormData();
  body.append('action', 'toggle_show_details');
  body.append('id', id);

  try {
    const res  = await fetch(window.location.href, { method: 'POST', body });
    const data = await res.json();

    if (data.success) {
      const isOn = data.show_details === 1;
      btn.dataset.state               = data.show_details;
      btn.style.background            = isOn ? 'var(--accent, #e85d2f)' : 'var(--surface, #e5e5e5)';
      btn.querySelector('span').style.left = isOn ? '23px' : '3px';
      btn.title = isOn ? 'Hide location details' : 'Show location details';
      showToast(isOn ? 'Location details enabled.' : 'Location details hidden.', 'success');
    } else {
      showToast('Toggle failed.', 'error');
    }
  } catch {
    showToast('Something went wrong.', 'error');
  } finally {
    btn.disabled = false;
  }
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>