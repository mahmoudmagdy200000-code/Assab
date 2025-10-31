<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Faker\Provider\Base;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Services\PreApprovalRequestService;
use Modules\Expense\Models\Expense;
use Modules\Expense\Transformers\ExpenseDetailResource;

/**
 * Pre-Approval Request Controller
 * For expenses > 500 SAR (requires approval before purchase)
 */
class PreApprovalRequestController extends BaseController
{
    public function __construct(
        private PreApprovalRequestService $preApprovalService
    ) {}

    /**
     * Create Pre-Approval Request
     * POST /api/branch-manager/expenses/pre-approval
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'purpose' => 'required|string|max:500',
            'estimated_amount' => 'required|numeric|min:500',
            'payment_method' => 'required|in:cash,supplier,custody',
            'supplier_id' => 'required_if:payment_method,supplier|exists:suppliers,id',
            'priority' => 'required|in:high,medium,low',

            // Items list
            'items' => 'sometimes|array',
            'items.*.category_id' => 'required_with:items|exists:categories,id',
            'items.*.description' => 'required_with:items|string|max:255',
            'items.*.quantity' => 'required_with:items|numeric|min:0.01',
            'items.*.rate' => 'required_with:items|numeric|min:0',

            // Expenses list
            'expenses' => 'sometimes|array',
            'expenses.*.category_id' => 'required_with:expenses|exists:categories,id',
            'expenses.*.description' => 'required_with:expenses|string|max:255',
            'expenses.*.price' => 'required_with:expenses|numeric|min:0',

            'attachments' => 'sometimes|array',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5120',

            'is_draft' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        // Validate estimated amount >= 500
        if ($request->estimated_amount < 500) {
            return $this->errorResponse('Pre-approval requests must be for amounts >= 500 SAR', 400);
        }

        DB::beginTransaction();
        try {
            $expense = $this->preApprovalService->createPreApprovalRequest($request->all());

            DB::commit();

            return $this->successResponse(
                new ExpenseDetailResource($expense),
                $request->is_draft
                    ? 'Pre-approval request saved as draft'
                    : 'Pre-approval request created successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to create pre-approval request: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Update Pre-Approval Request (Draft only)
     * PUT /api/branch-manager/expenses/pre-approval/{expense}
     */
    public function update(Request $request, int $expense): JsonResponse
    {
        $expenseModel = Expense::with(['preApprovalRequest', 'items', 'expenseLines'])
            ->findOrFail($expense);

        // Check authorization
        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return $this->errorResponse('Unauthorized to update this pre-approval request', 403);
        }

        if ($expenseModel->status !== 'draft') {
            return $this->errorResponse('Only draft pre-approval requests can be updated', 400);
        }

