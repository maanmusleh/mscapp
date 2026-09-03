<?php

declare(strict_types=1);

final class DashboardRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function curriculum(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                st.id AS stage_id,
                st.stage_number,
                st.name AS stage_name,
                r.id AS ribbon_id,
                r.name AS ribbon_name,
                c.id AS category_id,
                c.name AS category_name,
                sk.id AS skill_id,
                sk.name AS skill_name,
                sk.skill_code
             FROM canskate_stage st
             INNER JOIN canskate_ribbon r
                ON r.canskate_stage_id = st.id
                AND r.active = 1
                AND r.deleted_at IS NULL
             INNER JOIN canskate_category c
                ON c.canskate_ribbon_id = r.id
                AND c.active = 1
                AND c.deleted_at IS NULL
             INNER JOIN canskate_skill sk
                ON sk.canskate_category_id = c.id
                AND sk.active = 1
                AND sk.deleted_at IS NULL
             WHERE st.active = 1
               AND st.deleted_at IS NULL
             ORDER BY
                st.display_order,
                r.display_order,
                c.display_order,
                sk.display_order'
        );

        $stages = [];
        foreach ($statement->fetchAll() as $row) {
            $stageId = (int) $row['stage_id'];
            $ribbonId = (int) $row['ribbon_id'];

            if (!isset($stages[$stageId])) {
                $stages[$stageId] = [
                    'id' => $stageId,
                    'number' => (int) $row['stage_number'],
                    'name' => $row['stage_name'],
                    'has_badge' => (int) $row['stage_number'] > 0,
                    'skill_count' => 0,
                    'ribbons' => [],
                ];
            }

            if (!isset($stages[$stageId]['ribbons'][$ribbonId])) {
                $stages[$stageId]['ribbons'][$ribbonId] = [
                    'id' => $ribbonId,
                    'name' => $row['ribbon_name'],
                    'category_name' => $row['category_name'],
                    'is_participation_ribbon' => (int) $row['stage_number'] === 0,
                    'skills' => [],
                ];
            }

            $stages[$stageId]['ribbons'][$ribbonId]['skills'][] = [
                'id' => (int) $row['skill_id'],
                'name' => $row['skill_name'],
                'code' => $row['skill_code'],
                'is_participation' => (string) $row['skill_code'] === 'PCS-PARTICIPATION',
            ];
            $stages[$stageId]['skill_count']++;
        }

        foreach ($stages as &$stage) {
            foreach ($stage['ribbons'] as &$ribbon) {
                $ribbon['skill_count'] = !empty($ribbon['is_participation_ribbon'])
                    ? 1
                    : count(array_filter(
                        $ribbon['skills'],
                        static fn (array $skill): bool => empty($skill['is_participation'])
                    ));
                $ribbon['required_count'] = CanSkateRequirements::ribbonRequiredSkillCount(
                    (int) $stage['number'],
                    (string) $ribbon['name'],
                    (int) $ribbon['skill_count']
                );
            }
            unset($ribbon);
            $stage['ribbons'] = array_values($stage['ribbons']);
            $stage['required_ribbon_count'] = count($stage['ribbons']);
        }
        unset($stage);

        return array_values($stages);
    }

    public function genders(): array
    {
        return $this->pdo->query(
            'SELECT id, name
             FROM gender
             WHERE active = 1
             ORDER BY display_order, name'
        )->fetchAll();
    }

    public function skaters(
        int $clubId,
        string $search,
        string $status,
        int $seasonId,
        ?int $sessionId = null,
        ?int $groupId = null,
        int $limit = 10000
    ): array {
        $limit = max(1, min(10000, $limit));
        $where = ['s.club_id = :club_id', 's.deleted_at IS NULL'];
        $parameters = ['club_id' => $clubId, 'report_note_season_id' => $seasonId];

        if ($status === 'active') {
            $where[] = 's.active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 's.active = 0';
        }

        if ($search !== '') {
            $where[] = '(
                s.first_name LIKE :search_first_name
                OR s.last_name LIKE :search_last_name
                OR CONCAT(s.first_name, " ", s.last_name) LIKE :search_full_name
                OR s.skate_canada_number LIKE :search_skate_canada_number
            )';
            $searchPattern = '%' . $search . '%';
            $parameters['search_first_name'] = $searchPattern;
            $parameters['search_last_name'] = $searchPattern;
            $parameters['search_full_name'] = $searchPattern;
            $parameters['search_skate_canada_number'] = $searchPattern;
        }

        if ($groupId !== null) {
            $sessionFilter = '';
            $seasonFilter = '';
            if ($sessionId !== null) {
                $sessionFilter = 'AND e_filter.program_session_id = :session_id';
                $parameters['session_id'] = $sessionId;
            }
            if ($seasonId !== null) {
                $seasonFilter = 'AND se_filter.id = :season_id';
                $parameters['season_id'] = $seasonId;
            }

            $where[] = "EXISTS (
                SELECT 1
                FROM skater_enrollment e_filter
                INNER JOIN program_session ps_filter
                    ON ps_filter.id = e_filter.program_session_id
                    AND ps_filter.club_id = :filter_club_id
                    AND ps_filter.active = 1
                    AND ps_filter.deleted_at IS NULL
                INNER JOIN season se_filter
                    ON se_filter.id = ps_filter.season_id
                    AND se_filter.club_id = :filter_season_club_id
                    AND se_filter.active = 1
                    AND se_filter.deleted_at IS NULL
                INNER JOIN group_assignment ga_filter
                    ON ga_filter.id = (
                        SELECT ga_latest.id
                        FROM group_assignment ga_latest
                        WHERE ga_latest.skater_enrollment_id = e_filter.id
                        ORDER BY ga_latest.id DESC
                        LIMIT 1
                    )
                WHERE e_filter.skater_id = s.id
                  AND e_filter.active = 1
                  AND e_filter.deleted_at IS NULL
                  AND ga_filter.program_group_id = :group_id
                  $sessionFilter
                  $seasonFilter
            )";
            $parameters['filter_club_id'] = $clubId;
            $parameters['filter_season_club_id'] = $clubId;
            $parameters['group_id'] = $groupId;
        } elseif ($sessionId === 0 && $seasonId === 0) {
            $where[] = 'NOT EXISTS (
                SELECT 1
                FROM skater_enrollment e_unregistered_session
                WHERE e_unregistered_session.skater_id = s.id
                  AND e_unregistered_session.active = 1
                  AND e_unregistered_session.deleted_at IS NULL
            )';
        } elseif ($sessionId === 0 && $seasonId > 0) {
            $where[] = 'NOT EXISTS (
                SELECT 1
                FROM skater_enrollment e_unregistered_session
                INNER JOIN program_session ps_unregistered_session
                    ON ps_unregistered_session.id = e_unregistered_session.program_session_id
                    AND ps_unregistered_session.club_id = :unregistered_session_club_id
                    AND ps_unregistered_session.season_id = :unregistered_session_season_id
                    AND ps_unregistered_session.active = 1
                    AND ps_unregistered_session.deleted_at IS NULL
                WHERE e_unregistered_session.skater_id = s.id
                  AND e_unregistered_session.active = 1
                  AND e_unregistered_session.deleted_at IS NULL
            )';
            $parameters['unregistered_session_club_id'] = $clubId;
            $parameters['unregistered_session_season_id'] = $seasonId;
        } elseif ($sessionId !== null) {
            $seasonFilter = '';
            if ($seasonId !== null) {
                $seasonFilter = 'AND se_filter.id = :season_id';
                $parameters['season_id'] = $seasonId;
            }

            $where[] = "EXISTS (
                SELECT 1
                FROM skater_enrollment e_filter
                INNER JOIN program_session ps_filter
                    ON ps_filter.id = e_filter.program_session_id
                    AND ps_filter.club_id = :filter_club_id
                    AND ps_filter.active = 1
                    AND ps_filter.deleted_at IS NULL
                INNER JOIN season se_filter
                    ON se_filter.id = ps_filter.season_id
                    AND se_filter.club_id = :filter_season_club_id
                    AND se_filter.active = 1
                    AND se_filter.deleted_at IS NULL
                WHERE e_filter.skater_id = s.id
                  AND e_filter.program_session_id = :session_id
                  AND e_filter.active = 1
                  AND e_filter.deleted_at IS NULL
                  $seasonFilter
            )";
            $parameters['filter_club_id'] = $clubId;
            $parameters['filter_season_club_id'] = $clubId;
            $parameters['session_id'] = $sessionId;
        } elseif ($seasonId === 0) {
            $where[] = 'NOT EXISTS (
                SELECT 1
                FROM skater_enrollment e_unregistered
                WHERE e_unregistered.skater_id = s.id
                  AND e_unregistered.active = 1
                  AND e_unregistered.deleted_at IS NULL
            )';
        } elseif ($seasonId !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM skater_enrollment e_filter
                INNER JOIN program_session ps_filter
                    ON ps_filter.id = e_filter.program_session_id
                    AND ps_filter.club_id = :filter_club_id
                    AND ps_filter.active = 1
                    AND ps_filter.deleted_at IS NULL
                INNER JOIN season se_filter
                    ON se_filter.id = ps_filter.season_id
                    AND se_filter.club_id = :filter_season_club_id
                    AND se_filter.active = 1
                    AND se_filter.deleted_at IS NULL
                WHERE e_filter.skater_id = s.id
                  AND se_filter.id = :season_id
                  AND e_filter.active = 1
                  AND e_filter.deleted_at IS NULL
            )';
            $parameters['filter_club_id'] = $clubId;
            $parameters['filter_season_club_id'] = $clubId;
            $parameters['season_id'] = $seasonId;
        }

        $sql = 'SELECT
                    s.id,
                    s.public_id,
                    s.skate_canada_number,
                    s.first_name,
                    s.last_name,
                    s.date_of_birth,
                    s.general_notes,
                    report_note.note AS report_card_notes,
                    CONCAT_WS(\' \', report_note_editor.first_name, report_note_editor.last_name) AS report_card_note_updated_by_name,
                    report_note.updated_at AS report_card_note_updated_at,
                    s.medical_notes,
                    s.gender_id,
                    g.name AS gender_name,
                    s.active
                FROM skater s
                LEFT JOIN gender g ON g.id = s.gender_id
                LEFT JOIN skater_season_report_card_note report_note
                    ON report_note.skater_id = s.id AND report_note.season_id = :report_note_season_id
                LEFT JOIN app_user report_note_editor ON report_note_editor.id = report_note.updated_by_user_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY s.last_name, s.first_name
                LIMIT ' . $limit;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $skaters = $statement->fetchAll();

        if ($skaters === []) {
            return [];
        }

        $ids = array_map(static fn (array $skater): int => (int) $skater['id'], $skaters);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $skills = $this->pdo->prepare(
            "SELECT skater_id, canskate_skill_id, achievement_date
             FROM skater_skill
             WHERE skater_id IN ($placeholders)"
        );
        $skills->execute($ids);
        $skillMap = [];
        foreach ($skills->fetchAll() as $row) {
            $skillMap[(int) $row['skater_id']][(int) $row['canskate_skill_id']] = (string) $row['achievement_date'];
        }

        $ribbons = $this->pdo->prepare(
            "SELECT skater_id, canskate_ribbon_id, awarded_at
             FROM skater_ribbon
             WHERE skater_id IN ($placeholders)
               AND revoked_at IS NULL"
        );
        $ribbons->execute($ids);
        $ribbonMap = [];
        foreach ($ribbons->fetchAll() as $row) {
            $ribbonMap[(int) $row['skater_id']][(int) $row['canskate_ribbon_id']] =
                $row['awarded_at'];
        }

        $badges = $this->pdo->prepare(
            "SELECT skater_id, canskate_stage_id, awarded_at
             FROM skater_badge
             WHERE skater_id IN ($placeholders)
               AND revoked_at IS NULL"
        );
        $badges->execute($ids);
        $badgeMap = [];
        foreach ($badges->fetchAll() as $row) {
            $badgeMap[(int) $row['skater_id']][(int) $row['canskate_stage_id']] =
                $row['awarded_at'];
        }

        $attendanceDays = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT pd_attendance.session_date)
             FROM attendance a
             INNER JOIN attendance_status ast
                ON ast.id = a.attendance_status_id
               AND ast.counts_as_present = 1
             INNER JOIN program_date pd_attendance ON pd_attendance.id = a.program_date_id
             INNER JOIN program_session ps_attendance ON ps_attendance.id = pd_attendance.program_session_id
             WHERE ps_attendance.season_id = ?'
        );
        $attendanceDays->execute([$seasonId]);
        $attendanceDayCount = (int) $attendanceDays->fetchColumn();

        $attendance = $this->pdo->prepare(
            "SELECT
                a.skater_id,
                COUNT(DISTINCT CASE WHEN ast.counts_as_present = 1 THEN pd_attendance.session_date END) AS present
             FROM attendance a
             INNER JOIN attendance_status ast
                ON ast.id = a.attendance_status_id
             INNER JOIN program_date pd_attendance
                ON pd_attendance.id = a.program_date_id
             INNER JOIN program_session ps_attendance
                ON ps_attendance.id = pd_attendance.program_session_id
             WHERE a.skater_id IN ($placeholders)
               AND ps_attendance.season_id = ?
             GROUP BY a.skater_id"
        );
        $attendance->execute([...$ids, $seasonId]);
        $attendanceMap = [];
        foreach ($attendance->fetchAll() as $row) {
            $attendanceMap[(int) $row['skater_id']] = [
                'present' => (int) $row['present'],
                'recorded' => $attendanceDayCount,
            ];
        }

        $groups = $this->pdo->prepare(
            "SELECT
                e.skater_id,
                ps.id AS session_id,
                ps.name AS session_name,
                ps.day_of_week,
                ps.start_time,
                ps.end_time,
                ps.location,
                se.id AS season_id,
                se.name AS season_name,
                se.start_date AS season_start_date,
                se.end_date AS season_end_date,
                pg.id AS group_id,
                pg.name AS group_name,
                pg.colour_hex AS group_colour
             FROM skater_enrollment e
             INNER JOIN program_session ps
                ON ps.id = e.program_session_id
                AND ps.deleted_at IS NULL
             INNER JOIN season se
                ON se.id = ps.season_id
                AND se.deleted_at IS NULL
             LEFT JOIN group_assignment ga ON ga.id = (
                SELECT ga2.id
                FROM group_assignment ga2
                WHERE ga2.skater_enrollment_id = e.id
                ORDER BY ga2.id DESC
                LIMIT 1
             )
             LEFT JOIN program_group pg
                ON pg.id = ga.program_group_id
                AND pg.deleted_at IS NULL
             WHERE e.skater_id IN ($placeholders)
               AND e.active = 1
               AND e.deleted_at IS NULL
               AND se.id = ?
             ORDER BY
                e.skater_id,
                ps.day_of_week,
                ps.start_time,
                se.start_date,
                ps.name"
        );
        $groups->execute([...$ids, $seasonId]);
        $groupMap = [];
        foreach ($groups->fetchAll() as $row) {
            $groupMap[(int) $row['skater_id']][] = [
                'session_id' => (int) $row['session_id'],
                'session_name' => $row['session_name'],
                'day_of_week' => (int) $row['day_of_week'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'location' => $row['location'],
                'season_name' => $row['season_name'],
                'season_start_date' => $row['season_start_date'],
                'season_end_date' => $row['season_end_date'],
                'group_id' => $row['group_id'] === null ? null : (int) $row['group_id'],
                'group_name' => $row['group_name'],
                'group_colour' => $row['group_colour'],
            ];
        }

        $filterRegistrations = $this->pdo->prepare(
            "SELECT
                e.skater_id,
                ps.id AS session_id,
                pg.id AS group_id
             FROM skater_enrollment e
             INNER JOIN program_session ps
                ON ps.id = e.program_session_id
                AND ps.deleted_at IS NULL
             INNER JOIN season se
                ON se.id = ps.season_id
                AND se.deleted_at IS NULL
             LEFT JOIN group_assignment ga ON ga.id = (
                SELECT ga2.id
                FROM group_assignment ga2
                WHERE ga2.skater_enrollment_id = e.id
                ORDER BY ga2.id DESC
                LIMIT 1
             )
             LEFT JOIN program_group pg
                ON pg.id = ga.program_group_id
                AND pg.deleted_at IS NULL
             WHERE e.skater_id IN ($placeholders)
               AND e.active = 1
               AND e.deleted_at IS NULL
               AND se.id = ?
             ORDER BY e.skater_id, ps.day_of_week, ps.start_time, ps.name"
        );
        $filterRegistrations->execute([...$ids, $seasonId]);
        $filterRegistrationMap = [];
        foreach ($filterRegistrations->fetchAll() as $row) {
            $filterRegistrationMap[(int) $row['skater_id']][] = [
                'session_id' => (int) $row['session_id'],
                'group_id' => $row['group_id'] === null ? null : (int) $row['group_id'],
            ];
        }

        foreach ($skaters as &$skater) {
            $id = (int) $skater['id'];
            $skater['skills'] = $skillMap[$id] ?? [];
            $skater['ribbons'] = $ribbonMap[$id] ?? [];
            $skater['badges'] = $badgeMap[$id] ?? [];
            $skater['attendance'] = $attendanceMap[$id] ?? [
                'present' => 0,
                'recorded' => $attendanceDayCount,
            ];
            $skater['current_sessions'] = $groupMap[$id] ?? [];
            $skater['filter_registrations'] = $filterRegistrationMap[$id] ?? [];
        }

        return $skaters;
    }

    public function progressFilterOptions(int $clubId): array
    {
        $seasons = $this->pdo->prepare(
            'SELECT
                se.id,
                se.name,
                se.start_date,
                se.end_date,
                se.active
             FROM season se
             WHERE se.club_id = :club_id
               AND se.deleted_at IS NULL
             ORDER BY
                CASE
                    WHEN CURDATE() BETWEEN se.start_date AND se.end_date THEN 0
                    WHEN se.start_date > CURDATE() THEN 1
                    ELSE 2
                END,
                CASE WHEN se.start_date > CURDATE() THEN se.start_date END,
                CASE WHEN se.end_date < CURDATE() THEN se.end_date END DESC,
                se.start_date,
                se.name'
        );
        $seasons->execute(['club_id' => $clubId]);

        $sessions = $this->pdo->prepare(
            'SELECT
                ps.id,
                ps.name,
                ps.day_of_week,
                ps.start_time,
                ps.end_time,
                ps.location,
                (
                    SELECT COUNT(*)
                    FROM skater_enrollment session_enrollment_count
                    INNER JOIN skater session_skater_count
                       ON session_skater_count.id = session_enrollment_count.skater_id
                      AND session_skater_count.active = 1
                      AND session_skater_count.deleted_at IS NULL
                    WHERE session_enrollment_count.program_session_id = ps.id
                      AND session_enrollment_count.active = 1
                      AND session_enrollment_count.deleted_at IS NULL
                ) AS skater_count,
                se.id AS season_id,
                se.name AS season_name,
                se.start_date AS season_start_date,
                se.end_date AS season_end_date
             FROM program_session ps
             INNER JOIN season se
                ON se.id = ps.season_id
                AND se.club_id = :season_club_id
                AND se.active = 1
                AND se.deleted_at IS NULL
             WHERE ps.club_id = :session_club_id
               AND ps.active = 1
               AND ps.deleted_at IS NULL
             ORDER BY ps.day_of_week, ps.start_time, se.start_date, ps.name'
        );
        $sessions->execute([
            'season_club_id' => $clubId,
            'session_club_id' => $clubId,
        ]);

        $groups = $this->pdo->prepare(
            'SELECT
                pg.id,
                pg.name,
                pg.colour_hex,
                (
                    SELECT COUNT(*)
                    FROM skater_enrollment enrollment_count
                    INNER JOIN skater skater_count
                       ON skater_count.id = enrollment_count.skater_id
                      AND skater_count.active = 1
                      AND skater_count.deleted_at IS NULL
                    INNER JOIN group_assignment assignment_count
                       ON assignment_count.id = (
                          SELECT latest_assignment.id
                          FROM group_assignment latest_assignment
                          WHERE latest_assignment.skater_enrollment_id = enrollment_count.id
                          ORDER BY latest_assignment.id DESC
                          LIMIT 1
                       )
                    WHERE enrollment_count.program_session_id = pg.program_session_id
                      AND enrollment_count.active = 1
                      AND enrollment_count.deleted_at IS NULL
                      AND assignment_count.program_group_id = pg.id
                ) AS skater_count,
                pg.program_session_id,
                ps.name AS session_name,
                ps.day_of_week,
                ps.start_time,
                ps.end_time,
                ps.location,
                se.id AS season_id,
                se.name AS season_name
             FROM program_group pg
             INNER JOIN program_session ps
                ON ps.id = pg.program_session_id
                AND ps.club_id = :session_club_id
                AND ps.active = 1
                AND ps.deleted_at IS NULL
             INNER JOIN season se
                ON se.id = ps.season_id
                AND se.club_id = :season_club_id
                AND se.active = 1
                AND se.deleted_at IS NULL
             WHERE pg.active = 1
               AND pg.deleted_at IS NULL
             ORDER BY ps.day_of_week, ps.start_time, se.start_date, ps.name, pg.display_order, pg.name'
        );
        $groups->execute([
            'session_club_id' => $clubId,
            'season_club_id' => $clubId,
        ]);

        return [
            'seasons' => array_map(static function (array $season): array {
                $season['id'] = (int) $season['id'];

                return $season;
            }, $seasons->fetchAll()),
            'sessions' => array_map(static function (array $session): array {
                $session['id'] = (int) $session['id'];
                $session['season_id'] = (int) $session['season_id'];
                $session['day_of_week'] = (int) $session['day_of_week'];
                $session['skater_count'] = (int) $session['skater_count'];

                return $session;
            }, $sessions->fetchAll()),
            'groups' => array_map(static function (array $group): array {
                $group['id'] = (int) $group['id'];
                $group['program_session_id'] = (int) $group['program_session_id'];
                $group['season_id'] = (int) $group['season_id'];
                $group['day_of_week'] = (int) $group['day_of_week'];
                $group['skater_count'] = (int) $group['skater_count'];

                return $group;
            }, $groups->fetchAll()),
        ];
    }

    public function statistics(int $clubId, int $seasonId): array
    {
        $skaterCount = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT s.id)
             FROM skater s
             INNER JOIN skater_enrollment e
                ON e.skater_id = s.id
                AND e.active = 1
                AND e.deleted_at IS NULL
             INNER JOIN program_session ps
                ON ps.id = e.program_session_id
                AND ps.season_id = :season_id
                AND ps.active = 1
                AND ps.deleted_at IS NULL
             WHERE s.club_id = :club_id
               AND s.active = 1
               AND s.deleted_at IS NULL'
        );
        $skaterCount->execute([
            'club_id' => $clubId,
            'season_id' => $seasonId,
        ]);

        $sessionCount = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM program_session ps
             INNER JOIN season se ON se.id = ps.season_id
             WHERE ps.club_id = :club_id
               AND ps.active = 1
               AND ps.deleted_at IS NULL
               AND ps.season_id = :season_id
               AND se.active = 1
               AND se.deleted_at IS NULL
               AND CURDATE() BETWEEN se.start_date AND se.end_date'
        );
        $sessionCount->execute([
            'club_id' => $clubId,
            'season_id' => $seasonId,
        ]);

        $attendance = $this->pdo->prepare(
            'SELECT
                COUNT(a.id) AS recorded,
                COALESCE(SUM(ast.counts_as_present), 0) AS present
             FROM attendance a
             INNER JOIN attendance_status ast ON ast.id = a.attendance_status_id
             INNER JOIN skater s ON s.id = a.skater_id
             INNER JOIN program_date pd ON pd.id = a.program_date_id
             INNER JOIN program_session ps ON ps.id = pd.program_session_id
             WHERE s.club_id = :club_id
               AND ps.season_id = :season_id'
        );
        $attendance->execute([
            'club_id' => $clubId,
            'season_id' => $seasonId,
        ]);
        $attendanceRow = $attendance->fetch() ?: ['recorded' => 0, 'present' => 0];
        $recorded = (int) $attendanceRow['recorded'];

        return [
            'active_skaters' => (int) $skaterCount->fetchColumn(),
            'active_sessions' => (int) $sessionCount->fetchColumn(),
            'attendance_rate' => $recorded > 0
                ? (int) round(((int) $attendanceRow['present'] / $recorded) * 100)
                : null,
            'attendance_records' => $recorded,
        ];
    }

    public function skaterDetail(int $clubId, string $publicId): ?array
    {
        $skaterStatement = $this->pdo->prepare(
            'SELECT
                s.public_id,
                s.skate_canada_number,
                s.first_name,
                s.last_name,
                s.date_of_birth,
                s.parent_guardian_name,
                s.parent_guardian_email,
                s.parent_guardian_phone,
                s.general_notes,
                s.medical_notes,
                s.active,
                s.gender_id,
                g.name AS gender_name,
                s.created_at,
                s.updated_at
             FROM skater s
             LEFT JOIN gender g ON g.id = s.gender_id
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
               AND s.deleted_at IS NULL
             LIMIT 1'
        );
        $skaterStatement->execute([
            'club_id' => $clubId,
            'public_id' => $publicId,
        ]);
        $skater = $skaterStatement->fetch();

        if (!$skater) {
            return null;
        }

        $genders = $this->pdo->query(
            'SELECT id, name
             FROM gender
             WHERE active = 1
             ORDER BY display_order, name'
        )->fetchAll();

        $enrollmentStatement = $this->pdo->prepare(
            'SELECT
                e.public_id,
                e.program_session_id AS session_id,
                e.registration_date,
                e.active,
                ps.name AS session_name,
                ps.day_of_week,
                ps.start_time,
                ps.end_time,
                ps.location,
                se.id AS season_id,
                se.name AS season_name,
                pg.id AS group_id,
                pg.name AS group_name,
                pg.colour_hex AS group_colour
             FROM skater_enrollment e
             INNER JOIN skater s ON s.id = e.skater_id
             INNER JOIN program_session ps ON ps.id = e.program_session_id
             INNER JOIN season se ON se.id = ps.season_id
             LEFT JOIN group_assignment ga ON ga.id = (
                SELECT ga2.id
                FROM group_assignment ga2
                WHERE ga2.skater_enrollment_id = e.id
                ORDER BY ga2.id DESC
                LIMIT 1
             )
             LEFT JOIN program_group pg ON pg.id = ga.program_group_id
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
               AND e.deleted_at IS NULL
             ORDER BY se.start_date DESC, ps.day_of_week, ps.start_time'
        );
        $enrollmentStatement->execute([
            'club_id' => $clubId,
            'public_id' => $publicId,
        ]);

        $sessionOptionsStatement = $this->pdo->prepare(
            'SELECT
                ps.id AS session_id,
                ps.name AS session_name,
                ps.day_of_week,
                ps.start_time,
                ps.end_time,
                se.name AS season_name,
                pg.id AS group_id,
                pg.name AS group_name,
                pg.colour_hex AS group_colour
             FROM program_session ps
             INNER JOIN season se ON se.id = ps.season_id
             LEFT JOIN program_group pg
                ON pg.program_session_id = ps.id
               AND pg.active = 1
               AND pg.deleted_at IS NULL
             WHERE ps.club_id = :club_id
               AND ps.active = 1
               AND ps.deleted_at IS NULL
             ORDER BY se.start_date DESC, ps.day_of_week, ps.start_time, pg.display_order, pg.name'
        );
        $sessionOptionsStatement->execute(['club_id' => $clubId]);
        $registrationOptions = [];
        foreach ($sessionOptionsStatement->fetchAll() as $row) {
            $sessionId = (int) $row['session_id'];
            if (!isset($registrationOptions[$sessionId])) {
                $registrationOptions[$sessionId] = [
                    'session_id' => $sessionId,
                    'session_name' => $row['session_name'],
                    'season_name' => $row['season_name'],
                    'day_of_week' => (int) $row['day_of_week'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'groups' => [],
                ];
            }
            if ($row['group_id'] !== null) {
                $registrationOptions[$sessionId]['groups'][] = [
                    'group_id' => (int) $row['group_id'],
                    'group_name' => $row['group_name'],
                    'group_colour' => $row['group_colour'],
                ];
            }
        }

        $attendanceStatement = $this->pdo->prepare(
            'SELECT
                ps.name AS session_name,
                (
                    SELECT COUNT(DISTINCT pd_all.session_date)
                    FROM attendance a_all
                    INNER JOIN attendance_status ast_all
                       ON ast_all.id = a_all.attendance_status_id
                      AND ast_all.counts_as_present = 1
                    INNER JOIN program_date pd_all ON pd_all.id = a_all.program_date_id
                    WHERE pd_all.program_session_id = ps.id
                ) AS recorded,
                COUNT(DISTINCT CASE WHEN ast.counts_as_present = 1 THEN pd.session_date END) AS present
             FROM attendance a
             INNER JOIN attendance_status ast ON ast.id = a.attendance_status_id
             INNER JOIN skater s ON s.id = a.skater_id
             INNER JOIN program_date pd ON pd.id = a.program_date_id
             INNER JOIN program_session ps ON ps.id = pd.program_session_id
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
             GROUP BY ps.id, ps.name
             ORDER BY ps.name'
        );
        $attendanceStatement->execute([
            'club_id' => $clubId,
            'public_id' => $publicId,
        ]);

        $recentAttendanceStatement = $this->pdo->prepare(
            'SELECT
                pd.session_date,
                ps.name AS session_name,
                ast.name AS status_name,
                ast.code AS status_code,
                a.notes
             FROM attendance a
             INNER JOIN attendance_status ast ON ast.id = a.attendance_status_id
             INNER JOIN skater s ON s.id = a.skater_id
             INNER JOIN program_date pd ON pd.id = a.program_date_id
             INNER JOIN program_session ps ON ps.id = pd.program_session_id
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
             ORDER BY pd.session_date DESC
             LIMIT 12'
        );
        $recentAttendanceStatement->execute([
            'club_id' => $clubId,
            'public_id' => $publicId,
        ]);

        $assessmentStatement = $this->pdo->prepare(
            'SELECT history.*
             FROM (
                SELECT
                    ah.id AS event_id,
                    "skill" AS history_type,
                    ah.assessed_at,
                    ar.code AS result_code,
                    ar.name AS result_name,
                    ah.notes,
                    cs.name AS skill_name,
                    pd.session_date,
                    ps.name AS session_name,
                    CONCAT(c.first_name, " ", c.last_name) AS coach_name,
                    COALESCE(u.username, "System") AS entered_by_username
                FROM assessment_history ah
                INNER JOIN skater s ON s.id = ah.skater_id
                INNER JOIN assessment_result ar ON ar.id = ah.assessment_result_id
                INNER JOIN canskate_skill cs ON cs.id = ah.canskate_skill_id
                INNER JOIN canskate_category cat ON cat.id = cs.canskate_category_id
                INNER JOIN canskate_ribbon skill_ribbon ON skill_ribbon.id = cat.canskate_ribbon_id
                INNER JOIN canskate_stage skill_stage
                    ON skill_stage.id = skill_ribbon.canskate_stage_id
                   AND skill_stage.active = 1
                   AND skill_stage.deleted_at IS NULL
                LEFT JOIN program_date pd ON pd.id = ah.program_date_id
                LEFT JOIN program_session ps ON ps.id = pd.program_session_id
                LEFT JOIN coach_assignment ca ON ca.id = ah.coach_assignment_id
                LEFT JOIN coach_session_assignment csa ON csa.id = ca.coach_session_assignment_id
                LEFT JOIN coach c ON c.id = csa.coach_id
                LEFT JOIN app_user u ON u.id = ah.created_by_user_id
                WHERE s.club_id = :club_id_skill
                  AND s.public_id = :public_id_skill

                UNION ALL

                SELECT
                    sr.id,
                    "ribbon",
                    sr.awarded_at,
                    "AWARDED",
                    "Ribbon awarded",
                    sr.notes,
                    CONCAT(st.name, " · ", r.name, " ribbon"),
                    NULL,
                    NULL,
                    NULL,
                    COALESCE(u.username, "System")
                FROM skater_ribbon sr
                INNER JOIN skater s ON s.id = sr.skater_id
                INNER JOIN canskate_ribbon r ON r.id = sr.canskate_ribbon_id
                INNER JOIN canskate_stage st
                    ON st.id = r.canskate_stage_id
                   AND st.active = 1
                   AND st.deleted_at IS NULL
                LEFT JOIN app_user u ON u.id = sr.created_by_user_id
                WHERE s.club_id = :club_id_ribbon_award
                  AND s.public_id = :public_id_ribbon_award

                UNION ALL

                SELECT
                    sr.id,
                    "ribbon",
                    sr.revoked_at,
                    "REVOKED",
                    "Ribbon award removed",
                    "Ribbon award removed from the skater record.",
                    CONCAT(st.name, " · ", r.name, " ribbon"),
                    NULL,
                    NULL,
                    NULL,
                    COALESCE(u.username, "System")
                FROM skater_ribbon sr
                INNER JOIN skater s ON s.id = sr.skater_id
                INNER JOIN canskate_ribbon r ON r.id = sr.canskate_ribbon_id
                INNER JOIN canskate_stage st
                    ON st.id = r.canskate_stage_id
                   AND st.active = 1
                   AND st.deleted_at IS NULL
                LEFT JOIN app_user u ON u.id = sr.revoked_by_user_id
                WHERE s.club_id = :club_id_ribbon_revoke
                  AND s.public_id = :public_id_ribbon_revoke
                  AND sr.revoked_at IS NOT NULL

                UNION ALL

                SELECT
                    sb.id,
                    "badge",
                    sb.awarded_at,
                    "AWARDED",
                    "Stage badge awarded",
                    sb.notes,
                    CONCAT(st.name, " badge"),
                    NULL,
                    NULL,
                    NULL,
                    COALESCE(u.username, "System")
                FROM skater_badge sb
                INNER JOIN skater s ON s.id = sb.skater_id
                INNER JOIN canskate_stage st
                    ON st.id = sb.canskate_stage_id
                   AND st.active = 1
                   AND st.deleted_at IS NULL
                LEFT JOIN app_user u ON u.id = sb.created_by_user_id
                WHERE s.club_id = :club_id_badge_award
                  AND s.public_id = :public_id_badge_award

                UNION ALL

                SELECT
                    sb.id,
                    "badge",
                    sb.revoked_at,
                    "REVOKED",
                    "Stage badge award removed",
                    "Stage badge award removed from the skater record.",
                    CONCAT(st.name, " badge"),
                    NULL,
                    NULL,
                    NULL,
                    COALESCE(u.username, "System")
                FROM skater_badge sb
                INNER JOIN skater s ON s.id = sb.skater_id
                INNER JOIN canskate_stage st
                    ON st.id = sb.canskate_stage_id
                   AND st.active = 1
                   AND st.deleted_at IS NULL
                LEFT JOIN app_user u ON u.id = sb.revoked_by_user_id
                WHERE s.club_id = :club_id_badge_revoke
                  AND s.public_id = :public_id_badge_revoke
                  AND sb.revoked_at IS NOT NULL

                UNION ALL

                SELECT
                    sae.id,
                    "record",
                    sae.event_at,
                    sae.event_type,
                    CASE sae.event_type
                        WHEN "ADDED" THEN "Skater added"
                        WHEN "DELETED" THEN "Skater deleted"
                        ELSE "Skater record changed"
                    END,
                    sae.details,
                    "Skater record",
                    NULL,
                    NULL,
                    NULL,
                    COALESCE(u.username, "System")
                FROM skater_audit_event sae
                INNER JOIN skater s ON s.id = sae.skater_id
                LEFT JOIN app_user u ON u.id = sae.created_by_user_id
                WHERE s.club_id = :club_id_record
                  AND s.public_id = :public_id_record
             ) history
             ORDER BY history.assessed_at DESC, history.event_id DESC
             LIMIT 50'
        );
        $assessmentStatement->execute([
            'club_id_skill' => $clubId,
            'public_id_skill' => $publicId,
            'club_id_ribbon_award' => $clubId,
            'public_id_ribbon_award' => $publicId,
            'club_id_ribbon_revoke' => $clubId,
            'public_id_ribbon_revoke' => $publicId,
            'club_id_badge_award' => $clubId,
            'public_id_badge_award' => $publicId,
            'club_id_badge_revoke' => $clubId,
            'public_id_badge_revoke' => $publicId,
            'club_id_record' => $clubId,
            'public_id_record' => $publicId,
        ]);

        $achievedSkillsStatement = $this->pdo->prepare(
            'SELECT ss.canskate_skill_id, ss.achievement_date
             FROM skater_skill ss
             INNER JOIN skater s ON s.id = ss.skater_id
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
               AND s.deleted_at IS NULL'
        );
        $achievedSkillsStatement->execute([
            'club_id' => $clubId,
            'public_id' => $publicId,
        ]);
        $achievedSkills = [];
        foreach ($achievedSkillsStatement->fetchAll() as $row) {
            $achievedSkills[(int) $row['canskate_skill_id']] = $row['achievement_date'];
        }

        $ribbonAwardsStatement = $this->pdo->prepare(
            'SELECT sr.canskate_ribbon_id, DATE_FORMAT(sr.awarded_at, "%Y-%m-%dT%H:%i:%sZ") AS awarded_at
             FROM skater_ribbon sr
             INNER JOIN skater s ON s.id = sr.skater_id
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
               AND s.deleted_at IS NULL
               AND sr.revoked_at IS NULL'
        );
        $ribbonAwardsStatement->execute([
            'club_id' => $clubId,
            'public_id' => $publicId,
        ]);
        $ribbonAwards = array_column(
            $ribbonAwardsStatement->fetchAll(),
            'awarded_at',
            'canskate_ribbon_id'
        );

        $badgeAwardsStatement = $this->pdo->prepare(
            'SELECT sb.canskate_stage_id, DATE_FORMAT(sb.awarded_at, "%Y-%m-%dT%H:%i:%sZ") AS awarded_at
             FROM skater_badge sb
             INNER JOIN skater s ON s.id = sb.skater_id
             WHERE s.club_id = :club_id
               AND s.public_id = :public_id
               AND s.deleted_at IS NULL
               AND sb.revoked_at IS NULL'
        );
        $badgeAwardsStatement->execute([
            'club_id' => $clubId,
            'public_id' => $publicId,
        ]);
        $badgeAwards = array_column(
            $badgeAwardsStatement->fetchAll(),
            'awarded_at',
            'canskate_stage_id'
        );

        $achievementEditor = $this->curriculum();
        foreach ($achievementEditor as &$stage) {
            $stageAchieved = 0;
            foreach ($stage['ribbons'] as &$ribbon) {
                $ribbonAchieved = 0;
                foreach ($ribbon['skills'] as &$skill) {
                    $skill['achieved'] = array_key_exists((int) $skill['id'], $achievedSkills);
                    $skill['achievement_date'] = $achievedSkills[(int) $skill['id']] ?? null;
                    if ($skill['achieved'] && (
                        !empty($ribbon['is_participation_ribbon'])
                            ? !empty($skill['is_participation'])
                            : empty($skill['is_participation'])
                    )) {
                        $ribbonAchieved++;
                        $stageAchieved++;
                    }
                }
                unset($skill);
                $ribbon['achieved_count'] = $ribbonAchieved;
                $ribbon['eligible'] = (int) $ribbon['required_count'] > 0
                    && $ribbonAchieved >= (int) $ribbon['required_count'];
                $ribbon['awarded_at'] = $ribbonAwards[$ribbon['id']] ?? null;
            }
            unset($ribbon);
            $stage['achieved_count'] = $stageAchieved;
            $stage['eligible'] = (int) $stage['required_ribbon_count'] > 0
                && count(array_filter(
                    $stage['ribbons'],
                    static fn (array $ribbon): bool => $ribbon['awarded_at'] !== null
                )) >= (int) $stage['required_ribbon_count'];
            $stage['badge_awarded_at'] = $badgeAwards[$stage['id']] ?? null;
        }
        unset($stage);

        $attendance = [];
        foreach ($attendanceStatement->fetchAll() as $row) {
            $recorded = (int) $row['recorded'];
            $row['rate'] = $recorded > 0
                ? (int) round(((int) $row['present'] / $recorded) * 100)
                : null;
            $attendance[] = $row;
        }

        return [
            'skater' => $skater,
            'genders' => $genders,
            'enrollments' => $enrollmentStatement->fetchAll(),
            'registration_options' => array_values($registrationOptions),
            'attendance' => $attendance,
            'recent_attendance' => $recentAttendanceStatement->fetchAll(),
            'achievement_editor' => $achievementEditor,
            'assessments' => $assessmentStatement->fetchAll(),
        ];
    }
}
