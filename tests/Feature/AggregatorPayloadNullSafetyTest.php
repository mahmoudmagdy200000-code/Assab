<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aggregator\Models\Aggregator;
use Modules\Aggregator\Models\BranchAggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Tests\TestCase;

/**
 * The mobile client casts the aggregator string fields to String, so a null
 * logo/description crashed the end-shift «add aggregators» sheet with
 * "type 'Null' is not a subtype of type 'String' in type cast". Aggregators are
 * seeded without either field, so the payload must never carry null there.
 */
class AggregatorPayloadNullSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Aggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        // Exactly how they are seeded: no logo, no description.
        $this->aggregator = Aggregator::create([
            'name' => 'جاهز', 'code' => 'JAHEZ', 'commission_rate' => 15,
            'payment_terms' => 'Monthly', 'integration_type' => 'manual', 'is_active' => true,
        ]);
    }

    public function test_available_list_emits_strings_not_nulls(): void
    {
        $res = $this->getJson('/api/aggregators/available');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $this->aggregator->id);
        $this->assertNotNull($row);
        foreach (['name', 'code', 'logo', 'description', 'integration_type', 'created_at'] as $field) {
            $this->assertIsString($row[$field], "{$field} must be a string, got null");
        }
        $this->assertSame('', $row['logo']);
    }

    public function test_branch_aggregator_list_emits_strings_not_nulls(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        BranchAggregator::create([
            'branch_id' => $branch->id,
            'aggregator_id' => $this->aggregator->id,
            'is_enabled' => true,
        ]);

        $res = $this->actingAs($manager, 'sanctum')->getJson('/api/branch-aggregators');
        $res->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $this->aggregator->id);
        $this->assertNotNull($row);
        foreach (['name', 'code', 'logo'] as $field) {
            $this->assertIsString($row[$field], "{$field} must be a string, got null");
        }
        $this->assertSame('', $row['logo']);
    }

    public function test_detail_payload_emits_strings_not_nulls(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);

        $res = $this->actingAs($manager, 'sanctum')->getJson("/api/aggregators/{$this->aggregator->id}");
        $res->assertOk();

        $data = $res->json('data');
        $this->assertIsString($data['logo']);
        $this->assertIsString($data['description']);
        $this->assertIsString($data['contact']['email']);
        $this->assertIsString($data['contact']['phone']);
        $this->assertIsString($data['integration']['api_endpoint']);
        $this->assertIsString($data['timestamps']['created_at']);
    }
}
