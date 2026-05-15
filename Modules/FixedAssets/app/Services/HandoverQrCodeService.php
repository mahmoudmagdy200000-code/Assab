<?php

namespace Modules\FixedAssets\Services;

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
        $svg = $this->renderSvg($payload);

        $path = "fixed-assets/handover-qr/{$handoverId}.svg";
        Storage::disk('public')->put($path, $svg);

        return $path;
    }

    public function url(?string $path): string
    {
        if (! $path) {
            return '';
        }

        return asset('storage/'.$path);
    }

    private function renderSvg(string $payload): string
    {
        $escaped = htmlspecialchars($payload, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200">
  <rect width="200" height="200" fill="#ffffff"/>
  <text x="100" y="100" text-anchor="middle" dominant-baseline="middle" font-family="monospace" font-size="10">{$escaped}</text>
</svg>
SVG;
    }
}
