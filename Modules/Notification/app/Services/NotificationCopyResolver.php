<?php

namespace Modules\Notification\Services;

use Illuminate\Contracts\Translation\Translator;
use Modules\Notification\Enums\NotificationType;

/**
 * Single source of notification copy for every channel.
 *
 * Copy is resolved against the *recipient's* locale rather than the request
 * locale, because the sender is normally a queue worker or a different user.
 */
class NotificationCopyResolver
{
    public const TITLE_LIMIT = 60;

    public const BODY_LIMIT = 180;

    public function __construct(
        private readonly Translator $translator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function title(NotificationType $type, array $data, string $locale): string
    {
        return mb_substr(
            $this->line("notification::push.{$type->value}.title", $data, $locale, $type->label()),
            0,
            self::TITLE_LIMIT
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function body(NotificationType $type, array $data, string $locale): string
    {
        return mb_substr(
            $this->line("notification::push.{$type->value}.body", $data, $locale, $type->label()),
            0,
            self::BODY_LIMIT
        );
    }

    /**
     * The locale this recipient reads in: explicit user setting first, then the
     * locale their most recent device reported, then the app default.
     */
    public function localeFor(object $notifiable): string
    {
        foreach (['locale', 'language'] as $attribute) {
            $value = $notifiable->{$attribute} ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (method_exists($notifiable, 'settings')) {
            try {
                $language = $notifiable->settings()->value('language');

                if (is_string($language) && $language !== '') {
                    return $language;
                }
            } catch (\Throwable) {
                // Model declares settings() but the table is absent in this
                // context (e.g. a partial test schema) — fall through.
            }
        }

        if (method_exists($notifiable, 'preferredPushLocale')) {
            try {
                return $notifiable->preferredPushLocale();
            } catch (\Throwable) {
                // Same rationale as above.
            }
        }

        return (string) config('app.locale', 'en');
    }

    /**
     * @param  array<string, mixed>  $replacements
     */
    private function line(string $key, array $replacements, string $locale, string $fallback): string
    {
        $line = $this->translator->get($key, $this->scalarsOnly($replacements), $locale);

        // Laravel echoes the key back when a line is missing. A raw translation
        // key on a lock screen is worse than the untranslated label.
        return is_string($line) && $line !== $key ? $line : $fallback;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function scalarsOnly(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return $out;
    }
}
