<?php

declare(strict_types=1);

final class SkaterService
{
    private PDO $pdo;
    private const IMPORT_MAX_BYTES = 2_097_152;
    private const IMPORT_MAX_ROWS = 10_000;
    private const IMPORT_REQUIRED_HEADERS = [
        'Participant First Name',
        'Participant Last Name',
        'Gender',
        'Birthdate',
        'Registered Program SKU',
    ];
    private const AWARD_CORRECTION_WINDOW_SECONDS = 900;
    private const RINK_PRIOR_ACHIEVEMENT_REMOVAL_MESSAGE =
        'Achievements added prior to today cannot be removed in the Coach App. Ask an administrator to make this change via the Skater Dashboard.';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function update(int $clubId, int $userId, string $publicId, array $input): void
    {
        $required = [
            'first_name' => trim((string) ($input['first_name'] ?? '')),
            'last_name' => trim((string) ($input['last_name'] ?? '')),
            'date_of_birth' => trim((string) ($input['date_of_birth'] ?? '')),
        ];

        if ($required['first_name'] === '' || $required['last_name'] === '') {
            throw new InvalidArgumentException('First and last name are required.');
        }
        if (mb_strlen($required['first_name']) > 100 || mb_strlen($required['last_name']) > 100) {
            throw new InvalidArgumentException('First and last name must be 100 characters or fewer.');
        }
        if (!$this->validDate($required['date_of_birth'])) {
            throw new InvalidArgumentException('Date of birth must be a valid date.');
        }
        $skateCanadaNumber = $this->skateCanadaNumber($input['skate_canada_number'] ?? null);
        $genderTextProvided = array_key_exists('gender_text', $input);
        $genderText = $genderTextProvided ? $this->genderText($input['gender_text']) : null;
        $genderId = $genderTextProvided
            ? $this->importGenderId($genderText ?? '', $this->importGenderMap())
            : $this->genderId($input['gender_id'] ?? null);

        $expectedUpdatedAt = $this->version($input['updated_at'] ?? null);
        $statement = $this->pdo->prepare(
            'UPDATE skater
             SET
                skate_canada_number = :skate_canada_number,
                first_name = :first_name,
                last_name = :last_name,
                date_of_birth = :date_of_birth,
                gender_id = :gender_id,
                gender_text = :gender_text,
                parent_guardian_name = :parent_guardian_name,
                parent_guardian_email = :parent_guardian_email,
                parent_guardian_phone = :parent_guardian_phone,
                general_notes = :general_notes,
                medical_notes = :medical_notes,
                active = :active,
                updated_by_user_id = :updated_by_user_id
             WHERE club_id = :club_id
               AND public_id = :public_id
               AND deleted_at IS NULL'
        );

        $this->pdo->beginTransaction();
        try {
            $current = $this->pdo->prepare(
                'SELECT updated_at FROM skater
                 WHERE club_id = :club_id AND public_id = :public_id AND deleted_at IS NULL
                 FOR UPDATE'
            );
            $current->execute(['club_id' => $clubId, 'public_id' => $publicId]);
            $currentVersion = $current->fetchColumn();
            if ($currentVersion === false || !hash_equals($expectedUpdatedAt, (string) $currentVersion)) {
                throw new InvalidArgumentException('This skater was changed or removed by another user. Refresh the page and try again.');
            }
            $statement->execute([
                'skate_canada_number' => $skateCanadaNumber,
                'first_name' => $required['first_name'],
                'last_name' => $required['last_name'],
                'date_of_birth' => $required['date_of_birth'],
                'gender_id' => $genderId,
                'gender_text' => $genderText,
                'parent_guardian_name' => $this->nullable($input['parent_guardian_name'] ?? null),
                'parent_guardian_email' => $this->nullable($input['parent_guardian_email'] ?? null),
                'parent_guardian_phone' => $this->nullable($input['parent_guardian_phone'] ?? null),
                'general_notes' => $this->nullable($input['general_notes'] ?? null),
                'medical_notes' => $this->nullable($input['medical_notes'] ?? null),
                'active' => !empty($input['active']) ? 1 : 0,
                'updated_by_user_id' => $userId,
                'club_id' => $clubId,
                'public_id' => $publicId,
            ]);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                throw new InvalidArgumentException($this->duplicateSkaterMessage($exception));
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare(
                'SELECT COUNT(*) FROM skater
                 WHERE club_id = :club_id AND public_id = :public_id AND deleted_at IS NULL'
            );
            $exists->execute(['club_id' => $clubId, 'public_id' => $publicId]);
            if ((int) $exists->fetchColumn() === 0) {
                throw new RuntimeException('Skater not found.');
            }
        }
    }

