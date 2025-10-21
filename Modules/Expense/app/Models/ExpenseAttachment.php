<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Expense Attachment Model
 */
class ExpenseAttachment extends Model
{
    use HasFactory;

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
        return asset('storage/' . $this->file_path);
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

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}
