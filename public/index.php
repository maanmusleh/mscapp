<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

if (!config('installed', false)) {
    $installer = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    header('Location: ' . ($installer === '' ? '' : $installer) . '/install.php', true, 302);
    exit;
}

$requestPath = isset($_GET['route']) && is_string($_GET['route'])
    ? '/' . trim($_GET['route'], '/')
    : (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$basePath = (string) config('base_path', '');
if ($basePath !== '' && string_starts_with($requestPath, $basePath)) {
    $requestPath = substr($requestPath, strlen($basePath)) ?: '/';
}
$requestPath = '/' . trim($requestPath, '/');
if ($requestPath === '//') {
    $requestPath = '/';
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (Auth::check() && !empty($_SESSION['totp_enrollment_required'])
    && !string_starts_with($requestPath, '/account') && $requestPath !== '/logout') {
    redirect('account');
}

try {
    if ($method === 'GET' && ($requestPath === '/' || $requestPath === '/login')) {
        if (Auth::check()) {
            redirect(authenticated_landing_path(Auth::user()));
        }

        render('login', [
            'error' => null,
            'flashes' => consume_flashes(),
            'old' => ['identity' => ''],
            'totpRequired' => Auth::totpPending(),
        ]);
    }

    if ($method === 'POST' && $requestPath === '/login') {
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            render('login', [
                'error' => 'Your session expired. Refresh the page and try again.',
                'flashes' => [],
                'old' => [
                    'identity' => (string) ($_POST['identity'] ?? ''),
                ],
            ]);
        }

        $identity = trim((string) ($_POST['identity'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($identity === '' || $password === '') {
            render('login', [
                'error' => 'Email address or user ID, and password are required.',
                'flashes' => [],
                'old' => ['identity' => $identity],
            ]);
        }

        if (!Auth::attempt($identity, $password)) {
            render('login', [
                'error' => Auth::takeLoginFailureMessage(),
                'flashes' => [],
                'old' => ['identity' => $identity],
            ]);
        }

        if (Auth::totpPending()) {
            render('login', [
                'error' => null,
                'flashes' => [],
                'old' => ['identity' => $identity],
                'totpRequired' => true,
            ]);
        }

        redirect(authenticated_landing_path(Auth::user()));
    }

    if ($method === 'POST' && $requestPath === '/login/totp') {
        if (!csrf_is_valid($_POST['_token'] ?? null) || !Auth::totpPending()) {
            Auth::cancelTotp();
            redirect('login');
        }
        if (!Auth::completeTotp((string) ($_POST['code'] ?? ''))) {
            render('login', [
                'error' => 'The authenticator code was not recognized. Try the current code and check your device time.',
                'flashes' => [],
                'old' => ['identity' => ''],
                'totpRequired' => true,
            ]);
        }
        redirect(authenticated_landing_path(Auth::user()));
    }

    if ($method === 'POST' && $requestPath === '/login/totp/cancel') {
        Auth::cancelTotp();
        redirect('login');
    }

    if ($method === 'POST' && $requestPath === '/logout') {
        Auth::requireLogin();
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            http_response_code(419);
            exit('Session expired.');
        }
        Auth::logout();
        redirect('login');
    }

    if (
        Auth::check()
        && (bool) (Auth::user()['must_change_password'] ?? false)
        && !in_array($requestPath, ['/account/password', '/logout'], true)
    ) {
        redirect('account/password');
    }

    if ($method === 'GET' && $requestPath === '/users') {
        Auth::requireRole(['ADMINISTRATOR']);
        redirect('admin-tools#users');
    }

    if ($method === 'POST' && $requestPath === '/users') {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            $_SESSION['user_management_flash'] = ['type' => 'error', 'message' => 'The user form expired. Please try again.'];
            redirect('admin-tools#users');
        }
        try {
            (new UserService(Database::connection()))->create((int) $user['id'], $_POST);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new InvalidArgumentException('A user with that email address already exists.');
            }
            throw $exception;
        }
        $_SESSION['user_management_flash'] = ['type' => 'success', 'message' => 'User added. They will be asked to choose a new password when they first sign in.'];
        redirect('admin-tools#users');
    }

    if ($method === 'POST' && preg_match('#^/users/([0-9a-fA-F-]{36})/reset-password$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            $_SESSION['user_management_flash'] = ['type' => 'error', 'message' => 'The password-reset form expired. Please try again.'];
            redirect('admin-tools#users');
        }
        (new UserService(Database::connection()))->resetPassword(
            (int) $user['id'],
            $matches[1],
            $_POST
        );
        $_SESSION['user_management_flash'] = ['type' => 'success', 'message' => 'Temporary password set and any password login lock cleared. The user will be required to choose a new password at sign-in.'];
        redirect('admin-tools#users');
    }

    if ($method === 'POST' && preg_match('#^/users/([0-9a-fA-F-]{36})/reset-2fa$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The reset request expired. Please try again.');
            redirect('admin-tools#users');
        }
        (new UserService(Database::connection()))->resetTotp((int) $user['id'], $matches[1]);
        $_SESSION['user_management_flash'] = ['type' => 'success', 'message' => 'Two-factor authentication has been reset. The user can sign in with their password and set it up again.'];
        redirect('admin-tools#users');
    }

    if ($method === 'POST' && preg_match('#^/users/([0-9a-fA-F-]{36})/clear-password-lock$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            $_SESSION['user_management_flash'] = ['type' => 'error', 'message' => 'The password-lock request expired. Please try again.'];
            redirect('admin-tools#users');
        }
        (new UserService(Database::connection()))->clearPasswordLock((int) $user['id'], $matches[1]);
        $_SESSION['user_management_flash'] = ['type' => 'success', 'message' => 'Password login lock cleared.'];
        redirect('admin-tools#users');
    }

    if ($method === 'POST' && preg_match('#^/users/([0-9a-fA-F-]{36})/delete$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The delete request expired. Please try again.');
            redirect('admin-tools#users');
        }
        if (strcasecmp(trim((string) ($_POST['confirmation'] ?? '')), 'delete') !== 0) {
            throw new InvalidArgumentException('Type delete to confirm this action.');
        }
        if (strcasecmp((string) $user['public_id'], $matches[1]) === 0) {
            throw new InvalidArgumentException('You cannot delete your own account.');
        }
        (new UserService(Database::connection()))->delete((int) $user['id'], $matches[1]);
        $_SESSION['user_management_flash'] = ['type' => 'success', 'message' => 'User deleted.'];
        redirect('admin-tools#users');
    }

    if ($method === 'POST' && preg_match('#^/users/([0-9a-fA-F-]{36})/(suspend|resume)$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The account-access request expired. Please try again.');
            redirect('admin-tools#users');
        }
        if (strcasecmp((string) $user['public_id'], $matches[1]) === 0) {
            throw new InvalidArgumentException('You cannot pause your own account.');
        }
        $active = $matches[2] === 'resume';
        (new UserService(Database::connection()))->setActive((int) $user['id'], $matches[1], $active);
        $_SESSION['user_management_flash'] = ['type' => 'success', 'message' => $active ? 'User access resumed.' : 'User access paused.'];
        redirect('admin-tools#users');
    }

    if ($method === 'GET' && $requestPath === '/admin-tools') {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        $adminTools = new AdminToolsService(Database::connection());
        $policyStatement = Database::connection()->prepare('SELECT totp_policy FROM club WHERE id = :club_id');
        $policyStatement->execute(['club_id' => $user['club_id']]);
        $totpPolicy = $policyStatement->fetchColumn() ?: 'OPTIONAL';
        $clubSettingsFlash = $_SESSION['club_settings_flash'] ?? null;
        $groupColoursFlash = $_SESSION['group_colours_flash'] ?? null;
        $userManagementFlash = $_SESSION['user_management_flash'] ?? null;
        $totpPolicyFlash = $_SESSION['totp_policy_flash'] ?? null;
        $databaseManagementFlash = $_SESSION['database_management_flash'] ?? null;
        $stageSettingsFlash = $_SESSION['stage_settings_flash'] ?? null;
        $activityPage = filter_var($_GET['activity_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $activityPage = $activityPage === false ? 1 : $activityPage;
        $loginActivityPage = (new LoginActivityService())->page((int) $activityPage, 25);
        unset($_SESSION['group_colours_flash']);
        unset($_SESSION['user_management_flash']);
        unset($_SESSION['totp_policy_flash']);
        unset($_SESSION['club_settings_flash']);
        unset($_SESSION['database_management_flash']);
        unset($_SESSION['stage_settings_flash']);
        render('admin-tools', [
            'user' => $user,
            'groupColours' => $adminTools->groupColours((int) $user['club_id']),
            'clubSettings' => $adminTools->clubSettings((int) $user['club_id']),
            'clubTimeZones' => DateTimeZone::listIdentifiers(),
            'databaseSummary' => $adminTools->databaseSummary((int) $user['club_id']),
            'stages' => $adminTools->stages(),
            'users' => (new UserService(Database::connection()))->users(),
            'loginActivity' => $loginActivityPage['entries'],
            'loginActivityPage' => $loginActivityPage,
            'flashes' => consume_flashes(),
            'groupColoursFlash' => is_array($groupColoursFlash) ? $groupColoursFlash : null,
            'userManagementFlash' => is_array($userManagementFlash) ? $userManagementFlash : null,
            'totpPolicy' => $totpPolicy,
            'clubSettingsFlash' => is_array($clubSettingsFlash) ? $clubSettingsFlash : null,
            'totpPolicyFlash' => is_array($totpPolicyFlash) ? $totpPolicyFlash : null,
            'databaseManagementFlash' => is_array($databaseManagementFlash) ? $databaseManagementFlash : null,
            'stageSettingsFlash' => is_array($stageSettingsFlash) ? $stageSettingsFlash : null,
        ]);
    }

    if ($method === 'POST' && $requestPath === '/admin-tools/club-settings') {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            $_SESSION['club_settings_flash'] = ['type' => 'error', 'message' => 'The club settings form expired. Please try again.'];
            redirect('admin-tools#club-settings');
        }
        (new AdminToolsService(Database::connection()))->updateClubSettings((int) $user['club_id'], (int) $user['id'], $_POST);
        $_SESSION['club_settings_flash'] = ['type' => 'success', 'message' => 'Club settings updated.'];
        redirect('admin-tools#club-settings');
    }

    if ($method === 'POST' && $requestPath === '/admin-tools/stages') {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            $_SESSION['stage_settings_flash'] = ['type' => 'error', 'message' => 'The stage settings form expired. Please try again.'];
            redirect('admin-tools#stage-settings');
        }
        (new AdminToolsService(Database::connection()))->updateStageSettings((int) $user['id'], $_POST);
        $_SESSION['stage_settings_flash'] = ['type' => 'success', 'message' => 'Enabled stages updated.'];
        redirect('admin-tools#stage-settings');
    }

    if ($method === 'POST' && $requestPath === '/admin-tools/totp-policy') {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            $_SESSION['totp_policy_flash'] = ['type' => 'error', 'message' => 'The two-factor policy form expired. Please try again.'];
            redirect('admin-tools');
        }
        $policy = strtoupper(trim((string) ($_POST['totp_policy'] ?? '')));
        if (!in_array($policy, ['UNAVAILABLE', 'OPTIONAL', 'REQUIRED'], true)) {
            throw new InvalidArgumentException('Choose a valid two-factor authentication policy.');
        }
        $statement = Database::connection()->prepare('UPDATE club SET totp_policy = :policy WHERE id = :club_id');
        $statement->execute(['policy' => $policy, 'club_id' => $user['club_id']]);
        $_SESSION['totp_policy_flash'] = ['type' => 'success', 'message' => 'Two-factor authentication policy updated.'];
        redirect('admin-tools');
    }

    if ($method === 'GET' && $requestPath === '/reports') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY']);
        $canExportSkaterAchievements = in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR'], true);
        render('reports', [
            'user' => $user,
            'flashes' => consume_flashes(),
            'canExportSkaterAchievements' => $canExportSkaterAchievements,
            'achievementExportOptions' => $canExportSkaterAchievements
                ? (new AchievementExportService(Database::connection()))->filterOptions((int) $user['club_id'])
                : ['seasons' => [], 'sessions' => [], 'groups' => [], 'ages' => [], 'badges' => []],
        ]);
    }

    if ($method === 'POST' && $requestPath === '/reports/skater-achievements/export') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            throw new InvalidArgumentException('The export form expired. Refresh the page and try again.');
        }
        $export = (new AchievementExportService(Database::connection()))->export(
            (int) $user['club_id'],
            $_POST,
            (string) ($_POST['format'] ?? '')
        );
        $path = (string) $export['path'];
        try {
            header('Content-Type: ' . $export['mime']);
            header('Content-Disposition: attachment; filename="' . basename((string) $export['filename']) . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('Cache-Control: private, no-store, max-age=0');
            readfile($path);
        } finally {
            @unlink($path);
        }
        exit;
    }

    if ($method === 'POST' && $requestPath === '/reports/sessions/export') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            throw new InvalidArgumentException('The export form expired. Refresh the page and try again.');
        }
        $export = (new AchievementExportService(Database::connection()))->exportSessionReport(
            (int) $user['club_id'],
            (string) ($_POST['season_filter'] ?? 'all'),
            (string) ($_POST['format'] ?? '')
        );
        $path = (string) $export['path'];
        try {
            header('Content-Type: ' . $export['mime']);
            header('Content-Disposition: attachment; filename="' . basename((string) $export['filename']) . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('Cache-Control: private, no-store, max-age=0');
            readfile($path);
        } finally {
            @unlink($path);
        }
        exit;
    }

    if ($method === 'GET' && $requestPath === '/legacy-achievement-import/template') {
        Auth::requireRole(['ADMINISTRATOR']);
        $path = dirname(__DIR__) . '/assets/canskate-historical-import-template.xlsx';
        if (!is_file($path)) {
            throw new RuntimeException('The historical import template is not available on this installation.');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="canskate-historical-import-template.xlsx"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        readfile($path);
        exit;
    }

    if ($method === 'GET' && $requestPath === '/legacy-achievement-import') {
        if (!Auth::check()) {
            $_SESSION['post_login_destination'] = 'legacy-achievement-import';
        }
        $user = Auth::requireRole(['ADMINISTRATOR']);
        $result = $_SESSION['legacy_achievement_import_result'] ?? null;
        unset($_SESSION['legacy_achievement_import_result']);
        $service = new LegacyAchievementImportService(Database::connection());
        render('legacy-achievement-import', [
            'user' => $user,
            'result' => is_array($result) ? $result : null,
            'seasons' => $service->seasons((int) $user['club_id']),
            'selectedSeasonId' => is_array($result) ? (int) ($result['season_id'] ?? 0) : 0,
            'flashes' => consume_flashes(),
        ]);
    }

    if ($method === 'POST' && $requestPath === '/legacy-achievement-import') {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            throw new InvalidArgumentException('The import form expired. Please choose the workbook again.');
        }
        $_SESSION['legacy_achievement_import_result'] = (
            new LegacyAchievementImportService(Database::connection())
        )->import(
            (int) $user['club_id'],
            (int) $user['id'],
            (int) ($_POST['season_id'] ?? 0),
            $_FILES['legacy_workbook'] ?? []
        );
        redirect('legacy-achievement-import');
    }

    if ($method === 'GET' && $requestPath === '/rink-app') {
        if (!Auth::check()) {
            $_SESSION['post_login_destination'] = 'rink-app';
        }
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY', 'COACH']);
        $repository = new DashboardRepository(Database::connection());
        $rinkService = new RinkService(Database::connection());
        $filterOptions = $rinkService->filterOptionsForUser(
            $user,
            $repository->progressFilterOptions((int) $user['club_id'])
        );
        $validSeasonIds = array_column($filterOptions['seasons'], 'id');
        $requestedSeasonId = filter_var($_GET['season_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $seasonId = is_int($requestedSeasonId) && in_array($requestedSeasonId, $validSeasonIds, true)
            ? $requestedSeasonId
            : ($validSeasonIds[0] ?? null);
        $seasonSessions = array_values(array_filter(
            $filterOptions['sessions'],
            static fn (array $session): bool => $session['season_id'] === $seasonId
        ));
        $validSessionIds = array_column($seasonSessions, 'id');
        $requestedSessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $sessionId = is_int($requestedSessionId) && in_array($requestedSessionId, $validSessionIds, true)
            ? $requestedSessionId
            : null;
        $sessionGroups = array_values(array_filter(
            $filterOptions['groups'],
            static fn (array $group): bool => $group['program_session_id'] === $sessionId
        ));
        $validGroupIds = array_column($sessionGroups, 'id');
        $requestedGroupId = filter_var($_GET['group_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $groupId = is_int($requestedGroupId) && in_array($requestedGroupId, $validGroupIds, true)
            ? $requestedGroupId
            : null;
        $activity = strtolower(trim((string) ($_GET['activity'] ?? 'roster')));
        if (!in_array($activity, ['roster', 'skills', 'chat'], true)) {
            $activity = 'roster';
        }
        if ($sessionId !== null) {
            $rinkService->assertUserSessionAccess($user, (int) $seasonId, $sessionId);
        }
        $includeSensitive = ($user['role_code'] ?? '') !== 'READ_ONLY';
        $rosterPayload = $sessionId !== null && $activity === 'roster'
            ? $rinkService->roster(
                (int) $user['club_id'],
                (int) $seasonId,
                $sessionId,
                $groupId,
                $includeSensitive
            )
            : [
                'skaters' => [],
                'attendance' => [
                    'enabled' => false,
                    'date' => date('Y-m-d'),
                    'message' => null,
                    'records' => [],
                ],
            ];
        $assessPayload = $sessionId !== null && $activity === 'skills'
            ? $rinkService->assess(
                (int) $user['club_id'],
                (int) $seasonId,
                $sessionId,
                $groupId,
                $includeSensitive
            )
            : [
                'stages' => [],
                'skaters' => [],
            ];
        $chatPayload = $sessionId !== null && $activity === 'chat'
            ? $rinkService->chat((int) $user['club_id'], (int) $user['id'], (int) $seasonId, $sessionId, true)
            : ['messages' => [], 'unread_count' => 0];
        $chatUnreadCount = $sessionId !== null && $activity !== 'chat'
            ? $rinkService->chatUnreadCount((int) $user['club_id'], (int) $user['id'], $sessionId)
            : 0;

        render('rink-app', [
            'user' => $user,
            'rosterPayload' => $rosterPayload,
            'assessPayload' => $assessPayload,
            'chatPayload' => $chatPayload,
            'chatUnreadCount' => $chatUnreadCount,
            'filterOptions' => $filterOptions,
            'seasonSessions' => $seasonSessions,
            'sessionGroups' => $sessionGroups,
            'groupColours' => (new AdminToolsService(Database::connection()))->groupColours(
                (int) $user['club_id']
            ),
            'activity' => $activity,
            'filters' => [
                'season_id' => $seasonId,
                'session_id' => $sessionId,
                'group_id' => $groupId,
            ],
            'flashes' => consume_flashes(),
        ]);
    }

    if ($method === 'GET' && $requestPath === '/api/rink/status') {
        Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY', 'COACH']);
        $pdo = Database::connection();
        $pdo->query('SELECT 1')->fetchColumn();
        json_response(['connected' => true]);
    }

    if ($method === 'GET' && $requestPath === '/api/rink/roster') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY', 'COACH']);
        $seasonId = filter_var($_GET['season_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $groupId = filter_var($_GET['group_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($seasonId) || !is_int($sessionId)) {
            throw new InvalidArgumentException('Choose a valid season and session.');
        }
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId);
        json_response($rink->roster(
            (int) $user['club_id'],
            $seasonId,
            $sessionId,
            is_int($groupId) ? $groupId : null,
            ($user['role_code'] ?? '') !== 'READ_ONLY'
        ));
    }

    if ($method === 'GET' && $requestPath === '/api/rink/assess') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY', 'COACH']);
        $seasonId = filter_var($_GET['season_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $groupId = filter_var($_GET['group_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($seasonId) || !is_int($sessionId)) {
            throw new InvalidArgumentException('Choose a valid season and session.');
        }
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId);
        json_response($rink->assess(
            (int) $user['club_id'],
            $seasonId,
            $sessionId,
            is_int($groupId) ? $groupId : null,
            ($user['role_code'] ?? '') !== 'READ_ONLY'
        ));
    }

    if ($method === 'GET' && $requestPath === '/api/rink/report-card-template') {
        Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY', 'COACH']);
        $templatePath = dirname(__DIR__) . '/assets/CanSkate-progress-report-template.pdf';
        if (!is_file($templatePath) || !is_readable($templatePath)) {
            throw new RuntimeException('The report-card template is unavailable.');
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($templatePath));
        header('Cache-Control: private, no-store, max-age=0');
        readfile($templatePath);
        exit;
    }

    if ($method === 'GET' && $requestPath === '/api/rink/chat') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY', 'COACH']);
        $seasonId = filter_var($_GET['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($seasonId) || !is_int($sessionId)) throw new InvalidArgumentException('Choose a valid season and session.');
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId);
        json_response($rink->chat((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, true));
    }
    if ($method === 'GET' && $requestPath === '/api/rink/chat/status') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY', 'COACH']);
        $seasonId = filter_var($_GET['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($seasonId) || !is_int($sessionId)) throw new InvalidArgumentException('Choose a valid season and session.');
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId);
        json_response(['unread_count' => $rink->chatUnreadCount((int) $user['club_id'], (int) $user['id'], $sessionId)]);
    }
    if ($method === 'POST' && $requestPath === '/api/rink/chat') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']); $input = request_json(); $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($seasonId) || !is_int($sessionId)) throw new InvalidArgumentException('Choose a valid season and session.');
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId);
        $rink->postChat((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, (string) ($input['message'] ?? ''));
        json_response(['message' => 'Message posted.']);
    }
    if ($method === 'POST' && preg_match('#^/api/rink/chat/([0-9a-fA-F-]{36})/edit$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']); $input = request_json(); $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($seasonId) || !is_int($sessionId)) throw new InvalidArgumentException('Choose a valid season and session.');
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId);
        $rink->editChat((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, $matches[1], (string) ($input['message'] ?? ''));
        json_response(['message' => 'Message updated.']);
    }
    if ($method === 'POST' && preg_match('#^/api/rink/chat/([0-9a-fA-F-]{36})/delete$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']); $input = request_json(); $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($seasonId) || !is_int($sessionId)) throw new InvalidArgumentException('Choose a valid season and session.');
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId);
        $rink->deleteChat((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, $matches[1]);
        json_response(['message' => 'Message deleted.']);
    }
    if ($method === 'POST' && preg_match('#^/api/rink/chat/([0-9a-fA-F-]{36})/expiry$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']); $input = request_json(); $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($seasonId) || !is_int($sessionId)) throw new InvalidArgumentException('Choose a valid season and session.');
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId);
        $rink->updateChatExpiry((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, $matches[1], (string) ($input['expires_at'] ?? ''));
        json_response(['message' => 'Message deletion time updated.']);
    }

    if ($method === 'GET' && preg_match(
        '#^/api/rink/skaters/([0-9a-fA-F-]{36})$#',
        $requestPath,
        $matches
    )) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY', 'COACH']);
        $seasonId = filter_var($_GET['season_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $sessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($seasonId) || !is_int($sessionId)) {
            throw new InvalidArgumentException('Choose a season and session.');
        }
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess(
            $user,
            $seasonId,
            $sessionId,
            $matches[1]
        );
        $detail = (new DashboardRepository(Database::connection()))->skaterDetail(
            (int) $user['club_id'],
            $matches[1]
        );
        if ($detail === null) {
            json_response(['error' => 'Skater not found.'], 404);
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
        json_response($detail);
    }

    if ($method === 'POST' && $requestPath === '/api/rink/group-assignment') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $groupId = $input['group_id'] ?? null;
        if ($groupId !== null) {
            $groupId = filter_var($groupId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        }
        $groupName = isset($input['group_name']) ? trim((string) $input['group_name']) : null;
        $groupColour = isset($input['group_colour'])
            ? strtoupper(trim((string) $input['group_colour']))
            : null;
        if ($groupName === '') {
            $groupName = null;
        }
        if ($groupColour === '') {
            $groupColour = null;
        }
        if (
            !is_int($seasonId)
            || !is_int($sessionId)
            || ($groupId !== null && !is_int($groupId))
            || ($groupId !== null && $groupName !== null)
            || ($groupName !== null && (
                mb_strlen($groupName) > 100
                || $groupColour === null
                || preg_match('/^#[0-9A-F]{6}$/D', $groupColour) !== 1
            ))
            || ($groupName === null && $groupColour !== null)
        ) {
            throw new InvalidArgumentException('Choose a valid season, session, and group.');
        }
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId, (string) ($input['skater_id'] ?? ''));
        $result = $rink->assignGroup(
            (int) $user['club_id'],
            (int) $user['id'],
            $seasonId,
            $sessionId,
            (string) ($input['skater_id'] ?? ''),
            $groupId,
            $groupName,
            $groupColour
        );
        json_response(['message' => 'Group assignment updated.', 'result' => $result]);
    }

    if ($method === 'POST' && $requestPath === '/api/rink/attendance') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($seasonId) || !is_int($sessionId)) {
            throw new InvalidArgumentException('Choose a valid season and session.');
        }
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId, (string) ($input['skater_id'] ?? ''));
        $result = $rink->recordAttendance(
            (int) $user['club_id'],
            (int) $user['id'],
            $seasonId,
            $sessionId,
            (string) ($input['skater_id'] ?? ''),
            filter_var($input['present'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );
        json_response([
            'message' => $result['present'] ? 'Marked present.' : 'Marked absent.',
            'result' => $result,
        ]);
    }

    if ($method === 'POST' && $requestPath === '/api/rink/report-card-notes') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $skaterPublicId = (string) ($input['skater_id'] ?? '');
        if (!is_int($seasonId) || !is_int($sessionId) || preg_match('/^[0-9a-fA-F-]{36}$/', $skaterPublicId) !== 1) {
            throw new InvalidArgumentException('Choose a valid season, session, and skater.');
        }
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId, $skaterPublicId);
        $result = $rink->saveReportCardNote(
            (int) $user['club_id'],
            (int) $user['id'],
            $seasonId,
            $sessionId,
            $skaterPublicId,
            (string) ($input['note'] ?? '')
        );
        json_response(['message' => 'Report-card note saved.'] + $result);
    }

    if ($method === 'DELETE' && $requestPath === '/api/rink/report-card-notes') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $skaterPublicId = (string) ($input['skater_id'] ?? '');
        if (!is_int($seasonId) || !is_int($sessionId) || preg_match('/^[0-9a-fA-F-]{36}$/', $skaterPublicId) !== 1) {
            throw new InvalidArgumentException('Choose a valid season, session, and skater.');
        }
        $rink = new RinkService(Database::connection());
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId, $skaterPublicId);
        $rink->deleteReportCardNote(
            (int) $user['club_id'],
            (int) $user['id'],
            $seasonId,
            $sessionId,
            $skaterPublicId
        );
        json_response(['message' => 'Report-card note deleted.']);
    }

    if ($method === 'GET' && $requestPath === '/api/rink/report-card-note-library') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']);
        json_response(['notes' => (new RinkService(Database::connection()))->reportCardNoteLibrary((int) $user['id'])]);
    }

    if ($method === 'POST' && $requestPath === '/api/rink/report-card-note-library') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        $note = (new RinkService(Database::connection()))->createReportCardLibraryNote(
            (int) $user['id'],
            (string) ($input['title'] ?? ''),
            (string) ($input['content'] ?? '')
        );
        json_response(['message' => 'Note added to your library.', 'note' => $note], 201);
    }

    if ($method === 'PUT' && preg_match('#^/api/rink/report-card-note-library/([1-9][0-9]*)$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        $note = (new RinkService(Database::connection()))->updateReportCardLibraryNote(
            (int) $user['id'],
            (int) $matches[1],
            (string) ($input['title'] ?? ''),
            (string) ($input['content'] ?? '')
        );
        json_response(['message' => 'Library note updated.', 'note' => $note]);
    }

    if ($method === 'DELETE' && preg_match('#^/api/rink/report-card-note-library/([1-9][0-9]*)$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        (new RinkService(Database::connection()))->deleteReportCardLibraryNote((int) $user['id'], (int) $matches[1]);
        json_response(['message' => 'Library note deleted.']);
    }

    if (in_array($method, ['POST', 'DELETE'], true) && preg_match(
        '#^/api/rink/skaters/([0-9a-fA-F-]{36})/(skills|ribbons|badges)/([1-9][0-9]*)$#',
        $requestPath,
        $matches
    )) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'COACH']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($seasonId) || !is_int($sessionId)) {
            throw new InvalidArgumentException('Choose a valid season and session.');
        }
        $rink = new RinkService(Database::connection());
        $publicId = $matches[1];
        $targetId = (int) $matches[3];
        $rink->assertUserSessionAccess($user, $seasonId, $sessionId, $publicId);
        if ($matches[2] === 'skills') {
            $method === 'POST'
                ? $rink->markSkill((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, $publicId, $targetId)
                : $rink->removeSkill((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, $publicId, $targetId);
        } elseif ($matches[2] === 'ribbons') {
            $method === 'POST'
                ? $rink->awardRibbon((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, $publicId, $targetId)
                : $rink->removeRibbon((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, $publicId, $targetId);
        } else {
            $method === 'POST'
                ? $rink->awardBadge((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, $publicId, $targetId)
                : $rink->removeBadge((int) $user['club_id'], (int) $user['id'], $seasonId, $sessionId, $publicId, $targetId);
        }
        json_response(['message' => 'Achievements updated.']);
    }

    if ($method === 'GET' && $requestPath === '/sessions') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $scheduleAdmin = new ScheduleAdminService(Database::connection());
        $selectedSeasonId = filter_var($_GET['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $selectedSeasonId = $selectedSeasonId === false ? null : $selectedSeasonId;
        render('sessions', [
            'user' => $user,
            'selectedSeasonId' => $selectedSeasonId,
            'scheduleData' => $scheduleAdmin->data((int) $user['club_id'], $selectedSeasonId),
            'flashes' => consume_flashes(),
        ]);
    }

    if ($method === 'POST' && preg_match('#^/sessions/(seasons|sessions)$#', $requestPath, $matches)) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The form expired. Please try again.');
            redirect('sessions');
        }
        $scheduleAdmin = new ScheduleAdminService(Database::connection());
        $resource = $matches[1];
        $intent = (string) ($_POST['intent'] ?? '');
        if ($resource === 'seasons') {
            $intent === 'remove' ? $scheduleAdmin->removeSeason((int) $user['club_id'], (int) $user['id'], $_POST['id'] ?? null) : $scheduleAdmin->saveSeason((int) $user['club_id'], (int) $user['id'], $_POST);
        } else {
            $intent === 'remove' ? $scheduleAdmin->removeSession((int) $user['club_id'], (int) $user['id'], $_POST['id'] ?? null) : $scheduleAdmin->saveSession((int) $user['club_id'], (int) $user['id'], $_POST);
        }
        flash('success', ucfirst(rtrim($resource, 's')) . ' saved.');
        $seasonId = filter_var($_POST['season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        redirect($resource === 'sessions' && $seasonId !== false ? 'sessions?season_id=' . $seasonId : 'sessions');
    }

    if ($method === 'POST' && $requestPath === '/sessions/rinks') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $returnSeasonId = filter_var($_POST['return_season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $returnPath = $returnSeasonId === false ? 'sessions' : 'sessions?season_id=' . $returnSeasonId;
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The form expired. Please try again.');
            redirect($returnPath);
        }
        $scheduleAdmin = new ScheduleAdminService(Database::connection());
        $intent = (string) ($_POST['intent'] ?? '');
        if ($intent === 'remove') {
            $scheduleAdmin->removeRink((int) $user['club_id'], (int) $user['id'], $_POST['original_name'] ?? '');
        } else {
            $scheduleAdmin->saveRink((int) $user['club_id'], (int) $user['id'], $_POST);
        }
        flash('success', 'Rink saved.');
        redirect($returnPath);
    }

    if ($method === 'POST' && $requestPath === '/admin-tools/group-colours') {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            $_SESSION['group_colours_flash'] = ['type' => 'error', 'message' => 'The group-colour form expired. Please try again.'];
            redirect('admin-tools#group-colours');
        }
        $adminTools = new AdminToolsService(Database::connection());
        $intent = (string) ($_POST['intent'] ?? '');
        if ($intent === 'add') {
            $adminTools->addGroupColour((int) $user['club_id'], (int) $user['id'], $_POST);
            $_SESSION['group_colours_flash'] = ['type' => 'success', 'message' => 'Group colour added.'];
        } elseif ($intent === 'update') {
            $adminTools->updateGroupColour((int) $user['club_id'], (int) $user['id'], $_POST);
            $_SESSION['group_colours_flash'] = ['type' => 'success', 'message' => 'Group colour and existing session assignments updated.'];
        } elseif ($intent === 'remove') {
            $adminTools->removeGroupColour((int) $user['club_id'], (int) $user['id'], $_POST);
            $_SESSION['group_colours_flash'] = ['type' => 'success', 'message' => 'Group colour removed.'];
        } else {
            throw new InvalidArgumentException('Choose a valid group-colour action.');
        }
        redirect('admin-tools#group-colours');
    }

    if ($method === 'POST' && $requestPath === '/admin-tools/backup') {
        Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            $_SESSION['database_management_flash'] = ['type' => 'error', 'message' => 'The backup request expired. Please try again.'];
            redirect('admin-tools#database-management');
        }
        $backup = (new AdminToolsService(Database::connection()))->createBackup();
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
        header('Content-Length: ' . (string) filesize($backup['path']));
        readfile($backup['path']);
        @unlink($backup['path']);
        exit;
    }

    if ($method === 'POST' && $requestPath === '/admin-tools/restore') {
        $user = Auth::requireRole(['ADMINISTRATOR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            $_SESSION['database_management_flash'] = ['type' => 'error', 'message' => 'The restore form expired. Please try again.'];
            redirect('admin-tools#database-management');
        }
        if (strtolower(trim((string) ($_POST['confirmation'] ?? ''))) !== 'restore') {
            throw new InvalidArgumentException('Type restore to confirm replacing the live database.');
        }
        (new AdminToolsService(Database::connection()))->restoreBackup($_FILES['backup_file'] ?? []);
        $_SESSION['database_management_flash'] = ['type' => 'success', 'message' => 'Database restore completed. Sign in again if your account was changed by the backup.'];
        redirect('admin-tools#database-management');
    }

    if ($method === 'GET' && $requestPath === '/api/account/report-card-signature') {
        $user = Auth::requireLogin();
        $signature = (new UserService(Database::connection()))->reportCardSignaturePng((int) $user['id']);
        if ($signature === null) {
            http_response_code(204);
            header('Cache-Control: private, no-store, max-age=0');
            exit;
        }
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($signature));
        header('Cache-Control: private, no-store, max-age=0');
        echo $signature;
        exit;
    }

    if ($method === 'GET' && ($requestPath === '/account' || $requestPath === '/account/password')) {
        $user = Auth::requireLogin();
        $coachNavigator = null;
        if (($user['role_code'] ?? '') === 'COACH' && $requestPath === '/account') {
            $coachFilterOptions = (new RinkService(Database::connection()))->filterOptionsForUser(
                $user,
                (new DashboardRepository(Database::connection()))->progressFilterOptions((int) $user['club_id'])
            );
            $validCoachSeasonIds = array_column($coachFilterOptions['seasons'], 'id');
            $requestedCoachSeasonId = filter_var($_GET['season_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $coachSeasonId = is_int($requestedCoachSeasonId) && in_array($requestedCoachSeasonId, $validCoachSeasonIds, true)
                ? $requestedCoachSeasonId
                : ($validCoachSeasonIds[0] ?? null);
            $validCoachSessionIds = array_column(array_values(array_filter(
                $coachFilterOptions['sessions'],
                static fn (array $session): bool => $session['season_id'] === $coachSeasonId
            )), 'id');
            $requestedCoachSessionId = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $coachSessionId = is_int($requestedCoachSessionId) && in_array($requestedCoachSessionId, $validCoachSessionIds, true)
                ? $requestedCoachSessionId
                : null;
            $coachNavigator = [
                'seasons' => $coachFilterOptions['seasons'],
                'sessions' => $coachFilterOptions['sessions'],
                'season_id' => $coachSeasonId,
                'session_id' => $coachSessionId,
            ];
        }
        $signatureMetadata = $requestPath === '/account'
            ? (new UserService(Database::connection()))->reportCardSignatureMetadata((int) $user['id'])
            : ['has_signature' => false, 'width' => null, 'height' => null, 'updated_at' => null];
        render('account', [
            'user' => $user,
            'flashes' => consume_flashes(),
            'passwordOnly' => $requestPath === '/account/password',
            'coachNavigator' => $coachNavigator,
            'signatureMetadata' => $signatureMetadata,
        ]);
    }

    if ($method === 'POST' && $requestPath === '/account/profile') {
        $user = Auth::requireLogin();
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The profile form expired. Please try again.');
            redirect('account');
        }
        try {
            (new UserService(Database::connection()))->updateOwnProfile($user, $_POST);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new InvalidArgumentException('That email address is already used by another account in this club.');
            }
            throw $exception;
        }
        flash('success', 'Your profile has been updated.');
        redirect('account');
    }

    if ($method === 'POST' && $requestPath === '/account/report-card-signature') {
        $user = Auth::requireLogin();
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The signature upload form expired. Please try again.');
            redirect('account#report-card-signature');
        }
        (new UserService(Database::connection()))->saveReportCardSignature(
            $user,
            is_array($_FILES['signature_file'] ?? null) ? $_FILES['signature_file'] : []
        );
        flash('success', 'Your report-card signature has been saved.');
        redirect('account#report-card-signature');
    }

    if ($method === 'POST' && $requestPath === '/account/report-card-signature/delete') {
        $user = Auth::requireLogin();
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The signature removal request expired. Please try again.');
            redirect('account#report-card-signature');
        }
        (new UserService(Database::connection()))->deleteReportCardSignature($user);
        flash('success', 'Your report-card signature has been removed.');
        redirect('account#report-card-signature');
    }

    if ($method === 'POST' && $requestPath === '/account/password') {
        $user = Auth::requireLogin();
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The password form expired. Please try again.');
            redirect('account/password');
        }
        (new UserService(Database::connection()))->changeOwnPassword($user, $_POST);
        flash('success', 'Your password has been changed.');
        redirect('account');
    }

    if ($method === 'POST' && $requestPath === '/account/totp/setup') {
        $user = Auth::requireLogin();
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The two-factor setup request expired. Please try again.');
            redirect('account');
        }
        $policyStatement = Database::connection()->prepare('SELECT totp_policy FROM club WHERE id = :club_id');
        $policyStatement->execute(['club_id' => $user['club_id']]);
        if ($policyStatement->fetchColumn() === 'UNAVAILABLE') {
            throw new InvalidArgumentException('Two-factor authentication is currently unavailable.');
        }
        $_SESSION['totp_setup_secret'] = (new TotpService())->createSecret();
        redirect('account');
    }

    if ($method === 'POST' && $requestPath === '/account/totp/confirm') {
        $user = Auth::requireLogin();
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The two-factor setup request expired. Please try again.');
            redirect('account');
        }
        $secret = $_SESSION['totp_setup_secret'] ?? null;
        if (!is_string($secret) || !(new TotpService())->verify($secret, (string) ($_POST['code'] ?? ''))) {
            throw new InvalidArgumentException('Enter the current six-digit code from your authenticator app.');
        }
        (new UserService(Database::connection()))->enableTotp($user, $secret);
        unset($_SESSION['totp_setup_secret']);
        unset($_SESSION['totp_enrollment_required']);
        flash('success', 'Two-factor authentication is now enabled.');
        redirect('account');
    }

    if ($method === 'POST' && $requestPath === '/account/totp/cancel-setup') {
        Auth::requireLogin();
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The two-factor setup request expired. Please try again.');
            redirect('account');
        }
        unset($_SESSION['totp_setup_secret']);
        redirect('account');
    }

    if ($method === 'POST' && $requestPath === '/account/totp/disable') {
        $user = Auth::requireLogin();
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The two-factor request expired. Please try again.');
            redirect('account');
        }
        (new UserService(Database::connection()))->disableTotp($user, (string) ($_POST['code'] ?? ''));
        flash('success', 'Two-factor authentication has been disabled.');
        redirect('account');
    }

    if ($method === 'GET' && $requestPath === '/dashboard') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY']);

        $repository = new DashboardRepository(Database::connection());
        $stages = $repository->curriculum();
        $groupColours = (new AdminToolsService(Database::connection()))->groupColours(
            (int) $user['club_id']
        );
        $filterOptions = $repository->progressFilterOptions((int) $user['club_id']);
        $requestedSeasonValue = (string) ($_GET['season_id'] ?? '');
        $showUnregistered = $requestedSeasonValue === 'unregistered';
        $requestedSeasonId = filter_var($requestedSeasonValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: null;
        $validSeasonIds = array_column($filterOptions['seasons'], 'id');
        $seasonId = $showUnregistered
            ? 0
            : ($requestedSeasonId !== null
            && in_array($requestedSeasonId, $validSeasonIds, true)
                ? $requestedSeasonId
                : ($validSeasonIds[0] ?? -1));
        $requestedSessionValue = (string) ($_GET['session_id'] ?? '');
        $showUnregisteredSession = $requestedSessionValue === 'unregistered';
        $sessionId = filter_var($requestedSessionValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: null;
        if ($showUnregisteredSession) {
            $sessionId = 0;
        }
        $groupId = filter_var($_GET['group_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: null;
        $validSessionIds = array_column(array_filter(
            $filterOptions['sessions'],
            static fn (array $session): bool => $session['season_id'] === $seasonId
        ), 'id');
        $validGroupIds = array_column(array_filter(
            $filterOptions['groups'],
            static fn (array $group): bool => $group['season_id'] === $seasonId
        ), 'id');
        if (!$showUnregisteredSession && $sessionId !== null && !in_array($sessionId, $validSessionIds, true)) {
            $sessionId = null;
        }
        if ($groupId !== null && !in_array($groupId, $validGroupIds, true)) {
            $groupId = null;
        }
        $selectedGroupIds = [];
        if ($groupId !== null) {
            $selectedGroup = current(array_filter(
                $filterOptions['groups'],
                static fn (array $group): bool => $group['id'] === $groupId
            ));
            if ($selectedGroup !== false) {
                $selectedGroupName = mb_strtolower(trim((string) $selectedGroup['name']));
                $selectedGroupIds = array_map(
                    static fn (array $group): int => $group['id'],
                    array_filter(
                        $filterOptions['groups'],
                        static fn (array $group): bool => $group['season_id'] === $seasonId
                            && mb_strtolower(trim((string) $group['name'])) === $selectedGroupName
                    )
                );
            }
        }
        if ($showUnregisteredSession) {
            $groupId = null;
            $selectedGroupIds = [];
        }
        $search = trim((string) ($_GET['search'] ?? ''));
        $search = mb_substr($search, 0, 100);
        $requestedAge = filter_var($_GET['age'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 150],
        ]);
        $age = is_int($requestedAge) ? $requestedAge : null;
        $requestedHighestBadge = filter_var(
            $_GET['highest_badge'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );
        $validHighestBadges = array_merge(
            [0],
            array_map(static fn (array $stage): int => (int) $stage['number'], $stages)
        );
        $highestBadge = is_int($requestedHighestBadge)
            && in_array($requestedHighestBadge, $validHighestBadges, true)
                ? $requestedHighestBadge
                : null;

        $pageSizes = [10, 50, 100, 250, 1000];
        $pageSize = filter_var($_GET['page_size'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $pageSize = is_int($pageSize) && in_array($pageSize, $pageSizes, true)
            ? $pageSize
            : 100;
        $page = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $page = is_int($page) ? $page : 1;

        $skatersByPublicId = [];
        $dashboardSeasons = $showUnregistered
            ? [['id' => 0]]
            : $filterOptions['seasons'];
        foreach ($dashboardSeasons as $season) {
            $availableSeasonId = (int) $season['id'];

            $seasonSessionFilter = $showUnregisteredSession && $availableSeasonId === $seasonId
                ? 0
                : null;
            foreach ($repository->skaters(
                (int) $user['club_id'],
                '',
                'all',
                $availableSeasonId,
                $seasonSessionFilter,
                null,
                10000
            ) as $seasonSkater) {
                $publicId = (string) $seasonSkater['public_id'];
                if (!isset($skatersByPublicId[$publicId])) {
                    $seasonSkater['season_views'] = [];
                    $skatersByPublicId[$publicId] = $seasonSkater;
                }

                $skatersByPublicId[$publicId]['season_views'][$availableSeasonId] = [
                    'attendance' => $seasonSkater['attendance'],
                    'current_sessions' => $seasonSkater['current_sessions'],
                    'filter_registrations' => $seasonSkater['filter_registrations'],
                ];
            }
        }

        $skaters = array_values($skatersByPublicId);
        usort($skaters, static function (array $left, array $right): int {
            return strcasecmp(
                $left['last_name'] . "\0" . $left['first_name'],
                $right['last_name'] . "\0" . $right['first_name']
            );
        });

        $today = new DateTimeImmutable('today');
        $calculateAge = static function (?string $dateOfBirth) use ($today): ?int {
            if ($dateOfBirth === null || $dateOfBirth === '') {
                return null;
            }

            $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
            if ($birthDate === false || $birthDate > $today) {
                return null;
            }

            return $birthDate->diff($today)->y;
        };
        $availableAgeMap = [];
        $shownSkaterCount = 0;
        foreach ($skaters as &$skater) {
            $seasonView = $skater['season_views'][$seasonId] ?? null;
            $matchesFilters = $seasonView !== null;

            if ($matchesFilters && $showUnregistered && !$showUnregisteredSession) {
                // The virtual Unassigned season is only meaningful with its
                // explicit Unregistered session filter.
                $matchesFilters = false;
            } elseif ($matchesFilters && $showUnregisteredSession) {
                $matchesFilters = $seasonView['filter_registrations'] === [];
            } elseif ($matchesFilters && $sessionId !== null) {
                $matchesFilters = false;
                foreach ($seasonView['filter_registrations'] as $session) {
                    if ($session['session_id'] === $sessionId) {
                        $matchesFilters = true;
                        break;
                    }
                }
            }
            if ($matchesFilters && $groupId !== null) {
                $matchesFilters = false;
                foreach ($seasonView['filter_registrations'] as $group) {
                    if (
                        in_array($group['group_id'], $selectedGroupIds, true)
                        && ($sessionId === null || $group['session_id'] === $sessionId)
                    ) {
                        $matchesFilters = true;
                        break;
                    }
                }
            }
            if ($matchesFilters && $search !== '') {
                $searchText = (string) $skater['first_name'] . ' '
                    . (string) $skater['last_name'] . ' '
                    . (string) ($skater['skate_canada_number'] ?? '');
                $matchesFilters = mb_stripos($searchText, $search, 0, 'UTF-8') !== false;
            }

            $skaterAge = $calculateAge($skater['date_of_birth'] ?? null);
            $highestBadgeEarned = 0;
            foreach ($stages as $stage) {
                if (isset($skater['badges'][$stage['id']])) {
                    $highestBadgeEarned = max($highestBadgeEarned, (int) $stage['number']);
                }
            }
            $skater['filter_age'] = $skaterAge;
            $skater['highest_badge'] = $highestBadgeEarned;

            if ($matchesFilters && $skaterAge !== null) {
                $availableAgeMap[$skaterAge] = true;
            }
            if ($matchesFilters && $age !== null) {
                $matchesFilters = $skaterAge === $age;
            }
            if ($matchesFilters && $highestBadge !== null) {
                $matchesFilters = $highestBadgeEarned === $highestBadge;
            }

            $skater['initially_visible'] = $matchesFilters;
            if ($matchesFilters) {
                $shownSkaterCount++;
            }
        }
        unset($skater);
        $availableAges = array_map('intval', array_keys($availableAgeMap));
        sort($availableAges, SORT_NUMERIC);

        $totalMatchingSkaters = $shownSkaterCount;
        $pageCount = max(1, (int) ceil($totalMatchingSkaters / $pageSize));
        $page = min($page, $pageCount);
        $skaters = array_values(array_filter(
            $skaters,
            static fn (array $skater): bool => $skater['initially_visible']
        ));
        $focusedSkaterId = strtolower(trim((string) ($_GET['focus_skater'] ?? '')));
        $focusedSkater = null;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $focusedSkaterId) === 1) {
            foreach ($skaters as $skater) {
                if (strtolower((string) $skater['public_id']) === $focusedSkaterId) {
                    $focusedSkater = $skater;
                    break;
                }
            }
        }
        $skaters = array_slice($skaters, ($page - 1) * $pageSize, $pageSize);
        if ($focusedSkater !== null && !in_array($focusedSkater['public_id'], array_column($skaters, 'public_id'), true)) {
            array_unshift($skaters, $focusedSkater);
        }

        render('dashboard', [
            'user' => $user,
            'stages' => $stages,
            'genders' => $repository->genders(),
            'skaters' => $skaters,
            'availableAges' => $availableAges,
            'shownSkaterCount' => $shownSkaterCount,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'page_sizes' => $pageSizes,
                'page_count' => $pageCount,
                'total' => $totalMatchingSkaters,
            ],
            'filterOptions' => $filterOptions,
            'filters' => [
                'season_id' => $seasonId,
                'unregistered' => $showUnregistered,
                'session_id' => $sessionId,
                'unregistered_session' => $showUnregisteredSession,
                'group_id' => $groupId,
                'age' => $age,
                'highest_badge' => $highestBadge,
                'search' => $search,
            ],
            'flashes' => consume_flashes(),
            'groupColours' => $groupColours,
            'canEdit' => in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR', 'COACH'], true),
            'canViewSensitive' => in_array($user['role_code'], ['ADMINISTRATOR', 'REGISTRAR', 'COACH'], true),
        ]);
    }

    if ($method === 'GET' && $requestPath === '/imports/template.csv') {
        Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cat-skater-import-template.csv"');
        echo "Participant First Name,Participant Last Name,Gender,Birthdate,Member Names,Member Email,Member Telephone,Skate Canada Number,Registered Program SKU,Notes\n";
        exit;
    }

    if ($method === 'POST' && $requestPath === '/imports/skaters') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        if (!csrf_is_valid($_POST['_token'] ?? null)) {
            flash('error', 'The import form expired. Please try again.');
            redirect('sessions');
        }

        $seasonId = filter_var($_POST['season_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_int($seasonId)) {
            flash('error', 'Select a season before importing skaters.');
            redirect('sessions');
        }

        $service = new SkaterService(Database::connection());
        $result = $service->import(
            (int) $user['club_id'],
            (int) $user['id'],
            $seasonId,
            $_FILES['skater_csv'] ?? []
        );
        flash(
            'success',
            "Import complete: {$result['created']} added, {$result['updated']} matched, {$result['assigned']} session assignment(s) applied, and {$result['duplicates_skipped']} duplicate record(s) skipped."
        );
        redirect('sessions?season_id=' . $seasonId);
    }

    if ($method === 'POST' && $requestPath === '/api/group-assignments') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }

        $seasonId = filter_var($input['season_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $sessionId = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $groupId = $input['group_id'] ?? null;
        $groupName = $input['group_name'] ?? null;
        $groupColour = $input['group_colour'] ?? null;
        if ($sessionId === false) {
            throw new InvalidArgumentException(
                'Choose a single session before assigning a group.'
            );
        }
        if ($groupId !== null) {
            $groupId = filter_var($groupId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($groupId === false) {
                throw new InvalidArgumentException('Choose a valid group.');
            }
        }
        if ($groupName !== null && !is_string($groupName)) {
            throw new InvalidArgumentException('Choose a valid group colour.');
        }
        if ($groupColour !== null && !is_string($groupColour)) {
            throw new InvalidArgumentException('Choose a valid group colour.');
        }
        if ($seasonId === false) {
            throw new InvalidArgumentException('Choose a valid season.');
        }

        $service = new SkaterService(Database::connection());
        $result = $service->assignSkatersToGroup(
            (int) $user['club_id'],
            (int) $user['id'],
            is_array($input['skater_ids'] ?? null) ? $input['skater_ids'] : [],
            (int) $seasonId,
            (int) $sessionId,
            $groupId === null ? null : (int) $groupId,
            $groupName,
            $groupColour
        );
        json_response([
            'message' => $result['skaters'] === 1
                ? 'Group assignment updated for 1 skater.'
                : "Group assignments updated for {$result['skaters']} skaters.",
            'result' => $result,
        ]);
    }

    if ($method === 'POST' && preg_match(
        '#^/api/skaters/([0-9a-fA-F-]{36})/skills/([1-9][0-9]*)$#',
        $requestPath,
        $matches
    )) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }

        $service = new SkaterService(Database::connection());
        $result = $service->markSkillAchieved(
            (int) $user['club_id'],
            (int) $user['id'],
            $matches[1],
            (int) $matches[2]
        );
        json_response([
            'message' => 'Skill marked achieved.',
            'automatically_completed_skill_ids' => $result['automatically_completed_skill_ids'],
        ]);
    }

    if ($method === 'DELETE' && preg_match(
        '#^/api/skaters/([0-9a-fA-F-]{36})/skills/([1-9][0-9]*)$#',
        $requestPath,
        $matches
    )) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }

        $service = new SkaterService(Database::connection());
        $service->removeSkillAchievement(
            (int) $user['club_id'],
            (int) $user['id'],
            $matches[1],
            (int) $matches[2]
        );
        json_response(['message' => 'Achievement removed and recorded in history.']);
    }

    if (in_array($method, ['POST', 'DELETE'], true) && preg_match(
        '#^/api/skaters/([0-9a-fA-F-]{36})/ribbons/([1-9][0-9]*)$#',
        $requestPath,
        $matches
    )) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }

        $service = new SkaterService(Database::connection());
        if ($method === 'POST') {
            $overrideIneligible = filter_var(
                $input['override_ineligible'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            $award = $service->awardRibbon(
                (int) $user['club_id'],
                (int) $user['id'],
                $matches[1],
                (int) $matches[2],
                $overrideIneligible
            );
            json_response([
                'message' => $overrideIneligible
                    ? 'Ribbon awarded and its remaining skills marked achieved.'
                    : 'Ribbon marked awarded.',
                'awarded_at' => $award['awarded_at'],
                'completed_skill_ids' => $award['completed_skill_ids'],
            ]);
        }

        $correctedInput = $service->revokeRibbonAward(
            (int) $user['club_id'],
            (int) $user['id'],
            $matches[1],
            (int) $matches[2]
        );
        json_response([
            'message' => $correctedInput
                ? 'Ribbon award correction removed without retaining history.'
                : 'Ribbon award removed and retained in history.',
        ]);
    }

    if (in_array($method, ['POST', 'DELETE'], true) && preg_match(
        '#^/api/skaters/([0-9a-fA-F-]{36})/badges/([1-9][0-9]*)$#',
        $requestPath,
        $matches
    )) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }

        $service = new SkaterService(Database::connection());
        if ($method === 'POST') {
            $overrideIneligible = filter_var(
                $input['override_ineligible'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            $award = $service->awardStageBadge(
                (int) $user['club_id'],
                (int) $user['id'],
                $matches[1],
                (int) $matches[2],
                $overrideIneligible
            );
            json_response([
                'message' => $overrideIneligible
                    ? 'Badge awarded and its remaining skills marked achieved.'
                    : 'Stage badge marked awarded.',
                'awarded_at' => $award['awarded_at'],
                'completed_skill_ids' => $award['completed_skill_ids'],
            ]);
        }

        $correctedInput = $service->revokeStageBadge(
            (int) $user['club_id'],
            (int) $user['id'],
            $matches[1],
            (int) $matches[2]
        );
        json_response([
            'message' => $correctedInput
                ? 'Stage badge award correction removed without retaining history.'
                : 'Stage badge award removed and retained in history.',
        ]);
    }

    if ($method === 'POST' && $requestPath === '/api/skaters') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }

        $publicId = (new SkaterService(Database::connection()))->create(
            (int) $user['club_id'],
            (int) $user['id'],
            $input
        );
        json_response(['message' => 'Skater added.', 'public_id' => $publicId], 201);
    }

    if ($method === 'DELETE' && $requestPath === '/api/skaters') {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        if (strcasecmp(trim((string) ($input['confirmation'] ?? '')), 'delete') !== 0) {
            throw new InvalidArgumentException('Type delete to confirm this action.');
        }

        $deleted = (new SkaterService(Database::connection()))->delete(
            (int) $user['club_id'],
            (int) $user['id'],
            is_array($input['skater_ids'] ?? null) ? $input['skater_ids'] : []
        );
        json_response([
            'message' => $deleted === 1 ? 'Skater deleted.' : "{$deleted} skaters deleted.",
            'deleted' => $deleted,
        ]);
    }

    if ($method === 'GET' && preg_match(
        '#^/api/skaters/([0-9a-fA-F-]{36})$#',
        $requestPath,
        $matches
    )) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR', 'READ_ONLY']);
        $repository = new DashboardRepository(Database::connection());
        $detail = $repository->skaterDetail((int) $user['club_id'], $matches[1]);

        if ($detail === null) {
            json_response(['error' => 'Skater not found.'], 404);
        }

        $canViewSensitive = in_array(
            $user['role_code'],
            ['ADMINISTRATOR', 'REGISTRAR', 'COACH'],
            true
        );
        if (!$canViewSensitive) {
            $detail['skater']['parent_guardian_name'] = null;
            $detail['skater']['parent_guardian_email'] = null;
            $detail['skater']['parent_guardian_phone'] = null;
            $detail['skater']['general_notes'] = null;
            $detail['skater']['medical_notes'] = null;
        }

        $detail['permissions'] = [
            'can_edit' => $canViewSensitive,
            'can_view_sensitive' => $canViewSensitive,
        ];
        $detail['csrf_token'] = csrf_token();
        json_response($detail);
    }

    if ($method === 'POST' && preg_match(
        '#^/api/skaters/([0-9a-fA-F-]{36})$#',
        $requestPath,
        $matches
    )) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }

        $service = new SkaterService(Database::connection());
        $service->update(
            (int) $user['club_id'],
            (int) $user['id'],
            $matches[1],
            $input
        );
        json_response(['message' => 'Skater record updated.']);
    }

    if ($method === 'POST' && preg_match(
        '#^/api/skaters/([0-9a-fA-F-]{36})/registrations$#',
        $requestPath,
        $matches
    )) {
        $user = Auth::requireRole(['ADMINISTRATOR', 'REGISTRAR']);
        $input = request_json();
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_token'] ?? null);
        if (!csrf_is_valid(is_string($token) ? $token : null)) {
            json_response(['error' => 'Your session expired. Refresh and try again.'], 419);
        }
        $result = (new SkaterService(Database::connection()))->updateRegistrations(
            (int) $user['club_id'],
            (int) $user['id'],
            $matches[1],
            is_array($input['registrations'] ?? null) ? $input['registrations'] : []
        );
        json_response([
            'message' => $result['changed_sessions'] === 0
                ? 'Sessions and groups are already up to date.'
                : 'Sessions and groups updated.',
            'result' => $result,
        ]);
    }

    http_response_code(404);
    render('error', [
        'title' => 'Page not found',
        'message' => 'The page you requested does not exist.',
        'user' => Auth::user(),
    ]);
} catch (InvalidArgumentException $exception) {
    if (string_starts_with($requestPath, '/api/')) {
        json_response(['error' => $exception->getMessage()], 422);
    }
    if ($method === 'GET' && $requestPath === '/admin-tools') {
        http_response_code(500);
        render('error', [
            'title' => 'Admin Tools could not load',
            'message' => config('environment') === 'development'
                ? $exception->getMessage()
                : 'Confirm that all CAT database migrations have been installed.',
            'user' => Auth::user(),
        ]);
    }
    if ($requestPath === '/users' || string_starts_with($requestPath, '/users/')) {
        $_SESSION['user_management_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        redirect('admin-tools#users');
    }
    if (string_starts_with($requestPath, '/account/report-card-signature')) {
        flash('error', $exception->getMessage());
        redirect('account#report-card-signature');
    }
    if ($requestPath === '/imports/skaters') {
        $message = $exception->getMessage();
        if (!string_contains($message, 'No skaters imported.')) {
            $message .= "\nNo skaters imported. Please correct errors and try again.";
        }
        flash('error', $message);
        redirect('sessions');
    }
    if ($requestPath === '/legacy-achievement-import') {
        flash('error', $exception->getMessage());
        redirect('legacy-achievement-import');
    }
    if ($requestPath === '/reports/skater-achievements/export' || $requestPath === '/reports/sessions/export') {
        flash('error', $exception->getMessage());
        redirect($requestPath === '/reports/sessions/export' ? 'reports#session-report' : 'reports#export-skater-achievements');
    }
    if (string_starts_with($requestPath, '/sessions/')) {
        $seasonId = filter_var($_POST['season_id'] ?? $_POST['return_season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $returnPath = $seasonId === false ? 'sessions' : 'sessions?season_id=' . $seasonId;
        $anchor = $requestPath === '/sessions/seasons'
            ? '#seasons-heading'
            : ($requestPath === '/sessions/rinks' ? '#rinks-heading' : '#sessions-heading');
        flash('error', $exception->getMessage());
        redirect($returnPath . $anchor);
    }
    if ($requestPath === '/admin-tools/group-colours') {
        $_SESSION['group_colours_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        redirect('admin-tools#group-colours');
    }
    if ($requestPath === '/admin-tools/stages') {
        $_SESSION['stage_settings_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        redirect('admin-tools#stage-settings');
    }
    if ($requestPath === '/admin-tools/backup' || $requestPath === '/admin-tools/restore') {
        $_SESSION['database_management_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        redirect('admin-tools#database-management');
    }
    if (string_starts_with($requestPath, '/admin-tools')) {
        flash('error', $exception->getMessage());
        redirect('admin-tools');
    }
    flash('error', $exception->getMessage());
    redirect($requestPath === '/imports/skaters' ? 'sessions' : 'dashboard');
} catch (RuntimeException $exception) {
    if ($method === 'POST' && $requestPath === '/login') {
        render('login', [
            'error' => $exception->getMessage(),
            'flashes' => [],
            'old' => ['identity' => trim((string) ($_POST['identity'] ?? ''))],
            'totpRequired' => false,
        ]);
    }
    if ($method === 'POST' && $requestPath === '/login/totp') {
        Auth::cancelTotp();
        render('login', [
            'error' => $exception->getMessage(),
            'flashes' => [],
            'old' => ['identity' => ''],
            'totpRequired' => false,
        ]);
    }
    if (string_starts_with($requestPath, '/api/')) {
        json_response(['error' => $exception->getMessage()], 500);
    }
    if ($method === 'GET' && $requestPath === '/admin-tools') {
        error_log((string) $exception);
        http_response_code(500);
        render('error', [
            'title' => 'Admin Tools could not load',
            'message' => config('environment') === 'development'
                ? $exception->getMessage()
                : 'Confirm that all CAT database migrations have been installed.',
            'user' => Auth::user(),
        ]);
    }
    if ($requestPath === '/users' || string_starts_with($requestPath, '/users/')) {
        $_SESSION['user_management_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        redirect('admin-tools#users');
    }
    if (string_starts_with($requestPath, '/account/report-card-signature')) {
        flash('error', $exception->getMessage());
        redirect('account#report-card-signature');
    }
    if ($requestPath === '/imports/skaters') {
        $message = $exception->getMessage();
        if (!string_contains($message, 'No skaters imported.')) {
            $message .= "\nNo skaters imported. Please correct errors and try again.";
        }
        flash('error', $message);
        redirect('sessions');
    }
    if ($requestPath === '/legacy-achievement-import') {
        flash('error', $exception->getMessage());
        redirect('legacy-achievement-import');
    }
    if ($requestPath === '/reports/skater-achievements/export' || $requestPath === '/reports/sessions/export') {
        flash('error', $exception->getMessage());
        redirect($requestPath === '/reports/sessions/export' ? 'reports#session-report' : 'reports#export-skater-achievements');
    }
    if (string_starts_with($requestPath, '/sessions/')) {
        $seasonId = filter_var($_POST['season_id'] ?? $_POST['return_season_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $returnPath = $seasonId === false ? 'sessions' : 'sessions?season_id=' . $seasonId;
        $anchor = $requestPath === '/sessions/seasons'
            ? '#seasons-heading'
            : ($requestPath === '/sessions/rinks' ? '#rinks-heading' : '#sessions-heading');
        flash('error', $exception->getMessage());
        redirect($returnPath . $anchor);
    }
    if ($requestPath === '/admin-tools/group-colours') {
        $_SESSION['group_colours_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        redirect('admin-tools#group-colours');
    }
    if ($requestPath === '/admin-tools/stages') {
        $_SESSION['stage_settings_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        redirect('admin-tools#stage-settings');
    }
    if ($requestPath === '/admin-tools/backup' || $requestPath === '/admin-tools/restore') {
        $_SESSION['database_management_flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
        redirect('admin-tools#database-management');
    }
    if (string_starts_with($requestPath, '/admin-tools')) {
        flash('error', $exception->getMessage());
        redirect('admin-tools');
    }
    http_response_code(500);
    render('error', [
        'title' => 'CAT needs attention',
        'message' => $exception->getMessage(),
        'user' => Auth::user(),
    ]);
} catch (Throwable $exception) {
    $reference = log_application_exception($exception);
    if (string_starts_with($requestPath, '/api/')) {
        $response = ['error' => "An unexpected error occurred. Reference {$reference}."];
        if (can_view_diagnostic_exception()) {
            $response['diagnostic'] = get_class($exception) . ': ' . $exception->getMessage();
        }
        json_response($response, 500);
    }
    http_response_code(500);
    render('error', [
        'title' => 'Something went wrong',
        'message' => config('environment') === 'development'
            ? $exception->getMessage()
            : 'The request could not be completed.',
        'user' => Auth::user(),
    ]);
}
