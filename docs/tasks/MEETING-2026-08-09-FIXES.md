# تعديلات 2026-08-09 — حساب المحاسب (مشتريات / شفتات / موظفين / عهد / تذكيرات)

> الحالة: ✅ مُنفَّذ · Tests: `tests/Feature/Meeting20260809FixesTest.php` (22 حالة)
> Base: `/api/v1` · السطحان مدعومان: `/api/v1/company/me/*` و `/api/v1/accountant/*`
> النقود: `*Halalas` عدد صحيح + نسخة `*Sar` عشرية بجانبها في كل الحقول الجديدة.

الطلبات السبعة من الاجتماع ومقابلها في الـ API:

| # | الطلب | الحالة |
|---|---|---|
| 1 | المشتريات عند المحاسب بنفس الصورة المرفقة | ✅ endpoint جديد `GET /purchases` |
| 2 | إدارة الشفتات — كتابة يدوية في الخانتين | ✅ `durationHours` صار رقماً حراً + وقت حر |
| 3 | كشف حساب الموظف — فلترة بالعلامة التجارية | ✅ `?brandId=` |
| 4 | كشف حساب الموظف — تحميل PDF | ✅ `?format=pdf` |
| 5 | العهد النقدية — فلترة بالفرع والعلامة التجارية | ✅ `?branchId=` `?brandId=` (+ `?status=`) |
| 6 | تعيين الشفتات عن طريق الفرع أيضاً | ✅ `PUT /branches/{branchId}/shift-config` |
| 7 | التذكير لا يعمل + زر «إرسال للكل» | ✅ محرّك تذكيرات كامل + `POST /reminders/send-all` |

---

## 1. موديول المشتريات — `GET /company/me/purchases`

الشاشة القديمة كانت قائمة عمليات مسطّحة. هذا الـ endpoint يرجّع **كروت** كما في الصورة:
كرت لكل مورد (أو لكل فرع) ← فواتيره ← بنود كل فاتورة.

```
GET /company/me/purchases
  ?groupBy=supplier|branch      # افتراضي supplier — زر «عرض حسب المورد / حسب الفرع»
  &brandId=&branchId=&supplierId=
  &status=pending,approved      # يقبل قائمة مفصولة بفواصل
  &match=diff,exact,review
  &documented=true|false
  &q=                           # بحث: فرع / مورد / منتج / رقم فاتورة
  &dateFrom=&dateTo=
  &page=&pageSize=              # الترقيم على الكروت
```

### الاستجابة

