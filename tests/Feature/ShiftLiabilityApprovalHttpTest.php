<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class ShiftLiabilityApprovalHttpTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private Employee $employee;

    private Shift $template;

    private CashierShift $cashierShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Liability HTTP Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $this->company->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'is_active' => true,
        ]);
        $this->cashierA = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);
        $this->cashierB = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->employee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => '1001',
            'name' => 'Employee 1',
            'role' => 'كاشير',
            'monthly_salary' => 500000,
            'status' => 'active',
        ]);

        $this->template = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->cashierShift = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        // Setup report revision and cash count with 100.00 SAR (10,000 halalas) shortage
        DB::transaction(function () {
            $rev = app(ShiftReportRevisionService::class)->recordCashierRevision(
                $this->cashierShift,
                'cashier',
                $this->cashierA->id,
                0
            );
            app(ShiftCashCountService::class)->record(
                $this->cashierShift,
                $rev,
                10000, // gross
                0,     // cards
                0,     // apps
                0      // counted: variance = -10000 halalas
            );
        });
    }

    public function test_f09_authenticated_employee_responds_only_for_own_linked_share(): void
    {
        $user = \Modules\Admin\Models\AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'Linked employee',
            'email' => Str::uuid().'@example.test', 'password' => 'secret', 'status' => 'active',
        ]);
        $this->employee->update(['asab_user_id' => $user->id]);
        $this->actingAs($this->cashierA, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'expected_revision' => 1, 'expected_version' => 0,
                'shares' => [['type' => 'employee', 'id' => $this->employee->id, 'amount' => 10000]],
            ])->assertCreated();
        $key = (string) Str::uuid();
        $uri = "/api/v1/shift-reports/{$this->cashierShift->id}/liability/respond";
        $body = ['version' => 1, 'response' => 'objected', 'reason' => 'Recorded objection'];
        $first = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', $key)->postJson($uri, $body);
        $first->assertOk();
        $this->postJson($uri, $body)->assertOk()->assertContent($first->getContent());
        $this->assertDatabaseHas('shift_liability_shares', [
            'responsible_id' => $this->employee->id, 'employee_response_status' => 'objected',
        ]);
        $this->employee->update(['status' => 'inactive']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($uri, $body)->assertForbidden();
    }

    public function test_f09_allocation_requires_explicit_current_report_revision(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 10000]],
                'expected_version' => 0,
            ])->assertStatus(422);
        $this->assertSame(0, ShiftLiabilityAllocation::count());
    }

    public function test_f09_approval_requires_explicit_current_report_revision(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 10000]],
                'expected_version' => 0, 'expected_revision' => 1, 'cashier_confirmed' => true,
            ])->assertStatus(201);
        $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 1,
            ])->assertStatus(422);
        $this->assertSame(0, \Modules\Shift\Models\ShiftLiabilityPosting::count());
        $this->assertSame('pending', ShiftLiabilityAllocation::sole()->manager_approval_status);
    }

    public function test_cashier_can_allocate_and_confirm_shortage_via_http(): void
    {
        $idempotencyKey = (string) Str::uuid();

        // 1. Cashier A allocates 10,000 halalas shortage to himself
        $response = $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [
                    [
                        'type' => 'cashier',
                        'id' => $this->cashierA->id,
                        'amount' => 10000,
                    ],
                ],
                'expected_version' => 0, 'expected_revision' => 1,
                'cashier_confirmed' => false,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.variance_halalas', -10000);

        $this->assertSame(1, ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)->count());

        // 2. Cashier A confirms allocation
        $confirmKey = (string) Str::uuid();
        $confirmResponse = $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('Idempotency-Key', $confirmKey)
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/confirm", [
                'version' => 1, 'expected_revision' => 1,
            ]);

        $confirmResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $this->cashierShift->id)->firstOrFail();
        $this->assertNotNull($allocation->cashier_confirmed_at);
    }

    public function test_employee_can_respond_with_acceptance_or_objection(): void
    {
        // Manager creates allocation dividing between Cashier A and Cashier B
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [
                    [
                        'type' => 'cashier',
                        'id' => $this->cashierA->id,
                        'amount' => 6000,
                    ],
                    [
                        'type' => 'cashier',
                        'id' => $this->cashierB->id,
                        'amount' => 4000,
                    ],
                ],
                'expected_version' => 0, 'expected_revision' => 1,
                'reason' => 'Split liability between shift cashier and reliever',
            ])
            ->assertStatus(201);

        // Objection without reason fails validation (422)
        $this->actingAs($this->cashierB, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/respond", [
                'version' => 1, 'expected_revision' => 1,
                'response' => 'objected',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');

        // Objection with reason succeeds (200)
        $this->actingAs($this->cashierB, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/respond", [
                'version' => 1, 'expected_revision' => 1,
                'response' => 'objected',
                'reason' => 'I was on break during the count discrepancy',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // Cashier A accepts (200)
        $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/respond", [
                'version' => 1, 'expected_revision' => 1,
                'response' => 'accepted',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_branch_manager_can_approve_allocation_and_atomic_shortage_is_reconciled(): void
    {
        // Cashier allocates and confirms
        $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [
                    [
                        'type' => 'cashier',
                        'id' => $this->cashierA->id,
                        'amount' => 10000,
                    ],
                ],
                'expected_version' => 0, 'expected_revision' => 1,
                'cashier_confirmed' => true,
            ])
            ->assertStatus(201);

        // Manager approves allocation
        $approveKey = (string) Str::uuid();
        $response = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $approveKey)
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 1, 'expected_revision' => 1,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.manager_approval_status', 'approved');

        // Verify ledger posting and custody transaction
        $this->assertSame(1, ShiftLiabilityPosting::where('cashier_shift_id', $this->cashierShift->id)->count());
        $posting = ShiftLiabilityPosting::where('cashier_shift_id', $this->cashierShift->id)->firstOrFail();
        $this->assertSame(10000, $posting->delta_halalas);
        $this->assertSame(10000, $posting->target_halalas);

        $this->assertSame(1, CashierCustodyTransaction::where('cashier_id', $this->cashierA->id)->count());
    }

    public function test_stale_allocation_version_or_unconfirmed_cashier_is_rejected(): void
    {
        // Cashier allocates but does NOT confirm
        $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [
                    [
                        'type' => 'cashier',
                        'id' => $this->cashierA->id,
                        'amount' => 10000,
                    ],
                ],
                'expected_version' => 0, 'expected_revision' => 1,
                'cashier_confirmed' => false,
            ])
            ->assertStatus(201);

        // Approving without cashier confirmation fails (409 CASHIER_ALLOCATION_CONFIRMATION_REQUIRED)
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 1, 'expected_revision' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CASHIER_ALLOCATION_CONFIRMATION_REQUIRED');

        // Approving non-existent version fails (409 STALE_OR_MISSING_LIABILITY_ALLOCATION)
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 99, 'expected_revision' => 1,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'STALE_OR_MISSING_LIABILITY_ALLOCATION');
    }

    public function test_explicit_manager_self_share_required_if_manager_allocated_share(): void
    {
        // Manager allocates share to manager
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [
                    [
                        'type' => 'branch_manager',
                        'id' => $this->manager->id,
                        'amount' => 10000,
                    ],
                ],
                'expected_version' => 0, 'expected_revision' => 1,
                'reason' => 'Manager took responsibility for the safe difference',
            ])
            ->assertStatus(201);

        // Without explicit_self_share, approval fails with 409 EXPLICIT_MANAGER_SELF_APPROVAL_REQUIRED
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 1, 'expected_revision' => 1,
                'explicit_self_share' => false,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'EXPLICIT_MANAGER_SELF_APPROVAL_REQUIRED');

        // With explicit_self_share, approval succeeds
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 1, 'expected_revision' => 1,
                'explicit_self_share' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_unauthorized_actor_cannot_approve(): void
    {
        // Cashier allocates & confirms
        $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [
                    [
                        'type' => 'cashier',
                        'id' => $this->cashierA->id,
                        'amount' => 10000,
                    ],
                ],
                'expected_version' => 0, 'expected_revision' => 1,
                'cashier_confirmed' => true,
            ])
            ->assertStatus(201);

        // Cashier cannot call approve (403 ONLY_BRANCH_MANAGER)
        $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 1, 'expected_revision' => 1,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ONLY_BRANCH_MANAGER');
    }

    public function test_same_key_replay_returns_cached_response_and_changed_payload_is_rejected(): void
    {
        $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [
                    [
                        'type' => 'cashier',
                        'id' => $this->cashierA->id,
                        'amount' => 10000,
                    ],
                ],
                'expected_version' => 0, 'expected_revision' => 1,
                'cashier_confirmed' => true,
            ])
            ->assertStatus(201);

        $key = (string) Str::uuid();

        // 1. Initial approve
        $first = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 1, 'expected_revision' => 1,
            ]);
        $first->assertStatus(200);

        // 2. Exact replay with same key
        $replay = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 1, 'expected_revision' => 1,
            ]);
        $replay->assertStatus(200);
        $this->assertSame($first->json('data.version'), $replay->json('data.version'));

        // 3. Changed payload with same key returns 409 IDEMPOTENCY_PAYLOAD_MISMATCH
        $mismatch = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 2,
            ]);
        $mismatch->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    public function test_full_workflow_from_shortage_to_approval_to_daily_submit(): void
    {
        // Resolve manager workday for today
        $workday = BranchManagerShift::where('branch_manager_id', $this->manager->id)
            ->whereDate('shift_date', today())
            ->first();
        if (! $workday) {
            $workday = BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today()->toDateString(),
                'status' => 'completed',
                'daily_report_submitted' => false,
            ]);
        } else {
            $workday->update(['status' => 'completed', 'daily_report_submitted' => false]);
        }
        app(\Modules\Shift\Services\ShiftReportRevisionService::class)
            ->recordManagerRevision($workday, 'branch_manager', $this->manager->id, 0);

        // 1. Cashier allocates
        $this->actingAs($this->cashierA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/allocate", [
                'shares' => [
                    [
                        'type' => 'cashier',
                        'id' => $this->cashierA->id,
                        'amount' => 10000,
                    ],
                ],
                'expected_version' => 0, 'expected_revision' => 1,
                'cashier_confirmed' => true,
            ])
            ->assertStatus(201);

        // 2. Manager approves
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/shifts/{$this->cashierShift->id}/liability/approve", [
                'version' => 1, 'expected_revision' => 1,
            ])
            ->assertStatus(200);

        // 3. Manager submits daily close report
        $submitResponse = $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
                'branch_manager_shift_id' => $workday->id,
                'expected_revision' => 1,
            ]);

        $submitResponse->assertStatus(200);
        $this->assertTrue((bool) $workday->fresh()->daily_report_submitted);
    }
}
