<?php

namespace Modules\Expense\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Services\StreamUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Modules\Expense\Models\Expense;
use Modules\Expense\Repositories\ExpenseRepository;
use Modules\Expense\Transformers\ExpenseDetailResource;

/**
 * Expense Attachment Controller
 * For adding attachments to any expense type.
 * Data access via ExpenseRepository; business logic (upload/delete) in controller for now.
 */
class ExpenseAttachmentController extends BaseController
{
    public function __construct(
        private ExpenseRepository $expenseRepository,
        private readonly StreamUploadService $streamUpload
    ) {}

    /**
     * Add attachments to an expense
     * POST /api/branch-manager/expenses/{expense}/attachments
     *
     * For regular expenses: attachments[] = file
     * For grouped invoices: invoices[invoice_id][attachments][] = file
     */
    public function store(Request $request, string $expense): JsonResponse
    {
        $expenseModel = $this->expenseRepository->findWithAttachmentsAndInvoiceDetails($expense);

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

        // Check if this is a grouped invoice request
        $isGroupedInvoice = $request->has('invoices') && $expenseModel->expense_type === 'grouped_invoice';

        if ($isGroupedInvoice) {
            return $this->storeGroupedInvoiceAttachments($request, $expenseModel);
        }

        // Regular attachment upload (original logic)
        return $this->storeRegularAttachments($request, $expenseModel);
    }

