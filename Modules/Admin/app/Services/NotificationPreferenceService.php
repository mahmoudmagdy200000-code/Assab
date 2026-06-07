<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabNotificationPreference;
use Modules\Admin\Models\AsabUser;

/**
 * Per-user notification preferences get/update (MISSING_Dashboard §7). Stored
 * preferences are merged over the defaults so a partial PATCH only overrides the
 * keys it sends.
 */
class NotificationPreferenceService
{
    /** Default per-event channel matrix. */
    public const DEFAULT_EVENTS = [
        'operation.created' => ['inApp' => true, 'email' => false, 'push' => false],
        'operation.status_changed' => ['inApp' => true, 'email' => false, 'push' => false],
        'approval.pending' => ['inApp' => true, 'email' => true, 'push' => true],
        'invoice.paid' => ['inApp' => true, 'email' => true, 'push' => false],
        'subscription.expiring' => ['inApp' => true, 'email' => true, 'push' => false],
        'quota.warning' => ['inApp' => true, 'email' => true, 'push' => false],
        'reminder.responded' => ['inApp' => true, 'email' => false, 'push' => false],
    ];

    private const DEFAULT_QUIET_HOURS = ['enabled' => false, 'startsAt' => '22:00', 'endsAt' => '07:00'];

    public function get(AsabUser $user): array
    {
        return $this->present(AsabNotificationPreference::find($user->id), $user);
    }

    /**
     * Merge a partial preferences body and persist. Returns the full object.
     *
     * @param  array<string,mixed>  $body
     */
    public function update(AsabUser $user, array $body): array
    {
        $pref = DB::transaction(function () use ($user, $body) {
            $pref = AsabNotificationPreference::firstOrNew(['user_id' => $user->id]);
            $channels = $body['channels'] ?? [];

            foreach ([
                'inApp' => 'inapp_enabled', 'email' => 'email_enabled',
                'push' => 'push_enabled', 'whatsapp' => 'whatsapp_enabled',
            ] as $key => $column) {
                if (array_key_exists('enabled', (array) ($channels[$key] ?? []))) {
                    $pref->{$column} = (bool) $channels[$key]['enabled'];
                }
            }
            if (array_key_exists('address', (array) ($channels['email'] ?? []))) {
                $pref->email_address = $channels['email']['address'] ?: null;
            }

            if (isset($body['events']) && is_array($body['events'])) {
                $pref->events = array_replace_recursive(
                    $pref->events ?? self::DEFAULT_EVENTS,
                    $body['events'],
                );
            }

            if (isset($body['quietHours']) && is_array($body['quietHours'])) {
                $pref->quiet_hours = array_replace($pref->quiet_hours ?? self::DEFAULT_QUIET_HOURS, $body['quietHours']);
            }

            $pref->save();

            return $pref;
        });

        return $this->present($pref, $user);
    }

    private function present(?AsabNotificationPreference $pref, AsabUser $user): array
    {
        return [
            'channels' => [
                'inApp' => ['enabled' => (bool) ($pref->inapp_enabled ?? true)],
                'email' => [
                    'enabled' => (bool) ($pref->email_enabled ?? true),
                    'address' => $pref?->email_address ?: $user->email,
                ],
                'push' => ['enabled' => (bool) ($pref->push_enabled ?? false)],
                'whatsapp' => ['enabled' => (bool) ($pref->whatsapp_enabled ?? false)],
            ],
            'events' => array_replace(self::DEFAULT_EVENTS, $pref?->events ?? []),
            'quietHours' => $pref?->quiet_hours ?? self::DEFAULT_QUIET_HOURS,
        ];
    }
}
