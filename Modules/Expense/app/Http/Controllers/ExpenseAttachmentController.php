<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Models\Expense;
use Modules\Expense\Transformers\ExpenseDetailResource;

/**
 * Expense Attachment Controller
 * For adding attachments to any expense type
 */
class ExpenseAttachmentController extends BaseController
{
    /**
     * Add attachments to an expense
     * POST /api/branch-manager/expenses/{expense}/attachments
     */
    public function store(Request $request, string $expense): JsonResponse
    {
        $expenseModel = Expense::with(['attachments'])->findOrFail($expense);

        // Authorization check
        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return $this->errorResponse(
                'Unauthorized access to this expense',
                403
            );
        }

        // Only allow adding attachments to draft or pending expenses
        if (!in_array($expenseModel->status, ['draft', 'pending'])) {
            return $this->errorResponse(
                'Cannot add attachments to expenses with status: ' . $expenseModel->status,
                400
            );
        }

        $validator = Validator::make($request->all(), [
            'attachments' => 'required|array|max:10',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB
            'invoice_detail_id' => 'sometimes|exists:invoice_details,id', // For grouped invoices
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        // Check total attachments limit (max 15 per expense)
        $currentAttachmentsCount = $expenseModel->attachments->count();
        $newAttachmentsCount = count($request->file('attachments'));

        if (($currentAttachmentsCount + $newAttachmentsCount) > 15) {
            return $this->errorResponse(
                'Maximum 15 attachments allowed per expense',
                400,
                [
                    'current_count' => $currentAttachmentsCount,
                    'trying_to_add' => $newAttachmentsCount,
                    'max_allowed' => 15
                ]
            );
        }

        // Validate invoice_detail_id belongs to this expense (for grouped invoices)
        if ($request->has('invoice_detail_id')) {
            $invoiceExists = $expenseModel->invoiceDetails()
                ->where('id', $request->invoice_detail_id)
                ->exists();

            if (!$invoiceExists) {
                return $this->errorResponse(
                    'Invoice detail does not belong to this expense',
                    400
                );
            }
        }

        DB::beginTransaction();
        try {
            $uploadedAttachments = [];

            foreach ($request->file('attachments') as $file) {
                $attachment = $this->uploadAttachment($expenseModel, $file, $request->invoice_detail_id ?? null);
                $uploadedAttachments[] = $attachment;
            }

            // Create timeline entry
            $this->createTimelineEntry(
                $expenseModel,
                'attachments_added',
                count($uploadedAttachments) . ' attachment(s) added'
            );

            DB::commit();

            return $this->successResponse(
                [
                    'uploaded_count' => count($uploadedAttachments),
                    'attachments' => $uploadedAttachments,
                    'expense' => new ExpenseDetailResource($expenseModel->fresh(['attachments']))
                ],
                'Attachments uploaded successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to upload attachments: ' . $e->getMessage());

            return $this->errorResponse(
                'Failed to upload attachments',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Delete specific attachments
     * DELETE /api/branch-manager/expenses/{expense}/attachments
     */
    public function destroy(Request $request, string $expense): JsonResponse
    {
        $expenseModel = Expense::with(['attachments'])->findOrFail($expense);

        // Authorization check
        if ($expenseModel->branch_manager_id !== auth()->id()) {
            return $this->errorResponse(
                'Unauthorized access to this expense',
                403
            );
        }

        // Only allow deleting attachments from draft expenses
        if ($expenseModel->status !== 'draft') {
            return $this->errorResponse(
                'Can only delete attachments from draft expenses',
                400
            );
        }

        $validator = Validator::make($request->all(), [
            'attachment_ids' => 'required|array|min:1',
            'attachment_ids.*' => 'required|exists:expense_attachments,id',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        DB::beginTransaction();
        try {
            $attachments = $expenseModel->attachments()
                ->whereIn('id', $request->attachment_ids)
                ->get();

            if ($attachments->isEmpty()) {
                return $this->errorResponse(
                    'No valid attachments found to delete',
                    404
                );
            }

            $deletedCount = 0;

            foreach ($attachments as $attachment) {
                try {
                    // Delete physical file
                    if (Storage::disk('public')->exists($attachment->file_path)) {
                        Storage::disk('public')->delete($attachment->file_path);
                    }

                    // Delete database record
                    $attachment->delete();
                    $deletedCount++;
                } catch (\Exception $e) {
                    Log::warning('Failed to delete attachment: ' . $e->getMessage());
                }
            }

            // Create timeline entry
            $this->createTimelineEntry(
                $expenseModel,
                'attachments_deleted',
                $deletedCount . ' attachment(s) deleted'
            );

            DB::commit();

            return $this->successResponse(
                [
                    'deleted_count' => $deletedCount,
                    'expense' => new ExpenseDetailResource($expenseModel->fresh(['attachments']))
                ],
                'Attachments deleted successfully'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete attachments: ' . $e->getMessage());

            return $this->errorResponse(
                'Failed to delete attachments',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Get all attachments for an expense
     * GET /api/branch-manager/expenses/{expense}/attachments
     */
    public function index(string $expense): JsonResponse
    {
        try {
            $expenseModel = Expense::with(['attachments'])->findOrFail($expense);

            // Authorization check
            if ($expenseModel->branch_manager_id !== auth()->id()) {
                return $this->errorResponse(
                    'Unauthorized access to this expense',
                    403
                );
            }

            $attachments = $expenseModel->attachments->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'file_type' => $attachment->file_type,
                    'file_size' => $attachment->file_size,
                    'file_size_formatted' => $this->formatFileSize($attachment->file_size),
                    'file_url' => Storage::disk('public')->url($attachment->file_path),
                    'invoice_detail_id' => $attachment->invoice_detail_id,
                    'created_at' => $attachment->created_at,
                ];
            });

            return $this->successResponse(
                [
                    'total_count' => $attachments->count(),
                    'attachments' => $attachments
                ],
                'Attachments retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Failed to retrieve attachments',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Upload single attachment
     */
    private function uploadAttachment(Expense $expense, $file, ?int $invoiceDetailId = null): array
    {
        $folderPath = match ($expense->expense_type) {
            'quick_cash' => 'expenses/quick-cash',
            'single_invoice' => 'expenses/receipts',
            'grouped_invoice' => 'expenses/invoices',
            'pre_approval' => 'expenses/attachments',
            default => 'expenses/attachments'
        };

        $filename = 'expense_' . $expense->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs($folderPath, $filename, 'public');

        $attachment = $expense->attachments()->create([
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
            'invoice_detail_id' => $invoiceDetailId,
        ]);

        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'file_type' => $attachment->file_type,
            'file_size' => $attachment->file_size,
            'file_size_formatted' => $this->formatFileSize($attachment->file_size),
            'file_url' => Storage::disk('public')->url($path),
            'created_at' => $attachment->created_at,
        ];
    }

    /**
     * Create timeline entry
     */
    private function createTimelineEntry(Expense $expense, string $action, string $notes = null): void
    {
        $expense->timelines()->create([
            'action' => $action,
            'performed_by' => auth()->id(),
            'performed_by_type' => 'branch_manager',
            'status' => $action,
            'notes' => $notes,
        ]);
    }

    /**
     * Format file size to human readable format
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
