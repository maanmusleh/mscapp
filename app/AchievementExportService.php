<?php

declare(strict_types=1);

final class AchievementExportService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function filterOptions(int $clubId): array
    {
        $seasons = $this->pdo->prepare(
            'SELECT id, name, start_date, end_date
             FROM season
             WHERE club_id = :club_id AND deleted_at IS NULL
             ORDER BY
                CASE WHEN end_date >= CURDATE() THEN 0 ELSE 1 END,
                CASE WHEN end_date >= CURDATE() THEN start_date END DESC,
                CASE WHEN end_date < CURDATE() THEN end_date END DESC,
                name'
        );
        $seasons->execute(['club_id' => $clubId]);

        $sessions = $this->pdo->prepare(
            'SELECT id, season_id, name, day_of_week, start_time
             FROM program_session
             WHERE club_id = :club_id AND deleted_at IS NULL
             ORDER BY season_id, day_of_week, start_time, name'
        );
        $sessions->execute(['club_id' => $clubId]);

        $groups = $this->pdo->prepare(
            'SELECT pg.id, pg.program_session_id, pg.name, ps.season_id, ps.name AS session_name
             FROM program_group pg
             INNER JOIN program_session ps
                ON ps.id = pg.program_session_id
               AND ps.club_id = :club_id
               AND ps.deleted_at IS NULL
             WHERE pg.deleted_at IS NULL
             ORDER BY ps.season_id, ps.day_of_week, ps.start_time, ps.name, pg.display_order, pg.name'
        );
        $groups->execute(['club_id' => $clubId]);

        $ages = $this->pdo->prepare(
            'SELECT DISTINCT TIMESTAMPDIFF(YEAR, s.date_of_birth, CURDATE()) AS age
             FROM skater s
             WHERE s.club_id = :club_id
               AND s.deleted_at IS NULL
               AND s.date_of_birth IS NOT NULL
               AND s.date_of_birth <= CURDATE()
             ORDER BY age'
        );
        $ages->execute(['club_id' => $clubId]);

        $badges = $this->pdo->query(
            'SELECT id, stage_number, name
             FROM canskate_stage
             WHERE stage_number > 0 AND active = 1 AND deleted_at IS NULL
             ORDER BY stage_number'
        );

        return [
            'seasons' => array_map([$this, 'integerIds'], $seasons->fetchAll()),
            'sessions' => array_map([$this, 'sessionIds'], $sessions->fetchAll()),
            'groups' => array_map([$this, 'groupIds'], $groups->fetchAll()),
            'ages' => array_map('intval', $ages->fetchAll(PDO::FETCH_COLUMN)),
            'badges' => array_map([$this, 'badgeIds'], $badges->fetchAll()),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{filename:string,mime:string,path:string,row_count:int}
     */
    public function export(int $clubId, array $filters, string $format): array
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw new InvalidArgumentException('Choose Excel or CSV format.');
        }
        if ($format === 'xlsx' && !class_exists('ZipArchive')) {
            throw new RuntimeException('Excel exports are not available on this server. Choose CSV instead.');
        }

        $seasonId = $this->requiredId($filters['season_id'] ?? null, 'Choose a season.');
        $sessionId = $this->optionalId($filters['session_id'] ?? null, 'Choose a valid session.');
        $groupId = $this->optionalId($filters['group_id'] ?? null, 'Choose a valid group.');
        $age = $this->optionalInteger($filters['age'] ?? null, 0, 150, 'Choose a valid age.');
        $highestBadge = $this->optionalInteger(
            $filters['highest_badge'] ?? null,
            0,
            6,
            'Choose a valid highest badge.'
        );

        $columns = $this->selectedColumns($filters);
        $season = $this->season($clubId, $seasonId);
        $this->validateRegistrationFilters($clubId, $seasonId, $sessionId, $groupId);
        $skaters = $this->skaters($clubId, $seasonId, $sessionId, $groupId, $age, $highestBadge);
        $curriculum = $this->curriculum();
        $achievementMaps = $this->achievementMaps(array_map('intval', array_column($skaters, 'id')));
        [$headers, $rows] = $this->rows($skaters, $curriculum, $achievementMaps, $columns);

        $seasonSlug = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $season['name']));
        $seasonSlug = trim((string) $seasonSlug, '-') ?: 'season';
        $filename = 'skater-achievements-' . $seasonSlug . '-' . date('Y-m-d') . '.' . $format;
        $path = $this->temporaryPath('achievement-export-');

        try {
            if ($format === 'csv') {
                $this->writeCsv($path, $headers, $rows);
            } else {
                $this->writeXlsx($path, $headers, $rows);
            }
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return [
            'filename' => $filename,
            'mime' => $format === 'csv'
                ? 'text/csv; charset=utf-8'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'path' => $path,
            'row_count' => count($rows),
        ];
    }

    /** @return array{filename:string,mime:string,path:string,row_count:int} */
    public function exportSessionReport(int $clubId, string $seasonFilter, string $format): array
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            throw new InvalidArgumentException('Choose Excel or CSV format.');
        }
        if ($format === 'xlsx' && !class_exists('ZipArchive')) {
            throw new RuntimeException('Excel exports are not available on this server. Choose CSV instead.');
        }

        $where = 'ps.club_id = :club_id AND ps.deleted_at IS NULL';
        $parameters = ['club_id' => $clubId];
        $slug = 'all-seasons';
        if ($seasonFilter === 'unassigned') {
            $where .= ' AND ps.season_id IS NULL';
            $slug = 'unassigned';
        } elseif ($seasonFilter !== 'all') {
            $seasonId = $this->requiredId($seasonFilter, 'Choose a valid season filter.');
            $season = $this->season($clubId, $seasonId);
            $where .= ' AND ps.season_id = :season_id';
            $parameters['season_id'] = $seasonId;
            $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $season['name'])), '-') ?: 'season';
        }

        $statement = $this->pdo->prepare(
            'SELECT
                COALESCE(s.name, "Unassigned") AS season_name,
                ps.sku,
                ps.name,
                ELT(ps.day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday") AS day_name,
                TIME_FORMAT(ps.start_time, "%l:%i %p") AS start_time,
                TIME_FORMAT(ps.end_time, "%l:%i %p") AS end_time,
                COALESCE(ps.location, "") AS rink
             FROM program_session ps
             LEFT JOIN season s ON s.id = ps.season_id
             WHERE ' . $where . '
             ORDER BY s.start_date, s.name, ps.day_of_week, ps.start_time, ps.name'
        );
        $statement->execute($parameters);
        $headers = ['Season', 'SKU', 'Session name', 'Day', 'Start time', 'End time', 'Rink'];
        $rows = array_map(static fn (array $row): array => [
            $row['season_name'], $row['sku'], $row['name'], $row['day_name'],
            strtolower((string) $row['start_time']), strtolower((string) $row['end_time']), $row['rink'],
        ], $statement->fetchAll());
        $path = $this->temporaryPath('session-report-');
        try {
            if ($format === 'csv') {
                $this->writeCsv($path, $headers, $rows);
            } else {
                $this->writeXlsx($path, $headers, $rows);
            }
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return [
            'filename' => 'session-report-' . $slug . '-' . date('Y-m-d') . '.' . $format,
            'mime' => $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'path' => $path,
            'row_count' => count($rows),
        ];
    }

    /** @return array<string, mixed> */
    private function season(int $clubId, int $seasonId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name FROM season
             WHERE id = :season_id AND club_id = :club_id AND deleted_at IS NULL'
        );
        $statement->execute(['season_id' => $seasonId, 'club_id' => $clubId]);
        $season = $statement->fetch();
        if (!is_array($season)) {
            throw new InvalidArgumentException('Choose a season that belongs to your club.');
        }
        return $season;
    }

    private function validateRegistrationFilters(
        int $clubId,
        int $seasonId,
        ?int $sessionId,
        ?int $groupId
    ): void {
        if ($sessionId !== null) {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM program_session
                 WHERE id = :session_id AND season_id = :season_id
                   AND club_id = :club_id AND deleted_at IS NULL'
            );
            $statement->execute([
                'session_id' => $sessionId,
                'season_id' => $seasonId,
                'club_id' => $clubId,
            ]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new InvalidArgumentException('Choose a session from the selected season.');
            }
        }

        if ($groupId !== null) {
            $sql = 'SELECT COUNT(*)
                    FROM program_group pg
                    INNER JOIN program_session ps ON ps.id = pg.program_session_id
                    WHERE pg.id = :group_id AND pg.deleted_at IS NULL
                      AND ps.season_id = :season_id AND ps.club_id = :club_id
                      AND ps.deleted_at IS NULL';
            $parameters = ['group_id' => $groupId, 'season_id' => $seasonId, 'club_id' => $clubId];
            if ($sessionId !== null) {
                $sql .= ' AND ps.id = :session_id';
                $parameters['session_id'] = $sessionId;
            }
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new InvalidArgumentException('Choose a group from the selected season and session.');
            }
        }

        if ($sessionId !== null && $groupId !== null) {
            return;
        }
    }

    /** @return list<array<string, mixed>> */
    private function skaters(
        int $clubId,
        int $seasonId,
        ?int $sessionId,
        ?int $groupId,
        ?int $age,
        ?int $highestBadge
    ): array {
        $parameters = [
            'club_id' => $clubId,
            'season_id' => $seasonId,
            'roster_club_id' => $clubId,
        ];
        $registrationWhere = '';
        if ($sessionId !== null) {
            $registrationWhere .= ' AND se.program_session_id = :session_id';
            $parameters['session_id'] = $sessionId;
        }
        if ($groupId !== null) {
            $registrationWhere .= ' AND EXISTS (
                SELECT 1 FROM group_assignment ga
                WHERE ga.id = (
                    SELECT latest_ga.id FROM group_assignment latest_ga
                    WHERE latest_ga.skater_enrollment_id = se.id
                    ORDER BY latest_ga.id DESC LIMIT 1
                )
                AND ga.program_group_id = :group_id
            )';
            $parameters['group_id'] = $groupId;
        }

        $where = '';
        if ($age !== null) {
            $where .= ' AND TIMESTAMPDIFF(YEAR, s.date_of_birth, CURDATE()) = :age';
            $parameters['age'] = $age;
        }
        if ($highestBadge !== null) {
            $where .= ' AND COALESCE((
                SELECT MAX(stage_filter.stage_number)
                FROM skater_badge badge_filter
                INNER JOIN canskate_stage stage_filter
                   ON stage_filter.id = badge_filter.canskate_stage_id
                WHERE badge_filter.skater_id = s.id
                  AND badge_filter.revoked_at IS NULL
                  AND stage_filter.active = 1
                  AND stage_filter.deleted_at IS NULL
            ), 0) = :highest_badge';
            $parameters['highest_badge'] = $highestBadge;
        }

        $statement = $this->pdo->prepare(
            'SELECT DISTINCT
                s.id, s.first_name, s.last_name, s.skate_canada_number,
                s.date_of_birth, g.name AS gender_name,
                s.parent_guardian_name, s.parent_guardian_email,
                s.parent_guardian_phone, s.general_notes, s.medical_notes, s.active
             FROM skater s
             LEFT JOIN gender g ON g.id = s.gender_id
             WHERE s.club_id = :club_id AND s.deleted_at IS NULL
               AND EXISTS (
                    SELECT 1
                    FROM skater_enrollment se
                    INNER JOIN program_session ps
                       ON ps.id = se.program_session_id
                      AND ps.club_id = :roster_club_id
                      AND ps.season_id = :season_id
                      AND ps.deleted_at IS NULL
                    WHERE se.skater_id = s.id AND se.deleted_at IS NULL'
                    . $registrationWhere .
               ')' . $where .
            ' ORDER BY s.last_name, s.first_name, s.date_of_birth, s.id'
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function curriculum(): array
    {
        $skills = $this->pdo->query(
            'SELECT skill.id, skill.skill_code, skill.name AS skill_name,
                    category.name AS category_name, ribbon.name AS ribbon_name,
                    stage.stage_number, stage.name AS stage_name
             FROM canskate_skill skill
             INNER JOIN canskate_category category
                ON category.id = skill.canskate_category_id
               AND category.active = 1
               AND category.deleted_at IS NULL
             INNER JOIN canskate_ribbon ribbon
                ON ribbon.id = category.canskate_ribbon_id
               AND ribbon.active = 1
               AND ribbon.deleted_at IS NULL
             INNER JOIN canskate_stage stage
                ON stage.id = ribbon.canskate_stage_id
               AND stage.active = 1
               AND stage.deleted_at IS NULL
             WHERE skill.active = 1 AND skill.deleted_at IS NULL
             ORDER BY stage.display_order, ribbon.display_order, category.display_order, skill.display_order, skill.id'
        )->fetchAll();
        $ribbons = $this->pdo->query(
            'SELECT ribbon.id, ribbon.name AS ribbon_name,
                    stage.stage_number, stage.name AS stage_name
             FROM canskate_ribbon ribbon
             INNER JOIN canskate_stage stage
                ON stage.id = ribbon.canskate_stage_id
               AND stage.active = 1
               AND stage.deleted_at IS NULL
             WHERE ribbon.active = 1 AND ribbon.deleted_at IS NULL
             ORDER BY stage.display_order, ribbon.display_order, ribbon.id'
        )->fetchAll();
        $badges = $this->pdo->query(
            'SELECT id, stage_number, name AS stage_name
             FROM canskate_stage
             WHERE stage_number > 0 AND active = 1 AND deleted_at IS NULL
             ORDER BY display_order, id'
        )->fetchAll();
        return ['skills' => $skills, 'ribbons' => $ribbons, 'badges' => $badges];
    }

    /**
     * @param list<int> $skaterIds
     * @return array<string, array<int, array<int, string>>>
     */
    private function achievementMaps(array $skaterIds): array
    {
        $maps = ['skills' => [], 'ribbons' => [], 'badges' => []];
        foreach (array_chunk($skaterIds, 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $queries = [
                'skills' => 'SELECT skater_id, canskate_skill_id AS achievement_id, achievement_date AS achieved_at
                             FROM skater_skill WHERE skater_id IN (' . $placeholders . ')',
                'ribbons' => 'SELECT skater_id, canskate_ribbon_id AS achievement_id, DATE(awarded_at) AS achieved_at
                              FROM skater_ribbon WHERE revoked_at IS NULL AND skater_id IN (' . $placeholders . ')',
                'badges' => 'SELECT skater_id, canskate_stage_id AS achievement_id, DATE(awarded_at) AS achieved_at
                             FROM skater_badge WHERE revoked_at IS NULL AND skater_id IN (' . $placeholders . ')',
            ];
            foreach ($queries as $type => $sql) {
                $statement = $this->pdo->prepare($sql);
                $statement->execute($chunk);
                foreach ($statement->fetchAll() as $row) {
                    $maps[$type][(int) $row['skater_id']][(int) $row['achievement_id']] =
                        (string) $row['achieved_at'];
                }
            }
        }
        return $maps;
    }

    /**
     * @param list<array<string, mixed>> $skaters
     * @param array<string, list<array<string, mixed>>> $curriculum
     * @param array<string, array<int, array<int, string>>> $maps
     * @param list<string> $columns
     * @return array{0:list<string>,1:list<list<mixed>>}
     */
    private function rows(array $skaters, array $curriculum, array $maps, array $columns): array
    {
        $include = static function (string $column) use ($columns): bool {
            return in_array($column, $columns, true);
        };
        $headers = [];
        if ($include('name')) $headers = array_merge($headers, ['First Name', 'Last Name']);
        if ($include('canskate_number')) $headers[] = 'Skate Canada Number';
        if ($include('date_of_birth')) $headers[] = 'Date of Birth';
        if ($include('gender')) $headers[] = 'Gender';
        if ($include('guardian_info')) $headers = array_merge($headers, ['Guardian Name', 'Guardian Email', 'Guardian Phone']);
        if ($include('general_notes')) $headers[] = 'General Notes';
        if ($include('medical_notes')) $headers[] = 'Medical / Accommodation Notes';
        if ($include('skills')) {
            foreach ($curriculum['skills'] as $skill) {
                $code = trim((string) ($skill['skill_code'] ?? ''));
                $headers[] = 'Skill | ' . $this->stageLabel($skill) . ' | '
                    . $skill['ribbon_name'] . ' | ' . $skill['category_name'] . ' | '
                    . $skill['skill_name'] . ($code === '' ? '' : ' [' . $code . ']');
            }
        }
        if ($include('ribbons')) {
            foreach ($curriculum['ribbons'] as $ribbon) {
                $headers[] = 'Ribbon | ' . $this->stageLabel($ribbon) . ' | ' . $ribbon['ribbon_name'];
            }
        }
        if ($include('badges')) {
            foreach ($curriculum['badges'] as $badge) {
                $headers[] = 'Badge | ' . $this->stageLabel($badge);
            }
        }

        $rows = [];
        foreach ($skaters as $skater) {
            $id = (int) $skater['id'];
            $row = [];
            if ($include('name')) $row = array_merge($row, [$skater['first_name'], $skater['last_name']]);
            if ($include('canskate_number')) $row[] = $skater['skate_canada_number'];
            if ($include('date_of_birth')) $row[] = $skater['date_of_birth'];
            if ($include('gender')) $row[] = $skater['gender_name'];
            if ($include('guardian_info')) $row = array_merge($row, [
                $skater['parent_guardian_name'], $skater['parent_guardian_email'], $skater['parent_guardian_phone'],
            ]);
            if ($include('general_notes')) $row[] = $skater['general_notes'];
            if ($include('medical_notes')) $row[] = $skater['medical_notes'];
            if ($include('skills')) {
                foreach ($curriculum['skills'] as $skill) {
                    $row[] = $maps['skills'][$id][(int) $skill['id']] ?? null;
                }
            }
            if ($include('ribbons')) {
                foreach ($curriculum['ribbons'] as $ribbon) {
                    $row[] = $maps['ribbons'][$id][(int) $ribbon['id']] ?? null;
                }
            }
            if ($include('badges')) {
                foreach ($curriculum['badges'] as $badge) {
                    $row[] = $maps['badges'][$id][(int) $badge['id']] ?? null;
                }
            }
            $rows[] = $row;
        }
        return [$headers, $rows];
    }

    /** @param array<string, mixed> $filters @return list<string> */
    private function selectedColumns(array $filters): array
    {
        $allowed = [
            'name', 'canskate_number', 'date_of_birth', 'gender', 'guardian_info',
            'general_notes', 'medical_notes', 'skills', 'ribbons', 'badges',
        ];
        $raw = $filters['columns'] ?? null;
        if ($raw === null && empty($filters['columns_selected'])) {
            return $allowed;
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException('Select at least one column to include.');
        }
        $selected = array_values(array_unique(array_filter(
            array_map('strval', $raw),
            static function (string $column) use ($allowed): bool {
                return in_array($column, $allowed, true);
            }
        )));
        if ($selected === []) {
            throw new InvalidArgumentException('Select at least one column to include.');
        }
        return $selected;
    }

    /** @param array<string, mixed> $stage */
    private function stageLabel(array $stage): string
    {
        return (int) $stage['stage_number'] === 0 ? 'Pre-CanSkate' : 'Stage ' . (int) $stage['stage_number'];
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The CSV export could not be created.');
        }
        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, array_map([$this, 'safeCsvValue'], $row));
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param mixed $value */
    private function safeCsvValue($value): string
    {
        $value = $value === null ? '' : (string) $value;
        return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function writeXlsx(string $path, array $headers, array $rows): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The Excel export could not be created.');
        }
        try {
            $lastColumn = $this->excelColumn(count($headers));
            $lastRow = count($rows) + 1;
            $sheetPath = $this->temporaryPath('achievement-sheet-');
            $sheet = fopen($sheetPath, 'wb');
            if ($sheet === false) {
                throw new RuntimeException('The Excel worksheet could not be created.');
            }
            try {
                fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
                fwrite($sheet, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
                fwrite($sheet, '<dimension ref="A1:' . $lastColumn . $lastRow . '"/>');
                fwrite($sheet, '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
                fwrite($sheet, '<cols>');
                foreach ($headers as $index => $header) {
                    $column = $index + 1;
                    $width = $this->xlsxColumnWidth($header);
                    fwrite($sheet, '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>');
                }
                fwrite($sheet, '</cols><sheetData>');
                $this->writeXlsxRow($sheet, 1, $headers, true);
                foreach ($rows as $index => $row) {
                    $this->writeXlsxRow($sheet, $index + 2, $row, false);
                }
                fwrite($sheet, '</sheetData><autoFilter ref="A1:' . $lastColumn . $lastRow . '"/>');
                fwrite($sheet, '</worksheet>');
            } finally {
                fclose($sheet);
            }

            $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
            $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
            $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
            $zip->addFromString('docProps/core.xml', $this->corePropertiesXml());
            $zip->addFromString('xl/workbook.xml', $this->workbookXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
            $zip->addFromString('xl/styles.xml', $this->stylesXml());
            $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
            if (!$zip->close()) {
                throw new RuntimeException('The Excel export could not be finalized.');
            }
            @unlink($sheetPath);
        } catch (Throwable $exception) {
            $zip->close();
            if (isset($sheetPath)) {
                @unlink($sheetPath);
            }
            throw $exception;
        }
    }

    /** @param resource $handle @param list<mixed> $values */
    private function writeXlsxRow($handle, int $rowNumber, array $values, bool $header): void
    {
        fwrite($handle, '<row r="' . $rowNumber . '"' . ($header ? ' ht="108" customHeight="1"' : '') . '>');
        foreach ($values as $index => $value) {
            $reference = $this->excelColumn($index + 1) . $rowNumber;
            if (!$header && $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $value) === 1) {
                fwrite($handle, '<c r="' . $reference . '" s="2"><v>' . $this->excelDate((string) $value) . '</v></c>');
                continue;
            }
            $text = $value === null ? '' : (string) $value;
            $text = mb_substr($text, 0, 32767);
            fwrite($handle, '<c r="' . $reference . '" s="' . ($header ? '1' : '0') . '" t="inlineStr"><is><t xml:space="preserve">'
                . $this->xml($text) . '</t></is></c>');
        }
        fwrite($handle, '</row>');
    }

    private function excelDate(string $date): int
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($value === false) {
            return 0;
        }
        $epoch = new DateTimeImmutable('1899-12-30');
        return (int) $epoch->diff($value)->format('%r%a');
    }

    private function xlsxColumnWidth(string $header): int
    {
        if ($header === 'First Name' || $header === 'Last Name' || $header === 'Guardian Phone') {
            return 18;
        }
        if ($header === 'Skate Canada Number') {
            return 21;
        }
        if ($header === 'Date of Birth' || $header === 'Gender') {
            return 14;
        }
        if ($header === 'Guardian Name') {
            return 22;
        }
        if ($header === 'Guardian Email') {
            return 30;
        }
        if ($header === 'General Notes' || $header === 'Medical / Accommodation Notes') {
            return 40;
        }
        return 16;
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    }

    private function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Skater Achievements" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Aptos"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF173B5E"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function appPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>CanSkate Achievement Tracker</Application></Properties>';
    }

    private function corePropertiesXml(): string
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Skater Achievements</dc:title><dc:creator>CanSkate Achievement Tracker</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified></cp:coreProperties>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function excelColumn(int $number): string
    {
        $column = '';
        while ($number > 0) {
            $number--;
            $column = chr(65 + ($number % 26)) . $column;
            $number = intdiv($number, 26);
        }
        return $column;
    }

    private function temporaryPath(string $prefix): string
    {
        $directory = dirname(__DIR__) . '/tmp';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('The export folder is not available.');
        }
        $path = tempnam($directory, $prefix);
        if ($path === false) {
            throw new RuntimeException('The export file could not be prepared.');
        }
        return $path;
    }

    /** @param mixed $value */
    private function requiredId($value, string $message): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException($message);
        }
        return (int) $id;
    }

    /** @param mixed $value */
    private function optionalId($value, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->requiredId($value, $message);
    }

    /** @param mixed $value */
    private function optionalInteger($value, int $minimum, int $maximum, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);
        if ($integer === false) {
            throw new InvalidArgumentException($message);
        }
        return (int) $integer;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function integerIds(array $row): array
    {
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function sessionIds(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['season_id'] = (int) $row['season_id'];
        $row['day_of_week'] = (int) $row['day_of_week'];
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function groupIds(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['program_session_id'] = (int) $row['program_session_id'];
        $row['season_id'] = (int) $row['season_id'];
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function badgeIds(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['stage_number'] = (int) $row['stage_number'];
        return $row;
    }
}
