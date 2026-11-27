<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Notifications\Notification;
use Modules\Admin\Services\CredentialMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CredentialMailerTest extends TestCase
{
    public function test_nested_failover_to_log_transport_is_not_deliverable(): void
    {
        $mailer = new CredentialMailer(new Repository([
            'mail' => ['default' => 'primary', 'mailers' => [
                'primary' => ['transport' => 'failover', 'mailers' => ['smtp', 'secondary']],
                'smtp' => ['transport' => 'smtp'],
                'secondary' => ['transport' => 'failover', 'mailers' => ['log']],
                'log' => ['transport' => 'log'],
            ]],
        ]), $this->createMock(LoggerInterface::class));

        $this->assertFalse($mailer->deliverable());
    }

    public function test_unknown_failover_mailers_fail_closed(): void
    {
        $mailer = new CredentialMailer(new Repository([
            'mail' => ['default' => 'failover', 'mailers' => [
                'failover' => ['transport' => 'failover', 'mailers' => ['missing']],
            ]],
        ]), $this->createMock(LoggerInterface::class));

        $this->assertFalse($mailer->deliverable());
    }

    public function test_missing_default_mailer_fails_closed(): void
    {
        $mailer = new CredentialMailer(new Repository([]), $this->createMock(LoggerInterface::class));

        $this->assertFalse($mailer->deliverable());
    }

    public function test_array_transport_is_deliverable_only_in_testing_environment(): void
    {
        $mailers = ['array' => ['transport' => 'array']];
        $stagingMailer = new CredentialMailer(new Repository([
            'app' => ['env' => 'staging'],
            'mail' => ['default' => 'array', 'mailers' => $mailers],
        ]), $this->createMock(LoggerInterface::class));
        $testMailer = new CredentialMailer(new Repository([
            'app' => ['env' => 'testing'],
            'mail' => ['default' => 'array', 'mailers' => $mailers],
        ]), $this->createMock(LoggerInterface::class));

        $this->assertFalse($stagingMailer->deliverable());
        $this->assertTrue($testMailer->deliverable());
    }

    public function test_transport_exception_message_is_never_logged(): void
    {
        $credential = 'Sensitive-Test-Credential-Do-Not-Log';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->callback(
            fn (string $message): bool => ! str_contains($message, $credential)
        ));
        $mailer = new CredentialMailer(new Repository([
            'mail' => ['default' => 'smtp', 'mailers' => ['smtp' => ['transport' => 'smtp']]],
        ]), $logger);
        $notifiable = new class($credential) {
            public bool $wasCalled = false;
            public function __construct(private readonly string $credential) {}
            public function notify(Notification $notification): void
            {
                $this->wasCalled = true;
                throw new \RuntimeException('transport rejected '.$this->credential);
            }
        };

        $this->assertFalse($mailer->send($notifiable, new class extends Notification {}));
        $this->assertTrue($notifiable->wasCalled);
    }
}
