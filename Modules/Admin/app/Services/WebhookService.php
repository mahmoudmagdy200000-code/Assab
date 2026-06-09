<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Models\Webhook;
use Modules\Admin\Models\WebhookDelivery;

/**
 * Tenant outbound webhooks (FE completion request §3.5). Deliveries are HMAC-SHA256
 * signed with the per-webhook secret (shown once at creation, encrypted at rest).
 */
class WebhookService
{
    /**
     * @return array{model: Webhook, secret: string}
     */
    public function create(string $companyId, string $url, array $events, ?string $description, ?string $createdById): array
    {
        $secret = 'whsec_'.Str::random(40);

        $model = Webhook::create([
            'company_id' => $companyId,
            'url' => $url,
            'events' => array_values($events),
            'secret' => $secret,
            'secret_prefix' => substr($secret, 0, 12),
            'is_active' => true,
            'description' => $description,
            'created_by_id' => $createdById,
        ]);

        return ['model' => $model, 'secret' => $secret];
    }

    /** Fan a single event out to all matching active webhooks for a company. */
    public function dispatchEvent(string $companyId, string $event, array $payload): void
    {
        Webhook::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Webhook $w) => in_array($event, $w->events ?? [], true))
            ->each(fn (Webhook $w) => $this->deliver($w, $event, $payload));
    }

    /** Deliver one event to one webhook, recording the attempt. */
    public function deliver(Webhook $webhook, string $event, array $payload): WebhookDelivery
    {
        $body = json_encode(['event' => $event, 'data' => $payload], JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', $body, $webhook->secret);

        $statusCode = null;
        $responseText = null;
        $error = null;

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-ASAB-Signature' => $signature, 'X-ASAB-Event' => $event])
                ->withBody($body, 'application/json')
                ->post($webhook->url);
            $statusCode = $response->status();
            $responseText = Str::limit($response->body(), 2000);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            Log::warning('Webhook delivery failed: '.$e->getMessage());
        }

        $ok = $statusCode !== null && $statusCode >= 200 && $statusCode < 300;
        $webhook->forceFill([
            'last_triggered_at' => now(),
            'failure_count' => $ok ? 0 : $webhook->failure_count + 1,
        ])->save();

        return WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => $event,
            'payload' => $payload,
            'status_code' => $statusCode,
            'response' => $responseText,
            'error' => $error,
            'attempted_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function present(Webhook $w): array
    {
        return [
            'id' => $w->id,
            'url' => $w->url,
            'events' => $w->events ?? [],
            'secret_prefix' => $w->secret_prefix,
            'isActive' => (bool) $w->is_active,
            'description' => $w->description,
            'lastTriggeredAt' => optional($w->last_triggered_at)->toIso8601String(),
            'failureCount' => $w->failure_count,
        ];
    }
}
