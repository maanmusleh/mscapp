<?php

declare(strict_types=1);

final class Auth
{
    private const MAX_TOTP_ATTEMPTS = 5;
    private const DUMMY_PASSWORD_HASH = '$2y$10$vWBOFVUKFH32xtdJ0pVtGuTOnrREn71/YelvqTzgt0VJFdNK3xjjK';

    public static function enforceSessionLifetime(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $hasSecurityContext = is_array($_SESSION['auth_user'] ?? null)
            || filter_var($_SESSION['pending_totp_user_id'] ?? null, FILTER_VALIDATE_INT) !== false;
        if (!$hasSecurityContext) {
            return;
        }

        $now = time();
        $idleLifetime = max(300, (int) config('session.lifetime_seconds', 3600));
        $absoluteLifetime = max($idleLifetime, (int) config('session.absolute_lifetime_seconds', 43200));
        $lastActivity = filter_var($_SESSION['last_activity_at'] ?? null, FILTER_VALIDATE_INT);
        $startedAt = filter_var($_SESSION['session_started_at'] ?? null, FILTER_VALIDATE_INT);
        $expired = ($lastActivity !== false && $lastActivity < $now - $idleLifetime)
            || ($startedAt !== false && $startedAt < $now - $absoluteLifetime);
        if ($expired) {
            $_SESSION = [];
            session_regenerate_id(true);
            flash('warning', 'Your session expired. Please sign in again.');
            return;
        }

        $_SESSION['session_started_at'] = $startedAt === false ? $now : $startedAt;
        $_SESSION['last_activity_at'] = $now;
    }

    public static function user(): ?array
    {
        $user = $_SESSION['auth_user'] ?? null;

        if (!is_array($user)) {
            return null;
        }

        $freshUser = self::reloadUser($user);
        if ($freshUser !== null) {
            $_SESSION['auth_user'] = $freshUser;

            return $freshUser;
        }

        return self::normalizeUserRecord($user);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            flash('warning', 'Please sign in to continue.');
            redirect('login');
        }

