<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Webhook;
use Modules\Admin\Models\WebhookDelivery;
use Modules\Admin\Services\WebhookService;

/**
 * Tenant webhook management (FE completion request §3.5). The signing secret is
 * returned once, on creation.
 */
class TenantWebhookController extends AsabController
{
    /** Events a tenant may subscribe to. */
    private const EVENTS = ['operation.created', 'operation.status_changed', 'invoice.created', 'invoice.paid', 'subscription.updated'];

    public function __construct(private readonly WebhookService $webhooks) {}

    /** GET /company/me/webhooks */
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $rows = Webhook::where('company_id', $request->user()->company_id)->orderByDesc('created_at')->get();

            return $this->listResponse($rows->map(fn (Webhook $w) => $this->webhooks->present($w))->all());
        });
    }

    /** POST /company/me/webhooks → secret returned ONCE. */
    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'url' => 'required|url|max:1000',
                'events' => 'required|array|min:1',
                'events.*' => 'in:'.implode(',', self::EVENTS),
                'description' => 'sometimes|nullable|string|max:255',
            ]);

            $result = $this->webhooks->create(
                $request->user()->company_id, $data['url'], $data['events'], $data['description'] ?? null, $request->user()->id,
            );
            $w = $result['model'];

            return $this->created([
                'id' => $w->id, 'url' => $w->url, 'events' => $w->events,
                'secret' => $result['secret'], 'isActive' => (bool) $w->is_active,
            ]);
        });
    }

    /** PATCH /company/me/webhooks/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $webhook = $this->find($request, $id);
            $data = $request->validate([
                'url' => 'sometimes|url|max:1000',
                'events' => 'sometimes|array|min:1',
                'events.*' => 'in:'.implode(',', self::EVENTS),
                'isActive' => 'sometimes|boolean',
            ]);
            $webhook->update(array_filter([
                'url' => $data['url'] ?? null,
                'events' => $data['events'] ?? null,
                'is_active' => array_key_exists('isActive', $data) ? $data['isActive'] : null,
            ], fn ($v) => $v !== null));

            return $this->ok($this->webhooks->present($webhook->fresh()));
        });
    }

    /** DELETE /company/me/webhooks/{id} → 204 */
    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $this->find($request, $id)->delete();

            return $this->noContent();
        });
    }

    /** POST /company/me/webhooks/{id}/test */
    public function test(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $webhook = $this->find($request, $id);
            $data = $request->validate(['event' => 'sometimes|in:'.implode(',', self::EVENTS)]);
            $event = $data['event'] ?? 'operation.created';

            $delivery = $this->webhooks->deliver($webhook, $event, ['test' => true, 'sentAt' => now()->toIso8601String()]);
            $ok = $delivery->status_code !== null && $delivery->status_code >= 200 && $delivery->status_code < 300;

            return $this->ok([
                'delivered' => $ok,
                'statusCode' => $delivery->status_code,
                'error' => $delivery->error,
            ]);
        });
    }

    /** GET /company/me/webhooks/{id}/deliveries?page=&pageSize= */
    public function deliveries(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $webhook = $this->find($request, $id);
            $perPage = min((int) $request->query('pageSize', 20), 100);
            $p = WebhookDelivery::where('webhook_id', $webhook->id)
                ->orderByDesc('attempted_at')
                ->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

            return $this->paginated($p, array_map(fn (WebhookDelivery $d) => [
                'id' => $d->id,
                'event' => $d->event,
                'statusCode' => $d->status_code,
                'error' => $d->error,
                'response' => $d->response,
                'attemptedAt' => optional($d->attempted_at)->toIso8601String(),
            ], $p->items()));
        });
    }

    private function find(Request $request, string $id): Webhook
    {
        return Webhook::where('company_id', $request->user()->company_id)->findOrFail($id);
    }
}
