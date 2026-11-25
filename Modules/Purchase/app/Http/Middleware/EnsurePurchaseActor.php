<?php

namespace Modules\Purchase\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchaseAuthorizationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Action-specific actor guard for `/api/v1/purchase/*` routes.
 *
 * Modes:
 * - `branch`: Active, non-suspended BranchManager assigned to a branch (default).
 * - `receiving_asab`: Active BranchManager OR active AsabUser with a branch role.
 * - `supplier_response`: Active, non-suspended Supplier.
 * - `return_view`: Active BranchManager OR active BrandOwner (scoped per controller/policy).
 */
class EnsurePurchaseActor
{
    public function __construct(
        private readonly PurchaseAuthorizationService $authService
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'branch'): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // AsabUser carries no `branch_id` column: its branch scope comes from the explicit
        // branch-manager role assignment. Bind the active branch context (in memory only,
        // never persisted) to a branch inside the actor's authorized set so downstream
        // branch-scoped code keeps working. `X-Branch-Id` may pick another authorized branch.
        if ($user instanceof \Modules\Admin\Models\AsabUser && in_array($mode, ['branch', 'receiving_asab', 'return_view'], true)) {
            $allowed = $this->authService->resolveActingBranchIds($user, true);
            if ($allowed !== []) {
                $requested = (string) $request->header('X-Branch-Id', '');
                $user->setAttribute('branch_id', in_array($requested, $allowed, true) ? $requested : $allowed[0]);
            }
        }

        $authorized = match ($mode) {
            'receiving_asab' => $this->authService->resolveActingBranchIds($user, true) !== [],
            'supplier_response' => $this->authService->ensureActiveSupplier($user) !== null,
            'return_view' => $this->authService->ensureActiveBranchManager($user) !== null
                || $this->authService->ensureActiveBrandOwner($user) !== null,
            default => $this->authService->ensureActiveBranchManager($user) !== null,
        };

        if (! $authorized) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized for this purchase operation.',
            ], 403);
        }

        return $next($request);
    }
}
