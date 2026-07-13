# T14 — Company Portal (Company-Admin surfaces)
> SRS: §12 CMP-1..8 (dashboard plan hero+quotas+brand cards, subscription billing-cycle toggle + plan comparison + upgrade/downgrade/cancel/reactivate/contact-sales, users n/50 + invite flow + toggle + resend, org tree brands/restaurants/branches + transfer-manager + add-branch pending-review, modules toggles plan-gated, billing summary/invoices/pdf/pay/payment-methods/address, settings + logo + preferences, support channels/tickets/chat), plus API keys, SSO, webhooks, onboarding, invitation accept · Audited: 2026-07-10 · FE doc deliverable: docs/fe-wiring/FE-T14-company-portal.md
> Status: ✅ done — 8 behavior gaps fixed 2026-07-13 (T14.1 add-branch pending-review + admin queue, T14.2 invitation email+resend, T14.3 cross-tenant accept guard, T14.4 real storage quota, T14.5 SSO live-plan gate, T14.6 transfer-manager validation+scope sync, T14.7 support seed). Tests: `tests/Feature/CompanyPortalGapsTest.php` (9 passed, 39 assertions). FE doc: docs/fe-wiring/FE-T14-company-portal.md

## 1. Endpoint inventory (audited against code)

All handlers live in `Modules/Admin/app/Http/Controllers/Company/`; services in `Modules/Admin/app/Services/`. Route refs = `Modules/Admin/routes/api.php`. Company-admin block runs under `asab.tenant + asab.role:company-admin + asab.idempotency + asab.audit` (L483); support block allows all 5 company roles (L574).

