<?php

namespace Modules\BranchManagers\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Modules\BranchManagers\Models\BranchManager;

class BranchManagerMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // تحقق من المصادقة
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // تحقق من نوع المستخدم
        if (!$user instanceof BranchManager) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Branch Manager access required.'
            ], 403);
        }

        // تحقق من حالة الحساب
        if (!$user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact administrator.'
            ], 403);
        }

        // تحقق من الإيقاف
        if (method_exists($user, 'isSuspended') && $user->isSuspended()) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact administrator.'
            ], 403);
        }

        // ✅ تحقق من ارتباط Branch Manager بفرع
        if (!$user->branch_id) {
            return response()->json([
                'success' => false,
                'message' => 'Branch manager is not assigned to any branch. Please contact administrator.'
            ], 403);
        }

        // ✅ إضافة branch_id للـ request ليكون متاح في كل الـ Controllers
        $request->merge(['manager_branch_id' => $user->branch_id]);

        return $next($request);
    }
}
