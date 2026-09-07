<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Notifications\PasswordResetLinkNotification;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Models\BrandOwnerOtp;
use Modules\Notification\Mail\NotificationMail;
use Modules\Notification\Services\OtpDeliveryService;
use Tests\TestCase;

/**
 * Reported 2026-09-07: "accounts added from the dashboard never receive the
 * OTP". Two independent holes, both of which reported success:
 *   1. /auth/forgot-password wrote the password_reset_tokens row and sent
 *      nothing at all.
 *   2. The brand-owner / brand-manager portals delivered their OTP with
 *      Log::info() — the code went to storage/logs, never to the user.
 */
class OtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    // ---- Dashboard self-service reset (§4 /auth/forgot-password) ----

    public function test_forgot_password_emails_the_reset_token(): void
    {
        Notification::fake();
        $user = $this->dashboardUser('reset@asab.test');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'reset@asab.test'])
            ->assertNoContent();

        Notification::assertSentTo($user, PasswordResetLinkNotification::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'reset@asab.test']);
    }

    public function test_forgot_password_on_an_unknown_email_sends_nothing_and_still_returns_204(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@asab.test'])
            ->assertNoContent();

        Notification::assertNothingSent();
    }

    /** The token is a credential: the `log` mailer must never carry it. */
    public function test_forgot_password_suppresses_delivery_on_the_log_mailer(): void
    {
        Notification::fake();
        config()->set('mail.default', 'log');
        $this->dashboardUser('logged@asab.test');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'logged@asab.test'])
            ->assertNoContent();

        Notification::assertNothingSent();
    }

    // ---- Mobile portal OTP ----

    public function test_brand_owner_forgot_password_actually_mails_the_code(): void
    {
        Mail::fake();
        BrandOwner::create([
            'name' => 'مالك', 'email' => 'owner@asab.test', 'phone' => '0500000001',
            'password' => 'secret-password', 'status' => 'active',
        ]);

        $this->postJson('/api/v1/brand-owner/auth/forgot-password', [
            'identifier' => 'owner@asab.test', 'type' => 'email',
        ])->assertOk();

        Mail::assertSent(NotificationMail::class, fn ($mail) => $mail->hasTo('owner@asab.test'));
        $this->assertDatabaseHas('brand_owner_otps', ['identifier' => 'owner@asab.test']);
        // The stored code stays hashed; only the mailed copy is readable.
        $this->assertNotSame('', BrandOwnerOtp::where('identifier', 'owner@asab.test')->value('otp'));
    }

    public function test_otp_delivery_refuses_the_log_mailer(): void
    {
        Mail::fake();
        config()->set('mail.default', 'log');

        $sent = app(OtpDeliveryService::class)->sendEmail('someone@asab.test', '123456');

        $this->assertFalse($sent);
        Mail::assertNothingSent();
    }

    private function dashboardUser(string $email): AsabUser
    {
        $company = AsabCompany::create(['name' => 'OTP Co', 'plan' => 'Professional', 'status' => 'active']);

        return AsabUser::create([
            'company_id' => $company->id, 'name' => 'مستخدم',
            'email' => $email, 'password' => 'secret-password', 'status' => 'active',
        ]);
    }
}
