<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\PaymentTransaction;
use Modules\Admin\Models\WebhookEvent;

/**
 * Payment-gateway webhooks (COMPANY_DASHBOARD_API_SPEC.md §6). Public, no auth —
 * verified by provider signature. Idempotent via webhook_events.event_id.
 * Processing is synchronous-mock here (a real connector would queue it).
 */
class WebhookController extends AsabController
{
    public function handle(Request $request, string $provider): JsonResponse
    {
        $payload = $request->all();
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? ($payload['event'] ?? null);

        // event_id + event_type are mandatory: idempotency and routing depend on them.
        if (! $eventId || ! $eventType) {
            return $this->fail('INVALID_WEBHOOK', 'Missing event id or type', 'حدث Webhook غير صالح', [], 400);
        }

        $signature = $request->header('Stripe-Signature') ?? $request->header('X-Signature');
        [$verified, $secretConfigured] = $this->verifySignature($provider, $request, $signature);

        // In production a provider secret is configured — reject unverified events.
        if ($secretConfigured && ! $verified) {
            return $this->fail('INVALID_SIGNATURE', 'Webhook signature verification failed', 'فشل التحقق من توقيع Webhook', [], 401);
        }

        // Idempotency: ignore a replayed event.
        if (WebhookEvent::where('event_id', $eventId)->exists()) {
            return $this->ok(['received' => true, 'duplicate' => true]);
        }

        $event = WebhookEvent::create([
            'provider' => $provider, 'event_id' => $eventId, 'event_type' => $eventType, 'payload' => $payload,
            'signature' => $signature, 'signature_verified' => $verified, 'received_at' => now(),
        ]);

        try {
            $this->process($eventType, $payload);
            $event->update(['processed_at' => now()]);
        } catch (\Throwable $e) {
            $event->update(['processing_error' => $e->getMessage()]);
        }

        return $this->ok(['received' => true]);
    }

    /**
     * Verify the provider HMAC signature when a secret is configured.
     *
     * @return array{0: bool, 1: bool} [verified, secretConfigured]
     */
    private function verifySignature(string $provider, Request $request, ?string $signature): array
    {
        $secret = config("services.{$provider}.webhook_secret");
        if (! $secret) {
            // No secret configured (dev): cannot verify — record as unverified but allow processing.
            return [false, false];
        }
        if (! $signature) {
            return [false, true];
        }
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return [hash_equals($expected, $signature) || str_contains($signature, $expected), true];
    }

    private function process(string $eventType, array $payload): void
    {
        $invoicePublicId = $payload['data']['invoicePublicId'] ?? ($payload['invoicePublicId'] ?? null);
        if (! $invoicePublicId) {
            return;
        }
        $invoice = BillingInvoice::withoutGlobalScopes()->where('public_id', $invoicePublicId)->first();
        if (! $invoice) {
            return;
        }

        if (str_contains($eventType, 'payment_succeeded') || str_contains($eventType, 'paid')) {
            PaymentTransaction::create([
                'invoice_id' => $invoice->id, 'amount' => $invoice->amount_due, 'currency' => $invoice->currency,
                'status' => 'succeeded', 'provider_txn_id' => $payload['data']['txnId'] ?? null,
                'provider_response' => $payload, 'processed_at' => now(), 'created_at' => now(),
            ]);
            $invoice->update(['amount_paid' => $invoice->total, 'amount_due' => 0, 'status' => 'paid', 'paid_at' => now()]);
        } elseif (str_contains($eventType, 'payment_failed') || str_contains($eventType, 'failed')) {
            PaymentTransaction::create([
                'invoice_id' => $invoice->id, 'amount' => $invoice->amount_due, 'currency' => $invoice->currency,
                'status' => 'failed', 'failure_code' => $payload['data']['failureCode'] ?? 'declined',
                'failure_message' => $payload['data']['failureMessage'] ?? null, 'provider_response' => $payload,
                'processed_at' => now(), 'created_at' => now(),
            ]);
            $invoice->update(['status' => 'overdue']);
        }
    }
}
