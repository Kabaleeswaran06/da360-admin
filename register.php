<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

requireLogin();
$currentUser = getCurrentUser();
if ($currentUser['role'] !== 'Super Admin') {
    header('Location: /da360-admin/dashboard.php');
    exit;
}

$error   = '';
$success = '';
$pwError   = '';
$pwSuccess = '';

$db = getDB();

// ── Handle Register ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $username        = trim($_POST['username']        ?? '');
    $name            = trim($_POST['name']            ?? '');
    $role            = trim($_POST['role']            ?? 'Editor');
    $password        = $_POST['password']             ?? '';
    $passwordConfirm = $_POST['password_confirm']     ?? '';
    $allowedRoles    = ['Super Admin', 'Manager', 'Editor'];

    if (!$username || !$name || !$password) {
        $error = 'Username, full name, and password are all required.';
    } elseif (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
        $error = 'Username must be 3–30 characters: lowercase letters, numbers, underscores only.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = 'Invalid role selected.';
    } else {
        try {
            $check = $db->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
            $check->execute([$username]);
            if ($check->fetch()) {
                $error = "Username '{$username}' is already taken.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    INSERT INTO admin_users (username, password_hash, name, role, is_active)
                    VALUES (:username, :password_hash, :name, :role, 1)
                ");
                $stmt->execute([
                    ':username'      => $username,
                    ':password_hash' => $hash,
                    ':name'          => $name,
                    ':role'          => $role,
                ]);
                $success = "Account <strong>{$username}</strong> created successfully.";
            }
        } catch (Exception $e) {
            $error = 'Database error — could not create account.';
        }
    }
}

// ── Handle Password Update ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_password') {
    $userId      = (int)($_POST['user_id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';

    if (!$userId) {
        $pwError = 'Invalid user.';
    } elseif (strlen($newPassword) < 8) {
        $pwError = 'Password must be at least 8 characters.';
    } else {
        try {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $userId]);
            $pwSuccess = 'Password updated successfully.';
        } catch (Exception $e) {
            $pwError = 'Database error — could not update password.';
        }
    }
}

// ── Handle Toggle Active ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
    $userId    = (int)($_POST['user_id']    ?? 0);
    $newStatus = (int)($_POST['new_status'] ?? 0);
    if ($userId && $userId !== (int)$currentUser['id']) {
        $stmt = $db->prepare("UPDATE admin_users SET is_active = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);
        $pwSuccess = 'User status updated.';
    } else {
        $pwError = 'You cannot deactivate your own account.';
    }
}

