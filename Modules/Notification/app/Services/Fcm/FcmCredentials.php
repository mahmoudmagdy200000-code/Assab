<?php

namespace Modules\Notification\Services\Fcm;

use Modules\Notification\Exceptions\FcmConfigurationException;

/**
 * Reads and validates the Firebase service-account JSON key once per process.
 * The private key never leaves this object and is never logged.
 */
class FcmCredentials
{
    private ?array $decoded = null;

    public function __construct(
        private readonly ?string $credentialsPath,
        private readonly ?string $configuredProjectId = null,
    ) {}

    public function projectId(): string
    {
        $projectId = $this->configuredProjectId ?: ($this->all()['project_id'] ?? null);

        if (! $projectId) {
            throw FcmConfigurationException::missingProjectId();
        }

        return $projectId;
    }

    public function clientEmail(): string
    {
        return $this->required('client_email');
    }

    public function privateKey(): string
    {
        return $this->required('private_key');
    }

    public function tokenUri(): string
    {
        return $this->all()['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    }

    /**
     * True when a usable key file is present. Lets callers degrade gracefully
     * (e.g. a health check) instead of catching a configuration exception.
     */
    public function isConfigured(): bool
    {
        return is_string($this->credentialsPath)
            && $this->credentialsPath !== ''
            && is_readable($this->credentialsPath);
    }

    private function required(string $key): string
    {
        $value = $this->all()[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw FcmConfigurationException::invalidCredentials("missing `{$key}`");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        if ($this->decoded !== null) {
            return $this->decoded;
        }

        if (! $this->isConfigured()) {
            throw FcmConfigurationException::missingCredentials($this->credentialsPath);
        }

        $raw = file_get_contents($this->credentialsPath);

        if ($raw === false) {
            throw FcmConfigurationException::missingCredentials($this->credentialsPath);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw FcmConfigurationException::invalidCredentials('not valid JSON — '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw FcmConfigurationException::invalidCredentials('expected a JSON object');
        }

        return $this->decoded = $decoded;
    }
}
