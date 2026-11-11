<?php

namespace Modules\Admin\Transformers;

/**
 * Transformer for Shift Custody Statement responses.
 */
class ShiftCustodyStatementTransformer
{
    /**
     * Transform statement data into canonical JSON envelope.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function transform(array $data): array
    {
        return [
            'unit' => $data['unit'] ?? 'halala',
            'identity_link_state' => $data['identity_link_state'] ?? 'unlinked',
            'identity' => [
                'employee_id' => (string) ($data['identity']['employee_id'] ?? ''),
                'name' => (string) ($data['identity']['name'] ?? ''),
                'role' => (string) ($data['identity']['role'] ?? ''),
                'linked_type' => $data['identity']['linked_type'] ?? null,
                'linked_id' => $data['identity']['linked_id'] ?? null,
                'branch_id' => $data['identity']['branch_id'] ?? null,
                'branch_name' => $data['identity']['branch_name'] ?? null,
            ],
            'period' => [
                'from' => $data['period']['from'] ?? null,
                'to' => $data['period']['to'] ?? null,
            ],
            'balances' => [
                'is_available' => (bool) ($data['balances']['is_available'] ?? false),
                'opening_balance' => $data['balances']['opening_balance'] ?? null,
                'closing_balance' => $data['balances']['closing_balance'] ?? null,
                'total_cash_in' => $data['balances']['total_cash_in'] ?? null,
                'total_cash_out' => $data['balances']['total_cash_out'] ?? null,
                'net_period_change' => $data['balances']['net_period_change'] ?? null,
            ],
            'entries' => array_map(fn (array $e) => [
                'source_type' => (string) ($e['source_type'] ?? ''),
                'source_id' => (string) ($e['source_id'] ?? ''),
                'event_at' => (string) ($e['event_at'] ?? ''),
                'business_date' => $e['business_date'] ?? null,
                'branch' => [
                    'id' => $e['branch']['id'] ?? null,
                    'name' => $e['branch']['name'] ?? null,
                ],
                'transaction_type' => (string) ($e['transaction_type'] ?? ''),
                'classification' => (string) ($e['classification'] ?? 'other'),
                'label_ar' => (string) ($e['label_ar'] ?? ''),
                'label_en' => (string) ($e['label_en'] ?? ''),
                'source_shift_id' => $e['source_shift_id'] ?? null,
                'receiving_shift_id' => $e['receiving_shift_id'] ?? null,
                'receipt_id' => $e['receipt_id'] ?? null,
                'counterparty' => [
                    'type' => $e['counterparty']['type'] ?? null,
                    'id' => $e['counterparty']['id'] ?? null,
                    'name' => $e['counterparty']['name'] ?? null,
                ],
                'cash_in' => (int) ($e['cash_in'] ?? 0),
                'cash_out' => (int) ($e['cash_out'] ?? 0),
                'running_balance' => (int) ($e['running_balance'] ?? 0),
                'confirmation_state' => (string) ($e['confirmation_state'] ?? 'confirmed'),
                'lineage_key' => $e['lineage_key'] ?? null,
                'reversal_id' => $e['reversal_id'] ?? null,
            ], $data['entries'] ?? []),
            'pending' => array_map(fn (array $p) => [
                'request_type' => (string) ($p['request_type'] ?? ''),
                'request_id' => (string) ($p['request_id'] ?? ''),
                'created_at' => (string) ($p['created_at'] ?? ''),
                'business_date' => $p['business_date'] ?? null,
                'amount' => (int) ($p['amount'] ?? 0),
                'counterparty' => [
                    'type' => $p['counterparty']['type'] ?? null,
                    'id' => $p['counterparty']['id'] ?? null,
                    'name' => $p['counterparty']['name'] ?? null,
                ],
                'source_shift_id' => $p['source_shift_id'] ?? null,
                'status' => (string) ($p['status'] ?? 'pending'),
            ], $data['pending'] ?? []),
            'pagination' => [
                'page' => (int) ($data['pagination']['page'] ?? 1),
                'pageSize' => (int) ($data['pagination']['pageSize'] ?? 20),
                'total' => (int) ($data['pagination']['total'] ?? 0),
                'totalPages' => (int) ($data['pagination']['totalPages'] ?? 0),
            ],
        ];
    }
}