```jsonc
{
  "data": [
    {
      "key": "019f…",                 // supplierId أو branchId حسب groupBy
      "groupBy": "supplier",
      "supplierId": "019f…", "supplierName": "شركة الدواجن الوطنية",
      "branchId": null,   "branchName": null,
      "title": "شركة الدواجن الوطنية",
      "invoiceCount": 3,               // «3 فواتير»
      "counterpartCount": 3,           // «3 فروع» (أو «2 مورد» في وضع الفرع)
      "totalHalalas": 1706000, "totalSar": 17060.00,
      "diffCount": 1,                  // شارة «1 فرق»
      "pendingCount": 2,               // شارة «2 معلق»
      "documentedCount": 0,
      "dailyAverageHalalas": 88700,    // «يومياً 887 ر.س»
      "counterparts": [                // الشرائح تحت اسم الكرت
        { "id": "019f…", "name": "فرع الرياض - العليا", "invoiceCount": 1,
          "totalHalalas": 576000, "totalSar": 5760.00 }
      ],
      "branches": [ /* = counterparts عندما groupBy=supplier */ ],
      "suppliers": [ /* = counterparts عندما groupBy=branch */ ],
      "invoices": [
        {
          "id": "019f…", "publicId": "PUR-0042",
          "invoiceNumber": "INV-D001",
          "branchId": "019f…", "branchName": "فرع الرياض - العليا",
          "supplierId": "019f…", "supplierName": "شركة الدواجن الوطنية",
          "date": "2026-10-14",
          "status": "pending", "statusLabelAr": "معلق",
          "match": "diff", "matchLabelAr": "فروق في الكمية",
          "itemCount": 2, "itemsPreview": "دجاج طازج، صدر دجاج",
          "totalHalalas": 576000, "totalSar": 5760.00,
          "linesTotalHalalas": 279600, "vatHalalas": 296400,
          "lineCount": 2, "documentedLineCount": 0,
          "documentedCaption": "0/2 موثّق",
          "attachmentsCount": 2,
          "attachments": [ { "id": "…", "filename": "…", "publicUrl": "…" } ],
          "lines": [
            {
              "rowId": "r1", "item": "دجاج طازج", "unit": "كجم",
              "unitPriceHalalas": 3200, "unitPriceSar": 32.00,
              "lastArrivalPriceHalalas": 3000, "lastArrivalPriceSar": 30.00,
              "priceDeltaHalalas": 200, "priceDeltaSar": 2.00,
              "priceDirection": "up",          // up | down | same | unknown
              "ordQty": 48, "rcvQty": null, "qty": 48,
              "totalHalalas": 153600, "totalSar": 1536.00,
              "documented": false, "documentedAt": null,
              "lineMatch": { "key": "pending", "labelAr": "بانتظار الاستلام" }
            }
          ]
        }
      ],
      "invoicesTruncated": false
    }
  ],
  "meta": {
    "groupBy": "supplier", "page": 1, "pageSize": 20, "total": 4, "totalPages": 1,
    "scannedOperations": 12, "truncated": false, "maxOperations": 1000,
    "kpis": {
      "totalPurchasesHalalas": 3570000, "totalPurchasesSar": 35700.00,  // «إجمالي قيمة المشتريات»
      "pendingInvoices": 5,        // «فواتير معلقة»
      "diffInvoices": 3,           // «فواتير بفروق»
      "activeSuppliers": 4,        // «موردون نشطون»
      "invoices": 12,
      "period": { "from": null, "to": null }
    }
  }
}
```

**«آخر سعر وصول»** = سعر نفس الصنف من نفس المورد في **الفاتورة السابقة**
(مشتق وقت القراءة، غير مخزّن). `null` = أول وصول للصنف ⇒ أخفِ شارة الفرق.

### الأفعال على الشاشة

| الزر | الطلب |
|---|---|
| «توثيق ✓» على سطر | `PATCH /company/me/operations/{id}/purchase-lines/{rowId}` body `{ "documented": true }` |
| «تعديل البنود» | نفس الـ PATCH مع `ordQty` / `rcvQty` / `unitPriceHalalas` أو `unitPriceSar` |
| «موافقة جماعية (5)» | `POST /company/me/purchases/bulk-document` body `{ "operationIds": ["…"] }` |
| «تصدير Excel» | `GET /company/me/purchases/export?format=xlsx|csv` (نفس الفلاتر) |

الـ PATCH يرجّع `{ rowId, row, documented, match, amount }`.

> حدود مُعلَنة: التجميع يمسح حتى **1000** عملية (`meta.truncated`)، وكل كرت يرجّع حتى
> **50** فاتورة (`invoicesTruncated`). ضيّق بالتاريخ عند الحاجة.

---

## 2. + 6. إدارة الشفتات

### كتابة يدوية

`durationHours` كان `integer|min:1|max:24` ⇒ أي قيمة خارج الدروب ليست صالحة.
الآن:

| الحقل | القاعدة الجديدة |
|---|---|
| `durationHours` | `numeric` — **7.5 مقبولة** وتُخزَّن 450 دقيقة |
| `durationMinutes` | بديل صريح بالدقائق (`15..1440`) |
| `firstShiftStart` | نص حر: `06:00` / `6:00` / `6:00 AM` / `٦:٠٠` — يُطبَّع إلى `HH:MM` (لكن `25:99` = 422) |

الاستجابة أضافت `durationMinutes` و`coverageMinutes`، و`durationHours` بقيت (مقرّبة) لكل قارئ قديم.

### تعديل شفت واحد بمفرده (زر ✏️)

```jsonc
PUT /company/me/brands/{brandId}/shift-config
{ "shiftOverrides": [ { "no": 2, "start": "15:00", "durationHours": 6 } ] }
```

