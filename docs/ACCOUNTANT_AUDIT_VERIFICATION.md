# تقرير التحقق النهائي — لوحة تحكم المحاسب (ASAB)

> **إطار حاسم قبل القراءة:** الأوديت الأصلي يصف **واجهة أمامية React** (`ASABPrototype.tsx` + `platform/accountant.ts`) وهي **غير موجودة على هذا الجهاز** — المتاح فقط هو **باك-إند Laravel**. لذلك هذا التحقق يؤكّد أو ينفي **نصف العقد الخاص بالباك فقط** (وجود الـ endpoints + أشكال الاستجابة + أسئلة B-A الخمسة). كل ادعاءات **سلوك الفرونت** (أزرار ميتة، كتابة محلية فقط، `setTimeout` وهمي، حُرّاس ضد الانهيار، "hook غير مُستدعى"، بذور ثابتة) تبقى **غير قابلة للتحقق** من هذا الريبو، وهي مُدرجة صراحةً كذلك ولم يُدّعَ فحصها.

---

## 1. الخلاصة

- **الادعاء المحوري (type-mismatch / B-A1) صحيح على مستوى الباك:** `GET /accountant/operations` يُعيد مغلّفًا `{ data:[...], meta }` حيث كل عنصر عملية **9 حقول scalar فقط** (`id, publicId, branchId, moduleKey, amount, match, status, origin, operationDate`) — **لا** يوجد أي `.invoices/.reconciliation/.purchases/.records/.branches/.employees/.dayOptions` لا على العملية ولا على الـ envelope (`AccountantController.php:330-343`). فجوة الشكل التي يصفها الأوديت **حقيقية**، لكن استنتاج "الانهيار / الـ fallback الثابت" نفسه **سلوك فرونت غير قابل للتحقق**.
- **معظم "الأزرار الميتة" لها endpoints حيّة فعّالة:** approve/reject/bulk-approve، salesLineUpdate، addNote، employeeLookup، salesVarianceAssign، وكل mutations الأصول والمخزون والهدر — **كلها موجودة وتعمل**. لذا أي "موت" هو **مشكلة ربط في الفرونت وليس endpoint ناقصًا**.
- **B-A3 (transfer custody) صحيح جوهريًا:** لا يوجد endpoint مخصّص لنقل عهدة/فرع الأصل؛ المسار الوحيد باسم transfer هو `branches/{id}/transfer-manager` (لمدير الفرع). القدرة موجودة **بشكل غير مباشر** عبر `PATCH company/me/assets/{id}` فقط.
- **B-A2 دقيق جزئيًا:** لا لوحة المحاسب (platform) ولا لوحة الشركة تحتوي `completedBranches/totalBranches` — فجوة "4/8 static" حقيقية؛ لكن `approvalRate` **موجود** على لوحة الـ platform، واللوحتان تقدّمان KPIs أغنى مما افترض الأوديت. ملاحظة: `approvalRatePct` في لوحة الشركة **مثبّت على 100** (`AccountantCompanyController.php:42`).
- **الأوديت أخطأ في عدة نقاط باك-إند** ستُصحَّح في القسم 4 (أبرزها: **صفحة التقارير ليست static — يوجد catalog + download حقيقي**؛ الحقول اسمها `transactions` وليس `txns`، `branches` وليس `byBranch`، `supervisor` وليس `name`، `empAllocs` وليس `empId`).
- **ثغرتان في الباك لم يلتقطهما الأوديت:** (1) `AutoReminderRule` لا يستخدم `BelongsToTenant` و`rules()` بلا فلتر `company_id` → **تسريب قواعد عبر كل الشركات**؛ (2) `GET accountant/assets` **غير مُصفّح وغير محدود** (خرق guardrail الـ unbounded collection).

---

## 2. إجابات أسئلة الباك (B-A1 .. B-A5)

