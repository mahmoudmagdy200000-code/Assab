<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Transformers\SupplierResource;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * A dashboard-provisioned supplier (ProcurementCatalogBridgeService::
 * provisionSupplier) sets only name/email/phone — address/status/etc. stay null.
 * When it surfaced in the mobile «Choose Sources» list, SupplierResource emitted
 * those nulls raw and the Flutter app threw
 * "type 'Null' is not a subtype of type 'String' in type cast". The resource now
 * coalesces the nullable string fields; this locks that.
 */
class SupplierResourceNullSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_minimally_provisioned_supplier_renders_without_null_strings(): void
    {
        // Mirror the bridge insert: name + a couple basics, everything else left
        // to the schema. `address` is nullable with NO default → null; `status`
        // carries a DB default of 'offline'. Both must reach the app as strings.
        $supplier = Supplier::forceCreate([
            'name' => 'مورد الجسر',
            'is_active' => true,
        ])->fresh();

        $arr = (new SupplierResource($supplier))->toArray(request());

        $this->assertIsString($arr['email']);
        $this->assertIsString($arr['phone']);
        $this->assertIsString($arr['address']);
        $this->assertIsString($arr['status']);
    }

    public function test_a_fully_populated_supplier_keeps_its_values(): void
    {
        $supplier = Supplier::forceCreate([
            'name' => 'مورد كامل', 'email' => 's@x.test', 'phone' => '0553421100',
            'address' => 'الرياض', 'status' => 'online', 'is_active' => true,
        ]);

        $arr = (new SupplierResource($supplier))->toArray(request());

        $this->assertSame('s@x.test', $arr['email']);
        $this->assertSame('الرياض', $arr['address']);
        $this->assertSame('online', $arr['status']);
    }
}
