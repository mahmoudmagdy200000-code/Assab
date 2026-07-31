# تحقّق ربط الداشبورد بالباك اند — قبل ميتنج 2026-07-31 (22:00)

> **الداشبورد المربوط:** https://orchid-crow-319475.hostingersite.com/#/preview/asab/ASABPrototype
> **الباك اند:** https://ivory-snail-183262.hostingersite.com/api/v1
> كل سطر تحت متحقّق بنداء حقيقي على السيرفر المباشر (مش من الدوكس). التاريخ: 2026-07-31.

---

## ⚡ الخلاصة في سطرين

**الربط نفسه سليم** — كل الـendpoints بترد صح، العزل بين العلامات شغال، والفلاتر والتواريخ مضبوطة.
**المشكلة الأكبر في الفرونت**: الشاشة الرئيسية بتعرض كل الفلوس **×100**، و«عرض» على أي صف بيفتح
شاشة غلط، و«مراجعة الجرد» بتعرض صفر فروع، وفيه شاشتين بيعرضوا **أرقام مخترعة** كأنها حقيقية.
التفاصيل الكاملة بأدلة من الباندل في **[FE-BLOCKERS-2026-07-31.md](FE-BLOCKERS-2026-07-31.md)**.
اتنين منهم (الفلوس ×100 وتكلفة الأصول ×100) **اتصلحوا من ناحية الباك اند** فهيظبطوا من غير ديبلوي فرونت.

---

## ✅ اللي اتأكد إنه شغال صح

| # | البند | الدليل |
|---|---|---|
| 1 | **الفرونت مربوط على الـAPI الصح** | الباندل `assets/index-CRS1pwF2.js` مكمبايل على `https://ivory-snail-183262.hostingersite.com` |
| 2 | **تاريخ «14 أكتوبر 2025» المثبّت اتشال** | مفيش أي أثر لـ`2025-10-14` في الباندل |
| 3 | **الدخول شغال لـ6 أدوار** | head / admin / accountant.burger / accountant.shawarma / procurement / owner → 200 |
| 4 | **تجديد التوكن (refresh)** | `POST /auth/refresh` بيرجع زوج جديد + `expiresIn: 900` |
| 5 | **صندوق العمليات بأسماء مش IDs** | 21 عملية، كلها بـ`branchName`/`brandName` عربي صحيح + `date` بيوم العملية الحقيقي |
| 6 | **تفاصيل المشتريات `PUR-`** | `purchases.purchaseItems[]` + `summary` كاملة؛ `totalHalalas = ordQty × unitPriceHalalas` مضبوطة؛ `rcvQty: null` → `lineMatch: pending` |
| 7 | **تفاصيل الجرد `INV-`** | `payload.items[]` بـ`actualQty`/`purchases`/`waste`/`expectedQty` |
| 8 | **فلو «تحديد الأصناف للجرد» كامل** | العلامات → الفروع → الكتالوج → حفظ القائمة رجّع `{"savedCount":3,"pushedAt":"…"}` وقريناها تاني اتحفظت |
| 9 | **عزل العلامات (multi-tenancy)** | محاسب شاورما على علامة برجر → **404** (fail-closed، مفيش تسريب) |
| 10 | **سطح الهيد** | `/head/operations/pending` + `final-approved` + `rejected` → 200 |
| 11 | **الأخطاء موحّدة بالعربي** | `{"error":{"code","message","messageAr"},"requestId"}` على الراوتس الموجودة |

---

## 🔴 اللي لازم يتعمل قبل الميتنج

### أ) على السيرفر (كونفج — الأولوية القصوى)

> ⛔ **تصحيح مهم لتعليمات ملف 2026-07-30:** `php artisan storage:link` **مش الحل** هنا — الـsymlink
> **موجود فعلًا** والملفات شغالة. وبالذات **ممنوع `storage:link --force`**: ده بيمسح
> `public/storage` — يعني **كل الفواتير المرفوعة تتمسح** ومفيش رجوع.

**التشخيص الحقيقي (متحقّق بنداء مباشر):** الملفات متشافة على الويب تحت `/public/storage/`،
والـ`APP_URL` بيولّد لينك من غير جزء `/public` فبيرجع 404:

```bash
# نفس الملف بالظبط:
curl -sI https://ivory-snail-183262.hostingersite.com/storage/expenses/receipts/expense_019fb38e-…jpg   # 404
curl -sI https://ivory-snail-183262.hostingersite.com/public/storage/expenses/receipts/expense_019fb38e-…jpg   # 200 OK ✅
```

