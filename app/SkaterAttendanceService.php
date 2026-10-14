<?php

declare(strict_types=1);

final class SkaterAttendanceService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function detail(int $clubId, string $publicId, ?DateTimeImmutable $now = null): array
    {
        $sessions = $this->pdo->prepare(
            'SELECT DISTINCT ps.id AS session_id, ps.name AS session_name,
                se.id AS season_id, se.name AS season_name, se.start_date, se.end_date
             FROM skater_enrollment e
             INNER JOIN skater s ON s.id = e.skater_id
             INNER JOIN program_session ps ON ps.id = e.program_session_id AND ps.club_id = s.club_id
             INNER JOIN season se ON se.id = ps.season_id AND se.club_id = s.club_id AND se.active = 1
             WHERE s.club_id = :club_id AND s.public_id = :public_id AND s.deleted_at IS NULL
               AND e.deleted_at IS NULL AND ps.deleted_at IS NULL AND se.deleted_at IS NULL
             ORDER BY se.start_date DESC, se.id DESC, ps.name, ps.id'
        );
        $sessions->execute(['club_id' => $clubId, 'public_id' => $publicId]);
        $sessions = $sessions->fetchAll();
        $seasonOptions = $this->pdo->prepare(
            'SELECT id, name, start_date, end_date FROM season
             WHERE club_id = :club_id AND active = 1 AND deleted_at IS NULL
             ORDER BY start_date DESC, id DESC'
        );
        $seasonOptions->execute(['club_id' => $clubId]);
        $seasons = $seasonOptions->fetchAll();
        foreach ($seasons as &$season) {
            $season['id'] = (int) $season['id'];
        }
        unset($season);
        $zone = $this->pdo->prepare('SELECT time_zone FROM club WHERE id = ?');
        $zone->execute([$clubId]);
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone(
            new DateTimeZone((string) ($zone->fetchColumn() ?: 'America/Toronto'))
        )->format('Y-m-d');
        $defaultSeasonId = null;
        $latestRegisteredSeasonId = null;
        $summary = [];
        foreach ($sessions as $session) {
            $seasonId = (int) $session['season_id'];
            $sessionId = (int) $session['session_id'];
            if ($latestRegisteredSeasonId === null) $latestRegisteredSeasonId = $seasonId;
            if ($defaultSeasonId === null && $session['start_date'] <= $today && $session['end_date'] >= $today) {
                $defaultSeasonId = $seasonId;
            }
            $summary[$sessionId] = [
                'session_id' => $sessionId, 'session_name' => $session['session_name'],
                'season_id' => $seasonId, 'season_name' => $session['season_name'],
                'present' => 0, 'recorded' => 0, 'rate' => null,
            ];
        }
        $defaultSeasonId = $defaultSeasonId ?? $latestRegisteredSeasonId;

        // Include every day attendance was taken, even if this skater has no entry.
        $records = $this->pdo->prepare(
            'SELECT pd.id AS program_date_id, pd.session_date, pd.cancelled,
                ps.id AS session_id, ps.name AS session_name,
                se.id AS season_id, se.name AS season_name,
                a.id AS attendance_id, COALESCE(a.sync_revision, 0) AS revision,
                COALESCE(ast.counts_as_present, 0) AS present,
                ast.code AS status_code, ast.name AS status_name
             FROM program_date pd
             INNER JOIN program_session ps ON ps.id = pd.program_session_id
             INNER JOIN season se ON se.id = ps.season_id AND se.club_id = ps.club_id AND se.active = 1
             INNER JOIN skater s ON s.club_id = ps.club_id AND s.public_id = :public_id AND s.deleted_at IS NULL
             LEFT JOIN attendance a ON a.program_date_id = pd.id AND a.skater_id = s.id
             LEFT JOIN attendance_status ast ON ast.id = a.attendance_status_id
             WHERE ps.club_id = :club_id AND ps.deleted_at IS NULL AND se.deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM skater_enrollment e
                   WHERE e.skater_id = s.id AND e.program_session_id = ps.id AND e.deleted_at IS NULL)
               AND EXISTS (SELECT 1 FROM attendance taken WHERE taken.program_date_id = pd.id)
             ORDER BY pd.session_date DESC, ps.name, pd.id DESC'
        );
        $records->execute(['club_id' => $clubId, 'public_id' => $publicId]);
        $records = $records->fetchAll();
        foreach ($records as &$record) {
            foreach (['program_date_id', 'session_id', 'season_id', 'revision'] as $key) {
                $record[$key] = (int) $record[$key];
            }
            $record['present'] = (bool) $record['present'];
            $record['cancelled'] = (bool) $record['cancelled'];
            $summary[$record['session_id']]['recorded']++;
            $summary[$record['session_id']]['present'] += $record['present'] ? 1 : 0;
        }
        unset($record);
        foreach ($summary as &$session) {
            if ($session['recorded'] > 0) {
                $session['rate'] = (int) round($session['present'] / $session['recorded'] * 100);
            }
        }
        unset($session);
        return [
            'attendance_seasons' => $seasons,
            'attendance_default_season_id' => $defaultSeasonId,
            'attendance' => array_values($summary),
            'attendance_records' => $records,
        ];
    }

    public function update(int $clubId, int $userId, string $publicId, int $programDateId, bool $present, int $expectedRevision): void
    {
        $this->pdo->beginTransaction();
        try {
            // Lock the registration before the date, matching the offline attendance writer.
            $registration = $this->pdo->prepare(
                'SELECT s.id AS skater_id
                 FROM skater s
                 INNER JOIN skater_enrollment e ON e.skater_id = s.id AND e.deleted_at IS NULL
                 INNER JOIN program_session ps ON ps.id = e.program_session_id AND ps.club_id = s.club_id
                 INNER JOIN season se ON se.id = ps.season_id AND se.club_id = s.club_id AND se.active = 1
                 INNER JOIN program_date pd ON pd.program_session_id = ps.id
                 WHERE s.club_id = :club_id AND s.public_id = :public_id AND s.deleted_at IS NULL
                   AND ps.deleted_at IS NULL AND se.deleted_at IS NULL AND pd.id = :date_id
                 LIMIT 1 FOR UPDATE'
            );
            $registration->execute(['club_id' => $clubId, 'public_id' => $publicId, 'date_id' => $programDateId]);
            $skaterId = $registration->fetchColumn();
            if ($skaterId === false) {
                throw new InvalidArgumentException('This attendance day is not in a session registered to this skater.');
            }
            $day = $this->pdo->prepare('SELECT cancelled FROM program_date WHERE id = ? FOR UPDATE');
            $day->execute([$programDateId]);
            if ((int) $day->fetchColumn() === 1) {
                throw new InvalidArgumentException('Attendance cannot be edited for a cancelled session date.');
            }
            $taken = $this->pdo->prepare('SELECT id FROM attendance WHERE program_date_id = ? LIMIT 1');
            $taken->execute([$programDateId]);
            if ($taken->fetchColumn() === false) {
                throw new InvalidArgumentException('Attendance has not been taken for this session date.');
            }
            $current = $this->pdo->prepare('SELECT sync_revision FROM attendance WHERE program_date_id = ? AND skater_id = ? FOR UPDATE');
            $current->execute([$programDateId, (int) $skaterId]);
            if ((int) ($current->fetchColumn() ?: 0) !== $expectedRevision) {
                throw new InvalidArgumentException('This attendance record was changed by another user. Reopen the skater record and try again.');
            }
            $status = $this->pdo->prepare('SELECT id FROM attendance_status WHERE code = ? AND active = 1');
            $status->execute([$present ? 'PRESENT' : 'ABSENT']);
            $statusId = $status->fetchColumn();
            if ($statusId === false) {
                throw new RuntimeException('Attendance reference data is incomplete.');
            }
            $save = $this->pdo->prepare(
                'INSERT INTO attendance (program_date_id, skater_id, attendance_status_id, recorded_by_user_id, updated_by_user_id)
                 VALUES (:date_id, :skater_id, :status_id, :user_id, :updated_user_id)
                 ON DUPLICATE KEY UPDATE
                    sync_revision = sync_revision + 1,
                    attendance_status_id = VALUES(attendance_status_id),
                    recorded_by_user_id = VALUES(recorded_by_user_id),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    recorded_at = UTC_TIMESTAMP()'
            );
            $save->execute([
                'date_id' => $programDateId, 'skater_id' => (int) $skaterId, 'status_id' => (int) $statusId,
                'user_id' => $userId, 'updated_user_id' => $userId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }
}
