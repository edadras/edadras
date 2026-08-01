<?php

namespace App\Services\Ai;

use App\Tenancy\TenantContext;

/**
 * The management chat assistant. It answers questions about the club using a
 * snapshot of that club's own numbers, never another tenant's.
 */
class AiAssistant
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly AiInsightService $insights,
        private readonly TenantContext $tenancy,
    ) {}

    public function available(): bool
    {
        return $this->claude->isConfigured();
    }

    /**
     * @param  array<int, array{role:string, content:string}>  $history
     */
    public function ask(string $question, array $history = []): array
    {
        if (! $this->available()) {
            return [
                'answer' => __('ai.assistant_unavailable'),
                'available' => false,
                'context' => $this->insights->snapshot(),
            ];
        }

        $snapshot = $this->insights->snapshot();

        $messages = collect($history)
            ->filter(fn ($turn) => in_array($turn['role'] ?? null, ['user', 'assistant'], true))
            ->map(fn ($turn) => ['role' => $turn['role'], 'content' => (string) $turn['content']])
            ->values()
            ->push(['role' => 'user', 'content' => $question])
            ->all();

        $answer = $this->claude->ask($this->systemPrompt($snapshot), $messages);

        return [
            'answer' => $answer,
            'available' => true,
            'context' => $snapshot,
        ];
    }

    protected function systemPrompt(array $snapshot): string
    {
        $club = $this->tenancy->get();
        $locale = app()->getLocale();
        $numbers = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
        You are the management assistant inside GymFlow AI, a club management
        platform. You are advising the staff of "{$club?->name}", a {$club?->type}
        club. Answer in the "{$locale}" locale unless the user writes in another
        language, in which case match theirs.

        Below is a live snapshot of this club's own figures. Ground every number
        you quote in it. If the snapshot does not contain what is needed, say so
        plainly and suggest which report would answer the question — do not guess
        a figure. You have no access to any other club's data.

        Keep answers short and practical: lead with the answer, then at most a few
        supporting lines. When the staff asks what to do, give one clear
        recommendation rather than a survey of options. Amounts are in
        {$club?->currency}.

        Snapshot:
        {$numbers}
        PROMPT;
    }
}
