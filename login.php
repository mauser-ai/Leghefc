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

    $user = UserService::login($nickname, $password);
    if ($user !== null) {
        Auth::login($user);
        UserService::updateLastLogin((int)$user['id']);
        AuditService::log('LOGIN', null, null, null, null, null, $user['nickname']);

        $team = TeamService::getTeamByUser((int)$user['id']);
        redirect($team === null ? '/profile.php?first=1' : '/dashboard.php');
    } else {
        $error = 'Nickname o password non validi, oppure account disattivato.';
    }
}

$pageTitle = 'Login - Fantacalcio Asta';
$bodyClass = 'auth-wrapper';
$showNav = false;
require __DIR__ . '/partials/header.php';
?>
<div class="card auth-card shadow">
  <div class="card-body p-4">
    <h3 class="mb-1 text-center">⚽ Accedi</h3>
    <p class="text-center text-dim mb-4">Fantacalcio Asta Manager</p>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="mb-3">
        <label class="form-label">Nickname</label>
        <input type="text" name="nickname" class="form-control" value="<?= e($nickname) ?>" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary w-100">Accedi</button>
    </form>

    <p class="text-center mt-3 mb-0 text-dim">
      Non hai un account? <a href="/register.php">Registrati</a>
    </p>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
