<?php

namespace App\Messaging\Channels;

use App\Messaging\Contracts\MessageChannel;
use App\Messaging\DeliveryResult;
use App\Messaging\Recipient;
use App\Models\PushToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Firebase Cloud Messaging, HTTP v1. The access token is minted from the
 * service account with a signed JWT and cached until just before it expires,
 * so a campaign of a thousand pushes mints one token, not a thousand.
 *
 * A token Firebase rejects as gone is deleted, which is the only way the
 * push_tokens table ever stops growing.
 */
class PushChannel implements MessageChannel
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function send(Recipient $recipient, string $body, array $context = []): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::skipped('push_not_configured');
        }

        if (! $recipient->reachableOn('push')) {
            return DeliveryResult::skipped('no_device');
        }

        $token = $this->accessToken();

        if ($token === null) {
            return DeliveryResult::failed('push_auth_failed');
        }

        $sent = 0;
        $lastError = null;

        foreach ($recipient->pushTokens as $device) {
            $result = $this->push($token, $device, $body, $context);

            $result->delivered ? $sent++ : $lastError = $result->error;
        }

        return $sent > 0
            ? DeliveryResult::sent("fcm:{$sent}")
            : DeliveryResult::failed($lastError ?? 'push_no_device_accepted');
    }

    public function isConfigured(): bool
    {
        return filled(Arr::get($this->config, 'credentials')) && filled(Arr::get($this->config, 'project_id'));
    }

    protected function push(string $accessToken, string $device, string $body, array $context): DeliveryResult
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->config['project_id']}/messages:send";

        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) Arr::get($this->config, 'timeout', 15))
                ->post($url, [
                    'message' => [
                        'token' => $device,
                        'notification' => [
                            'title' => Arr::get($context, 'title', config('app.name')),
                            'body' => $body,
                        ],
                        'data' => array_map('strval', Arr::get($context, 'data', [])),
                    ],
                ]);

            if ($response->status() === 404 || $response->status() === 400) {
                // Firebase says the device is gone; stop carrying it around.
                PushToken::where('token', $device)->delete();

                return DeliveryResult::failed('push_token_stale');
            }

            if ($response->failed()) {
                return DeliveryResult::failed('push_'.$response->status());
            }

            return DeliveryResult::sent(Arr::get($response->json(), 'name'));
        } catch (Throwable $e) {
            return DeliveryResult::failed('push_error: '.$e->getMessage());
        }
    }

    /** An OAuth access token for the service account, cached until it expires. */
    protected function accessToken(): ?string
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return null;
        }

        return Cache::remember(
            'fcm.access_token.'.md5($credentials['client_email']),
            3300,
            function () use ($credentials) {
                $jwt = $this->signedAssertion($credentials);

                if ($jwt === null) {
                    return null;
                }

                $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                return $response->successful() ? $response->json('access_token') : null;
            }
        );
    }

    /** @return array<string, string>|null */
    protected function credentials(): ?array
    {
        $source = Arr::get($this->config, 'credentials');

        if (blank($source)) {
            return null;
        }

        // Either the JSON itself, or a path to the service account file.
        $json = str_starts_with(trim($source), '{')
            ? $source
            : (is_readable($source) ? file_get_contents($source) : null);

        $decoded = $json ? json_decode($json, true) : null;

        return isset($decoded['client_email'], $decoded['private_key']) ? $decoded : null;
    }

    /** @param array<string, string> $credentials */
    protected function signedAssertion(array $credentials): ?string
    {
        $now = time();

        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        $payload = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $signature = '';

        if (! openssl_sign($payload, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
