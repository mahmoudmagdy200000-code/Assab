# رفع موظفي الفروع — لكل فرع (2026-08-04)

**الملاحظة:** في شاشة رفع بيانات البراند، الفروع الجديدة لها «الأصول الثابتة» فقط.
«موظفو المطاعم» كان لكل **مطعم** لا لكل **فرع**، فالفرع الجديد لم يكن له مكان يرفع
منه موظفيه.

**ما تم:** نفس ملف الموظفين صار له مدخل لكل فرع، بنفس شكل «الأصول الثابتة».

---

## المسارات الجديدة

```
POST /api/v1/admin/branches/{branchId}/upload/employees     (multipart: file)
POST /api/v1/admin/branches/{branchId}/uploads/employees    (اسم بديل بالجمع)
```

القالب هو نفسه القائم — لا حاجة لقالب جديد:

```
GET /api/v1/admin/upload/templates/employees?format=xlsx|csv
```

| اسم الموظف | الوظيفة | اسم الفرع | رقم الجوال | رقم الهوية | الراتب الشهري (ر.س) | نوع الوردية | تاريخ التعيين |
| --- | --- | --- | --- | --- | --- | --- | --- |

القواعد في الرفع لكل فرع:

- كل صف يهبط على **هذا الفرع**؛ عمود «اسم الفرع» اختياري ويجوز تركه فارغًا.
- إذا ذكر الصف اسم فرع **مختلف** → خطأ في الصف («هذا الملف يخص فرع «س» — الصف يذكر «ص»») ولا يُحفظ في مكان خاطئ.
- صف بوظيفة **كاشير** مرفوض كما هو الحال في الرفع لكل مطعم (حسابات الكاشير تُنشأ من تطبيق مدير الفرع).
- الراتب في الملف بالريال ويُخزَّن بالهللات.
- إن لم يُحفظ أي صف → 422 بـ `UPLOAD_FAILED` مع أسباب الصفوف (بدل 200 و«تم الرفع» كاذب).
- فرع غير مرتبط بمطعم/شركة → 422 `BRANCH_NOT_LINKED`.

الرد:

```jsonc
{ "employeeCount": 12, "errors": [{ "row": 4, "message": "اسم الموظف والوظيفة مطلوبان" }] }
```

## حالة الرفع لكل فرع

`GET /api/v1/admin/branches/{branchId}/upload-status` صار يحمل عمود الموظفين
بنفس شكل عمود الأصول:

```jsonc
{
  "branchId": "…",
  "fixedAssets": true,  "fixedAssetsStatus": "done", "fixedAssetsCount": 40,
  "employees": false,   "employeesStatus": "not_uploaded",
  "employeesCount": 0,  "employeesFailedRows": 0,
  "employeesFailureReason": null, "employeesUploadedAt": null,

  "completionPct": 100,        // ← كما هو: الأصول الثابتة فقط (لم يتغيّر معناه)
  "overallCompletionPct": 50   // ← جديد: الأصول + الموظفين
}
```

وللشاشة كاملة في نداء واحد:

`GET /api/v1/admin/brands/{brandId}/branches/upload-status`

```jsonc
{
  "branches": [
    { "branchId": "…", "branchName": "برجر بيت — فرع العليا",
      "fixedAssets": true, "employees": true, "employeesCount": 12, "…": "…" }
  ],
  "totals": {
    "branches": 5, "uploaded": 5, "failed": 0,
    "employeesUploaded": 3, "employeesFailed": 0   // ← جديد
  }
}
```

**للفرونت:** أضف جدول «موظفو الفروع» تحت جدول «الأصول الثابتة» بنفس المكوّن —
الأعمدة `employees*` تطابق `fixedAssets*` حرفًا بحرف، وزر «رفع» ينادي
`POST /admin/branches/{branchId}/upload/employees` وزر «نموذج» ينادي قالب
`employees` نفسه.

> الرفع لكل مطعم (`POST /admin/restaurants/{restaurantId}/upload/employees`) باقٍ
> كما هو — من يرفع كشفًا واحدًا لعدة فروع يستخدمه، ومن يبني فرعًا فرعًا يستخدم
> المسار الجديد. الاثنان يكتبان في نفس `asab_employees`.

## الاختبارات

`tests/Feature/BranchEmployeesUploadTest.php` — 5 اختبارات: الرفع لفرع، رفض صف
يذكر فرعًا آخر، عمود الحالة لكل فرع، عمود الحالة على مستوى البراند، ورفض الكاشير.
Regression: 54 اختبارًا في سويتات الرفع القائمة — خضراء.
