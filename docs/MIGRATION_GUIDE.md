# Migration Guide - API Response Format

## 🚀 دليل الترحيل إلى Response Format الجديد

### الخطوة 1: تحديث Controllers

#### قبل التحديث:

```php
<?php

namespace Modules\YourModule\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;

class YourController extends Controller
{
    public function index(): JsonResponse
    {
        $data = $this->service->getData();

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'Success'
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->service->create($request->all());

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'Created successfully'
        ], 201);
    }
}
```

#### بعد التحديث:

```php
<?php

namespace Modules\YourModule\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;

class YourController extends BaseController
{
    public function index(): JsonResponse
    {
        $data = $this->service->getData();

        return $this->paginatedResponse(
            $data,
            'Data retrieved successfully'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->service->create($request->validated());

        return $this->createdResponse(
            new YourResource($data),
            'Resource created successfully'
        );
    }
}
```

### الخطوة 2: تحديث Resources

#### قبل التحديث:

```php
<?php

namespace Modules\YourModule\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class YourResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
```

#### بعد التحديث:

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

### الخطوة 3: تحديث Requests

#### قبل التحديث:

```php
<?php

namespace Modules\YourModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class YourRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422)
        );
    }
}
```

#### بعد التحديث:

```php
<?php

namespace Modules\YourModule\Http\Requests;

use App\Http\Requests\BaseRequest;

class YourRequest extends BaseRequest
{
    public function rules(): array
    {
        $rules = array_merge(
            $this->getCommonRules(),
            $this->getPaginationRules()
        );

        if ($this->isCreating()) {
            $rules = array_merge($rules, [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
            ]);
        }

        return $rules;
    }

    protected function getTableName(): string
    {
        return 'users';
    }
}
```

## 📋 قائمة التحقق للترحيل

### ✅ Controllers

-   [ ] تغيير `extends Controller` إلى `extends BaseController`
-   [ ] إضافة `use App\Http\Controllers\BaseController;`
-   [ ] استبدال `response()->json()` بـ methods من BaseController
-   [ ] إضافة `try-catch` مع `handleException()`
-   [ ] استخدام `$request->validated()` بدلاً من `$request->all()`

### ✅ Resources

-   [ ] تغيير `extends JsonResource` إلى `extends BaseResource`
-   [ ] إضافة `use App\Http\Resources\BaseResource;`
-   [ ] استبدال التنسيق اليدوي بـ formatting methods
-   [ ] استخدام `formatTimestamps()` للتواريخ
-   [ ] استخدام `formatStatus()` للحالات
-   [ ] استخدام `formatImageUrl()` للصور

### ✅ Requests

-   [ ] تغيير `extends FormRequest` إلى `extends BaseRequest`
-   [ ] إضافة `use App\Http\Requests\BaseRequest;`
-   [ ] استخدام `getCommonRules()` للقواعد العامة
-   [ ] استخدام `getPaginationRules()` للتصفح
-   [ ] استخدام `getSearchRules()` للبحث
-   [ ] إضافة `getTableName()` method

### ✅ Error Handling

-   [ ] إضافة `try-catch` blocks في Controllers
-   [ ] استخدام `handleException()` للتعامل مع الأخطاء
-   [ ] إزالة error handling اليدوي

## 🔄 أمثلة على التحويلات

### 1. Success Response

#### قبل:

```php
return response()->json([
    'success' => true,
    'data' => $data,
    'message' => 'Success'
], 200);
```

#### بعد:

```php
return $this->successResponse($data, 'Success');
```

### 2. Error Response

#### قبل:

```php
return response()->json([
    'success' => false,
    'message' => 'Error occurred',
    'errors' => $errors
], 400);
```

#### بعد:

```php
return $this->errorResponse('Error occurred', 400, $errors);
```

### 3. Validation Error

#### قبل:

```php
return response()->json([
    'success' => false,
    'message' => 'Validation failed',
    'errors' => $validator->errors()
], 422);
```

#### بعد:

```php
return $this->validationErrorResponse($validator->errors());
```

### 4. Paginated Response

#### قبل:

