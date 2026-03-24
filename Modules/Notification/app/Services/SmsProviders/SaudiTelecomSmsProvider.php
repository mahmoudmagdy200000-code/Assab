<?php

namespace Modules\Notification\Services\SmsProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SaudiTelecomSmsProvider implements SmsProviderInterface
{
    private string $apiUrl;
    private string $apiKey;
    private string $senderId;

    public function __construct()
    {
        $this->apiUrl = config('notification.sms.stc.api_url', '');
        $this->apiKey = config('notification.sms.stc.api_key', '');
        $this->senderId = config('notification.sms.stc.sender_id', '');
    }

    /**
     * Send SMS via STC provider
     */
    public function send(string $phoneNumber, string $message): bool
    {
        try {
            // Format phone number (add country code if needed)
            $phoneNumber = $this->formatPhoneNumber($phoneNumber);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->timeout(8)
                ->retry(3, function (int $attempt) {
                    return ($attempt ** 2) * 100 + random_int(25, 200);
                })
                ->post($this->apiUrl, [
                'to' => $phoneNumber,
                'message' => $message,
                'sender' => $this->senderId,
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('STC SMS API error', [
                'phone' => $phoneNumber,
                'response' => $response->body(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('STC SMS provider exception', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Format phone number for Saudi Arabia
     */
    private function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove any non-numeric characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Add country code if not present
        if (!str_starts_with($phoneNumber, '966')) {
            if (str_starts_with($phoneNumber, '0')) {
                $phoneNumber = '966' . substr($phoneNumber, 1);
            } else {
                $phoneNumber = '966' . $phoneNumber;
            }
        }

        return $phoneNumber;
    }
}

