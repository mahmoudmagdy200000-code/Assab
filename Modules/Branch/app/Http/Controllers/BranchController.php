<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;

class BranchController extends BaseController
{
    public function index()
    {
        $manager = auth()->user();

        // تأكد إن المستخدم مسجل دخول كـ Branch Manager
        if (!$manager) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        // هنا بنجيب كل الفروع - ممكن تضيف شرط لو عايز يجيب بس فروع محددة
        $branches = Branch::query()
            ->orderBy('name')
            ->paginate(10);

        return $this->paginatedResponse($branches, 'Branches retrieved successfully');
    }
}
