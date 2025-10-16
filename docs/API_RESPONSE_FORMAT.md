# API Response Format Documentation

## نظرة عامة

تم إنشاء نظام موحد للـ API Response Format لضمان التناسق في جميع الموديولات. يتضمن النظام:

-   **BaseController**: Controller أساسي مع methods للـ responses
-   **BaseResource**: Resource أساسي مع methods للتنسيق
-   **BaseRequest**: Request أساسي مع validation موحد
-   **ApiResponse Trait**: Trait للـ response methods

## 📁 هيكل الملفات

```
app/
├── Http/
│   ├── Controllers/
│   │   └── BaseController.php
│   ├── Resources/
│   │   ├── BaseResource.php
│   │   └── UserResource.php (مثال)
│   └── Requests/
│       ├── BaseRequest.php
│       └── UserRequest.php (مثال)
└── Traits/
    └── ApiResponse.php
```

## 🎯 Response Format الموحد

### Success Response

```json
{
    "success": true,
    "message": "Success message",
    "data": {
        // البيانات المطلوبة
    },
    "meta": {
        // معلومات إضافية (اختيارية)
    }
}
```

### Error Response

```json
{
    "success": false,
    "message": "Error message",
    "errors": {
        // تفاصيل الأخطاء (اختيارية)
    },
    "meta": {
        // معلومات إضافية (اختيارية)
    }
}
```

### Paginated Response

```json
{
    "success": true,
    "message": "Data retrieved successfully",
    "data": [
        // البيانات
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

## 🚀 كيفية الاستخدام

### 1. في Controllers

```php
<?php

namespace Modules\YourModule\Http\Controllers;

use App\Http\Controllers\BaseController;

class YourController extends BaseController
{
    public function index()
    {
        $data = $this->service->getData();

        return $this->paginatedResponse(
            $data,
            'Data retrieved successfully'
        );
    }

    public function store(Request $request)
    {
        $data = $this->service->create($request->validated());

        return $this->createdResponse(
            new YourResource($data),
            'Resource created successfully'
        );
    }

    public function show($id)
    {
        $data = $this->service->find($id);

        return $this->resourceResponse(
            new YourResource($data),
            'Resource retrieved successfully'
        );
    }

    public function update(Request $request, $id)
    {
        $data = $this->service->update($id, $request->validated());

        return $this->updatedResponse(
            new YourResource($data),
            'Resource updated successfully'
        );
    }

    public function destroy($id)
    {
        $this->service->delete($id);

        return $this->deletedResponse('Resource deleted successfully');
    }
}
```

### 2. في Resources

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
            'email' => $this->email,
            'status' => $this->formatStatus(),
            'image' => $this->formatImageUrl($this->image),
            'created_at' => $this->formatDate($this->created_at),
            'timestamps' => $this->formatTimestamps(),
        ];
    }
}
```

### 3. في Requests

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
            $this->getPaginationRules(),
            $this->getSearchRules()
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
        return 'your_table';
    }
}
```

## 📋 Available Methods

### BaseController Methods

#### Success Responses

-   `successResponse($data, $message, $status, $meta)`
-   `createdResponse($data, $message)`
-   `updatedResponse($data, $message)`
-   `deletedResponse($message)`
-   `resourceResponse($data, $message)`
-   `collectionResponse($data, $message, $meta)`
-   `paginatedResponse($data, $message)`

#### Error Responses

-   `errorResponse($message, $status, $errors, $meta)`
-   `validationErrorResponse($errors, $message)`
-   `notFoundResponse($message)`
-   `unauthorizedResponse($message)`
-   `forbiddenResponse($message)`
-   `serverErrorResponse($message, $exception)`
-   `conflictResponse($message)`
-   `tooManyRequestsResponse($message)`

#### Utility Methods

-   `handleException($exception, $context)`
-   `responseWithMeta($data, $message, $meta, $status)`
-   `responseWithExecutionTime($data, $message, $startTime, $status)`
-   `responseWithCache($data, $message, $cached, $cacheTtl, $status)`

### BaseResource Methods

#### Formatting Methods

-   `formatTimestamps()` - تنسيق التواريخ
-   `formatStatus()` - تنسيق الحالة
-   `formatCurrency($amount, $currency)` - تنسيق العملة
-   `formatPercentage($value, $decimals)` - تنسيق النسب المئوية
-   `formatDate($date, $format)` - تنسيق التاريخ
-   `formatImageUrl($imagePath)` - تنسيق رابط الصورة
-   `formatContact($contact)` - تنسيق معلومات الاتصال
-   `formatLocation($location)` - تنسيق الموقع
-   `formatStatistics($stats)` - تنسيق الإحصائيات
-   `formatBoolean($value)` - تنسيق القيم المنطقية
-   `formatIds($items, $key)` - تنسيق مصفوفة الـ IDs
-   `formatNestedResource($resource, $resourceClass)` - تنسيق Resource متداخل
-   `formatNestedCollection($collection, $resourceClass)` - تنسيق Collection متداخل

### BaseRequest Methods

#### Validation Rules

-   `getCommonRules()` - قواعد عامة
-   `getPaginationRules()` - قواعد التصفح
-   `getSearchRules()` - قواعد البحث
-   `getDateRangeRules()` - قواعد نطاق التاريخ
-   `getFileUploadRules()` - قواعد رفع الملفات
-   `getIdRules()` - قواعد الـ ID

#### Utility Methods

-   `validatedWithDefaults($defaults)` - بيانات محققة مع قيم افتراضية
-   `isCreating()` - هل الطلب لإنشاء جديد
-   `isUpdating()` - هل الطلب للتحديث
-   `getRouteParam($param, $default)` - الحصول على معامل من الـ route
-   `getPaginationParams()` - معاملات التصفح
-   `getSearchParams()` - معاملات البحث
-   `getDateRangeParams()` - معاملات نطاق التاريخ

## 🔧 Migration Guide

### تحديث Controllers الموجودة

1. **تغيير الـ extends:**

```php
// قبل
class YourController extends Controller

