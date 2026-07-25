<?php

namespace Modules\BrandOwner\Http\Requests\Financial\Concerns;

/**
 * Normalizes the `format_type` input to its canonical case (PDF / Excel) before
 * validation runs.
 *
 * The file exporters are already case-insensitive (they lower-case internally),
 * so a caller sending "pdf" or "excel" expresses a perfectly valid intent — this
 * trait keeps the strict `in:PDF,Excel` rule from rejecting it on case alone.
 * A blank string is normalised to null so an "omitted" format reads as omitted
 * (and hits the endpoint default) instead of failing the `in` rule with a
 * confusing "selected format type is invalid". Anything else is passed through
 * untouched so unrecognised formats still fail validation as before.
 */
trait NormalizesFormatType
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('format_type')) {
            return;
        }

        $format = $this->input('format_type');

        if (! is_string($format)) {
            return;
        }

        $format = trim($format);

        if ($format === '') {
            $this->merge(['format_type' => null]);

            return;
        }

        $canonical = ['pdf' => 'PDF', 'excel' => 'Excel'];

        $this->merge([
            'format_type' => $canonical[strtolower($format)] ?? $format,
        ]);
    }
}