الإصلاح كله في `.env` — **من غير أي كوماند بيمسح حاجة**:

```bash
cd ~/domains/ivory-snail-183262.hostingersite.com/public_html

# في .env:
#   APP_URL=https://ivory-snail-183262.hostingersite.com/public   ← بجزء public، ومن غير / في الآخر
#   APP_DEBUG=false
#   APP_ENV=production
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan optimize:clear
```

**`APP_DEBUG` مفتوح دلوقتي فعلًا وبيسرّب** (ده اتأكد بنداء، مش تخمين):

```bash
curl -H "Accept: application/json" -H "Authorization: Bearer <token>" \
  https://ivory-snail-183262.hostingersite.com/api/v1/accountant/operations/<any-id>
# بيرجع stack trace كامل فيه اسم حساب الاستضافة والمسار المطلق على السيرفر:
# "file":"/home/u887387254/domains/…/vendor/laravel/framework/…"
```

أي راوت غلط أثناء الديمو بيطبع ده في الـNetwork tab. **لازم يتقفل قبل الميتنج.**

**التأكيد بعد التعديل:**

```bash
curl -sI https://ivory-snail-183262.hostingersite.com/public/storage/… | head -1    # 200
curl -s -H "Accept: application/json" .../api/v1/route-does-not-exist | head -c 200 # من غير file/trace
```

### ب) بعد سحب كود النهاردة

```bash
git pull && /opt/alt/php83/usr/bin/php artisan optimize:clear

# 1) يخلّي إصلاح الفاتورة الـ0.00 يوصل للعمليات المتسجّلة بالفعل (بدون إعادة seeding):
/opt/alt/php83/usr/bin/php artisan asab:bridge-backfill --resync-payloads --dry-run
/opt/alt/php83/usr/bin/php artisan asab:bridge-backfill --resync-payloads

# 2) يملا فجوات الديمو الـ3 (تبويب المبيعات فاضي + تبويب الهدر فاضي + مفيش لوجين بوابة فرع):
/opt/alt/php83/usr/bin/php artisan asab:demo-topup --dry-run    # يقولك الناقص من غير ما يكتب
/opt/alt/php83/usr/bin/php artisan asab:demo-topup
```

> ⛔ **ممنوع `migrate:fresh` على البرودكشن** — فيه داتا حقيقية (فاتورة يوم 23) جنب داتا الديمو.
> `asab:demo-topup` مبني عشان ده بالظبط: **بيضيف بس**، بيتخطّى أي حاجة موجودة، ومبيلمسش
> يومية مدير شغّالة ولا بيعمل reset لباسورد لوجين قائم (متغطّي بتستات).

### ج) فجوات محتوى الديمو (متأكَّدة على البرودكشن دلوقتي)

| التبويب | عدد العمليات دلوقتي | السبب |
|---|---|---|
| المصروفات | 13 | ✅ |
| الورديات | 10 | ✅ |
| المشتريات | 3 | ✅ |
| الجرد | 3 | ✅ |
| **المبيعات** | **0** | مفيش إقفال يومية مدير على البرودكشن → الجسر مامنتّش عملية `sales` |
| **الهدر** | **0** | بيجي من بوابة الفرع، ولوجين البوابة نفسه مش موجود على البرودكشن |

يعني **تبويبين من الستة هيفتحوا فاضيين قدام العميل** — `asab:demo-topup` بيحلّ التلاتة.

### د) دور «مالك العلامة» — يتشال من ديمو الداشبورد

`owner@nakhat.sa` بيعمل لوجين 200، بس **مفيش ولا شاشة داشبورد بتقبله**: كل `/accountant/*`
و`/company/me/*` بترجع 403/404 لأن مفيش راوت بيقبل رول `brand-owner`. وسطح الموبايل بتاعه
(`/brand-owner/*`) بيرجع 403 كمان لأن مكانش ليه صف في جدول `brand_owners`.
الإصلاح في السييدر خلّى الحساب شغّال في عالم الموبايل، لكن **شاشات الداشبورد للدور ده مش مبنية** —
يتشال من الديمو أو يتعرض من تطبيق الموبايل.

---

## 🛠 إصلاحات باك اند اتعملت النهاردة (كود + تستات)

