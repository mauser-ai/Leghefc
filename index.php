<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (Auth::isAuthenticated()) {
    redirect('/dashboard.php');
}
redirect('/login.php');