### B-A1 — هل `/operations` مصفوفة أم كائن، وهل تُخدَم الأشكال الغنية؟
**الإجابة: مصفوفة داخل مغلّف — والأشكال الغنية غير مُخدَّمة إطلاقًا.**
`AccountantController@operations` يُعيد `paginated()` → `{ data:[...], meta:{...,summary} }`؛ كل عنصر من `present()` يحمل **9 حقول scalar فقط** بلا أي sub-collections (`AccountantController.php:75-100, 330-343`؛ `AsabResponse.php:42-53`). قراءة sales-detail الغنية (`reconEmployees/dayOptions/reconciliation`) **غير موجودة كـ GET**؛ التسوية **write-only عبر PATCH** فقط (`AccountantController.php:133-184`؛ `api.php:337,599,602`)، وقراءة العملية المفردة الوحيدة هي `OperationController@show` (`OperationController.php:43-59`) وتُعيد `present()` + payload خام + auditTrail، لا read-model منظّمًا. grep لـ `reconEmployees`/`dayOptions` = **صفر نتائج**. → **مؤكَّد على الباك؛ استنتاج انهيار الفرونت غير قابل للتحقق.**

### B-A2 — شكل لوحتَي التحكم، وتحديدًا `completedBranches/totalBranches`؟
**الإجابة: كلا اللوحتين لا تحتويان هذين الحقلين إطلاقًا.**
- Platform: `AccountantController@dashboard` (`:52-73`) يُعيد `{ kpis:{awaitingReview,iApproved,finalApproved,approvalRate,overdueCount}, modules:[...], recentOperations:[...] }` — `approvalRate` **موجود** (`:66`)، لكن `completedBranches/totalBranches` **غائبان**.
- Company: `AccountantCompanyController@dashboard` (`:27-49`) يُعيد `{ today, counts:{...,approvalRatePct}, pendingByModule, needsAttention, rejectedReuploadNeededCount }` — لا `completedBranches/totalBranches`، و`approvalRatePct` **مثبّت 100** (`:42`).
→ فجوة "4/8 static" **حقيقية** لهذين الحقلين على السطحين معًا؛ ادعاء "اللوحة تقدّم `approvalRate` فقط" **غير دقيق** (تقدّم KPIs أكثر).

### B-A3 — هل يوجد endpoint لنقل العهدة (transfer custody)؟
**الإجابة: لا endpoint مخصّص — القدرة متاحة فقط بشكل غير مباشر.**
المسار الوحيد بكلمة transfer هو `POST company/me/branches/{id}/transfer-manager` (لنقل **مدير** فرع، لا علاقة له بالأصول). نقل العهدة/الفرع للأصل ممكن فقط عبر `PATCH company/me/assets/{id}` → `updateAsset` الذي يقبل `custodian` و`branchId` كحقول `sometimes` (`api.php:642`؛ `AccountantCompanyController.php:172-201`). → **B-A3 صحيح: لا endpoint مخصّص**؛ لو ادّعى الأوديت غياب القدرة كليًا فذلك **دقيق جزئيًا فقط**.

### B-A4 — نطاق `/reminders` وقواعد التذكير؟
**الإجابة: `/reminders` مُنطّق على مستوى الشركة (tenant) وليس لكل محاسب — والقواعد بها تسريب tenant.**
`GET /reminders` → `AccountantController@reminders` (`api.php:385`, middleware `asab.role:accountant,head`)؛ موديل `Reminder` يستخدم `BelongsToTenant` (`Reminder.php:11`) فيُطبَّق global scope على `company_id` (`BelongsToTenant.php:15-32`) — إذن **مُنطّق للشركة تلقائيًا لكن ليس لكل `user_id`** (كل محاسب/head يرى كل تذكيرات الشركة). هذا سطح **مختلف** عن `/company/me/accountant/reminders` (`PersonalReminderController`، شخصي لكل مستخدم). القواعد: كل endpoints قواعد التذكير موجودة وفعّالة (`ReminderController.php:165-232`) **لكن** `AutoReminderRule` **لا** يستخدم `BelongsToTenant` و`rules()` بلا فلتر `company_id` → **`GET reminders/rules` يسرّب قواعد كل الشركات** (`AutoReminderRule.php:8-16`). → **B-A4: النطاق دقيق جزئيًا (tenant لا per-accountant)؛ + ثغرة تسريب حقيقية في القواعد.**

