<?php

namespace Modules\FixedAssets\Services;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;

class HandoverQrCodeService
{
    public function payload(string $handoverId): string
    {
        return json_encode(['handoverId' => $handoverId], JSON_UNESCAPED_SLASHES);
    }

    public function generateAndStore(string $handoverId): string
    {
        $payload = $this->payload($handoverId);

        $qrCode = new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 400,
            margin: 16,
        );

        $result = (new PngWriter)->write($qrCode);

        $path = "fixed-assets/handover-qr/{$handoverId}.png";
        Storage::disk('public')->put($path, $result->getString());

        return $path;
    }

    public function url(?string $path): string
    {
        if (! $path) {
            return '';
        }

        return asset('storage/'.$path);
    }
}