- الجسم **جزئي**: ما لم تُرسله يبقى كما هو (قبلها كان `numShifts` يُصفَّر إلى 1).
- كل نافذة في `shifts[]` صارت تحمل `durationMinutes` و`overridden: true|false`.
- إرسال `{ "no": 2 }` بدون قيم يمسح تخصيص الشفت الثاني فيعود للجدول العام.

### تعيين الشفتات على مستوى الفرع

```
GET    /company/me/shifts/configs?scope=brand|branch|all[&brandId=]
PUT    /company/me/branches/{branchId}/shift-config      # نفس جسم البراند
DELETE /company/me/branches/{branchId}/shift-config      # يرجع الفرع لجدول علامته
POST   /company/me/branches/{branchId}/shift-config/regenerate
```

- كل صف يحمل الآن `scope` (`brand|branch`)، `ownerId/ownerName`، و`hasOwnConfig`.
- `scope=branch` يرجّع صفاً لكل فرع؛ الفرع بلا تخصيص يرث جدول علامته و`hasOwnConfig=false`.
- **الأولوية**: تخصيص الفرع يغلب جدول البراند — وحفظ جدول البراند لا يسحب الفرع المخصَّص إليه.
- الحفظ يزرع قوالب `shifts` في تطبيق الموبايل مباشرة (`mobileShiftsSeeded`)، وكشف التأخير
  (`late`) صار يقيس على جدول الفرع إن وُجد.
- `GET /company/me/branch/settings` (مدير الفرع) يعرض الجدول الفعلي + `shiftConfig.scope`.

---

## 3. + 4. كشف حساب الموظف

```
GET /company/me/employees?brandId=&branchId=&q=&empNumber=
GET /company/me/employees/{id}/statement/export?month=YYYY-MM&format=pdf|xlsx|csv
GET /company/me/employees/payroll/export?month=YYYY-MM&brandId=
```

- `brandId` يحلّ فروع العلامة **عبر المطعم أيضاً** — الفرع المرتبط بمطعم فقط
  (`asab_brand_id = NULL`) كان يختفي من كل فلترة بالعلامة.
- `format=pdf` يرجّع PDF عربي RTL جاهز للطباعة (`Content-Type: application/pdf`)
  فيه الحركات + الرصيد الافتتاحي/الختامي وإجمالي المدين والدائن.

---

## 5. العهد النقدية

```
GET /company/me/cash-custody?brandId=&branchId=&status=normal|low|critical&q=
GET /company/me/cash-custody/export?brandId=&branchId=&status=&format=xlsx|csv
```

`status` **مشتقّ من الرصيد الحيّ** لا من عمود `status` (الصفوف القديمة مخزَّن فيها `active`،
والفلترة على العمود كانت ستُسقطها كلها). قيمة غير معروفة ⇒ 422.

---

## 7. التذكيرات

### لماذا لم تكن تعمل

ثلاث فجوات، كلها مغلقة الآن:

1. **لا شيء كان يُنشئ التذكيرات.** جدول `asab_reminders` كان يُقرأ فقط ⇒ القائمة فارغة
   دائماً وكل المؤشرات صفر.
2. **قواعد التذكير التلقائي لم يكن لها مستهلك.** المفاتيح كانت تكتب صفوفاً لا يقرؤها أحد.
3. **«إرسال» كان يقلب الحالة فقط** دون إشعار أي أحد.

### الجديد

```
GET    /company/me/reminders?moduleKey=&brandId=&branchId=&status=&q=
POST   /company/me/reminders/scan          # «تحديث» — يعيد بناء قائمة اليوم
POST   /company/me/reminders/send-all      # «إرسال تذكير للكل»
POST   /company/me/reminders/bulk-send     # نفس المعالج (ids اختيارية)
POST   /company/me/reminders/{id}/send
POST   /company/me/reminders/{id}/respond
GET    /company/me/reminders/rules         # مزروعة تلقائياً أول قراءة
POST   /company/me/reminders/rules
PATCH  /company/me/reminders/rules/{id}
DELETE /company/me/reminders/rules/{id}
POST   /company/me/reminders/rules/{id}/toggle
GET    /company/me/reminders/export
```

