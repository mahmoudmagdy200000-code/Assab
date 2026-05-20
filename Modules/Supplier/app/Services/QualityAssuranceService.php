<?php

namespace Modules\Supplier\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierQualityDocument;

class QualityAssuranceService
{
    /**
     * Upload quality document
     */
    public function uploadDocument(Supplier $supplier, array $data): SupplierQualityDocument
    {
        $file = $data['file'];
        $filePath = $file->store('suppliers/quality-documents', 'public');

        return SupplierQualityDocument::create([
            'supplier_id' => $supplier->id,
            'order_id' => $data['order_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'document_type' => $data['document_type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'file_path' => $filePath,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientOriginalExtension(),
            'issue_date' => $data['issue_date'] ?? now(),
            'expiry_date' => $data['expiry_date'] ?? null,
            'issuing_authority' => $data['issuing_authority'] ?? null,
            'certificate_number' => $data['certificate_number'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * Get quality documents
     */
    public function getDocuments(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = SupplierQualityDocument::where('supplier_id', $supplier->id)
            ->orderBy('created_at', 'desc');

        if (! empty($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }

        if (! empty($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Log quality incident
     */
    public function logIncident(Supplier $supplier, array $data): SupplierQualityDocument
    {
        return SupplierQualityDocument::create([
            'supplier_id' => $supplier->id,
            'order_id' => $data['order_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'document_type' => 'incident_report',
            'title' => $data['title'] ?? 'Quality Incident Report',
            'description' => $data['description'],
            'file_path' => $data['file_path'] ?? null,
            'file_name' => $data['file_name'] ?? null,
            'metadata' => [
                'incident_type' => $data['incident_type'] ?? null,
                'severity' => $data['severity'] ?? null,
                'resolution_steps' => $data['resolution_steps'] ?? null,
            ],
        ]);
    }
}
