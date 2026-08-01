<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper around the Anthropic PHP SDK. Everything the AI module needs
 * goes through here so the rest of the app never touches the SDK directly.
 */
class ClaudeClient
{
    protected ?Client $client = null;

    public function __construct(protected array $config) {}

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false) && ! empty($this->config['api_key']);
    }

    /** Plain prose answer, used by the management chat assistant. */
    public function ask(string $system, array $messages): string
    {
        $message = $this->client()->messages->create(
            model: $this->config['model'],
            maxTokens: $this->config['max_tokens'],
            system: [
                ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            outputConfig: ['effort' => $this->config['effort']],
            messages: $messages,
        );

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        return '';
    }

    /**
     * Asks for a JSON document matching the given schema. Used for the
     * generated workout and nutrition programs.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function structured(string $system, string $prompt, array $schema): array
    {
        $message = $this->client()->messages->create(
            model: $this->config['model'],
            maxTokens: $this->config['max_tokens'],
            system: [
                ['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']],
            ],
            outputConfig: [
                'effort' => $this->config['effort'],
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            messages: [
                ['role' => 'user', 'content' => $prompt],
            ],
        );

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $decoded = json_decode($block->text, true);

                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        throw new RuntimeException('The AI response did not contain usable JSON.');
    }

    /** Returns null instead of throwing so callers can fall back quietly. */
    public function tryStructured(string $system, string $prompt, array $schema): ?array
    {
        try {
            return $this->structured($system, $prompt, $schema);
        } catch (Throwable $e) {
            Log::warning('GymFlow AI request failed, using the built-in template.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function client(): Client
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The AI module is not configured. Set ANTHROPIC_API_KEY.');
        }

        return $this->client ??= new Client(apiKey: $this->config['api_key']);
    }
}