| # | Method | Path | Handler | Status | Notes |
|---|--------|------|---------|--------|-------|
| 1 | POST | `/company/invitations/accept` | OnboardController@acceptInvitation | 🟡 | L85, public token-auth. Full flow: validates token+expiry, creates AsabUser (password `hashed` cast) + AsabUserRole scope + CompanyUser, marks invitation accepted, issues access/refresh tokens. 🟡: `AsabUser::updateOrCreate(['email'=>…])` overwrites `company_id`+password of an email already registered in ANOTHER company → cross-tenant account takeover edge (OnboardController.php:102) |
| 2 | POST | `/webhooks/{provider}` | WebhookController@handle | ✅ | L88–89, provider whitelist `stripe\|tap\|hyperpay\|moyasar`. HMAC verify when secret configured (401 on fail), idempotent via `webhook_events.event_id`, pays/fails invoices + realtime. Unverified allowed only when no secret configured (dev) |
| 3 | GET | `/plans` | SubscriptionController@plans | ✅ | L480–481 (roles: company-admin,head). Active plans ordered, features w/ `labelAr`, `isCurrent` flag, price monthly/annual + `annualDiscountPct` — feeds §4.1 3-plan comparison |
| 4 | POST | `/company/onboard` | OnboardController@onboard | ✅ | L486. Transaction: company update, CompanySettings, CompanyPreferences, module activation from `plan.modules_included`, initial brands, 14-day trial subscription, `nextStep: add_payment_method` |
| 5 | GET | `/company/invitations` | UserController@invitations | ✅ | L489, status multi-filter, tenant-scoped |
| 6 | POST | `/company/invitations` | UserController@invite | 🟡 | L490. Validates role scope (branch→branchId, accountant→brandId), brand tenant-ownership, duplicate member 409, quota `assertCanAdd('users')`, 7-day token. 🟡: **no email is sent to the invitee** — token only in API response; in-app notification goes to the inviter (UserController.php:94) |
| 7 | POST | `/company/invitations/{id}/revoke` | UserController@revokeInvitation | ✅ | L491, tenant-scoped, sets `status=revoked` |
| 8 | GET | `/company/me/dashboard/brand-performance` | DashboardController@brandPerformance | ✅ | L494. CompanyDashboardService::brandBreakdown — per-brand sales/target/achievementPct + branchesAboveTarget |
| 9 | GET | `/company/me/dashboard` | DashboardController@index | 🟡 | L495. Plan hero (plan, status, `daysRemaining`, `pricePerYear`), quota chips brands/restaurants/branches/users, KPIs incl. deltas + `branchesAboveTarget`/`branchCompletionRate` («n من n فرع فوق الهدف»), brand cards. 🟡: `storage.usedGb` hardcoded `0` (DashboardController.php:53) |
| 10 | GET | `/company/me/subscription` | SubscriptionController@show | 🟡 | L498. Subscription + plan + `usage` (PlanLimitService::quotas) + nextInvoice. 🟡: `usage` has **no storage key** — CMP-2 requires branches/users/storage meters (PlanLimitService.php:51–62 lacks storage) |
| 11 | POST | `/company/me/subscription/upgrade` | SubscriptionController@upgrade | ✅ | L499. SubscriptionService::upgrade — ALREADY_ON_PLAN/INVALID_TRANSITION 409s, DB::transaction, proration invoice (15% VAT), module re-gating, change-log, realtime |
| 12 | POST | `/company/me/subscription/downgrade` | SubscriptionController@downgrade | ✅ | L500. `downgradeViolations` → 422 QUOTA_WOULD_EXCEED with per-resource details; period_end default |
| 13 | POST | `/company/me/subscription/cancel` | SubscriptionController@cancel | ✅ | L501. Reason enum, `cancelAtPeriodEnd`, notifies admins «تم إلغاء الاشتراك» |
| 14 | POST | `/company/me/subscription/reactivate` | SubscriptionController@reactivate | ✅ | L502. 409 SUBSCRIPTION_EXPIRED for expired; restores active + auto_renew |
| 15 | POST | `/company/me/subscription/contact-sales` | SubscriptionController@contactSales | ✅ | L503. Creates high-priority support ticket «طلب ترقية لخطة مؤسسي (Enterprise)», 202 |
| 16 | POST | `/company/me/subscription/billing-cycle` | SubscriptionController@billingCycle | ✅ | L504. «سنوي (وفّر 17%) / شهري» toggle; cycle_change via applyChange incl. proration |
| 17 | GET | `/company/me/users` | UserController@index | ✅ | L507. Filters roleKey/status/brandId/branchId/search, paginated, meta `activeCount`+`maxUsers` → renders «نشط · n/50» |
| 18 | POST | `/company/me/users` | UserController@invite | 🟡 | L508 — alias of #6, same missing-email gap |
| 19 | PATCH | `/company/me/users/{id}` | UserController@update | ✅ | L509. Role/scope validation, LAST_ADMIN_CANNOT_DEMOTE 409, brand tenant check, transaction syncs `asab_user_roles` for accountant brand scope |
| 20 | POST | `/company/me/users/{id}/toggle-status` | UserController@toggleStatus | ✅ | L510. CANNOT_DISABLE_SELF + LAST_ACTIVE_ADMIN 409s |
| 21 | DELETE | `/company/me/users/{id}` | UserController@destroy | ✅ | L511. Last-admin guard |
| 22 | POST | `/company/me/users/{id}/resend-invite` | UserController@resendInvite | 🟡 | L512. **Stub**: checks `status==='invited'` then returns `{resent:true}` 202 — nothing is regenerated or delivered (UserController.php:208–218); operates on CompanyUser id, not invitation id |
| 23 | GET | `/company/me/brands` | OrgController@tree | ✅ | L515. brand→restaurant→branch accordion with manager, city, sales/target `pctOfTarget`; meta brandCount/restaurantCount/branchCount/`maxBranches` («من 20 مسموح») |
| 24 | POST | `/company/me/brands` | OrgController@storeBrand | ✅ | L516. BRAND_NAME_EXISTS 409, quota check |
| 25 | PATCH | `/company/me/brands/{id}` | OrgController@updateBrand | ✅ | L517 |
| 26 | DELETE | `/company/me/brands/{id}` | OrgController@destroyBrand | ✅ | L518. BRAND_HAS_DEPENDENTS 409 |
| 27 | POST | `/company/me/restaurants` | OrgController@storeRestaurant | ✅ | L519. Brand tenant check + quota |
| 28 | PATCH | `/company/me/restaurants/{id}` | OrgController@updateRestaurant | ✅ | L520 |
| 29 | DELETE | `/company/me/restaurants/{id}` | OrgController@destroyRestaurant | ✅ | L521. RESTAURANT_HAS_BRANCHES 409 |
| 30 | POST | `/company/me/branches` | OrgController@storeBranch | 🟡 | L522. Creates legacy `Modules\Branch` row with asab_* columns + quota check. 🟡: **created `status='active'` immediately** — SRS CMP-4 requires pending platform-admin review («سيظهر بعد مراجعة الإدارة»). No review state/queue exists anywhere (grep `pending_review` = 0 hits) (OrgController.php:175–185) |
| 31 | PATCH | `/company/me/branches/{id}` | OrgController@updateBranch | ✅ | L523. name/manager/status/address/target |
| 32 | DELETE | `/company/me/branches/{id}` | OrgController@destroyBranch | ✅ | L524. BRANCH_HAS_OPEN_OPERATIONS 409 |
| 33 | POST | `/company/me/branches/{id}/transfer-manager` | OrgController@transferManager | 🟡 | L525. 🟡: `newManagerUserId` only `required\|string` — **no tenant-membership validation** (zero-trust violation) and no sync of `CompanyUser.branch_id`/`asab_user_roles.branch_ids`, so the new manager's data scope doesn't follow (OrgController.php:225–234) |
| 34 | GET | `/company/me/modules` | ModuleController@index | ✅ | L528. 9 modules w/ Arabic names, counts for «مفعّلة» / «متاحة للتفعيل» / «تحتاج ترقية» (`activeCount/availableCount/upgradeRequiredCount`) |
| 35 | PATCH | `/company/me/modules/{moduleKey}` | ModuleController@toggle | ✅ | L529. **Plan-gated**: 403 `UPGRADE_REQUIRED` «يحتاج ترقية الخطة» when `!is_in_plan`; 409 MODULE_HAS_PENDING_OPS on deactivate with open ops; realtime broadcast |
| 36 | GET | `/company/me/billing/summary` | BillingController@summary | ✅ | L532. totalPaid, nextInvoice (date+amount), default PM `last4` (card **** 4521) |
| 37 | GET | `/company/me/billing/invoices/export` | BillingController@export | ✅ | L533 (before `{id}` — order safe). 202 + jobId, GenerateCompanyExportJob → `exports/{companyId}/{jobId}.{xlsx,csv}` + notification with download link |
| 38 | GET | `/company/me/exports/{jobId}/download` | ExportController@download | ✅ | L534. jobId regex guard, company-namespaced path = tenant isolation, 404 EXPORT_NOT_READY |
| 39 | GET | `/company/me/billing/invoices` | BillingController@invoices | ✅ | L535. status/year filters, paginated, `publicId` INV-2025-###, «✓ مدفوع» = `status: paid`, `pdfUrl` per row |
| 40 | GET | `/company/me/billing/invoices/{id}` | BillingController@show | ✅ | L536. Lines + payment transactions + VAT breakdown |
| 41 | GET | `/company/me/billing/invoices/{id}/pdf` | BillingController@pdf | ✅ | L537. ExportService::invoicePdf (RTL Arabic HTML→PDF), audited download |
| 42 | POST | `/company/me/billing/invoices/{id}/pay` | BillingController@pay | ✅ | L538. BillingService::pay — ALREADY_PAID 409, transaction, mock PSP charge, realtime invoicePaid |
| 43 | GET | `/company/me/billing/payment-methods` | BillingController@paymentMethods | ✅ | L539 |
| 44 | POST | `/company/me/billing/payment-methods` | BillingController@addPaymentMethod | ✅ | L540. Provider enum, mock tokenization, first-method auto-default |
| 45 | POST | `/company/me/billing/payment-methods/{id}/set-default` | BillingController@setDefaultPaymentMethod | ✅ | L541. Transactional single-default |
| 46 | DELETE | `/company/me/billing/payment-methods/{id}` | BillingController@deletePaymentMethod | ✅ | L542. IS_DEFAULT_METHOD 409 |
| 47 | GET | `/company/me/billing/address` | BillingController@address | ✅ | L543 |
| 48 | PUT | `/company/me/billing/address` | BillingController@updateAddress | ✅ | L544. Full KSA address incl. taxId/crNumber |
| 49 | GET | `/company/me/settings` | SettingsController@show | ✅ | L547. firstOrCreate; superset of CMP-7 (name/city/crNumber/email + currency/tz/vat/…) |
| 50 | PUT | `/company/me/settings` | SettingsController@update | ✅ | L548. Doc aliases `name`/`city` → legal_name/primary_city |
| 51 | PATCH | `/company/me/settings` | SettingsController@update | ✅ | L550 — doc-conformance alias of #50 |
| 52 | POST | `/company/me/settings/logo` | SettingsController@uploadLogo | ✅ | L551. image ≤2MB → public disk, `logoUrl` |
| 53 | PATCH | `/company/me/preferences` | SettingsController@updatePreferences | ✅ | L552. Notification/reminder/integration prefs |
| 54 | GET | `/company/me/api-keys` | ApiKeyController@index | ✅ | L555. Non-revoked only, prefix-masked presentation |
| 55 | POST | `/company/me/api-keys` | ApiKeyController@store | ✅ | L556. Scope whitelist, sha256 hash stored, **plaintext returned once** |
| 56 | DELETE | `/company/me/api-keys/{id}` | ApiKeyController@destroy | ✅ | L557. Tenant-scoped revoke, 204 |
| 57 | GET | `/company/me/sso` | SsoController@show | ✅ | L560. `{enabled:false}` default |
| 58 | PUT | `/company/me/sso` | SsoController@update | 🟡 | L561. saml/oidc validation, `oidc_client_secret` encrypted cast. 🟡: Enterprise gate reads **legacy `AsabCompany.plan === 'Enterprise'`** (platform-admin string) — NOT the live `CompanySubscription` plan code, so a portal self-upgrade to enterprise never unlocks SSO (SsoController.php:62–68; SubscriptionService::applyChange doesn't touch `asab_companies.plan`) |
| 59 | DELETE | `/company/me/sso` | SsoController@destroy | ✅ | L562. 204, deletes config |
| 60 | GET | `/company/me/webhooks` | TenantWebhookController@index | ✅ | L565. `secret_prefix` only |
| 61 | POST | `/company/me/webhooks` | TenantWebhookController@store | ✅ | L566. Event whitelist (5 events), **secret returned once** (`whsec_…`) |
| 62 | GET | `/company/me/webhooks/{id}/deliveries` | TenantWebhookController@deliveries | ✅ | L567. Paginated delivery log |
| 63 | PATCH | `/company/me/webhooks/{id}` | TenantWebhookController@update | ✅ | L568 |
| 64 | DELETE | `/company/me/webhooks/{id}` | TenantWebhookController@destroy | ✅ | L569 |
| 65 | POST | `/company/me/webhooks/{id}/test` | TenantWebhookController@test | ✅ | L570. Real signed delivery (X-ASAB-Signature sha256 HMAC), records status/error |
| 66 | GET | `/company/me/support/channels` | SupportController@channels | ✅ | L577. Seeded: «الدردشة المباشرة», phone `800 123 4567`, `support@asab.sa` «رد خلال 24 ساعة» (CompanyDashboardSeeder.php:81–83). Note: seeded chat hours «يومياً 8ص - 12م» ≠ SRS 9am–9pm |
| 67 | POST | `/company/me/support/chat/start` | SupportChatController@start | ✅ | L580. Queue position + estimated wait, realtime channel `chat.session.{id}` |
| 68 | GET | `/company/me/support/chat/{sessionId}` | SupportChatController@show | ✅ | L581. Owner-or-agent isolation (`findOwned`) |
| 69 | POST | `/company/me/support/chat/{sessionId}/message` | SupportChatController@message | ✅ | L582. Broadcast via SupportChatService |
| 70 | POST | `/company/me/support/chat/{sessionId}/close` | SupportChatController@close | ✅ | L583. 204 |
| 71 | GET | `/company/me/support/tickets` | SupportController@index | ✅ | L585. Non-admin sees own tickets only; status/category filters, paginated |
| 72 | POST | `/company/me/support/tickets` | SupportController@store | ✅ | L586. Category enum superset of SRS (subscription/technical/general_inquiry/feature_request/billing/other), Arabic default subjects, public_id TCK-### |
| 73 | GET | `/company/me/support/tickets/{id}` | SupportController@show | ✅ | L587. Messages thread; ownership guard «ليست تذكرتك» 403 |
| 74 | POST | `/company/me/support/tickets/{id}/reply` | SupportController@reply | ✅ | L588. Reopens waiting_customer, realtime notify opener |
| 75 | POST | `/company/me/support/tickets/{id}/close` | SupportController@close | ✅ | L589 |
| 76 | POST | `/company/me/support/tickets/{id}/attachments` | SupportController@addAttachment | ✅ | L590. ≤5MB |
| 77 | GET | `/company/me/audit-logs` | CrossController@auditLogs | ✅ | L800 (roles: company-admin,head). Tenant-scoped, action/entityType/actor/date filters, paginated |

Totals: **69 ✅ · 8 🟡 · 0 ❌** (every scoped route exists and is wired; the 🟡s are specific behavior gaps below).

## 2. Answers to critical checks

1. **Module toggle enforces plan gating («تحتاج ترقية»)? — YES.** `ModuleController::toggle` (Modules/Admin/app/Http/Controllers/Company/ModuleController.php:60–62) throws `AsabException('UPGRADE_REQUIRED', …, 'يحتاج ترقية الخطة', 403)` when activating a module with `is_in_plan=false`. `index` (line 41–50) returns `isActive`/`isInPlan` per module plus `upgradeRequiredCount` so the FE can render the three states «مفعّلة» / «متاحة للتفعيل» / «تحتاج ترقية». Re-gating on plan change is handled by `SubscriptionService::regateModules` (SubscriptionService.php:195–202) which also force-deactivates modules that fall out of plan. Note: code's Arabic message is «يحتاج ترقية الخطة» vs SRS chip «تحتاج ترقية» — FE should key off `UPGRADE_REQUIRED`, not the message string.

2. **Add-branch triggers admin-review state («سيظهر بعد مراجعة الإدارة»)? — NO.** `OrgController::storeBranch` (OrgController.php:175–185) creates the legacy branch row with `'status' => 'active'` immediately and returns 201 `{status: 'active'}`. There is no pending-review status, no platform-admin review queue/endpoint, and no notification to platform admins — grep for `pending_review|review_status|سيظهر بعد مراجعة` across `Modules/` returns zero hits. This is the largest SRS gap in the module (CMP-4).

3. **Quotas (branches/users/storage) in subscription payload? — PARTIAL.** `SubscriptionController::show` returns `usage` from `PlanLimitService::quotas()` (PlanLimitService.php:51–62), which covers **brands, restaurants, branches, users** as `{used, max}` (null max = unlimited, matching «n/∞» chips) — but has **no storage entry**. Storage appears only on the dashboard payload (DashboardController.php:53) with `usedGb` **hardcoded to 0** (`maxGb` from `plans.storage_gb` is real). CMP-2's storage-GB usage meter cannot render truthfully.

4. **Invite→accept→credentials flow end-to-end? — WORKS IN-BAND, BUT NO EMAIL DELIVERY.** Chain verified in code: `UserController::invite` (UserController.php:66–98) validates role scoping + brand tenant ownership + duplicate member (409) + user quota, creates `CompanyInvitation` with 64-char token, 7-day expiry → public `OnboardController::acceptInvitation` (OnboardController.php:84–133) validates token/pending/expiry, transactionally creates `AsabUser` (password auto-hashed via `'password' => 'hashed'` cast, AsabUser.php:31) + `AsabUserRole` scope + `CompanyUser(active)`, marks invitation accepted, and **returns access+refresh tokens** (`AuthService::issueTokens`) with role-appropriate `defaultPage`. Two defects: (a) the token is never emailed to the invitee — the only "delivery" is the API response to the admin and an in-app notification **to the inviter** (UserController.php:94); (b) `resend-invite` (UserController.php:208–218) is a stub returning `{resent:true}` with no regeneration/delivery. Also (c) `AsabUser::updateOrCreate` keyed on email can silently re-home a user who already exists under a different company (overwrites `company_id` and password) — cross-tenant edge.

## 3. Gaps

1. **CMP-4 add-branch pending review** → `storeBranch` creates `status='active'`; no `pending_review` state, no platform-admin approve/reject surface, no «سيظهر بعد مراجعة الإدارة» response semantics → Core meeting requirement; without it tenant-created branches bypass platform governance and appear instantly in org tree/quotas.
2. **CMP-3 invitation delivery + resend** → invite email with accept link never sent to the invitee; `resend-invite` is a no-op stub on the wrong entity (CompanyUser instead of CompanyInvitation); no `POST company/invitations/{id}/resend` → The invite modal's «إرسال دعوة» promise is false; onboarding of company staff is impossible without the admin manually copying the token from the API response.
3. **Invitation-accept cross-tenant collision** → `AsabUser::updateOrCreate(['email'=>…])` in `acceptInvitation` rewrites `company_id` + password of an existing user from another tenant → account hijack/tenant-isolation breach if an admin (maliciously or not) invites an email that already exists elsewhere.
4. **CMP-2/CMP-1 storage quota** → no storage-usage computation anywhere; `quotas()` lacks the key, subscription payload omits it, dashboard hardcodes `usedGb: 0` → Storage meter (basic 2 GB / pro 10 GB / enterprise ∞ per seeder) renders fake data; no enforcement of the plan's storage cap.
5. **SSO plan gate reads stale field** → `SsoController::assertEnterprise` checks `AsabCompany.plan === 'Enterprise'` (set only by platform admin, `Admin/CompanyController`), while portal upgrades mutate `CompanySubscription.plan_id` only → self-service enterprise upgrade never unlocks SSO; conversely a stale 'Enterprise' string on a downgraded company keeps SSO open.
6. **transfer-manager unvalidated + scope not synced** → `newManagerUserId` accepted raw (no check it is a company member / branch-role user); `CompanyUser.branch_id` and `asab_user_roles.branch_ids` are not updated → violates zero-trust rule; the transferred manager's effective data scope (ResolveTenant reads `asab_user_roles`) doesn't move with the branch.
7. **Support channels seed vs SRS (data-only)** → seeded chat hours «يومياً 8ص - 12م» / phone hours «الأحد-الخميس 9ص-6م» vs SRS "live chat 9am–9pm" → cosmetic; fix seed so FE demo matches the prototype copy.
8. **No Pest coverage** → zero feature tests exist for any of the 77 endpoints (tests/Feature has no company-portal file) → the whole surface is unguarded against regression; master-plan DoD requires green tests.

## 4. Tasks (ordered, dependency-aware)

- [ ] T14.1 Implement add-branch pending-review flow: migration adding `asab_review_status` (`pending_review|approved|rejected`, default `approved` for legacy rows) to `branches`; `OrgController::storeBranch` creates with `pending_review` + returns `{status:'pending_review', messageAr:'سيظهر بعد مراجعة الإدارة'}`; notify platform admins; add platform-admin review endpoints (`GET admin/branch-requests`, `POST admin/branch-requests/{id}/approve|reject` in `Admin/BranchController` or a dedicated controller); org `tree` includes the pending badge; pending branches excluded from active-branch quota chips but counted for `assertCanAdd` — Files: `Modules/Admin/app/Http/Controllers/Company/OrgController.php`, `Modules/Admin/app/Http/Controllers/Admin/BranchController.php`, `Modules/Admin/routes/api.php`, new migration in `Modules/Admin/database/migrations/`, `Modules/Admin/app/Services/NotificationService.php` (trigger) — Accept: POST me/branches returns 201 with `status=pending_review`; branch invisible as "active" in tree until platform-admin approves; approve flips to `active` + notifies company-admin; reject notifies with reason.
- [ ] T14.2 Deliver invitation emails + real resend: queued Mailable (Arabic RTL) with accept-link `token` sent on invite; `POST company/invitations/{id}/resend` route regenerating/extending token + re-sending; rewrite `UserController::resendInvite` to resolve the member's pending invitation and delegate (keep 202 contract); never expose `token` in list responses once email delivery exists — Files: `Modules/Admin/app/Http/Controllers/Company/UserController.php`, new `Modules/Admin/app/Mail/CompanyInvitationMail.php` (or Notification-module channel), `Modules/Admin/routes/api.php` — Accept: invite dispatches queued mail to invitee containing the accept URL; resend on invitation id resends + extends `expires_at`; resend on a non-pending invitation → 409.
- [ ] T14.3 Harden `acceptInvitation` against cross-tenant email collision: if an `AsabUser` with the invitation email exists under a different `company_id`, reject with 409 `EMAIL_IN_OTHER_COMPANY` (or explicit re-link flow) instead of `updateOrCreate`; never overwrite an existing password — Files: `Modules/Admin/app/Http/Controllers/Company/OnboardController.php` — Accept: accepting an invite for an email registered to another company returns 409 and mutates nothing; fresh-email accept still returns tokens.
- [ ] T14.4 Real storage quota: compute used bytes per company (sum of attachment/upload sizes — `operation_attachments`, ticket attachments, logos), add `storage: {usedGb, maxGb}` to `PlanLimitService::quotas()`/`usage()`, surface in subscription `usage` and dashboard (drop the hardcoded 0), optionally enforce in `assertCanAdd('storage')` on upload paths — Files: `Modules/Admin/app/Services/PlanLimitService.php`, `Modules/Admin/app/Http/Controllers/Company/DashboardController.php`, `Modules/Admin/app/Http/Controllers/Company/SubscriptionController.php` — Accept: GET me/subscription returns `usage.storage.{used,max}`; dashboard `quotas.storage.usedGb` reflects seeded attachment sizes; unlimited plan → `max: null`.
- [ ] T14.5 Fix SSO enterprise gate: gate on the live subscription plan code (`SubscriptionService::current($companyId)->plan->code === 'enterprise'`), or sync `asab_companies.plan` inside `SubscriptionService::applyChange`; keep 403 `PLAN_REQUIRED` contract — Files: `Modules/Admin/app/Http/Controllers/Company/SsoController.php`, (`Modules/Admin/app/Services/SubscriptionService.php` if sync route chosen) — Accept: company upgraded to enterprise via portal can PUT me/sso; professional-plan company gets 403 `PLAN_REQUIRED` regardless of the legacy string column.
- [ ] T14.6 Validate + sync transfer-manager: assert `newManagerUserId` is an active `CompanyUser` of the same company (422 `INVALID_ROLE_SCOPE` otherwise); in one transaction update `branches.asab_manager_user_id` + `manager` label, move `CompanyUser.branch_id`, and update `asab_user_roles.branch_ids` for the branch role — Files: `Modules/Admin/app/Http/Controllers/Company/OrgController.php` — Accept: foreign/unknown user id → 422; after transfer, new manager's `/company/me/branch/overview` resolves to the transferred branch.
- [ ] T14.7 Align support-channel seed data with SRS copy (chat hours 9am–9pm) — Files: `Modules/Admin/database/seeders/CompanyDashboardSeeder.php` — Accept: GET support/channels returns chat hours matching the prototype.
- [ ] T14.8 Pest feature tests for the full T14 surface (see §5) — Files: `tests/Feature/CompanyPortalSubscriptionTest.php`, `tests/Feature/CompanyPortalUsersInvitationsTest.php`, `tests/Feature/CompanyPortalOrgTest.php`, `tests/Feature/CompanyPortalModulesBillingTest.php`, `tests/Feature/CompanyPortalSettingsIntegrationsTest.php`, `tests/Feature/CompanyPortalSupportTest.php` — Accept: `composer test -d memory_limit=1024M` green; every case in §5 covered.
- [ ] T14.9 Write FE wiring doc from `docs/fe-wiring/_TEMPLATE.md` covering all ✅ endpoints (and the fixed 🟡 ones) → `docs/fe-wiring/FE-T14-company-portal.md`; flip T14 on the master-plan board — Accept: doc contains method/path/role/middleware, request/response JSON from seeded data, enums with Arabic labels, pagination contracts, screen mapping per §6.

## 5. Tests required

Pest feature tests (SQLite in-memory; seed via `CompanyDashboardSeeder`; remember `-d memory_limit=1024M`):

**Subscription & plans**
- plans list returns 3 active plans with `isCurrent` on the subscriber's plan; head role allowed, accountant 403.
- show returns usage quotas incl. storage (post-T14.4) and nextInvoice amount matching billing cycle.
- upgrade basic→professional immediate: plan_id changes, SubscriptionChange logged, proration invoice `line_type=proration` created, modules re-gated (`is_in_plan` updated).
- upgrade to same plan+cycle → 409 `ALREADY_ON_PLAN`; upgrade on cancelled sub → 409 `INVALID_TRANSITION`.
- downgrade with usage above target caps → 422 `QUOTA_WOULD_EXCEED` with violations array.
- cancel (period_end) keeps status, sets `cancel_at_period_end`, auto_renew false; reactivate restores; reactivate on expired → 409 `SUBSCRIPTION_EXPIRED`.
- billing-cycle toggle monthly↔annual logs `cycle_change`.
- contact-sales creates a high-priority subscription ticket, 202.

**Users & invitations**
- users index meta `activeCount`/`maxUsers` («نشط · n/50»); role/status/search filters; tenant isolation (company B members never listed).
- invite: branch role without branchId → 422; accountant without brandId → 422; brandId of another company → 422 `INVALID_ROLE_SCOPE`; duplicate member → 409; at max_users quota → 409 `QUOTA_EXCEEDED`; success dispatches invitation mail (post-T14.2, `Mail::fake`).
- accept: valid token creates hashed-password user + role scope + active CompanyUser + returns tokens; expired token → 422 `INVITATION_EXPIRED` and marks invitation expired; reused token → 422; email existing in another company → 409 (post-T14.3).
- resend: pending invitation → 202 + mail resent + expiry extended; accepted invitation → 409 (post-T14.2).
- toggle-status: self → 409 `CANNOT_DISABLE_SELF`; last active admin → 409 `LAST_ACTIVE_ADMIN`; demote last admin via PATCH → 409.
- update with brandId for accountant syncs `asab_user_roles.brand_ids`.

**Org (brands/restaurants/branches)**
- tree returns brand→restaurant→branch with pctOfTarget from seeded sales operations; meta maxBranches.
- storeBrand duplicate name → 409; over brand quota → 409 `QUOTA_EXCEEDED`.
- destroyBrand with restaurants → 409; destroyRestaurant with branches → 409; destroyBranch with pending ops → 409 `BRANCH_HAS_OPEN_OPERATIONS`.
- storeBranch → 201 `pending_review` + platform-admin notification; branch hidden from active counts until admin approves; admin approve → active + company-admin notified (post-T14.1).
- transfer-manager: user from another company → 422; success moves `CompanyUser.branch_id` + role scope (post-T14.6).
- tenant isolation: PATCH/DELETE against another company's brand/restaurant/branch id → 404.

**Modules**
- index returns 9 modules with Arabic names + three-state counts.
- toggle on with `is_in_plan=false` → 403 `UPGRADE_REQUIRED`; toggle off with pending/approved ops → 409 `MODULE_HAS_PENDING_OPS`; happy toggle persists `toggled_by_id`.
- after plan downgrade, out-of-plan module force-deactivated (`regateModules`).

**Billing**
- summary totalPaid sums only paid invoices of own company; default PM last4.
- invoices pagination + status/year filters; pdf endpoint returns `application/pdf` and writes audit log; other company's invoice id → 404.
- pay open invoice → paid + transaction + `amount_due` 0; pay paid invoice → 409 `ALREADY_PAID`.
- payment methods: add sets first as default; delete default with others present → 409 `IS_DEFAULT_METHOD`; set-default exclusive.
- invoices/export → 202 + jobId; after running job, `exports/{jobId}/download` streams the file; foreign jobId (other company namespace) → 404; malformed jobId → 400.
- public webhook: missing id/type → 400; duplicate event_id → `duplicate:true` no reprocess; `payment_succeeded` marks invoice paid; bad signature with configured secret → 401.

**Settings / API keys / SSO / tenant webhooks**
- settings PUT with doc-alias `{name, city, crNumber, email}` persists; PATCH alias identical; logo upload validates image.
- api-keys: create returns plaintext once + only hash stored; list masks (prefix only); revoked key disappears from index; invalid scope → 422.
- sso: non-enterprise → 403 `PLAN_REQUIRED` (against subscription plan post-T14.5); enterprise PUT persists with encrypted oidc secret; DELETE → 204 + show returns `enabled:false`.
- tenant webhooks: store returns secret once; invalid event → 422; test endpoint records delivery (Http::fake); deliveries paginated; tenant isolation on {id}.

**Support**
- channels public to all 5 company roles; branch-role user can open ticket; ticket index for non-admin only shows own tickets; foreign-user ticket show → 403 «ليست تذكرتك»; reply reopens `waiting_customer`; attachment >5MB → 422.
- chat: start returns queue position; foreign session id → 404 (`findOwned`); message broadcasts; close → 204.
- audit-logs: company-admin + head 200, accountant 403; only own-company rows.

**Cross-cutting**
- every mutating company-admin endpoint rejects head/accountant/branch/procurement (403 role denial spot-checks).
- idempotency middleware: replaying a POST with the same Idempotency-Key returns the cached response (spot-check invite + pay).

## 6. FE wiring notes

- **Base + middleware**: all paths under `/api/v1`. Company-admin block requires `Authorization: Bearer`, tenant resolution (`asab.tenant`), role `company-admin`; mutations accept `Idempotency-Key` and are audited. Support endpoints accept all 5 portal roles: `company-admin, head, accountant, branch, procurement`.
- **Money is halalas** everywhere (`*Halalas`, `priceMonthlyHalalas`, `totalHalalas`) — divide by 100 for SAR display.
- **Quota chips**: `{used, max}` with `max: null` = unlimited → render «n/∞»; branches cap renders «من 20 مسموح» from `meta.maxBranches` (org tree) / `quotas.branches.max` (dashboard).
- **Module three-state mapping**: `isActive=true` → «مفعّلة»; `isActive=false && isInPlan=true` → «متاحة للتفعيل»; `isInPlan=false` → «تحتاج ترقية». Activating a gated module returns HTTP 403 `code: UPGRADE_REQUIRED`, `messageAr: «يحتاج ترقية الخطة»` — key the upgrade-alert modal off the code, not the string.
- **Error envelope**: `{success:false, code, message, messageAr, details}`. Key codes: `QUOTA_EXCEEDED` (409), `QUOTA_WOULD_EXCEED` (422 + `details.violations[]`), `ALREADY_ON_PLAN`, `INVALID_TRANSITION`, `SUBSCRIPTION_EXPIRED`, `LAST_ACTIVE_ADMIN`, `CANNOT_DISABLE_SELF`, `USER_ALREADY_MEMBER`, `INVALID_ROLE_SCOPE`, `MODULE_HAS_PENDING_OPS`, `BRAND_HAS_DEPENDENTS`, `RESTAURANT_HAS_BRANCHES`, `BRANCH_HAS_OPEN_OPERATIONS`, `ALREADY_PAID`, `IS_DEFAULT_METHOD`, `PLAN_REQUIRED`, `UPGRADE_REQUIRED`, `INVALID_INVITATION`, `INVITATION_EXPIRED`, `EXPORT_NOT_READY`.
- **Canonical vs alias paths**: `POST /company/invitations` is canonical for inviting; `POST /company/me/users` is a doc-conformance alias to the same handler — FE should use the canonical invitations path (it pairs with `GET /company/invitations` + revoke/resend). `PUT /company/me/settings` is canonical; `PATCH` is the alias. Settings body accepts doc-aliases `name`/`city` mapping to `legalName`/`primaryCity` — response echoes both key sets.
- **Secrets shown once**: API key plaintext (`asab_live_…`) only in the POST response; tenant-webhook `secret` (`whsec_…`) only in the POST response — afterwards only `prefix`/`secret_prefix` are available. Design copy-once UI.
- **Async export flow**: `GET billing/invoices/export` → 202 `{jobId}`; completion arrives as notification type `export.ready` with link `/api/v1/company/me/exports/{jobId}/download`; download 404s with `EXPORT_NOT_READY` until the queue worker finishes.
- **Invoice PDF** is a raw `application/pdf` binary (not the JSON envelope); invoice `publicId` format `INV-YYYY-###`; «✓ مدفوع» = `status: "paid"`; support ticket `publicId` = `TCK-###`.
- **Subscription cancel reasons enum**: `too_expensive | missing_features | switching_provider | shutting_down | other`; billing cycle: `monthly | annual` (annual saving «سنوي (وفّر 17%)» comes from `plan.annualDiscountPct`).
- **Ticket categories** (Arabic default subjects generated server-side): `subscription`→«استفسار عن الاشتراك», `technical`→«مشكلة تقنية», `general_inquiry`→«استفسار عام», `feature_request`→«طلب ميزة», `billing`→«استفسار عن الفوترة», `other`→«أخرى».
- **Realtime**: subscription/quota/invoice/module/user-lifecycle events broadcast (RealtimeBroadcaster); chat uses channel `chat.session.{sessionId}`. Wire bell + live chat to these.
- **Screen mapping**: CMP-1 `ca-dashboard` → #8/#9; CMP-2 `ca-subscription` → #3, #10–16; CMP-3 `ca-users` → #5–7, #17–22; CMP-4 `ca-branches` → #23–33; CMP-5 `ca-modules` → #34–35; CMP-6 `ca-billing` → #36–48; CMP-7 `ca-settings` → #49–53 (+integrations #54–65); CMP-8 `ca-support` → #66–76; audit screen → #77; onboarding wizard → #4; invite-accept public page → #1.
- **Quirks**: add-branch currently returns `status:'active'` — after T14.1 the FE must render the pending badge from `status:'pending_review'` and show «سيظهر بعد مراجعة الإدارة». `GET /plans` also allows `head`. Dashboard `storage.usedGb` is fake-0 until T14.4. Invitation `token` appears in invitations list responses (pre-T14.2) — do not render it.
