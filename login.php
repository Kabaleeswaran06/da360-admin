<?php
require_once __DIR__ . '/config/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: /da360-admin/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (attemptLogin($username, $password)) {
        header('Location: /da360-admin/dashboard.php');
        exit;
    }
    $error = 'Invalid username or password. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>DA360 Admin — Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/da360-admin/assets/style.css">
</head>
<body class="login-page">

<div class="login-box">
  <div class="login-logo">Digital <em>Academy</em> 360</div>
  <div class="login-tagline">ADMIN PANEL · CONTENT MANAGER</div>

  <?php if ($error): ?>
    <div class="login-error">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="">
    <div class="login-form-group">
      <label for="username">Username</label>
      <input
        type="text"
        id="username"
        name="username"
        placeholder="Enter your username"
        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
        autocomplete="username"
        required
        autofocus
      />
    </div>
    <div class="login-form-group">
      <label for="password">Password</label>
      <input
        type="password"
        id="password"
        name="password"
        placeholder="Enter your password"
        autocomplete="current-password"
        required
      />
    </div>
    <button type="submit" class="login-btn">Sign In →</button>
  </form>

 
</div>

</body>
</html>
