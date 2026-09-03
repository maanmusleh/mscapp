<?php

declare(strict_types=1);

final class AdminToolsService
{
    private PDO $pdo;
    private const GROUP_COLOURS_KEY = 'group_colours';
    private const MAX_RESTORE_BYTES = 250 * 1024 * 1024;

    private const DEFAULT_GROUP_COLOURS = [
        ['name' => 'Red', 'hex' => '#DC3545'],
        ['name' => 'Orange', 'hex' => '#FD7E14'],
        ['name' => 'Yellow', 'hex' => '#FFC107'],
        ['name' => 'Green', 'hex' => '#198754'],
        ['name' => 'Blue', 'hex' => '#0D6EFD'],
        ['name' => 'Purple', 'hex' => '#6F42C1'],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array{club_name:string,time_zone:string} */
    public function clubSettings(int $clubId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT club_name, time_zone FROM club WHERE id = :club_id AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['club_id' => $clubId]);
        $settings = $statement->fetch();
        if (!$settings) throw new InvalidArgumentException('Club settings are unavailable.');

        return [
            'club_name' => (string) $settings['club_name'],
            'time_zone' => (string) ($settings['time_zone'] ?: 'America/Toronto'),
        ];
    }

    public function updateClubSettings(int $clubId, int $userId, array $input): void
    {
        $clubName = trim((string) ($input['club_name'] ?? ''));
        $timeZone = trim((string) ($input['time_zone'] ?? ''));
        if ($clubName === '' || mb_strlen($clubName) > 160) {
            throw new InvalidArgumentException('Enter a club name of up to 160 characters.');
        }
        if (!in_array($timeZone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Choose a valid time zone.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE club SET club_name = :club_name, time_zone = :time_zone, updated_by_user_id = :user_id
             WHERE id = :club_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'club_name' => $clubName,
            'time_zone' => $timeZone,
            'user_id' => $userId,
            'club_id' => $clubId,
        ]);
    }

    /** @return list<array{id:int,stage_number:int,name:string,active:bool}> */
    public function stages(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, stage_number, name, active
             FROM canskate_stage
             WHERE deleted_at IS NULL
             ORDER BY display_order, stage_number'
        );