        $validator = Validator::make($request->all(), [
            'purpose' => 'sometimes|string|max:500',
            'estimated_amount' => 'sometimes|numeric|min:500',
            'payment_method' => 'sometimes|in:cash,supplier,custody',
            'supplier_id' => 'sometimes|exists:suppliers,id',
            'priority' => 'sometimes|in:high,medium,low',
            'items' => 'sometimes|array',
            'expenses' => 'sometimes|array',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        // Validate estimated amount >= 500 if changed
        if (isset($request->estimated_amount) && $request->estimated_amount < 500) {
            return $this->errorResponse('Pre-approval requests must be for amounts >= 500 SAR', 400);
        }

        DB::beginTransaction();
        try {
            $updated = $this->preApprovalService->updatePreApprovalRequest($expenseModel, $request->all());

            DB::commit();

            return $this->successResponse(
                new ExpenseDetailResource($updated),
                'Pre-approval request updated successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to update pre-approval request: ' . $e->getMessage(),
                500
            );
        }
    }



    /**
     * Get previously submitted pre-approval requests
     * GET /api/branch-manager/expenses/pre-approval/previous
     */
    public function getPreviousRequests(Request $request): JsonResponse
    {
        try {
            $query = Expense::query()
                ->where('expense_type', 'pre_approval')
                ->where('branch_manager_id', auth()->id())
                ->with(['preApprovalRequest', 'supplier'])
                ->orderBy('submitted_at', 'desc');

            // Apply search filter if provided
            if ($search = $request->input('search')) {
                $search = strtolower(trim($search));
                $query->where(function ($q) use ($search) {
                    $q->whereHas('preApprovalRequest', function ($sq) use ($search) {
                        $sq->whereRaw('LOWER(purpose) LIKE ?', ["%{$search}%"]);
                    })->orWhereHas('supplier', function ($sq) use ($search) {
                        $sq->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
                    });
                });
            }

            $requests = $query->paginate(10);

            return $this->paginatedResponse(
                ExpenseDetailResource::collection($requests),
                'Previous pre-approval requests retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve previous pre-approval requests',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Duplicate a previous pre-approval request as a new draft
     * POST /api/branch-manager/expenses/pre-approval/{expense}/duplicate
     */
    public function duplicate(Request $request, int $expense): JsonResponse
    {
        try {
            // Find the original pre-approval request with all relationships
            $originalExpense = Expense::with([
                'preApprovalRequest',
                'items',
                'expenseLines',
                'supplier',
                'attachments'
            ])
                ->where('expense_type', 'pre_approval')
                ->findOrFail($expense);

            // Check authorization - user must own the original request
            if ($originalExpense->branch_manager_id !== auth()->id()) {
                return $this->errorResponse(
                    'Unauthorized to duplicate this pre-approval request',
                    403
                );
            }

            // Validate optional overrides
            $validator = Validator::make($request->all(), [
                'purpose' => 'sometimes|string|max:500',
                'estimated_amount' => 'sometimes|numeric|min:500',
                'priority' => 'sometimes|in:high,medium,low',
                'payment_method' => 'sometimes|in:cash,supplier,custody',
                'supplier_id' => 'sometimes|exists:suppliers,id',
                'is_draft' => 'sometimes|boolean',
                'copy_attachments' => 'sometimes|boolean', // New parameter
            ]);

            if ($validator->fails()) {
                return $this->errorResponse(
                    'Validation failed',
                    422,
                    $validator->errors()->toArray()
                );
            }

            DB::beginTransaction();

            // Prepare data for duplication
            $preApproval = $originalExpense->preApprovalRequest;

            $duplicateData = [
                'purpose' => $request->input('purpose', $preApproval->purpose . ' (نسخة)'),
                'estimated_amount' => $request->input('estimated_amount', $preApproval->estimated_amount),
                'priority' => $request->input('priority', $preApproval->priority),
                'payment_method' => $request->input('payment_method', $originalExpense->payment_method),
                'supplier_id' => $request->input('supplier_id', $originalExpense->supplier_id),
                'is_draft' => $request->input('is_draft', true), // Default to draft
            ];

            // Validate estimated amount >= 500
            if ($duplicateData['estimated_amount'] < 500) {
                DB::rollBack();
                return $this->errorResponse(
                    'Pre-approval requests must be for amounts >= 500 SAR',
                    400
                );
            }

            // Duplicate items
            if ($originalExpense->items->isNotEmpty()) {
                $duplicateData['items'] = $originalExpense->items->map(function ($item) {
                    return [
                        'category_id' => $item->category_id,
                        'description' => $item->name,
                        'quantity' => $item->quantity,
                        'rate' => $item->unit_price,
                    ];
                })->toArray();
            }

            // Duplicate expense lines
            if ($originalExpense->expenseLines->isNotEmpty()) {
                $duplicateData['expenses'] = $originalExpense->expenseLines->map(function ($line) {
                    return [
                        'category_id' => $line->category_id,
                        'description' => $line->name,
                        'price' => $line->price,
                    ];
                })->toArray();
            }

            // Create the new pre-approval request using the service
            $newExpense = $this->preApprovalService->createPreApprovalRequest($duplicateData);

            // Copy attachments if requested
            $copyAttachments = $request->input('copy_attachments', true); // Default true
            if ($copyAttachments && $originalExpense->attachments->isNotEmpty()) {
                foreach ($originalExpense->attachments as $attachment) {
                    $this->duplicateAttachment($newExpense, $attachment);
                }
            }

            DB::commit();

            // Reload the expense with attachments
            $newExpense->load('attachments');

            return $this->createdResponse(
                new ExpenseDetailResource($newExpense),
                'Pre-approval request duplicated successfully as a draft'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse(
                'Original pre-approval request not found',
                404
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse(
                'Failed to duplicate pre-approval request',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Duplicate attachment file
     */
    private function duplicateAttachment(Expense $newExpense, $originalAttachment): void
    {
        try {
            // Check if original file exists
            if (!Storage::disk('public')->exists($originalAttachment->file_path)) {
                return;
            }

            // Generate new filename
            $extension = pathinfo($originalAttachment->file_path, PATHINFO_EXTENSION);
            $newFilename = 'expense_' . $newExpense->id . '_' . time() . '_' . uniqid() . '.' . $extension;
            $newPath = 'expenses/attachments/' . $newFilename;

            // Copy the file
            Storage::disk('public')->copy(
                $originalAttachment->file_path,
                $newPath
            );

            // Create new attachment record
            $newExpense->attachments()->create([
                'file_path' => $newPath,
                'file_name' => $originalAttachment->file_name,
                'file_type' => $originalAttachment->file_type,
                'file_size' => $originalAttachment->file_size,
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the whole operation
            Log::warning('Failed to duplicate attachment: ' . $e->getMessage());
        }
    }
}
