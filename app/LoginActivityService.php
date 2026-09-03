<?php

declare(strict_types=1);

final class LoginActivityService
{
    private const MAX_PASSWORD_FAILURES_PER_IDENTITY_AND_IP = 5;
    private const MAX_PASSWORD_FAILURES_PER_IP = 30;
    private const MAX_TOTP_FAILURES_PER_USER_AND_IP = 5;

    public static function record(string $eventType, ?int $userId = null, ?string $identity = null, ?int $actorUserId = null): void
    {
        self::pruneExpired();
        $statement = Database::connection()->prepare(
            'INSERT INTO user_login_activity (app_user_id, actor_user_id, event_type, attempted_identity, source_ip)
             VALUES (:app_user_id, :actor_user_id, :event_type, :attempted_identity, :source_ip)'
        );
        $statement->execute([
            'app_user_id' => $userId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'attempted_identity' => $identity === null ? null : mb_substr(trim($identity), 0, 254),
            'source_ip' => self::sourceIp(),
        ]);
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, page: int, per_page: int, total: int, pages: int}
     */
    public function page(int $page = 1, int $perPage = 25): array
    {
        self::pruneExpired();
        $perPage = max(1, min(100, $perPage));
        $total = (int) Database::connection()->query('SELECT COUNT(*) FROM user_login_activity')->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $perPage;
        $statement = Database::connection()->prepare(
            'SELECT activity.event_type, activity.attempted_identity, activity.source_ip, activity.created_at,
                    user.first_name AS user_first_name, user.last_name AS user_last_name, user.username AS user_username,
                    actor.first_name AS actor_first_name, actor.last_name AS actor_last_name, actor.username AS actor_username
             FROM user_login_activity activity
             LEFT JOIN app_user user ON user.id = activity.app_user_id
             LEFT JOIN app_user actor ON actor.id = activity.actor_user_id
             ORDER BY activity.created_at DESC, activity.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'entries' => $statement->fetchAll(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
        ];
    }

    public static function assertPasswordAttemptAllowed(string $identity): void
    {
        self::pruneExpired();
        $identity = mb_strtolower(mb_substr(trim($identity), 0, 254));
        $ip = self::sourceIp();
        if ($identity === '' || $ip === null) {
            return;
        }

        $statement = Database::connection()->prepare(
            'SELECT
                SUM(CASE WHEN attempted_identity = :identity THEN 1 ELSE 0 END) AS identity_ip_failures,
                COUNT(*) AS ip_failures
             FROM user_login_activity
             WHERE event_type = \'password_failed\'
               AND source_ip = :source_ip
               AND created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE'
        );
        $statement->execute(['identity' => $identity, 'source_ip' => $ip]);
        $failures = $statement->fetch();
        $identityIpFailures = (int) ($failures['identity_ip_failures'] ?? 0);
        $ipFailures = (int) ($failures['ip_failures'] ?? 0);
        if (
            $identityIpFailures >= self::MAX_PASSWORD_FAILURES_PER_IDENTITY_AND_IP
            || $ipFailures >= self::MAX_PASSWORD_FAILURES_PER_IP
        ) {
            self::record('login_throttled', null, $identity);
            throw new RuntimeException(
                'Too many unsuccessful sign-in attempts from this network. Please wait ten minutes and try again.'
            );
        }
    }

    public static function assertTotpAttemptAllowed(int $userId): void
    {
        self::pruneExpired();
        $ip = self::sourceIp();
        if ($userId <= 0 || $ip === null) {
            return;
        }
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM user_login_activity
             WHERE event_type = \'totp_failed\'
               AND app_user_id = :user_id
               AND source_ip = :source_ip
               AND created_at >= UTC_TIMESTAMP() - INTERVAL 10 MINUTE'
        );
        $statement->execute(['user_id' => $userId, 'source_ip' => $ip]);
        if ((int) $statement->fetchColumn() >= self::MAX_TOTP_FAILURES_PER_USER_AND_IP) {
            self::record('totp_blocked', $userId);
            throw new RuntimeException(
                'Too many authentication-code attempts from this network. Please wait ten minutes and sign in again.'
            );
        }
    }

    private static function pruneExpired(): void
    {
        Database::connection()->exec('DELETE FROM user_login_activity WHERE created_at < CURRENT_TIMESTAMP - INTERVAL 90 DAY');
    }

    private static function sourceIp(): ?string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        return $ip === '' ? null : mb_substr($ip, 0, 45);
    }
}
