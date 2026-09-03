<?php

declare(strict_types=1);

final class RinkService
{
    private PDO $pdo;
    private SkaterService $skaters;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->skaters = new SkaterService($pdo);
    }

    /**
     * Enforce the Rink App's object-level authorization boundary.
     *
     * Administrators, editors, and read-only users may view any session in
     * their club. A coach account linked to a coach record is limited to its
     * active assignments. Legacy/unlinked coach accounts retain club-wide
     * Coach App access because account creation does not require a coach link.
     */
    public function assertUserSessionAccess(
        array $user,
        int $seasonId,
        int $sessionId,
        ?string $skaterPublicId = null
    ): void {
        $clubId = filter_var($user['club_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $roleCode = strtoupper(trim((string) ($user['role_code'] ?? '')));
        if (!is_int($clubId) || !in_array(
            $roleCode,
            ['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY', 'COACH'],
            true
        )) {
            throw new InvalidArgumentException('You do not have access to that session.');
        }

        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);
        if ($roleCode !== 'COACH') {
            return;
        }

        $coachId = filter_var($user['coach_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($coachId)) {
            return;
        }

        $statement = $this->pdo->prepare(
            'SELECT assignment.id
             FROM coach_session_assignment assignment
             INNER JOIN coach coach
                ON coach.id = assignment.coach_id
               AND coach.club_id = :coach_club_id
               AND coach.active = 1
               AND coach.deleted_at IS NULL
             INNER JOIN program_session session
                ON session.id = assignment.program_session_id
               AND session.id = :session_id
               AND session.season_id = :season_id
               AND session.club_id = :session_club_id
               AND session.active = 1
               AND session.deleted_at IS NULL
             WHERE assignment.coach_id = :coach_id
               AND assignment.active = 1
               AND assignment.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'coach_club_id' => $clubId,
            'session_club_id' => $clubId,
            'session_id' => $sessionId,
            'season_id' => $seasonId,
            'coach_id' => $coachId,
        ]);
        if ($statement->fetchColumn() === false) {
            throw new InvalidArgumentException('You are not assigned to that session.');
        }
    }

    /**
     * Restrict navigation choices for a linked coach to explicitly assigned
     * sessions. Non-coach roles and legacy/unlinked coach accounts retain the
     * club-wide filter options supplied to the method.
     */
    public function filterOptionsForUser(array $user, array $options): array
    {
        if (strtoupper(trim((string) ($user['role_code'] ?? ''))) !== 'COACH') {
            return $options;
        }

        $clubId = filter_var($user['club_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $coachId = filter_var($user['coach_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($clubId)) {
            return ['seasons' => [], 'sessions' => [], 'groups' => []];
        }
        if (!is_int($coachId)) {
            return $options;
        }

        $statement = $this->pdo->prepare(
            'SELECT session.id
             FROM coach_session_assignment assignment
             INNER JOIN coach coach
                ON coach.id = assignment.coach_id
               AND coach.club_id = :coach_club_id
               AND coach.active = 1
               AND coach.deleted_at IS NULL
             INNER JOIN program_session session
                ON session.id = assignment.program_session_id
               AND session.club_id = :session_club_id
               AND session.active = 1
               AND session.deleted_at IS NULL
             INNER JOIN season season
                ON season.id = session.season_id
               AND season.club_id = :season_club_id
               AND season.active = 1
               AND season.deleted_at IS NULL
             WHERE assignment.coach_id = :coach_id
               AND assignment.active = 1
               AND assignment.deleted_at IS NULL'
        );
        $statement->execute([
            'coach_club_id' => $clubId,
            'session_club_id' => $clubId,
            'season_club_id' => $clubId,
            'coach_id' => $coachId,
        ]);
        $sessionIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $sessions = array_values(array_filter(
            is_array($options['sessions'] ?? null) ? $options['sessions'] : [],
            static fn (array $session): bool => in_array((int) ($session['id'] ?? 0), $sessionIds, true)
        ));
        $authorizedSessionIds = array_map(
            static fn (array $session): int => (int) $session['id'],
            $sessions
        );
        $seasonIds = array_values(array_unique(array_map(
            static fn (array $session): int => (int) $session['season_id'],
            $sessions
        )));

        return [
            'seasons' => array_values(array_filter(
                is_array($options['seasons'] ?? null) ? $options['seasons'] : [],
                static fn (array $season): bool => in_array((int) ($season['id'] ?? 0), $seasonIds, true)
            )),
            'sessions' => $sessions,
            'groups' => array_values(array_filter(
                is_array($options['groups'] ?? null) ? $options['groups'] : [],
                static fn (array $group): bool => in_array(
                    (int) ($group['program_session_id'] ?? 0),
                    $authorizedSessionIds,
                    true
                )
            )),
        ];
    }

    public function assertSessionAccess(
        int $clubId,
        int $seasonId,
        int $sessionId,
        ?string $skaterPublicId = null
    ): void {
        $parameters = [
            'season_club_id' => $clubId,
            'session_club_id' => $clubId,
            'season_id' => $seasonId,
            'session_id' => $sessionId,
        ];
        $skaterJoin = '';
        $skaterWhere = '';
        if ($skaterPublicId !== null) {
            $skaterJoin = 'INNER JOIN skater_enrollment enrollment
                ON enrollment.program_session_id = session.id
               AND enrollment.active = 1
               AND enrollment.deleted_at IS NULL
             INNER JOIN skater skater
                ON skater.id = enrollment.skater_id
               AND skater.club_id = :skater_club_id
               AND skater.public_id = :skater_public_id
               AND skater.deleted_at IS NULL';
            $skaterWhere = ' AND skater.active = 1';
            $parameters['skater_club_id'] = $clubId;
            $parameters['skater_public_id'] = $skaterPublicId;
        }

        $statement = $this->pdo->prepare(
            'SELECT session.id
             FROM program_session session
             INNER JOIN season season
                ON season.id = session.season_id
               AND season.club_id = :season_club_id
               AND season.active = 1
               AND season.deleted_at IS NULL
             ' . $skaterJoin . '
             WHERE session.id = :session_id
               AND session.season_id = :season_id
               AND session.club_id = :session_club_id
               AND session.active = 1
               AND session.deleted_at IS NULL' . $skaterWhere . '
             LIMIT 1'
        );
        $statement->execute($parameters);
        if ($statement->fetchColumn() === false) {
            throw new InvalidArgumentException(
                $skaterPublicId === null
                    ? 'Choose a valid season and session.'
                    : 'This skater is not active in the selected session.'
            );
        }
    }

    public function assignGroup(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId,
        ?int $groupId,
        ?string $groupName = null,
        ?string $groupColour = null
    ): array {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);

        return $this->skaters->assignSkatersToGroup(
            $clubId,
            $userId,
            [$skaterPublicId],
            $seasonId,
            $sessionId,
            $groupId,
            $groupName,
            $groupColour
        );
    }

    /**
     * @return array{
     *   skaters:list<array<string,mixed>>,
     *   attendance:array{enabled:bool,date:string,message:?string,records:array<string,array{recorded:bool,present:bool,status_code:string}>}
     * }
     */
    public function roster(
        int $clubId,
        int $seasonId,
        int $sessionId,
        ?int $groupId = null,
        bool $includeSensitive = true
    ): array {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId);
        $repository = new DashboardRepository($this->pdo);
        $skaters = $repository->skaters(
            $clubId,
            '',
            'active',
            $seasonId,
            $sessionId,
            $groupId,
            10000
        );
        $attendance = $this->attendanceState(
            $clubId,
            $seasonId,
            $sessionId,
            array_values(array_map(
                static fn (array $skater): string => (string) $skater['public_id'],
                $skaters
            ))
        );
        $today = new DateTimeImmutable('today');

        return [
            'skaters' => array_values(array_map(
                static function (array $skater) use ($sessionId, $attendance, $today, $includeSensitive): array {
                    $registration = null;
                    foreach ($skater['current_sessions'] ?? [] as $candidate) {
                        if ((int) $candidate['session_id'] === $sessionId) {
                            $registration = $candidate;
                            break;
                        }
                    }
                    $birthDate = !empty($skater['date_of_birth'])
                        ? DateTimeImmutable::createFromFormat('!Y-m-d', (string) $skater['date_of_birth'])
                        : false;
                    $age = $birthDate !== false && $birthDate <= $today
                        ? $birthDate->diff($today)->y
                        : null;
                    $record = $attendance['records'][$skater['public_id']] ?? [
                        'recorded' => false,
                        'present' => false,
                        'status_code' => '',
                    ];

                    return [
                        'public_id' => (string) $skater['public_id'],
                        'first_name' => (string) $skater['first_name'],
                        'last_name' => (string) $skater['last_name'],
                        'gender_name' => (string) ($skater['gender_name'] ?? ''),
                        'age' => $age,
                        'general_notes' => $includeSensitive ? (string) ($skater['general_notes'] ?? '') : '',
                        'report_card_notes' => $includeSensitive ? (string) ($skater['report_card_notes'] ?? '') : '',
                        'report_card_note_updated_by_name' => $includeSensitive ? (string) ($skater['report_card_note_updated_by_name'] ?? '') : '',
                        'report_card_note_updated_at' => $includeSensitive ? ($skater['report_card_note_updated_at'] ?? null) : null,
                        'medical_notes' => $includeSensitive ? (string) ($skater['medical_notes'] ?? '') : '',
                        'group_id' => $registration['group_id'] ?? null,
                        'group_name' => (string) ($registration['group_name'] ?? 'No group'),
                        'group_colour' => (string) ($registration['group_colour'] ?? '#ffffff'),
                        'attendance_recorded' => (bool) $record['recorded'],
                        'present' => (bool) $record['present'],
                        'attendance_status' => (string) $record['status_code'],
                    ];
                },
                $skaters
            )),
            'attendance' => $attendance,
        ];
    }

    /**
     * @return array{
     *   stages:list<array<string,mixed>>,
     *   skaters:list<array<string,mixed>>
     * }
     */
    public function assess(
        int $clubId,
        int $seasonId,
        int $sessionId,
        ?int $groupId = null,
        bool $includeSensitive = true
    ): array {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId);
        $repository = new DashboardRepository($this->pdo);
        $skaters = $repository->skaters(
            $clubId,
            '',
            'active',
            $seasonId,
            $sessionId,
            $groupId,
            10000
        );

        return [
            'stages' => $repository->curriculum(),
            'skaters' => array_values(array_map(
                static function (array $skater) use ($sessionId, $includeSensitive): array {
                    $registration = null;
                    foreach ($skater['current_sessions'] ?? [] as $candidate) {
                        if ((int) $candidate['session_id'] === $sessionId) {
                            $registration = $candidate;
                            break;
                        }
                    }

                    return [
                        'public_id' => (string) $skater['public_id'],
                        'first_name' => (string) $skater['first_name'],
                        'last_name' => (string) $skater['last_name'],
                        'report_card_notes' => $includeSensitive ? (string) ($skater['report_card_notes'] ?? '') : '',
                        'report_card_note_updated_by_name' => $includeSensitive ? (string) ($skater['report_card_note_updated_by_name'] ?? '') : '',
                        'report_card_note_updated_at' => $includeSensitive ? ($skater['report_card_note_updated_at'] ?? null) : null,
                        'medical_notes' => $includeSensitive ? (string) ($skater['medical_notes'] ?? '') : '',
                        'group_id' => $registration['group_id'] ?? null,
                        'group_name' => (string) ($registration['group_name'] ?? 'No group'),
                        'group_colour' => (string) ($registration['group_colour'] ?? '#ffffff'),
                        'season_session_count' => count($skater['current_sessions'] ?? []),
                        'skills' => $skater['skills'] ?? [],
                        'ribbons' => $skater['ribbons'] ?? [],
                        'badges' => $skater['badges'] ?? [],
                    ];
                },
                $skaters
            )),
        ];
    }

    /** @return array{messages:list<array<string,mixed>>,unread_count:int} */
    public function chat(int $clubId, int $userId, int $seasonId, int $sessionId, bool $markViewed = false): array
    {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId);
        $this->purgeExpiredChat();
        $unread = $this->chatUnreadCount($clubId, $userId, $sessionId);
        $messages = $this->pdo->prepare(
            'SELECT message.public_id,
                    CASE WHEN message.deleted_at IS NULL THEN message.message_text ELSE NULL END AS message_text,
                    message.edited_at, message.deleted_at, message.created_at, message.expires_at,
                    user.first_name, user.last_name, message.app_user_id
             FROM rink_chat_message message
             INNER JOIN app_user user ON user.id = message.app_user_id
             WHERE message.club_id = :club_id AND message.program_session_id = :session_id
               AND message.expires_at > UTC_TIMESTAMP()
             ORDER BY message.created_at ASC, message.id ASC'
        );
        $messages->execute(['club_id' => $clubId, 'session_id' => $sessionId]);
        if ($markViewed) $this->markChatViewed($userId, $sessionId);
        return ['messages' => $messages->fetchAll(), 'unread_count' => $unread];
    }

    public function chatUnreadCount(int $clubId, int $userId, int $sessionId): int
    {
        $this->purgeExpiredChat();
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM rink_chat_message message
             LEFT JOIN rink_chat_view view_state
               ON view_state.program_session_id = message.program_session_id
              AND view_state.app_user_id = :viewer_id
             WHERE message.club_id = :club_id AND message.program_session_id = :session_id
               AND message.app_user_id <> :author_id AND message.expires_at > UTC_TIMESTAMP()
               AND (view_state.last_viewed_at IS NULL OR message.created_at > view_state.last_viewed_at)'
        );
        $statement->execute([
            'club_id' => $clubId,
            'session_id' => $sessionId,
            'viewer_id' => $userId,
            'author_id' => $userId,
        ]);
        return (int) $statement->fetchColumn();
    }

    public function postChat(int $clubId, int $userId, int $seasonId, int $sessionId, string $message): void
    {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId);
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 1500) throw new InvalidArgumentException('Enter a message of up to 1,500 characters.');
        $expiry = (new DateTimeImmutable('+12 hours', new DateTimeZone('UTC')));
        $statement = $this->pdo->prepare('INSERT INTO rink_chat_message (club_id, program_session_id, app_user_id, message_text, expires_at) VALUES (:club_id, :session_id, :user_id, :message, :expires_at)');
        $statement->execute(['club_id' => $clubId, 'session_id' => $sessionId, 'user_id' => $userId, 'message' => $message, 'expires_at' => $expiry->format('Y-m-d H:i:s')]);
        $this->markChatViewed($userId, $sessionId);
    }

    public function editChat(int $clubId, int $userId, int $seasonId, int $sessionId, string $publicId, string $message): void
    {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId);
        $this->purgeExpiredChat();
        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 1500) throw new InvalidArgumentException('Enter a message of up to 1,500 characters.');
        $messageStatement = $this->pdo->prepare(
            'SELECT id FROM rink_chat_message
             WHERE public_id = :public_id AND club_id = :club_id AND program_session_id = :session_id
               AND app_user_id = :user_id AND deleted_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $messageStatement->execute([
            'public_id' => $publicId,
            'club_id' => $clubId,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);
        $chatMessage = $messageStatement->fetch();
        if (!$chatMessage) throw new InvalidArgumentException('That message can no longer be edited.');
        $statement = $this->pdo->prepare(
            'UPDATE rink_chat_message SET message_text = :message, edited_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $statement->execute([
            'message' => $message,
            'id' => $chatMessage['id'],
        ]);
    }

    public function deleteChat(int $clubId, int $userId, int $seasonId, int $sessionId, string $publicId): void
    {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId);
        $this->purgeExpiredChat();
        $statement = $this->pdo->prepare(
            'UPDATE rink_chat_message SET message_text = "", deleted_at = UTC_TIMESTAMP()
             WHERE public_id = :public_id AND club_id = :club_id AND program_session_id = :session_id
               AND app_user_id = :user_id AND deleted_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $statement->execute([
            'public_id' => $publicId,
            'club_id' => $clubId,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);
        if ($statement->rowCount() === 0) throw new InvalidArgumentException('That message can no longer be deleted.');
    }

    public function updateChatExpiry(int $clubId, int $userId, int $seasonId, int $sessionId, string $publicId, string $expiresAt): void
    {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId);
        $this->purgeExpiredChat();
        try {
            $expiry = new DateTimeImmutable($expiresAt);
        } catch (Exception $exception) {
            throw new InvalidArgumentException('Choose a valid deletion date and time.');
        }
        $expiry = $expiry->setTimezone(new DateTimeZone('UTC'));
        if ($expiry <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('Choose a deletion date and time in the future.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE rink_chat_message SET expires_at = :expires_at
             WHERE public_id = :public_id AND club_id = :club_id AND program_session_id = :session_id
               AND app_user_id = :user_id AND deleted_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $statement->execute([
            'expires_at' => $expiry->format('Y-m-d H:i:s'),
            'public_id' => $publicId,
            'club_id' => $clubId,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);
        if ($statement->rowCount() === 0) throw new InvalidArgumentException('That message can no longer be updated.');
    }

    private function markChatViewed(int $userId, int $sessionId): void
    {
        $statement = $this->pdo->prepare('INSERT INTO rink_chat_view (program_session_id, app_user_id, last_viewed_at) VALUES (:session_id, :user_id, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_viewed_at = VALUES(last_viewed_at)');
        $statement->execute(['session_id' => $sessionId, 'user_id' => $userId]);
    }

    private function purgeExpiredChat(): void
    {
        $this->pdo->exec('DELETE FROM rink_chat_message WHERE expires_at <= UTC_TIMESTAMP()');
    }

    /**
     * @param list<string> $skaterPublicIds
     * @return array{enabled:bool,date:string,message:?string,records:array<string,array{recorded:bool,present:bool,status_code:string}>}
     */
    public function attendanceState(
        int $clubId,
        int $seasonId,
        int $sessionId,
        array $skaterPublicIds
    ): array {
        $context = $this->sessionContext($clubId, $seasonId, $sessionId);
        $window = $this->attendanceWindow($context);
        $attendanceDate = $window['date'];
        $enabled = $window['enabled'];
        $message = $window['message'];
        $records = [];
        if ($skaterPublicIds !== []) {
            $placeholders = implode(',', array_fill(0, count($skaterPublicIds), '?'));
            $statement = $this->pdo->prepare(
                "SELECT
                    s.public_id,
                    ast.code AS status_code,
                    ast.counts_as_present
                 FROM skater s
                 LEFT JOIN program_date pd
                    ON pd.program_session_id = ?
                   AND pd.session_date = ?
                 LEFT JOIN attendance a
                    ON a.program_date_id = pd.id
                   AND a.skater_id = s.id
                 LEFT JOIN attendance_status ast ON ast.id = a.attendance_status_id
                 WHERE s.club_id = ?
                   AND s.public_id IN ($placeholders)"
            );
            $statement->execute([$sessionId, $attendanceDate, $clubId, ...$skaterPublicIds]);
            foreach ($statement->fetchAll() as $row) {
                $records[(string) $row['public_id']] = [
                    'recorded' => $row['status_code'] !== null,
                    'present' => (int) ($row['counts_as_present'] ?? 0) === 1,
                    'status_code' => (string) ($row['status_code'] ?? ''),
                ];
            }
        }

        return [
            'enabled' => $enabled,
            'date' => $attendanceDate,
            'message' => $message,
            'records' => $records,
        ];
    }

    public function recordAttendance(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId,
        bool $present
    ): array {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);
        $context = $this->sessionContext($clubId, $seasonId, $sessionId);
        $window = $this->attendanceWindow($context);
        $attendanceDate = $window['date'];

        $this->pdo->beginTransaction();
        try {
            $programDate = $this->pdo->prepare(
                'SELECT id, cancelled
                 FROM program_date
                 WHERE program_session_id = :session_id
                   AND session_date = :session_date
                 LIMIT 1
                 FOR UPDATE'
            );
            $programDate->execute(['session_id' => $sessionId, 'session_date' => $attendanceDate]);
            $lesson = $programDate->fetch();
            if (!$lesson) {
                $lastLesson = $this->pdo->prepare(
                    'SELECT lesson_number
                     FROM program_date
                     WHERE program_session_id = :session_id
                     ORDER BY lesson_number DESC
                     LIMIT 1
                     FOR UPDATE'
                );
                $lastLesson->execute(['session_id' => $sessionId]);
                $lessonNumber = ((int) ($lastLesson->fetchColumn() ?: 0)) + 1;
                $insertDate = $this->pdo->prepare(
                    'INSERT INTO program_date (
                        program_session_id, lesson_number, session_date, cancelled,
                        notes, created_by_user_id, updated_by_user_id
                     ) VALUES (
                        :session_id, :lesson_number, :session_date, 0,
                        :notes, :user_id, :updated_user_id
                     )'
                );
                $insertDate->execute([
                    'session_id' => $sessionId,
                    'lesson_number' => $lessonNumber,
                    'session_date' => $attendanceDate,
                    'notes' => 'Created automatically from the Coach App attendance roster.',
                    'user_id' => $userId,
                    'updated_user_id' => $userId,
                ]);
                $programDateId = (int) $this->pdo->lastInsertId();
            } else {
                if ((int) $lesson['cancelled'] === 1) {
                    throw new InvalidArgumentException('Attendance cannot be recorded for a cancelled session date.');
                }
                $programDateId = (int) $lesson['id'];
            }

            $skater = $this->pdo->prepare(
                'SELECT id FROM skater
                 WHERE club_id = :club_id AND public_id = :public_id
                   AND active = 1 AND deleted_at IS NULL
                 LIMIT 1'
            );
            $skater->execute(['club_id' => $clubId, 'public_id' => $skaterPublicId]);
            $skaterId = $skater->fetchColumn();
            $status = $this->pdo->prepare(
                'SELECT id, code FROM attendance_status
                 WHERE code = :code AND active = 1 LIMIT 1'
            );
            $status->execute(['code' => $present ? 'PRESENT' : 'ABSENT']);
            $statusRow = $status->fetch();
            if ($skaterId === false || !$statusRow) {
                throw new RuntimeException('Attendance could not be recorded because its reference data is incomplete.');
            }
            $attendance = $this->pdo->prepare(
                'INSERT INTO attendance (
                    program_date_id, skater_id, attendance_status_id,
                    recorded_by_user_id, notes, updated_by_user_id
                 ) VALUES (
                    :program_date_id, :skater_id, :status_id,
                    :recorded_by_user_id, :notes, :updated_by_user_id
                 )
                 ON DUPLICATE KEY UPDATE
                    attendance_status_id = VALUES(attendance_status_id),
                    recorded_by_user_id = VALUES(recorded_by_user_id),
                    notes = VALUES(notes),
                    recorded_at = UTC_TIMESTAMP(),
                    updated_by_user_id = VALUES(updated_by_user_id)'
            );
            $attendance->execute([
                'program_date_id' => $programDateId,
                'skater_id' => (int) $skaterId,
                'status_id' => (int) $statusRow['id'],
                'recorded_by_user_id' => $userId,
                'notes' => 'Recorded from the Coach App.',
                'updated_by_user_id' => $userId,
            ]);
            $this->pdo->commit();

            return ['present' => $present, 'status_code' => (string) $statusRow['code']];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function saveReportCardNote(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId,
        string $note
    ): array {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);
        $note = trim($note);
        if (mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('Enter a report-card note of up to 2,000 characters.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO skater_season_report_card_note
                (skater_id, season_id, note, updated_by_user_id)
             SELECT s.id, :season_id, :note, :user_id
             FROM skater s
             WHERE s.club_id = :club_id AND s.public_id = :public_id AND s.deleted_at IS NULL
             ON DUPLICATE KEY UPDATE
                note = VALUES(note),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'season_id' => $seasonId,
            'note' => $note,
            'user_id' => $userId,
            'club_id' => $clubId,
            'public_id' => $skaterPublicId,
        ]);
        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare(
                'SELECT 1 FROM skater WHERE club_id = :club_id AND public_id = :public_id AND deleted_at IS NULL'
            );
            $exists->execute(['club_id' => $clubId, 'public_id' => $skaterPublicId]);
            if ($exists->fetchColumn() === false) {
                throw new InvalidArgumentException('Skater not found.');
            }
        }
        $updatedNote = $this->pdo->prepare(
            'SELECT report_note.note,
                    CONCAT_WS(\' \', u.first_name, u.last_name) AS updated_by_name,
                    report_note.updated_at
             FROM skater s
             LEFT JOIN skater_season_report_card_note report_note
                ON report_note.skater_id = s.id AND report_note.season_id = :season_id
             LEFT JOIN app_user u ON u.id = report_note.updated_by_user_id
             WHERE s.club_id = :club_id AND s.public_id = :public_id AND s.deleted_at IS NULL'
        );
        $updatedNote->execute(['season_id' => $seasonId, 'club_id' => $clubId, 'public_id' => $skaterPublicId]);
        $result = $updatedNote->fetch();
        if (!is_array($result)) {
            throw new InvalidArgumentException('Skater not found.');
        }
        return [
            'note' => (string) ($result['note'] ?? ''),
            'updated_by_name' => (string) ($result['updated_by_name'] ?? ''),
            'updated_at' => $result['updated_at'] ?? null,
        ];
    }

    public function deleteReportCardNote(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId
    ): void {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);
        $statement = $this->pdo->prepare(
            'DELETE report_note
             FROM skater_season_report_card_note report_note
             INNER JOIN skater s ON s.id = report_note.skater_id
             WHERE report_note.season_id = :season_id
               AND s.club_id = :club_id AND s.public_id = :public_id AND s.deleted_at IS NULL'
        );
        $statement->execute([
            'season_id' => $seasonId,
            'club_id' => $clubId,
            'public_id' => $skaterPublicId,
        ]);
        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare(
                'SELECT 1 FROM skater WHERE club_id = :club_id AND public_id = :public_id AND deleted_at IS NULL'
            );
            $exists->execute(['club_id' => $clubId, 'public_id' => $skaterPublicId]);
            if ($exists->fetchColumn() === false) {
                throw new InvalidArgumentException('Skater not found.');
            }
        }
    }

    /** @return list<array{id:int,title:string,content:string}> */
    public function reportCardNoteLibrary(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, note_title AS title, note_content AS content
             FROM report_card_note_library
             WHERE app_user_id = :user_id
             ORDER BY created_at ASC, id ASC'
        );
        $statement->execute(['user_id' => $userId]);
        return array_map(static fn (array $note): array => [
            'id' => (int) $note['id'],
            'title' => (string) $note['title'],
            'content' => (string) $note['content'],
        ], $statement->fetchAll());
    }

    /** @return array{id:int,title:string,content:string} */
    public function createReportCardLibraryNote(int $userId, string $title, string $content): array
    {
        $title = trim($title);
        $content = trim($content);
        if ($title === '' || mb_strlen($title) > 32) {
            throw new InvalidArgumentException('Enter a note title of up to 32 characters.');
        }
        if ($content === '' || mb_strlen($content) > 512) {
            throw new InvalidArgumentException('Enter note content of up to 512 characters.');
        }
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM app_user WHERE id = :user_id FOR UPDATE');
            $lock->execute(['user_id' => $userId]);
            if ($lock->fetchColumn() === false) throw new InvalidArgumentException('User not found.');
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM report_card_note_library WHERE app_user_id = :user_id');
            $count->execute(['user_id' => $userId]);
            if ((int) $count->fetchColumn() >= 50) {
                throw new InvalidArgumentException('Your notes library can contain up to 50 notes.');
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO report_card_note_library (app_user_id, note_title, note_content)
                 VALUES (:user_id, :title, :content)'
            );
            $insert->execute(['user_id' => $userId, 'title' => $title, 'content' => $content]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return ['id' => $id, 'title' => $title, 'content' => $content];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array{id:int,title:string,content:string} */
    public function updateReportCardLibraryNote(int $userId, int $noteId, string $title, string $content): array
    {
        $title = trim($title);
        $content = trim($content);
        if ($noteId <= 0 || $title === '' || mb_strlen($title) > 32) {
            throw new InvalidArgumentException('Enter a note title of up to 32 characters.');
        }
        if ($content === '' || mb_strlen($content) > 512) {
            throw new InvalidArgumentException('Enter note content of up to 512 characters.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE report_card_note_library
             SET note_title = :title, note_content = :content
             WHERE id = :id AND app_user_id = :user_id'
        );
        $statement->execute(['id' => $noteId, 'user_id' => $userId, 'title' => $title, 'content' => $content]);
        if ($statement->rowCount() === 0) {
            $exists = $this->pdo->prepare(
                'SELECT 1 FROM report_card_note_library WHERE id = :id AND app_user_id = :user_id'
            );
            $exists->execute(['id' => $noteId, 'user_id' => $userId]);
            if ($exists->fetchColumn() === false) throw new InvalidArgumentException('This library note could not be found.');
        }
        return ['id' => $noteId, 'title' => $title, 'content' => $content];
    }

    public function deleteReportCardLibraryNote(int $userId, int $noteId): void
    {
        if ($noteId <= 0) {
            throw new InvalidArgumentException('Choose a valid library note.');
        }
        $statement = $this->pdo->prepare(
            'DELETE FROM report_card_note_library WHERE id = :id AND app_user_id = :user_id'
        );
        $statement->execute(['id' => $noteId, 'user_id' => $userId]);
        if ($statement->rowCount() === 0) {
            throw new InvalidArgumentException('This library note could not be found.');
        }
    }

    public function markSkill(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId,
        int $skillId
    ): void {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);
        $this->skaters->markSkillAchieved($clubId, $userId, $skaterPublicId, $skillId);
    }

    public function removeSkill(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId,
        int $skillId
    ): void {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);
        $this->skaters->removeSkillAchievement(
            $clubId,
            $userId,
            $skaterPublicId,
            $skillId,
            true
        );
    }

    public function awardRibbon(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId,
        int $ribbonId
    ): array {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);

        return $this->skaters->awardRibbon(
            $clubId,
            $userId,
            $skaterPublicId,
            $ribbonId
        );
    }

    public function removeRibbon(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId,
        int $ribbonId
    ): void {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);
        $this->skaters->revokeRibbonAward(
            $clubId,
            $userId,
            $skaterPublicId,
            $ribbonId,
            true
        );
    }

    public function awardBadge(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId,
        int $stageId
    ): array {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);

        return $this->skaters->awardStageBadge(
            $clubId,
            $userId,
            $skaterPublicId,
            $stageId
        );
    }

    public function removeBadge(
        int $clubId,
        int $userId,
        int $seasonId,
        int $sessionId,
        string $skaterPublicId,
        int $stageId
    ): void {
        $this->assertSessionAccess($clubId, $seasonId, $sessionId, $skaterPublicId);
        $this->skaters->revokeStageBadge(
            $clubId,
            $userId,
            $skaterPublicId,
            $stageId,
            true
        );
    }

    /**
     * @param array{time_zone:string} $context
     * @return array{enabled:bool,date:string,message:string}
     */
    private function attendanceWindow(array $context, ?DateTimeImmutable $now = null): array
    {
        $timeZone = new DateTimeZone((string) ($context['time_zone'] ?: 'America/Toronto'));
        $now = ($now ?? new DateTimeImmutable('now', $timeZone))->setTimezone($timeZone);

        return [
            'enabled' => true,
            'date' => $now->format('Y-m-d'),
            'message' => null,
        ];
    }

    /** @return array{time_zone:string} */
    private function sessionContext(int $clubId, int $seasonId, int $sessionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.time_zone
             FROM program_session ps
             INNER JOIN season se
                ON se.id = ps.season_id
               AND se.club_id = :season_club_id
               AND se.active = 1
               AND se.deleted_at IS NULL
             INNER JOIN club c
                ON c.id = ps.club_id
               AND c.active = 1
               AND c.deleted_at IS NULL
             WHERE ps.id = :session_id
               AND ps.season_id = :season_id
               AND ps.club_id = :session_club_id
               AND ps.active = 1
               AND ps.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'season_club_id' => $clubId,
            'session_club_id' => $clubId,
            'season_id' => $seasonId,
            'session_id' => $sessionId,
        ]);
        $context = $statement->fetch();
        if (!$context) {
            throw new InvalidArgumentException('Choose a valid season and session.');
        }
        return $context;
    }
}
