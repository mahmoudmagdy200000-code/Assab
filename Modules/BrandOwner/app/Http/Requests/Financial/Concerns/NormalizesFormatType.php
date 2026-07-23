<?php

namespace Modules\BrandOwner\Http\Requests\Financial\Concerns;

/**
 * Normalizes the `format_type` input to its canonical case (PDF / Excel) before
 * validation runs.
 *
 * The file exporters are already case-insensitive (they lower-case internally),
 * so a caller sending "pdf" or "excel" expresses a perfectly valid intent — this
 * trait keeps the strict `in:PDF,Excel` rule from rejecting it on case alone.
 * Anything that is not a recognised format is passed through untouched so it
 * still fails validation as before.
 */
trait NormalizesFormatType
{
    protected function prepareForValidation(): void
    {
        $format = $this->input('format_type');

        if (is_string($format) && $format !== '') {
            $canonical = ['pdf' => 'PDF', 'excel' => 'Excel'];
            $this->merge([
                'format_type' => $canonical[strtolower(trim($format))] ?? $format,
            ]);
        }
    }
}
