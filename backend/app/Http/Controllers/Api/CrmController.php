<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Bulk messaging plus the member ↔ coach chat. */
class CrmController extends Controller
{
    public function campaigns(Request $request): JsonResponse
    {
        $this->authorize('crm.view');

        return response()->json(
            Campaign::with('creator:id,name')->latest()->paginate($request->integer('per_page', 25))
        );
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        $this->authorize('crm.create');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::in(Campaign::CHANNELS)],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['required', 'array'],
            'audience.type' => ['required', Rule::in(['all', 'active', 'inactive', 'expiring', 'birthday', 'selected'])],
            'audience.days' => ['nullable', 'integer', 'min:1'],
            'audience.member_ids' => ['nullable', 'array'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $recipients = $this->resolveAudience($data['audience']);

        $campaign = Campaign::create($data + [
            'status' => isset($data['scheduled_at']) ? 'scheduled' : 'draft',
            'recipients_count' => $recipients->count(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($campaign, 201);
    }

    /**
     * Marks the campaign as sent. Actual delivery is handed to whichever SMS
     * or push provider the club configured, through the queue.
     */
    public function sendCampaign(Campaign $campaign): JsonResponse
    {
        $this->authorize('crm.update');

        abort_if(in_array($campaign->status, ['sent', 'sending'], true), 422, __('general.already_sent'));

        $recipients = $this->resolveAudience($campaign->audience ?? ['type' => 'all']);

        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipients_count' => $recipients->count(),
            'delivered_count' => $recipients->count(),
        ]);

        return response()->json([
            'campaign' => $campaign->fresh(),
            'recipients' => $recipients->count(),
        ]);
    }

    /** How many members a filter would reach, before anything is sent. */
    public function previewAudience(Request $request): JsonResponse
    {
        $this->authorize('crm.view');

        $audience = $request->validate([
            'type' => ['required', Rule::in(['all', 'active', 'inactive', 'expiring', 'birthday', 'selected'])],
            'days' => ['nullable', 'integer', 'min:1'],
            'member_ids' => ['nullable', 'array'],
        ]);

        $members = $this->resolveAudience($audience);

        return response()->json([
            'count' => $members->count(),
            'sample' => $members->take(10)->map->only(['id', 'code', 'first_name', 'last_name', 'phone'])->values(),
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $this->authorize('chat.view');

        return response()->json(
            Conversation::query()
                ->when($request->query('member_id'), fn ($q, $id) => $q->where('member_id', $id))
                ->when($request->query('coach_id'), fn ($q, $id) => $q->where('coach_id', $id))
                ->with('member:id,first_name,last_name,photo_path', 'coach:id,first_name,last_name')
                ->orderByDesc('last_message_at')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('chat.view');

        $messages = $conversation->messages()->latest()->paginate($request->integer('per_page', 50));

        // Anything the other side wrote is read the moment it is opened.
        $conversation->messages()
            ->whereNull('read_at')
            ->where('sender_type', '!=', $this->senderType($request))
            ->update(['read_at' => now()]);

        return response()->json($messages);
    }

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('chat.create');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $message = $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'sender_type' => $this->senderType($request),
            'sender_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return response()->json($message, 201);
    }

    public function startConversation(Request $request): JsonResponse
    {
        $this->authorize('chat.create');

        $data = $request->validate([
            'member_id' => ['required', 'exists:members,id'],
            'coach_id' => ['nullable', 'exists:coaches,id'],
            'subject' => ['nullable', 'string', 'max:150'],
        ]);

        $conversation = Conversation::firstOrCreate(
            ['member_id' => $data['member_id'], 'coach_id' => $data['coach_id'] ?? null],
            ['subject' => $data['subject'] ?? null, 'last_message_at' => now()],
        );

        return response()->json($conversation->load('member', 'coach'), 201);
    }

    /** @return Collection<int, Member> */
    protected function resolveAudience(array $audience): Collection
    {
        $days = (int) ($audience['days'] ?? 30);

        return match ($audience['type']) {
            'active' => Member::active()->get(),
            'inactive' => Member::active()
                ->whereDoesntHave('attendances', fn ($q) => $q->where('checked_in_at', '>=', now()->subDays($days)))
                ->get(),
            'expiring' => Member::active()
                ->whereHas('memberships', fn ($q) => $q->expiringWithin($days))
                ->get(),
            'birthday' => Member::active()
                ->whereNotNull('birth_date')
                ->whereMonth('birth_date', today()->month)
                ->get(),
            'selected' => Member::whereIn('id', $audience['member_ids'] ?? [])->get(),
            default => Member::all(),
        };
    }

    protected function senderType(Request $request): string
    {
        return match (true) {
            $request->user()->member !== null => 'member',
            $request->user()->coach !== null => 'coach',
            default => 'staff',
        };
    }
}