### B-A5 — انقسام المسارات: قراءات على `/accountant/*` وتصدير على `/company/me/*`؟
**الإجابة: صحيح حرفيًا — مع سطحين شبه مكرّرين.**
- Surface 1 `/api/v1/accountant/*` (`api.php:333-381`, role `accountant,head`) = قراءات/كتابات فقط، **صفر** مسار export.
- Surface 2 `/api/v1/company/me/*` (`api.php:571-660`, role `accountant`, tenant-scoped) = مجموعة موازية من القراءات/الكتابات **+ كل التصديرات**: reminders/export (589), operations/export (595), inventory (616), waste (630), assets (638), shifts (645), employees/payroll (650), cash-custody (654).
الانقسام **حقيقي وغير موثّق كنمط متعمّد** (تعليق الكتلة `566-570` يذكر فقط إعادة استخدام controllers). → **B-A5 مؤكَّد كواقع، دقيق جزئيًا لأن الصورة الأشمل = سطحان متوازيان.**

---

## 3. جدول التحقق

> مُرتّب بالمفاجآت/REFUTED/PARTIAL أولًا داخل كل مجال.

### reminders-reports
| Claim | Audit verdict | Backend reality | Verdict | Evidence |
|---|---|---|---|---|
| صفحة التقارير 100% static وأزرار view/download ميتة | dead/static | catalog حقيقي (7 واصفات) + download حقيقي (pdf/xlsx/json blob) | **REFUTED** | `ReportController.php:94-132`; `Company/CrossController.php:112-127`; `api.php:744-745` |
| لا يوجد `GET reports/{key}/download` عامل | missing | موجود وفعّال، يبثّ binary عبر ExportService | **REFUTED** | `api.php:744,747`; `CrossController.php:112-127` |
| `/reminders` غير مُنطّق للمحاسب | not-scoped | مُنطّق للشركة عبر `BelongsToTenant` (لكن ليس per-user) | **PARTIAL** | `AccountantController.php:102-131`; `Reminder.php:11`; `BelongsToTenant.php:15-32` |
| قواعد التذكير static بلا hook | static | كل endpoints القواعد موجودة؛ **+ثغرة**: `rules()` تسرّب عبر الـ tenants | **CONFIRMED (+ثغرة باك)** | `ReminderController.php:165-232`; `AutoReminderRule.php:8-16` |
| send/bulk-send/respond/create لا تُستدعى | dead hooks | كل الـ endpoints موجودة؛ لا endpoint "create single" مشترك (broadcast هو الأقرب) | **UNVERIFIABLE-FRONTEND** | `ReminderController.php:22-83,120-163` |
| ازدواج شرط `target!=="all" && target!=="all"` | FE bug | منطق الباك للـ target سليم | **UNVERIFIABLE-FRONTEND** | `ReminderController.php:41-50` |
| سطحا التذكير (فريقي vs شخصي) | ملتبس | سطحان متمايزان مؤكَّدان | **CONFIRMED** | `PersonalReminderController.php:16-27`; `api.php:384-398` |

