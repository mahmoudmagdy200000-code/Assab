# 🎯 API Response Format System

## نظرة عامة

تم إنشاء نظام موحد للـ API Response Format لضمان التناسق والجودة في جميع الموديولات. يتضمن النظام:

-   **BaseController**: Controller أساسي مع methods للـ responses
-   **BaseResource**: Resource أساسي مع methods للتنسيق
-   **BaseRequest**: Request أساسي مع validation موحد
-   **ApiResponse Trait**: Trait للـ response methods
-   **Response Macros**: Global response macros

## 📁 هيكل الملفات

```
app/
├── Http/
│   ├── Controllers/
│   │   └── BaseController.php          # Controller أساسي
│   ├── Resources/
│   │   ├── BaseResource.php           # Resource أساسي
│   │   └── UserResource.php           # مثال
│   └── Requests/
│       ├── BaseRequest.php            # Request أساسي
│       └── UserRequest.php            # مثال
├── Providers/
│   └── ApiResponseServiceProvider.php # Service Provider
└── Traits/
    └── ApiResponse.php                # Trait للـ responses

docs/
├── API_RESPONSE_FORMAT.md             # Documentation شامل
├── QUICK_START.md                     # دليل البدء السريع
└── MIGRATION_GUIDE.md                 # دليل الترحيل

examples/
├── UpdatedBranchController.php        # مثال Controller محدث
└── UpdatedAggregatorController.php    # مثال Controller محدث
```

## 🚀 البدء السريع

### 1. تحديث Controller

```php
<?php

namespace Modules\YourModule\Http\Controllers;

use App\Http\Controllers\BaseController;

class YourController extends BaseController
{
    public function index()
    {
        $data = $this->service->getData();
        return $this->paginatedResponse($data, 'Success');
    }

    public function store(Request $request)
    {
        $data = $this->service->create($request->validated());
        return $this->createdResponse(new YourResource($data));
    }
}
```

### 2. تحديث Resource

```php
<?php

namespace Modules\YourModule\Transformers;

use App\Http\Resources\BaseResource;

class YourResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->formatStatus(),
            'timestamps' => $this->formatTimestamps(),
        ];
    }
}
```

### 3. تحديث Request

```php
<?php

namespace Modules\YourModule\Http\Requests;

use App\Http\Requests\BaseRequest;

class YourRequest extends BaseRequest
{
    public function rules(): array
    {
        return array_merge(
            $this->getCommonRules(),
            $this->getPaginationRules(),
            [
                'name' => 'required|string|max:255',
                'email' => 'required|email',
            ]
        );
    }
}
```

## 📋 Response Methods المتاحة

### Success Responses

-   `successResponse($data, $message, $status, $meta)` - Response عام
-   `createdResponse($data, $message)` - 201 Created
-   `updatedResponse($data, $message)` - 200 Updated
-   `deletedResponse($message)` - 200 Deleted
-   `resourceResponse($data, $message)` - 200 Single Resource
-   `collectionResponse($data, $message, $meta)` - 200 Collection
-   `paginatedResponse($data, $message)` - 200 Paginated

### Error Responses

-   `errorResponse($message, $status, $errors, $meta)` - Error عام
-   `validationErrorResponse($errors, $message)` - 422 Validation Error
-   `notFoundResponse($message)` - 404 Not Found
-   `unauthorizedResponse($message)` - 401 Unauthorized
-   `forbiddenResponse($message)` - 403 Forbidden
-   `serverErrorResponse($message, $exception)` - 500 Server Error
-   `conflictResponse($message)` - 409 Conflict

### Utility Methods

-   `handleException($exception, $context)` - معالجة الأخطاء
-   `responseWithMeta($data, $message, $meta, $status)` - Response مع meta
-   `responseWithExecutionTime($data, $message, $startTime, $status)` - Response مع وقت التنفيذ

## 🎨 Resource Formatting Methods

-   `formatTimestamps()` - تنسيق التواريخ
-   `formatStatus()` - تنسيق الحالة
-   `formatCurrency($amount, $currency)` - تنسيق العملة
-   `formatPercentage($value, $decimals)` - تنسيق النسب المئوية
-   `formatDate($date, $format)` - تنسيق التاريخ
-   `formatImageUrl($imagePath)` - تنسيق رابط الصورة
-   `formatBoolean($value)` - تنسيق القيم المنطقية
-   `formatNestedResource($resource, $resourceClass)` - تنسيق Resource متداخل
-   `formatNestedCollection($collection, $resourceClass)` - تنسيق Collection متداخل

