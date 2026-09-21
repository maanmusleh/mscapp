<?php

declare(strict_types=1);

/**
 * Read-only verification of the installed CAT schema.
 *
 * This deliberately does not run DDL. A web request is not a safe place to
 * alter a production database: migrations can require a backup, a maintenance
 * window, or manual handling of existing data.
 */
final class SchemaHealthService
{
    /** @var string[] */
    private const REQUIRED_TABLES = [
        'schema_migration', 'gender', 'user_role', 'season_status', 'coach_role',
        'attendance_status', 'assessment_result', 'notification_type', 'club',
        'coach', 'app_user', 'user_login_activity', 'skater', 'skater_audit_event',
        'canskate_stage', 'canskate_ribbon', 'canskate_category', 'canskate_skill',
        'season', 'program_session', 'program_date', 'program_group',
        'skater_enrollment', 'group_assignment', 'coach_session_assignment',
        'coach_assignment', 'attendance', 'assessment_history', 'skater_skill',
        'skater_ribbon', 'skater_badge', 'application_setting', 'saved_report',
        'notification', 'report_card_note_library', 'skater_season_report_card_note',
        'rink_chat_message', 'rink_chat_view', 'rink_offline_change',
    ];

    /** @var array<string, string> */
    private const MIGRATIONS = [
        '1.0.0' => 'Base CAT schema and reference data',
        '1.1.0' => 'User names and temporary-password enforcement',
        '1.2.0' => 'Installation-local user accounts',
        '1.2.1' => 'Flexible Skate Canada numbers',
        '1.2.2' => 'Rink App chat',
        '1.2.3' => 'Editable Rink App chat messages',
        '1.2.4' => 'Deleted-chat placeholders',
        '1.2.5' => 'Club time zone for attendance',
        '1.2.6' => 'General skater notes',
        '1.2.7' => 'Password login locks',
        '1.2.8' => 'Pre-CanSkate curriculum',
        '1.2.9' => 'Legacy report-card notes',
        '1.2.10' => 'Report-card note library',
        '1.2.11' => 'Legacy report-card note audit fields',
        '1.2.12' => 'Report-card signatures',
        '1.2.13' => 'Season-specific report-card notes',
        '1.2.14' => 'Remove legacy report-card note fields',
        '1.2.15' => 'Login-rate-limit indexes',
        '1.2.16' => 'Free-form skater gender',
        '1.2.17' => 'Coach App offline sync',
        '1.2.18' => 'Session report-card coach assignments',
        '1.2.19' => 'Release Skate Canada numbers from deleted skaters',
        '1.2.20' => 'Deactivate enrollments for deleted skaters',
        '1.2.21' => 'Installation-wide user identity uniqueness',
        '1.2.22' => 'Active skater identity uniqueness and stale schedule-write protection',
        '1.2.23' => 'Stale skater-profile-write protection',
        '1.2.24' => 'Colour-group report-card coach assignments',
    ];

    /** @var array<string, array<string, string[]>> */
    private const MIGRATION_COLUMNS = [
        '1.2.6' => ['skater' => ['general_notes']],
        '1.2.7' => ['app_user' => [
            'password_failed_attempts', 'password_failure_window_started_at',
            'password_short_lock_issued_at', 'password_lock_until',
        ]],
        '1.2.12' => ['app_user' => [
            'report_card_signature_png', 'report_card_signature_width',
            'report_card_signature_height', 'report_card_signature_updated_at',
        ]],
        '1.2.16' => ['skater' => ['gender_text']],
        '1.2.17' => ['attendance' => ['sync_revision']],
        '1.2.18' => ['program_session' => ['report_card_coach_user_id']],
        '1.2.22' => ['skater' => ['active_identity_marker']],
        '1.2.24' => ['program_group' => ['report_card_coach_user_id']],
    ];

    /** @var array<string, array<string, string[]>> */
    private const MIGRATION_INDEXES = [
        '1.2.15' => ['user_login_activity' => [
            'idx_user_login_activity_user_ip_created',
            'idx_user_login_activity_ip_created',
            'idx_user_login_activity_identity_ip_created',
        ]],
        '1.2.21' => ['app_user' => [
            'idx_app_user_club', 'uq_app_user_username', 'uq_app_user_email',
        ]],
        '1.2.22' => ['skater' => ['uq_skater_active_identity']],
        '1.2.24' => ['program_group' => ['idx_program_group_report_card_coach']],
    ];