### shifts-employees-cash
| Claim | Audit verdict | Backend reality | Verdict | Evidence |
|---|---|---|---|---|
| كل shift له حقل `name` (حارس الاسم) | expects `name` | **لا حقل `name`** — الاسم هو `supervisor` | **REFUTED** | `ShiftController.php:88-103` |
| صف الموظف في الـ index يحمل `movements[]` inline | inline movements | الـ index **لا** يحمل movements؛ موجودة فقط في statement | **REFUTED** | `EmployeeController.php:16-34,36-58` |
| صفوف cash-custody بلا amount/used و`b.txns.map` ينهار | NaN/crash | amount/used **موجودان**؛ لكن المفتاح `transactions` وليس `txns` → `b.txns` undefined | **PARTIAL** | `CashCustodyController.php:18-52` |
| `shifts/live` بشكله المتوقّع | works | موجود، bare `{active:[],overdue:[]}`، بلا `name` | **PARTIAL** | `ShiftController.php:27-37,88-103` |
| `shifts/history` | works | موجود، `{data,meta}` مصفّح | **CONFIRMED** | `ShiftController.php:39-51` |
| `shifts/{id}/close` بـ cash/sales/variance + setTimeout وهمي | fake close | endpoint حقيقي؛ variance محسوب سيرفر-سايد | **UNVERIFIABLE-FRONTEND** | `ShiftController.php:53-86` |
| `employees/{id}/statement` (balance/movements) | not called | موجود بالشكل المذكور | **UNVERIFIABLE-FRONTEND** | `EmployeeController.php:36-58` |
| `employees/{id}/movements` (addMovement) | should exist | موجود، 201 `{id}` | **CONFIRMED** | `EmployeeController.php:60-81` |
| `cash-custody/{id}/settlement-request` | not called | موجود، 201 `{id,status}` | **UNVERIFIABLE-FRONTEND** | `CashCustodyController.php:54-67` |
| `cash-custody/{id}/transactions` (add) | not called | موجود، DB::transaction | **UNVERIFIABLE-FRONTEND** | `CashCustodyController.php:69-100` |
| نظائر `/company/me/*` للجميع | exist | مؤكَّدة (بأسماء حقول مختلفة في بعض المسارات) | **CONFIRMED** | `api.php:646-659`; `AccountantCompanyController.php:363-414` |

### assets
| Claim | Audit verdict | Backend reality | Verdict | Evidence |
|---|---|---|---|---|
| **B-A3**: لا endpoint لنقل العهدة | MISSING | لا مخصّص؛ ممكن غير مباشر عبر `PATCH .../assets/{id}` | **PARTIAL** | `api.php:642`; `AccountantCompanyController.php:172-201` |
| `GET accountant/assets` يقود قائمة الأصول | works | موجود لكن **غير مصفّح/غير محدود** (خرق guardrail)؛ bare `{data,summary,drafts}` | **CONFIRMED (+خرق)** | `AssetController.php:17-40` |
| `updateAsset` يحرّر الأصل | exists | موجود؛ لكن الرد **يحذف** category/custodian/cost/publicId، و`array_filter` يمنع تصفير الحقول بـ null | **CONFIRMED (بتحفظ)** | `AccountantCompanyController.php:172-201` |
| convertExpenseToAsset ينشئ **draft** | draft flow | مؤكَّد: ينشئ `AssetDraft` (`DRAFT-...`, status=draft) | **CONFIRMED** | `AccountantController.php:273-311` |
| useCreate/Confirm/Drafts/ConfirmDraft/DeleteDraft/Import "exist but not called" | dead hooks | كل الـ endpoints حيّة وفعّالة | **UNVERIFIABLE-FRONTEND** | `api.php:340-345,612-614,641`; `AssetController.php`, `AccountantCompanyController.php:203-286` |

