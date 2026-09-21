<?php

declare(strict_types=1);

final class UserService
{
    private const SIGNATURE_MAX_BYTES = 2097152;
    private const SIGNATURE_MIN_WIDTH = 300;
    private const SIGNATURE_MIN_HEIGHT = 80;
    private const SIGNATURE_MAX_WIDTH = 4000;
    private const SIGNATURE_MAX_HEIGHT = 2000;
    private const SIGNATURE_MAX_PIXELS = 8000000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function users(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.public_id, u.first_name, u.last_name, u.username, u.email,
                    u.active, u.must_change_password, u.password_lock_until, u.totp_enabled_at, u.last_login_at, u.created_at,
                    r.code AS role_code, r.name AS role_name
             FROM app_user u
             INNER JOIN user_role r ON r.id = u.user_role_id
             WHERE u.deleted_at IS NULL
             ORDER BY u.active DESC, u.last_name, u.first_name, u.username'
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    public function create(int $createdByUserId, array $input): void
    {
        [$firstName, $lastName, $email, $password, $roleCode] = $this->validatedNewUser($input);
        $roleId = $this->roleId($roleCode);

        $statement = $this->pdo->prepare(
            'INSERT INTO app_user (
                user_role_id, first_name, last_name, username, email,
                password_hash, must_change_password, active, created_by_user_id, updated_by_user_id
             ) VALUES (
                :role_id, :first_name, :last_name, :username, :email,
                :password_hash, 1, 1, :created_by_user_id, :updated_by_user_id
             )'
        );
        $statement->execute([
            'role_id' => $roleId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $email,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_by_user_id' => $createdByUserId,
            'updated_by_user_id' => $createdByUserId,
        ]);
        switch ($roleCode) {
            case 'ADMINISTRATOR': $roleName = 'Administrator'; break;
            case 'REGISTRAR': $roleName = 'Editor'; break;
            case 'COACH': $roleName = 'Coach'; break;
            default: $roleName = $roleCode;
        }
        LoginActivityService::record(
            'account_created',
            (int) $this->pdo->lastInsertId(),
            "{$firstName} {$lastName} ({$roleName})",
            $createdByUserId
        );
    }

    public function resetPassword(int $adminUserId, string $publicId, array $input): void
    {
        $password = (string) ($input['temporary_password'] ?? '');
        $confirmation = (string) ($input['temporary_password_confirmation'] ?? '');
        $this->validatePassword($password, $confirmation);

        $statement = $this->pdo->prepare(
            'UPDATE app_user
             SET password_hash = :password_hash,
                 must_change_password = 1,
                 password_failed_attempts = 0,
                 password_failure_window_started_at = NULL,
                 password_short_lock_issued_at = NULL,
                 password_lock_until = NULL,
                 updated_by_user_id = :updated_by_user_id
             WHERE public_id = :public_id
               AND active = 1
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_by_user_id' => $adminUserId,
            'public_id' => $publicId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('The user could not be found.');
        }
        LoginActivityService::record('password_reset_by_administrator', $this->userId($publicId), null, $adminUserId);
    }

    public function delete(int $adminUserId, string $publicId): void
    {
        $targetUserId = $this->userId($publicId);
        $statement = $this->pdo->prepare(
            'UPDATE app_user
             SET active = 0,
                 username = CONCAT("deleted-", public_id),
                 email = NULL,
                 deleted_at = UTC_TIMESTAMP(),
                 updated_by_user_id = :admin_user_id
             WHERE public_id = :public_id AND deleted_at IS NULL'
        );
        $statement->execute(['admin_user_id' => $adminUserId, 'public_id' => $publicId]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('The user could not be found.');
        }
        LoginActivityService::record('account_deleted', $targetUserId, null, $adminUserId);
    }

    public function setActive(int $adminUserId, string $publicId, bool $active): void
    {
        $targetUserId = $this->userId($publicId);
        $statement = $this->pdo->prepare(
            'UPDATE app_user SET active = :active, updated_by_user_id = :admin_user_id
             WHERE public_id = :public_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'active' => $active ? 1 : 0,
            'admin_user_id' => $adminUserId,
            'public_id' => $publicId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('The user could not be found.');
        }
        LoginActivityService::record($active ? 'account_resumed' : 'account_paused', $targetUserId, null, $adminUserId);
    }

