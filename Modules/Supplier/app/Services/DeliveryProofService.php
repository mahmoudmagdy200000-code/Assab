<?php

namespace Modules\Supplier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\DeliveryProof;
use Modules\Supplier\Models\Supplier;

class DeliveryProofService
{
    /**
     * Create delivery proof
     */
    public function createProof(PurchaseOrder $order, Supplier $supplier, array $data): DeliveryProof
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        return DB::transaction(function () use ($order, $data) {
            $signaturePath = null;
            if (isset($data['recipient_signature']) && is_object($data['recipient_signature']) && method_exists($data['recipient_signature'], 'isValid') && $data['recipient_signature']->isValid()) {
                $signaturePath = $data['recipient_signature']->store('supplier/deliveries/signatures', 'public');
            }

            $deliveryPhotos = [];
            if (!empty($data['delivery_photos'])) {
                foreach ($data['delivery_photos'] as $photo) {
                    if ($photo && is_object($photo) && method_exists($photo, 'isValid') && $photo->isValid()) {
                        $photoPath = $photo->store('supplier/deliveries/photos', 'public');
                        $deliveryPhotos[] = $photoPath;
                    }
                }
            }

            return DeliveryProof::create([
                'purchase_order_id' => $order->id,
                'recipient_name' => $data['recipient_name'],
                'recipient_signature' => $signaturePath,
                'delivery_photos' => $deliveryPhotos,
                'condition_confirmation' => $data['condition_confirmation'] ?? null,
                'acknowledgment_received_at' => now(),
            ]);
        });
    }

    /**
     * Get delivery proof for order
     */
    public function getProof(PurchaseOrder $order, Supplier $supplier): ?DeliveryProof
    {
        if ($order->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this order');
        }

        return DeliveryProof::where('purchase_order_id', $order->id)->first();
    }

    /**
     * Update delivery proof
     */
    public function updateProof(DeliveryProof $proof, Supplier $supplier, array $data): DeliveryProof
    {
        $proof->load('purchaseOrder');
        if ($proof->purchaseOrder->supplier_id !== $supplier->id) {
            throw new \Exception('Unauthorized access to this proof');
        }

        return DB::transaction(function () use ($proof, $data) {
            $updateData = [];

            if (isset($data['recipient_name'])) {
                $updateData['recipient_name'] = $data['recipient_name'];
            }

            if (isset($data['condition_confirmation'])) {
                $updateData['condition_confirmation'] = $data['condition_confirmation'];
            }

            if (isset($data['recipient_signature']) && is_object($data['recipient_signature']) && method_exists($data['recipient_signature'], 'isValid') && $data['recipient_signature']->isValid()) {
                // Delete old signature if exists
                if ($proof->recipient_signature) {
                    Storage::disk('public')->delete($proof->recipient_signature);
                }
                $updateData['recipient_signature'] = $data['recipient_signature']->store('supplier/deliveries/signatures', 'public');
            }

            if (!empty($data['delivery_photos'])) {
                // Delete old photos if exists
                if ($proof->delivery_photos) {
                    foreach ($proof->delivery_photos as $oldPhoto) {
                        Storage::disk('public')->delete($oldPhoto);
                    }
                }

                $deliveryPhotos = [];
                foreach ($data['delivery_photos'] as $photo) {
                    if ($photo && is_object($photo) && method_exists($photo, 'isValid') && $photo->isValid()) {
                        $photoPath = $photo->store('supplier/deliveries/photos', 'public');
                        $deliveryPhotos[] = $photoPath;
                    }
                }
                $updateData['delivery_photos'] = $deliveryPhotos;
            }

            $proof->update($updateData);

            return $proof->fresh();
        });
    }
}

