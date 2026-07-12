<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\Operation;
use Modules\Admin\Support\SalesChannels;

/**
 * SRS ACC-1.4 «جدول المقارنة والتسوية» — reconciles a sales operation channel by
 * channel (entered vs expected) against the branch's locked total.
 *
 * The grand total («إجمالي المبيعات — من رفع مدير الفرع — مقفل») is `op->amount`
 * and is never writable here; only the per-channel collection figures are.
 */
class SalesReconciliationService
{
    /**
     * Persist a reconciliation edit and re-derive the operation's match badge.
     *
     * @param  array<string, mixed>  $input  validated request body
     * @return array<string, mixed> the computed reconciliation block
     */
    public function save(Operation $op, array $input): array
    {
        $channels = $this->normalize($input, $op);
        $expected = $this->expectedTotal($op);
        $collected = array_sum(array_column($channels, 'actualAmountHalalas'));
        $variance = $collected - $expected;

        return DB::transaction(function () use ($op, $input, $channels, $expected, $collected, $variance) {
            $payload = $op->payload ?? [];
            $reconciliation = array_merge($payload['reconciliation'] ?? [], $input, [
                'channels' => $channels,
                'expectedTotalHalalas' => $expected,
                'totalCollectionHalalas' => $collected,
                'varianceHalalas' => $variance,
            ]);

            $payload['reconciliation'] = $reconciliation;
            // Mirrored at the payload root: the variance-allocation service and
            // several legacy readers look for it there.
            $payload['varianceHalalas'] = $variance;

            $op->update([
                'payload' => $payload,
                'match' => $variance === 0 ? 'exact' : 'diff',
                'diff_note' => $this->diffNote($variance),
            ]);

            return $this->present($op->fresh());
        });
    }

    /**
     * The reconciliation table the detail screen renders: one row per channel
     * with its Arabic label, the entered/expected pair, the difference and a
     * per-row status, plus the «المجموع الكلي» footer.
     *
     * @return array<string, mixed>|null null when the operation was never reconciled
     */
    public function present(Operation $op): ?array
    {
        $stored = $op->payload['reconciliation'] ?? null;
        if (! is_array($stored) || ! isset($stored['channels'])) {
            return null;
        }

        $rows = [];
        foreach ($stored['channels'] as $channel) {
            $actual = (int) ($channel['actualAmountHalalas'] ?? 0);
            $pos = $channel['posAmountHalalas'] ?? null;
            $diff = $pos === null ? null : $actual - (int) $pos;

            $rows[] = [
                'key' => $channel['key'],
                'labelAr' => SalesChannels::labelAr($channel['key']),
                'icon' => SalesChannels::icon($channel['key']),
                'group' => SalesChannels::CHANNELS[$channel['key']]['group'] ?? 'core',
                'posAmountHalalas' => $pos === null ? null : (int) $pos,
                'actualAmountHalalas' => $actual,
                'diffHalalas' => $diff,
                'status' => ($diff ?? 0) === 0 ? 'exact' : 'diff',
                'statusLabelAr' => ($diff ?? 0) === 0 ? 'متطابق' : 'فرق',
            ];
        }

        $variance = (int) ($stored['varianceHalalas'] ?? 0);

        return [
            'channels' => $rows,
            'totals' => [
                'expectedTotalHalalas' => (int) ($stored['expectedTotalHalalas'] ?? $this->expectedTotal($op)),
                'totalCollectionHalalas' => (int) ($stored['totalCollectionHalalas'] ?? 0),
                'varianceHalalas' => $variance,
                'status' => $variance === 0 ? 'exact' : 'diff',
                'statusLabelAr' => $variance === 0 ? 'مطابق' : 'فرق',
            ],
            'varianceReason' => $stored['varianceReason'] ?? null,
            'isLocked' => $op->status === Operation::STATUS_FINAL,
        ];
    }