    /**
     * Store attachments for grouped invoice (multiple invoices)
     */
    private function storeGroupedInvoiceAttachments(Request $request, Expense $expenseModel): JsonResponse
    {
        // Validate the grouped invoice structure
        $validator = Validator::make($request->all(), [
            'invoices' => 'required|array',
            'invoices.*' => 'array',
            'invoices.*.attachments' => 'required|array|max:10',
            'invoices.*.attachments.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        // Get invoices from files (FormData sends files not input)
        $invoicesData = $request->file('invoices');

        if (!$invoicesData || !is_array($invoicesData)) {
            return $this->errorResponse(
                'No invoices data provided',
                400
            );
        }

        $invoiceIds = array_keys($invoicesData);

        // Validate all invoice IDs belong to this expense
        $validInvoices = $expenseModel->invoiceDetails()
            ->whereIn('id', $invoiceIds)
            ->pluck('id')
            ->toArray();

        $invalidInvoices = array_diff($invoiceIds, $validInvoices);
        if (!empty($invalidInvoices)) {
            return $this->errorResponse(
                'Some invoice IDs do not belong to this expense',
                400,
                ['invalid_invoice_ids' => $invalidInvoices]
            );
        }

        // Calculate total attachments count
        $totalNewAttachments = 0;
        foreach ($invoicesData as $invoiceId => $invoiceData) {
            if (isset($invoiceData['attachments'])) {
                $totalNewAttachments += count($invoiceData['attachments']);
            }
        }

        $currentAttachmentsCount = $expenseModel->attachments->count();

        if (($currentAttachmentsCount + $totalNewAttachments) > 15) {
            return $this->errorResponse(
                'Maximum 15 attachments allowed per expense',
                400,
                [
                    'current_count' => $currentAttachmentsCount,
                    'trying_to_add' => $totalNewAttachments,
                    'max_allowed' => 15
                ]
            );
        }

        DB::beginTransaction();
        try {
            $uploadedAttachments = [];
            $uploadCountPerInvoice = [];

            foreach ($invoicesData as $invoiceId => $invoiceData) {
                if (!isset($invoiceData['attachments']) || empty($invoiceData['attachments'])) {
                    continue;
                }

                $uploadCountPerInvoice[$invoiceId] = 0;

                foreach ($invoiceData['attachments'] as $file) {
                    $attachment = $this->uploadAttachment($expenseModel, $file, $invoiceId);

                    if (!isset($uploadedAttachments[$invoiceId])) {
                        $uploadedAttachments[$invoiceId] = [];
                    }

                    $uploadedAttachments[$invoiceId][] = $attachment;
                    $uploadCountPerInvoice[$invoiceId]++;
                }
            }

            // Create timeline entry
            $totalUploaded = array_sum($uploadCountPerInvoice);
            $invoiceCount = count($uploadCountPerInvoice);

            $this->createTimelineEntry(
                $expenseModel,
                'attachments_added',
                "{$totalUploaded} attachment(s) added to {$invoiceCount} invoice(s)"
            );

            DB::commit();

            return $this->successResponse(
                [
                    'total_uploaded' => $totalUploaded,
                    'upload_per_invoice' => $uploadCountPerInvoice,
                    'attachments' => $uploadedAttachments,
                    'expense' => new ExpenseDetailResource($expenseModel->fresh(['attachments', 'invoiceDetails.attachments']))
                ],
                'Attachments uploaded successfully to grouped invoices'
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to upload grouped invoice attachments: ' . $e->getMessage());

            return $this->errorResponse(
                'Failed to upload attachments',
                500,
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Store regular attachments (original logic)
     * Attachments are required only when the expense has no attachments yet (first upload).
     * When the expense already has attachments, new files are optional (avoids "required" error when app sends empty POST on submit).
     */
    private function storeRegularAttachments(Request $request, Expense $expenseModel): JsonResponse
    {
        $hasExistingAttachments = $expenseModel->attachments->count() > 0;
        $attachmentsRule = $hasExistingAttachments ? 'sometimes|array|max:10' : 'required|array|max:10';

        $validator = Validator::make($request->all(), [
            'attachments' => $attachmentsRule,
            'attachments.*' => 'file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB
            'invoice_detail_id' => 'sometimes|exists:invoice_details,id', // For single invoice
        ]);

        if ($validator->fails()) {
            return $this->errorResponse(
                'Validation failed',
                422,
                $validator->errors()->toArray()
            );
        }

        $newAttachmentsCount = count($request->file('attachments') ?? []);
        if ($newAttachmentsCount === 0) {
            return $this->successResponse(
                [
                    'uploaded_count' => 0,
                    'attachments' => $expenseModel->attachments->toArray(),
                    'expense' => new ExpenseDetailResource($expenseModel->fresh(['attachments'])),
                ],
                'No new files to upload'
            );
        }

        // Check total attachments limit (max 15 per expense)
        $currentAttachmentsCount = $expenseModel->attachments->count();

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

        // Validate invoice_detail_id belongs to this expense (if provided)
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
        $expenseModel = $this->expenseRepository->findWithAttachmentsAndInvoiceDetails($expense);

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
            $expenseModel = $this->expenseRepository->findWithAttachmentsForIndex($expense);

            // Authorization check
            if ($expenseModel->branch_manager_id !== auth()->id()) {
                return $this->errorResponse(
                    'Unauthorized access to this expense',
                    403
                );
            }

            // Group attachments by invoice if it's a grouped invoice
            if ($expenseModel->expense_type === 'grouped_invoice') {
                $attachmentsByInvoice = [];

                foreach ($expenseModel->invoiceDetails as $invoice) {
                    $attachmentsByInvoice[$invoice->id] = [
                        'invoice_number' => $invoice->invoice_number,
                        'attachments' => $invoice->attachments->map(function ($attachment) {
                            return [
                                'id' => $attachment->id,
                                'file_name' => $attachment->file_name,
                                'file_type' => $attachment->file_type,
                                'file_size' => $attachment->file_size,
                                'file_size_formatted' => $this->formatFileSize($attachment->file_size),
                                'file_url' => Storage::disk('public')->url($attachment->file_path),
                                'created_at' => $attachment->created_at,
                            ];
                        })
                    ];
                }

                return $this->successResponse(
                    [
                        'total_count' => $expenseModel->attachments->count(),
                        'attachments_by_invoice' => $attachmentsByInvoice
                    ],
                    'Grouped invoice attachments retrieved successfully'
                );
            }

            // Regular attachments listing
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
    private function uploadAttachment(Expense $expense, $file, string|int|null $invoiceDetailId = null): array
    {
        $folderPath = match ($expense->expense_type) {
            'quick_cash' => 'expenses/quick-cash',
            'single_invoice' => 'expenses/receipts',
            'grouped_invoice' => 'expenses/invoices',
            'pre_approval' => 'expenses/attachments',
            default => 'expenses/attachments'
        };

        $filename = 'expense_' . $expense->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $path = $this->streamUpload->storeFromUpload($file, $folderPath, $filename, 'public');

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
            'invoice_detail_id' => $invoiceDetailId,
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