    /** @var array<string, string> */
    private const MIGRATION_FILES = [
        '1.2.6' => 'migrations/1.2.6_add_general_skater_notes.sql',
        '1.2.7' => 'migrations/1.2.7_add_password_login_locks.sql',
        '1.2.8' => 'migrations/1.2.8_add_pre_canskate.sql',
        '1.2.9' => 'migrations/1.2.9_add_report_card_notes.sql',
        '1.2.10' => 'migrations/1.2.10_add_report_card_note_library.sql',
        '1.2.11' => 'migrations/1.2.11_track_report_card_note_updates.sql',
        '1.2.12' => 'migrations/1.2.12_add_report_card_signatures.sql',
        '1.2.13' => 'migrations/1.2.13_make_report_card_notes_season_specific.sql',
        '1.2.14' => 'migrations/1.2.14_remove_legacy_skater_report_card_notes.sql',
        '1.2.15' => 'migrations/1.2.15_harden_login_rate_limits.sql',
        '1.2.16' => 'migrations/1.2.16_add_free_form_skater_gender.sql',
        '1.2.17' => 'migrations/1.2.17_add_coach_offline_sync.sql',
        '1.2.18' => 'migrations/1.2.18_add_session_report_card_coach.sql',
        '1.2.19' => 'migrations/1.2.19_release_deleted_skater_numbers.sql',
        '1.2.20' => 'migrations/1.2.20_deactivate_deleted_skater_enrollments.sql',
        '1.2.21' => 'migrations/1.2.21_enforce_global_user_identity.sql',
        '1.2.22' => 'migrations/1.2.22_harden_skater_identity_and_schedule_writes.sql',
        '1.2.23' => 'migrations/1.2.23_prevent_stale_skater_profile_writes.sql',
        '1.2.24' => 'migrations/1.2.24_add_group_report_card_coaches.sql',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array{healthy: bool, missing_tables: string[], missing_migrations: string[], inconsistent_migrations: array<string, string[]>, out_of_order_migrations: array<string, string[]>, missing_schema: string[], missing_schema_by_migration: array<string, string[]>}
     */
    public function inspect(): array
    {
        $tables = $this->tableNames();
        $missingTables = array_values(array_diff(self::REQUIRED_TABLES, $tables));
        sort($missingTables, SORT_STRING);

        if (!in_array('schema_migration', $tables, true)) {
            return [
                'healthy' => false,
                'missing_tables' => $missingTables,
                'missing_migrations' => array_keys(self::MIGRATIONS),
                'inconsistent_migrations' => [],
                'out_of_order_migrations' => [],
                'missing_schema' => [],
                'missing_schema_by_migration' => [],
            ];
        }

        $applied = $this->appliedMigrationVersions();
        $missingMigrations = array_values(array_diff(array_keys(self::MIGRATIONS), $applied));
        $missingSchemaByMigration = $this->missingSchemaByMigration($tables);
        $inconsistentMigrations = [];
        foreach ($missingSchemaByMigration as $version => $items) {
            if (in_array($version, $applied, true)) {
                $inconsistentMigrations[$version] = $items;
            }
        }
        $outOfOrderMigrations = [];
        foreach ($missingMigrations as $version) {
            foreach ($applied as $appliedVersion) {
                if (isset(self::MIGRATIONS[$appliedVersion]) && version_compare($appliedVersion, $version, '>')) {
                    $outOfOrderMigrations[$version][] = $appliedVersion;
                }
            }
        }

        $missingSchema = [];
        foreach ($missingSchemaByMigration as $items) {
            foreach ($items as $item) {
                $missingSchema[] = $item;
            }
        }
        sort($missingSchema, SORT_STRING);

        return [
            'healthy' => $missingTables === [] && $missingMigrations === [] && $missingSchema === [],
            'missing_tables' => $missingTables,
            'missing_migrations' => $missingMigrations,
            'inconsistent_migrations' => $inconsistentMigrations,
            'out_of_order_migrations' => $outOfOrderMigrations,
            'missing_schema' => $missingSchema,
            'missing_schema_by_migration' => $missingSchemaByMigration,
        ];
    }

    /** @return string[] */
    private function tableNames(): array
    {
        $statement = $this->pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES\n"
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        );

        return array_map('strtolower', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return string[] */
    private function appliedMigrationVersions(): array
    {
        $statement = $this->pdo->query('SELECT version_number FROM schema_migration');

        return array_map(static function ($version): string {
            // MySQL comparisons on the schema_migration VARCHAR can ignore
            // trailing spaces, while PHP array comparisons do not. Normalize
            // it here so the health check matches MySQL's migration lookup.
            return trim((string) $version);
        }, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param string[] $tables @return array<string, string[]> */
    private function missingSchemaByMigration(array $tables): array
    {
        $missing = [];
        $columns = $this->columnNames();
        $indexes = $this->indexNames();

        foreach (self::MIGRATION_COLUMNS as $version => $tablesAndColumns) {
            foreach ($tablesAndColumns as $table => $expectedColumns) {
                if (!in_array($table, $tables, true)) {
                    continue;
                }
                foreach ($expectedColumns as $column) {
                    if (!in_array($column, $columns[$table] ?? [], true)) {
                        $missing[$version][] = "column {$table}.{$column}";
                    }
                }
            }
        }

        foreach (self::MIGRATION_INDEXES as $version => $tablesAndIndexes) {
            foreach ($tablesAndIndexes as $table => $expectedIndexes) {
                if (!in_array($table, $tables, true)) {
                    continue;
                }
                foreach ($expectedIndexes as $index) {
                    if (!in_array($index, $indexes[$table] ?? [], true)) {
                        $missing[$version][] = "index {$table}.{$index}";
                    }
                }
            }
        }

        if (!in_array('report_card_note_library', $tables, true)) {
            $missing['1.2.10'][] = 'table report_card_note_library';
        }
        if (!in_array('skater_season_report_card_note', $tables, true)) {
            $missing['1.2.13'][] = 'table skater_season_report_card_note';
        }
        if (!in_array('rink_offline_change', $tables, true)) {
            $missing['1.2.17'][] = 'table rink_offline_change';
        }

        return $missing;
    }

    /** @return array<string, string[]> */
    private function columnNames(): array
    {
        $statement = $this->pdo->query(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        );
        $columns = [];
        foreach ($statement->fetchAll() as $row) {
            $table = strtolower((string) $row['TABLE_NAME']);
            $columns[$table][] = strtolower((string) $row['COLUMN_NAME']);
        }

        return $columns;
    }

    /** @return array<string, string[]> */
    private function indexNames(): array
    {
        $statement = $this->pdo->query(
            'SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()'
        );
        $indexes = [];
        foreach ($statement->fetchAll() as $row) {
            $table = strtolower((string) $row['TABLE_NAME']);
            $indexes[$table][] = strtolower((string) $row['INDEX_NAME']);
        }

        return $indexes;
    }

    /** @return string|null */
    public static function migrationFile(string $version): ?string
    {
        return self::MIGRATION_FILES[$version] ?? null;
    }

    /** @return string[] */
    public static function priorMigrationVersions(string $version): array
    {
        $versions = array_keys(self::MIGRATIONS);
        $index = array_search($version, $versions, true);

        return $index === false ? [] : array_slice($versions, 0, $index);
    }

    /**
     * Returns an ordered, complete upgrade path only when it is safe to bundle
     * the existing migration files. An empty result means that a database
     * administrator must reconcile the schema manually before it can advance.
     *
     * @param array{missing_tables: string[], missing_migrations: string[], inconsistent_migrations: array<string, string[]>, out_of_order_migrations: array<string, string[]>, missing_schema: string[], missing_schema_by_migration: array<string, string[]>} $health
     * @return string[]
     */
    public static function migrationBundleVersions(array $health): array
    {
        if ($health['inconsistent_migrations'] !== [] || $health['out_of_order_migrations'] !== []) {
            return [];
        }

        $migrationCreatedTables = [
            'report_card_note_library', 'skater_season_report_card_note', 'rink_offline_change',
        ];
        if (array_diff($health['missing_tables'], $migrationCreatedTables) !== []) {
            return [];
        }

        $versions = array_keys(self::MIGRATIONS);
        $missing = $health['missing_migrations'];
        if ($missing === []) {
            return [];
        }

        $firstMissingIndex = array_search($missing[0], $versions, true);
        if ($firstMissingIndex === false) {
            return [];
        }
        $expectedMissing = array_slice($versions, $firstMissingIndex);
        if ($missing !== $expectedMissing) {
            return [];
        }
        foreach ($expectedMissing as $version) {
            if (self::migrationFile($version) === null) {
                return [];
            }
        }

        // If every current requirement is already present, a migration record
        // was probably lost. Replaying DDL would be unsafe and will fail.
        if ($health['missing_schema'] === []) {
            return [];
        }

        return $expectedMissing;
    }
}
