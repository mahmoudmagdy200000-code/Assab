<?php

namespace Modules\Purchase\Enums;

enum DocumentType: string
{
    case INVOICE = 'invoice';
    case DELIVERY_NOTE = 'delivery_note';
    case RECEIPT_WITHOUT_DOCUMENT = 'receipt_without_document';
    case QUALITY_CERTIFICATE = 'quality_certificate';
    case PHOTO = 'photo';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::INVOICE => 'Invoice',
            self::DELIVERY_NOTE => 'Delivery Note',
            self::RECEIPT_WITHOUT_DOCUMENT => 'Receipt Without Document',
            self::QUALITY_CERTIFICATE => 'Quality Certificate',
            self::PHOTO => 'Photo',
            self::OTHER => 'Other',
        };
    }

    public function requiresInvoiceDetails(): bool
    {
        return $this === self::INVOICE;
    }
}
