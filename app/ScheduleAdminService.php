<?php

declare(strict_types=1);

final class ScheduleAdminService
{
    private const RINKS_KEY = 'rinks';
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function data(int $clubId, ?int $selectedSeasonId = null): array
    {
        $statuses = $this->pdo->query('SELECT id, name FROM season_status WHERE active = 1 ORDER BY display_order, name')->fetchAll();
        $seasons = $this->pdo->prepare('SELECT id, name, NULL AS season_year, NULL AS season_term_id, season_status_id, start_date, end_date, active FROM season WHERE club_id = :club_id AND deleted_at IS NULL ORDER BY CASE WHEN end_date >= CURDATE() THEN 0 ELSE 1 END, CASE WHEN end_date >= CURDATE() THEN start_date END DESC, CASE WHEN end_date < CURDATE() THEN end_date END DESC, name');
        $seasons->execute(['club_id' => $clubId]);
        $sessionSql = 'SELECT ps.id, ps.season_id, ps.sku, ps.name, ps.day_of_week, ps.start_time, ps.end_time, ps.location, se.name AS season_name FROM program_session ps INNER JOIN season se ON se.id = ps.season_id WHERE ps.club_id = :club_id AND ps.deleted_at IS NULL';
        $sessionParams = ['club_id' => $clubId];
        if ($selectedSeasonId !== null) {
            $sessionSql .= ' AND ps.season_id = :season_id';
            $sessionParams['season_id'] = $selectedSeasonId;
        }
        $sessions = $this->pdo->prepare($sessionSql . ' ORDER BY ps.day_of_week, ps.start_time, se.start_date DESC, ps.name');
        $sessions->execute($sessionParams);
        return ['terms' => [], 'statuses' => $statuses, 'seasons' => $seasons->fetchAll(), 'sessions' => $sessions->fetchAll(), 'rinks' => $this->rinks($clubId)];
    }