    /**
     * The six columns of the ACC-1.3 matching table, for list rows.
     *
     * @return array<string, int|null>|null
     */
    public function breakdown(Operation $op): ?array
    {
        $stored = $op->payload['reconciliation'] ?? null;
        if (! is_array($stored) || ! isset($stored['channels'])) {
            return null;
        }

        $sum = function (array $keys) use ($stored): int {
            $total = 0;
            foreach ($stored['channels'] as $channel) {
                if (in_array($channel['key'], $keys, true)) {
                    $total += (int) ($channel['actualAmountHalalas'] ?? 0);
                }
            }

            return $total;
        };

        return [
            'cashHalalas' => $sum(['cash', 'pos']),
            'cardHalalas' => $sum(['bank']),
            'appsHalalas' => $sum(SalesChannels::deliveryKeys()),
            'totalSalesHalalas' => (int) ($stored['expectedTotalHalalas'] ?? $this->expectedTotal($op)),
            'collectedHalalas' => (int) ($stored['totalCollectionHalalas'] ?? 0),
            'varianceHalalas' => (int) ($stored['varianceHalalas'] ?? 0),
        ];
    }

    /**
     * Fold the request body — canonical `channels[]` or the legacy
     * cash/bank/deliveryApps triple — onto canonical channel rows.
     *
     * @return array<int, array{key:string, posAmountHalalas:?int, actualAmountHalalas:int}>
     */
    private function normalize(array $input, Operation $op): array
    {
        $existing = [];
        foreach ($op->payload['reconciliation']['channels'] ?? [] as $channel) {
            $existing[$channel['key']] = $channel;
        }

        $rows = [];
        $put = function (string $keyOrName, int $actual, ?int $pos = null) use (&$rows, $existing) {
            $key = SalesChannels::resolve($keyOrName);
            if ($key === null) {
                throw new AsabException(
                    'UNKNOWN_SALES_CHANNEL',
                    "Unknown sales channel: {$keyOrName}",
                    'قناة تحصيل غير معروفة: '.$keyOrName,
                    422,
                    ['allowed' => SalesChannels::keys()],
                );
            }
            // An omitted expected amount keeps whatever the branch reported.
            $rows[$key] = [
                'key' => $key,
                'posAmountHalalas' => $pos ?? ($existing[$key]['posAmountHalalas'] ?? null),
                'actualAmountHalalas' => $actual,
            ];
        };

        if (isset($input['channels'])) {
            foreach ($input['channels'] as $channel) {
                $put(
                    (string) $channel['key'],
                    (int) ($channel['actualAmountHalalas'] ?? 0),
                    isset($channel['posAmountHalalas']) ? (int) $channel['posAmountHalalas'] : null,
                );
            }

            return array_values($rows);
        }

        // Legacy body: cashAmount/cashHalalas + bankAmount/bankHalalas + deliveryApps[].
        if (isset($input['cashHalalas']) || isset($input['cashAmount'])) {
            $put('cash', (int) ($input['cashHalalas'] ?? $input['cashAmount']));
        }
        if (isset($input['bankHalalas']) || isset($input['bankAmount'])) {
            $put('bank', (int) ($input['bankHalalas'] ?? $input['bankAmount']));
        }
        foreach ($input['deliveryApps'] ?? [] as $app) {
            $put((string) ($app['name'] ?? ''), (int) ($app['amountHalalas'] ?? 0));
        }

        return array_values($rows);
    }

    /** The locked branch total: never client-supplied. */
    private function expectedTotal(Operation $op): int
    {
        return (int) ($op->amount
            ?: ($op->payload['expectedHalalas']
                ?? ($op->payload['totalSalesHalalas']
                    ?? ($op->payload['totalHalalas'] ?? 0))));
    }

    private function diffNote(int $variance): ?string
    {
        if ($variance === 0) {
            return null;
        }

        $sar = number_format(abs($variance) / 100, 2);

        return $variance < 0
            ? "نقص في التحصيل: {$sar} ر.س"
            : "زيادة في التحصيل: {$sar} ر.س";
    }
}
