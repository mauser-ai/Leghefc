<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (Auth::isAuthenticated()) {
    redirect('/dashboard.php');
}

$error = '';
$nickname = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nickname = trim((string)($_POST['nickname'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $result = UserService::register($nickname, $password, $confirm);
    if ($result['ok']) {
        Auth::login($result['user']);
        UserService::updateLastLogin((int)$result['user']['id']);
        redirect('/profile.php?first=1');
    } else {
        $error = $result['error'];
    }
}

$pageTitle = 'Registrazione - Fantacalcio Asta';
$bodyClass = 'auth-wrapper';
$showNav = false;
require __DIR__ . '/partials/header.php';
?>
<div class="card auth-card shadow">
  <div class="card-body p-4">
    <h3 class="mb-1 text-center">⚽ Registrati</h3>
    <p class="text-center text-dim mb-4">Crea il tuo account per configurare la squadra</p>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="mb-3">
        <label class="form-label">Nickname</label>
        <input type="text" name="nickname" class="form-control" value="<?= e($nickname) ?>" required minlength="3" autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required minlength="6">
      </div>
      <div class="mb-3">
        <label class="form-label">Conferma password</label>
        <input type="password" name="confirm_password" class="form-control" required minlength="6">
      </div>
      <button type="submit" class="btn btn-success w-100">Registrati</button>
    </form>

    <p class="text-center mt-3 mb-0 text-dim">
      Hai già un account? <a href="/login.php">Accedi</a>
    </p>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