    public function saveSeason(int $clubId, int $userId, array $input): void
    {
        $id = $this->id($input['id'] ?? null);
        $values = [
            'name' => $this->text($input['name'] ?? null, 120, 'Season name'),
            'season_status_id' => $this->id($input['season_status_id'] ?? null),
            'start_date' => $this->date($input['start_date'] ?? null),
            'end_date' => $this->date($input['end_date'] ?? null),
            'active' => !empty($input['active']) ? 1 : 0,
            'club_id' => $clubId,
            'user_id' => $userId,
        ];
        $statusCode = $values['end_date'] < date('Y-m-d')
            ? 'COMPLETED'
            : ($values['start_date'] > date('Y-m-d') ? 'REGISTRATION_OPEN' : 'IN_PROGRESS');
        $values['season_status_id'] = $this->lookupId('season_status', $statusCode);
        if ($values['end_date'] < $values['start_date']) throw new InvalidArgumentException('Enter a valid season date range.');
        if ($id === null) {
            $values['created_by_user_id'] = $userId;
            $values['updated_by_user_id'] = $userId;
            unset($values['user_id']);
            $sql = 'INSERT INTO season (club_id, season_status_id, name, start_date, end_date, active, created_by_user_id, updated_by_user_id) VALUES (:club_id, :season_status_id, :name, :start_date, :end_date, :active, :created_by_user_id, :updated_by_user_id)';
        } else {
            $values['id'] = $id;
            $sql = 'UPDATE season SET season_status_id = :season_status_id, name = :name, start_date = :start_date, end_date = :end_date, active = :active, updated_by_user_id = :user_id WHERE id = :id AND club_id = :club_id AND deleted_at IS NULL';
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare($sql)->execute($values);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function saveSession(int $clubId, int $userId, array $input): void
    {
        $id = $this->id($input['id'] ?? null);
        $sku = $this->text($input['sku'] ?? null, 64, 'SKU');
        $values = ['club_id' => $clubId, 'user_id' => $userId, 'season_id' => $this->id($input['season_id'] ?? null), 'sku' => $sku, 'name' => $this->nullableText($input['name'] ?? null, 160) ?? $sku, 'day_of_week' => $this->id($input['day_of_week'] ?? null), 'start_time' => $this->time($input['start_time'] ?? null), 'end_time' => $this->time($input['end_time'] ?? null), 'location' => $this->nullableText($input['location'] ?? null, 160)];
        if ((int) $values['day_of_week'] > 7 || $values['end_time'] <= $values['start_time']) throw new InvalidArgumentException('Enter a valid session day and time range.');
        if ($id === null) {
            $values['created_by_user_id'] = $userId;
            $values['updated_by_user_id'] = $userId;
            unset($values['user_id']);
            $sql = 'INSERT INTO program_session (club_id, season_id, sku, name, day_of_week, start_time, end_time, location, created_by_user_id, updated_by_user_id) VALUES (:club_id, :season_id, :sku, :name, :day_of_week, :start_time, :end_time, :location, :created_by_user_id, :updated_by_user_id)';
        } else {
            $values['id'] = $id;
            $sql = 'UPDATE program_session SET sku = :sku, name = :name, day_of_week = :day_of_week, start_time = :start_time, end_time = :end_time, location = :location, updated_by_user_id = :user_id WHERE id = :id AND season_id = :season_id AND club_id = :club_id AND deleted_at IS NULL';
        }
        try {
            $this->pdo->prepare($sql)->execute($values);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new InvalidArgumentException('That SKU is already in use.');
            }
            throw $exception;
        }
    }

    public function removeSeason(int $clubId, int $userId, $id): void { $this->remove('season', $clubId, $userId, $id, 'id', 'A season with sessions cannot be removed. Remove its sessions first.'); }
    public function removeSession(int $clubId, int $userId, $id): void { $this->remove('program_session', $clubId, $userId, $id, 'id', 'A session with active skater registrations cannot be removed.'); }

    public function rinks(int $clubId): array
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM application_setting WHERE club_id = :club_id AND setting_key = :key');
        $statement->execute(['club_id' => $clubId, 'key' => self::RINKS_KEY]);
        $stored = $statement->fetchColumn();
        $rinks = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($rinks)) {
            $locations = $this->pdo->prepare('SELECT DISTINCT location FROM program_session WHERE club_id = :club_id AND location IS NOT NULL AND location <> "" AND deleted_at IS NULL ORDER BY location');
            $locations->execute(['club_id' => $clubId]);
            $rinks = array_column($locations->fetchAll(), 'location');
        }
        return array_values(array_filter(array_map('trim', $rinks)));
    }

    public function saveRink(int $clubId, int $userId, array $input): void
    {
        $original = trim((string) ($input['original_name'] ?? ''));
        $name = $this->text($input['name'] ?? null, 160, 'Rink name');
        $rinks = $this->rinks($clubId);
        $found = false;
        foreach ($rinks as &$rink) { if (strcasecmp($rink, $original) === 0) { $rink = $name; $found = true; break; } }
        unset($rink);
        if ($original === '') $rinks[] = $name; elseif (!$found) throw new InvalidArgumentException('That rink is no longer available.');
        if (count(array_unique(array_map('strtolower', $rinks))) !== count($rinks)) throw new InvalidArgumentException('Each rink needs a unique name.');
        $this->pdo->beginTransaction();
        try {
            if ($original !== '') $this->pdo->prepare('UPDATE program_session SET location = :name, updated_by_user_id = :user_id WHERE club_id = :club_id AND location = :original AND deleted_at IS NULL')->execute(['name' => $name, 'user_id' => $userId, 'club_id' => $clubId, 'original' => $original]);
            $this->saveRinks($clubId, $userId, $rinks);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function removeRink(int $clubId, int $userId, $name): void
    {
        $name = trim((string) $name);
        $inUse = $this->pdo->prepare('SELECT COUNT(*) FROM program_session WHERE club_id = :club_id AND location = :name AND deleted_at IS NULL'); $inUse->execute(['club_id' => $clubId, 'name' => $name]);
        if ((int) $inUse->fetchColumn() > 0) throw new InvalidArgumentException('This rink is used by one or more sessions and cannot be removed.');
        $this->saveRinks($clubId, $userId, array_values(array_filter($this->rinks($clubId), static fn(string $rink): bool => strcasecmp($rink, $name) !== 0)));
    }

    private function saveRinks(int $clubId, int $userId, array $rinks): void { $sql = 'INSERT INTO application_setting (club_id, setting_key, setting_value, value_type, description, created_by_user_id, updated_by_user_id) VALUES (:club_id, :key, :value, "json", :description, :created_by, :updated_by) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_user_id = VALUES(updated_by_user_id)'; $this->pdo->prepare($sql)->execute(['club_id' => $clubId, 'key' => self::RINKS_KEY, 'value' => json_encode($rinks, JSON_THROW_ON_ERROR), 'description' => 'Administrator-managed rink names.', 'created_by' => $userId, 'updated_by' => $userId]); }
    private function remove(string $table, int $clubId, int $userId, $id, string $column, string $message): void { $id = $this->id($id); $check = $table === 'season' ? 'SELECT COUNT(*) FROM program_session WHERE season_id = :id AND deleted_at IS NULL' : 'SELECT COUNT(*) FROM skater_enrollment enrollment INNER JOIN skater ON skater.id = enrollment.skater_id WHERE enrollment.program_session_id = :id AND enrollment.deleted_at IS NULL AND skater.deleted_at IS NULL'; $statement = $this->pdo->prepare($check); $statement->execute(['id' => $id]); if ((int) $statement->fetchColumn() > 0) throw new InvalidArgumentException($message); $this->pdo->prepare("UPDATE {$table} SET active = 0, deleted_at = UTC_TIMESTAMP(), updated_by_user_id = :user_id WHERE {$column} = :id AND club_id = :club_id")->execute(['user_id' => $userId, 'id' => $id, 'club_id' => $clubId]); }
    private function id($value): ?int { if ($value === null || $value === '') return null; $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); if ($id === false) throw new InvalidArgumentException('Choose a valid value.'); return (int) $id; }
    private function lookupId(string $table, string $code): int { $statement = $this->pdo->prepare("SELECT id FROM {$table} WHERE code = :code AND active = 1 LIMIT 1"); $statement->execute(['code' => $code]); $id = $statement->fetchColumn(); if ($id === false) throw new RuntimeException('Required season settings are not configured.'); return (int) $id; }
    private function text($value, int $limit, string $label): string { $value = trim((string) $value); if ($value === '' || mb_strlen($value) > $limit) throw new InvalidArgumentException("{$label} is required."); return $value; }
    private function nullableText($value, int $limit): ?string { $value = trim((string) $value); if ($value === '') return null; if (mb_strlen($value) > $limit) throw new InvalidArgumentException('Value is too long.'); return $value; }
    private function date($value): string { $value = trim((string) $value); $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); if ($date === false || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Enter a valid date.'); return $value; }
    private function time($value): string { $value = trim((string) $value); if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $value) !== 1) throw new InvalidArgumentException('Enter a valid time.'); return $value . ':00'; }
}