## 🔧 Request Helper Methods

-   `getCommonRules()` - قواعد عامة
-   `getPaginationRules()` - قواعد التصفح
-   `getSearchRules()` - قواعد البحث
-   `getDateRangeRules()` - قواعد نطاق التاريخ
-   `getFileUploadRules()` - قواعد رفع الملفات
-   `isCreating()` - هل الطلب لإنشاء جديد
-   `isUpdating()` - هل الطلب للتحديث
-   `getPaginationParams()` - معاملات التصفح
-   `getSearchParams()` - معاملات البحث

## 📊 Response Format

### Success Response

```json
{
    "success": true,
    "message": "Success message",
    "data": {
        /* البيانات */
    },
    "meta": {
        /* معلومات إضافية */
    }
}
```

### Error Response

```json
{
    "success": false,
    "message": "Error message",
    "errors": {
        /* تفاصيل الأخطاء */
    },
    "meta": {
        /* معلومات إضافية */
    }
}
```

### Paginated Response

```json
{
    "success": true,
    "message": "Data retrieved successfully",
    "data": [
        /* البيانات */
    ],
    "meta": {
        "pagination": {
            "current_page": 1,
            "per_page": 15,
            "total": 100,
            "last_page": 7,
            "from": 1,
            "to": 15,
            "has_more_pages": true
        }
    }
}
```

## 🚨 Error Handling

```php
try {
    // منطق العمل
} catch (\Exception $e) {
    return $this->handleException($e, 'Operation context');
}
```

الأخطاء المدعومة تلقائياً:

-   `ValidationException` → 422
-   `ModelNotFoundException` → 404
-   `AuthorizationException` → 403
-   `AuthenticationException` → 401
-   `QueryException` → 500

## 🧪 Testing

```php
$response = $this->get('/api/your-endpoint');

$response->assertStatus(200)
    ->assertJsonStructure([
        'success',
        'message',
        'data',
        'meta'
    ])
    ->assertJson(['success' => true]);
```

## 🔄 Migration Steps

### 1. Controllers

-   تغيير `extends Controller` إلى `extends BaseController`
-   إضافة `use App\Http\Controllers\BaseController;`
-   استبدال `response()->json()` بـ methods من BaseController
-   إضافة `try-catch` مع `handleException()`

### 2. Resources

-   تغيير `extends JsonResource` إلى `extends BaseResource`
-   إضافة `use App\Http\Resources\BaseResource;`
-   استبدال التنسيق اليدوي بـ formatting methods

### 3. Requests

-   تغيير `extends FormRequest` إلى `extends BaseRequest`
-   إضافة `use App\Http\Requests\BaseRequest;`
-   استخدام helper methods للقواعد

## 📈 Benefits

### ✅ التناسق

-   جميع الـ responses لها نفس الهيكل
-   رسائل الأخطاء موحدة
-   تنسيق البيانات متسق

### ✅ سهولة الصيانة

-   كود أقل تكراراً
-   معالجة أخطاء موحدة
-   تنسيق تلقائي للبيانات

### ✅ تجربة مطور أفضل

-   methods جاهزة للاستخدام
-   documentation واضحة
-   examples شاملة

### ✅ جودة الكود

-   اتباع SOLID principles
-   Type hinting شامل
-   Error handling محسن

## 🎯 Next Steps

1. **تطبيق النظام**: ابدأ بتحديث الموديولات واحداً تلو الآخر
2. **اختبار شامل**: تأكد من عمل جميع الـ endpoints
3. **تدريب الفريق**: وضح النظام الجديد للفريق
4. **مراقبة الأداء**: راقب الأداء بعد التطبيق
5. **تحديث Documentation**: حدث API documentation

## 📚 Documentation

-   [API Response Format Documentation](docs/API_RESPONSE_FORMAT.md)
-   [Quick Start Guide](docs/QUICK_START.md)
-   [Migration Guide](docs/MIGRATION_GUIDE.md)

## 🤝 Contributing

للمساهمة في تحسين النظام:

1. اتبع نفس الأنماط المستخدمة
2. أضف tests للـ features الجديدة
3. حدث documentation
4. تأكد من عدم كسر الـ backward compatibility

---

**تم إنشاء هذا النظام لضمان التناسق والجودة في جميع الـ API responses عبر الموديولات! 🎉**
