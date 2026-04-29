<?php
// Root redirect
require_once __DIR__ . '/config/auth.php';
if (isLoggedIn()) {
    header('Location: /da360-admin/dashboard.php');
} else {
    header('Location: /da360-admin/login.php');
}
exit;
