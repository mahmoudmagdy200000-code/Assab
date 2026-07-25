# رد الباك اند على ملاحظات الفرونت — تقارير Brand Owner المالية (2026-07-25)

> **من:** الباك اند · **إلى:** الفرونت (سيف) · **رد على:** رسائل 2026-07-23.
> كل بند تحت متغطّى بتست في `tests/Feature/BrandOwnerFinancialReportingTest.php` (25 تست ✓).

---

## ملخص سطر لكل بند

| # | الملاحظة | الحالة |
|---|---|---|
| 1 | `break-even-analysis?branch_id=` (فاضي) | **اتصلح قبل كده** — `branch_id` اختياري، فاضي = أول فرع |
| 2 | `operational-profitability/email` بترجع «invalid format type» | **اتصلح دلوقتي** — `format_type` فاضي/`null`/محذوف = `PDF` |
| 3 | `menu-engineering` + الإكسبورت | **اتصلح** — الفرع والفترة كلهم اختياريين في GET + export + email |
| 4 | `item-test/submit` بيطلب `branch_id` | **اتصلح قبل كده** — اختياري، وبيتخزن عليه أول فرع |
| 5 | `price-simulator/saved-scenarios` عايز `id` + `details` | **اتصلح قبل كده** — كل عنصر `{ id, details: {…} }` |
| 6 | `smart-comparison?type=month` لسه بيطلب `branch_id` | **اتصلح دلوقتي** — اختياري، وكمان `compared_branch_id` |

---

## 1. `branch_id` بقى اختياري في كل تقارير المالية

القاعدة الموحّدة دلوقتي: **تبعتش `branch_id`، أو تبعته فاضي → الباك اند بيرجّع أول فرع (أبجديًا) من الداتابيز**،
والريسبونس بيرجّع `branch_id` + `branch_name` المتحلّين، فتعرفوا انتوا بتبصّوا على أنهي فرع.

اتطبقت على: `profit-and-loss` (GET/export/email)، `sales-channel-analysis` (GET/export/email)،
`sales-channel-level2` (GET/export)، `smart-comparison` (GET/export/email)، `break-even-analysis`،
`operational-profitability`، `menu-engineering` (GET/export/email)، `item-test/submit`.

**استثناءات مقصودة:** `price-simulator/item-info` و `price-simulator/simulate` لسه `branch_id` مطلوب —
لأنهم بيقروا سعر الصنف **جوّه فرع معيّن** (`branch_item`)، ومن غير فرع مفيش سعر.

### `smart-comparison` تحديدًا
- `GET /brand-owner/financial/smart-comparison?type=month` بيشتغل من غير أي بارامتر تاني:
  الفرع = أول فرع، الفترة = الشهر الحالي مقارنة بالشهر اللي قبله.
- `type=branch` من غير `compared_branch_id` → الباك اند بياخد **الفرع اللي بعده** أبجديًا.
  لو مفيش غير فرع واحد في الداتابيز: `compared_branch_id`/`compared_branch_name` = `null`
  و`compared_value` = `0.0` (مش بيجمّع كل الفروع).
- الإكسبورت والإيميل بقى مطلوب فيهم `type` + (`format_type` أو `email`) بس؛ الفترة والفروع اختياريين.

## 2. `format_type` الفاضي

الرسالة اللي كانت بتيجي «The selected format type is invalid» سببها إن `format_type` كان بيتبعت
**سترينج فاضي**، والقاعدة `in:PDF,Excel` كانت بتقع عليه.

دلوقتي: `""` أو `null` = «مش مبعوت» ⇒ الديفولت `PDF` في `operational-profitability/email`.
و`"pdf"` / `"excel"` (small) مقبولين في كل الإندبوينتس زي ما هما.

**ملحوظة:** في إندبوينتس **الإكسبورت** `format_type` لسه **مطلوب** (لازم تختاروا PDF ولا Excel صراحةً) —
بس لو بعتوه فاضي هترجع رسالة «required» واضحة مش «invalid».

## 3. `menu-engineering/export` و `/email`

بقى مطلوب فيهم `format_type` (للإكسبورت) أو `email` (للإيميل) **بس**.
`year` / `month` / `compared_year` / `compared_month` / `branch_id` كلهم اختياريين ونفس ديفولت الـ GET
(الشهر الحالي مقابل اللي قبله، وأول فرع).

## 4. `item-test/submit` و `saved-scenarios`

- `item-test/submit`: `branch_id` اختياري من قبل كده — لو محذوف بيتخزّن على أول فرع وبيرجع في `branch_name`.
- `price-simulator/saved-scenarios`: كل عنصر بقى `{ "id": "…", "details": { … } }`، و`id` هو نفسه
  الـ `scenario_id` اللي بتبعتوه لـ `price-simulator/export` و `/email`.

---

## نقطة محتاجة تأكيد منكم: البريفكس

روابطكم كلها بتستخدم `/api/v1/brand-owner/financial/...`، لكن في الريبو دي المسارات متسجّلة تحت
**`/api/brand-owner/financial/...`** (بريفكس `v1` مستخدم في مسارات داشبورد ASAB بس — `Modules/Admin`).
لو `/api/v1/...` شغال عندكم على السيرفر يبقى فيه rewrite على الاستضافة أو الديبلوي من نسخة أقدم —
لو ظهر 404 بعد الديبلوي الجديد استخدموا `/api/brand-owner/financial/...`.

---

## التغطية

`tests/Feature/BrandOwnerFinancialReportingTest.php` — 25 تست / 421 assertion ✓، منهم الجديد:
`smart_comparison_defaults_to_first_branch_when_branch_id_omitted`،
`smart_comparison_branch_type_defaults_compared_branch`،
`smart_comparison_export_and_email_without_branch_id`،
`operational_profitability_email_accepts_blank_or_omitted_format_type`،
`menu_engineering_export_and_email_without_period_or_branch`.

العقد الكامل محدَّث في `docs/tasks/brand-owner-financial-reporting-API-CONTRACT.md`.