        return array_map(
            static fn (array $stage): array => [
                'id' => (int) $stage['id'],
                'stage_number' => (int) $stage['stage_number'],
                'name' => (string) $stage['name'],
                'active' => (bool) $stage['active'],
            ],
            $statement->fetchAll()
        );
    }

    public function updateStageSettings(int $userId, array $input): void
    {
        $submittedIds = $input['enabled_stage_ids'] ?? [];
        if (!is_array($submittedIds)) {
            throw new InvalidArgumentException('Choose valid stages.');
        }

        $enabledIds = [];
        foreach ($submittedIds as $stageId) {
            $stageId = filter_var($stageId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($stageId === false) {
                throw new InvalidArgumentException('Choose valid stages.');
            }
            $enabledIds[(int) $stageId] = true;
        }

        $stages = $this->stages();
        $validIds = array_flip(array_map(static fn (array $stage): int => $stage['id'], $stages));
        foreach (array_keys($enabledIds) as $stageId) {
            if (!isset($validIds[$stageId])) {
                throw new InvalidArgumentException('Choose valid stages.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE canskate_stage
                 SET active = :active, updated_by_user_id = :user_id
                 WHERE id = :stage_id AND deleted_at IS NULL'
            );
            foreach ($stages as $stage) {
                $statement->execute([
                    'active' => isset($enabledIds[$stage['id']]) ? 1 : 0,
                    'user_id' => $userId,
                    'stage_id' => $stage['id'],
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function groupColours(int $clubId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT setting_value
             FROM application_setting
             WHERE club_id = :club_id
               AND setting_key = :setting_key
             LIMIT 1'
        );
        $statement->execute([
            'club_id' => $clubId,
            'setting_key' => self::GROUP_COLOURS_KEY,
        ]);
        $value = $statement->fetchColumn();
        $palette = self::DEFAULT_GROUP_COLOURS;
        if (is_string($value) && $value !== '') {
            try {
                $colours = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                $palette = $this->validateGroupColours(is_array($colours) ? $colours : []);
            } catch (JsonException|InvalidArgumentException $exception) {
                $palette = self::DEFAULT_GROUP_COLOURS;
            }
        }

        // The saved palette is only a list of available colours.  Session groups are
        // the source of truth for colours that have already been assigned, so retain
        // any of those colours even if a past palette edit no longer lists them.
        $coloursByName = [];
        foreach ($palette as $colour) {
            $coloursByName[mb_strtolower($colour['name'])] = $colour;
        }
        foreach ($this->assignedGroupColours($clubId) as $colour) {
            $key = mb_strtolower($colour['name']);
            if (!isset($coloursByName[$key])) {
                $coloursByName[$key] = $colour;
            }
        }

        return array_values($coloursByName);
    }

    /** @return array{users: int, skaters: int, seasons: int, programs: int, achievements: int} */
    public function databaseSummary(int $clubId): array
    {
        $queries = [
            'users' => 'SELECT COUNT(DISTINCT id) FROM app_user WHERE deleted_at IS NULL',
            'skaters' => 'SELECT COUNT(DISTINCT id) FROM skater WHERE club_id = :club_id AND deleted_at IS NULL',
            'seasons' => 'SELECT COUNT(DISTINCT id) FROM season WHERE club_id = :club_id AND deleted_at IS NULL',
            'programs' => 'SELECT COUNT(DISTINCT id) FROM program_session WHERE club_id = :club_id AND deleted_at IS NULL',
            'achievements' => 'SELECT COUNT(DISTINCT achievement.id)
                               FROM skater_skill achievement
                               INNER JOIN skater ON skater.id = achievement.skater_id
                               WHERE skater.club_id = :club_id AND skater.deleted_at IS NULL',
        ];
        $summary = [];
        foreach ($queries as $key => $query) {
            $statement = $this->pdo->prepare($query);
            $statement->execute($key === 'users' ? [] : ['club_id' => $clubId]);
            $summary[$key] = (int) $statement->fetchColumn();
        }

        return $summary;
    }

    public function addGroupColour(int $clubId, int $userId, array $input): void
    {
        $colours = $this->groupColours($clubId);
        $colours[] = [
            'name' => (string) ($input['name'] ?? ''),
            'hex' => (string) ($input['hex'] ?? ''),
        ];
        $this->saveGroupColours($clubId, $userId, $colours);
    }

    public function updateGroupColour(int $clubId, int $userId, array $input): void
    {
        $originalName = trim((string) ($input['original_name'] ?? ''));
        $replacement = [
            'name' => (string) ($input['name'] ?? ''),
            'hex' => (string) ($input['hex'] ?? ''),
        ];
        $colours = $this->groupColours($clubId);
        $found = false;
        foreach ($colours as &$colour) {
            if (strcasecmp($colour['name'], $originalName) === 0) {
                $colour = $replacement;
                $found = true;
                break;
            }
        }
        unset($colour);
        if (!$found) {
            throw new InvalidArgumentException('That group colour is no longer available.');
        }

        $colours = $this->validateGroupColours($colours);
        $this->pdo->beginTransaction();
        try {
            if (strcasecmp($originalName, $replacement['name']) !== 0) {
                $conflict = $this->pdo->prepare(
                    'SELECT COUNT(*)
                     FROM program_group replacement_group
                     INNER JOIN program_session ps
                        ON ps.id = replacement_group.program_session_id
                        AND ps.club_id = :club_id
                     WHERE replacement_group.name = :replacement_name
                       AND replacement_group.deleted_at IS NULL
                       AND EXISTS (
                           SELECT 1
                           FROM program_group original_group
                           WHERE original_group.program_session_id = replacement_group.program_session_id
                             AND original_group.name = :original_name
                             AND original_group.deleted_at IS NULL
                       )'
                );
                $conflict->execute([
                    'club_id' => $clubId,
                    'replacement_name' => $replacement['name'],
                    'original_name' => $originalName,
                ]);
                if ((int) $conflict->fetchColumn() > 0) {
                    throw new InvalidArgumentException(
                        'That name is already used by a group in one or more sessions.'
                    );
                }
            }

            $updateAssignedGroups = $this->pdo->prepare(
                'UPDATE program_group pg
                 INNER JOIN program_session ps
                    ON ps.id = pg.program_session_id
                    AND ps.club_id = :club_id
                 SET
                    pg.name = :name,
                    pg.colour_hex = :colour_hex,
                    pg.updated_by_user_id = :updated_by_user_id
                 WHERE pg.name = :original_name
                   AND pg.deleted_at IS NULL'
            );
            $updateAssignedGroups->execute([
                'club_id' => $clubId,
                'name' => $replacement['name'],
                'colour_hex' => strtoupper(trim($replacement['hex'])),
                'updated_by_user_id' => $userId,
                'original_name' => $originalName,
            ]);

            $this->saveGroupColours($clubId, $userId, $colours);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function removeGroupColour(int $clubId, int $userId, array $input): void
    {
        $name = trim((string) ($input['original_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Choose a valid group colour.');
        }

        $colours = $this->groupColours($clubId);
        $found = false;
        $remainingColours = [];
        foreach ($colours as $colour) {
            if (strcasecmp($colour['name'], $name) === 0) {
                $found = true;
                continue;
            }
            $remainingColours[] = $colour;
        }
        if (!$found) {
            throw new InvalidArgumentException('That group colour is no longer available.');
        }

        $this->pdo->beginTransaction();
        try {
            $assigned = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM program_group pg
                 INNER JOIN program_session ps
                    ON ps.id = pg.program_session_id
                 INNER JOIN group_assignment ga
                    ON ga.program_group_id = pg.id
                 INNER JOIN skater_enrollment se
                    ON se.id = ga.skater_enrollment_id
                 WHERE ps.club_id = :club_id
                   AND pg.name = :name
                   AND pg.deleted_at IS NULL
                   AND se.active = 1
                   AND se.deleted_at IS NULL
                   AND ga.id = (
                       SELECT MAX(latest.id)
                       FROM group_assignment latest
                       WHERE latest.skater_enrollment_id = se.id
                   )'
            );
            $assigned->execute(['club_id' => $clubId, 'name' => $name]);
            if ((int) $assigned->fetchColumn() > 0) {
                throw new InvalidArgumentException(
                    'This colour cannot be removed because one or more skaters are assigned to it.'
                );
            }

            $removeSessionGroups = $this->pdo->prepare(
                'UPDATE program_group pg
                 INNER JOIN program_session ps
                    ON ps.id = pg.program_session_id
                 SET pg.active = 0,
                     pg.deleted_at = NOW(),
                     pg.updated_by_user_id = :user_id
                 WHERE ps.club_id = :club_id
                   AND pg.name = :name
                   AND pg.deleted_at IS NULL'
            );
            $removeSessionGroups->execute([
                'club_id' => $clubId,
                'name' => $name,
                'user_id' => $userId,
            ]);
            $this->saveGroupColours($clubId, $userId, $remainingColours);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createBackup(): array
    {
        $connection = $this->databaseConnection();
        $path = tempnam(sys_get_temp_dir(), 'cat_bkup_');
        if ($path === false) {
            throw new RuntimeException('A temporary backup file could not be created.');
        }

        $command = [
            $this->databaseExecutable('CAT_MYSQLDUMP_BIN', 'mysqldump'),
            '--single-transaction',
            '--routines',
            '--events',
            '--triggers',
            '--add-drop-table',
            '--skip-add-drop-database',
            '--host=' . $connection['host'],
            '--user=' . $connection['username'],
        ];
        if ($connection['port'] !== null) {
            $command[] = '--port=' . $connection['port'];
        }
        $command[] = $connection['database'];
        try {
            $this->runDatabaseCommand($command, $connection['password'], $path, false);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return [
            'path' => $path,
            'filename' => 'cat_bkup_' . gmdate('Ymd_His') . '.sql',
        ];
    }

    public function restoreBackup(array $upload): void
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Choose a SQL backup file to restore.');
        }
        $path = $upload['tmp_name'] ?? '';
        $name = $upload['name'] ?? '';
        $size = (int) ($upload['size'] ?? 0);
        if (!is_string($path) || !is_uploaded_file($path)) {
            throw new InvalidArgumentException('The uploaded backup could not be verified.');
        }
        if (!is_string($name) || !string_ends_with(strtolower($name), '.sql')) {
            throw new InvalidArgumentException('Only .sql backup files can be restored.');
        }
        if ($size <= 0 || $size > self::MAX_RESTORE_BYTES) {
            throw new InvalidArgumentException('The backup file must be between 1 byte and 250 MB.');
        }

        $connection = $this->databaseConnection();
        $portableBackupPath = $this->databaseNeutralRestoreFile($path);
        $safetyBackup = null;
        $cleanupPath = null;
        try {
            // A restore is destructive. Keep a server-side rollback point until the
            // uploaded backup has been imported successfully.
            $safetyBackup = $this->createBackup();
            $cleanupPath = $this->schemaCleanupFile($connection['database']);
            $command = $this->mysqlCommand($connection);
            $this->runDatabaseCommand($command, $connection['password'], $cleanupPath, true);
            $this->runDatabaseCommand($command, $connection['password'], $portableBackupPath, true);
        } catch (Throwable $restoreException) {
            if (is_array($safetyBackup) && is_string($safetyBackup['path'] ?? null)) {
                try {
                    $rollbackCleanupPath = $this->schemaCleanupFile($connection['database']);
                    try {
                        $command = $this->mysqlCommand($connection);
                        $this->runDatabaseCommand($command, $connection['password'], $rollbackCleanupPath, true);
                        $this->runDatabaseCommand($command, $connection['password'], $safetyBackup['path'], true);
                    } finally {
                        @unlink($rollbackCleanupPath);
                    }
                } catch (Throwable $rollbackException) {
                    throw new RuntimeException(
                        'The restore failed and CAT could not automatically restore the safety backup: '
                        . $rollbackException->getMessage(),
                        0,
                        $restoreException
                    );
                }
                throw new RuntimeException(
                    'The restore failed. CAT restored the original database automatically: '
                    . $restoreException->getMessage(),
                    0,
                    $restoreException
                );
            }
            throw $restoreException;
        } finally {
            @unlink($portableBackupPath);
            if (is_string($cleanupPath)) {
                @unlink($cleanupPath);
            }
            if (is_array($safetyBackup) && is_string($safetyBackup['path'] ?? null)) {
                @unlink($safetyBackup['path']);
            }
        }
    }

    private function saveGroupColours(int $clubId, int $userId, array $colours): void
    {
        $colours = $this->validateGroupColours($colours);
        $statement = $this->pdo->prepare(
            'INSERT INTO application_setting (
                club_id, setting_key, setting_value, value_type, description,
                created_by_user_id, updated_by_user_id
             ) VALUES (
                :club_id, :setting_key, :setting_value, "json", :description,
                :created_by_user_id, :updated_by_user_id
             ) ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                value_type = VALUES(value_type),
                description = VALUES(description),
                updated_by_user_id = VALUES(updated_by_user_id)'
        );
        $statement->execute([
            'club_id' => $clubId,
            'setting_key' => self::GROUP_COLOURS_KEY,
            'setting_value' => json_encode($colours, JSON_THROW_ON_ERROR),
            'description' => 'Administrator-managed colour palette for session group assignment.',
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);
    }

    private function assignedGroupColours(int $clubId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pg.name, pg.colour_hex AS hex
             FROM program_group pg
             INNER JOIN program_session ps
                ON ps.id = pg.program_session_id
             WHERE ps.club_id = :club_id
               AND pg.deleted_at IS NULL
             ORDER BY pg.name ASC, pg.id ASC'
        );
        $statement->execute(['club_id' => $clubId]);

        $colours = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $colour) {
            try {
                $validated = $this->validateGroupColours([$colour]);
                $colours[] = $validated[0];
            } catch (InvalidArgumentException $exception) {
                // A malformed legacy group must not prevent administrators from
                // editing the remaining valid group colours.
            }
        }

        return $colours;
    }

    private function validateGroupColours(array $colours): array
    {
        $validated = [];
        $names = [];
        foreach ($colours as $colour) {
            $name = trim((string) ($colour['name'] ?? ''));
            $hex = strtoupper(trim((string) ($colour['hex'] ?? '')));
            $normalizedName = mb_strtolower($name);
            if ($name === '' || mb_strlen($name) > 100) {
                throw new InvalidArgumentException('Group colour names must be between 1 and 100 characters.');
            }
            if (preg_match('/^#[0-9A-F]{6}$/D', $hex) !== 1) {
                throw new InvalidArgumentException('Choose a valid group colour.');
            }
            if (isset($names[$normalizedName])) {
                throw new InvalidArgumentException('Each group colour needs a unique name.');
            }
            $names[$normalizedName] = true;
            $validated[] = ['name' => $name, 'hex' => $hex];
        }

        return $validated;
    }

    /**
     * Removes database-level directives emitted by older CAT backups. Table,
     * routine, trigger, and event statements remain unchanged and are imported
     * into the destination database selected on the mysql command line.
     */
    private function databaseNeutralRestoreFile(string $sourcePath): string
    {
        $destinationPath = tempnam(sys_get_temp_dir(), 'cat_restore_');
        if ($destinationPath === false) {
            throw new RuntimeException('A temporary restore file could not be created.');
        }
        $source = @fopen($sourcePath, 'rb');
        $destination = @fopen($destinationPath, 'wb');
        if (!is_resource($source) || !is_resource($destination)) {
            if (is_resource($source)) fclose($source);
            if (is_resource($destination)) fclose($destination);
            @unlink($destinationPath);
            throw new RuntimeException('The uploaded backup could not be prepared for restore.');
        }

        $skippingDirective = false;
        $written = 0;
        $requiredCatTables = [
            'schema_migration' => false,
            'club' => false,
            'app_user' => false,
        ];
        try {
            while (($line = fgets($source)) !== false) {
                if ($skippingDirective) {
                    if (strpos($line, ';') !== false) {
                        $skippingDirective = false;
                    }
                    continue;
                }
                $trimmed = ltrim($line);
                $databaseDirective = preg_match(
                    '/^(?:\/\*![0-9]+\s*)?(?:DROP|CREATE)\s+DATABASE\b/i',
                    $trimmed
                ) === 1;
                $useDirective = preg_match('/^USE\s+(?:`[^`]+`|[A-Za-z0-9_]+)\s*;/i', $trimmed) === 1;
                $embeddedDatabaseDirective = preg_match('/(?:DROP|CREATE)\s+DATABASE\b/i', $line) === 1
                    && !$databaseDirective;
                $embeddedUseDirective = preg_match('/(?:^|;)\s*USE\s+(?:`[^`]+`|[A-Za-z0-9_]+)\s*;/i', $line) === 1
                    && !$useDirective;
                if ($embeddedDatabaseDirective || $embeddedUseDirective) {
                    throw new InvalidArgumentException(
                        'The SQL backup contains an unsafe database-level statement.'
                    );
                }
                if ($databaseDirective || $useDirective) {
                    if (strpos($line, ';') === false) {
                        $skippingDirective = true;
                    }
                    continue;
                }
                if (preg_match('/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $line, $tableMatch) === 1) {
                    $tableName = strtolower($tableMatch[1]);
                    if (array_key_exists($tableName, $requiredCatTables)) {
                        $requiredCatTables[$tableName] = true;
                    }
                }
                $bytes = fwrite($destination, $line);
                if ($bytes === false) {
                    throw new RuntimeException('The portable restore file could not be written.');
                }
                $written += $bytes;
            }
            if (!feof($source)) {
                throw new RuntimeException('The uploaded backup could not be read completely.');
            }
        } catch (Throwable $exception) {
            fclose($source);
            fclose($destination);
            @unlink($destinationPath);
            throw $exception;
        }
        fclose($source);
        fclose($destination);
        if ($written === 0) {
            @unlink($destinationPath);
            throw new InvalidArgumentException('The SQL backup does not contain restorable content.');
        }
        $missingTables = array_keys(array_filter($requiredCatTables, static fn (bool $found): bool => !$found));
        if ($missingTables !== []) {
            @unlink($destinationPath);
            throw new InvalidArgumentException('The uploaded file is not a complete CAT database backup.');
        }

        return $destinationPath;
    }

    /** Creates SQL that removes every object from the configured CAT schema. */
    private function schemaCleanupFile(string $database): string
    {
        $tablesAndViews = $this->schemaRows(
            'SELECT TABLE_NAME AS object_name, TABLE_TYPE AS object_type
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :database',
            $database
        );
        $triggers = $this->schemaRows(
            'SELECT TRIGGER_NAME AS object_name
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = :database',
            $database
        );
        $routines = $this->schemaRows(
            'SELECT ROUTINE_NAME AS object_name, ROUTINE_TYPE AS object_type
             FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = :database',
            $database
        );
        $events = $this->schemaRows(
            'SELECT EVENT_NAME AS object_name
             FROM information_schema.EVENTS
             WHERE EVENT_SCHEMA = :database',
            $database
        );

        $tables = [];
        $views = [];
        foreach ($tablesAndViews as $object) {
            $name = $this->quoteDatabaseIdentifier((string) $object['object_name']);
            if (strtoupper((string) ($object['object_type'] ?? '')) === 'VIEW') {
                $views[] = $name;
            } else {
                $tables[] = $name;
            }
        }

        $statements = ['SET FOREIGN_KEY_CHECKS=0;', 'SET UNIQUE_CHECKS=0;'];
        foreach ($events as $event) {
            $statements[] = 'DROP EVENT IF EXISTS ' . $this->quoteDatabaseIdentifier((string) $event['object_name']) . ';';
        }
        foreach ($triggers as $trigger) {
            $statements[] = 'DROP TRIGGER IF EXISTS ' . $this->quoteDatabaseIdentifier((string) $trigger['object_name']) . ';';
        }
        if ($views !== []) {
            $statements[] = 'DROP VIEW IF EXISTS ' . implode(', ', $views) . ';';
        }
        if ($tables !== []) {
            $statements[] = 'DROP TABLE IF EXISTS ' . implode(', ', $tables) . ';';
        }
        foreach ($routines as $routine) {
            $type = strtoupper((string) ($routine['object_type'] ?? '')) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
            $statements[] = 'DROP ' . $type . ' IF EXISTS '
                . $this->quoteDatabaseIdentifier((string) $routine['object_name']) . ';';
        }
        $statements[] = 'SET UNIQUE_CHECKS=1;';
        $statements[] = 'SET FOREIGN_KEY_CHECKS=1;';

        $path = tempnam(sys_get_temp_dir(), 'cat_clean_');
        if ($path === false || file_put_contents($path, implode("\n", $statements) . "\n") === false) {
            if (is_string($path)) @unlink($path);
            throw new RuntimeException('The database replacement plan could not be created.');
        }

        return $path;
    }

    private function schemaRows(string $sql, string $database): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['database' => $database]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function quoteDatabaseIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function mysqlCommand(array $connection): array
    {
        $command = [
            $this->databaseExecutable('CAT_MYSQL_BIN', 'mysql'),
            '--host=' . $connection['host'],
            '--user=' . $connection['username'],
        ];
        if ($connection['port'] !== null) {
            $command[] = '--port=' . $connection['port'];
        }
        $command[] = $connection['database'];
        return $command;
    }

    private function databaseConnection(): array
    {
        $database = config('database');
        $dsn = (string) ($database['dsn'] ?? '');
        if (!string_starts_with($dsn, 'mysql:')) {
            throw new RuntimeException('Database backup and restore require a MySQL connection.');
        }
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[strtolower(trim($key))] = trim($value);
            }
        }
        $databaseName = $parts['dbname'] ?? '';
        if ($databaseName === '' || preg_match('/^[A-Za-z0-9_]+$/D', $databaseName) !== 1) {
            throw new RuntimeException('The configured database name is not valid for backup or restore.');
        }

        return [
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => isset($parts['port']) && ctype_digit($parts['port']) ? $parts['port'] : null,
            'database' => $databaseName,
            'username' => (string) ($database['username'] ?? ''),
            'password' => (string) ($database['password'] ?? ''),
        ];
    }

    private function databaseExecutable(string $environmentVariable, string $name): string
    {
        $configured = getenv($environmentVariable);
        $candidates = array_filter([
            is_string($configured) ? $configured : null,
            '/usr/bin/' . $name,
            '/usr/local/bin/' . $name,
            '/Applications/MAMP/Library/bin/mysql80/bin/' . $name,
            '/Applications/MAMP/Library/bin/mysql57/bin/' . $name,
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException(
            "The {$name} command was not found. Configure {$environmentVariable} on the application server."
        );
    }

    private function runDatabaseCommand(
        array $command,
        string $password,
        string $file,
        bool $inputFile
    ): void {
        $descriptors = $inputFile
            ? [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
            : [0 => ['pipe', 'r'], 1 => ['file', $file, 'w'], 2 => ['pipe', 'w']];
        $environment = $_ENV;
        $environment['MYSQL_PWD'] = $password;
        $process = proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('The database command could not be started.');
        }
        if (!$inputFile && isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        $errors = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException(
                'The database command failed' . ($errors !== '' ? ': ' . trim($errors) : '.')
            );
        }
    }
}
