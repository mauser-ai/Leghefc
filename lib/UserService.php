<?php
/**
 * Gestione utenti: registrazione, login, attivazione, ricerca.
 */

declare(strict_types=1);

final class UserService
{
    public static function findByNickname(string $nickname): ?array
    {
        return CsvStorage::findOne(Schema::USERS, Schema::USERS_HEADERS, function ($u) use ($nickname) {
            return strcasecmp($u['nickname'], $nickname) === 0;
        });
    }

    public static function findById(int $id): ?array
    {
        return CsvStorage::findOne(Schema::USERS, Schema::USERS_HEADERS, fn($u) => (int)$u['id'] === $id);
    }

    public static function listAll(): array
    {
        return CsvStorage::readAll(Schema::USERS, Schema::USERS_HEADERS);
    }

    /**
     * Registra un nuovo utente. Restituisce ['ok'=>true,'user'=>...] oppure ['ok'=>false,'error'=>...]
     */
    public static function register(string $nickname, string $password, string $confirm, string $role = 'user'): array
    {
        $nickname = trim($nickname);

        if ($nickname === '' || mb_strlen($nickname) < 3) {
            return ['ok' => false, 'error' => 'Il nickname deve avere almeno 3 caratteri.'];
        }
        if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $nickname)) {
            return ['ok' => false, 'error' => 'Il nickname può contenere solo lettere, numeri, punti, trattini e underscore.'];
        }
        if (strlen($password) < 6) {
            return ['ok' => false, 'error' => 'La password deve avere almeno 6 caratteri.'];
        }
        if ($password !== $confirm) {
            return ['ok' => false, 'error' => 'Le password non coincidono.'];
        }
        if (self::findByNickname($nickname) !== null) {
            return ['ok' => false, 'error' => 'Nickname già registrato.'];
        }

        $created = null;
        CsvStorage::transaction(Schema::USERS, Schema::USERS_HEADERS, function (array $rows) use (&$created, $nickname, $password, $role) {
            // Ricontrolla univocità dentro la transazione per evitare race condition.
            foreach ($rows as $r) {
                if (strcasecmp($r['nickname'], $nickname) === 0) {
                    $created = false;
                    return null; // abort, nessuna scrittura
                }
            }
            $maxId = 0;
            foreach ($rows as $r) {
                $maxId = max($maxId, (int)$r['id']);
            }
            $created = [
                'id' => (string)($maxId + 1),
                'nickname' => $nickname,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => now(),
                'last_login' => '',
                'active' => '1',
                'role' => $role,
            ];
            $rows[] = $created;
            return $rows;
        });

        if ($created === false || $created === null) {
            return ['ok' => false, 'error' => 'Nickname già registrato.'];
        }

        AuditService::log('REGISTER', null, null, null, null, null, $nickname);

        return ['ok' => true, 'user' => $created];
    }

    /**
     * Verifica le credenziali. Restituisce l'utente se valide, altrimenti null.
     */
    public static function login(string $nickname, string $password): ?array
    {
        $user = self::findByNickname($nickname);
        if ($user === null) {
            return null;
        }
        if ((int)$user['active'] !== 1) {
            return null;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }
        return $user;
    }

    public static function updateLastLogin(int $userId): void
    {
        CsvStorage::update(Schema::USERS, Schema::USERS_HEADERS,
            fn($u) => (int)$u['id'] === $userId,
            function ($u) {
                $u['last_login'] = now();
                return $u;
            }
        );
    }

    public static function setActive(int $userId, bool $active): void
    {
        CsvStorage::update(Schema::USERS, Schema::USERS_HEADERS,
            fn($u) => (int)$u['id'] === $userId,
            function ($u) use ($active) {
                $u['active'] = $active ? '1' : '0';
                return $u;
            }
        );
        AuditService::log($active ? 'ACTIVATE_USER' : 'DEACTIVATE_USER', null, null, null, null, null, (string)$userId);
    }
}
