<?php

declare(strict_types=1);

/** Coach App snapshots and transactional, replay-safe offline changes. */
final class RinkOfflineService
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function snapshot(array $user, int $seasonId, int $sessionId): array
    {
        $rink = new RinkService($this->pdo);
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId);
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();
        try {
            // All representations and conflict baselines describe the same database snapshot.
            $roster = $rink->roster((int) $user['club_id'], $seasonId, $sessionId, null, $user['role_code'] !== 'READ_ONLY');
            $assess = $rink->assess((int) $user['club_id'], $seasonId, $sessionId, null, $user['role_code'] !== 'READ_ONLY');
            $profiles = [];
            $versions = [];
            foreach ($roster['skaters'] as $skater) {
                $id = $skater['public_id'];
                $profiles[$id] = $this->profile($user, $id);
                $versions[$id] = [
                    'group' => $this->groupState((int) $user['club_id'], $sessionId, $id),
                    'attendance' => $this->attendanceState((int) $user['club_id'], $sessionId, $id, $roster['attendance']['date']),
                ];
            }
            $zone = $this->pdo->prepare('SELECT time_zone FROM club WHERE id = ?');
            $zone->execute([(int) $user['club_id']]);
            $names = $this->pdo->prepare('SELECT se.name AS season_name, ps.name AS session_name FROM program_session ps JOIN season se ON se.id = ps.season_id WHERE ps.id = ? AND ps.club_id = ?');
            $names->execute([$sessionId, (int) $user['club_id']]);
            $contextNames = $names->fetch() ?: [];
            $result = [
                'season_name' => $contextNames['season_name'] ?? '', 'session_name' => $contextNames['session_name'] ?? '',
                'roster' => $roster, 'assess' => $assess, 'profiles' => $profiles, 'versions' => $versions,
                'downloaded_at' => gmdate('c'), 'time_zone' => $zone->fetchColumn() ?: 'America/Toronto',
                'user_id' => (int) $user['id'], 'club_id' => (int) $user['club_id'],
                'season_id' => $seasonId, 'session_id' => $sessionId, 'csrf_token' => csrf_token(),
            ];
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function groupState(int $clubId, int $sessionId, string $publicId): array
    {
        $query = $this->pdo->prepare('SELECT e.id AS enrollment_id, ga.id AS revision, ga.program_group_id AS group_id,
                pg.name AS group_name, pg.colour_hex AS group_colour
            FROM skater_enrollment e JOIN skater s ON s.id = e.skater_id
            LEFT JOIN group_assignment ga ON ga.id = (SELECT MAX(g.id) FROM group_assignment g WHERE g.skater_enrollment_id = e.id)
            LEFT JOIN program_group pg ON pg.id = ga.program_group_id
            WHERE s.club_id = ? AND s.public_id = ? AND e.program_session_id = ? AND e.active = 1 AND e.deleted_at IS NULL
            ORDER BY e.id');
        $query->execute([$clubId, $publicId, $sessionId]);
        return $query->fetchAll();
    }

    private function attendanceState(int $clubId, int $sessionId, string $publicId, string $date): array
    {
        $query = $this->pdo->prepare('SELECT a.id, a.sync_revision AS revision, ast.code AS status
            FROM attendance a JOIN program_date pd ON pd.id = a.program_date_id
            JOIN skater s ON s.id = a.skater_id JOIN attendance_status ast ON ast.id = a.attendance_status_id
            WHERE s.club_id = ? AND s.public_id = ? AND pd.program_session_id = ? AND pd.session_date = ?');
        $query->execute([$clubId, $publicId, $sessionId, $date]);
        return $query->fetch() ?: ['id' => null, 'revision' => 0, 'status' => ''];
    }

    public function sync(array $user, array $op): array
    {
        if (!in_array($user['role_code'] ?? '', ['ADMINISTRATOR', 'REGISTRAR', 'COACH'], true)) {
            throw new InvalidArgumentException('This account has read-only access.');
        }
        $clubId = (int) $user['club_id'];
        $userId = (int) $user['id'];
        if ((int) ($op['user_id'] ?? 0) !== $userId || (int) ($op['club_id'] ?? 0) !== $clubId) {
            throw new InvalidArgumentException('Sign in with the account that recorded these changes.');
        }
        $id = (string) ($op['id'] ?? '');
        $kind = (string) ($op['kind'] ?? '');
        $publicId = (string) ($op['skater_id'] ?? '');
        $seasonId = (int) ($op['season_id'] ?? 0);
        $sessionId = (int) ($op['session_id'] ?? 0);
        if (!preg_match('/^[a-f0-9-]{36}$/D', $id) || !in_array($kind, ['group', 'attendance', 'skills', 'ribbons', 'badges'], true)) {
            throw new InvalidArgumentException('Invalid offline change.');
        }
        $rink = new RinkService($this->pdo);
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId, $publicId);
        $occurred = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', (string) ($op['occurred_at'] ?? ''), new DateTimeZone('UTC'));
        if (!$occurred || $occurred->format('Y-m-d\TH:i:s\Z') !== ($op['occurred_at'] ?? '') || $occurred->getTimestamp() > time() + 300) {
            throw new InvalidArgumentException('The change has an invalid date. Check the device clock.');
        }
        $zone = $this->pdo->prepare('SELECT time_zone FROM club WHERE id = ?');
        $zone->execute([$clubId]);
        $date = $occurred->setTimezone(new DateTimeZone($zone->fetchColumn() ?: 'America/Toronto'))->format('Y-m-d');
        if ($kind === 'attendance' && ($op['date'] ?? '') !== $date) {
            throw new InvalidArgumentException('The attendance date does not match the date this change was recorded.');
        }
        $this->pdo->beginTransaction();
        try {
            // Serialize replay checks and achievement operations for this skater.
            $lock = $this->pdo->prepare('SELECT id FROM skater WHERE club_id = ? AND public_id = ? FOR UPDATE');
            $lock->execute([$clubId, $publicId]);
            $skaterId = (int) $lock->fetchColumn();
            $receipt = $this->pdo->prepare('SELECT response_json FROM rink_offline_change WHERE club_id = ? AND app_user_id = ? AND operation_id = ? FOR UPDATE');
            $receipt->execute([$clubId, $userId, $id]);
            $saved = $receipt->fetchColumn();
            if ($saved !== false) {
                $this->pdo->commit();
                return json_decode((string) $saved, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($kind === 'group' || $kind === 'attendance') {
                // Group writers also lock enrollment; attendance writers lock the program date.
                $lock = $this->pdo->prepare('SELECT id FROM skater_enrollment WHERE skater_id = ? AND program_session_id = ? FOR UPDATE');
                $lock->execute([$skaterId, $sessionId]);
                if ($kind === 'attendance') {
                    $lock = $this->pdo->prepare('SELECT id FROM program_date WHERE program_session_id = ? AND session_date = ? FOR UPDATE');
                    $lock->execute([$sessionId, $date]);
                }
                $current = $kind === 'group' ? $this->groupState($clubId, $sessionId, $publicId) : $this->attendanceState($clubId, $sessionId, $publicId, $date);
                if (!isset($op['expected']) || $current != $op['expected']) {
                    $this->pdo->rollBack();
                    return ['conflict' => true, 'current' => $current, 'message' => 'Another coach changed this record. Review the server value before choosing which change to keep.'];
                }
            }
            $result = [];
            if ($kind === 'group') {
                $result = $rink->assignGroup($clubId, $userId, $seasonId, $sessionId, $publicId,
                    isset($op['group_id']) ? (int) $op['group_id'] : null,
                    $op['group_name'] ?? null, $op['group_colour'] ?? null);
            } elseif ($kind === 'attendance') {
                if (!isset($op['present']) || !is_bool($op['present'])) throw new InvalidArgumentException('Invalid attendance value.');
                $result = $rink->recordAttendance($clubId, $userId, $seasonId, $sessionId, $publicId, $op['present'], $date);
            } else {
                $targetId = (int) ($op['target_id'] ?? 0);
                if ($targetId < 1) throw new InvalidArgumentException('Invalid achievement.');
                $tables = ['skills' => ['skater_skill', 'canskate_skill_id'], 'ribbons' => ['skater_ribbon', 'canskate_ribbon_id'], 'badges' => ['skater_badge', 'canskate_stage_id']];
                [$table, $column] = $tables[$kind];
                $existing = $this->pdo->prepare("SELECT id FROM $table WHERE skater_id = ? AND $column = ?" . ($kind === 'skills' ? '' : ' AND revoked_at IS NULL') . ' FOR UPDATE');
                $existing->execute([$skaterId, $targetId]);
                if ($existing->fetchColumn() === false) {
                    $skaters = new SkaterService($this->pdo);
                    if ($kind === 'skills') {
                        $result = $skaters->markSkillAchieved($clubId, $userId, $publicId, $targetId);
                        foreach (array_merge([$targetId], $result['automatically_completed_skill_ids'] ?? []) as $skillId) {
                            $update = $this->pdo->prepare('UPDATE skater_skill SET achievement_date = ? WHERE skater_id = ? AND canskate_skill_id = ?');
                            $update->execute([$date, $skaterId, $skillId]);
                            $history = $this->pdo->prepare('UPDATE assessment_history SET assessed_at = ? WHERE id = (SELECT assessment_history_id FROM skater_skill WHERE skater_id = ? AND canskate_skill_id = ?)');
                            $history->execute([$occurred->format('Y-m-d H:i:s'), $skaterId, $skillId]);
                        }
                    } else {
                        $result = $kind === 'ribbons' ? $skaters->awardRibbon($clubId, $userId, $publicId, $targetId) : $skaters->awardStageBadge($clubId, $userId, $publicId, $targetId);
                        $update = $this->pdo->prepare("UPDATE $table SET awarded_at = ? WHERE skater_id = ? AND $column = ?");
                        $update->execute([$occurred->format('Y-m-d H:i:s'), $skaterId, $targetId]);
                    }
                }
            }
            $response = ['synced' => true, 'result' => $result];
            $insert = $this->pdo->prepare('INSERT INTO rink_offline_change (club_id, app_user_id, operation_id, response_json) VALUES (?, ?, ?, ?)');
            $insert->execute([$clubId, $userId, $id, json_encode($response, JSON_THROW_ON_ERROR)]);
            $this->pdo->commit();
            return $response;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
    public function profile(array $user, string $publicId): array
    {
        $detail = (new DashboardRepository($this->pdo))->skaterDetail(
            (int) $user['club_id'],
            $publicId
        );
        if ($detail === null) {
            throw new InvalidArgumentException('Skater not found.');
        }
        $today = date('Y-m-d');
        $timestampIsToday = static function (?string $value) use ($today): bool {
            if ($value === null || $value === '') {
                return false;
            }
            try {
                return (new DateTimeImmutable($value))
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d') === $today;
            } catch (Exception $exception) {
                return false;
            }
        };
        foreach ($detail['achievement_editor'] as &$stage) {
            foreach ($stage['ribbons'] as &$ribbon) {
                foreach ($ribbon['skills'] as &$skill) {
                    $skill['removable_today'] = !$skill['achieved']
                        || $skill['achievement_date'] === $today;
                }
                unset($skill);
                $ribbon['removable_today'] = $ribbon['awarded_at'] === null
                    || $timestampIsToday($ribbon['awarded_at']);
            }
            unset($ribbon);
            $stage['badge_removable_today'] = $stage['badge_awarded_at'] === null
                || $timestampIsToday($stage['badge_awarded_at']);
        }
        unset($stage);
        $canEditRink = in_array($user['role_code'] ?? '', ['ADMINISTRATOR', 'REGISTRAR', 'COACH'], true);
        $canViewSensitive = ($user['role_code'] ?? '') !== 'READ_ONLY';
        if (!$canViewSensitive) {
            $detail['skater']['parent_guardian_name'] = null;
            $detail['skater']['parent_guardian_email'] = null;
            $detail['skater']['parent_guardian_phone'] = null;
            $detail['skater']['general_notes'] = null;
            $detail['skater']['medical_notes'] = null;
            $detail['skater']['report_card_notes'] = null;
        }
        $detail['permissions'] = [
            'can_edit' => false,
            'can_view_sensitive' => $canViewSensitive,
            'can_edit_achievements' => $canEditRink,
        ];
        $detail['csrf_token'] = csrf_token();
        return $detail;
    }

}
