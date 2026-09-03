<?php

declare(strict_types=1);

final class TotpService
{
    private const STEP_SECONDS = 30;
    private const DIGITS = 6;
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function createSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $accountLabel): string
    {
        $issuer = 'CAT';
        $label = $issuer . ':' . $accountLabel;

        return 'otpauth://totp/' . rawurlencode($label) . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::STEP_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $counter = intdiv(time(), self::STEP_SECONDS);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->codeForCounter($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $this->key());

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $ciphertext): string
    {
        $payload = base64_decode($ciphertext, true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('The stored two-factor authentication secret is invalid.');
        }
        $secret = sodium_crypto_secretbox_open(
            substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        );
        if ($secret === false) {
            throw new RuntimeException('The stored two-factor authentication secret cannot be opened.');
        }

        return $secret;
    }

    private function codeForCounter(string $secret, int $counter): string
    {
        $binarySecret = $this->base32Decode($secret);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $binarySecret, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function key(): string
    {
        $key = base64_decode((string) config('security.totp_encryption_key', ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Two-factor authentication requires a valid CAT_TOTP_ENCRYPTION_KEY.');
        }

        return $key;
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        $bits = '';
        foreach (str_split($value) as $character) {
            $position = strpos(self::BASE32_ALPHABET, $character);
            if ($position === false) throw new InvalidArgumentException('Invalid authenticator secret.');
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $decoded .= chr(bindec($chunk));
        }

        return $decoded;
    }
}
