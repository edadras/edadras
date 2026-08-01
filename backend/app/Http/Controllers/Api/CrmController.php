<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCampaign;
use App\Messaging\ChannelManager;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Member;
use App\Services\CampaignDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Bulk messaging plus the member ↔ coach chat. */
class CrmController extends Controller
{
    public function __construct(
        private readonly CampaignDispatcher $dispatcher,
        private readonly ChannelManager $channels,
    ) {}

    /** Which channels this club can actually reach members on right now. */
    public function channelStatus(): JsonResponse
    {
        $this->authorize('crm.view');

        return response()->json([
            'channels' => collect($this->channels->status())
                ->map(fn (bool $live, string $channel) => [
                    'channel' => $channel,
                    'live' => $live,
                    'label' => __("crm.channel_{$channel}"),
                ])
                ->values(),
            'placeholders' => ['{name}', '{full_name}', '{code}', '{club}', '{expires}', '{sessions}'],
        ]);
    }

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

        $recipients = $this->dispatcher->audience($data['audience']);

        $campaign = Campaign::create($data + [
            'status' => isset($data['scheduled_at']) ? 'scheduled' : 'draft',
            'recipients_count' => $recipients->count(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json($campaign, 201);
    }

    /**
     * Sends the campaign. Anything above a handful of recipients goes to the
     * queue so the desk is not left staring at a spinner; a small one is sent
     * inline so the manager sees the delivery count straight away.
     */
    public function sendCampaign(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorize('crm.update');

        abort_if(in_array($campaign->status, ['sent', 'sending'], true), 422, __('general.already_sent'));

        $recipients = $this->dispatcher->audience($campaign->audience ?? ['type' => 'all']);
        $threshold = (int) config('gymflow.messaging.queue_above', 25);

        if ($recipients->count() > $threshold && ! $request->boolean('now')) {
            $campaign->update(['status' => 'sending', 'recipients_count' => $recipients->count()]);

            SendCampaign::dispatch($campaign->id, $campaign->tenant_id);

            return response()->json([
                'campaign' => $campaign->fresh(),
                'queued' => true,
                'recipients' => $recipients->count(),
            ], 202);
        }

        $result = $this->dispatcher->send($campaign);

        return response()->json(['campaign' => $campaign->fresh(), 'queued' => false] + $result);
    }

    /** Shows what one member would actually receive, before anything is sent. */
    public function previewCampaign(Request $request): JsonResponse
    {
        $this->authorize('crm.view');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'member_id' => ['nullable', 'exists:members,id'],
        ]);

        $member = isset($data['member_id'])
            ? Member::findOrFail($data['member_id'])
            : Member::active()->first();

        abort_unless($member, 422, __('general.no_members'));

        return response()->json([
            'member' => $member->only(['id', 'code', 'first_name', 'last_name']),
            'body' => $this->dispatcher->personalise($data['body'], $member),
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

        $members = $this->dispatcher->audience($audience);

        return response()->json([
            'count' => $members->count(),
            'sample' => $members->take(10)->map->only(['id', 'code', 'first_name', 'last_name', 'phone'])->values(),
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $this->authorize('chat.view');

        return response()->json(
            $this->visibleConversations($request)
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
        $this->authorizeThread($request, $conversation);

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
        $this->authorizeThread($request, $conversation);

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

    /**
     * A member sees their own threads and nobody else's; a coach sees the
     * threads they are on. Staff with chat access see the whole club.
     */
    protected function visibleConversations(Request $request): Builder
    {
        $user = $request->user();

        if ($member = $user->member) {
            return Conversation::query()->where('member_id', $member->id);
        }

        if ($coach = $user->coach) {
            return Conversation::query()->where('coach_id', $coach->id);
        }

        return Conversation::query();
    }

    /** Blocks reading or writing a thread the signed in user is not on. */
    protected function authorizeThread(Request $request, Conversation $conversation): void
    {
        $allowed = $this->visibleConversations($request)
            ->whereKey($conversation->id)
            ->exists();

        abort_unless($allowed, 403, __('auth.forbidden'));
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
