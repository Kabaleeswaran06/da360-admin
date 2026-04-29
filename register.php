<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

// Only Super Admins can create new accounts
requireLogin();
$currentUser = getCurrentUser();
if ($currentUser['role'] !== 'Super Admin') {
    header('Location: /da360-admin/dashboard.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username        = trim($_POST['username']         ?? '');
    $name            = trim($_POST['name']             ?? '');
    $role            = trim($_POST['role']             ?? 'Editor');
    $password        = $_POST['password']              ?? '';
    $passwordConfirm = $_POST['password_confirm']      ?? '';

    // ── Validation ──────────────────────────────────────────────────────
    $allowedRoles = ['Super Admin', 'Manager', 'Editor'];

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
            $db = getDB();

            // Check username uniqueness
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
            error_log('DA360 register error: ' . $e->getMessage());
            $error = 'Database error — could not create account.';
        }
    }
}

include __DIR__ . '/partials/header.php';
include __DIR__ . '/partials/sidebar.php';
?>

<main class="main-content" data-page="register">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="/da360-admin/dashboard.php">Home</a>
      <span class="breadcrumb-sep">/</span>
      <span>Register User</span>
    </div>
    <div class="page-header-inner">
      <div>
        <h1 class="page-title">Register <span>User</span></h1>
        <p class="page-subtitle">Create a new admin panel account. Only Super Admins can do this.</p>
      </div>
    </div>
  </div>

  <div class="register-wrap">

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

      <div class="reg-row">
        <!-- Username -->
        <div class="reg-field">
          <label for="username">Username</label>
          <input
            type="text"
            id="username"
            name="username"
            placeholder="e.g. john_doe"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            autocomplete="off"
            pattern="[a-z0-9_]{3,30}"
            required
          />
          <span class="reg-hint">3–30 chars · lowercase, numbers, underscores</span>
        </div>

        <!-- Full Name -->
        <div class="reg-field">
          <label for="name">Full Name</label>
          <input
            type="text"
            id="name"
            name="name"
            placeholder="e.g. John Doe"
            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
            autocomplete="off"
            required
          />
          <span class="reg-hint">Displayed in the admin panel header</span>
        </div>
      </div>

      <div class="reg-row">
        <!-- Password -->
        <div class="reg-field">
          <label for="password">Password</label>
          <div class="reg-password-wrap">
            <input
              type="password"
              id="password"
              name="password"
              placeholder="Min. 8 characters"
              autocomplete="new-password"
              required
            />
            <button type="button" class="reg-eye" onclick="togglePw('password', this)" title="Show / hide">👁</button>
          </div>
          <span class="reg-hint" id="pw-strength-label"></span>
        </div>

        <!-- Confirm Password -->
        <div class="reg-field">
          <label for="password_confirm">Confirm Password</label>
          <div class="reg-password-wrap">
            <input
              type="password"
              id="password_confirm"
              name="password_confirm"
              placeholder="Re-enter password"
              autocomplete="new-password"
              required
            />
            <button type="button" class="reg-eye" onclick="togglePw('password_confirm', this)" title="Show / hide">👁</button>
          </div>
          <span class="reg-hint" id="pw-match-label"></span>
        </div>
      </div>

      <!-- Role -->
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
        <div class="reg-field reg-field--empty"></div><!-- spacer -->
      </div>

      <!-- Role capability legend -->
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
</main>

<?php include __DIR__ . '/partials/footer.php'; ?>
<div id="toast"></div>

<style>
/* ── Register page styles ─────────────────────────────────────────────── */
.register-wrap { max-width: 780px; }

