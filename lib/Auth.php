<?php
/**
 * Helper di autenticazione basati su sessione PHP.
 */

declare(strict_types=1);

final class Auth
{
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['nickname'] = $user['nickname'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['authenticated'] = true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function isAuthenticated(): bool
    {
        return !empty($_SESSION['authenticated']) && !empty($_SESSION['user_id']);
    }

    public static function isAdmin(): bool
    {
        return self::isAuthenticated() && ($_SESSION['role'] ?? '') === 'admin';
    }

    public static function userId(): ?int
    {
        return self::isAuthenticated() ? (int)$_SESSION['user_id'] : null;
    }

    public static function nickname(): ?string
    {
        return $_SESSION['nickname'] ?? null;
    }

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    /**
     * Reindirizza al login se l'utente non è autenticato.
     */
    public static function requireLogin(): void
    {
        if (!self::isAuthenticated()) {
            redirect('/login.php');
        }
    }

    /**
     * Reindirizza (o risponde 403 in contesto API) se l'utente non è admin.
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            echo 'Accesso riservato agli amministratori.';
            exit;
        }
    }

    /**
     * Variante per endpoint API: risponde con JSON 401/403 invece di redirect.
     */
    public static function apiRequireLogin(): void
    {
        if (!self::isAuthenticated()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Non autenticato']);
            exit;
        }
    }

    public static function apiRequireAdmin(): void
    {
        self::apiRequireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Accesso riservato agli amministratori']);
            exit;
        }
    }
}

function requireLogin(): void
{
    Auth::requireLogin();
}

function requireAdmin(): void
{
    Auth::requireAdmin();
}
