<?php

namespace Tests\Feature;

use App\Support\PublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\ExpenseInvoiceService;
use Tests\TestCase;

/**
 * 2026-08-15 — invoice attachments 404'd on the live server. The URL served to
 * the dashboard was
 *
 *   https://host//storage/expenses/attachments/expense_….jpg
 *              ↑↑ doubled — `APP_URL` carried a trailing slash and the public
 *                 disk's url is `APP_URL.'/storage'`. Some web servers 404 it.
 *
 * and on this host the browsable prefix is not `/storage` at all (the document
 * root is the project root, so the file is at `/public/storage/…`).
 *
 * Both are configuration, but the code made them unfixable without a data
 * backfill: the absolute URL was baked into `asab_attachments.public_url` and
 * into the operation payload at upload time. Everything is derived from the
 * storage key on READ now, so fixing the config heals the whole history.
 */
class AttachmentPublicUrlTest extends TestCase
{
    use RefreshDatabase;

    /** Point the public disk at a prefix with a trailing-slash defect. */
    private function withDiskUrl(string $url): void
    {
        config(['filesystems.disks.public.url' => $url]);
        Storage::forgetDisk('public');
    }

    public function test_the_scheme_survives_but_a_doubled_path_slash_does_not(): void
    {
        $this->assertSame(
            'https://host/storage/a/b.jpg',
            PublicUrl::normalize('https://host//storage/a/b.jpg'),
        );
        $this->assertSame('https://host/a/b', PublicUrl::normalize('https://host//a///b'));
        $this->assertNull(PublicUrl::for(''));
        $this->assertNull(PublicUrl::for(null));
    }

    public function test_a_trailing_slash_in_the_configured_prefix_cannot_produce_a_double_slash(): void
    {
        $this->withDiskUrl('https://host//storage');

        $url = PublicUrl::for('expenses/attachments/receipt.jpg');

        $this->assertSame('https://host/storage/expenses/attachments/receipt.jpg', $url);
        $this->assertStringNotContainsString('//storage', $url);
    }

    public function test_the_configured_prefix_can_carry_a_document_root_segment(): void
    {
        // The host serves the project root, so the file lives at /public/storage.
        $this->withDiskUrl('https://host/public/storage');

        $this->assertSame(
            'https://host/public/storage/expenses/attachments/receipt.jpg',
            PublicUrl::for('expenses/attachments/receipt.jpg'),
        );
    }

    public function test_an_attachment_row_derives_its_url_instead_of_replaying_the_stored_one(): void
    {
        $this->withDiskUrl('https://host/public/storage');

        $attachment = Attachment::create([
            'owner_type' => 'operation',
            'owner_id' => (string) \Illuminate\Support\Str::uuid(),
            'filename' => 'receipt.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1234,
            'storage_key' => 'expenses/attachments/receipt.jpg',
            // A row written before the fix: doubled slash, wrong prefix.
            'public_url' => 'https://host//storage/expenses/attachments/receipt.jpg',
            'uploaded_at' => now(),
        ]);

        $this->assertSame(
            'https://host/public/storage/expenses/attachments/receipt.jpg',
            $attachment->fresh()->public_url,
            'the stored column must never be replayed verbatim — the config is the truth',
        );
    }

    public function test_a_row_with_no_storage_key_keeps_its_external_url_normalised(): void
    {
        $attachment = Attachment::create([
            'owner_type' => 'operation',
            'owner_id' => (string) \Illuminate\Support\Str::uuid(),
            'filename' => 'cdn.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 10,
            'storage_key' => null,
            'public_url' => 'https://cdn.example.com//files/cdn.jpg',
            'uploaded_at' => now(),
        ]);

        $this->assertSame('https://cdn.example.com/files/cdn.jpg', $attachment->fresh()->public_url);
    }

    public function test_the_expenses_statement_re_derives_the_urls_baked_into_its_payload(): void
    {
        $this->withDiskUrl('https://host/public/storage');

        $company = \Modules\Admin\Models\AsabCompany::create([
            'name' => 'Url Co', 'plan' => 'Basic', 'status' => 'active',
        ]);
        $branch = \Modules\Branch\Models\Branch::factory()->create(['asab_company_id' => $company->id]);

        $op = Operation::create([
            'public_id' => 'EXP-URL-1',
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'expenses',
            'source_module' => 'expense',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'amount' => 40000,
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'payload' => [
                'invoices' => [[
                    'invNum' => 'QC-77',
                    'vendor' => 'مؤسسة الخضار',
                    'amountHalalas' => 40000,
                    'attachments' => [[
                        'id' => 'a1',
                        'filename' => 'receipt.jpg',
                        'storageKey' => 'expenses/attachments/receipt.jpg',
                        // Baked at bridge time, before the config was corrected.
                        'publicUrl' => 'https://host//storage/expenses/attachments/receipt.jpg',
                    ]],
                ]],
            ],
        ]);

        $presented = app(ExpenseInvoiceService::class)->present($op);
        $url = $presented['invoices'][0]['attachments'][0]['publicUrl'];

        $this->assertSame('https://host/public/storage/expenses/attachments/receipt.jpg', $url);
        $this->assertSame('matched', $presented['invoices'][0]['matchStatus'], 'the document is still attached');
    }
}
