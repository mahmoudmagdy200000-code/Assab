# رد الباك اند على الفرونت — 2026-07-27: «مصدر الفروع غير المربوطة»

> **الخلاصة:** اتعمل **الخيارين**. عندك endpoint مخصّص للفروع غير المربوطة، **وكمان** الشجرة بقت تحمل `unlinkedBranches` + `branchCounts`. وشكل الربط اللي كتبته **صح** — `PATCH /api/v1/admin/branches/{id}` بـ `restaurantId`، ومفيش حقل تاني.

---

## 1. الخيار 1 — endpoint الفروع (المسار الرئيسي)

```
GET /api/v1/admin/brands/{brandId}/branches?linked=false
GET /api/v1/admin/brands/{brandId}/unlinked-branches      ← alias، نفس الرد بالحرف
```

`linked` بتقبل: `false` (المرشّحين للربط) · `true` (المربوطين تحت مطاعم البراند) · `all` (الافتراضي = الاتنين).

```json
{
  "data": [
    { "id": "br-uuid", "name": "فرع الخرج 1", "city": "الرياض",
      "restaurantId": null, "restaurantName": null, "brandId": "b-uuid", "companyId": "c-uuid",
      "manager": "…", "managerUserId": null, "address": "…", "phone": "…", "status": "active",
      "linkage": "brand" },
    { "id": "br-uuid-2", "name": "فرع قديم", "city": null,
      "restaurantId": null, "restaurantName": null, "brandId": null, "companyId": null,
      "linkage": "orphan" }
  ],
  "meta": {
    "brandId": "b-uuid",
    "linked": "false",
    "total": 2,
    "restaurants": [{ "id": "r-uuid", "name": "الخرج" }]
  }
}
```

**حقلين مهمين ليك:**

| الحقل | المعنى |
|---|---|
| `linkage` | `brand` = البراند متسجّل على الفرع بس المطعم ناقص · `orphan` = مفيش أي ربط (فرع من العالم القديم/الموبايل) · `linked` = مربوط بمطعم (بيظهر مع `linked=true/all` بس) |
| `meta.restaurants` | **نص التاني من المنتقي** — مطاعم البراند جاهزة للـdropdown، فمش محتاج نداء تاني |

**ليه في نوعين مرشّح؟** الفرع اللي `restaurant_id = null` نوعين: واحد عليه `asab_brand_id` (بيخصّ البراند ده قطعًا)، وواحد **مالوش أي عمود هيكل** — ده فرع قديم مش منسوب لأي براند، فبيتعرض على كل براند شركته مطابقة (أو الفروع اللي مالهاش شركة أصلًا). ده المقصود من الإصلاح: الربط هو اللي بينسبه. لو عايز تعرض الاتنين مفصولين في الـUI استخدم `linkage`.

**العزل:** فرع تابع لشركة تانية **مابيظهرش أبدًا** كمرشّح (فيه تست على ده). السقف 500 صف — دي قائمة منتقي مش تقرير.

---

## 2. الخيار 2 — الشجرة كمان بقت تحملها

`GET /api/v1/admin/brands` (نفس النداء بتاع الشجرة) على مستوى البراند:

```json
{
  "id": "b-uuid", "name": "حية عنب",
  "restaurants": [ … زي ما هي … ],
  "unlinkedBranches": [{ "id": "br-uuid", "name": "فرع الخرج 1", "city": "الرياض" }],
  "branchCounts": { "linked": 3, "unlinked": 1 }
}
```

> **فرق واحد مقصود:** الشجرة بترجّع الفروع اللي **البراند متسجّل عليها** بس (`linkage=brand`) — الفرع الـ`orphan` مالوش براند فمينفعش يتحط تحت براند بعينه في شجرة. عايز الـorphans كمان؟ استخدم الـendpoint بتاع الخيار 1. يعني: **badge من `branchCounts.unlinked`، والقائمة الكاملة للزرار من `?linked=false`**.

---

## 3. شكل الربط — تأكيد

اللي كتبته صح ومفيش حقل تاني:

```
PATCH /api/v1/admin/branches/{id}
{ "restaurantId": "<restaurantId>" }
```

- الباك اند **بيحلّ التلاتة من المطعم نفسه**: `asab_restaurant_id` + `asab_brand_id` + `asab_company_id`. متبعتش `brandId`/`companyId` — مش مقروءين، والمطعم هو مصدر الحقيقة عشان التلاتة مايختلفوش.
- الرد = الفرع بعد التحديث (نفس شكل عنصر `data` فوق) → حدّث الصف من الرد مباشرة.
- `restaurantId` غلط → `404 NOT_FOUND`. مش UUID → `422 VALIDATION_ERROR`.
- بعد الربط الفرع **بيختفي من `?linked=false`** وبيظهر في `?linked=true` وتحت مطعمه في الشجرة.
- الحقول التانية (`name`/`manager`/`managerUserId`/`city`/`address`/`phone`/`status`) لسه شغّالة في نفس النداء لو محتاجهم.

> **ملاحظة:** فك الربط (رجوع لـ null) **مش مدعوم** في الـPATCH — الحقول الفاضية بتتفلتر. لو الشاشة محتاجة «فك ربط» قوللي أعملها كـaction صريح.

---

## 4. خطوات الزرار

1. من كارت البراند: `branchCounts.unlinked > 0` → اعرض الزرار.
2. اضغط → `GET /api/v1/admin/brands/{brandId}/branches?linked=false` — الصفوف من `data`، ومطاعم الـdropdown من `meta.restaurants`.
3. لكل فرع: `PATCH /api/v1/admin/branches/{id}` بـ `{ restaurantId }`.
4. بعد الخلاص: أعِد نداء الشجرة (أو `?linked=false` لتحديث القائمة). ولو الربط كان عشان الكتالوج، أعِد الرفع أو اقرا `GET /admin/brands/{id}/upload-status` — `branchesLinked` هيبقى > 0.

**التستات:** `tests/Feature/BrandUnlinkedBranchesTest.php` (6) — المرشّحين بنوعيهم، الـalias، عزل شركة تانية، `linked=true`، حقول الشجرة، والـPATCH بيختم السلسلة والفرع بيخرج من القائمة.
