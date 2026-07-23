<?php

namespace Tests\Unit;

use Modules\Admin\Services\Notifications\WhatsAppSupplierNotifier;
use PHPUnit\Framework\TestCase;

/**
 * FR-PUR-1 — the WhatsApp click-to-chat link builder for «إرسال للمورد».
 * Pure logic (no container/DB): phone normalisation + message/URL shape.
 */
class WhatsAppSupplierNotifierTest extends TestCase
{
    private WhatsAppSupplierNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifier = new WhatsAppSupplierNotifier;
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function phoneCases(): array
    {
        return [
            'local trunk zero' => ['0553421100', '966553421100'],
            'plus and spaces' => ['+966 55 342 1100', '966553421100'],
            'double-zero intl' => ['00966553421100', '966553421100'],
            'already e164' => ['966553421100', '966553421100'],
            'bare subscriber' => ['553421100', '966553421100'],
            'null' => [null, null],
            'blank' => ['', null],
            'all zeros' => ['0000', null],
        ];
    }

    /**
     * @dataProvider phoneCases
     */
    public function test_phone_is_normalised_to_e164_digits(?string $raw, ?string $expectedDigits): void
    {
        $dispatch = $this->notifier->forOrder('GRP-1', 'مورد', $raw, []);

        $this->assertSame($expectedDigits, $dispatch->phone);

        if ($expectedDigits === null) {
            $this->assertNull($dispatch->url);
            $this->assertFalse($dispatch->deliverable());
        } else {
            $this->assertStringStartsWith('https://wa.me/'.$expectedDigits.'?text=', $dispatch->url);
            $this->assertTrue($dispatch->deliverable());
        }
    }

    public function test_message_carries_reference_supplier_items_and_eta(): void
    {
        $dispatch = $this->notifier->forOrder(
            'GRP-20260723-AB12',
            'شركة الدواجن',
            '0553421100',
            [
                ['name' => 'صدر دجاج', 'qty' => '320', 'unit' => 'كجم'],
                ['name' => 'أرز', 'qty' => '2.5', 'unit' => 'كيس'],
            ],
            '2026-07-30',
        );

        $msg = $dispatch->message;
        $this->assertStringContainsString('طلب شراء رقم: GRP-20260723-AB12', $msg);
        $this->assertStringContainsString('المورد: شركة الدواجن', $msg);
        $this->assertStringContainsString('1. صدر دجاج × 320 كجم', $msg);
        $this->assertStringContainsString('2. أرز × 2.5 كيس', $msg);
        $this->assertStringContainsString('موعد التسليم المتوقع: 2026-07-30', $msg);
        $this->assertSame('GRP-20260723-AB12', $dispatch->reference);

        // The URL text is exactly the message, url-encoded.
        $text = rawurldecode(substr($dispatch->url, strpos($dispatch->url, '?text=') + 6));
        $this->assertSame($msg, $text);
    }

    public function test_empty_item_list_reads_as_no_items(): void
    {
        $dispatch = $this->notifier->forOrder('GRP-2', 'مورد', '0553421100', []);

        $this->assertStringContainsString('(لا توجد أصناف)', $dispatch->message);
    }

    public function test_a_missing_phone_still_composes_a_usable_message(): void
    {
        $dispatch = $this->notifier->forOrder('GRP-3', 'مورد', null, [
            ['name' => 'ملح', 'qty' => '10', 'unit' => 'كجم'],
        ]);

        $this->assertNull($dispatch->url);
        $this->assertStringContainsString('1. ملح × 10 كجم', $dispatch->message);
    }
}