| # | المشكلة اللي اتشافت على البرودكشن | الإصلاح |
|---|---|---|
| 1 | **فاتورة EXP-0011 بتتعرض `0.00 ر.س`** وفوقيها الإجمالي `3,008.00` — وكمان متعلّمة «مطابقة ✅» | الفاتورة غير الضريبية بتتخزّن بـ`tax_total_amount = 0.00` (مش `null`)، فالـ`??` مكانش بيلقطها. دلوقتي الصفر بيتعامل زي الغياب → بيقع على مجموع السطور ثم إجمالي المصروف. `ExpenseBridgeService` + تست |
| 2 | **سجل الأصول: عمود الفرع فاضي** — الصف فيه `branchId` بس من غير `branchName` | `AssetController::present()` بيحلّ اسم الفرع (باستعلام واحد لكل صفحة، مش N+1) + تست |
| 3 | **مالك العلامة `owner@nakhat.sa` مقفول في العالمين** | السييدر كان بيعمل صف داشبورد بس؛ دلوقتي بيعدّي على `BrandOwnerProvisioningService` فبيتعمل صف الموبايل + الربط بنفس الباسورد + تست |
| 4 | **`asab:bridge-backfill` مش بيصلّح العمليات المتسجّلة أصلًا** — بيختار اللي مالهاش mirror بس، يعني أي إصلاح payload مبيوصلش لداتا البرودكشن | فلاج جديد `--resync-payloads` بيعيد mapping للعمليات المتسجّلة **الـpending بس** (اللي المحاسب اتصرّف فيها متتلمسش) + تستين |
| 5 | **الديمو على البرودكشن ناقص** ومستحيل يتعمله re-seed (فيه داتا حقيقية) | كوماند جديد `asab:demo-topup` — إضافي بالكامل، idempotent، بيتخطّى الموجود، ومحميّ بتستات إنه **مبيلمسش يومية مدير شغّالة** ولا **بيعمل reset للوجين قائم** |
| 6 | **كل الفلوس في صندوق العمليات ×100** — الفرونت بيقسم `amountHalalas` على 100 وبيطبع `amount` زي ما هي، والـAPI كان بيبعت `amount` بس | ضفنا `amountHalalas` على صفوف المحاسب + التفصيل + الهدر (سطح الهيد كان بيبعتها أصلًا — عشان كده شاشته كانت مظبوطة) |
| 7 | **عمود «التكلفة» في الأصول ×100** — الفرونت بيقرا `costHalalas` غير الموجود | ضفنا `costHalalas` (الـ`bookValueHalalas` كان موجود، عشان كده الدفترية كانت صح والتكلفة غلط) |
| 8 | **إجمالي المشتريات بيتناقض مع جدول الأصناف بفرق 15% بالظبط** (3 من 3 عمليات) — الهيدر شامل الضريبة والأصناف صافي | `purchases.summary` بقى فيه `vatHalalas` + `orderedValueWithVatHalalas` فالرقمين مفسّرين على الشاشة |
| 9 | **اسم المورد `null` في بلوك المشتريات** — أوردر الموبايل بيحمل supplier id قديم مبيتحلّش | fallback على `payload.supplierName` |
| 10 | **«الكمية المتوقعة» سالبة (−1.5)** — أول جرد للفرع مالوش رصيد افتتاحي فالحساب بيطرح من صفر | الرصيد السالب مستحيل فيزيائيًا → بيرجع `null` («—») بدل رقم مخترع، وضفنا `unitPriceHalalas` للـpayload عشان قيمة الفروقات متبقاش 0.00 |
| 11 | **سجل الورديات فاضي** رغم وجود 10 عمليات SHF — الـhistory بيفلتر `status='closed'` بس، والوردية المجسّرة بتقف عند `pending_review` | نفس القاعدة اللي الـKPI في نفس الملف بيطبقها أصلًا (`closedToday` بيعدّ الاتنين) — الـhistory بقى `whereIn(['pending_review','closed'])` |

**التستات:** `ExpenseBridgeTest` · `FixedAssetsRegisterTest` · `PurchasesAccountantTest` · `InventoryBridgeTest` · `BridgeBackfillCommandTest` · `DemoTopUpCommandTest` · `FullDemoSeederSmokeTest` · `E2eHardeningTest` · `AccountantDashboardTest` · `ShiftsTest` · `InventoryWasteTest` · `AssetReceiveBridgeTest` — كلها خضرا (14 تست جديد). `pint` نضيف.

> مفيش أي migration جديد في الدفعة دي — الديبلوي = `git pull` + `optimize:clear` بس.
