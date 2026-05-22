<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Purchase\Models\SavedPriceComparison;
use Tests\TestCase;

/**
 * Covers the Branch Manager Price Comparison API
 * (branch-manager-price-comparison-repository-impl-doc.md).
 */
class BranchManagerPriceComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
    }

    /**
     * Create a saved price comparison for a branch with a realistic snapshot.
     */
    private function makeComparison(string $branchId, string $createdBy, bool $withRecommendation = true): SavedPriceComparison
    {
        $snapshot = [
            'item_id' => (string) Str::uuid(),
            'item_name' => 'Fresh Beef (40 Kg)',
            'item_code' => 'BEEF-40',
            'item_unit' => 'Kg',
            'item_logo' => null,
            'item_price' => 1200.0,
            'quantity' => 60,
            'sources' => [
                'direct_supplier' => [
                    ['supplier_id' => 'sup_001', 'supplier_name' => 'Al Safa Meat', 'unit_price' => 1200.0, 'delivery_days' => 6, 'rating' => 4.5],
                ],
                'via_purchasing_officer' => ['unit_price' => 1150.0, 'delivery_days' => 8, 'rating' => 4.2],
                'internal_transfer' => [
                    ['branch_id' => 'branch_002', 'branch_name' => 'Branch 2', 'unit_price' => 1100.0, 'delivery_days' => 4, 'rating' => 4.0],
                ],
            ],
            'insights' => [
                'lowest_price' => ['source_type' => 'internal_transfer', 'source_id' => 'branch_002', 'source_name' => 'Branch 2', 'value' => 1100.0],
                'fastest_delivery' => ['source_type' => 'internal_transfer', 'source_id' => 'branch_002', 'source_name' => 'Branch 2', 'value' => 4],
                'best_compliance' => ['source_type' => 'direct_supplier', 'source_id' => 'sup_001', 'source_name' => 'Al Safa Meat', 'value' => 4.5],
            ],
            'price_trends' => [
                ['date' => '2025-04-01', 'value' => 1050.0],
                ['date' => '2025-05-01', 'value' => 1150.0],
            ],
        ];

        if ($withRecommendation) {
            $snapshot['best_option'] = [
                'type' => 'internal_transfer',
                'source_type' => 'internal_transfer',
                'source_id' => 'branch_002',
                'source_name' => 'Branch 2',
            ];
        }

        return SavedPriceComparison::create([
            'branch_id' => $branchId,
            'created_by' => $createdBy,
            'item_id' => $snapshot['item_id'],
            'item_name' => $snapshot['item_name'],
            'quantity' => $snapshot['quantity'],
            'snapshot' => $snapshot,
            'note' => 'sample',
        ]);
    }

    public function test_lists_saved_comparisons_for_own_branch_only(): void
    {
        $mine = $this->makeComparison($this->branch->id, $this->manager->id);

        $otherBranch = Branch::factory()->create();
        $otherManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id]);
        $this->makeComparison($otherBranch->id, $otherManager->id);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/price-comparisons');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'itemId', 'itemName', 'itemCode', 'savedDate', 'quantity']]]);

        // Tenant isolation: only the manager's own branch comparison is listed.
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.itemCode', 'BEEF-40');
    }

    public function test_shows_comparison_details_with_mapped_structure(): void
    {
        $comparison = $this->makeComparison($this->branch->id, $this->manager->id);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/price-comparisons/'.$comparison->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'comparisonId', 'itemId', 'itemName', 'itemCode', 'itemUnit', 'itemLogo', 'itemPrice', 'quantity',
                    'sources' => [
                        'directSupplier' => ['price', 'deliveryDays', 'rating', 'sourceId'],
                        'viaPurchasingOfficer' => ['price', 'deliveryDays', 'rating', 'sourceId'],
                        'internalTransfer' => ['price', 'deliveryDays', 'rating', 'sourceId'],
                    ],
                    'factors' => [
                        'bestCompliance' => ['sourceType', 'sourceId', 'sourceName', 'score'],
                        'fastestDelivery' => ['sourceType', 'sourceId', 'sourceName', 'score'],
                        'lowestPrice' => ['sourceType', 'sourceId', 'sourceName', 'score'],
                    ],
                    'priceTrends' => [['date', 'value']],
                ],
            ]);

        $response->assertJsonPath('data.comparisonId', $comparison->id)
            ->assertJsonPath('data.sources.directSupplier.sourceId', 'sup_001')
            ->assertJsonPath('data.sources.internalTransfer.sourceId', 'branch_002')
            ->assertJsonPath('data.factors.lowestPrice.sourceType', 'internal_transfer')
            ->assertJsonPath('data.factors.bestCompliance.score', 4.5);

        // Numeric scores are exposed as numbers (JSON drops a trailing .0).
        $this->assertEquals(1100, $response->json('data.factors.lowestPrice.score'));
        $this->assertSame('60', (string) $response->json('data.quantity'));
    }

    public function test_show_returns_404_for_a_comparison_from_another_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        $otherManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id]);
        $foreign = $this->makeComparison($otherBranch->id, $otherManager->id);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/price-comparisons/'.$foreign->id)
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_exports_comparison_and_returns_a_file_url(): void
    {
        Storage::fake('public');
        $comparison = $this->makeComparison($this->branch->id, $this->manager->id);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/price-comparisons/'.$comparison->id.'/export?formatType=Excel');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['url']]);

        $this->assertNotEmpty($response->json('data.url'));
        $this->assertCount(1, Storage::disk('public')->files('branch-manager/price-comparisons'));
    }

    public function test_create_order_without_a_recommendation_returns_422(): void
    {
        $comparison = $this->makeComparison($this->branch->id, $this->manager->id, withRecommendation: false);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/branch-manager/price-comparisons/'.$comparison->id.'/orders', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_brand_owner_cannot_access_branch_manager_price_comparisons(): void
    {
        $owner = BrandOwner::create([
            'name' => 'Owner One',
            'email' => 'owner@example.com',
            'phone' => '0500000000',
            'password' => 'secret-password',
            'is_active' => true,
            'is_first_login' => false,
            'status' => 'active',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/branch-manager/price-comparisons')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