// ── Fetch all users ───────────────────────────────────────────────────────────
$users = $db->query("SELECT id, username, name, role, is_active, created_at FROM admin_users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Which tab to show after POST
$activeTab = (isset($_POST['action']) && in_array($_POST['action'], ['update_password', 'toggle_active'])) ? 'manage' : 'register';

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content" data-page="register">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>User Management</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">User <span>Management</span></h1>
        <p class="page-subtitle">Register new admin accounts and manage existing users.</p>
      </div>
    </div>
  </div>

  <!-- ── Tabs ── -->
  <div class="um-tabs">
    <button class="um-tab <?= $activeTab === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">
      ➕ Register User
    </button>
    <button class="um-tab <?= $activeTab === 'manage' ? 'active' : '' ?>" onclick="switchTab('manage')">
      👥 Manage Users <span class="um-badge"><?= count($users) ?></span>
    </button>
  </div>

  <!-- ── Tab: Register ── -->
  <div id="tab-register" class="um-panel" style="display:<?= $activeTab === 'register' ? 'block' : 'none' ?>">

    <?php if ($success): ?>
      <div class="reg-alert reg-alert--success">
        ✅ <?= $success ?>
        <a href="/da360-admin/register.php" class="reg-alert-link">Add another →</a>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="reg-alert reg-alert--error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" class="register-form" novalidate>
      <input type="hidden" name="action" value="register">

      <div class="reg-row">
        <div class="reg-field">
          <label for="username">Username</label>
          <input type="text" id="username" name="username"
            placeholder="e.g. john_doe"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            autocomplete="off" pattern="[a-z0-9_]{3,30}" required />
          <span class="reg-hint">3–30 chars · lowercase, numbers, underscores</span>
        </div>
        <div class="reg-field">
          <label for="name">Full Name</label>
          <input type="text" id="name" name="name"
            placeholder="e.g. John Doe"
            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
            autocomplete="off" required />
          <span class="reg-hint">Displayed in the admin panel header</span>
        </div>
      </div>

      <div class="reg-row">
        <div class="reg-field">
          <label for="password">Password</label>
          <div class="reg-password-wrap">
            <input type="password" id="password" name="password"
              placeholder="Min. 8 characters" autocomplete="new-password" required />
            <button type="button" class="reg-eye" onclick="togglePw('password', this)">👁</button>
          </div>
          <span class="reg-hint" id="pw-strength-label"></span>
        </div>
        <div class="reg-field">
          <label for="password_confirm">Confirm Password</label>
          <div class="reg-password-wrap">
            <input type="password" id="password_confirm" name="password_confirm"
              placeholder="Re-enter password" autocomplete="new-password" required />
            <button type="button" class="reg-eye" onclick="togglePw('password_confirm', this)">👁</button>
          </div>
          <span class="reg-hint" id="pw-match-label"></span>
        </div>
      </div>

      <div class="reg-row">
        <div class="reg-field">
          <label for="role">Role</label>
          <select id="role" name="role" required>
            <?php foreach (['Editor', 'Manager', 'Super Admin'] as $r): ?>
              <option value="<?= $r ?>" <?= (($_POST['role'] ?? 'Editor') === $r) ? 'selected' : '' ?>>
                <?= $r ?>
              </option>
            <?php endforeach; ?>
          </select>
          <span class="reg-hint">Controls what this user can access</span>
        </div>
        <div class="reg-field reg-field--empty"></div>
      </div>

      <div class="reg-role-legend">
        <div class="role-card">
          <span class="role-badge role-badge--editor">Editor</span>
          <p>Can view and edit content fields. Cannot manage users or settings.</p>
        </div>
        <div class="role-card">
          <span class="role-badge role-badge--manager">Manager</span>
          <p>Full content access. Can manage FAQs, schemas, and meta. No user management.</p>
        </div>
        <div class="role-card">
          <span class="role-badge role-badge--superadmin">Super Admin</span>
          <p>Unrestricted access. Can create and deactivate accounts.</p>
        </div>
      </div>

      <div class="reg-actions">
        <a href="/da360-admin/dashboard.php" class="btn btn-secondary">← Cancel</a>
        <button type="submit" class="btn btn-primary">Create Account →</button>
      </div>
    </form>
  </div>

  <!-- ── Tab: Manage Users ── -->
  <div id="tab-manage" class="um-panel" style="display:<?= $activeTab === 'manage' ? 'block' : 'none' ?>">

    <?php if ($pwSuccess): ?>
      <div class="reg-alert reg-alert--success">✅ <?= htmlspecialchars($pwSuccess) ?></div>
    <?php endif; ?>
    <?php if ($pwError): ?>
      <div class="reg-alert reg-alert--error">⚠️ <?= htmlspecialchars($pwError) ?></div>
    <?php endif; ?>

    <div class="um-user-list">
      <?php foreach ($users as $u): ?>
        <?php $isSelf = ((int)$u['id'] === (int)$currentUser['id']); ?>
        <div class="um-user-card <?= $u['is_active'] ? '' : 'um-user-card--inactive' ?>">

          <!-- Left: avatar + info -->
          <div class="um-user-info">
            <div class="um-avatar"><?= strtoupper(substr($u['name'], 0, 1)) ?></div>
            <div>
              <div class="um-user-name">
                <?= htmlspecialchars($u['name']) ?>
                <?php if ($isSelf): ?>
                  <span class="um-you-badge">You</span>
                <?php endif; ?>
              </div>
              <div class="um-user-meta">
                @<?= htmlspecialchars($u['username']) ?> ·
                <span class="role-badge role-badge--<?= strtolower(str_replace(' ', '', $u['role'])) ?>">
                  <?= htmlspecialchars($u['role']) ?>
                </span>
                · Joined <?= date('M j, Y', strtotime($u['created_at'])) ?>
              </div>
            </div>
          </div>

          <!-- Right: actions -->
          <div class="um-user-actions">

            <!-- Status toggle -->
            <?php if (!$isSelf): ?>
              <form method="POST" action="" style="display:inline">
                <input type="hidden" name="action"     value="toggle_active">
                <input type="hidden" name="user_id"    value="<?= $u['id'] ?>">
                <input type="hidden" name="new_status" value="<?= $u['is_active'] ? 0 : 1 ?>">
                <button type="submit"
                  class="um-btn <?= $u['is_active'] ? 'um-btn--danger' : 'um-btn--success' ?>"
                  onclick="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
                  <?= $u['is_active'] ? '🔒 Deactivate' : '✅ Activate' ?>
                </button>
              </form>
            <?php endif; ?>

            <!-- Change password toggle -->
            <button class="um-btn um-btn--neutral"
              onclick="togglePwForm('pwform-<?= $u['id'] ?>', this)">
              🔑 Change Password
            </button>
          </div>

          <!-- Inline password form (hidden by default) -->
          <div id="pwform-<?= $u['id'] ?>" class="um-pw-form" style="display:none">
            <form method="POST" action="">
              <input type="hidden" name="action"  value="update_password">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <div class="um-pw-row">
                <div class="reg-password-wrap" style="flex:1">
                  <input type="password" name="new_password"
                    placeholder="New password (min. 8 chars)"
                    id="npw-<?= $u['id'] ?>"
                    autocomplete="new-password"
                    class="um-pw-input" required />
                  <button type="button" class="reg-eye"
                    onclick="togglePw('npw-<?= $u['id'] ?>', this)">👁</button>
                </div>
                <button type="submit" class="um-btn um-btn--primary">Save</button>
                <button type="button" class="um-btn um-btn--ghost"
                  onclick="togglePwForm('pwform-<?= $u['id'] ?>', this)">Cancel</button>
              </div>
            </form>
          </div>

        </div>
      <?php endforeach; ?>
    </div>
  </div>

</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
<div id="toast"></div>

<style>
/* ── Tabs ──────────────────────────────────────────────────────────────── */
.um-tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 24px;
  border-bottom: 1px solid #2d3f55;
  padding-bottom: 0;
}
.um-tab {
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  color: #64748b;
  font-size: 14px;
  font-weight: 600;
  padding: 10px 20px;
  cursor: pointer;
  transition: color .2s, border-color .2s;
  margin-bottom: -1px;
  font-family: inherit;
  display: flex;
  align-items: center;
  gap: 8px;
}
.um-tab:hover  { color: #94a3b8; }
.um-tab.active { color: #0ea5e9; border-bottom-color: #0ea5e9; }
.um-badge {
  background: #0ea5e9;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 1px 7px;
  border-radius: 20px;
}

/* ── User cards ────────────────────────────────────────────────────────── */
.um-user-list { display: flex; flex-direction: column; gap: 12px; max-width: 900px; }

.um-user-card {
  background: #1e293b;
  border: 1px solid #2d3f55;
  border-radius: 14px;
  padding: 18px 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  transition: border-color .2s;
}
.um-user-card:hover { border-color: #0ea5e9; }
.um-user-card--inactive { opacity: .55; }

.um-avatar {
  width: 44px; height: 44px;
  border-radius: 50%;
  background: linear-gradient(135deg, #0ea5e9, #8b5cf6);
  color: #fff;
  font-size: 18px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.um-user-info {
  display: flex;
  align-items: center;
  gap: 14px;
  flex: 1;
  min-width: 0;
}
.um-user-name {
  font-size: 15px;
  font-weight: 600;
  color: #e2e8f0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.um-you-badge {
  font-size: 10px;
  background: rgba(14,165,233,.2);
  color: #38bdf8;
  border: 1px solid rgba(14,165,233,.3);
  padding: 1px 7px;
  border-radius: 20px;
  font-weight: 700;
}
.um-user-meta {
  font-size: 12px;
  color: #64748b;
  margin-top: 3px;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.um-user-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}

/* Inline password form */
.um-pw-form {
  width: 100%;
  padding-top: 14px;
  border-top: 1px solid #2d3f55;
  margin-top: 4px;
}
.um-pw-row {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}
.um-pw-input {
  background: #0f172a;
  border: 1.5px solid #2d3f55;
  border-radius: 8px;
  color: #e2e8f0;
  font-size: 14px;
  padding: 9px 42px 9px 14px;
  font-family: inherit;
  width: 100%;
  transition: border-color .2s;
}
.um-pw-input:focus {
  outline: none;
  border-color: #0ea5e9;
  box-shadow: 0 0 0 3px rgba(14,165,233,.15);
}

/* Buttons */
.um-btn {
  font-size: 12px;
  font-weight: 600;
  padding: 7px 14px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  font-family: inherit;
  white-space: nowrap;
  transition: opacity .2s, transform .1s;
}
.um-btn:hover  { opacity: .85; }
.um-btn:active { transform: scale(.97); }
.um-btn--danger   { background: rgba(239,68,68,.15);  color: #f87171; border: 1px solid rgba(239,68,68,.3); }
.um-btn--success  { background: rgba(34,197,94,.15);  color: #4ade80; border: 1px solid rgba(34,197,94,.3); }
.um-btn--neutral  { background: rgba(100,116,139,.15); color: #94a3b8; border: 1px solid rgba(100,116,139,.3); }
.um-btn--primary  { background: #0ea5e9; color: #fff; }
.um-btn--ghost    { background: none; color: #64748b; border: 1px solid #2d3f55; }

/* ── Reuse existing register form styles ───────────────────────────────── */
.register-wrap { max-width: 780px; }
.reg-alert { padding:14px 18px; border-radius:10px; font-size:14px; margin-bottom:24px; display:flex; align-items:center; gap:10px; }
.reg-alert--success { background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.3); color:#86efac; }
.reg-alert--error   { background:rgba(239,68,68,.1);  border:1px solid rgba(239,68,68,.3);  color:#fca5a5; }
.reg-alert-link { margin-left:auto; color:#86efac; text-decoration:underline; white-space:nowrap; font-weight:600; }
.register-form { background:#1e293b; border:1px solid #2d3f55; border-radius:16px; padding:32px; display:flex; flex-direction:column; gap:24px; max-width:780px; }
.reg-row { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
@media(max-width:600px){.reg-row{grid-template-columns:1fr;}}
.reg-field { display:flex; flex-direction:column; gap:6px; }
.reg-field--empty { display:none; }
@media(min-width:601px){.reg-field--empty{display:block;}}
.reg-field label { font-size:13px; font-weight:600; color:#94a3b8; letter-spacing:.3px; text-transform:uppercase; }
.reg-field input,.reg-field select { background:#0f172a; border:1.5px solid #2d3f55; border-radius:8px; color:#e2e8f0; font-size:14px; padding:10px 14px; font-family:inherit; transition:border-color .2s,box-shadow .2s; width:100%; }
.reg-field input:focus,.reg-field select:focus { outline:none; border-color:#0ea5e9; box-shadow:0 0 0 3px rgba(14,165,233,.15); }
.reg-field input::placeholder{color:#475569;}
.reg-field select option{background:#1e293b;}
.reg-hint{font-size:11.5px;color:#475569;min-height:16px;}
.reg-hint.ok{color:#22c55e;}.reg-hint.bad{color:#f87171;}
.reg-password-wrap{position:relative;display:flex;}
.reg-password-wrap input{padding-right:42px;}
.reg-eye{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;color:#475569;padding:0;line-height:1;}
.reg-eye:hover{color:#94a3b8;}
.reg-role-legend{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
@media(max-width:600px){.reg-role-legend{grid-template-columns:1fr;}}
.role-card{background:#0f172a;border:1px solid #2d3f55;border-radius:10px;padding:14px;display:flex;flex-direction:column;gap:8px;}
.role-card p{font-size:12px;color:#64748b;margin:0;line-height:1.5;}
.role-badge{display:inline-block;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.3px;align-self:flex-start;}
.role-badge--editor{background:rgba(100,116,139,.2);color:#94a3b8;border:1px solid rgba(100,116,139,.3);}
.role-badge--manager{background:rgba(139,92,246,.2);color:#a78bfa;border:1px solid rgba(139,92,246,.3);}
.role-badge--superadmin,.role-badge--superadmin{background:rgba(14,165,233,.2);color:#38bdf8;border:1px solid rgba(14,165,233,.3);}
.reg-actions{display:flex;justify-content:flex-end;gap:12px;padding-top:8px;border-top:1px solid #2d3f55;}
</style>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.um-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.um-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + name).style.display = 'block';
    document.querySelector('[onclick="switchTab(\'' + name + '\')"]').classList.add('active');
}

// ── Toggle inline password form ───────────────────────────────────────────────
function togglePwForm(id, btn) {
    var form = document.getElementById(id);
    var visible = form.style.display !== 'none';
    form.style.display = visible ? 'none' : 'block';
    btn.textContent = visible ? '🔑 Change Password' : '✖ Cancel';
}

// ── Show/hide password ────────────────────────────────────────────────────────
function togglePw(fieldId, btn) {
    var input = document.getElementById(fieldId);
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
}

// ── Password strength ─────────────────────────────────────────────────────────
document.getElementById('password').addEventListener('input', function () {
    var val = this.value;
    var label = document.getElementById('pw-strength-label');
    if (!val) { label.textContent = ''; label.className = 'reg-hint'; return; }
    if (val.length < 8) { label.textContent = 'Too short'; label.className = 'reg-hint bad'; return; }
    var strong = /[A-Z]/.test(val) && /[0-9]/.test(val) && /[^a-zA-Z0-9]/.test(val);
    var medium = val.length >= 10 || /[A-Z]/.test(val) || /[0-9]/.test(val);
    if (strong)       { label.textContent = '💪 Strong';                          label.className = 'reg-hint ok'; }
    else if (medium)  { label.textContent = '👍 Medium — add numbers or symbols'; label.className = 'reg-hint'; }
    else              { label.textContent = '⚠️ Weak';                            label.className = 'reg-hint bad'; }
    checkMatch();
});
document.getElementById('password_confirm').addEventListener('input', checkMatch);
function checkMatch() {
    var pw  = document.getElementById('password').value;
    var pw2 = document.getElementById('password_confirm').value;
    var label = document.getElementById('pw-match-label');
    if (!pw2) { label.textContent = ''; label.className = 'reg-hint'; return; }
    if (pw === pw2) { label.textContent = '✅ Passwords match'; label.className = 'reg-hint ok'; }
    else            { label.textContent = '❌ Passwords do not match'; label.className = 'reg-hint bad'; }
}
</script>