```php
return response()->json([
    'success' => true,
    'data' => $data->items(),
    'pagination' => [
        'current_page' => $data->currentPage(),
        'per_page' => $data->perPage(),
        'total' => $data->total(),
        'last_page' => $data->lastPage(),
    ]
], 200);
```

#### بعد:

```php
return $this->paginatedResponse($data, 'Data retrieved successfully');
```

### 5. Resource Formatting

#### قبل:

```php
'status' => [
    'value' => $this->status,
    'label' => $this->status === 'active' ? 'Active' : 'Inactive',
    'color' => $this->status === 'active' ? 'green' : 'red',
],
'created_at' => $this->created_at->format('Y-m-d H:i:s'),
'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
```

#### بعد:

```php
'status' => $this->formatStatus(),
'timestamps' => $this->formatTimestamps(),
```

## 🚨 الأخطاء الشائعة

### 1. نسيان تحديث extends

```php
// ❌ خطأ
class YourController extends Controller

// ✅ صحيح
class YourController extends BaseController
```

### 2. عدم استخدام validated()

```php
// ❌ خطأ
$data = $request->all();

// ✅ صحيح
$data = $request->validated();
```

### 3. نسيان try-catch

```php
// ❌ خطأ
public function store(Request $request)
{
    $data = $this->service->create($request->validated());
    return $this->createdResponse($data);
}

// ✅ صحيح
public function store(Request $request)
{
    try {
        $data = $this->service->create($request->validated());
        return $this->createdResponse($data);
    } catch (\Exception $e) {
        return $this->handleException($e, 'Resource creation');
    }
}
```

### 4. عدم استخدام formatting methods

```php
// ❌ خطأ
'status' => $this->status,
'created_at' => $this->created_at->format('Y-m-d H:i:s'),

// ✅ صحيح
'status' => $this->formatStatus(),
'timestamps' => $this->formatTimestamps(),
```

## 🧪 Testing

### تحديث Tests

#### قبل:

```php
$response = $this->get('/api/your-endpoint');

$response->assertStatus(200)
    ->assertJson([
        'success' => true,
        'data' => [],
    ]);
```

#### بعد:

```php
$response = $this->get('/api/your-endpoint');

$response->assertStatus(200)
    ->assertJsonStructure([
        'success',
        'message',
        'data',
        'meta' => [
            'pagination'
        ]
    ])
    ->assertJson([
        'success' => true
    ]);
```

## 📊 Performance Tips

### 1. استخدام Pagination

```php
// ✅ جيد
return $this->paginatedResponse($data, 'Success');

// ❌ تجنب
return $this->successResponse($data->all(), 'Success');
```

### 2. استخدام Resource Collections

```php
// ✅ جيد
return $this->collectionResponse(
    YourResource::collection($data),
    'Success'
);

// ❌ تجنب
return $this->successResponse($data, 'Success');
```

### 3. Error Handling

```php
// ✅ جيد
try {
    // منطق العمل
} catch (\Exception $e) {
    return $this->handleException($e, 'Operation context');
}

// ❌ تجنب
try {
    // منطق العمل
} catch (\Exception $e) {
    return response()->json(['error' => $e->getMessage()], 500);
}
```

## 🎯 Checklist للانتهاء

-   [ ] جميع Controllers تستخدم BaseController
-   [ ] جميع Resources تستخدم BaseResource
-   [ ] جميع Requests تستخدم BaseRequest
-   [ ] جميع Responses تستخدم methods موحدة
-   [ ] Error handling موحد في جميع Controllers
-   [ ] Tests محدثة للـ response format الجديد
-   [ ] Documentation محدثة
-   [ ] Code review مكتمل

## 🚀 الخطوات التالية

1. **اختبار النظام**: تأكد من عمل جميع الـ endpoints
2. **مراجعة الأداء**: تحقق من أن الأداء لم يتأثر
3. **تحديث Documentation**: حدث API documentation
4. **تدريب الفريق**: وضح النظام الجديد للفريق
5. **مراقبة الأخطاء**: راقب الأخطاء بعد التطبيق

هذا النظام الجديد سيضمن التناسق والجودة في جميع الـ API responses! 🎉
