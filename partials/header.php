<?php
/**
 * Header HTML condiviso. Variabili opzionali attese dalla pagina chiamante:
 * $pageTitle (string), $bodyClass (string), $extraHead (string HTML)
 */
$pageTitle = $pageTitle ?? 'Fantacalcio Asta Manager';
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= e($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="/assets/css/style.css" rel="stylesheet">
<?= $extraHead ?? '' ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?php if (!empty($showNav)): ?>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="/dashboard.php">⚽ Fantacalcio Asta</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="/dashboard.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="/profile.php">Il mio Team</a></li>
        <li class="nav-item"><a class="nav-link" href="/join-auction.php">Entra in asta</a></li>
        <?php if (Auth::isAdmin()): ?>
        <li class="nav-item"><a class="nav-link" href="/admin/index.php">Admin</a></li>
        <?php endif; ?>
      </ul>
      <span class="navbar-text me-3">👤 <?= e(Auth::nickname() ?? '') ?></span>
      <a href="/logout.php" class="btn btn-outline-light btn-sm">Esci</a>
    </div>
  </div>
</nav>
<?php endif; ?>