    public function enableTotp(array $user, string $secret): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE app_user
             SET totp_secret_ciphertext = :secret, totp_enabled_at = UTC_TIMESTAMP(),
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :user_id AND active = 1 AND deleted_at IS NULL'
        );
        $statement->execute([
            'secret' => (new TotpService())->encrypt($secret),
            'updated_by_user_id' => $user['id'],
            'user_id' => $user['id'],
        ]);
        if ($statement->rowCount() !== 1) throw new InvalidArgumentException('Your user account could not be found.');
        LoginActivityService::record('totp_enabled', (int) $user['id'], null, (int) $user['id']);
    }

    public function disableTotp(array $user, string $code): void
    {
        $statement = $this->pdo->prepare(
            'SELECT totp_secret_ciphertext FROM app_user WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['id' => $user['id']]);
        $ciphertext = $statement->fetchColumn();
        if (!is_string($ciphertext) || $ciphertext === '' || !(new TotpService())->verify((new TotpService())->decrypt($ciphertext), $code)) {
            throw new InvalidArgumentException('Enter a current authenticator code to disable two-factor authentication.');
        }
        $update = $this->pdo->prepare(
            'UPDATE app_user SET totp_secret_ciphertext = NULL, totp_enabled_at = NULL,
             updated_by_user_id = :updated_by_user_id WHERE id = :user_id'
        );
        $update->execute(['updated_by_user_id' => $user['id'], 'user_id' => $user['id']]);
        LoginActivityService::record('totp_disabled', (int) $user['id'], null, (int) $user['id']);
    }

    public function resetTotp(int $adminUserId, string $publicId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE app_user SET totp_secret_ciphertext = NULL, totp_enabled_at = NULL,
             updated_by_user_id = :updated_by_user_id
             WHERE public_id = :public_id AND deleted_at IS NULL'
        );
        $statement->execute(['updated_by_user_id' => $adminUserId, 'public_id' => $publicId]);
        if ($statement->rowCount() !== 1) throw new InvalidArgumentException('The user could not be found.');
        $target = $this->pdo->prepare('SELECT id FROM app_user WHERE public_id = :public_id LIMIT 1');
        $target->execute(['public_id' => $publicId]);
        LoginActivityService::record('totp_reset_by_administrator', (int) $target->fetchColumn(), null, $adminUserId);
    }

    public function clearPasswordLock(int $adminUserId, string $publicId): void
    {
        $targetUserId = $this->userId($publicId);
        $statement = $this->pdo->prepare(
            'UPDATE app_user
             SET password_failed_attempts = 0,
                 password_failure_window_started_at = NULL,
                 password_short_lock_issued_at = NULL,
                 password_lock_until = NULL,
                 updated_by_user_id = :updated_by_user_id
             WHERE public_id = :public_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'updated_by_user_id' => $adminUserId,
            'public_id' => $publicId,
        ]);
        if ($statement->rowCount() !== 1) throw new InvalidArgumentException('The user could not be found.');
        LoginActivityService::record('password_lock_cleared_by_administrator', $targetUserId, null, $adminUserId);
    }

    public function updateOwnProfile(array $user, array $input): void
    {
        $firstName = $this->requiredText($input['first_name'] ?? '', 'First name');
        $lastName = $this->requiredText($input['last_name'] ?? '', 'Last name');
        $email = $this->email($input['email'] ?? '');

        $statement = $this->pdo->prepare(
            'UPDATE app_user
             SET first_name = :first_name, last_name = :last_name,
                username = :username, email = :email, updated_by_user_id = :updated_by_user_id
             WHERE id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $email,
            'email' => $email,
            'updated_by_user_id' => $user['id'],
            'user_id' => $user['id'],
        ]);
    }

    /** @return array{has_signature:bool,width:?int,height:?int,updated_at:?string} */
    public function reportCardSignatureMetadata(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT report_card_signature_width, report_card_signature_height,
                    report_card_signature_updated_at,
                    OCTET_LENGTH(report_card_signature_png) AS signature_bytes
             FROM app_user
             WHERE id = :user_id AND active = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new InvalidArgumentException('Your user account could not be found.');
        }
        return [
            'has_signature' => (int) ($row['signature_bytes'] ?? 0) > 0,
            'width' => $row['report_card_signature_width'] === null
                ? null
                : (int) $row['report_card_signature_width'],
            'height' => $row['report_card_signature_height'] === null
                ? null
                : (int) $row['report_card_signature_height'],
            'updated_at' => $row['report_card_signature_updated_at'] ?? null,
        ];
    }

    public function reportCardSignaturePng(int $userId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT report_card_signature_png
             FROM app_user
             WHERE id = :user_id AND active = 1 AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $signature = $statement->fetchColumn();
        return is_string($signature) && $signature !== '' ? $signature : null;
    }

    public function saveReportCardSignature(array $user, array $upload): void
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'The signature image is larger than 2 MB.'
                : ($error === UPLOAD_ERR_NO_FILE
                    ? 'Choose a signature image to upload.'
                    : 'The signature image could not be uploaded. Please try again.');
            throw new InvalidArgumentException($message);
        }
        $temporaryPath = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size <= 0) {
            throw new InvalidArgumentException('Choose a valid signature image to upload.');
        }
        if ($size > self::SIGNATURE_MAX_BYTES) {
            throw new InvalidArgumentException('The signature image is larger than 2 MB.');
        }
        $bytes = file_get_contents($temporaryPath);
        if (!is_string($bytes) || $bytes === '') {
            throw new InvalidArgumentException('The signature image could not be read.');
        }
        [$png, $width, $height] = $this->normalizeReportCardSignature($bytes);
        $statement = $this->pdo->prepare(
            'UPDATE app_user
             SET report_card_signature_png = :signature,
                 report_card_signature_width = :width,
                 report_card_signature_height = :height,
                 report_card_signature_updated_at = UTC_TIMESTAMP(),
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :user_id AND active = 1 AND deleted_at IS NULL'
        );
        $statement->bindValue('signature', $png, PDO::PARAM_LOB);
        $statement->bindValue('width', $width, PDO::PARAM_INT);
        $statement->bindValue('height', $height, PDO::PARAM_INT);
        $statement->bindValue('updated_by_user_id', (int) $user['id'], PDO::PARAM_INT);
        $statement->bindValue('user_id', (int) $user['id'], PDO::PARAM_INT);
        $statement->execute();
        LoginActivityService::record('report_card_signature_uploaded', (int) $user['id'], null, (int) $user['id']);
    }

    public function deleteReportCardSignature(array $user): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE app_user
             SET report_card_signature_png = NULL,
                 report_card_signature_width = NULL,
                 report_card_signature_height = NULL,
                 report_card_signature_updated_at = NULL,
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :user_id AND active = 1 AND deleted_at IS NULL'
        );
        $statement->execute([
            'updated_by_user_id' => $user['id'],
            'user_id' => $user['id'],
        ]);
        LoginActivityService::record('report_card_signature_deleted', (int) $user['id'], null, (int) $user['id']);
    }

    /** @return array{0:string,1:int,2:int} */
    private function normalizeReportCardSignature(string $bytes): array
    {
        $details = @getimagesizefromstring($bytes);
        if (!is_array($details)) {
            throw new InvalidArgumentException('Upload a PNG, JPEG, or WebP image.');
        }
        $width = (int) ($details[0] ?? 0);
        $height = (int) ($details[1] ?? 0);
        $mime = (string) ($details['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new InvalidArgumentException('Upload a PNG, JPEG, or WebP image.');
        }
        if ($width < self::SIGNATURE_MIN_WIDTH || $height < self::SIGNATURE_MIN_HEIGHT) {
            throw new InvalidArgumentException('The signature image must be at least 300 × 80 pixels.');
        }
        if ($width > self::SIGNATURE_MAX_WIDTH || $height > self::SIGNATURE_MAX_HEIGHT
            || ($width * $height) > self::SIGNATURE_MAX_PIXELS) {
            throw new InvalidArgumentException('The signature image must be no larger than 4000 × 2000 pixels.');
        }
        $aspectRatio = $width / $height;
        if ($aspectRatio < 1.5 || $aspectRatio > 12) {
            throw new InvalidArgumentException('Use a wide signature image with an aspect ratio between 1.5:1 and 12:1.');
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new InvalidArgumentException('The signature image could not be decoded.');
        }
        imagealphablending($source, true);
        imagesavealpha($source, true);
        $cropped = imagecropauto($source, IMG_CROP_TRANSPARENT);
        if ($cropped === false || (imagesx($cropped) === $width && imagesy($cropped) === $height)) {
            if ($cropped !== false && $cropped !== $source) imagedestroy($cropped);
            $cropped = imagecropauto($source, IMG_CROP_THRESHOLD, 0.08, 0xFFFFFF);
        }
        $working = $cropped !== false ? $cropped : $source;
        $workingWidth = imagesx($working);
        $workingHeight = imagesy($working);
        $scale = min(1, 1560 / max(1, $workingWidth), 360 / max(1, $workingHeight));
        $contentWidth = max(1, (int) round($workingWidth * $scale));
        $contentHeight = max(1, (int) round($workingHeight * $scale));
        $padding = 20;
        $canvasWidth = $contentWidth + ($padding * 2);
        $canvasHeight = $contentHeight + ($padding * 2);
        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagecopyresampled(
            $canvas,
            $working,
            $padding,
            $padding,
            0,
            0,
            $contentWidth,
            $contentHeight,
            $workingWidth,
            $workingHeight
        );
        $inkPixels = 0;
        for ($y = $padding; $y < $padding + $contentHeight; $y++) {
            for ($x = $padding; $x < $padding + $contentWidth; $x++) {
                $colour = imagecolorat($canvas, $x, $y);
                $alpha = ($colour >> 24) & 0x7F;
                $red = ($colour >> 16) & 0xFF;
                $green = ($colour >> 8) & 0xFF;
                $blue = $colour & 0xFF;
                if ($red >= 248 && $green >= 248 && $blue >= 248) {
                    imagesetpixel($canvas, $x, $y, $transparent);
                } elseif ($alpha < 120 && min($red, $green, $blue) < 240) {
                    $inkPixels++;
                }
            }
        }
        if ($inkPixels < 20) {
            if ($cropped !== false && $cropped !== $source) imagedestroy($cropped);
            imagedestroy($source);
            imagedestroy($canvas);
            throw new InvalidArgumentException('The image does not contain a visible signature.');
        }
        ob_start();
        imagepng($canvas, null, 6);
        $png = ob_get_clean();
        if ($cropped !== false && $cropped !== $source) imagedestroy($cropped);
        imagedestroy($source);
        imagedestroy($canvas);
        if (!is_string($png) || $png === '' || strlen($png) > self::SIGNATURE_MAX_BYTES) {
            throw new InvalidArgumentException('The normalized signature image is too large. Try a simpler image.');
        }
        return [$png, $canvasWidth, $canvasHeight];
    }

    public function changeOwnPassword(array $user, array $input): void
    {
        $currentPassword = (string) ($input['current_password'] ?? '');
        $newPassword = (string) ($input['new_password'] ?? '');
        $confirmation = (string) ($input['new_password_confirmation'] ?? '');
        if ($currentPassword === '' || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new InvalidArgumentException('Your current password is not correct.');
        }
        $this->validatePassword($newPassword, $confirmation);

        $statement = $this->pdo->prepare(
            'UPDATE app_user
             SET password_hash = :password_hash, must_change_password = 0,
                 password_failed_attempts = 0,
                 password_failure_window_started_at = NULL,
                 password_short_lock_issued_at = NULL,
                 password_lock_until = NULL,
                 updated_by_user_id = :updated_by_user_id
             WHERE id = :user_id AND deleted_at IS NULL'
        );
        $statement->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_by_user_id' => $user['id'],
            'user_id' => $user['id'],
        ]);
        LoginActivityService::record('password_changed', (int) $user['id'], null, (int) $user['id']);
    }

    private function userId(string $publicId): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM app_user WHERE public_id = :public_id AND deleted_at IS NULL LIMIT 1');
        $statement->execute(['public_id' => $publicId]);
        $id = $statement->fetchColumn();
        if ($id === false) throw new InvalidArgumentException('The user could not be found.');
        return (int) $id;
    }

    private function validatedNewUser(array $input): array
    {
        $firstName = $this->requiredText($input['first_name'] ?? '', 'First name');
        $lastName = $this->requiredText($input['last_name'] ?? '', 'Last name');
        $email = $this->email($input['email'] ?? '');
        $password = (string) ($input['initial_password'] ?? '');
        $confirmation = (string) ($input['initial_password_confirmation'] ?? '');
        $this->validatePassword($password, $confirmation);
        $roleCode = strtoupper(trim((string) ($input['role_code'] ?? '')));
        if (!in_array($roleCode, ['ADMINISTRATOR', 'REGISTRAR', 'COACH'], true)) {
            throw new InvalidArgumentException('Choose a valid user type.');
        }

        return [$firstName, $lastName, $email, $password, $roleCode];
    }

    private function roleId(string $roleCode): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM user_role WHERE code = :code AND active = 1 LIMIT 1'
        );
        $statement->execute(['code' => $roleCode]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new RuntimeException('That user type is not available.');
        }

        return (int) $id;
    }

    private function requiredText($value, string $label): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > 100) {
            throw new InvalidArgumentException("{$label} is required and must be 100 characters or fewer.");
        }

        return $value;
    }

    private function email($value): string
    {
        $email = trim((string) $value);
        if ($email === '' || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }

        return $email;
    }

    private function validatePassword(string $password, string $confirmation): void
    {
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('Passwords must be at least 12 characters.');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new InvalidArgumentException('The passwords do not match.');
        }
    }
}