### core-operations-dashboard-sales
| Claim | Audit verdict | Backend reality | Verdict | Evidence |
|---|---|---|---|---|
| **B-A1**: hook يُعيد `Operation[]` بينما الصفحات تقرأ props كائن | linchpin | العملية 9 حقول scalar فقط، بلا sub-collections | **PARTIAL (باك مؤكَّد)** | `AccountantController.php:330-343`; `AsabResponse.php:42-53` |
| لا GET يخدم read-shape التسوية الغنية | write-only | مؤكَّد؛ التسوية PATCH فقط، لا `reconEmployees/dayOptions` | **CONFIRMED** | `AccountantController.php:133-184`; grep=0 |
| **B-A2**: لوحة platform `completedBranches/totalBranches` فقط | premise | غائبان؛ `approvalRate` موجود؛ KPIs أغنى | **PARTIAL** | `AccountantController.php:52-73` |
| لوحة company بها `completedBranches/totalBranches` | — | غائبان؛ `approvalRatePct`=100 ثابت | **CONFIRMED (غائبان)** | `AccountantCompanyController.php:27-49` |
| expenses بلا invoices/dayOptions/attachLabels backend | no rich source | مؤكَّد؛ فقط operations(module=expenses) + verify/attachments/convert | **CONFIRMED** | `AccountantCompanyController.php:105-138`; grep=0 |
| approve/reject/bulk-approve تعمل | work | مؤكَّدة، role-gated | **CONFIRMED** | `OperationController.php:61-115`; `api.php:242,245,246` |
| salesLineUpdate/addNote/employeeLookup/salesVarianceAssign | dead buttons | كل الـ endpoints موجودة وفعّالة | **CONFIRMED (backend)** | `AccountantController.php:190-271`; `AccountantCompanyController.php:68-103` |
| fallbacks الثابتة تُعرض دائمًا لأن الـ props undefined | consequence | شرط الباك المسبق موجود؛ العرض نفسه فرونت | **UNVERIFIABLE-FRONTEND** | `AccountantController.php:91,330-343` |

### inventory
| Claim | Audit verdict | Backend reality | Verdict | Evidence |
|---|---|---|---|---|
| catalog يُرجع `brands` | expects `.brands` | يُرجع `categories` + `items[{id,name,cat,unit}]`، **لا `brands`** | **PARTIAL** | `InventoryController.php:111-128` |
| الصفحة تقرأ `.branches/.byBranch` من hook خاطئ → `{}` | wrong hook | endpoint مخصّص يخدم per-branch؛ المفتاح `branches` (لا `byBranch`) | **UNVERIFIABLE-FRONTEND** | `InventoryController.php:22-57` |
| أرقام التسوية (12400 / DEFICIT 360) ملفّقة في الفرونت | FE fabrication | daily-reconciliation يُعيد أرقامًا **محسوبة حقيقية**؛ لا ثوابت 12400/360 في الباك | **UNVERIFIABLE-FRONTEND (باك يخدم الحقيقي)** | `InventoryController.php:231-262`; `InventoryReconciliationService.php:27-143` |
| flagBranch/flagItems/sendConfirmation/storeCatalog/saveDailyList لا تُستدعى | dead hooks | كل الـ endpoints موجودة وفعّالة (سطحان) | **UNVERIFIABLE-FRONTEND** | `InventoryController.php:59-229`; `api.php:348-357,617-628` |

### waste
| Claim | Audit verdict | Backend reality | Verdict | Evidence |
|---|---|---|---|---|
| endpoint allocations بحمولة `empId` | empId payload | يتحقّق من `empAllocs` (array) ويخزّنه opaque — الاسم `empAllocs` لا `empId` | **PARTIAL** | `WasteController.php:70-84` |
| `GET accountant/waste` يعمل وكل entry به `products[]` | works | مؤكَّد؛ bare `{data:[],summary}`، `products` دائمًا مصفوفة (`?? []`) | **CONFIRMED** | `WasteController.php:19-47`; `Operation.php:38-39` |
| `WASTE_EMP` خريطة موظفين static فرونت فقط | FE static | مؤكَّد؛ الباك لا يخدم أي reference data للموظفين | **CONFIRMED** | `WasteController.php:31-45,70-84` |
| approve/reject/bulk-approve/classifyProduct "not called" | dead hooks | كل الـ endpoints موجودة وفعّالة | **UNVERIFIABLE-FRONTEND** | `WasteController.php:49-116`; `OperationService.php` |

