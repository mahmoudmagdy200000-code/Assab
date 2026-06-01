<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\PaymentMethod;
use Modules\Admin\Models\PaymentTransaction;

/**
 * Billing operations (COMPANY_DASHBOARD_API_SPEC.md §5.1.6). Charges are mocked
 * (no live PSP); the records mirror a real gateway flow for the frontend.
 */
class BillingService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Pay an open/overdue invoice. Mock-charges the chosen (or default) method.
     *
     * @return array{invoice: BillingInvoice, transaction: PaymentTransaction}
     */
    public function pay(string $companyId, string $invoiceId, ?string $paymentMethodId): array
    {
        $invoice = BillingInvoice::where('company_id', $companyId)
            ->where(fn ($q) => $q->where('id', $invoiceId)->orWhere('public_id', $invoiceId))->firstOrFail();

        if (in_array($invoice->status, ['paid', 'refunded', 'void'], true)) {
            throw new AsabException('ALREADY_PAID', 'Invoice is not payable', 'الفاتورة مدفوعة أو غير قابلة للدفع', 409, ['status' => $invoice->status]);
        }

        $method = $this->resolveMethod($companyId, $paymentMethodId);

        return DB::transaction(function () use ($invoice, $method) {
            $txn = PaymentTransaction::create([
                'invoice_id' => $invoice->id, 'payment_method_id' => $method?->id, 'amount' => $invoice->amount_due,
                'currency' => $invoice->currency, 'status' => 'succeeded', 'provider_txn_id' => 'mock_'.strtoupper(bin2hex(random_bytes(6))),
                'provider_response' => ['mock' => true], 'processed_at' => now(), 'created_at' => now(),
            ]);
            $invoice->update([
                'amount_paid' => $invoice->total, 'amount_due' => 0, 'status' => 'paid',
                'paid_at' => now(), 'payment_method_id' => $method?->id,
            ]);
            $this->notifications->pushToRole($invoice->company_id, 'company-admin', 'invoice.paid', 'تم سداد الفاتورة', $invoice->public_id);

            return ['invoice' => $invoice->fresh(), 'transaction' => $txn];
        });
    }

    public function addMethod(string $companyId, array $data, ?string $actorId): PaymentMethod
    {
        return DB::transaction(function () use ($companyId, $data, $actorId) {
            $setDefault = $data['setAsDefault'] ?? false;
            if ($setDefault) {
                PaymentMethod::where('company_id', $companyId)->update(['is_default' => false]);
            }

            // Mock gateway tokenization → derive last4/brand.
            return PaymentMethod::create([
                'company_id' => $companyId, 'type' => $data['type'], 'brand' => $data['type'] === 'mada' ? 'mada' : 'visa',
                'last4' => substr((string) (1000 + random_int(0, 8999)), -4), 'provider_token' => $data['providerToken'],
                'provider_name' => $data['providerName'], 'is_default' => $setDefault || PaymentMethod::where('company_id', $companyId)->count() === 0,
                'status' => 'active', 'added_by_id' => $actorId,
            ]);
        });
    }

    public function setDefaultMethod(string $companyId, string $id): PaymentMethod
    {
        $pm = PaymentMethod::where('company_id', $companyId)->findOrFail($id);
        DB::transaction(function () use ($companyId, $pm) {
            PaymentMethod::where('company_id', $companyId)->update(['is_default' => false]);
            $pm->update(['is_default' => true]);
        });

        return $pm->fresh();
    }

    public function deleteMethod(string $companyId, string $id): void
    {
        $pm = PaymentMethod::where('company_id', $companyId)->findOrFail($id);
        if ($pm->is_default && PaymentMethod::where('company_id', $companyId)->count() > 1) {
            throw new AsabException('IS_DEFAULT_METHOD', 'Set another method as default first', 'عيّن طريقة دفع افتراضية أخرى أولاً', 409);
        }
        $pm->delete();
    }

    private function resolveMethod(string $companyId, ?string $paymentMethodId): ?PaymentMethod
    {
        if ($paymentMethodId) {
            return PaymentMethod::where('company_id', $companyId)->findOrFail($paymentMethodId);
        }

        return PaymentMethod::where('company_id', $companyId)->where('is_default', true)->first();
    }
}
