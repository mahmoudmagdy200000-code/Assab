<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\SupportChannel;
use Modules\Admin\Models\SupportTicket;
use Modules\Admin\Models\TicketMessage;

/**
 * Technical support (COMPANY_DASHBOARD_API_SPEC.md §5.1.8).
 */
class SupportController extends AsabController
{
    public function channels(): JsonResponse
    {
        return $this->run(fn () => $this->listResponse(
            SupportChannel::orderBy('sort_order')->get()->map(fn (SupportChannel $c) => [
                'key' => $c->key, 'labelAr' => $c->label_ar, 'labelEn' => $c->label_en, 'value' => $c->value,
                'hoursAr' => $c->hours_ar, 'hoursEn' => $c->hours_en, 'isAvailable' => (bool) $c->is_available, 'icon' => $c->icon,
            ])->all()
        ));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'category' => 'required|in:subscription,technical,general_inquiry,feature_request,billing,other',
                'subject' => 'sometimes|string|max:200', 'body' => 'required|string|min:10',
                'priority' => 'sometimes|in:low,normal,high,urgent', 'attachments' => 'sometimes|array',
            ]);
            $ticket = SupportTicket::create([
                'public_id' => 'TCK-'.str_pad((string) (SupportTicket::withoutGlobalScopes()->count() + 1), 3, '0', STR_PAD_LEFT),
                'company_id' => $request->user()->company_id, 'opened_by_id' => $request->user()->id,
                'category' => $data['category'], 'subject' => $data['subject'] ?? $this->subjectFor($data['category']),
                'body' => $data['body'], 'priority' => $data['priority'] ?? 'normal', 'status' => 'open',
            ]);

            return $this->created($this->present($ticket));
        });
    }

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $user = $request->user();
            $q = SupportTicket::where('company_id', $user->company_id);
            if (! $user->hasAsabRole('company-admin')) {
                $q->where('opened_by_id', $user->id);
            }
            if ($status = $request->query('status')) {
                $q->whereIn('status', explode(',', $status));
            }
            if ($cat = $request->query('category')) {
                $q->where('category', $cat);
            }
            $page = $q->orderByDesc('created_at')->paginate(min((int) $request->query('pageSize', 20), 100));

            return $this->paginated($page, collect($page->items())->map([$this, 'present'])->all());
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $ticket = $this->findOwned($request, $id);

            return $this->ok(array_merge($this->present($ticket), [
                'messages' => $ticket->messages->map(fn (TicketMessage $m) => [
                    'id' => $m->id, 'authorId' => $m->author_id, 'authorType' => $m->author_type,
                    'body' => $m->body, 'createdAt' => optional($m->created_at)->toIso8601String(),
                ])->all(),
            ]));
        });
    }

    public function reply(Request $request, \Modules\Admin\Services\RealtimeBroadcaster $rt, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $rt, $id) {
            $ticket = $this->findOwned($request, $id);
            $data = $request->validate(['body' => 'required|string|min:1', 'attachments' => 'sometimes|array']);
            $msg = TicketMessage::create([
                'ticket_id' => $ticket->id, 'author_id' => $request->user()->id, 'author_type' => 'customer',
                'body' => $data['body'], 'created_at' => now(),
            ]);
            $ticket->update(['status' => $ticket->status === 'waiting_customer' ? 'open' : $ticket->status]);

            // Notify the ticket opener of a new reply (spec §8) — unless they wrote it themselves.
            if ($ticket->opened_by_id && $ticket->opened_by_id !== $request->user()->id) {
                $rt->supportTicketReplied($msg, $ticket->opened_by_id);
            }

            return $this->created(['id' => $msg->id, 'ticketId' => $ticket->id, 'body' => $msg->body]);
        });
    }

    public function close(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $ticket = $this->findOwned($request, $id);
            $ticket->update(['status' => 'closed', 'closed_at' => now()]);

            return $this->ok($this->present($ticket->fresh()));
        });
    }

    public function addAttachment(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $ticket = $this->findOwned($request, $id);
            $request->validate(['file' => 'required|file|max:5120']);
            $file = $request->file('file');
            $key = $file->store('ticket-attachments', 'public');
            $att = \Modules\Admin\Models\TicketAttachment::create([
                'ticket_id' => $ticket->id, 'filename' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(), 'storage_key' => $key, 'uploaded_by_id' => $request->user()->id, 'uploaded_at' => now(),
            ]);

            return $this->created(['id' => $att->id, 'filename' => $att->filename, 'size' => $att->size]);
        });
    }

    private function findOwned(Request $request, string $id): SupportTicket
    {
        $user = $request->user();
        $ticket = SupportTicket::where('company_id', $user->company_id)->findOrFail($id);
        if (! $user->hasAsabRole('company-admin') && $ticket->opened_by_id !== $user->id) {
            throw new AsabException('WRONG_ROLE', 'Not your ticket', 'ليست تذكرتك', 403);
        }

        return $ticket;
    }

    private function present(SupportTicket $t): array
    {
        return [
            'id' => $t->id, 'publicId' => $t->public_id, 'category' => $t->category, 'subject' => $t->subject,
            'body' => $t->body, 'priority' => $t->priority, 'status' => $t->status,
            'createdAt' => optional($t->created_at)->toIso8601String(), 'resolvedAt' => optional($t->resolved_at)->toIso8601String(),
        ];
    }

    private function subjectFor(string $category): string
    {
        return [
            'subscription' => 'استفسار عن الاشتراك', 'technical' => 'مشكلة تقنية', 'general_inquiry' => 'استفسار عام',
            'feature_request' => 'طلب ميزة', 'billing' => 'استفسار عن الفوترة', 'other' => 'أخرى',
        ][$category] ?? 'تذكرة دعم';
    }
}
