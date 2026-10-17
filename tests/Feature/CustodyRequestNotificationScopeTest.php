<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * BRC v3.4 F-09 (notifications) — a branch manager's funding request ("custody_request_created") used to be fanned out to
 * EVERY active brand owner (`sendToRole('brand_owner')`; `brand_owners` has no branch column, so the branch filter was a
 * no-op), disclosing the branch id and amount to other companies' owners. Only owners whose brands (server-resolved,
 * fail-closed) include the request's branch are notified; an unlinked owner gets nothing.
 */
class CustodyRequestNotificationScopeTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
    }

    /** @return string[] the brand owners that got an in-app "custody request created" row, sorted */
    private function raiseAndCollectRecipients(BranchManager $manager, float $amount = 777.0): array
    {
        DB::table('notifications')->delete();
        $this->actingAs($manager, 'sanctum')->postJson('/api/custody/request-cashin', [
            'requestedAmount' => $amount, 'purpose' => 'branch float', 'preferredReceiptMethod' => 'cash_handover',
        ])->assertStatus(201);

        return DB::table('notifications')->where('data', 'like', '%custody_request_created%')
            ->where('notifiable_type', 'like', '%BrandOwner%')
            ->pluck('notifiable_id')->unique()->sort()->values()->all();
    }

    private function ids(BrandOwner ...$owners): array
    {
        return collect($owners)->pluck('id')->sort()->values()->all();
    }

    public function test_only_owners_of_the_requests_brand_are_notified(): void
    {
        $this->assertSame($this->ids($this->ownerA1, $this->ownerA12), $this->raiseAndCollectRecipients($this->bmA1));
        $this->assertSame($this->ids($this->ownerA12), $this->raiseAndCollectRecipients($this->bmA2));
        $this->assertSame($this->ids($this->ownerB1), $this->raiseAndCollectRecipients($this->bmB1));
    }

    public function test_an_unlinked_owner_and_a_foreign_company_owner_never_receive_request_data(): void
    {
        $recipients = $this->raiseAndCollectRecipients($this->bmA1, 123456.0);

        $this->assertNotContains($this->ownerUnlinked->id, $recipients);
        $this->assertNotContains($this->ownerB1->id, $recipients);
        // and nothing mentioning the amount sits in any foreign / unlinked owner's inbox
        $leaked = DB::table('notifications')->whereIn('notifiable_id', [$this->ownerB1->id, $this->ownerUnlinked->id])->count();
        $this->assertSame(0, $leaked);
    }

    public function test_an_inactive_in_scope_owner_is_not_notified(): void
    {
        $this->ownerA1->forceFill(['is_active' => false])->save();

        $this->assertSame($this->ids($this->ownerA12), $this->raiseAndCollectRecipients($this->bmA1));
    }
}
