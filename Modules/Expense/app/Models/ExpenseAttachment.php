<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Expense Attachment Model
 */
class ExpenseAttachment extends Model
{
    use HasFactory , HasUuids;

    protected static function newFactory()
    {
        return \Modules\Expense\Database\Factories\ExpenseAttachmentFactory::new();
    }

    protected $fillable = [
        'expense_id',
        'invoice_detail_id',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function invoiceDetail()
    {
        return $this->belongsTo(InvoiceDetail::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->file_path);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $size = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2).' '.$units[$unitIndex];
    }
}