### cross-exports-pathconsistency
| Claim | Audit verdict | Backend reality | Verdict | Evidence |
|---|---|---|---|---|
| **B-A5**: قراءات `/accountant/*` وتصدير `/company/me/*` | inconsistency | صحيح حرفيًا؛ سطحان شبه مكرّرين؛ الانقسام غير موثّق كنمط | **PARTIAL** | `api.php:333-381,587-660`; block comment 566-570 |
| `suppliers/export` accountant-gated | accountant-only | موجود لكن gate أوسع: `company-admin,head,accountant,branch,procurement` | **PARTIAL** | `api.php:712-713`; `ExportController.php:99-103` |
| template `admin/upload/templates/fixed-assets` | works | موجود ويُرجع xlsx/csv حقيقي، لكن gated `asab.role:admin` لا accountant | **PARTIAL** | `api.php:215,221,111`; `UploadController.php:25-31,172-200` |
| operations/employees-payroll/assets/waste/cash-custody/inventory/shifts/reminders export | work | كلها موجودة، BinaryFileResponse (OpenSpout xlsx/csv) | **CONFIRMED** | `api.php:589,595,616,630,638,645,650,654`; `ExportController.php:27-129` |
| التصديرات blob لا JSON envelope | works | مؤكَّد؛ `response()->download()->deleteFileAfterSend` | **CONFIRMED** | `ExportService.php:35-55` |

---

## 4. تصحيحات للأوديت (حيث أخطأ/شوّش بشأن الباك)

1. **التقارير ليست "static / أزرار ميتة" على مستوى الباك (REFUTED):** يوجد `GET /company/me/reports` يُرجع **7** واصفات تقارير، و`GET /company/me/reports/{key}/download` يبثّ **blob حقيقي** (pdf/xlsx) أو json inline (`ReportController.php:94-132`؛ `CrossController.php:112-127`؛ `api.php:744-745`). الأوديت قال "6 عناوين مثبّتة" — العدد **7** والقائمة ديناميكية من الباك.
2. **حقل `name` للـ shift غير موجود (REFUTED):** الاسم البشري هو `supervisor` (من `supervisor_name`)؛ أي قراءة `shift.name` ستكون `undefined` دائمًا (`ShiftController.php:88-103`).
3. **صف الموظف في الـ index لا يحمل `movements[]` inline (REFUTED):** الحركات موجودة **فقط** على `employees/{id}/statement`؛ الـ index = `{id,empNumber,name,role,monthlySalary,status}` (`EmployeeController.php:16-34`).
4. **`b.txns` خطأ في التسمية (PARTIAL):** cash-custody rows تحمل `amount`/`used` فعلًا (لا NaN من الباك)، لكن مصفوفة الحركات مفتاحها **`transactions`** لا `txns` (`CashCustodyController.php:18-52`) — لو نُسب انهيار الـ NaN إلى غياب amount/used فهو غير دقيق؛ الانهيار (إن حدث) بسبب اسم المفتاح.
5. **catalog المخزون يُرجع `categories/items` لا `brands` (PARTIAL):** أي فرونت يقرأ `.brands` سيقع على fallback فارغ (`InventoryController.php:111-128`). الحقل الصحيح للتجميع per-branch هو `branches` لا `byBranch`.
6. **allocations الهدر تتوقّع `empAllocs` لا `empId` (PARTIAL):** الباك يتحقّق من `empAllocs` (array) ويخزّنه opaque بلا التحقق من بنية كل عنصر (`WasteController.php:70-84`).
7. **B-A2 غير دقيق في "approvalRate فقط":** لوحة الـ platform تقدّم KPIs أغنى (awaitingReview/iApproved/finalApproved/overdueCount) + modules breakdown؛ الحقيقي أن `completedBranches/totalBranches` هما الغائبان فقط.
8. **B-A3 "القدرة غائبة كليًا" مبالغة:** لا endpoint **مخصّص**، لكن نقل العهدة/الفرع ممكن عبر `PATCH company/me/assets/{id}`.
9. **`suppliers/export` ليس accountant-only:** مُتاح لكل أدوار الشركة (`api.php:712-713`).
10. **template الأصول الثابتة gated للأدمن لا للمحاسب:** لا يوجد مسار template مكافئ داخل كتلة `/company/me/*`، فوصول ExcelImportModal يعتمد على دور المستدعي (`api.php:111,215`).

