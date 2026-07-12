<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Tests\TestCase;

/**
 * Catalog + supplier enrichment for the procurement dashboard: item supplier
 * count + brand, price-history supplier attribution, and supplier lifetime
 * figures + KPI block. T11.11–T11.13.
 */
class ProcurementCatalogEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'Enrich Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير المشتريات',
            'email' => 'procurement@enrich.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'procurement', 'scope' => 'all']);
    }

    private function as()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    private function supplier(string $name): AsabSupplier
    {
        return AsabSupplier::create(['company_id' => $this->company->id, 'name' => $name, 'status' => 'active']);
    }

    // ---- T11.11 supplierCount + brand ----

    public function test_item_supplier_count_reflects_distinct_priced_suppliers(): void
    {
        $a = $this->supplier('مورد أ');
        $b = $this->supplier('مورد ب');

        $created = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'أرز', 'unit' => 'كيس', 'lastPriceHalalas' => 10000, 'supplierId' => $a->id, 'code' => 'RICE-9',
        ]);
        $created->assertCreated();
        $itemId = $created->json('id');

        // Re-price under a different supplier → a second attributed history row.
        $this->as()->patchJson('/api/v1/company/me/procurement/items/'.$itemId, [
            'supplierId' => $b->id, 'lastPriceHalalas' => 9500,
        ])->assertOk();

        $row = collect($this->as()->getJson('/api/v1/company/me/procurement/items')->json('data'))
            ->firstWhere('id', $itemId);
        $this->assertSame(2, $row['supplierCount']);
    }

    public function test_item_persists_and_returns_brand(): void
    {
        $brandId = (string) Str::uuid();

        $created = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'سكر', 'unit' => 'كيس', 'lastPriceHalalas' => 1200, 'brandId' => $brandId, 'code' => 'SUG-9',
        ]);
        $created->assertCreated()->assertJsonPath('brandId', $brandId);

        $row = collect($this->as()->getJson('/api/v1/company/me/procurement/items')->json('data'))
            ->firstWhere('id', $created->json('id'));
        $this->assertSame($brandId, $row['brandId']);
    }

    // ---- T11.12 price-history attribution ----

    public function test_price_history_rows_carry_supplier(): void
    {
        $a = $this->supplier('مورد التسعير');
        $created = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'زيت', 'unit' => 'لتر', 'lastPriceHalalas' => 4000, 'supplierId' => $a->id, 'code' => 'OIL-9',
        ]);
        $created->assertCreated();

        $history = $this->as()->getJson('/api/v1/company/me/procurement/items/'.$created->json('id').'/price-history');
        $history->assertOk();
        $row = collect($history->json('data'))->first();
        $this->assertSame($a->id, $row['supplierId']);
        $this->assertSame('مورد التسعير', $row['supplierName']);
    }

    // ---- T11.13 supplier lifetime + KPIs ----

    public function test_supplier_lifetime_orders_and_kpis(): void
    {
        $s = $this->supplier('مورد العمر');
        foreach (range(1, 3) as $i) {
            Operation::create([
                'public_id' => 'PUR-'.strtoupper(Str::random(6)),
                'company_id' => $this->company->id,
                'module_key' => 'purchases',
                'payload' => ['supplierId' => $s->id],
                'amount' => 5000,
                'match' => 'exact',
                'origin' => 'procurement',
                'status' => Operation::STATUS_APPROVED,
                'operation_date' => now(),
            ]);
        }

        $list = $this->as()->getJson('/api/v1/company/me/procurement/suppliers');
        $list->assertOk();

        $row = collect($list->json('data'))->firstWhere('id', $s->id);
        $this->assertSame(3, $row['lifetimeOrdersCount']);
        $this->assertEquals(150.0, $row['lifetimeSpend']); // 3 × 5000 halalas → SAR

        $kpis = $list->json('meta.kpis');
        $this->assertArrayHasKey('activeSuppliers', $kpis);
        $this->assertArrayHasKey('totalPurchases', $kpis);
        $this->assertArrayHasKey('avgRating', $kpis);
        $this->assertGreaterThanOrEqual(1, $kpis['activeSuppliers']);
    }
}