    public function create(int $clubId, int $userId, array $input): string
    {
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $dateOfBirth = trim((string) ($input['date_of_birth'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            throw new InvalidArgumentException('First and last name are required.');
        }
        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            throw new InvalidArgumentException('First and last name must be 100 characters or fewer.');
        }
        if (!$this->validDate($dateOfBirth)) {
            throw new InvalidArgumentException('Date of birth must be a valid date.');
        }
        $genderTextProvided = array_key_exists('gender_text', $input);
        $genderText = $genderTextProvided ? $this->genderText($input['gender_text']) : null;
        $genderId = $genderTextProvided
            ? $this->importGenderId($genderText ?? '', $this->importGenderMap())
            : $this->genderId($input['gender_id'] ?? null);

        $statement = $this->pdo->prepare(
            'INSERT INTO skater (
                club_id, gender_id, gender_text, skate_canada_number, first_name, last_name, date_of_birth,
                parent_guardian_name, parent_guardian_email, parent_guardian_phone, general_notes, medical_notes,
                active, created_by_user_id, updated_by_user_id
             ) VALUES (
                :club_id, :gender_id, :gender_text, :skate_canada_number, :first_name, :last_name, :date_of_birth,
                :parent_guardian_name, :parent_guardian_email, :parent_guardian_phone, :general_notes, :medical_notes,
                1, :created_by_user_id, :updated_by_user_id
             )'
        );

        try {
            $this->pdo->beginTransaction();
            $statement->execute([
                'club_id' => $clubId,
                'gender_id' => $genderId,
                'gender_text' => $genderText,
                'skate_canada_number' => $this->skateCanadaNumber($input['skate_canada_number'] ?? null),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $dateOfBirth,
                'parent_guardian_name' => $this->nullable($input['parent_guardian_name'] ?? null),
                'parent_guardian_email' => $this->nullable($input['parent_guardian_email'] ?? null),
                'parent_guardian_phone' => $this->nullable($input['parent_guardian_phone'] ?? null),
                'general_notes' => $this->nullable($input['general_notes'] ?? null),
                'medical_notes' => $this->nullable($input['medical_notes'] ?? null),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);
            $newId = (int) $this->pdo->lastInsertId();
            $publicId = $this->pdo->prepare('SELECT public_id FROM skater WHERE id = :id');
            $publicId->execute(['id' => $newId]);
            $value = $publicId->fetchColumn();
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('The new skater record could not be loaded.');
            }
            $registrations = [];
            if (is_array($input['registrations'] ?? null)) {
                foreach ($input['registrations'] as $registration) {
                    if (!is_array($registration)) {
                        throw new InvalidArgumentException('Choose valid sessions and groups.');
                    }
                    $registrations[] = [
                        'session_id' => $registration['session_id'] ?? null,
                        'group_id' => null,
                    ];
                }
            }
            if ($registrations !== []) {
                $this->updateRegistrations($clubId, $userId, $value, $registrations);
            }
            $this->recordAuditEvent(
                $newId,
                'ADDED',
                'Skater record added.',
                $userId
            );
            $this->pdo->commit();

            return $value;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw new InvalidArgumentException($this->duplicateSkaterMessage($exception));
            }
            throw $exception;
        }
    }

    public function delete(int $clubId, int $userId, array $publicIds): int
    {
        $publicIds = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => strtolower(trim((string) $value)), $publicIds),
            static fn (string $value): bool => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1
        )));
        if ($publicIds === []) {
            throw new InvalidArgumentException('Select at least one skater to delete.');
        }
        if (count($publicIds) > 500) {
            throw new InvalidArgumentException('Delete no more than 500 skaters at once.');
        }

        $placeholders = implode(', ', array_fill(0, count($publicIds), '?'));
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare(
                "SELECT id, public_id, first_name, last_name FROM skater WHERE club_id = ? AND deleted_at IS NULL AND public_id IN ({$placeholders}) FOR UPDATE"
            );
            $existing->execute([$clubId, ...$publicIds]);
            $existingRows = $existing->fetchAll();
            if (count($existingRows) !== count($publicIds)) {
                throw new InvalidArgumentException('One or more selected skaters could not be found. Refresh and try again.');
            }

            $auditEvent = $this->pdo->prepare(
                'INSERT INTO skater_audit_event (
                    skater_id, event_type, details, event_at, created_by_user_id
                 ) VALUES (:skater_id, :event_type, :details, UTC_TIMESTAMP(), :user_id)'
            );
            foreach ($existingRows as $row) {
                $auditEvent->execute([
                    'skater_id' => $row['id'],
                    'event_type' => 'DELETED',
                    'details' => 'Skater record deleted: ' . $row['first_name'] . ' ' . $row['last_name'] . '.',
                    'user_id' => $userId,
                ]);
            }

            // A deleted profile must not retain active session membership.  Keeping
            // it active is invisible while the profile is deleted, but would make
            // old sessions silently reappear if the record were ever restored.
            $deactivateEnrollments = $this->pdo->prepare(
                "UPDATE skater_enrollment
                 SET active = 0, updated_by_user_id = ?
                 WHERE active = 1
                   AND deleted_at IS NULL
                   AND skater_id IN (
                       SELECT id FROM skater
                       WHERE club_id = ?
                         AND deleted_at IS NULL
                         AND public_id IN ({$placeholders})
                   )"
            );
            $deactivateEnrollments->execute([$userId, $clubId, ...$publicIds]);

            $statement = $this->pdo->prepare(
                "UPDATE skater
                 SET active = 0,
                     skate_canada_number = NULL,
                     deleted_at = UTC_TIMESTAMP(),
                     updated_by_user_id = ?
                 WHERE club_id = ? AND deleted_at IS NULL AND public_id IN ({$placeholders})"
            );
            $statement->execute([$userId, $clubId, ...$publicIds]);
            $this->pdo->commit();

            return $statement->rowCount();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateRegistrations(
        int $clubId,
        int $userId,
        string $publicId,
        array $registrations
    ): array {
        if (count($registrations) > 100) {
            throw new InvalidArgumentException('Choose no more than 100 sessions at once.');
        }

        $requestedGroups = [];
        foreach ($registrations as $registration) {
            if (!is_array($registration)) {
                throw new InvalidArgumentException('Choose valid sessions and groups.');
            }
            $sessionId = filter_var($registration['session_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($sessionId === false || isset($requestedGroups[(int) $sessionId])) {
                throw new InvalidArgumentException('Choose each session only once.');
            }
            $groupValue = $registration['group_id'] ?? null;
            $groupId = $groupValue === null || $groupValue === ''
                ? null
                : filter_var($groupValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($groupId === false) {
                throw new InvalidArgumentException('Choose a valid group colour.');
            }
            $requestedGroups[(int) $sessionId] = $groupId === null ? null : (int) $groupId;
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
        $skater = $this->pdo->prepare(
            'SELECT id FROM skater
             WHERE club_id = :club_id AND public_id = :public_id AND deleted_at IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $skater->execute(['club_id' => $clubId, 'public_id' => $publicId]);
        $skaterId = $skater->fetchColumn();
        if ($skaterId === false) {
            throw new RuntimeException('Skater not found.');
        }

        $sessionRows = $this->pdo->prepare(
            'SELECT ps.id AS session_id, pg.id AS group_id
             FROM program_session ps
             LEFT JOIN program_group pg
                ON pg.program_session_id = ps.id
               AND pg.active = 1
               AND pg.deleted_at IS NULL
             WHERE ps.club_id = :club_id
               AND ps.active = 1
               AND ps.deleted_at IS NULL
             FOR UPDATE'
        );
        $sessionRows->execute(['club_id' => $clubId]);
        $availableGroups = [];
        foreach ($sessionRows->fetchAll() as $row) {
            $sessionId = (int) $row['session_id'];
            $availableGroups[$sessionId] ??= [];
            if ($row['group_id'] !== null) {
                $availableGroups[$sessionId][(int) $row['group_id']] = true;
            }
        }
        foreach ($requestedGroups as $sessionId => $groupId) {
            if (!array_key_exists($sessionId, $availableGroups)) {
                throw new InvalidArgumentException('One of the selected sessions is no longer available.');
            }
            if ($groupId !== null && !isset($availableGroups[$sessionId][$groupId])) {
                throw new InvalidArgumentException('The selected group colour does not belong to that session.');
            }
        }

        $enrollments = $this->pdo->prepare(
            'SELECT e.id, e.program_session_id, e.active
             FROM skater_enrollment e
             INNER JOIN program_session ps ON ps.id = e.program_session_id
             WHERE e.skater_id = :skater_id
               AND e.deleted_at IS NULL
               AND ps.club_id = :club_id
               AND ps.active = 1
               AND ps.deleted_at IS NULL
             FOR UPDATE'
        );
        $enrollments->execute(['skater_id' => $skaterId, 'club_id' => $clubId]);
        $existing = [];
        foreach ($enrollments->fetchAll() as $row) {
            $existing[(int) $row['program_session_id']] = $row;
        }

        $deactivate = $this->pdo->prepare(
            'UPDATE skater_enrollment SET active = 0, updated_by_user_id = :user_id
             WHERE id = :enrollment_id'
        );
        $activate = $this->pdo->prepare(
            'UPDATE skater_enrollment
             SET active = 1, registration_date = CURDATE(), updated_by_user_id = :user_id
             WHERE id = :enrollment_id'
        );
        $addEnrollment = $this->pdo->prepare(
            'INSERT INTO skater_enrollment (
                skater_id, program_session_id, registration_date, active, created_by_user_id, updated_by_user_id
             ) VALUES (
                :skater_id, :session_id, CURDATE(), 1, :created_by_user_id, :updated_by_user_id
             )'
        );
        $currentGroup = $this->pdo->prepare(
            'SELECT ga.program_group_id
             FROM group_assignment ga
             WHERE ga.skater_enrollment_id = :enrollment_id
             ORDER BY ga.id DESC
             LIMIT 1'
        );
        $assignGroup = $this->pdo->prepare(
            'INSERT INTO group_assignment (
                skater_enrollment_id, program_group_id, notes, created_by_user_id
             ) VALUES (:enrollment_id, :group_id, :notes, :user_id)'
        );

        $changedSessions = 0;
            foreach ($existing as $sessionId => $enrollment) {
                if ((int) $enrollment['active'] === 1 && !array_key_exists($sessionId, $requestedGroups)) {
                    $deactivate->execute(['user_id' => $userId, 'enrollment_id' => $enrollment['id']]);
                    $changedSessions++;
                }
            }
            foreach ($requestedGroups as $sessionId => $groupId) {
                $enrollment = $existing[$sessionId] ?? null;
                if ($enrollment === null) {
                    $addEnrollment->execute([
                        'skater_id' => $skaterId,
                        'session_id' => $sessionId,
                        'created_by_user_id' => $userId,
                        'updated_by_user_id' => $userId,
                    ]);
                    $enrollmentId = (int) $this->pdo->lastInsertId();
                    $changedSessions++;
                } else {
                    $enrollmentId = (int) $enrollment['id'];
                    if ((int) $enrollment['active'] !== 1) {
                        $activate->execute(['user_id' => $userId, 'enrollment_id' => $enrollmentId]);
                        $changedSessions++;
                    }
                }
                $currentGroup->execute(['enrollment_id' => $enrollmentId]);
                $existingGroupId = $currentGroup->fetchColumn();
                $existingGroupId = $existingGroupId === false ? null : (int) $existingGroupId;
                if ($existingGroupId === $groupId) {
                    continue;
                }
                $assignGroup->execute([
                    'enrollment_id' => $enrollmentId,
                    'group_id' => $groupId,
                    'notes' => $groupId === null
                        ? 'Group unassigned from the skater record.'
                        : 'Group assigned from the skater record.',
                    'user_id' => $userId,
                ]);
                $changedSessions++;
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return ['changed_sessions' => $changedSessions, 'active_sessions' => count($requestedGroups)];
    }

    public function import(int $clubId, int $userId, int $seasonId, array $file): array
    {
        if ($seasonId <= 0 || !$this->seasonBelongsToClub($clubId, $seasonId)) {
            throw new InvalidArgumentException('Select a valid season before importing skaters.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Choose a .csv or .xlsx file to import.');
        }
        if ((int) ($file['size'] ?? 0) > self::IMPORT_MAX_BYTES) {
            throw new InvalidArgumentException('The import file must be 2 MB or smaller.');
        }

        $rows = $this->parseImportFile((string) ($file['tmp_name'] ?? ''), (string) ($file['name'] ?? ''));
        $genderMap = $this->importGenderMap();
        $errors = [];
        $errorCount = 0;
        $validRows = [];
        foreach ($rows as $row) {
            $rowErrors = [];
            $firstName = trim((string) ($row['Participant First Name'] ?? ''));
            $lastName = trim((string) ($row['Participant Last Name'] ?? ''));
            $birthdate = $this->importDate($row['Birthdate'] ?? null);
            $sku = trim((string) ($row['Registered Program SKU'] ?? ''));
            if ($firstName === '') {
                $rowErrors[] = 'Participant First Name is required';
            } elseif (mb_strlen($firstName) > 100) {
                $rowErrors[] = 'Participant First Name is too long';
            }
            if ($lastName === '') {
                $rowErrors[] = 'Participant Last Name is required';
            } elseif (mb_strlen($lastName) > 100) {
                $rowErrors[] = 'Participant Last Name is too long';
            }
            if ($birthdate === null) {
                $rowErrors[] = 'Birthdate is required and must be a valid date';
            }
            $gender = trim((string) ($row['Gender'] ?? ''));
            if (mb_strlen($gender) > 80) {
                $rowErrors[] = 'Gender must be 80 characters or fewer';
            }
            if ($sku === '') {
                $rowErrors[] = 'Registered Program SKU is required';
            } elseif (mb_strlen($sku) > 64) {
                $rowErrors[] = 'Registered Program SKU must be 64 characters or fewer';
            }
            try {
                $number = $this->importSkateCanadaNumber($row['Skate Canada Number'] ?? null);
            } catch (InvalidArgumentException $exception) {
                $number = null;
                $rowErrors[] = 'Skate Canada Number must contain no more than 100 letters or numbers, or be blank';
            }
            if ($rowErrors !== []) {
                $errorCount++;
                if (count($errors) < 10) {
                    $errors[] = 'Row ' . $row['_row_number'] . ': ' . implode('; ', $rowErrors);
                }
                continue;
            }

            $validRows[] = [
                '_row_number' => $row['_row_number'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $birthdate,
                'skate_canada_number' => $number,
                'gender' => $gender,
                'parent_guardian_name' => $this->nullable($row['Member Names'] ?? null),
                'parent_guardian_email' => $this->nullable($row['Member Email'] ?? null),
                'parent_guardian_phone' => $this->nullable($row['Member Telephone'] ?? null)
                    ?? $this->nullable($row['Member Mobile'] ?? null),
                'medical_notes' => $this->nullable($row['Notes'] ?? null),
                'sku' => $sku,
            ];
        }
        if ($errors !== []) {
            $remaining = $errorCount - count($errors);
            $message = implode("\n", $errors);
            if ($remaining > 0) {
                $message .= "\nAnd {$remaining} more row(s) contain errors.";
            }
            $message .= "\nNo skaters imported. Please correct errors and try again.";
            throw new InvalidArgumentException($message);
        }
        if ($validRows === []) {
            throw new InvalidArgumentException(
                "The import file contains no skater rows.\nNo skaters imported. Please correct errors and try again."
            );
        }

        $this->pdo->beginTransaction();
        try {
            $findByIdentity = $this->pdo->prepare(
                'SELECT id, skate_canada_number
                 FROM skater
                 WHERE club_id = :club_id
                   AND first_name = :first_name
                   AND last_name = :last_name
                   AND date_of_birth = :date_of_birth
                   AND deleted_at IS NULL
                 ORDER BY id
                 LIMIT 20
                 FOR UPDATE'
            );
            $findByNumber = $this->pdo->prepare(
                'SELECT id
                 FROM skater
                 WHERE club_id = :club_id
                   AND skate_canada_number = :skate_canada_number
                   AND deleted_at IS NULL
                 LIMIT 1
                 FOR UPDATE'
            );
            $insert = $this->pdo->prepare(
                'INSERT INTO skater (
                    club_id, gender_id, gender_text, skate_canada_number, first_name, last_name, date_of_birth,
                    parent_guardian_name, parent_guardian_email, parent_guardian_phone, medical_notes,
                    active, created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :club_id, :gender_id, :gender_text, :skate_canada_number, :first_name, :last_name, :date_of_birth,
                    :parent_guardian_name, :parent_guardian_email, :parent_guardian_phone, :medical_notes,
                    1, :created_by_user_id, :updated_by_user_id
                 )'
            );
            $update = $this->pdo->prepare(
                'UPDATE skater
                 SET gender_id = :gender_id,
                     gender_text = :gender_text,
                     skate_canada_number = :skate_canada_number,
                     first_name = :first_name,
                     last_name = :last_name,
                     date_of_birth = :date_of_birth,
                     parent_guardian_name = :parent_guardian_name,
                     parent_guardian_email = :parent_guardian_email,
                     parent_guardian_phone = :parent_guardian_phone,
                     medical_notes = :medical_notes,
                     active = 1,
                     updated_by_user_id = :updated_by_user_id
                 WHERE id = :id AND club_id = :club_id'
            );
            $findSession = $this->pdo->prepare(
                'SELECT id, season_id, club_id, deleted_at
                 FROM program_session
                 WHERE sku = :sku
                 LIMIT 1
                 FOR UPDATE'
            );
            $restoreSession = $this->pdo->prepare(
                'UPDATE program_session
                 SET active = 1, deleted_at = NULL, updated_by_user_id = :user_id
                 WHERE id = :id'
            );
            $createSession = $this->pdo->prepare(
                'INSERT INTO program_session (
                    club_id, season_id, sku, name, day_of_week, start_time, end_time,
                    location, active, created_by_user_id, updated_by_user_id
                 ) VALUES (
                    :club_id, :season_id, :sku, :name, 1, \'00:00:00\', \'00:01:00\',
                    NULL, 1, :created_by_user_id, :updated_by_user_id
                 )'
            );
            $enroll = $this->pdo->prepare(
                 'INSERT INTO skater_enrollment (
                    skater_id, program_session_id, registration_date, active,
                    created_by_user_id, updated_by_user_id
                 ) VALUES (:skater_id, :session_id, CURDATE(), 1, :created_by_user_id, :updated_by_user_id)
                 ON DUPLICATE KEY UPDATE
                    active = 1, deleted_at = NULL, updated_by_user_id = :updated_user_id'
            );
            $created = 0;
            $updated = 0;
            $assigned = 0;
            $duplicatesSkipped = 0;
            $sessionIds = [];
            $importedIdentityIds = [];
            $processedImportRows = [];

            foreach ($validRows as $row) {
                $number = $row['skate_canada_number'];
                $identityKey = strtolower($row['first_name']) . "\0"
                    . strtolower($row['last_name']) . "\0" . $row['date_of_birth'];
                $importRowKey = $identityKey . "\0" . strtolower((string) ($number ?? ''))
                    . "\0" . strtolower($row['sku']);
                if (isset($processedImportRows[$importRowKey])) {
                    $duplicatesSkipped++;
                    continue;
                }
                $processedImportRows[$importRowKey] = true;
                $skaterId = null;
                $backfillsNumber = false;
                foreach ($importedIdentityIds[$identityKey] ?? [] as $candidate) {
                    if ($this->sameImportNumber($candidate['skate_canada_number'], $number)) {
                        $skaterId = $candidate['id'];
                        break;
                    }
                }
                if ($skaterId === null) {
                    $findByIdentity->execute([
                        'club_id' => $clubId,
                        'first_name' => $row['first_name'],
                        'last_name' => $row['last_name'],
                        'date_of_birth' => $row['date_of_birth'],
                    ]);
                    $identityCandidates = $findByIdentity->fetchAll();
                    $findByIdentity->closeCursor();
                    $identityMatch = $this->importIdentityMatch($identityCandidates, $number);
                    if ($identityMatch['ambiguous_blank']) {
                        throw new InvalidArgumentException(
                            "Row {$row['_row_number']}: more than one existing skater has this name and birthdate with a blank Skate Canada Number. Merge or correct those records before importing."
                        );
                    }
                    $skaterId = $identityMatch['id'];
                    $backfillsNumber = $identityMatch['backfills_number'];
                }
                if (($skaterId === null || $backfillsNumber) && $number !== null) {
                    $findByNumber->execute([
                        'club_id' => $clubId,
                        'skate_canada_number' => $number,
                    ]);
                    $numberOwnerId = $findByNumber->fetchColumn();
                    $findByNumber->closeCursor();
                    if ($numberOwnerId !== false && (int) $numberOwnerId !== $skaterId) {
                        throw new InvalidArgumentException(
                            "Row {$row['_row_number']}: Skate Canada Number belongs to a skater with a different name or birthdate."
                        );
                    }
                }

                $genderId = $this->importGenderId($row['gender'], $genderMap);
                $parameters = [
                    'club_id' => $clubId,
                    'gender_id' => $genderId,
                    'gender_text' => $this->genderText($row['gender']),
                    'skate_canada_number' => $number,
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'date_of_birth' => $row['date_of_birth'],
                    'parent_guardian_name' => $row['parent_guardian_name'],
                    'parent_guardian_email' => $row['parent_guardian_email'],
                    'parent_guardian_phone' => $row['parent_guardian_phone'],
                    'medical_notes' => $row['medical_notes'],
                    'updated_by_user_id' => $userId,
                ];
                if ($skaterId === null) {
                    $insert->execute($parameters + ['created_by_user_id' => $userId]);
                    $skaterId = (int) $this->pdo->lastInsertId();
                    $this->recordAuditEvent($skaterId, 'ADDED', 'Skater record added by import.', $userId);
                    $created++;
                } else {
                    $update->execute($parameters + ['id' => $skaterId]);
                    $updated++;
                }
                $importedIdentityIds[$identityKey][$skaterId] = [
                    'id' => $skaterId,
                    'skate_canada_number' => $number,
                ];

                if (!isset($sessionIds[$row['sku']])) {
                    $findSession->execute(['sku' => $row['sku']]);
                    $session = $findSession->fetch() ?: null;
                    $findSession->closeCursor();
                    if ($session !== null && (int) $session['club_id'] !== $clubId) {
                        throw new InvalidArgumentException("Row {$row['_row_number']}: the program SKU belongs to another club.");
                    }
                    if ($session !== null && (int) $session['season_id'] !== $seasonId) {
                        throw new InvalidArgumentException("Row {$row['_row_number']}: the program SKU belongs to a different season.");
                    }
                    if ($session !== null && $session['deleted_at'] !== null) {
                        $restoreSession->execute(['id' => $session['id'], 'user_id' => $userId]);
                    }
                    if ($session === null) {
                        $createSession->execute([
                            'club_id' => $clubId,
                            'season_id' => $seasonId,
                            'sku' => $row['sku'],
                            'name' => $row['sku'],
                            'created_by_user_id' => $userId,
                            'updated_by_user_id' => $userId,
                        ]);
                        $sessionIds[$row['sku']] = (int) $this->pdo->lastInsertId();
                    } else {
                        $sessionIds[$row['sku']] = (int) $session['id'];
                    }
                }
                $enroll->execute([
                    'skater_id' => $skaterId,
                    'session_id' => $sessionIds[$row['sku']],
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                    'updated_user_id' => $userId,
                ]);
                $assigned++;
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'assigned' => $assigned,
            'duplicates_skipped' => $duplicatesSkipped,
            'total' => count($validRows),
        ];
    }

    private function seasonBelongsToClub(int $clubId, int $seasonId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM season
             WHERE id = :season_id AND club_id = :club_id AND deleted_at IS NULL'
        );
        $statement->execute(['season_id' => $seasonId, 'club_id' => $clubId]);
        $count = (int) $statement->fetchColumn();
        $statement->closeCursor();

        return $count === 1;
    }

    /** @return list<array<string, mixed>> */
    private function parseImportFile(string $path, string $filename): array
    {
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('The import file could not be read.');
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            $rawRows = $this->parseImportCsv($path);
        } elseif ($extension === 'xlsx') {
            $rawRows = $this->parseImportXlsx($path);
        } else {
            throw new InvalidArgumentException('The import file must be a .csv or .xlsx file.');
        }
        if ($rawRows === []) {
            throw new InvalidArgumentException('The import file is empty.');
        }

        $headers = array_map(
            static fn ($value): string => trim((string) $value),
            $rawRows[0]['values']
        );
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
        }
        $missing = array_values(array_diff(self::IMPORT_REQUIRED_HEADERS, $headers));
        if (count($headers) !== count(array_unique($headers)) || $missing !== []) {
            $parts = [];
            if ($missing !== []) {
                $parts[] = 'missing: ' . implode(', ', $missing);
            }
            if (count($headers) !== count(array_unique($headers))) {
                $parts[] = 'duplicate column names';
            }
            throw new InvalidArgumentException(
                'The import file is missing one or more required columns (' . implode('; ', $parts) . ').'
            );
        }

        $rows = [];
        foreach (array_slice($rawRows, 1) as $rawRow) {
            $values = $rawRow['values'];
            $isBlank = count(array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) === 0;
            if ($isBlank) {
                continue;
            }
            if (count($values) > count($headers)) {
                throw new InvalidArgumentException("Row {$rawRow['_row_number']} has more fields than the header row.");
            }
            $values = array_pad($values, count($headers), '');
            $rows[] = ['_row_number' => $rawRow['_row_number']] + array_combine($headers, array_slice($values, 0, count($headers)));
        }

        if (count($rows) > self::IMPORT_MAX_ROWS) {
            throw new InvalidArgumentException('A single import is limited to 10,000 skaters.');
        }

        return $rows;
    }

    /** @return list<array{_row_number:int, values:list<mixed>}> */
    private function parseImportCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The CSV file could not be read.');
        }
        $rows = [];
        $line = 0;
        try {
            while (($values = fgetcsv($handle)) !== false) {
                $line++;
                $rows[] = ['_row_number' => $line, 'values' => $values];
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /** @return list<array{_row_number:int, values:list<mixed>}> */
    private function parseImportXlsx(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Excel imports are not available on this server.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('The Excel workbook could not be opened.');
        }
        try {
            $sharedStrings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $xml = simplexml_load_string($sharedXml);
                if ($xml !== false) {
                    $ns = $xml->getNamespaces(true);
                    foreach ($xml->children($ns['main'] ?? '')->si as $item) {
                        $text = '';
                        foreach ($item->children($ns['main'] ?? '')->t as $part) {
                            $text .= (string) $part;
                        }
                        foreach ($item->children($ns['main'] ?? '')->r as $run) {
                            $text .= (string) ($run->children($ns['main'] ?? '')->t ?? '');
                        }
                        $sharedStrings[] = $text;
                    }
                }
            }
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new InvalidArgumentException('The Excel workbook does not contain a first worksheet.');
            }
            $xml = simplexml_load_string($sheetXml);
            if ($xml === false) {
                throw new InvalidArgumentException('The first worksheet could not be read.');
            }
            $ns = $xml->getNamespaces(true);
            $main = $ns['main'] ?? '';
            $rows = [];
            foreach ($xml->children($main)->sheetData->row as $row) {
                $rowNumber = (int) ($row['r'] ?: (count($rows) + 1));
                $values = [];
                foreach ($row->children($main)->c as $cell) {
                    $reference = (string) $cell['r'];
                    preg_match('/^([A-Z]+)/', $reference, $match);
                    $index = 0;
                    if (isset($match[1])) {
                        foreach (str_split($match[1]) as $letter) {
                            $index = $index * 26 + ord($letter) - 64;
                        }
                        $index--;
                    }
                    $type = (string) $cell['t'];
                    $value = (string) ($cell->children($main)->v ?? '');
                    if ($type === 's') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string) ($cell->children($main)->is->children($main)->t ?? '');
                    }
                    $values[$index] = $value;
                }
                if ($values !== []) {
                    $maxIndex = max(array_keys($values));
                    $filled = array_fill(0, $maxIndex + 1, '');
                    foreach ($values as $index => $value) {
                        $filled[(int) $index] = $value;
                    }
                    $values = $filled;
                }
                $rows[] = ['_row_number' => $rowNumber, 'values' => $values];
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function importDate($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value > 0) {
            $days = (int) floor((float) $value);
            $date = (new DateTimeImmutable('1899-12-30'))->modify("+{$days} days");
            return $date->format('Y-m-d');
        }
        foreach (['!Y-m-d', '!m/d/Y', '!n/j/Y', '!Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (
                $date !== false
                && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
            ) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** @return array<string, int> */
    private function importGenderMap(): array
    {
        $statement = $this->pdo->query('SELECT id, code, name FROM gender WHERE active = 1');
        $map = [];
        foreach ($statement->fetchAll() as $row) {
            $map[strtolower(trim((string) $row['code']))] = (int) $row['id'];
            $map[strtolower(trim((string) $row['name']))] = (int) $row['id'];
        }

        return $map;
    }

    private function importGenderId(string $value, array $map): ?int
    {
        if ($value === '') {
            return $map['unspecified'] ?? null;
        }
        $key = strtolower($value);
        if (isset($map[$key])) {
            return $map[$key];
        }
        $aliases = ['m' => 'male', 'f' => 'female', 'nb' => 'non-binary', 'nonbinary' => 'non-binary'];
        if (isset($aliases[$key], $map[$aliases[$key]])) {
            return $map[$aliases[$key]];
        }

        return $map['unspecified'] ?? null;
    }

    private function sameImportNumber(?string $left, ?string $right): bool
    {
        return $left !== null && $right !== null
            ? strtoupper($left) === strtoupper($right)
            : $left === null && $right === null;
    }

    /**
     * @param list<array{id:mixed, skate_canada_number:mixed}> $candidates
     * @return array{id:?int, backfills_number:bool, ambiguous_blank:bool}
     */
    private function importIdentityMatch(array $candidates, ?string $number): array
    {
        foreach ($candidates as $candidate) {
            $candidateNumber = $candidate['skate_canada_number'] === null
                ? null
                : trim((string) $candidate['skate_canada_number']);
            if ($this->sameImportNumber($candidateNumber, $number)) {
                return [
                    'id' => (int) $candidate['id'],
                    'backfills_number' => false,
                    'ambiguous_blank' => false,
                ];
            }
        }

        if ($number !== null) {
            $blankCandidates = array_values(array_filter(
                $candidates,
                static fn (array $candidate): bool => $candidate['skate_canada_number'] === null
                    || trim((string) $candidate['skate_canada_number']) === ''
            ));
            if (count($blankCandidates) === 1) {
                return [
                    'id' => (int) $blankCandidates[0]['id'],
                    'backfills_number' => true,
                    'ambiguous_blank' => false,
                ];
            }
            if (count($blankCandidates) > 1) {
                return ['id' => null, 'backfills_number' => false, 'ambiguous_blank' => true];
            }
        }

        return ['id' => null, 'backfills_number' => false, 'ambiguous_blank' => false];
    }

    public function markSkillAchieved(
        int $clubId,
        int $userId,
        string $skaterPublicId,
        int $skillId
    ): array {
        if ($skillId <= 0) {
            throw new InvalidArgumentException('Choose a valid skill.');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();

        try {
            $context = $this->resolveManualAssessmentTarget(
                $clubId,
                $skaterPublicId,
                $skillId
            );

            $existing = $this->pdo->prepare(
                'SELECT id
                 FROM skater_skill
                 WHERE skater_id = :skater_id
                   AND canskate_skill_id = :skill_id
                 FOR UPDATE'
            );
            $existing->execute([
                'skater_id' => $context['skater_id'],
                'skill_id' => $skillId,
            ]);
            if ($existing->fetchColumn() !== false) {
                throw new InvalidArgumentException('This skill is already marked achieved.');
            }

            $result = $this->pdo->query(
                'SELECT id
                 FROM assessment_result
                 WHERE code = "ACHIEVED" AND active = 1
                 LIMIT 1'
            );
            $resultId = $result->fetchColumn();
            if ($resultId === false) {
                throw new RuntimeException('The Achieved assessment result is not configured.');
            }

            $assessment = $this->pdo->prepare(
                'INSERT INTO assessment_history (
                    program_date_id,
                    coach_assignment_id,
                    skater_id,
                    canskate_skill_id,
                    assessment_result_id,
                    notes,
                    assessed_at,
                    created_by_user_id
                 ) VALUES (
                    :program_date_id,
                    :coach_assignment_id,
                    :skater_id,
                    :skill_id,
                    :result_id,
                    :notes,
                    UTC_TIMESTAMP(),
                    :user_id
                 )'
            );
            $assessment->execute([
                'program_date_id' => null,
                'coach_assignment_id' => null,
                'skater_id' => $context['skater_id'],
                'skill_id' => $skillId,
                'result_id' => $resultId,
                'notes' => '[Manual administrator update] Achievement added from the administrator dashboard.',
                'user_id' => $userId,
            ]);
            $assessmentId = (int) $this->pdo->lastInsertId();

            $achievement = $this->pdo->prepare(
                'INSERT INTO skater_skill (
                    skater_id,
                    canskate_skill_id,
                    assessment_history_id,
                    achievement_date,
                    achieved_by_coach_id,
                    notes,
                    created_by_user_id,
                    updated_by_user_id
                 ) VALUES (
                    :skater_id,
                    :skill_id,
                    :assessment_history_id,
                    :achievement_date,
                    :coach_id,
                    :notes,
                    :created_by_user_id,
                    :updated_by_user_id
                 )'
            );
            $achievement->execute([
                'skater_id' => $context['skater_id'],
                'skill_id' => $skillId,
                'assessment_history_id' => $assessmentId,
                'achievement_date' => (new DateTimeImmutable())->format('Y-m-d'),
                'coach_id' => null,
                'notes' => 'Achievement added from the administrator dashboard.',
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            $automaticallyCompletedSkillIds = [];
            if ((string) $context['skill_code'] !== 'PCS-PARTICIPATION'
                && string_starts_with((string) $context['skill_code'], 'PCS-SKL-')) {
                $participation = $this->pdo->prepare(
                    'SELECT id
                     FROM canskate_skill
                     WHERE skill_code = "PCS-PARTICIPATION"
                       AND active = 1
                       AND deleted_at IS NULL
                     LIMIT 1
                     FOR UPDATE'
                );
                $participation->execute();
                $participationSkillId = $participation->fetchColumn();
                if ($participationSkillId !== false) {
                    $participationExisting = $this->pdo->prepare(
                        'SELECT id FROM skater_skill
                         WHERE skater_id = :skater_id AND canskate_skill_id = :skill_id
                         FOR UPDATE'
                    );
                    $participationExisting->execute([
                        'skater_id' => $context['skater_id'],
                        'skill_id' => $participationSkillId,
                    ]);
                    if ($participationExisting->fetchColumn() === false) {
                        $assessment->execute([
                            'program_date_id' => null,
                            'coach_assignment_id' => null,
                            'skater_id' => $context['skater_id'],
                            'skill_id' => $participationSkillId,
                            'result_id' => $resultId,
                            'notes' => '[Automatic Pre-CanSkate participation] Marked achieved with a Pre-CanSkate skill.',
                            'user_id' => $userId,
                        ]);
                        $achievement->execute([
                            'skater_id' => $context['skater_id'],
                            'skill_id' => $participationSkillId,
                            'assessment_history_id' => (int) $this->pdo->lastInsertId(),
                            'achievement_date' => (new DateTimeImmutable())->format('Y-m-d'),
                            'coach_id' => null,
                            'notes' => 'Automatically marked achieved with a Pre-CanSkate skill.',
                            'created_by_user_id' => $userId,
                            'updated_by_user_id' => $userId,
                        ]);
                        $automaticallyCompletedSkillIds[] = (int) $participationSkillId;
                    }
                }
            }

            if ($ownsTransaction) $this->pdo->commit();

            return ['automatically_completed_skill_ids' => $automaticallyCompletedSkillIds];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw new InvalidArgumentException('This skill is already marked achieved.');
            }
            throw $exception;
        }
    }

    public function removeSkillAchievement(
        int $clubId,
        int $userId,
        string $skaterPublicId,
        int $skillId,
        bool $sameDayOnly = false
    ): void {
        if ($skillId <= 0) {
            throw new InvalidArgumentException('Choose a valid skill.');
        }

        $this->pdo->beginTransaction();

        try {
            $current = $this->pdo->prepare(
                'SELECT
                    ss.id AS skater_skill_id,
                    ss.skater_id,
                    ss.canskate_skill_id,
                    ss.achievement_date,
                    ah.program_date_id,
                    ah.coach_assignment_id,
                    cs.skill_code
                 FROM skater_skill ss
                 INNER JOIN skater s
                    ON s.id = ss.skater_id
                    AND s.club_id = :club_id
                    AND s.public_id = :public_id
                    AND s.deleted_at IS NULL
                 INNER JOIN assessment_history ah ON ah.id = ss.assessment_history_id
                 INNER JOIN canskate_skill cs ON cs.id = ss.canskate_skill_id
                 WHERE ss.canskate_skill_id = :skill_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $current->execute([
                'club_id' => $clubId,
                'public_id' => $skaterPublicId,
                'skill_id' => $skillId,
            ]);
            $achievement = $current->fetch();
            if (!$achievement) {
                throw new InvalidArgumentException('This skill is not currently marked achieved.');
            }
            if ((string) $achievement['skill_code'] === 'PCS-PARTICIPATION') {
                $preCanSkateSkills = $this->pdo->prepare(
                    'SELECT COUNT(*)
                     FROM skater_skill ss
                     INNER JOIN canskate_skill cs ON cs.id = ss.canskate_skill_id
                     WHERE ss.skater_id = :skater_id
                       AND cs.skill_code LIKE "PCS-SKL-%"'
                );
                $preCanSkateSkills->execute(['skater_id' => $achievement['skater_id']]);
                if ((int) $preCanSkateSkills->fetchColumn() > 0) {
                    throw new InvalidArgumentException(
                        'Participation is automatically recorded while any Pre-CanSkate skill is achieved.'
                    );
                }
            }
            if ($sameDayOnly && $achievement['achievement_date'] !== date('Y-m-d')) {
                throw new InvalidArgumentException(self::RINK_PRIOR_ACHIEVEMENT_REMOVAL_MESSAGE);
            }

            $result = $this->pdo->query(
                'SELECT id
                 FROM assessment_result
                 WHERE code = "NOT_ACHIEVED" AND active = 1
                 LIMIT 1'
            );
            $resultId = $result->fetchColumn();
            if ($resultId === false) {
                throw new RuntimeException('The Not Achieved assessment result is not configured.');
            }

            $history = $this->pdo->prepare(
                'INSERT INTO assessment_history (
                    program_date_id,
                    coach_assignment_id,
                    skater_id,
                    canskate_skill_id,
                    assessment_result_id,
                    notes,
                    assessed_at,
                    created_by_user_id
                 ) VALUES (
                    :program_date_id,
                    :coach_assignment_id,
                    :skater_id,
                    :skill_id,
                    :result_id,
                    :notes,
                    UTC_TIMESTAMP(),
                    :user_id
                 )'
            );
            $history->execute([
                'program_date_id' => $achievement['program_date_id'],
                'coach_assignment_id' => $achievement['coach_assignment_id'],
                'skater_id' => $achievement['skater_id'],
                'skill_id' => $achievement['canskate_skill_id'],
                'result_id' => $resultId,
                'notes' => '[Achievement removed] Current achievement removed from the skater record.',
                'user_id' => $userId,
            ]);

            $delete = $this->pdo->prepare(
                'DELETE FROM skater_skill
                 WHERE id = :skater_skill_id
                   AND skater_id = :skater_id'
            );
            $delete->execute([
                'skater_skill_id' => $achievement['skater_skill_id'],
                'skater_id' => $achievement['skater_id'],
            ]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('The current achievement could not be removed.');
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function awardRibbon(
        int $clubId,
        int $userId,
        string $skaterPublicId,
        int $ribbonId,
        bool $overrideIneligible = false
    ): array {
        $target = $this->resolveManualRibbonTarget(
            $clubId,
            $skaterPublicId,
            $ribbonId,
            !$overrideIneligible
        );
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();

        try {
            $completedSkillIds = $overrideIneligible
                ? $this->completeMissingAwardSkills(
                    (int) $target['skater_id'],
                    'ribbon',
                    (int) $target['ribbon_id'],
                    $userId,
                    'the ribbon award'
                )
                : [];
            $current = $this->pdo->prepare(
                'SELECT id, revoked_at
                 FROM skater_ribbon
                 WHERE skater_id = :skater_id
                   AND canskate_ribbon_id = :ribbon_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $current->execute([
                'skater_id' => $target['skater_id'],
                'ribbon_id' => $target['ribbon_id'],
            ]);
            $record = $current->fetch();

            if ($record && $record['revoked_at'] === null) {
                throw new InvalidArgumentException('This ribbon is already marked awarded.');
            }

            if ($record) {
                $statement = $this->pdo->prepare(
                    'UPDATE skater_ribbon
                     SET
                        program_date_id = NULL,
                        coach_assignment_id = NULL,
                        awarded_by_coach_id = NULL,
                        awarded_at = UTC_TIMESTAMP(),
                        revoked_at = NULL,
                        revoked_by_user_id = NULL,
                        notes = :notes,
                        created_by_user_id = :created_by_user_id,
                        updated_by_user_id = :updated_by_user_id
                     WHERE id = :id'
                );
                $statement->execute([
                    'notes' => $overrideIneligible
                        ? 'Ribbon awarded and associated skills marked achieved from the administrator dashboard.'
                        : 'Ribbon awarded from the administrator dashboard.',
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                    'id' => $record['id'],
                ]);
                $awardRecordId = (int) $record['id'];
            } else {
                $statement = $this->pdo->prepare(
                    'INSERT INTO skater_ribbon (
                        skater_id,
                        canskate_ribbon_id,
                        program_date_id,
                        coach_assignment_id,
                        awarded_by_coach_id,
                        awarded_at,
                        notes,
                        created_by_user_id,
                        updated_by_user_id
                     ) VALUES (
                        :skater_id,
                        :ribbon_id,
                        NULL,
                        NULL,
                        NULL,
                        UTC_TIMESTAMP(),
                        :notes,
                        :created_by_user_id,
                        :updated_by_user_id
                     )'
                );
                $statement->execute([
                    'skater_id' => $target['skater_id'],
                    'ribbon_id' => $target['ribbon_id'],
                    'notes' => $overrideIneligible
                        ? 'Ribbon awarded and associated skills marked achieved from the administrator dashboard.'
                        : 'Ribbon awarded from the administrator dashboard.',
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                ]);
                $awardRecordId = (int) $this->pdo->lastInsertId();
            }

            $awardedAtStatement = $this->pdo->prepare(
                'SELECT DATE_FORMAT(awarded_at, "%Y-%m-%dT%H:%i:%sZ")
                 FROM skater_ribbon
                 WHERE id = :id'
            );
            $awardedAtStatement->execute(['id' => $awardRecordId]);
            $awardedAt = $awardedAtStatement->fetchColumn();
            if (!is_string($awardedAt) || $awardedAt === '') {
                throw new RuntimeException('The ribbon award timestamp could not be read.');
            }

            if ($ownsTransaction) $this->pdo->commit();

            return ['awarded_at' => $awardedAt, 'completed_skill_ids' => $completedSkillIds];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw new InvalidArgumentException('This ribbon is already marked awarded.');
            }
            throw $exception;
        }
    }

    public function revokeRibbonAward(
        int $clubId,
        int $userId,
        string $skaterPublicId,
        int $ribbonId,
        bool $sameDayOnly = false
    ): bool {
        $target = $this->resolveManualRibbonTarget(
            $clubId,
            $skaterPublicId,
            $ribbonId,
            false
        );
        $this->pdo->beginTransaction();

        try {
            $current = $this->pdo->prepare(
                'SELECT
                    id,
                    awarded_at,
                    TIMESTAMPDIFF(SECOND, awarded_at, UTC_TIMESTAMP()) AS award_age_seconds
                 FROM skater_ribbon
                 WHERE skater_id = :skater_id
                   AND canskate_ribbon_id = :ribbon_id
                   AND revoked_at IS NULL
                 LIMIT 1
                 FOR UPDATE'
            );
            $current->execute([
                'skater_id' => $target['skater_id'],
                'ribbon_id' => $target['ribbon_id'],
            ]);
            $record = $current->fetch();
            if (!$record) {
                throw new InvalidArgumentException('This ribbon is not currently marked awarded.');
            }
            if ($sameDayOnly && !$this->utcTimestampIsToday((string) $record['awarded_at'])) {
                throw new InvalidArgumentException(self::RINK_PRIOR_ACHIEVEMENT_REMOVAL_MESSAGE);
            }

            $awardAgeSeconds = (int) $record['award_age_seconds'];
            $isCorrection = $awardAgeSeconds >= 0
                && $awardAgeSeconds <= self::AWARD_CORRECTION_WINDOW_SECONDS;

            if ($isCorrection) {
                $statement = $this->pdo->prepare(
                    'DELETE FROM skater_ribbon
                     WHERE id = :id
                       AND revoked_at IS NULL'
                );
                $statement->execute(['id' => $record['id']]);
            } else {
                $statement = $this->pdo->prepare(
                    'UPDATE skater_ribbon
                     SET
                        revoked_at = UTC_TIMESTAMP(),
                        revoked_by_user_id = :revoked_by_user_id,
                        updated_by_user_id = :updated_by_user_id
                     WHERE id = :id
                       AND revoked_at IS NULL'
                );
                $statement->execute([
                    'revoked_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                    'id' => $record['id'],
                ]);
            }

            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The ribbon award could not be removed.');
            }

            $this->pdo->commit();

            return $isCorrection;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function awardStageBadge(
        int $clubId,
        int $userId,
        string $skaterPublicId,
        int $stageId,
        bool $overrideIneligible = false
    ): array {
        $target = $this->resolveManualBadgeTarget(
            $clubId,
            $skaterPublicId,
            $stageId,
            !$overrideIneligible
        );
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();

        try {
            $completedSkillIds = $overrideIneligible
                ? $this->completeMissingAwardSkills(
                    (int) $target['skater_id'],
                    'badge',
                    (int) $target['stage_id'],
                    $userId,
                    'the stage badge award'
                )
                : [];
            $current = $this->pdo->prepare(
                'SELECT id, revoked_at
                 FROM skater_badge
                 WHERE skater_id = :skater_id
                   AND canskate_stage_id = :stage_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $current->execute([
                'skater_id' => $target['skater_id'],
                'stage_id' => $target['stage_id'],
            ]);
            $record = $current->fetch();
            if ($record && $record['revoked_at'] === null) {
                throw new InvalidArgumentException('This stage badge is already marked awarded.');
            }

            if ($record) {
                $statement = $this->pdo->prepare(
                    'UPDATE skater_badge
                     SET
                        program_date_id = NULL,
                        coach_assignment_id = NULL,
                        awarded_by_coach_id = NULL,
                        awarded_at = UTC_TIMESTAMP(),
                        revoked_at = NULL,
                        revoked_by_user_id = NULL,
                        notes = :notes,
                        created_by_user_id = :created_by_user_id,
                        updated_by_user_id = :updated_by_user_id
                     WHERE id = :id'
                );
                $statement->execute([
                    'notes' => $overrideIneligible
                        ? 'Stage badge awarded and associated skills marked achieved from the administrator dashboard.'
                        : 'Stage badge awarded from the administrator dashboard.',
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                    'id' => $record['id'],
                ]);
                $awardRecordId = (int) $record['id'];
            } else {
                $statement = $this->pdo->prepare(
                    'INSERT INTO skater_badge (
                        skater_id,
                        canskate_stage_id,
                        program_date_id,
                        coach_assignment_id,
                        awarded_by_coach_id,
                        awarded_at,
                        notes,
                        created_by_user_id,
                        updated_by_user_id
                     ) VALUES (
                        :skater_id,
                        :stage_id,
                        NULL,
                        NULL,
                        NULL,
                        UTC_TIMESTAMP(),
                        :notes,
                        :created_by_user_id,
                        :updated_by_user_id
                     )'
                );
                $statement->execute([
                    'skater_id' => $target['skater_id'],
                    'stage_id' => $target['stage_id'],
                    'notes' => $overrideIneligible
                        ? 'Stage badge awarded and associated skills marked achieved from the administrator dashboard.'
                        : 'Stage badge awarded from the administrator dashboard.',
                    'created_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                ]);
                $awardRecordId = (int) $this->pdo->lastInsertId();
            }

            $awardedAtStatement = $this->pdo->prepare(
                'SELECT DATE_FORMAT(awarded_at, "%Y-%m-%dT%H:%i:%sZ")
                 FROM skater_badge
                 WHERE id = :id'
            );
            $awardedAtStatement->execute(['id' => $awardRecordId]);
            $awardedAt = $awardedAtStatement->fetchColumn();
            if (!is_string($awardedAt) || $awardedAt === '') {
                throw new RuntimeException('The stage badge award timestamp could not be read.');
            }

            if ($ownsTransaction) $this->pdo->commit();

            return ['awarded_at' => $awardedAt, 'completed_skill_ids' => $completedSkillIds];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw new InvalidArgumentException('This stage badge is already marked awarded.');
            }
            throw $exception;
        }
    }

    public function revokeStageBadge(
        int $clubId,
        int $userId,
        string $skaterPublicId,
        int $stageId,
        bool $sameDayOnly = false
    ): bool {
        $target = $this->resolveManualBadgeTarget(
            $clubId,
            $skaterPublicId,
            $stageId,
            false
        );
        $this->pdo->beginTransaction();

        try {
            $current = $this->pdo->prepare(
                'SELECT
                    id,
                    awarded_at,
                    TIMESTAMPDIFF(SECOND, awarded_at, UTC_TIMESTAMP()) AS award_age_seconds
                 FROM skater_badge
                 WHERE skater_id = :skater_id
                   AND canskate_stage_id = :stage_id
                   AND revoked_at IS NULL
                 LIMIT 1
                 FOR UPDATE'
            );
            $current->execute([
                'skater_id' => $target['skater_id'],
                'stage_id' => $target['stage_id'],
            ]);
            $record = $current->fetch();
            if (!$record) {
                throw new InvalidArgumentException('This stage badge is not currently marked awarded.');
            }
            if ($sameDayOnly && !$this->utcTimestampIsToday((string) $record['awarded_at'])) {
                throw new InvalidArgumentException(self::RINK_PRIOR_ACHIEVEMENT_REMOVAL_MESSAGE);
            }

            $awardAgeSeconds = (int) $record['award_age_seconds'];
            $isCorrection = $awardAgeSeconds >= 0
                && $awardAgeSeconds <= self::AWARD_CORRECTION_WINDOW_SECONDS;

            if ($isCorrection) {
                $statement = $this->pdo->prepare(
                    'DELETE FROM skater_badge
                     WHERE id = :id
                       AND revoked_at IS NULL'
                );
                $statement->execute(['id' => $record['id']]);
            } else {
                $statement = $this->pdo->prepare(
                    'UPDATE skater_badge
                     SET
                        revoked_at = UTC_TIMESTAMP(),
                        revoked_by_user_id = :revoked_by_user_id,
                        updated_by_user_id = :updated_by_user_id
                     WHERE id = :id
                       AND revoked_at IS NULL'
                );
                $statement->execute([
                    'revoked_by_user_id' => $userId,
                    'updated_by_user_id' => $userId,
                    'id' => $record['id'],
                ]);
            }

            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The stage badge award could not be removed.');
            }

            $this->pdo->commit();

            return $isCorrection;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function assignSkatersToGroup(
        int $clubId,
        int $userId,
        array $skaterPublicIds,
        int $seasonId,
        int $sessionId,
        ?int $groupId,
        ?string $groupName = null,
        ?string $groupColour = null
    ): array {
        $skaterPublicIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): string => trim((string) $id), $skaterPublicIds),
            static fn (string $id): bool => preg_match(
                '/^[0-9a-fA-F-]{36}$/D',
                $id
            ) === 1
        )));
        if ($skaterPublicIds === []) {
            throw new InvalidArgumentException('Select at least one skater.');
        }
        if (count($skaterPublicIds) > 500) {
            throw new InvalidArgumentException('Assign no more than 500 skaters at once.');
        }
        if ($seasonId <= 0) {
            throw new InvalidArgumentException('Choose a valid season.');
        }
        if ($sessionId <= 0) {
            throw new InvalidArgumentException(
                'Choose a single session before assigning a group.'
            );
        }
        $groupName = $groupName === null ? null : trim($groupName);
        $groupColour = $groupColour === null ? null : strtoupper(trim($groupColour));
        if ($groupId !== null && $groupName !== null) {
            throw new InvalidArgumentException('Choose either a group or a group colour.');
        }
        if ($groupName !== null) {
            if ($groupName === '' || mb_strlen($groupName) > 100) {
                throw new InvalidArgumentException(
                    'Group colour names must be between 1 and 100 characters.'
                );
            }
            if (
                $groupColour === null
                || preg_match('/^#[0-9A-F]{6}$/D', $groupColour) !== 1
            ) {
                throw new InvalidArgumentException('Choose a valid group colour.');
            }
        } elseif ($groupColour !== null) {
            throw new InvalidArgumentException('Choose a group colour name.');
        }
        $assignmentGroupName = $groupName;
        $assignmentGroupColour = $groupColour;

        $season = $this->pdo->prepare(
            'SELECT id
             FROM season
             WHERE id = :season_id
               AND club_id = :club_id
               AND active = 1
               AND deleted_at IS NULL'
        );
        $season->execute(['season_id' => $seasonId, 'club_id' => $clubId]);
        $seasonRow = $season->fetch();
        if (!$seasonRow) {
            throw new InvalidArgumentException('The selected season could not be found.');
        }

        $targetSessionId = $sessionId;
        if ($groupId !== null) {
            $group = $this->pdo->prepare(
                'SELECT
                    pg.program_session_id,
                    pg.name,
                    pg.colour_hex,
                    ps.season_id
                 FROM program_group pg
                 INNER JOIN program_session ps
                    ON ps.id = pg.program_session_id
                    AND ps.club_id = :club_id
                    AND ps.active = 1
                    AND ps.deleted_at IS NULL
                 WHERE pg.id = :group_id
                   AND pg.active = 1
                   AND pg.deleted_at IS NULL'
            );
            $group->execute(['club_id' => $clubId, 'group_id' => $groupId]);
            $groupTarget = $group->fetch();
            if (!$groupTarget || (int) $groupTarget['season_id'] !== $seasonId) {
                throw new InvalidArgumentException(
                    'The selected group is not available in this season.'
                );
            }
            $targetSessionId = (int) $groupTarget['program_session_id'];
            if ($sessionId !== $targetSessionId) {
                throw new InvalidArgumentException(
                    'The selected group does not belong to the selected session.'
                );
            }
            $assignmentGroupName = (string) $groupTarget['name'];
            $assignmentGroupColour = $groupTarget['colour_hex'] === null
                ? null
                : (string) $groupTarget['colour_hex'];
        } else {
            $session = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM program_session
                 WHERE id = :session_id
                   AND season_id = :season_id
                   AND club_id = :club_id
                   AND active = 1
                   AND deleted_at IS NULL'
            );
            $session->execute([
                'session_id' => $sessionId,
                'season_id' => $seasonId,
                'club_id' => $clubId,
            ]);
            if ((int) $session->fetchColumn() === 0) {
                throw new InvalidArgumentException(
                    'The selected session could not be found.'
                );
            }
        }

        $placeholders = implode(',', array_fill(0, count($skaterPublicIds), '?'));
        $enrollmentSql =
            'SELECT
                e.id AS enrollment_id,
                e.program_session_id,
                s.public_id AS skater_public_id
             FROM skater_enrollment e
             INNER JOIN skater s
                ON s.id = e.skater_id
                AND s.club_id = ?
                AND s.deleted_at IS NULL
             INNER JOIN program_session ps
                ON ps.id = e.program_session_id
                AND ps.season_id = ?
                AND ps.active = 1
                AND ps.deleted_at IS NULL
             WHERE s.public_id IN (' . $placeholders . ')
               AND e.active = 1
               AND e.deleted_at IS NULL';
        $enrollmentParameters = [$clubId, $seasonId, ...$skaterPublicIds];
        if ($targetSessionId !== null) {
            $enrollmentSql .= ' AND e.program_session_id = ?';
            $enrollmentParameters[] = $targetSessionId;
        }
        $enrollments = $this->pdo->prepare($enrollmentSql);
        $enrollments->execute($enrollmentParameters);
        $enrollmentRows = $enrollments->fetchAll();

        $insert = $this->pdo->prepare(
            'INSERT INTO group_assignment (
                skater_enrollment_id,
                program_group_id,
                notes,
                created_by_user_id
             ) VALUES (
                :enrollment_id,
                :group_id,
                :notes,
                :created_by_user_id
             )'
        );
        $findPaletteGroup = $this->pdo->prepare(
            'SELECT id
             FROM program_group
             WHERE program_session_id = :session_id
               AND name = :name
             LIMIT 1'
        );
        $restorePaletteGroup = $this->pdo->prepare(
            'UPDATE program_group
             SET
                colour_hex = :colour_hex,
                active = 1,
                updated_by_user_id = :updated_by_user_id,
                deleted_at = NULL
             WHERE id = :group_id'
        );
        $insertPaletteGroup = $this->pdo->prepare(
            'INSERT INTO program_group (
                program_session_id,
                name,
                colour_hex,
                display_order,
                notes,
                active,
                created_by_user_id,
                updated_by_user_id
             )
             SELECT
                :session_id,
                :name,
                :colour_hex,
                COALESCE(MAX(display_order), 0) + 10,
                :notes,
                1,
                :created_by_user_id,
                :updated_by_user_id
             FROM program_group
             WHERE program_session_id = :display_order_session_id'
        );

        $paletteGroupIdsBySession = [];
        $affectedSkaters = [];
        $updates = [];
        $assignmentCount = 0;
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            foreach ($enrollmentRows as $enrollment) {
                $lock = $this->pdo->prepare('SELECT id FROM skater_enrollment WHERE id = ? FOR UPDATE');
                $lock->execute([$enrollment['enrollment_id']]);
                $enrollmentSessionId = (int) $enrollment['program_session_id'];
                $assignmentGroupId = $groupId;
                if ($groupName !== null) {
                    if (!array_key_exists($enrollmentSessionId, $paletteGroupIdsBySession)) {
                        $findPaletteGroup->execute([
                            'session_id' => $enrollmentSessionId,
                            'name' => $groupName,
                        ]);
                        $paletteGroupId = $findPaletteGroup->fetchColumn();
                        if ($paletteGroupId === false) {
                            $insertPaletteGroup->execute([
                                'session_id' => $enrollmentSessionId,
                                'name' => $groupName,
                                'colour_hex' => $groupColour,
                                'notes' => 'Created from the administrator group-colour palette.',
                                'created_by_user_id' => $userId,
                                'updated_by_user_id' => $userId,
                                'display_order_session_id' => $enrollmentSessionId,
                            ]);
                            $paletteGroupId = $this->pdo->lastInsertId();
                        } else {
                            $restorePaletteGroup->execute([
                                'colour_hex' => $groupColour,
                                'updated_by_user_id' => $userId,
                                'group_id' => $paletteGroupId,
                            ]);
                        }
                        $paletteGroupIdsBySession[$enrollmentSessionId] =
                            (int) $paletteGroupId;
                    }
                    $assignmentGroupId = $paletteGroupIdsBySession[$enrollmentSessionId];
                }

                $insert->execute([
                    'enrollment_id' => $enrollment['enrollment_id'],
                    'group_id' => $assignmentGroupId,
                    'notes' => $assignmentGroupId === null
                        ? 'Group unassigned from the administrator dashboard.'
                        : 'Group assigned from the administrator dashboard.',
                    'created_by_user_id' => $userId,
                ]);
                $assignmentCount++;
                $skaterPublicId = (string) $enrollment['skater_public_id'];
                $affectedSkaters[$skaterPublicId] = true;
                $updates[] = [
                    'skater_id' => $skaterPublicId,
                    'session_id' => $enrollmentSessionId,
                    'group_id' => $assignmentGroupId,
                    'group_name' => $assignmentGroupId === null
                        ? null
                        : $assignmentGroupName,
                    'group_colour' => $assignmentGroupId === null
                        ? null
                        : $assignmentGroupColour,
                ];
            }
            if ($ownsTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if ($assignmentCount === 0) {
            throw new InvalidArgumentException(
                'None of the selected skaters has an eligible registration for this assignment.'
            );
        }

        return [
            'assignments' => $assignmentCount,
            'skaters' => count($affectedSkaters),
            'skipped' => count($skaterPublicIds) - count($affectedSkaters),
            'updates' => $updates,
        ];
    }

    private function resolveManualAssessmentTarget(
        int $clubId,
        string $skaterPublicId,
        int $skillId
    ): array {
        $statement = $this->pdo->prepare(
            'SELECT
                s.id AS skater_id,
                cs.skill_code
             FROM skater s
             INNER JOIN canskate_skill cs
                ON cs.id = :skill_id
                AND cs.active = 1
                AND cs.deleted_at IS NULL
             INNER JOIN canskate_category cc
                ON cc.id = cs.canskate_category_id
                AND cc.active = 1
                AND cc.deleted_at IS NULL
             INNER JOIN canskate_ribbon cr
                ON cr.id = cc.canskate_ribbon_id
                AND cr.active = 1
                AND cr.deleted_at IS NULL
             INNER JOIN canskate_stage st
                ON st.id = cr.canskate_stage_id
                AND st.active = 1
                AND st.deleted_at IS NULL
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
               AND s.deleted_at IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'skill_id' => $skillId,
            'club_id' => $clubId,
            'public_id' => $skaterPublicId,
        ]);
        $target = $statement->fetch();
        if (!$target) {
            throw new InvalidArgumentException('The skater or skill could not be found.');
        }

        return $target;
    }

    /**
     * Records current achievements for every still-missing skill in a ribbon or
     * stage. This is only called by the explicit administrator award override.
     * The caller owns the surrounding transaction.
     */
    private function completeMissingAwardSkills(
        int $skaterId,
        string $awardType,
        int $awardId,
        int $userId,
        string $awardLabel
    ): array {
        $scopeSql = $awardType === 'ribbon'
            ? 'category.canskate_ribbon_id = :award_id'
            : 'ribbon.canskate_stage_id = :award_id';
        $skills = $this->pdo->prepare(
            'SELECT skill.id
             FROM canskate_skill skill
             INNER JOIN canskate_category category ON category.id = skill.canskate_category_id
             INNER JOIN canskate_ribbon ribbon ON ribbon.id = category.canskate_ribbon_id
             INNER JOIN canskate_stage stage ON stage.id = ribbon.canskate_stage_id
             LEFT JOIN skater_skill achieved
                ON achieved.skater_id = :skater_id
               AND achieved.canskate_skill_id = skill.id
             WHERE ' . $scopeSql . '
               AND skill.active = 1
               AND skill.deleted_at IS NULL
               AND (
                    (stage.stage_number = 0 AND skill.skill_code = "PCS-PARTICIPATION")
                    OR stage.stage_number <> 0
               )
               AND achieved.id IS NULL
             ORDER BY skill.id
             FOR UPDATE'
        );
        $skills->execute(['skater_id' => $skaterId, 'award_id' => $awardId]);
        $skillIds = array_map('intval', $skills->fetchAll(PDO::FETCH_COLUMN));
        if ($skillIds === []) {
            return [];
        }

        $resultId = $this->pdo->query(
            'SELECT id FROM assessment_result WHERE code = "ACHIEVED" AND active = 1 LIMIT 1'
        )->fetchColumn();
        if ($resultId === false) {
            throw new RuntimeException('The Achieved assessment result is not configured.');
        }

        $assessment = $this->pdo->prepare(
            'INSERT INTO assessment_history (
                program_date_id, coach_assignment_id, skater_id, canskate_skill_id,
                assessment_result_id, notes, assessed_at, created_by_user_id
             ) VALUES (NULL, NULL, :skater_id, :skill_id, :result_id, :notes, UTC_TIMESTAMP(), :user_id)'
        );
        $achievement = $this->pdo->prepare(
            'INSERT INTO skater_skill (
                skater_id, canskate_skill_id, assessment_history_id, achievement_date,
                achieved_by_coach_id, notes, created_by_user_id, updated_by_user_id
             ) VALUES (
                :skater_id, :skill_id, :assessment_history_id, CURDATE(), NULL,
                :notes, :created_by_user_id, :updated_by_user_id
             )'
        );
        foreach ($skillIds as $skillId) {
            $assessment->execute([
                'skater_id' => $skaterId,
                'skill_id' => $skillId,
                'result_id' => $resultId,
                'notes' => '[Administrator award override] Marked achieved while recording ' . $awardLabel . '.',
                'user_id' => $userId,
            ]);
            $achievement->execute([
                'skater_id' => $skaterId,
                'skill_id' => $skillId,
                'assessment_history_id' => (int) $this->pdo->lastInsertId(),
                'notes' => 'Marked achieved while recording ' . $awardLabel . '.',
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);
        }

        return $skillIds;
    }

    private function resolveManualRibbonTarget(
        int $clubId,
        string $skaterPublicId,
        int $ribbonId,
        bool $requireEligible
    ): array {
        if ($ribbonId <= 0) {
            throw new InvalidArgumentException('Choose a valid ribbon.');
        }

        $statement = $this->pdo->prepare(
            'SELECT
                s.id AS skater_id,
                r.id AS ribbon_id,
                st.stage_number,
                r.name AS ribbon_name,
                (
                    SELECT COUNT(*)
                    FROM canskate_skill required_skill
                    INNER JOIN canskate_category required_category
                        ON required_category.id = required_skill.canskate_category_id
                    WHERE required_category.canskate_ribbon_id = r.id
                      AND required_skill.active = 1
                      AND required_skill.deleted_at IS NULL
                      AND (
                        (st.stage_number = 0 AND required_skill.skill_code = "PCS-PARTICIPATION")
                        OR (st.stage_number <> 0 AND required_skill.skill_code <> "PCS-PARTICIPATION")
                      )
                ) AS required_skills,
                (
                    SELECT COUNT(*)
                    FROM skater_skill achieved_skill
                    INNER JOIN canskate_skill achieved_definition
                        ON achieved_definition.id = achieved_skill.canskate_skill_id
                    INNER JOIN canskate_category achieved_category
                        ON achieved_category.id = achieved_definition.canskate_category_id
                    WHERE achieved_skill.skater_id = s.id
                      AND achieved_category.canskate_ribbon_id = r.id
                      AND achieved_definition.active = 1
                      AND achieved_definition.deleted_at IS NULL
                      AND (
                        (st.stage_number = 0 AND achieved_definition.skill_code = "PCS-PARTICIPATION")
                        OR (st.stage_number <> 0 AND achieved_definition.skill_code <> "PCS-PARTICIPATION")
                      )
                ) AS achieved_skills
             FROM skater s
             INNER JOIN canskate_ribbon r
                ON r.id = :ribbon_id
                AND r.active = 1
                AND r.deleted_at IS NULL
             INNER JOIN canskate_stage st
                ON st.id = r.canskate_stage_id
                AND st.active = 1
                AND st.deleted_at IS NULL
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
               AND s.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'ribbon_id' => $ribbonId,
            'club_id' => $clubId,
            'public_id' => $skaterPublicId,
        ]);
        $target = $statement->fetch();
        if (!$target) {
            throw new InvalidArgumentException('The skater or ribbon could not be found.');
        }
        $requiredSkillCount = CanSkateRequirements::ribbonRequiredSkillCount(
            (int) $target['stage_number'],
            (string) $target['ribbon_name'],
            (int) $target['required_skills']
        );
        if (
            $requireEligible
            && (
                $requiredSkillCount === 0
                || (int) $target['achieved_skills'] < $requiredSkillCount
            )
        ) {
            throw new InvalidArgumentException(
                $requiredSkillCount . ' of ' . (int) $target['required_skills']
                . ' skills associated with this ribbon must be achieved before it can be awarded.'
            );
        }

        return $target;
    }

    private function resolveManualBadgeTarget(
        int $clubId,
        string $skaterPublicId,
        int $stageId,
        bool $requireEligible
    ): array {
        if ($stageId <= 0) {
            throw new InvalidArgumentException('Choose a valid stage badge.');
        }

        $statement = $this->pdo->prepare(
            'SELECT
                s.id AS skater_id,
                st.id AS stage_id,
                (
                    SELECT COUNT(*)
                    FROM canskate_ribbon required_ribbon
                    WHERE required_ribbon.canskate_stage_id = st.id
                      AND required_ribbon.active = 1
                      AND required_ribbon.deleted_at IS NULL
                ) AS required_ribbons,
                (
                    SELECT COUNT(*)
                    FROM skater_ribbon awarded_ribbon
                    INNER JOIN canskate_ribbon ribbon_definition
                        ON ribbon_definition.id = awarded_ribbon.canskate_ribbon_id
                    WHERE awarded_ribbon.skater_id = s.id
                      AND awarded_ribbon.revoked_at IS NULL
                      AND ribbon_definition.canskate_stage_id = st.id
                      AND ribbon_definition.active = 1
                      AND ribbon_definition.deleted_at IS NULL
                ) AS awarded_ribbons
             FROM skater s
             INNER JOIN canskate_stage st
                ON st.id = :stage_id
                AND st.active = 1
                AND st.deleted_at IS NULL
                AND st.stage_number > 0
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
               AND s.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'stage_id' => $stageId,
            'club_id' => $clubId,
            'public_id' => $skaterPublicId,
        ]);
        $target = $statement->fetch();
        if (!$target) {
            throw new InvalidArgumentException('The skater or stage could not be found.');
        }
        if (
            $requireEligible
            && (
                (int) $target['required_ribbons'] === 0
                || (int) $target['awarded_ribbons'] < (int) $target['required_ribbons']
            )
        ) {
            throw new InvalidArgumentException(
                'All fundamental-area ribbons for this stage must be awarded before its badge can be awarded.'
            );
        }

        return $target;
    }

    private function skateCanadaNumber($value): ?string
    {
        $number = strtoupper(trim((string) $value));
        if ($number === '') {
            return null;
        }
        if (preg_match('/^[A-Z0-9]{10}$/D', $number) !== 1) {
            throw new InvalidArgumentException(
                'Skate Canada number must contain exactly 10 letters or numbers, with no spaces or punctuation.'
            );
        }

        return $number;
    }

    private function importSkateCanadaNumber($value): ?string
    {
        $number = strtoupper(trim((string) $value));
        if ($number === '') {
            return null;
        }
        if (mb_strlen($number) > 100 || preg_match('/^[A-Z0-9]+$/D', $number) !== 1) {
            throw new InvalidArgumentException(
                'Imported Skate Canada number must contain no more than 100 letters or numbers, with no spaces or punctuation.'
            );
        }

        return $number;
    }

    private function utcTimestampIsToday(string $value): bool
    {
        try {
            $timestamp = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception $exception) {
            return false;
        }

        return $timestamp
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d') === date('Y-m-d');
    }

    private function recordAuditEvent(int $skaterId, string $eventType, string $details, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO skater_audit_event (
                skater_id, event_type, details, event_at, created_by_user_id
             ) VALUES (:skater_id, :event_type, :details, UTC_TIMESTAMP(), :user_id)'
        );
        $statement->execute([
            'skater_id' => $skaterId,
            'event_type' => $eventType,
            'details' => $details,
            'user_id' => $userId,
        ]);
    }

    private function genderId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $genderId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($genderId === false) {
            throw new InvalidArgumentException('Choose a valid gender.');
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM gender WHERE id = :id AND active = 1'
        );
        $statement->execute(['id' => $genderId]);
        if ((int) $statement->fetchColumn() === 0) {
            throw new InvalidArgumentException('Choose a valid gender.');
        }

        return (int) $genderId;
    }

    private function genderText($value): ?string
    {
        $gender = trim((string) $value);
        if (mb_strlen($gender) > 80) {
            throw new InvalidArgumentException('Gender must be 80 characters or fewer.');
        }

        return $gender === '' ? null : $gender;
    }

    private function nullable($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function version($value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $value) !== 1) {
            throw new InvalidArgumentException('This skater was changed or removed by another user. Refresh the page and try again.');
        }
        return $value;
    }

    private function duplicateSkaterMessage(PDOException $exception): string
    {
        $detail = strtolower((string) (($exception->errorInfo[2] ?? null) ?: $exception->getMessage()));
        if (strpos($detail, 'uq_skater_active_identity') !== false) {
            return 'An active skater with this first name, last name, and date of birth already exists.';
        }
        return 'That Skate Canada number is already assigned to another skater.';
    }
}
