<?php

namespace Modules\Admin\Http\Controllers\Company;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\BillingAddress;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\PaymentMethod;
use Modules\Admin\Models\PaymentTransaction;
use Modules\Admin\Services\BillingService;
use Modules\Admin\Services\SubscriptionService;

/**
 * Billing & payments (COMPANY_DASHBOARD_API_SPEC.md §5.1.6).
 */
class BillingController extends AsabController
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $companyId = $request->user()->company_id;
            $sub = $this->subscriptions->current($companyId);
            $totalPaid = (int) BillingInvoice::where('company_id', $companyId)->where('status', 'paid')->sum('amount_paid');
            $default = PaymentMethod::where('company_id', $companyId)->where('is_default', true)->first();

            return $this->ok([
                'totalPaidHalalas' => $totalPaid,
                'nextInvoice' => [
                    'issueDate' => optional($sub->current_period_end)->toIso8601String(),
                    'amountHalalas' => $this->subscriptions->priceFor($sub->plan, $sub->billing_cycle),
                ],
                'defaultPaymentMethod' => $default ? ['id' => $default->id, 'type' => $default->type, 'brand' => $default->brand, 'last4' => $default->last4] : null,
            ]);
        });
    }

    public function invoices(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = BillingInvoice::where('company_id', $request->user()->company_id);
            if ($status = $request->query('status')) {
                $q->whereIn('status', explode(',', $status));
            }
            if ($year = $request->query('year')) {
                $q->whereYear('issue_date', $year);
            }
            $page = $q->orderByDesc('issue_date')->paginate(min((int) $request->query('pageSize', 20), 100));

            $items = collect($page->items())->map(fn (BillingInvoice $i) => [
                'id' => $i->id, 'publicId' => $i->public_id, 'issueDate' => optional($i->issue_date)->toIso8601String(),
                'issueDateLabel' => optional($i->issue_date)->translatedFormat('d F Y'), 'dueDate' => optional($i->due_date)->toIso8601String(),
                'totalHalalas' => $i->total, 'status' => $i->status,
                'paymentMethod' => $i->payment_method_id ? $this->pmBrief($i->payment_method_id) : null,
                'pdfUrl' => "/api/v1/company/me/billing/invoices/{$i->id}/pdf",
            ])->all();

            return $this->paginated($page, $items);
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $inv = BillingInvoice::where('company_id', $request->user()->company_id)->with('lines')->findOrFail($id);
            $txns = PaymentTransaction::where('invoice_id', $inv->id)->orderByDesc('created_at')->get();

            return $this->ok([
                'id' => $inv->id, 'publicId' => $inv->public_id, 'status' => $inv->status,
                'issueDate' => optional($inv->issue_date)->toIso8601String(), 'dueDate' => optional($inv->due_date)->toIso8601String(),
                'periodStart' => optional($inv->period_start)->toIso8601String(), 'periodEnd' => optional($inv->period_end)->toIso8601String(),
                'subtotal' => $inv->subtotal, 'vatRate' => $inv->vat_rate, 'vatAmount' => $inv->vat_amount,
                'discount' => $inv->discount, 'total' => $inv->total, 'amountPaid' => $inv->amount_paid, 'amountDue' => $inv->amount_due,
                'currency' => $inv->currency, 'billingAddressSnapshot' => $inv->billing_address_snapshot,
                'lines' => $inv->lines->map(fn ($l) => ['description' => $l->description, 'quantity' => $l->quantity, 'unitPrice' => $l->unit_price, 'amount' => $l->amount, 'lineType' => $l->line_type])->all(),
                'payments' => $txns->map(fn ($t) => ['id' => $t->id, 'amount' => $t->amount, 'status' => $t->status, 'processedAt' => optional($t->processed_at)->toIso8601String()])->all(),
            ]);
        });
    }

    public function pdf(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $inv = BillingInvoice::where('company_id', $request->user()->company_id)->findOrFail($id);

            // PDF rendering deferred to an async generator; expose the data + a stable URL.
            return $this->ok(['publicId' => $inv->public_id, 'downloadUrl' => null, 'note' => 'PDF generation deferred to async renderer']);
        });
    }

    public function export(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok(['jobId' => 'job_'.strtoupper(bin2hex(random_bytes(6)))], 202));
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->listResponse(
            PaymentMethod::where('company_id', $request->user()->company_id)->orderByDesc('is_default')->get()
                ->map([$this, 'presentPm'])->all()
        ));
    }

    public function addPaymentMethod(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'providerName' => 'required|in:stripe,tap,hyperpay,moyasar',
                'providerToken' => 'required|string',
                'type' => 'required|in:card,mada,apple_pay',
                'setAsDefault' => 'sometimes|boolean',
            ]);
            $pm = $this->billing->addMethod($request->user()->company_id, $data, $request->user()->id);

            return $this->created($this->presentPm($pm));
        });
    }

    public function setDefaultPaymentMethod(Request $request, string $id): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->presentPm($this->billing->setDefaultMethod($request->user()->company_id, $id))));
    }

    public function deletePaymentMethod(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $this->billing->deleteMethod($request->user()->company_id, $id);

            return $this->noContent();
        });
    }

    public function address(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $addr = BillingAddress::where('company_id', $request->user()->company_id)->where('is_default', true)->first();

            return $this->ok($addr ? $this->presentAddr($addr) : null);
        });
    }

    public function updateAddress(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'legalName' => 'required|string|max:200', 'taxId' => 'nullable|string|max:32', 'crNumber' => 'nullable|string|max:32',
                'addressLine1' => 'required|string|max:255', 'addressLine2' => 'nullable|string|max:255', 'city' => 'required|string|max:80',
                'region' => 'nullable|string|max:80', 'postalCode' => 'nullable|string|max:16', 'country' => 'sometimes|string|size:2',
                'contactEmail' => 'nullable|email', 'contactPhone' => 'nullable|string|max:32',
            ]);
            $addr = BillingAddress::updateOrCreate(
                ['company_id' => $request->user()->company_id, 'is_default' => true],
                [
                    'legal_name' => $data['legalName'], 'tax_id' => $data['taxId'] ?? null, 'cr_number' => $data['crNumber'] ?? null,
                    'address_line1' => $data['addressLine1'], 'address_line2' => $data['addressLine2'] ?? null, 'city' => $data['city'],
                    'region' => $data['region'] ?? null, 'postal_code' => $data['postalCode'] ?? null, 'country' => $data['country'] ?? 'SA',
                    'contact_email' => $data['contactEmail'] ?? null, 'contact_phone' => $data['contactPhone'] ?? null,
                ],
            );

            return $this->ok($this->presentAddr($addr));
        });
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $data = $request->validate(['paymentMethodId' => 'sometimes|string']);
            $r = $this->billing->pay($request->user()->company_id, $id, $data['paymentMethodId'] ?? null);

            return $this->ok([
                'invoice' => ['id' => $r['invoice']->id, 'publicId' => $r['invoice']->public_id, 'status' => $r['invoice']->status, 'amountDue' => $r['invoice']->amount_due],
                'paymentTransaction' => ['id' => $r['transaction']->id, 'status' => $r['transaction']->status, 'amount' => $r['transaction']->amount],
            ]);
        });
    }

    public function presentPm(PaymentMethod $pm): array
    {
        return [
            'id' => $pm->id, 'type' => $pm->type, 'brand' => $pm->brand, 'last4' => $pm->last4,
            'expMonth' => $pm->exp_month, 'expYear' => $pm->exp_year, 'holderName' => $pm->holder_name,
            'providerName' => $pm->provider_name, 'isDefault' => (bool) $pm->is_default, 'status' => $pm->status,
        ];
    }

    private function presentAddr(BillingAddress $a): array
    {
        return [
            'id' => $a->id, 'legalName' => $a->legal_name, 'taxId' => $a->tax_id, 'crNumber' => $a->cr_number,
            'addressLine1' => $a->address_line1, 'addressLine2' => $a->address_line2, 'city' => $a->city,
            'region' => $a->region, 'postalCode' => $a->postal_code, 'country' => $a->country,
            'contactEmail' => $a->contact_email, 'contactPhone' => $a->contact_phone,
        ];
    }

    private function pmBrief(string $pmId): ?array
    {
        $pm = PaymentMethod::find($pmId);

        return $pm ? ['brand' => $pm->brand, 'last4' => $pm->last4] : null;
    }
}
