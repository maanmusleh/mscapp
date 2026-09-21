<?php

declare(strict_types=1);

final class LegacyAchievementImportService
{
    private const MAX_BYTES = 10_485_760;
    private const MAX_ROWS = 10_000;
    private const HEADER_COUNT = 26;
    private const HEADERS = [
        'LAST', 'FIRST', 'M/F', 'SESSION', 'DOB', 'PHONE #',
        'S1_BALANCE', 'S1_CONTROL', 'S1_AGILITY', 'S1_PASSED',
        'S2_BALANCE', 'S2_CONTROL', 'S2_AGILITY', 'S2_PASSED',
        'S3_BALANCE', 'S3_CONTROL', 'S3_AGILITY', 'S3_PASSED',
        'S4_BALANCE', 'S4_CONTROL', 'S4_AGILITY', 'S4_PASSED',
        'S5_BALANCE', 'S5_CONTROL', 'S5_AGILITY', 'S5_PASSED',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return list<array<string, mixed>> */
    public function seasons(int $clubId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, start_date, end_date, active
             FROM season
             WHERE club_id = :club_id AND deleted_at IS NULL
             ORDER BY CASE WHEN end_date >= CURDATE() THEN 0 ELSE 1 END,
                      CASE WHEN end_date >= CURDATE() THEN start_date END DESC,
                      CASE WHEN end_date < CURDATE() THEN end_date END DESC,
                      name'
        );
        $statement->execute(['club_id' => $clubId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function import(int $clubId, int $userId, int $seasonId, array $file): array
    {
        $season = $this->season($clubId, $seasonId);
        $this->validateUpload($file);
        $rows = $this->parseXlsx((string) $file['tmp_name']);
        $filename = (string) ($file['name'] ?? 'canskate-import.xlsx');
        $errors = [];

        if ($rows === []) {
            $errors[] = 'The workbook’s first worksheet is empty.';
            return $this->validationFailure($filename, $season, 0, $errors);
        }

        $grid = [];
        foreach ($rows as $row) {
            $grid[(int) $row['_row_number']] = $row['values'];
        }
        $headerErrors = $this->headerErrors($grid[1] ?? []);
        if ($headerErrors !== []) {
            return $this->validationFailure($filename, $season, 0, $headerErrors);
        }

        $genderMap = $this->genderMap();
        if (!isset($genderMap['M'])) {
            $errors[] = 'CAT does not have an active Male gender value configured.';
        }
        if (!isset($genderMap['F'])) {
            $errors[] = 'CAT does not have an active Female gender value configured.';
        }

        $curriculum = $this->curriculum($errors);
        $preparedRows = [];
        $seenEnrollments = [];
        $identityProfiles = [];
        $dataRowCount = 0;

        foreach ($rows as $rawRow) {
            $rowNumber = (int) $rawRow['_row_number'];
            if ($rowNumber <= 1 || $this->rowIsBlank($rawRow['values'])) {
                continue;
            }
            $dataRowCount++;
            $values = $rawRow['values'];
            $rowErrors = [];

            foreach (array_slice($values, self::HEADER_COUNT, null, true) as $column => $value) {
                if (trim((string) $value) !== '') {
                    $rowErrors[] = 'column ' . $this->excelColumn((int) $column) . ' contains data outside the template';
                }
            }

            $lastName = trim((string) ($values[0] ?? ''));
            $firstName = trim((string) ($values[1] ?? ''));
            $genderCode = strtoupper(trim((string) ($values[2] ?? '')));
            $sku = trim((string) ($values[3] ?? ''));
            $birthdate = $this->importDate($values[4] ?? null);
            $phone = trim((string) ($values[5] ?? ''));

            if ($lastName === '') {
                $rowErrors[] = 'LAST is required';
            } elseif (mb_strlen($lastName) > 100) {
                $rowErrors[] = 'LAST must be 100 characters or fewer';
            }
            if ($firstName === '') {
                $rowErrors[] = 'FIRST is required';
            } elseif (mb_strlen($firstName) > 100) {
                $rowErrors[] = 'FIRST must be 100 characters or fewer';
            }
            if (!in_array($genderCode, ['', 'M', 'F'], true)) {
                $rowErrors[] = 'M/F must contain M, F, or be blank';
            } elseif ($genderCode !== '' && !isset($genderMap[$genderCode])) {
                $rowErrors[] = "M/F value {$genderCode} is not configured in CAT";
            }
            if ($sku === '') {
                $rowErrors[] = 'SESSION is required';
            } elseif (mb_strlen($sku) > 64) {
                $rowErrors[] = 'SESSION must be 64 characters or fewer';
            }
            if ($birthdate === null) {
                $rowErrors[] = 'DOB is required and must be a valid Excel date or recognizable calendar date';
            } elseif ($birthdate > date('Y-m-d')) {
                $rowErrors[] = 'DOB cannot be in the future';
            }
            if (mb_strlen($phone) > 40) {
                $rowErrors[] = 'PHONE # must be 40 characters or fewer';
            }

            $achievementDates = [];
            for ($column = 6; $column < self::HEADER_COUNT; $column++) {
                $header = self::HEADERS[$column];
                $rawValue = $values[$column] ?? '';
                if ($this->isBlankAchievement($rawValue)) {
                    $achievementDates[$header] = null;
                    continue;
                }
                $achievementDate = $this->achievementMonthEnd($rawValue);
                if ($achievementDate === null) {
                    $rowErrors[] = 'column ' . $this->excelColumn($column) . " ({$header}) must be a date, NP, or blank";
                    $achievementDates[$header] = null;
                    continue;
                }
                $achievementDates[$header] = $achievementDate;
            }

            if ($rowErrors !== []) {
                $errors[] = 'Row ' . $rowNumber . ': ' . implode('; ', array_unique($rowErrors)) . '.';
                continue;
            }

            $identityKey = mb_strtolower($firstName) . "\0" . mb_strtolower($lastName) . "\0" . $birthdate;
            $enrollmentKey = $identityKey . "\0" . mb_strtolower($sku);
            if (isset($seenEnrollments[$enrollmentKey])) {
                $errors[] = "Row {$rowNumber}: duplicate skater/session record; it duplicates row {$seenEnrollments[$enrollmentKey]}.";
                continue;
            }
            $seenEnrollments[$enrollmentKey] = $rowNumber;

            if (isset($identityProfiles[$identityKey])) {
                $profile = $identityProfiles[$identityKey];
                if ($genderCode !== '' && $profile['gender'] !== '' && $genderCode !== $profile['gender']) {
                    $errors[] = "Row {$rowNumber}: M/F conflicts with row {$profile['row_number']} for the same skater.";
                }
                if ($phone !== '' && $profile['phone'] !== '' && $phone !== $profile['phone']) {
                    $errors[] = "Row {$rowNumber}: PHONE # conflicts with row {$profile['row_number']} for the same skater.";
                }
                if ($profile['gender'] === '' && $genderCode !== '') {
                    $identityProfiles[$identityKey]['gender'] = $genderCode;
                }
                if ($profile['phone'] === '' && $phone !== '') {
                    $identityProfiles[$identityKey]['phone'] = $phone;
                }
            } else {
                $identityProfiles[$identityKey] = [
                    'row_number' => $rowNumber,
                    'gender' => $genderCode,
                    'phone' => $phone,
                ];
            }

            $preparedRows[] = [
                'row_number' => $rowNumber,
                'identity_key' => $identityKey,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'gender_id' => $genderCode === '' ? null : $genderMap[$genderCode],
                'sku' => $sku,
                'date_of_birth' => $birthdate,
                'phone' => $phone === '' ? null : $phone,
                'achievement_dates' => $achievementDates,
                'existing_skater_id' => null,
            ];
        }

        if ($dataRowCount > self::MAX_ROWS) {
            $errors[] = 'The import contains ' . number_format($dataRowCount)
                . ' data rows; the maximum is ' . number_format(self::MAX_ROWS) . '.';
        }
        if ($dataRowCount === 0) {
            $errors[] = 'The workbook contains no skater rows below the header.';
        }

        $this->preflightDatabase($clubId, $seasonId, $preparedRows, $errors);
        if ($errors !== []) {
            return $this->validationFailure($filename, $season, $dataRowCount, array_values(array_unique($errors)));
        }

        $stats = $this->writeImport($clubId, $userId, $season, $preparedRows, $curriculum);
        return [
            'status' => 'imported',
            'filename' => $filename,
            'season_id' => (int) $season['id'],
            'season_name' => (string) $season['name'],
            'row_count' => $dataRowCount,
            'error_count' => 0,
            'errors' => [],
            'stats' => $stats,
        ];
    }

    /** @return array<string, int> */
    private function writeImport(int $clubId, int $userId, array $season, array $rows, array $curriculum): array
    {
        $stats = [
            'rows_imported' => count($rows),
            'skaters_created' => 0,
            'skaters_updated' => 0,
            'sessions_created' => 0,
            'sessions_restored' => 0,
            'enrollments_recorded' => 0,
            'skills_added' => 0,
            'skill_dates_updated' => 0,
            'ribbons_recorded' => 0,
            'badges_recorded' => 0,
        ];
        $this->pdo->beginTransaction();
        try {
            $findSession = $this->pdo->prepare(
                'SELECT id, club_id, season_id, deleted_at
                 FROM program_session WHERE sku = :sku LIMIT 1 FOR UPDATE'
            );
            $createSession = $this->pdo->prepare(
                'INSERT INTO program_session (
                    club_id, season_id, sku, name, day_of_week, start_time, end_time,
                    location, active, created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :club_id, :season_id, :sku, :name, 1, "00:00:00", "00:01:00",
                    NULL, 1, :created_by_user_id, :updated_by_user_id
                 )'
            );
            $restoreSession = $this->pdo->prepare(
                'UPDATE program_session SET active = 1, deleted_at = NULL, updated_by_user_id = :user_id WHERE id = :id'
            );
            $insertSkater = $this->pdo->prepare(
                'INSERT INTO skater (
                    club_id, gender_id, first_name, last_name, date_of_birth,
                    parent_guardian_phone, active, created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :club_id, :gender_id, :first_name, :last_name, :date_of_birth,
                    :phone, 1, :created_by_user_id, :updated_by_user_id
                 )'
            );
            $updateSkater = $this->pdo->prepare(
                'UPDATE skater
                 SET gender_id = COALESCE(:gender_id, gender_id),
                     parent_guardian_phone = COALESCE(:phone, parent_guardian_phone),
                     active = 1, updated_by_user_id = :user_id
                 WHERE id = :id AND club_id = :club_id AND deleted_at IS NULL'
            );
            $enroll = $this->pdo->prepare(
                'INSERT INTO skater_enrollment (
                    skater_id, program_session_id, registration_date, active,
                    created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :skater_id, :session_id, :registration_date, 1,
                    :created_by_user_id, :updated_by_user_id
                 )
                 ON DUPLICATE KEY UPDATE
                    registration_date = LEAST(registration_date, VALUES(registration_date)),
                    active = 1, deleted_at = NULL, updated_by_user_id = VALUES(updated_by_user_id)'
            );
            $loadSkills = $this->pdo->prepare(
                'SELECT id, canskate_skill_id, achievement_date FROM skater_skill WHERE skater_id = :skater_id'
            );
            $history = $this->pdo->prepare(
                'INSERT INTO assessment_history (
                    program_date_id, coach_assignment_id, skater_id, canskate_skill_id,
                    assessment_result_id, notes, assessed_at, created_by_user_id
                 ) VALUES (
                    NULL, NULL, :skater_id, :skill_id, :result_id, :notes, :assessed_at, :user_id
                 )'
            );
            $insertSkill = $this->pdo->prepare(
                'INSERT INTO skater_skill (
                    skater_id, canskate_skill_id, assessment_history_id, achievement_date,
                    achieved_by_coach_id, notes, created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :skater_id, :skill_id, :history_id, :achievement_date,
                    NULL, :notes, :created_by_user_id, :updated_by_user_id
                 )'
            );
            $updateSkillDate = $this->pdo->prepare(
                'UPDATE skater_skill
                 SET achievement_date = :achievement_date, updated_by_user_id = :user_id
                 WHERE id = :id'
            );
            $loadRibbons = $this->pdo->prepare(
                'SELECT canskate_ribbon_id, awarded_at, revoked_at FROM skater_ribbon WHERE skater_id = :skater_id'
            );
            $insertRibbon = $this->pdo->prepare(
                'INSERT INTO skater_ribbon (
                    skater_id, canskate_ribbon_id, awarded_at, notes,
                    created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :skater_id, :award_id, :awarded_at, :notes,
                    :created_by_user_id, :updated_by_user_id
                 )'
            );
            $updateRibbon = $this->pdo->prepare(
                'UPDATE skater_ribbon
                 SET awarded_at = LEAST(awarded_at, :awarded_at), revoked_at = NULL,
                     revoked_by_user_id = NULL, updated_by_user_id = :user_id
                 WHERE skater_id = :skater_id AND canskate_ribbon_id = :award_id'
            );
            $loadBadges = $this->pdo->prepare(
                'SELECT canskate_stage_id, awarded_at, revoked_at FROM skater_badge WHERE skater_id = :skater_id'
            );
            $insertBadge = $this->pdo->prepare(
                'INSERT INTO skater_badge (
                    skater_id, canskate_stage_id, awarded_at, notes,
                    created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :skater_id, :award_id, :awarded_at, :notes,
                    :created_by_user_id, :updated_by_user_id
                 )'
            );
            $updateBadge = $this->pdo->prepare(
                'UPDATE skater_badge
                 SET awarded_at = LEAST(awarded_at, :awarded_at), revoked_at = NULL,
                     revoked_by_user_id = NULL, updated_by_user_id = :user_id
                 WHERE skater_id = :skater_id AND canskate_stage_id = :award_id'
            );
            $achievedResultId = (int) $this->pdo->query(
                'SELECT id FROM assessment_result WHERE code = "ACHIEVED" AND active = 1 LIMIT 1'
            )->fetchColumn();
            if ($achievedResultId <= 0) {
                throw new RuntimeException('The Achieved assessment result is not configured.');
            }

            $sessionIds = [];
            $skaterIds = [];
            $skaterWasExisting = [];
            $skaterUpdated = [];
            $skillsBySkater = [];
            $ribbonsBySkater = [];
            $badgesBySkater = [];

            foreach ($rows as $row) {
                $skuKey = mb_strtolower((string) $row['sku']);
                if (!isset($sessionIds[$skuKey])) {
                    $findSession->execute(['sku' => $row['sku']]);
                    $session = $findSession->fetch();
                    if ($session === false) {
                        $createSession->execute([
                            'club_id' => $clubId,
                            'season_id' => $season['id'],
                            'sku' => $row['sku'],
                            'name' => $row['sku'],
                            'created_by_user_id' => $userId,
                            'updated_by_user_id' => $userId,
                        ]);
                        $sessionIds[$skuKey] = (int) $this->pdo->lastInsertId();
                        $stats['sessions_created']++;
                    } else {
                        if ((int) $session['club_id'] !== $clubId || (int) $session['season_id'] !== (int) $season['id']) {
                            throw new RuntimeException(
                                'A SESSION SKU changed after validation. Nothing was imported; validate the workbook again.'
                            );
                        }
                        $sessionIds[$skuKey] = (int) $session['id'];
                        if ($session['deleted_at'] !== null) {
                            $restoreSession->execute(['user_id' => $userId, 'id' => $session['id']]);
                            $stats['sessions_restored']++;
                        }
                    }
                }

                $identityKey = (string) $row['identity_key'];
                if (!isset($skaterIds[$identityKey])) {
                    $existingSkaterId = $this->activeSkaterIdForImport($clubId, $row);
                    if ($existingSkaterId === null) {
                        $insertSkater->execute([
                            'club_id' => $clubId,
                            'gender_id' => $row['gender_id'],
                            'first_name' => $row['first_name'],
                            'last_name' => $row['last_name'],
                            'date_of_birth' => $row['date_of_birth'],
                            'phone' => $row['phone'],
                            'created_by_user_id' => $userId,
                            'updated_by_user_id' => $userId,
                        ]);
                        $skaterIds[$identityKey] = (int) $this->pdo->lastInsertId();
                        $stats['skaters_created']++;
                        $skaterWasExisting[$identityKey] = false;
                        $this->recordAudit(
                            $skaterIds[$identityKey],
                            'ADDED',
                            'Skater record added by historical data import.',
                            $userId
                        );
                    } else {
                        $skaterIds[$identityKey] = $existingSkaterId;
                        $skaterWasExisting[$identityKey] = true;
                    }
                }
                $skaterId = $skaterIds[$identityKey];
                $updateSkater->execute([
                    'gender_id' => $row['gender_id'],
                    'phone' => $row['phone'],
                    'user_id' => $userId,
                    'id' => $skaterId,
                    'club_id' => $clubId,
                ]);
                if (!isset($skaterUpdated[$skaterId]) && $skaterWasExisting[$identityKey]) {
                    $skaterUpdated[$skaterId] = true;
                    $stats['skaters_updated']++;
                }

                $enroll->execute([
                    'skater_id' => $skaterId,
                    'session_id' => $sessionIds[$skuKey],
                    'registration_date' => $season['start_date'],
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                ]);
                $stats['enrollments_recorded']++;

                if (!isset($skillsBySkater[$skaterId])) {
                    $loadSkills->execute(['skater_id' => $skaterId]);
                    $skillsBySkater[$skaterId] = [];
                    foreach ($loadSkills->fetchAll() as $skill) {
                        $skillsBySkater[$skaterId][(int) $skill['canskate_skill_id']] = $skill;
                    }
                }
                if (!isset($ribbonsBySkater[$skaterId])) {
                    $loadRibbons->execute(['skater_id' => $skaterId]);
                    $ribbonsBySkater[$skaterId] = [];
                    foreach ($loadRibbons->fetchAll() as $ribbon) {
                        $ribbonsBySkater[$skaterId][(int) $ribbon['canskate_ribbon_id']] = $ribbon;
                    }
                }
                if (!isset($badgesBySkater[$skaterId])) {
                    $loadBadges->execute(['skater_id' => $skaterId]);
                    $badgesBySkater[$skaterId] = [];
                    foreach ($loadBadges->fetchAll() as $badge) {
                        $badgesBySkater[$skaterId][(int) $badge['canskate_stage_id']] = $badge;
                    }
                }

                foreach ($row['achievement_dates'] as $header => $achievementDate) {
                    if ($achievementDate === null) {
                        continue;
                    }
                    $awardTimestamp = $achievementDate . ' 12:00:00';
                    if (string_ends_with($header, '_PASSED')) {
                        $stageNumber = (int) substr($header, 1, 1);
                        $stageId = (int) $curriculum[$stageNumber]['stage_id'];
                        if (isset($badgesBySkater[$skaterId][$stageId])) {
                            $updateBadge->execute([
                                'awarded_at' => $awardTimestamp,
                                'user_id' => $userId,
                                'skater_id' => $skaterId,
                                'award_id' => $stageId,
                            ]);
                        } else {
                            $insertBadge->execute([
                                'skater_id' => $skaterId,
                                'award_id' => $stageId,
                                'awarded_at' => $awardTimestamp,
                                'notes' => 'Stage badge date imported from historical records.',
                                'created_by_user_id' => $userId,
                                'updated_by_user_id' => $userId,
                            ]);
                            $badgesBySkater[$skaterId][$stageId] = ['awarded_at' => $awardTimestamp, 'revoked_at' => null];
                        }
                        $stats['badges_recorded']++;
                        continue;
                    }

                    $stageNumber = (int) substr($header, 1, 1);
                    $category = strtolower((string) substr($header, 3));
                    $ribbon = $curriculum[$stageNumber]['ribbons'][$category];
                    foreach ($ribbon['skill_ids'] as $skillId) {
                        $skillId = (int) $skillId;
                        if (!isset($skillsBySkater[$skaterId][$skillId])) {
                            $notes = 'Achievement date imported from historical ' . ucfirst($category) . ' ribbon records.';
                            $history->execute([
                                'skater_id' => $skaterId,
                                'skill_id' => $skillId,
                                'result_id' => $achievedResultId,
                                'notes' => '[Historical data import] ' . $notes,
                                'assessed_at' => $awardTimestamp,
                                'user_id' => $userId,
                            ]);
                            $insertSkill->execute([
                                'skater_id' => $skaterId,
                                'skill_id' => $skillId,
                                'history_id' => (int) $this->pdo->lastInsertId(),
                                'achievement_date' => $achievementDate,
                                'notes' => $notes,
                                'created_by_user_id' => $userId,
                                'updated_by_user_id' => $userId,
                            ]);
                            $skillsBySkater[$skaterId][$skillId] = [
                                'id' => (int) $this->pdo->lastInsertId(),
                                'achievement_date' => $achievementDate,
                            ];
                            $stats['skills_added']++;
                        } elseif ($achievementDate < (string) $skillsBySkater[$skaterId][$skillId]['achievement_date']) {
                            $updateSkillDate->execute([
                                'achievement_date' => $achievementDate,
                                'user_id' => $userId,
                                'id' => $skillsBySkater[$skaterId][$skillId]['id'],
                            ]);
                            $skillsBySkater[$skaterId][$skillId]['achievement_date'] = $achievementDate;
                            $stats['skill_dates_updated']++;
                        }
                    }

                    $ribbonId = (int) $ribbon['ribbon_id'];
                    if (isset($ribbonsBySkater[$skaterId][$ribbonId])) {
                        $updateRibbon->execute([
                            'awarded_at' => $awardTimestamp,
                            'user_id' => $userId,
                            'skater_id' => $skaterId,
                            'award_id' => $ribbonId,
                        ]);
                    } else {
                        $insertRibbon->execute([
                            'skater_id' => $skaterId,
                            'award_id' => $ribbonId,
                            'awarded_at' => $awardTimestamp,
                            'notes' => ucfirst($category) . ' ribbon date imported from historical records.',
                            'created_by_user_id' => $userId,
                            'updated_by_user_id' => $userId,
                        ]);
                        $ribbonsBySkater[$skaterId][$ribbonId] = ['awarded_at' => $awardTimestamp, 'revoked_at' => null];
                    }
                    $stats['ribbons_recorded']++;
                }
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $stats;
    }

    private function preflightDatabase(int $clubId, int $seasonId, array &$rows, array &$errors): void
    {
        $findSession = $this->pdo->prepare(
            'SELECT club_id, season_id FROM program_session WHERE sku = :sku LIMIT 1'
        );
        $findSkater = $this->pdo->prepare(
            'SELECT id FROM skater
             WHERE club_id = :club_id AND first_name = :first_name
               AND last_name = :last_name AND date_of_birth = :date_of_birth
               AND deleted_at IS NULL
             ORDER BY id
             LIMIT 3'
        );
        $checkedSkus = [];
        $checkedSkaters = [];
        foreach ($rows as &$row) {
            $skuKey = mb_strtolower((string) $row['sku']);
            if (!isset($checkedSkus[$skuKey])) {
                $findSession->execute(['sku' => $row['sku']]);
                $session = $findSession->fetch();
                if ($session !== false && (int) $session['club_id'] !== $clubId) {
                    $errors[] = "Row {$row['row_number']}: SESSION “{$row['sku']}” belongs to another club.";
                } elseif ($session !== false && (int) $session['season_id'] !== $seasonId) {
                    $errors[] = "Row {$row['row_number']}: SESSION “{$row['sku']}” already belongs to a different season.";
                }
                $checkedSkus[$skuKey] = true;
            }

            $identityKey = (string) $row['identity_key'];
            if (!array_key_exists($identityKey, $checkedSkaters)) {
                $findSkater->execute([
                    'club_id' => $clubId,
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'date_of_birth' => $row['date_of_birth'],
                ]);
                $ids = array_map('intval', $findSkater->fetchAll(PDO::FETCH_COLUMN));
                if (count($ids) > 1) {
                    $errors[] = "Row {$row['row_number']}: more than one existing CAT skater has this FIRST, LAST, and DOB; merge or correct those records before importing.";
                    $checkedSkaters[$identityKey] = null;
                } else {
                    $checkedSkaters[$identityKey] = $ids[0] ?? null;
                }
            }
            $row['existing_skater_id'] = $checkedSkaters[$identityKey];
        }
        unset($row);
    }

    /**
     * Resolves a current profile from inside the import transaction. The lock
     * prevents a delete from changing that profile before its enrollment is
     * written; the database unique key closes the concurrent-create gap.
     *
     * @param array<string, mixed> $row
     */
    private function activeSkaterIdForImport(int $clubId, array $row): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM skater
             WHERE club_id = :club_id AND first_name = :first_name
               AND last_name = :last_name AND date_of_birth = :date_of_birth
               AND deleted_at IS NULL
             ORDER BY id
             LIMIT 2
             FOR UPDATE'
        );
        $statement->execute([
            'club_id' => $clubId,
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'date_of_birth' => $row['date_of_birth'],
        ]);
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) > 1) {
            throw new RuntimeException(
                'More than one active CAT skater now has the same FIRST, LAST, and DOB. Nothing was imported; resolve the duplicate profiles and validate the workbook again.'
            );
        }
        return $ids[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function curriculum(array &$errors): array
    {
        $statement = $this->pdo->query(
            'SELECT st.id AS stage_id, st.stage_number, r.id AS ribbon_id,
                    r.name AS ribbon_name, sk.id AS skill_id
             FROM canskate_stage st
             INNER JOIN canskate_ribbon r
                ON r.canskate_stage_id = st.id AND r.active = 1 AND r.deleted_at IS NULL
             INNER JOIN canskate_category c
                ON c.canskate_ribbon_id = r.id AND c.active = 1 AND c.deleted_at IS NULL
             INNER JOIN canskate_skill sk
                ON sk.canskate_category_id = c.id AND sk.active = 1 AND sk.deleted_at IS NULL
             WHERE st.stage_number BETWEEN 1 AND 5 AND st.deleted_at IS NULL
             ORDER BY st.stage_number, r.display_order, c.display_order, sk.display_order, sk.id'
        );
        $curriculum = [];
        foreach ($statement->fetchAll() as $row) {
            $stage = (int) $row['stage_number'];
            $ribbon = strtolower(trim((string) $row['ribbon_name']));
            if (!in_array($ribbon, ['balance', 'control', 'agility'], true)) {
                continue;
            }
            $curriculum[$stage]['stage_id'] = (int) $row['stage_id'];
            $curriculum[$stage]['ribbons'][$ribbon]['ribbon_id'] = (int) $row['ribbon_id'];
            $curriculum[$stage]['ribbons'][$ribbon]['skill_ids'][] = (int) $row['skill_id'];
        }
        for ($stage = 1; $stage <= 5; $stage++) {
            foreach (['balance', 'control', 'agility'] as $ribbon) {
                if (empty($curriculum[$stage]['ribbons'][$ribbon]['skill_ids'])) {
                    $errors[] = "CAT’s active Stage {$stage} " . ucfirst($ribbon)
                        . ' curriculum is missing; the import cannot safely map ribbon dates to skills.';
                }
            }
        }
        return $curriculum;
    }

    /** @return array<string, int> */
    private function genderMap(): array
    {
        $map = [];
        foreach ($this->pdo->query('SELECT id, code, name FROM gender WHERE active = 1')->fetchAll() as $row) {
            $code = strtoupper(trim((string) $row['code']));
            $name = strtoupper(trim((string) $row['name']));
            if ($code === 'M' || $name === 'MALE') {
                $map['M'] = (int) $row['id'];
            }
            if ($code === 'F' || $name === 'FEMALE') {
                $map['F'] = (int) $row['id'];
            }
        }
        return $map;
    }

    /** @return array<string, mixed> */
    private function season(int $clubId, int $seasonId): array
    {
        if ($seasonId <= 0) {
            throw new InvalidArgumentException('Select the season that will receive every imported record.');
        }
        $statement = $this->pdo->prepare(
            'SELECT id, name, start_date, end_date FROM season
             WHERE id = :id AND club_id = :club_id AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['id' => $seasonId, 'club_id' => $clubId]);
        $season = $statement->fetch();
        if ($season === false) {
            throw new InvalidArgumentException('Select a valid season for this club.');
        }
        return $season;
    }

    private function validateUpload(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Choose the historical CanSkate Excel workbook to import.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('The Excel workbook must be larger than 0 bytes and no larger than 10 MB.');
        }
        if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('The historical data import requires an .xlsx workbook.');
        }
        $path = (string) ($file['tmp_name'] ?? '');
        $verified = $path !== '' && is_uploaded_file($path);
        if (!$verified && PHP_SAPI === 'cli') {
            $verified = $path !== '' && is_file($path) && is_readable($path);
        }
        if (!$verified) {
            throw new InvalidArgumentException('The uploaded workbook could not be verified.');
        }
    }

    /** @return list<string> */
    private function headerErrors(array $headers): array
    {
        $errors = [];
        foreach (self::HEADERS as $column => $expected) {
            $actual = trim((string) ($headers[$column] ?? ''));
            if ($this->normalizeHeader($actual) !== $this->normalizeHeader($expected)) {
                $shown = $actual === '' ? '[blank]' : '“' . $actual . '”';
                $errors[] = 'Header ' . $this->excelColumn($column) . " must be {$expected}; found {$shown}.";
            }
        }
        foreach (array_slice($headers, self::HEADER_COUNT, null, true) as $column => $value) {
            if (trim((string) $value) !== '') {
                $errors[] = 'Header ' . $this->excelColumn((int) $column) . ' is outside the A–Z template and must be removed.';
            }
        }
        return $errors;
    }

    /** @return array<string, mixed> */
    private function validationFailure(string $filename, array $season, int $rowCount, array $errors): array
    {
        return [
            'status' => 'validation_failed',
            'filename' => $filename,
            'season_id' => (int) $season['id'],
            'season_name' => (string) $season['name'],
            'row_count' => $rowCount,
            'error_count' => count($errors),
            'errors' => $errors,
            'stats' => [],
        ];
    }

    /** @return list<array{_row_number:int, values:list<mixed>}> */
    private function parseXlsx(string $path): array
    {
        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
            throw new RuntimeException('Excel imports require the PHP ZIP and SimpleXML extensions.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('The uploaded Excel workbook could not be opened.');
        }
        try {
            $sharedStrings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $xml = simplexml_load_string($sharedXml);
                if ($xml === false) {
                    throw new InvalidArgumentException('The workbook’s shared text could not be read.');
                }
                $namespace = $this->spreadsheetNamespace($xml);
                foreach ($xml->children($namespace)->si as $item) {
                    $sharedStrings[] = $this->xmlText($item, $namespace);
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new InvalidArgumentException('The workbook does not contain the expected first worksheet.');
            }
            $xml = simplexml_load_string($sheetXml);
            if ($xml === false) {
                throw new InvalidArgumentException('The workbook’s first worksheet could not be read.');
            }
            $namespace = $this->spreadsheetNamespace($xml);
            $rows = [];
            foreach ($xml->children($namespace)->sheetData->row as $row) {
                $rowAttributes = $row->attributes();
                $rowNumber = (int) ($rowAttributes['r'] ?: (count($rows) + 1));
                $values = [];
                foreach ($row->children($namespace)->c as $cell) {
                    $cellAttributes = $cell->attributes();
                    $reference = (string) $cellAttributes['r'];
                    preg_match('/^([A-Z]+)/', $reference, $match);
                    $index = isset($match[1]) ? $this->columnIndex($match[1]) : count($values);
                    $type = (string) $cellAttributes['t'];
                    $value = (string) ($cell->children($namespace)->v ?? '');
                    if ($type === 's') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = $this->xmlText($cell->children($namespace)->is, $namespace);
                    }
                    $values[$index] = $value;
                }
                $filled = [];
                if ($values !== []) {
                    $filled = array_fill(0, max(array_keys($values)) + 1, '');
                    foreach ($values as $index => $value) {
                        $filled[(int) $index] = $value;
                    }
                }
                $rows[] = ['_row_number' => $rowNumber, 'values' => $filled];
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function xmlText(SimpleXMLElement $element, string $namespace): string
    {
        $children = $element->children($namespace);
        if (isset($children->t)) {
            return (string) $children->t;
        }
        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) ($run->children($namespace)->t ?? '');
        }
        return $text;
    }

    private function spreadsheetNamespace(SimpleXMLElement $xml): string
    {
        $namespaces = $xml->getNamespaces(true);
        if (isset($namespaces['main'])) {
            return (string) $namespaces['main'];
        }
        if (isset($namespaces['x'])) {
            return (string) $namespaces['x'];
        }
        $first = reset($namespaces);
        return $first === false ? '' : (string) $first;
    }

    private function importDate($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value > 0) {
            $days = (int) floor((float) $value);
            return (new DateTimeImmutable('1899-12-30'))->modify("+{$days} days")->format('Y-m-d');
        }
        foreach (['!Y-m-d', '!m/d/Y', '!n/j/Y', '!Y/m/d', '!d-M-y', '!j-M-y', '!d-M-Y', '!j-M-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($dateErrors === false
                || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }

    private function achievementMonthEnd($value): ?string
    {
        $date = $this->importDate($value);
        if ($date === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed === false ? null : $parsed->modify('last day of this month')->format('Y-m-d');
    }

    private function isBlankAchievement($value): bool
    {
        $value = strtoupper(trim((string) $value));
        return $value === '' || $value === 'NP';
    }

    private function rowIsBlank(array $values): bool
    {
        return count(array_filter($values, static function ($value): bool {
            return trim((string) $value) !== '';
        })) === 0;
    }

    private function recordAudit(int $skaterId, string $eventType, string $details, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO skater_audit_event (skater_id, event_type, details, event_at, created_by_user_id)
             VALUES (:skater_id, :event_type, :details, UTC_TIMESTAMP(), :user_id)'
        );
        $statement->execute([
            'skater_id' => $skaterId,
            'event_type' => $eventType,
            'details' => $details,
            'user_id' => $userId,
        ]);
    }

    private function normalizeHeader(string $value): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim($value))) ?? '';
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }
        return $index - 1;
    }

    private function excelColumn(int $zeroBasedIndex): string
    {
        $number = $zeroBasedIndex + 1;
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr(65 + ($number % 26)) . $letters;
            $number = intdiv($number, 26);
        }
        return $letters;
    }
}
