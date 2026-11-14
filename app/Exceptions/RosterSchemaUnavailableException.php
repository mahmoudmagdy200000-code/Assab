<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

final class RosterSchemaUnavailableException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'ROSTER_SCHEMA_NOT_READY',
                'message' => 'Recurring assignment storage is not ready. Contact the system administrator.',
                'messageAr' => 'التعيين المتكرر غير جاهز على الخادم. يلزم أن يتحقق مسؤول النظام من ترحيل جدول التعيينات. يمكنك استخدام تعيين يوم محدد مؤقتاً.',
            ],
        ], 503);
    }
}
