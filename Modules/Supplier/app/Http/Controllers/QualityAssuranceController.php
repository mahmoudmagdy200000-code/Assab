<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Http\Requests\QualityAssurance\UploadDocumentRequest;
use Modules\Supplier\Http\Requests\QualityAssurance\LogIncidentRequest;
use Modules\Supplier\Services\QualityAssuranceService;
use Modules\Supplier\Transformers\QualityDocumentResource;

class QualityAssuranceController extends BaseController
{
    public function __construct(
        private readonly QualityAssuranceService $qualityService
    ) {}

    /**
     * Upload quality document
     */
    public function uploadDocument(UploadDocumentRequest $request): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $document = $this->qualityService->uploadDocument($supplier, $request->validated());

            return $this->createdResponse(
                new QualityDocumentResource($document),
                'Quality document uploaded successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'uploading document');
        }
    }

    /**
     * Get documents
     */
    public function getDocuments(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $filters = request()->only(['document_type', 'order_id', 'product_id']);
            $perPage = request()->get('per_page', 15);

            $documents = $this->qualityService->getDocuments($supplier, $filters, $perPage);

            return $this->paginatedResponse(
                QualityDocumentResource::collection($documents),
                'Quality documents retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching documents');
        }
    }

    /**
     * Log quality incident
     */
    public function logIncident(LogIncidentRequest $request): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $document = $this->qualityService->logIncident($supplier, $request->validated());

            return $this->createdResponse(
                new QualityDocumentResource($document),
                'Quality incident logged successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'logging incident');
        }
    }
}

