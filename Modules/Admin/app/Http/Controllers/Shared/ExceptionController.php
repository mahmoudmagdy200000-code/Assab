<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Operation;

/**
 * Risk panel — derived exceptions (BACKEND_API_SPEC.md §7.8).
 */
class ExceptionController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $forRole = $request->query('forRole', 'accountant');
            $items = [];

            $stuck = Operation::where('status', 'pending')->where('submitted_at', '<', now()->subDays(2))->count();
            if ($stuck > 0) {
                $items[] = $this->item('high', '⏳', 'عمليات معلّقة أكثر من يومين', $stuck, $forRole, 'مراجعة العمليات المعلّقة', 'review-pending');
            }

            $diffs = Operation::where('match', 'diff')->whereIn('status', ['pending', 'approved'])->count();
            if ($diffs > 0) {
                $items[] = $this->item('medium', '⚠️', 'فروقات غير محلولة', $diffs, $forRole, 'حل الفروقات', 'review-diffs');
            }

            $pendingErp = Operation::where('status', 'final-approved')->where('erp_posted', false)->count();
            if ($pendingErp > 0) {
                $items[] = $this->item('medium', '📤', 'عمليات بانتظار التصدير لـ ERP', $pendingErp, 'head', 'تصدير لـ ERP', 'head-erp');
            }

            $corrections = Operation::where('is_correction', true)->where('status', 'pending')->count();
            if ($corrections > 0) {
                $items[] = $this->item('high', '🔧', 'عمليات تعديل بانتظار المراجعة', $corrections, $forRole, 'مراجعة التعديلات', 'review-corrections');
            }

            return $this->ok(['items' => $items]);
        });
    }

    private function item(string $severity, string $icon, string $label, int $count, string $owner, string $action, string $navTarget): array
    {
        return [
            'severity' => $severity,
            'icon' => $icon,
            'label' => $label,
            'count' => $count,
            'owner' => $owner,
            'action' => $action,
            'age' => '',
            'impact' => '',
            'navTarget' => $navTarget,
            'navLabel' => $action,
        ];
    }
}
