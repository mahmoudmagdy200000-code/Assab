<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Services\WorkdaySalesReview;

class WorkdaySalesReviewController
{
    public function index(Request $request, WorkdaySalesReview $review): JsonResponse
    {
        return response()->json(['data' => $review->managerReports($request->user())]);
    }

    public function update(Request $request, string $day, WorkdaySalesReview $review): JsonResponse
    {
        $command = $request->validate([
            'action' => 'required|in:reply,resubmit',
            'questionId' => 'required_if:action,reply|nullable|uuid',
            'returnCycle' => 'required_if:action,resubmit|nullable|uuid',
            'message' => 'required|string|max:1000',
        ]);

        return response()->json(['data' => $review->managerAction($request->user(), $day, $command)]);
    }
}