// بعد
class YourController extends BaseController
```

2. **تحديث الـ imports:**

```php
use App\Http\Controllers\BaseController;
```

3. **تحديث الـ responses:**

```php
// قبل
return response()->json([
    'success' => true,
    'data' => $data
]);

// بعد
return $this->successResponse($data, 'Success message');
```

### تحديث Resources الموجودة

1. **تغيير الـ extends:**

```php
// قبل
class YourResource extends JsonResource

// بعد
class YourResource extends BaseResource
```

2. **تحديث الـ imports:**

```php
use App\Http\Resources\BaseResource;
```

3. **استخدام الـ formatting methods:**

```php
// قبل
'status' => $this->status,
'created_at' => $this->created_at->format('Y-m-d H:i:s'),

// بعد
'status' => $this->formatStatus(),
'timestamps' => $this->formatTimestamps(),
```

### تحديث Requests الموجودة

1. **تغيير الـ extends:**

```php
// قبل
class YourRequest extends FormRequest

// بعد
class YourRequest extends BaseRequest
```

2. **تحديث الـ imports:**

```php
use App\Http\Requests\BaseRequest;
```

3. **استخدام الـ common rules:**

```php
public function rules(): array
{
    return array_merge(
        $this->getCommonRules(),
        $this->getPaginationRules(),
        // قواعدك الخاصة
    );
}
```

## 🎨 Examples

### مثال كامل لـ Controller

```php
<?php

namespace Modules\Products\Http\Controllers;

use App\Http\Controllers\BaseController;
use Modules\Products\Http\Requests\ProductRequest;
use Modules\Products\Services\ProductService;
use Modules\Products\Transformers\ProductResource;

class ProductController extends BaseController
{
    public function __construct(
        private ProductService $productService
    ) {}

    public function index(ProductRequest $request)
    {
        try {
            $filters = $request->getPaginationParams();
            $products = $this->productService->getProducts($filters);

            return $this->paginatedResponse(
                $products,
                'Products retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Product listing');
        }
    }

    public function store(ProductRequest $request)
    {
        try {
            $data = $request->validated();
            $product = $this->productService->createProduct($data);

            return $this->createdResponse(
                new ProductResource($product),
                'Product created successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'Product creation');
        }
    }
}
```

### مثال كامل لـ Resource

```php
<?php

namespace Modules\Products\Transformers;

use App\Http\Resources\BaseResource;

class ProductResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->formatCurrency($this->price),
            'status' => $this->formatStatus(),
            'category' => $this->formatNestedResource($this->whenLoaded('category')),
            'images' => $this->formatNestedCollection($this->whenLoaded('images')),
            'inventory' => [
                'stock' => $this->stock,
                'low_stock_threshold' => $this->low_stock_threshold,
                'is_low_stock' => $this->stock <= $this->low_stock_threshold,
            ],
            'statistics' => $this->formatStatistics([
                'total' => $this->total_sales ?? 0,
                'active' => $this->active_sales ?? 0,
            ]),
            'timestamps' => $this->formatTimestamps(),
        ];
    }
}
```

## 🚨 Error Handling

النظام يتعامل مع الأخطاء تلقائياً:

```php
// في Controller
try {
    // منطق العمل
} catch (\Exception $e) {
    return $this->handleException($e, 'Operation context');
}
```

الأخطاء المدعومة:

-   `ValidationException` → 422
-   `ModelNotFoundException` → 404
-   `AuthorizationException` → 403
-   `AuthenticationException` → 401
-   `QueryException` → 500
-   `General Exception` → 500

## 📊 Performance Tips

1. **استخدام Pagination:**

```php
return $this->paginatedResponse($data, 'Success');
```

2. **استخدام Execution Time:**

```php
$startTime = microtime(true);
// منطق العمل
return $this->responseWithExecutionTime($data, 'Success', $startTime);
```

3. **استخدام Cache Information:**

```php
return $this->responseWithCache($data, 'Success', true, 3600);
```

## 🔍 Testing

```php
// في Tests
$response = $this->get('/api/products');

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

هذا النظام يضمن التناسق والجودة في جميع الـ API responses عبر الموديولات.
