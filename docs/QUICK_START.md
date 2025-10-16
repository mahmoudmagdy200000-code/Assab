# Quick Start Guide - API Response Format

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

    public function show($id)
    {
        $data = $this->service->find($id);
        return $this->resourceResponse(new YourResource($data));
    }

    public function update(Request $request, $id)
    {
        $data = $this->service->update($id, $request->validated());
        return $this->updatedResponse(new YourResource($data));
    }

    public function destroy($id)
    {
        $this->service->delete($id);
        return $this->deletedResponse();
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
            'image' => $this->formatImageUrl($this->image),
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

-   `successResponse($data, $message, $status, $meta)`
-   `createdResponse($data, $message)` - 201
-   `updatedResponse($data, $message)` - 200
-   `deletedResponse($message)` - 200
-   `resourceResponse($data, $message)` - 200
-   `collectionResponse($data, $message, $meta)` - 200
-   `paginatedResponse($data, $message)` - 200

### Error Responses

-   `errorResponse($message, $status, $errors, $meta)`
-   `validationErrorResponse($errors, $message)` - 422
-   `notFoundResponse($message)` - 404
-   `unauthorizedResponse($message)` - 401
-   `forbiddenResponse($message)` - 403
-   `serverErrorResponse($message, $exception)` - 500
-   `conflictResponse($message)` - 409

### Utility Methods

-   `handleException($exception, $context)`
-   `responseWithMeta($data, $message, $meta, $status)`
-   `responseWithExecutionTime($data, $message, $startTime, $status)`

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

هذا كل ما تحتاجه للبدء! 🎉
