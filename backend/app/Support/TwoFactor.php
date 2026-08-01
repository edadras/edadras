<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Time-based one time passwords, RFC 6238. Six digits over a thirty second
 * step, which is what Google Authenticator, Authy and 1Password all expect.
 *
 * Verification accepts the neighbouring windows too, because phone clocks
 * drift and a code typed on the last second of a step arrives in the next.
 */
class TwoFactor
{
    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    protected const DIGITS = 6;

    protected const STEP = 30;

    /** A fresh base32 secret, the length authenticator apps expect. */
    public function generateSecret(int $length = 32): string
    {
        return collect(range(1, $length))
            ->map(fn () => self::ALPHABET[random_int(0, 31)])
            ->implode('');
    }

    /** The otpauth:// URI an authenticator app scans. */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::STEP,
        ]);
    }

    /** True when the code matches, allowing `$window` steps either side. */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv(time(), self::STEP);

        for ($offset = -$window; $offset <= $window; $offset++) {
            // hash_equals, so a wrong code cannot be narrowed down by timing.
            if (hash_equals($this->codeAt($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The code for one counter step, used by verify() and by tests. */
    public function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);

        if ($key === '') {
            return str_repeat('0', self::DIGITS);
        }

        $hash = hash_hmac('sha1', pack('N*', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** The code for right now. */
    public function currentCode(string $secret): string
    {
        return $this->codeAt($secret, intdiv(time(), self::STEP));
    }

    /**
     * One-use codes for the day the phone is lost. Shown once, stored hashed.
     *
     * @return array<int, string>
     */
    public function recoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)))
            ->all();
    }

    protected function base32Decode(string $secret): string
    {
        $secret = rtrim(strtoupper(preg_replace('/[^A-Z2-7=]/i', '', $secret) ?? ''), '=');

        $bits = '';

        foreach (str_split($secret) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                return '';
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
