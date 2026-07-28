<?php

namespace Modules\Notification\Console\Commands;

use Illuminate\Console\Command;
use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\DataTransferObjects\FcmMessage;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Exceptions\FcmConfigurationException;
use Modules\Notification\Services\Fcm\FcmAccessTokenProvider;
use Modules\Notification\Services\Fcm\FcmCredentials;
use Modules\Notification\Services\Fcm\FcmHttpV1Client;

/**
 * Verifies the FCM transport end to end without touching the notification
 * pipeline: reads the service-account key, mints an OAuth2 access token, and
 * optionally delivers a real message to one registration token.
 *
 * Exists because every failure mode here (missing file, wrong project, revoked
 * key, blocked egress) otherwise surfaces only as a queued job quietly failing
 * hours later.
 */
class FcmDoctorCommand extends Command
{
    protected $signature = 'notification:fcm-doctor
                            {--token= : Send a real test push to this FCM registration token}';

    protected $description = 'Check the Firebase credentials and, optionally, send a real test push';

    public function handle(FcmCredentials $credentials, FcmAccessTokenProvider $tokenProvider): int
    {
        $driver = config('notification.fcm.driver');

        $this->line('');
        $this->line("  Driver ................. <options=bold>{$driver}</>");

        if ($driver !== 'http_v1') {
            $this->warn('  FCM_DRIVER is not "http_v1" — nothing is actually sent to Firebase.');
            $this->line('  Set FCM_DRIVER=http_v1 once the service-account key is in place.');
            $this->line('');

            return self::SUCCESS;
        }

        $path = config('notification.fcm.credentials');
        $this->line("  Key path ............... {$path}");

        if (! $credentials->isConfigured()) {
            $this->line('');
            $this->error('  Service-account key is missing or unreadable.');
            $this->line('  Place the JSON key at the path above, or set FIREBASE_CREDENTIALS.');
            $this->line('');

            return self::FAILURE;
        }

        try {
            $this->line("  Project ................ <options=bold>{$credentials->projectId()}</>");
            $this->line("  Client email ........... {$credentials->clientEmail()}");

            // The only real proof the key is valid and egress to Google works.
            $tokenProvider->forget();
            $accessToken = $tokenProvider->token();

            $this->line('  Access token ........... <fg=green>minted ok</> ('.mb_strlen($accessToken).' chars)');
        } catch (FcmConfigurationException $e) {
            $this->line('');
            $this->error('  '.$e->getMessage());
            $this->line('');

            return self::FAILURE;
        }

        $deviceToken = $this->option('token');

        if (! $deviceToken) {
            $this->line('');
            $this->info('  Credentials are valid. Pass --token=<registration token> to send a real push.');
            $this->line('');

            return self::SUCCESS;
        }

        $client = app(FcmClientInterface::class);

        if (! $client instanceof FcmHttpV1Client) {
            $this->error('  Resolved client is not the HTTP v1 transport; check FCM_DRIVER.');

            return self::FAILURE;
        }

        $result = $client->sendToToken($deviceToken, new FcmMessage(
            title: 'Assab test notification',
            body: 'If you can read this, push delivery is working.',
            data: ['type' => 'audit_trail_notification', 'test' => '1'],
            priority: NotificationPriority::HIGH,
            androidChannelId: config('notification.fcm.android_channel_id'),
        ));

        $this->line('');

        if ($result->success) {
            $this->info('  Sent. Message id: '.$result->messageId);
            $this->line('');

            return self::SUCCESS;
        }

        $this->error('  Send failed: '.$result->errorCode.' — '.$result->errorMessage);

        if ($result->shouldPrune) {
            $this->line('  That registration token is dead; the app must register a fresh one.');
        }

        if ($result->retryable) {
            $this->line('  Transient failure — retrying usually succeeds.');
        }

        $this->line('');

        return self::FAILURE;
    }
}
