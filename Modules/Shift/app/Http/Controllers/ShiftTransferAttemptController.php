<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\ShiftTransferAttemptService;

class ShiftTransferAttemptController extends Controller
{
    public function __construct(private ShiftTransferAttemptService $attempts) {}

    public function present(Request $request, string $type, string $id)
    {
        $data = $request->validate(['presented_halalas' => 'required|integer|min:0|max:99999999999', 'idempotency_key' => 'required|string|max:100']);

        return response()->json(['data' => $this->attempts->present($type, $id, $request->user(), (int) $data['presented_halalas'], $data['idempotency_key'])]);
    }

    public function initiate(Request $request, string $attempt)
    {
        $data = $request->validate(['returned_halalas' => 'required|integer|min:1|max:99999999999', 'idempotency_key' => 'required|string|max:100', 'reason' => 'required|string|max:2000', 'evidence_reference' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->attempts->initiate($attempt, $request->user(), (int) $data['returned_halalas'], $data['idempotency_key'], $data['reason'], $data['evidence_reference'] ?? null)]);
    }

    public function confirm(Request $request, string $return)
    {
        return response()->json(['data' => $this->attempts->confirmReturn($return, $request->user())]);
    }

    public function reject(Request $request, string $attempt)
    {
        $data = $request->validate(['physical_halalas' => 'required|integer|min:0|max:99999999999', 'reason' => 'required|string|max:2000', 'correction_reason' => 'required|in:input_error,actual_shortage']);
        $this->attempts->rejectAttempt($attempt, $request->user(), (int) $data['physical_halalas'], $data['reason'], $data['correction_reason']);

        return response()->json(['success' => true]);
    }

    public function receipt(Request $request, string $attempt)
    {
        $data = $request->validate(['confirmed_halalas' => 'required|integer|min:0|max:99999999999']);

        return response()->json(['data' => $this->attempts->confirmReceipt($attempt, $request->user(), (int) $data['confirmed_halalas'])]);
    }

    public function recount(Request $request, string $shift)
    {
        $data = $request->validate(['counted_halalas' => 'required|integer|min:0|max:99999999999']);

        return response()->json(['data' => $this->attempts->recount(CashierShift::findOrFail($shift), $request->user(), (int) $data['counted_halalas'])]);
    }
}
