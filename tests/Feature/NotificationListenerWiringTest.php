<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Events\BranchManagerSuspendedEvent;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Events\CashierCreatedEvent;
use Modules\Cashier\Models\Cashier;
use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Providers\EventServiceProvider;
use Modules\Notification\Services\DeviceTokenService;
use Modules\Shift\Events\VarianceRecorded;
use Modules\Shift\Models\CashierShift;
use Tests\Fakes\RecordingFcmClient;
use Tests\TestCase;

/**
 * Cross-module wiring: a domain event fires, the right people get notified.
 */
class NotificationListenerWiringTest extends TestCase
{
    use RefreshDatabase;

    private RecordingFcmClient $fcm;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fcm = new RecordingFcmClient;
        $this->app->instance(FcmClientInterface::class, $this->fcm);

        $this->branch = Branch::factory()->create();
    }

    private function registerDevice(object $owner, string $seed): string
    {
        return $this->app->make(DeviceTokenService::class)->register(
            $owner,
            str_repeat($seed, 40).Str::random(80),
            ['platform' => 'android', 'locale' => 'en', 'device_id' => 'dev-'.$seed]
        )->token;
    }

    public function test_every_registered_listener_resolves_from_the_container(): void
    {
        $listeners = (new \ReflectionClass(EventServiceProvider::class))
            ->getDefaultProperties()['listen'];

        $this->assertNotEmpty($listeners);

        foreach ($listeners as $event => $handlers) {
            $this->assertTrue(class_exists($event), "Event [{$event}] does not exist");

            foreach ($handlers as $handler) {
                $this->assertTrue(class_exists($handler), "Listener [{$handler}] does not exist");
                $this->assertTrue(
                    method_exists($this->app->make($handler), 'handle'),
                    "Listener [{$handler}] has no handle()"
                );
            }
        }
    }

    public function test_cashier_creation_notifies_the_cashier_without_leaking_the_password(): void
    {
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->registerDevice($cashier, 'a');

        event(new CashierCreatedEvent($cashier, 'Sup3rSecret!'));

        $this->assertSame(1, $cashier->notifications()->count());
        $this->assertSame(
            NotificationType::CASHIER_ACCOUNT_CREATED->value,
            $cashier->notifications()->value('data')['type'] ?? null
        );

        // The generated password rides on the event but must never reach a push
        // payload — it lands in the OS notification log.
        $payload = json_encode($this->fcm->sent[0]['message']);
        $this->assertStringNotContainsString('Sup3rSecret!', $payload);
    }

    public function test_branch_manager_suspension_notifies_the_manager_with_the_reason(): void
    {
        $manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->registerDevice($manager, 'b');

        event(new BranchManagerSuspendedEvent($manager, 'Repeated cash variances'));

        // Exactly one in-app row: the module's own AccountSuspendedNotification
        // is mail-only now, so the unified pipeline owns the record.
        $this->assertSame(1, $manager->notifications()->count());
        $this->assertStringContainsString(
            'Repeated cash variances',
            $this->fcm->sent[0]['message']->body
        );
    }

    public function test_shift_variance_notifies_the_cashier_and_their_branch_managers(): void
    {
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);

        $cashierToken = $this->registerDevice($cashier, 'c');
        $managerToken = $this->registerDevice($manager, 'd');

        $shift = CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'shift_date' => now()->toDateString(),
        ]);

        event(new VarianceRecorded($shift));

        $this->assertEqualsCanonicalizing(
            [$cashierToken, $managerToken],
            $this->fcm->sentTokens()
        );
    }
}