**ثغرات باك لم يلتقطها الأوديت (تستحق التذكرة):**
- **تسريب tenant في قواعد التذكير:** `AutoReminderRule` بلا `BelongsToTenant` و`rules()` بلا `company_id` → `GET reminders/rules` يعرض قواعد كل الشركات (`AutoReminderRule.php:8-16`؛ `ReminderController.php:165-173`).
- **`GET accountant/assets` غير مصفّح وغير محدود** — خرق guardrail الـ unbounded collection (`AssetController.php:17-40`).
- **`updateAsset`:** الرد يحذف حقولًا محدّثة (category/custodian/cost/publicId/usefulLifeMonths)، و`array_filter` يمنع تصفير الحقول بـ `null` (لا يمكن مسح custodian/branch/notes) (`AccountantCompanyController.php:172-201`).
- **`approvalRatePct` مثبّت على 100** في لوحة الشركة، غير محسوب (`AccountantCompanyController.php:42`).

---

## 5. غير قابل للتحقق (الفرونت غائب)

لا يوجد `ASABPrototype.tsx` ولا `platform/accountant.ts` على هذا الجهاز، فالفئات التالية **لم تُفحَص** وتحتاج جولة فرونت مستقلة:

- **"hook exists but not called":** كل خطافات الأصول (`useCreate/Confirm/PlatformAssetDrafts/ConfirmDraft/DeleteDraft/ConvertExpenseToAsset/ImportAssets`)، والمخزون (`useFlag*/useSend*/useSave*Catalog/DailyList`)، والهدر (`useApprove/Reject/BulkApprove/PatchProduct/PutAllocations`)، والتذكير (`useSend/BulkSend/Respond/CreateReminder`)، والورديات/الموظفين/الكاش (`useClosePlatformShift/useEmployeeStatement/useRequestCashSettlement/useCreateCashTransaction`). **كل نظائرها في الباك موجودة وفعّالة** — لكن كونها مُستدعاة أم لا **غير قابل للتحقق**.
- **الادعاء المحوري B-A1 downstream:** أن الـ hook يُعيد array فتقرأ الصفحات props كائن فتكون `undefined` فتُعرض fallbacks ثابتة دائمًا — **شرط الباك المسبق مؤكَّد**، لكن سلوك الـ destructuring والعرض **فرونت**.
- **"wrong hook":** قراءة المخزون `.branches/.byBranch` من hook العمليات — سلوك فرونت.
- **`setTimeout` الوهمي / confirm-close الزائف** في إغلاق الوردية — سلوك فرونت (endpoint الإغلاق حقيقي).
- **الأرقام الملفّقة في الفرونت** (12400 / DEFICIT 360، أرقام 4/8 static) — الباك يخدم القيم المحسوبة الحقيقية، لكن ما يعرضه الفرونت فعليًا **غير قابل للتحقق**.
- **أخطاء منطق الفرونت** مثل ازدواج الشرط `target!=="all" && target!=="all"` — لا وجود لها في الباك، **غير قابلة للتحقق** كسلوك فرونت.
- **"أزرار ميتة" (Confirm Assignment / Request Clarification / Save Note / view/download):** كل الـ endpoints خلفها موجودة؛ **الربط الفعلي في الفرونت غير قابل للتحقق**.
- **كون الصفحات (reminder rules / reports) static:** endpoints الباك جاهزة؛ استاتيكية الفرونت **غير قابلة للتحقق**.

---

*تم إنشاؤه عبر تحقق متعدد الوكلاء (7 محقّقين مجاليّين + توليف) على مصدر Laravel الباك-إند. الفرونت غير موجود على الجهاز فبقيت ادعاءات سلوكه غير قابلة للتحقق.*