.reg-alert {
  padding: 14px 18px;
  border-radius: 10px;
  font-size: 14px;
  margin-bottom: 24px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.reg-alert--success {
  background: rgba(34,197,94,.1);
  border: 1px solid rgba(34,197,94,.3);
  color: #86efac;
}
.reg-alert--error {
  background: rgba(239,68,68,.1);
  border: 1px solid rgba(239,68,68,.3);
  color: #fca5a5;
}
.reg-alert-link {
  margin-left: auto;
  color: #86efac;
  text-decoration: underline;
  white-space: nowrap;
  font-weight: 600;
}

.register-form {
  background: #1e293b;
  border: 1px solid #2d3f55;
  border-radius: 16px;
  padding: 32px;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.reg-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
@media (max-width: 600px) { .reg-row { grid-template-columns: 1fr; } }

.reg-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.reg-field--empty { display: none; }
@media (min-width: 601px) { .reg-field--empty { display: block; } }

.reg-field label {
  font-size: 13px;
  font-weight: 600;
  color: #94a3b8;
  letter-spacing: .3px;
  text-transform: uppercase;
}

.reg-field input,
.reg-field select {
  background: #0f172a;
  border: 1.5px solid #2d3f55;
  border-radius: 8px;
  color: #e2e8f0;
  font-size: 14px;
  padding: 10px 14px;
  font-family: inherit;
  transition: border-color .2s, box-shadow .2s;
  width: 100%;
}
.reg-field input:focus,
.reg-field select:focus {
  outline: none;
  border-color: #0ea5e9;
  box-shadow: 0 0 0 3px rgba(14,165,233,.15);
}
.reg-field input::placeholder { color: #475569; }
.reg-field select option { background: #1e293b; }

.reg-hint {
  font-size: 11.5px;
  color: #475569;
  min-height: 16px;
}
.reg-hint.ok  { color: #22c55e; }
.reg-hint.bad { color: #f87171; }

/* Password input wrapper */
.reg-password-wrap {
  position: relative;
  display: flex;
}
.reg-password-wrap input { padding-right: 42px; }
.reg-eye {
  position: absolute;
  right: 10px; top: 50%;
  transform: translateY(-50%);
  background: none; border: none;
  cursor: pointer; font-size: 16px;
  color: #475569; padding: 0;
  line-height: 1;
}
.reg-eye:hover { color: #94a3b8; }

/* Role legend */
.reg-role-legend {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}
@media (max-width: 600px) { .reg-role-legend { grid-template-columns: 1fr; } }

.role-card {
  background: #0f172a;
  border: 1px solid #2d3f55;
  border-radius: 10px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.role-card p { font-size: 12px; color: #64748b; margin: 0; line-height: 1.5; }

.role-badge {
  display: inline-block;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 20px;
  letter-spacing: .3px;
  align-self: flex-start;
}
.role-badge--editor     { background: rgba(100,116,139,.2); color: #94a3b8; border: 1px solid rgba(100,116,139,.3); }
.role-badge--manager    { background: rgba(139,92,246,.2);  color: #a78bfa; border: 1px solid rgba(139,92,246,.3); }
.role-badge--superadmin { background: rgba(14,165,233,.2);  color: #38bdf8; border: 1px solid rgba(14,165,233,.3); }

/* Actions */
.reg-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding-top: 8px;
  border-top: 1px solid #2d3f55;
}
</style>

<script>
function togglePw(fieldId, btn) {
    var input = document.getElementById(fieldId);
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
}

// ── Live password strength indicator ────────────────────────────────────
document.getElementById('password').addEventListener('input', function () {
    var val = this.value;
    var label = document.getElementById('pw-strength-label');
    if (!val) { label.textContent = ''; label.className = 'reg-hint'; return; }
    if (val.length < 8)  { label.textContent = 'Too short'; label.className = 'reg-hint bad'; return; }
    var strong = /[A-Z]/.test(val) && /[0-9]/.test(val) && /[^a-zA-Z0-9]/.test(val);
    var medium = val.length >= 10 || /[A-Z]/.test(val) || /[0-9]/.test(val);
    if (strong) { label.textContent = '💪 Strong'; label.className = 'reg-hint ok'; }
    else if (medium) { label.textContent = '👍 Medium — add numbers or symbols'; label.className = 'reg-hint'; }
    else { label.textContent = '⚠️ Weak'; label.className = 'reg-hint bad'; }
    checkMatch();
});

// ── Live password match indicator ────────────────────────────────────────
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