> ⚠️ `‎/company/me/accountant/reminders` شيء **آخر** — قائمة تذكيرات المحاسب الشخصية.
> شاشة «بيانات الفروع المفقودة» تقرأ `‎/company/me/reminders` (بدون `accountant/`).
> هذا كان سبب ظهور 0/0/0/0: الواجهة كانت تقرأ القائمة الشخصية.

`GET /reminders` يرجّع:

```jsonc
{
  "data": [ {
    "id": "…", "publicId": "REM-0001",
    "branchId": "…", "branchName": "فرع الرياض - العليا",
    "moduleKey": "sales", "moduleLabelAr": "المبيعات",
    "message": "لم يتم رفع بيانات المبيعات لفرع … ليوم 2026-08-09",
    "daysMissing": 2, "urgency": "medium",
    "reminderStatus": "not_sent",           // not_sent | sent | responded
    "requiredBy": "2026-08-09T22:00:00+03:00", "sentAt": null, "respondedAt": null
  } ],
  "meta": {
    "summary": { "notSent": 11, "sent": 0, "responded": 1, "totalMissing": 11, "total": 12 },
    "modules": [ { "key": "sales", "labelAr": "المبيعات" }, … ],
    "capped": false
  }
}
```

- **`totalMissing` = ما زال ناقصاً فعلاً** (`notSent + sent`)؛ `total` يشمل ما تم الرد عليه.
- زر «إرسال تذكير للكل» ⇒ `POST /reminders/send-all` ويقبل تضييقاً اختيارياً:
  `{ "moduleKey": "sales", "brandId": "…", "branchId": "…", "onlyUnsent": false }`
  ويرجّع `{ "sent": n, "skipped": m }`.
- الإرسال يدفع إشعاراً حقيقياً لمديري الفرع (`reminder.missing-data`) + حدث Pusher
  `reminder.sent` على غرفة `reminders.branch.{branchId}`.

### المحرّك (جدولة تلقائية)

| الأمر | الجدولة | الوظيفة |
|---|---|---|
| `asab:reminders-generate` | كل ساعة (Asia/Riyadh) | يرفع تذكيراً لكل (فرع × موديول مطلوب) بلا بيانات، **ويغلق** ما وصلت بياناته |
| `asab:reminders-dispatch` | كل ساعة (د:05) | يرسل ما استحق حسب `trigger_hour` ثم يكرّر كل `repeat_hours` حتى يوجد رد |

- الموديولات المطلوبة يومياً: `sales`، `inventory`، `waste`، `expenses`.
- القواعد الافتراضية تُزرع لكل شركة أول قراءة: 22:00/2س، 20:00/3س، 21:00/4س، 23:00/2س.
- التوليد **idempotent** — إعادة التشغيل لنفس اليوم تُحدِّث ولا تكرّر.
- التكرار يستمر بعد منتصف الليل: بيانات أمس الناقصة ما زالت ناقصة الساعة 1 فجراً.

---

## إصلاحات جانبية خرجت من نفس الشغل

1. **فلترة العلامة التجارية كانت ترجع فارغاً** في كل الشاشات التي تعتمد
   `branches.asab_brand_id` وحده (الفرع المربوط بمطعم فقط). صار هناك `BrandBranchResolver`
   واحد تستخدمه المشتريات والموظفون والعهد والعمليات والتذكيرات.
2. **كمية الاستلام لم تكن تُطابق أبداً** في المطابقة الثلاثية: الجسر كان يفهرس
   `quantity_received` بـ `item_id` بينما `rowId` في الـ payload هو معرّف سطر أمر الشراء
   ⇒ كل سطر كان يظهر «بانتظار الاستلام». صار الفهرس بالمفتاحين.
3. **حفظ جزئي لجدول الشفتات** كان يُصفّر `numShifts` إلى 1.
4. **قواعد التذكير** كانت قابلة للتعديل عبر الشركات (لا فحص ملكية) — الآن مقيّدة بالشركة.
