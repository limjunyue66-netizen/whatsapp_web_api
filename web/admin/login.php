<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
start_app_session();
if (current_user()) {
    header('Location: dashboard.php');
    exit;
}
$app = e((string) setting('app_name', app_config('app_name')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login — <?= $app ?></title>
  <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <h1><?= $app ?></h1>
    <p class="muted">Internal consent-based WhatsApp messaging control panel.</p>
    <div id="alert" class="alert error"></div>
    <form id="loginForm" class="form-grid">
      <label>Username<input name="username" autocomplete="username" required></label>
      <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn" type="submit">Sign in</button>
    </form>
  </div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const alert = document.getElementById('alert');
  alert.classList.remove('show');
  try {
    const res = await fetch('../api/admin/login.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({username: fd.get('username'), password: fd.get('password')})
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'Login failed');
    location.href = 'dashboard.php';
  } catch (err) {
    alert.textContent = err.message;
    alert.classList.add('show');
  }
});
</script>
</body>
</html>
