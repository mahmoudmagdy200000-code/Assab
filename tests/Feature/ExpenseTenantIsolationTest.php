<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Cashier\Models\Cashier;
use Modules\Expense\Events\ExpenseApprovedEvent;
use Modules\Expense\Events\ExpenseRejectedEvent;
use Modules\Expense\Models\Expense;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * EXP-06 security follow-up (G-1 / G-2 / G-3): the expense decision, list, detail,
 * timeline, view and edit routes a brand owner / brand manager can reach are scoped to
 * the brands that actor is assigned to. Everything runs through the real routes and
 * middleware as an authenticated actor; no service is called directly.
 *
 * Denied means: a uniform 404 (an out-of-scope UUID is indistinguishable from a
 * non-existent one) and ZERO writes anywhere a decision could land.
 */
class ExpenseTenantIsolationTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private const REASON = 'الفاتورة غير واضحة ويجب إعادة التصوير';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function asActor(object $actor): static
    {
        return $this->actingAs($actor, 'sanctum');
    }

    /** @return string[] */
    private function ids(TestResponse $response): array
    {
        $data = $response->json('data');
        $rows = is_array($data) && array_key_exists('data', $data) ? $data['data'] : ($data ?? []);

        return collect($rows)->pluck('id')->sort()->values()->all();
    }

    /** The three decision URLs a brand actor can reach (brand-owner group + shared group). */
    private function decisionUrls(string $verb, string $id): array
    {
        return [
            "/api/brand-owner/expenses/{$id}/{$verb}",
            "/api/branch-manager/expenses/{$id}/{$verb}",
        ];
    }

    private function assertDeniedWithoutWrites(callable $call): void
    {
        Event::fake([ExpenseApprovedEvent::class, ExpenseRejectedEvent::class]);
        $before = $this->writeSnapshot();

        $call();

        $this->assertSame($before, $this->writeSnapshot(), 'a denied request wrote to the database');
        Event::assertNotDispatched(ExpenseApprovedEvent::class);
        Event::assertNotDispatched(ExpenseRejectedEvent::class);
    }

    // ── G-3: authorized flows keep working ───────────────────────────────────

    public function test_owner_approves_own_brand_expense(): void
    {
        $viaOwnerRoute = $this->pendingExpense($this->bmA1);
        $viaSharedRoute = $this->pendingExpense($this->bmA1);

        $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$viaOwnerRoute->id}/approve")
            ->assertOk()->assertJsonPath('success', true);
        $this->asActor($this->ownerA1)->postJson("/api/branch-manager/expenses/{$viaSharedRoute->id}/approve")
            ->assertOk()->assertJsonPath('success', true);

        foreach ([$viaOwnerRoute, $viaSharedRoute] as $expense) {
            $expense = $expense->fresh();
            $this->assertSame('approved', $expense->status);
            $this->assertSame('brand_owner', $expense->decided_by_role);
            $this->assertSame($this->ownerA1->id, $expense->approved_by);
            $this->assertSame('Owner A1', $expense->decided_by_name);
        }
    }

    public function test_owner_rejects_own_brand_expense(): void
    {
        $expense = $this->pendingExpense($this->bmA1);

        $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$expense->id}/reject", ['reason' => self::REASON])
            ->assertOk();

        $expense = $expense->fresh();
        $this->assertSame('rejected', $expense->status);
        $this->assertSame('brand_owner', $expense->decided_by_role);
        $this->assertSame($this->ownerA1->id, $expense->rejected_by);
        $this->assertSame(self::REASON, $expense->rejection_reason);
    }

    public function test_assigned_brand_manager_approves_assigned_brand_expense(): void
    {
        $expense = $this->pendingExpense($this->bmA1);

        $this->asActor($this->managerA1)->postJson("/api/brand-owner/expenses/{$expense->id}/approve")
            ->assertOk()->assertJsonPath('success', true);

        $expense = $expense->fresh();
        $this->assertSame('approved', $expense->status);
        $this->assertSame('brand_manager', $expense->decided_by_role, 'G-1: a brand manager is not recorded as a brand owner');
        $this->assertSame($this->managerA1->id, $expense->approved_by, 'actor id is preserved');
        $this->assertSame('Manager A1', $expense->decided_by_name);
    }

    public function test_assigned_brand_manager_rejects_assigned_brand_expense(): void
    {
        $expense = $this->pendingExpense($this->bmA1);

        $this->asActor($this->managerA1)->postJson("/api/branch-manager/expenses/{$expense->id}/reject", ['reason' => self::REASON])
            ->assertOk();

        $expense = $expense->fresh();
        $this->assertSame('rejected', $expense->status);
        $this->assertSame('brand_manager', $expense->decided_by_role);
        $this->assertSame($this->managerA1->id, $expense->rejected_by);
    }

    public function test_decision_attribution_distinguishes_owner_and_manager_in_every_representation(): void
    {
        $byOwner = $this->pendingExpense($this->bmA1);
        $byManager = $this->pendingExpense($this->bmA1);

        $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$byOwner->id}/approve")->assertOk();
        $this->asActor($this->managerA1)->postJson("/api/brand-owner/expenses/{$byManager->id}/approve")->assertOk();

        // API response (detail + list)
        $this->asActor($this->ownerA1)->getJson("/api/brand-owner/expenses/{$byOwner->id}")
            ->assertOk()->assertJsonPath('data.decided_by.role', 'brand_owner');
        $this->asActor($this->managerA1)->getJson("/api/brand-owner/expenses/{$byManager->id}")
            ->assertOk()->assertJsonPath('data.decided_by.role', 'brand_manager');

        // Dashboard mirror evidence (operation payload + approval step)
        $ownerOp = Operation::where('source_id', $byOwner->id)->firstOrFail();
        $managerOp = Operation::where('source_id', $byManager->id)->firstOrFail();
        $this->assertSame('brand_owner', $ownerOp->payload['legacyDecision']['byRole']);
        $this->assertSame('brand_manager', $managerOp->payload['legacyDecision']['byRole']);
        $this->assertNotSame($ownerOp->payload['legacyDecision']['byRoleLabelAr'], $managerOp->payload['legacyDecision']['byRoleLabelAr']);
        $managerStep = ApprovalStep::where('operation_id', $managerOp->id)->where('stage_id', 'final')->firstOrFail();
        $this->assertStringContainsString('مدير العلامة التجارية', $managerStep->action);
        $this->assertSame('Manager A1', $managerStep->actor_label);

        // Timeline attributes the brand manager by type AND keeps the real actor id (G-1).
        $line = DB::table('expense_timelines')->where('expense_id', $byManager->id)->where('action', 'approve')->first();
        $this->assertSame('brand_manager', $line->performed_by_type);
        $this->assertSame($this->managerA1->id, $line->performed_by);

        // And an owner decision still records brand_owner.
        $ownerLine = DB::table('expense_timelines')->where('expense_id', $byOwner->id)->where('action', 'approve')->first();
        $this->assertSame('brand_owner', $ownerLine->performed_by_type);
    }

    public function test_multi_brand_owner_decides_each_owned_brand_but_not_another_company(): void
    {
        $a1 = $this->pendingExpense($this->bmA1);
        $a2 = $this->pendingExpense($this->bmA2);
        $b1 = $this->pendingExpense($this->bmB1);

        $this->asActor($this->ownerA12)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertOk();
        $this->asActor($this->ownerA12)->postJson("/api/brand-owner/expenses/{$a2->id}/reject", ['reason' => self::REASON])->assertOk();

        $this->assertDeniedWithoutWrites(function () use ($b1) {
            $this->asActor($this->ownerA12)->postJson("/api/brand-owner/expenses/{$b1->id}/approve")->assertNotFound();
        });
        $this->assertSame('pending', $b1->fresh()->status);
    }

    // ── G-2: denied decisions ────────────────────────────────────────────────

    public function test_brand_manager_cannot_decide_an_unassigned_brand_expense(): void
    {
        $a2 = $this->pendingExpense($this->bmA2);

        foreach (['approve', 'reject'] as $verb) {
            foreach ($this->decisionUrls($verb, $a2->id) as $url) {
                $this->assertDeniedWithoutWrites(function () use ($url, $verb) {
                    $this->asActor($this->managerA1)->postJson($url, $verb === 'reject' ? ['reason' => self::REASON] : [])
                        ->assertNotFound()->assertJsonPath('success', false);
                });
            }
        }
        $this->assertSame('pending', $a2->fresh()->status);
    }

    public function test_brand_manager_without_any_assignment_decides_nothing(): void
    {
        $a1 = $this->pendingExpense($this->bmA1);

        $this->assertDeniedWithoutWrites(function () use ($a1) {
            $this->asActor($this->managerUnassigned)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertNotFound();
            $this->asActor($this->managerUnassigned)->postJson("/api/brand-owner/expenses/{$a1->id}/reject", ['reason' => self::REASON])->assertNotFound();
        });
    }

    public function test_owner_without_any_dashboard_link_decides_nothing_fail_closed(): void
    {
        $a1 = $this->pendingExpense($this->bmA1);

        $this->assertDeniedWithoutWrites(function () use ($a1) {
            $this->asActor($this->ownerUnlinked)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertNotFound();
        });
    }

    public function test_owner_cannot_decide_another_owners_expense_in_same_or_other_company(): void
    {
        $a2 = $this->pendingExpense($this->bmA2); // same company, brand A1-owner is not assigned to A2
        $b1 = $this->pendingExpense($this->bmB1); // other company

        foreach ([$a2, $b1] as $expense) {
            foreach (['approve', 'reject'] as $verb) {
                foreach ($this->decisionUrls($verb, $expense->id) as $url) {
                    $this->assertDeniedWithoutWrites(function () use ($url, $verb) {
                        $this->asActor($this->ownerA1)->postJson($url, $verb === 'reject' ? ['reason' => self::REASON] : [])->assertNotFound();
                    });
                }
            }
        }

        // …and the reverse direction: owner of company B cannot touch company A.
        $a1 = $this->pendingExpense($this->bmA1);
        $this->assertDeniedWithoutWrites(function () use ($a1) {
            $this->asActor($this->ownerB1)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertNotFound();
        });
        $this->assertSame('pending', $a1->fresh()->status);
    }

    public function test_cross_company_custody_expense_leaves_custody_and_ledger_untouched_when_denied(): void
    {
        $b1 = $this->pendingExpense($this->bmB1, ['payment_method' => 'custody', 'total_amount' => 900.00]);

        $this->assertDeniedWithoutWrites(function () use ($b1) {
            $this->asActor($this->ownerA12)->postJson("/api/brand-owner/expenses/{$b1->id}/approve")->assertNotFound();
        });
        $this->assertSame(0, DB::table('custody_transactions')->count());
        $this->assertSame(0, DB::table('personal_ledger_transactions')->count());
    }

    public function test_unauthorized_uuid_is_indistinguishable_from_a_missing_uuid(): void
    {
        $b1 = $this->pendingExpense($this->bmB1);
        $missing = '00000000-0000-4000-8000-000000000000';

        $forbidden = $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$b1->id}/approve");
        $absent = $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$missing}/approve");

        $this->assertSame($absent->status(), $forbidden->status());
        $this->assertSame(404, $forbidden->status());
        $this->assertSame($absent->json('message'), $forbidden->json('message'));
        $this->assertStringNotContainsString('Modules', (string) $forbidden->getContent(), 'no internal model name leaks');
    }

    public function test_request_supplied_scope_fields_are_never_authorization(): void
    {
        $b1 = $this->pendingExpense($this->bmB1);

        $this->assertDeniedWithoutWrites(function () use ($b1) {
            $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$b1->id}/approve", [
                'brand_id' => $this->brandA1->id, 'company_id' => $this->companyA->id,
                'branch_id' => $this->branchA1->id, 'branch_manager_id' => $this->bmA1->id,
            ])->assertNotFound();
            $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$b1->id}/reject", [
                'reason' => self::REASON, 'brand_id' => $this->brandA1->id, 'branch_manager_id' => $this->bmA1->id,
            ])->assertNotFound();
        });
    }

    public function test_branch_manager_remains_denied_as_an_expense_approver(): void
    {
        $a1 = $this->pendingExpense($this->bmA1);

        $this->assertDeniedWithoutWrites(function () use ($a1) {
            // Shared route: reaches the controller, explicit 403.
            $this->asActor($this->bmA1)->postJson("/api/branch-manager/expenses/{$a1->id}/approve")->assertForbidden();
            $this->asActor($this->bmA1)->postJson("/api/branch-manager/expenses/{$a1->id}/reject", ['reason' => self::REASON])->assertForbidden();
            // Brand-owner group: blocked by the role middleware.
            $this->asActor($this->bmA1)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertForbidden();
            $this->asActor($this->bmA1)->postJson("/api/brand-owner/expenses/{$a1->id}/reject", ['reason' => self::REASON])->assertForbidden();
        });
        $this->assertSame('pending', $a1->fresh()->status);
    }

    public function test_dashboard_accountant_head_and_cashier_are_not_expense_approvers(): void
    {
        $a1 = $this->pendingExpense($this->bmA1);
        $cashier = Cashier::factory()->create(['branch_id' => $this->branchA1->id, 'created_by' => $this->bmA1->id]);

        foreach (['accountant', 'head'] as $role) {
            $dash = AsabUser::create([
                'company_id' => $this->companyA->id, 'name' => $role.' A', 'email' => $role.'.a@tenant.test',
                'password' => 'secret-password', 'status' => 'active',
            ]);
            AsabUserRole::create(['user_id' => $dash->id, 'role_key' => $role, 'scope' => 'all']);
            $this->assertDeniedWithoutWrites(function () use ($dash, $a1) {
                $this->asActor($dash)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertForbidden();
                $this->asActor($dash)->postJson("/api/branch-manager/expenses/{$a1->id}/approve")->assertForbidden();
                $this->asActor($dash)->postJson("/api/branch-manager/expenses/{$a1->id}/reject", ['reason' => self::REASON])->assertForbidden();
                $this->asActor($dash)->getJson('/api/brand-owner/expenses')->assertForbidden();
            });
        }

        $this->assertDeniedWithoutWrites(function () use ($cashier, $a1) {
            $this->asActor($cashier)->postJson("/api/branch-manager/expenses/{$a1->id}/approve")->assertForbidden();
            $this->asActor($cashier)->postJson("/api/branch-manager/expenses/{$a1->id}/reject", ['reason' => self::REASON])->assertForbidden();
            $this->asActor($cashier)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertForbidden();
        });
        $this->assertSame([], $this->ids($this->asActor($cashier)->getJson('/api/branch-manager/expenses')->assertOk()));
        $this->assertSame('pending', $a1->fresh()->status);
    }

    public function test_second_decision_is_rejected_and_writes_nothing(): void
    {
        $a1 = $this->pendingExpense($this->bmA1);
        $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertOk();

        $this->assertDeniedWithoutWrites(function () use ($a1) {
            $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertStatus(400);
            $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$a1->id}/reject", ['reason' => self::REASON])->assertStatus(400);
            // a different authorized actor of the same brand cannot flip it either
            $this->asActor($this->managerA1)->postJson("/api/brand-owner/expenses/{$a1->id}/reject", ['reason' => self::REASON])->assertStatus(400);
        });
        $this->assertSame('approved', $a1->fresh()->status);
        $this->assertSame('brand_owner', $a1->fresh()->decided_by_role);
    }

    public function test_authorized_custody_approval_keeps_its_existing_financial_effect(): void
    {
        $a1 = $this->pendingExpense($this->bmA1, ['payment_method' => 'custody', 'total_amount' => 250.00]);

        $this->asActor($this->managerA1)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertOk();

        $rows = DB::table('custody_transactions')->where('related_expense_id', $a1->id)->count()
            + DB::table('personal_ledger_transactions')->where('related_expense_id', $a1->id)->count();
        $this->assertGreaterThanOrEqual(1, $rows, 'approval still posts the expense deduction (BRC-02 owns any change to it)');
        $this->assertSame(1, DB::table('custody_transactions')->where('related_expense_id', $a1->id)->count()
            + DB::table('personal_ledger_transactions')->where('related_expense_id', $a1->id)->count(), 'exactly one deduction row set per approval');
    }

    // ── G-2: list / detail / timeline / view / edit isolation ───────────────

    public function test_lists_only_disclose_expenses_of_assigned_brands(): void
    {
        $a1 = $this->pendingExpense($this->bmA1);
        $a2 = $this->pendingExpense($this->bmA2);
        $b1 = $this->pendingExpense($this->bmB1);

        foreach (['/api/brand-owner/expenses', '/api/branch-manager/expenses'] as $url) {
            $this->assertSame([$a1->id], $this->ids($this->asActor($this->ownerA1)->getJson($url)->assertOk()), $url);
            $this->assertSame(collect([$a1->id, $a2->id])->sort()->values()->all(), $this->ids($this->asActor($this->ownerA12)->getJson($url)->assertOk()), $url);
            $this->assertSame([$b1->id], $this->ids($this->asActor($this->ownerB1)->getJson($url)->assertOk()), $url);
            $this->assertSame([$a1->id], $this->ids($this->asActor($this->managerA1)->getJson($url)->assertOk()), $url);
            $this->assertSame([], $this->ids($this->asActor($this->managerUnassigned)->getJson($url)->assertOk()), $url);
            $this->assertSame([], $this->ids($this->asActor($this->ownerUnlinked)->getJson($url)->assertOk()), $url);
        }
    }

    public function test_list_filters_cannot_widen_the_scope(): void
    {
        $this->pendingExpense($this->bmA1);
        $b1 = $this->pendingExpense($this->bmB1);

        $res = $this->asActor($this->ownerA1)->getJson('/api/brand-owner/expenses?branch_manager_id='.$this->bmB1->id.'&status=pending&per_page=100')->assertOk();
        $this->assertNotContains($b1->id, $this->ids($res));
        $this->assertSame([], $this->ids($res));
    }

    public function test_recent_and_summary_exclude_other_tenants(): void
    {
        $this->pendingExpense($this->bmA1, ['status' => 'approved', 'total_amount' => 100.00, 'net_amount' => 100.00]);
        $b1 = $this->pendingExpense($this->bmB1, ['status' => 'approved', 'total_amount' => 7000.00, 'net_amount' => 7000.00]);

        $recent = $this->asActor($this->ownerA1)->getJson('/api/branch-manager/expenses/recent')->assertOk();
        $this->assertNotContains($b1->id, $this->ids($recent));
        $this->assertCount(1, $this->ids($recent));

        $summary = $this->asActor($this->ownerA1)->getJson('/api/branch-manager/expenses/summary')->assertOk();
        $this->assertSame(1, $summary->json('data.total_expenses'));
        $this->assertEquals(100.0, $summary->json('data.total_amount'));
    }

    public function test_detail_timeline_view_and_edit_are_denied_out_of_scope_without_writes(): void
    {
        $a1 = $this->pendingExpense($this->bmA1);
        $b1 = $this->pendingExpense($this->bmB1);
        $a2 = $this->pendingExpense($this->bmA2);

        foreach ([$b1, $a2] as $foreign) {
            $this->assertDeniedWithoutWrites(function () use ($foreign) {
                $id = $foreign->id;
                $this->asActor($this->ownerA1)->getJson("/api/brand-owner/expenses/{$id}")->assertNotFound();
                $this->asActor($this->ownerA1)->getJson("/api/branch-manager/expenses/{$id}")->assertNotFound();
                $this->asActor($this->ownerA1)->getJson("/api/brand-owner/expenses/{$id}/timeline")->assertNotFound();
                $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$id}/view")->assertNotFound();
                $this->asActor($this->ownerA1)->putJson("/api/brand-owner/expenses/{$id}/edit", ['note' => 'tamper'])->assertNotFound();
                $this->asActor($this->managerA1)->getJson("/api/brand-owner/expenses/{$id}")->assertNotFound();
            });
        }

        // In scope the same calls still work, and view/edit still record their timeline entry.
        $this->asActor($this->ownerA1)->getJson("/api/brand-owner/expenses/{$a1->id}")->assertOk()->assertJsonPath('data.id', $a1->id);
        $this->asActor($this->ownerA1)->getJson("/api/branch-manager/expenses/{$a1->id}")->assertOk();
        $this->asActor($this->ownerA1)->getJson("/api/brand-owner/expenses/{$a1->id}/timeline")->assertOk();
        $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$a1->id}/view")->assertOk();
        $this->asActor($this->ownerA1)->putJson("/api/brand-owner/expenses/{$a1->id}/edit", ['note' => 'ok'])->assertOk();
        $this->assertSame(2, DB::table('expense_timelines')->where('expense_id', $a1->id)->whereIn('action', ['view', 'edit'])->count());
    }

    public function test_expense_of_a_soft_deleted_branch_manager_stays_in_its_brand_scope(): void
    {
        $a1 = $this->pendingExpense($this->bmA1);
        $this->bmA1->delete();

        $this->asActor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$a1->id}/approve")->assertOk();
        $this->assertSame('approved', $a1->fresh()->status);
        $this->asActor($this->ownerB1)->getJson("/api/brand-owner/expenses/{$a1->id}")->assertNotFound();
    }

    public function test_branch_manager_side_routes_remain_owner_only_adjacent_check(): void
    {
        // Pre-existing protection (documented, unchanged): a branch manager cannot read or touch another manager's expense.
        $a2 = $this->pendingExpense($this->bmA2);

        $this->asActor($this->bmA1)->getJson("/api/branch-manager/expenses/{$a2->id}")->assertForbidden();
        $this->asActor($this->bmA1)->getJson("/api/branch-manager/expenses/{$a2->id}/timeline")->assertForbidden();
        $this->asActor($this->bmA1)->getJson("/api/branch-manager/expenses/{$a2->id}/attachments")->assertForbidden();
    }
}
