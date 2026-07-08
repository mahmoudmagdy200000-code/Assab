<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Tests\TestCase;

/**
 * Regression: GET procurement/items must read the same store the item CRUD
 * and Excel export write to (asab_supplier_items) — it used to read a legacy
 * table and always came back empty on real data.
 */
class ProcurementItemsListTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $company = AsabCompany::create(['name' => 'Items Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->manager = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'مدير المشتريات',
            'email' => 'procurement@items.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'procurement', 'scope' => 'all']);
    }

    public function test_created_items_appear_in_the_list_with_rich_fields(): void
    {
        $as = $this->actingAs($this->manager, 'sanctum');

        $created = $as->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'دجاج طازج',
            'unit' => 'كجم',
            'category' => 'لحوم ودواجن',
            'lastPriceHalalas' => 2400,
            'code' => 'CHK-01',
        ]);
        $created->assertCreated();

        $list = $as->getJson('/api/v1/company/me/procurement/items?search=دجاج');
        $list->assertOk();

        $row = collect($list->json('data'))->firstWhere('id', $created->json('id'));
        $this->assertNotNull($row, 'created item must appear in the list');
        $this->assertSame('دجاج طازج', $row['name']);
        $this->assertSame('لحوم ودواجن', $row['category']);
        $this->assertSame(2400, $row['lastPriceHalalas']);
        $this->assertSame('CHK-01', $row['code']);
        $this->assertArrayHasKey('meta', $list->json());
    }

    public function test_created_suppliers_appear_in_the_suppliers_list(): void
    {
        $as = $this->actingAs($this->manager, 'sanctum');

        $created = $as->postJson('/api/v1/company/me/procurement/suppliers', [
            'name' => 'شركة الدواجن الوطنية',
            'category' => 'لحوم ودواجن',
            'contactPhone' => '0553421100',
        ]);
        $created->assertCreated();

        $list = $as->getJson('/api/v1/company/me/procurement/suppliers');
        $list->assertOk();

        $row = collect($list->json('data'))->firstWhere('id', $created->json('id'));
        $this->assertNotNull($row, 'created supplier must appear in the list');
        $this->assertSame('شركة الدواجن الوطنية', $row['name']);
        $this->assertSame('0553421100', $row['contactPhone']);
        $this->assertTrue($row['isActive']);
    }

    public function test_items_of_another_company_are_not_listed(): void
    {
        $other = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        \Modules\Admin\Models\SupplierItem::create([
            'company_id' => $other->id, 'name' => 'صنف شركة تانية', 'unit' => 'kg', 'price' => 100, 'status' => 'active',
        ]);

        $list = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/company/me/procurement/items');

        $list->assertOk();
        $this->assertNull(collect($list->json('data'))->firstWhere('name', 'صنف شركة تانية'));
    }
}