        return $user;
    }

    public static function requireRole(array $roleCodes): array
    {
        $user = self::requireLogin();
        $allowedRoles = array_map([self::class, 'normalizeRoleCode'], $roleCodes);

        if (!in_array($user['role_code'], $allowedRoles, true)) {
            http_response_code(403);
            render('forbidden', ['user' => $user]);
        }

        return $user;
    }

    public static function hasRole(string ...$roleCodes): bool
    {
        $user = self::user();

        return $user !== null
            && in_array($user['role_code'], array_map([self::class, 'normalizeRoleCode'], $roleCodes), true);
    }

    public static function attempt(string $identity, string $password): bool
    {
        unset($_SESSION['login_failure_message']);
        $identity = trim($identity);
        LoginActivityService::assertPasswordAttemptAllowed($identity);
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT
                u.id,
                u.public_id,
                c.id AS club_id,
                u.username,
                u.email,
                u.password_hash,
                u.first_name,
                u.last_name,
                u.must_change_password,
                u.password_failed_attempts,
                u.password_failure_window_started_at,
                u.password_short_lock_issued_at,
                u.password_lock_until,
                u.totp_secret_ciphertext,
                u.totp_enabled_at,
                u.coach_id,
                r.code AS role_code,
                r.name AS role_name,
                c.public_id AS club_public_id,
                c.club_name,
                c.totp_policy
             FROM app_user u
             INNER JOIN user_role r ON r.id = u.user_role_id AND r.active = 1
             INNER JOIN (
                SELECT id, public_id, club_name, totp_policy
                FROM club
                WHERE active = 1 AND deleted_at IS NULL
                ORDER BY id
                LIMIT 1
             ) c
             WHERE (u.username = :username_identity OR u.email = :email_identity)
               AND u.active = 1
               AND u.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'username_identity' => trim($identity),
            'email_identity' => trim($identity),
        ]);
        $record = $statement->fetch();

        if (!is_array($record)) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            self::recordUnknownPasswordFailure($identity);
            return false;
        }

        if (self::hasActivePasswordLock($record)) {
            LoginActivityService::record('password_blocked', (int) $record['id'], $identity);
            throw new RuntimeException('Sign-in is temporarily unavailable. Please wait ten minutes and try again.');
        }

        if (!password_verify($password, (string) $record['password_hash'])) {
            LoginActivityService::record('password_failed', (int) $record['id'], $identity);
            $_SESSION['login_failure_message'] = 'The sign-in credentials were not recognized.';
            return false;
        }

        if (password_needs_rehash((string) $record['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $pdo->prepare('UPDATE app_user SET password_hash = :hash WHERE id = :id');
            $rehash->execute([
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $record['id'],
            ]);
        }

        $totpPolicy = $record['totp_policy'] ?? 'OPTIONAL';
        if ($totpPolicy !== 'UNAVAILABLE' && !empty($record['totp_secret_ciphertext'])) {
            session_regenerate_id(true);
            $_SESSION['pending_totp_user_id'] = (int) $record['id'];
            $_SESSION['totp_attempts'] = [];
            $_SESSION['last_activity_at'] = time();
            $_SESSION['session_started_at'] = time();
            csrf_token();

            return true;
        }

        self::completeAuthentication($record);
        if ($totpPolicy === 'REQUIRED') $_SESSION['totp_enrollment_required'] = true;

        return true;
    }

    public static function takeLoginFailureMessage(): string
    {
        $message = $_SESSION['login_failure_message'] ?? 'The sign-in credentials were not recognized.';
        unset($_SESSION['login_failure_message']);

        return is_string($message) ? $message : 'The sign-in credentials were not recognized.';
    }

    public static function totpPending(): bool
    {
        return filter_var($_SESSION['pending_totp_user_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) !== false;
    }

    public static function completeTotp(string $code): bool
    {
        $userId = filter_var($_SESSION['pending_totp_user_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($userId === false) return false;

        LoginActivityService::assertTotpAttemptAllowed((int) $userId);

        $attempts = array_values(array_filter(
            $_SESSION['totp_attempts'] ?? [],
            static fn (int $timestamp): bool => $timestamp >= time() - 600
        ));
        if (count($attempts) >= self::MAX_TOTP_ATTEMPTS) {
            LoginActivityService::record('totp_blocked', (int) $userId);
            throw new RuntimeException('Too many authentication-code attempts. Please sign in again in ten minutes.');
        }

        $statement = Database::connection()->prepare(
            'SELECT totp_secret_ciphertext FROM app_user
             WHERE id = :id AND active = 1 AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $ciphertext = $statement->fetchColumn();
        if (!is_string($ciphertext) || $ciphertext === '') {
            self::clearPendingTotp();
            return false;
        }
        if (!(new TotpService())->verify((new TotpService())->decrypt($ciphertext), $code)) {
            LoginActivityService::record('totp_failed', (int) $userId);
            $attempts[] = time();
            $_SESSION['totp_attempts'] = $attempts;
            return false;
        }

        $user = self::reloadUser(['id' => $userId]);
        if ($user === null) {
            self::clearPendingTotp();
            return false;
        }
        self::clearPendingTotp();
        self::completeAuthentication($user);

        return true;
    }

    public static function cancelTotp(): void
    {
        self::clearPendingTotp();
    }

    private static function completeAuthentication(array $record): void
    {
        session_regenerate_id(true);
        $_SESSION['auth_user'] = self::normalizeUserRecord($record);
        unset($_SESSION['login_failure_message']);
        $_SESSION['last_activity_at'] = time();
        $_SESSION['session_started_at'] = time();
        csrf_token();

        $update = Database::connection()->prepare(
            'UPDATE app_user
             SET last_login_at = UTC_TIMESTAMP(),
                 password_failed_attempts = 0,
                 password_failure_window_started_at = NULL,
                 password_short_lock_issued_at = NULL,
                 password_lock_until = NULL
             WHERE id = :id'
        );
        $update->execute(['id' => $record['id']]);
        LoginActivityService::record('login_success', (int) $record['id']);

    }

    private static function clearPendingTotp(): void
    {
        unset($_SESSION['pending_totp_user_id'], $_SESSION['totp_attempts']);
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $parameters['path'],
                $parameters['domain'],
                $parameters['secure'],
                $parameters['httponly']
            );
        }

        session_destroy();
    }

    private static function recordUnknownPasswordFailure(string $identity): void
    {
        LoginActivityService::record('password_failed', null, $identity);
        $_SESSION['login_failure_message'] = 'The sign-in credentials were not recognized.';
    }

    private static function hasActivePasswordLock(array $record): bool
    {
        $lockUntil = trim((string) ($record['password_lock_until'] ?? ''));

        return $lockUntil !== '' && strtotime($lockUntil . ' UTC') > time();
    }

    private static function reloadUser(array $user): ?array
    {
        $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($userId === false) {
            return null;
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT
                u.id,
                u.public_id,
                c.id AS club_id,
                u.username,
                u.email,
                u.password_hash,
                u.first_name,
                u.last_name,
                u.must_change_password,
                u.totp_enabled_at,
                u.coach_id,
                r.code AS role_code,
                r.name AS role_name,
                c.public_id AS club_public_id,
                c.club_name
             FROM app_user u
             INNER JOIN user_role r ON r.id = u.user_role_id AND r.active = 1
             INNER JOIN (
                SELECT id, public_id, club_name
                FROM club
                WHERE active = 1 AND deleted_at IS NULL
                ORDER BY id
                LIMIT 1
             ) c
             WHERE u.id = :id
               AND u.active = 1
               AND u.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $record = $statement->fetch();

        return is_array($record) ? self::normalizeUserRecord($record) : null;
    }

    private static function normalizeUserRecord(array $record): array
    {
        if (isset($record['role_code'])) {
            $record['role_code'] = self::normalizeRoleCode((string) $record['role_code']);
        }

        return $record;
    }

    private static function normalizeRoleCode(string $roleCode): string
    {
        $normalized = strtoupper(trim($roleCode));

        switch ($normalized) {
            case 'ADMIN':
            case 'ADMINISTRATOR':
            case 'ADMINISTRATOR_ROLE': return 'ADMINISTRATOR';
            case 'REGISTRAR':
            case 'REGISTRAR_ROLE': return 'REGISTRAR';
            case 'READ_ONLY':
            case 'READONLY':
            case 'VIEW_ONLY': return 'READ_ONLY';
            case 'COACH': return 'COACH';
            default: return $normalized;
        }
    }
}
