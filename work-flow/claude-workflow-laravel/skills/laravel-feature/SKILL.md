---
name: laravel-feature
description: Laravel modular/clean architecture reference and feature scaffolding. Use when creating features, API endpoints, models, migrations, actions, or repositories, or when asking about architecture patterns, layer boundaries, request/response flow, DI bindings, validation, authorization, or project structure. Triggers on "create feature", "new endpoint", "new API", "add module", "how does X work", "architecture", "scaffold".
allowed-tools: Read Write Edit Bash(find *) Bash(grep *) Bash(php artisan route:list *) Bash(php artisan make:*)
---

# Laravel Backend Architecture — Reference & Scaffolding

## Architecture Layers

```
HTTP → Application → Domain ← Infrastructure
```

Domain depends on nothing. HTTP and Infrastructure depend on Domain, never on each other.

```
Request → Middleware → FormRequest → Controller → DTO → Action → RepositoryInterface → EloquentRepo → DB
                       (validate +                                                                      ↓
                        authorize)                                                                   Model
                                                                                                        ↓
Response ←   JSON envelope   ←   Resource   ←   DTO / Model   ←   Action returns
```

**Rule of thumb:** if you deleted `app/Http/` entirely, the business logic must still run (from a command, a job, or a test).

---

## Layout Decision — pick ONE per project

| | **A. Modular / Domain** (default) | **B. Standard Laravel + Actions** |
|---|---|---|
| Structure | `app/Domain/{Module}/...` | `app/Models`, `app/Actions`, `app/Http` |
| Best for | 15+ endpoints, multiple modules, 2+ devs, long-lived product | MVPs, small internal tools, <10 endpoints |
| Cost | More files, more ceremony | Gets messy past ~20 models |
| Migration path | — | Can be lifted into A later, module by module |

**Take A when the project will be maintained for more than 6 months. Take B when speed to first release dominates and the scope is genuinely small.** Record the choice at the top of `CLAUDE.md`. Never mix both in one codebase.

Everything below shows **Layout A**. For Layout B, keep the same class responsibilities and flatten the paths.

---

## Repository Layer — when to use it (READ BEFORE SCAFFOLDING)

A repository over Eloquent is **not** free. Use this decision rule:

- **Use a repository** when: the data source may change (DB → external API), the module is a bounded context with complex queries, or the query logic is reused by 3+ Actions.
- **Skip the repository** and let the Action use the Model directly when: it's simple CRUD, there is one caller, and the "repository" would only be `Model::find()` passthrough. A wrapper that adds no behavior is dead weight.
- **Never** create a repository interface with a single implementation and a single caller "for testing" — Laravel's test DB + factories test that better.

If you skip it, keep query logic in **query scopes** or a dedicated `{Module}Queries` class, not scattered in Actions.

---

## Feature Directory Structure (Layout A)

```
app/
├── Domain/{Module}/
│   ├── Actions/
│   │   └── {Verb}{Noun}Action.php
│   ├── Contracts/
│   │   └── {Module}RepositoryInterface.php
│   ├── Data/                              # DTOs
│   │   ├── {Noun}Data.php
│   │   └── {Verb}{Noun}Data.php           # input DTO
│   ├── Enums/
│   │   └── {Noun}Status.php
│   ├── Events/
│   │   └── {Noun}{PastTenseVerb}.php
│   ├── Exceptions/
│   │   └── {Module}Exception.php
│   ├── Jobs/
│   ├── Models/
│   │   └── {Noun}.php
│   └── Policies/
│       └── {Noun}Policy.php
├── Infrastructure/{Module}/
│   ├── Repositories/
│   │   └── Eloquent{Module}Repository.php
│   └── Gateways/                          # external APIs
├── Http/Api/V1/{Module}/
│   ├── Controllers/
│   │   └── {Noun}Controller.php
│   ├── Requests/
│   │   ├── Store{Noun}Request.php
│   │   └── Update{Noun}Request.php
│   └── Resources/
│       ├── {Noun}Resource.php
│       └── {Noun}CollectionResource.php
├── Providers/
│   └── DomainServiceProvider.php
└── Support/                               # shared across 2+ modules

database/
├── migrations/
├── factories/{Noun}Factory.php
└── seeders/

routes/api/v1/{module}.php
tests/
├── Feature/Api/V1/{Module}/{Noun}EndpointTest.php
└── Unit/Domain/{Module}/{Verb}{Noun}ActionTest.php
```

---

## Layer Patterns

### Migration
```php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            $table->string('status')->index()->default(InvoiceStatus::Draft->value);
            $table->decimal('total', 15, 2);   // NEVER float for money
            $table->char('currency', 3);
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);   // composite for the common filter
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
```

### Enum (Domain — pure PHP)
```php
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft  => $target === self::Issued,
            self::Issued => $target === self::Paid,
            self::Paid   => false,
        };
    }
}
```

### Model (Domain — data + relations + scopes only, NO business logic)
```php
final class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['client_id', 'number', 'status', 'total', 'currency', 'issued_at'];

    protected function casts(): array
    {
        return [
            'status'    => InvoiceStatus::class,
            'total'     => 'decimal:2',
            'issued_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    #[Scope]
    protected function status(Builder $query, InvoiceStatus $status): void
    {
        $query->where('status', $status);
    }
}
```

### DTO (Domain — readonly, replaces loose arrays)
```php
final readonly class IssueInvoiceData
{
    public function __construct(
        public int $clientId,
        public string $currency,
        /** @var array<int, InvoiceLineData> */
        public array $lines,
        public ?CarbonImmutable $issuedAt = null,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        return new self(
            clientId: (int) $validated['client_id'],
            currency: $validated['currency'],
            lines: array_map(InvoiceLineData::fromArray(...), $validated['lines']),
            issuedAt: isset($validated['issued_at'])
                ? CarbonImmutable::parse($validated['issued_at'])
                : null,
        );
    }
}
```

### Repository Interface (Domain)
```php
interface InvoiceRepositoryInterface
{
    public function findById(int $id): ?Invoice;

    /** @return LengthAwarePaginator<Invoice> */
    public function paginateForClient(int $clientId, int $perPage = 15): LengthAwarePaginator;

    public function store(IssueInvoiceData $data): Invoice;
}
```

### Repository Implementation (Infrastructure — catches driver errors, maps to domain exceptions)
```php
final class EloquentInvoiceRepository implements InvoiceRepositoryInterface
{
    public function findById(int $id): ?Invoice
    {
        return Invoice::with(['client:id,name', 'lines'])->find($id);
    }

    public function paginateForClient(int $clientId, int $perPage = 15): LengthAwarePaginator
    {
        return Invoice::query()
            ->select(['id', 'client_id', 'number', 'status', 'total', 'currency', 'issued_at'])
            ->with('client:id,name')
            ->where('client_id', $clientId)
            ->latest('id')
            ->paginate($perPage);
    }

    public function store(IssueInvoiceData $data): Invoice
    {
        try {
            return DB::transaction(function () use ($data): Invoice {
                $invoice = Invoice::create([...]);
                $invoice->lines()->createMany(array_map(fn ($l) => $l->toArray(), $data->lines));

                return $invoice->fresh(['lines']);
            });
        } catch (QueryException $e) {
            throw InvoiceException::persistenceFailed($e);   // typed domain exception
        }
    }
}
```

### Action (Application — the business operation)
```php
final readonly class IssueInvoiceAction
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private InvoiceNumberGenerator $numbers,
    ) {}

    public function handle(IssueInvoiceData $data): Invoice
    {
        $invoice = $this->invoices->store($data);

        if (! $invoice->status->canTransitionTo(InvoiceStatus::Issued)) {
            throw InvoiceException::invalidTransition($invoice->status, InvoiceStatus::Issued);
        }

        $invoice->update([
            'status'    => InvoiceStatus::Issued,
            'number'    => $this->numbers->next(),
            'issued_at' => now(),
        ]);

        InvoiceIssued::dispatch($invoice->id);   // after commit — carries the ID, not the model

        return $invoice;
    }
}
```

### Domain Exception (typed, carries a stable code + status)
```php
class InvoiceException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidTransition(InvoiceStatus $from, InvoiceStatus $to): self
    {
        return new self("Cannot move invoice from {$from->value} to {$to->value}.", 'INVOICE_INVALID_TRANSITION');
    }

    public static function persistenceFailed(Throwable $previous): self
    {
        return new self('Could not save the invoice.', 'INVOICE_PERSISTENCE_FAILED', 500, $previous);
    }
}
```

### FormRequest (HTTP — validate + authorize)
```php
final class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Invoice::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id'      => ['required', 'integer', Rule::exists('clients', 'id')],
            'currency'       => ['required', 'string', 'size:3'],
            'lines'          => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.name'   => ['required', 'string', 'max:255'],
            'lines.*.qty'    => ['required', 'integer', 'min:1'],
            'lines.*.price'  => ['required', 'numeric', 'min:0'],
        ];
    }

    public function toData(): IssueInvoiceData
    {
        return IssueInvoiceData::fromArray($this->validated());
    }
}
```

### Controller (HTTP — thin, one Action call)
```php
final class InvoiceController extends Controller
{
    public function store(StoreInvoiceRequest $request, IssueInvoiceAction $action): JsonResponse
    {
        $invoice = $action->handle($request->toData());

        return InvoiceResource::make($invoice)
            ->response()
            ->setStatusCode(201);
    }

    public function index(IndexInvoiceRequest $request, InvoiceRepositoryInterface $invoices): AnonymousResourceCollection
    {
        return InvoiceResource::collection(
            $invoices->paginateForClient($request->integer('client_id'), $request->integer('per_page', 15))
        );
    }
}
```

### API Resource (HTTP — the response contract)
```php
final class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'number'    => $this->number,
            'status'    => $this->status->value,
            'total'     => (string) $this->total,     // string, not float — no precision loss
            'currency'  => $this->currency,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'client'    => ClientResource::make($this->whenLoaded('client')),
            'lines'     => InvoiceLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
```

`whenLoaded()` is mandatory on every relation — it is what prevents a Resource from silently triggering N+1.

### Policy (authorization)
```php
final class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->company_id === $invoice->client->company_id;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice) && $invoice->status === InvoiceStatus::Draft;
    }
}
```

### Routes (`routes/api/v1/invoices.php`)
```php
Route::middleware(['auth:sanctum', 'throttle:api'])
    ->prefix('invoices')
    ->name('invoices.')
    ->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('{invoice}', [InvoiceController::class, 'show'])->name('show')->can('view', 'invoice');
    });
```

### DI Registration (`app/Providers/DomainServiceProvider.php`)
```php
final class DomainServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        InvoiceRepositoryInterface::class => EloquentInvoiceRepository::class,
    ];

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::unguard(false);
    }
}
```

- **Singleton** for stateless gateways and clients
- **Bind** for repositories
- Actions are auto-resolved by the container — no registration needed

### Standard JSON Envelope

Success (Resource output is wrapped by Laravel's `data` key — keep it consistent):
```json
{ "data": { }, "meta": { } }
```

Error (rendered centrally in `bootstrap/app.php`):
```json
{ "message": "Cannot move invoice from paid to issued.", "error_code": "INVOICE_INVALID_TRANSITION", "errors": {} }
```

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (InvoiceException $e, Request $request) {
        return $request->expectsJson()
            ? response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status)
            : null;
    });
})
```

---

## Layer Rules

1. **Controllers** call exactly one Action — never a repository for writes, never a model
2. **Actions** depend only on interfaces and other Actions — never on `Request` or `Response`
3. **Models** hold casts, relations, and scopes — zero business rules, zero HTTP, zero notifications
4. **Repositories** catch driver exceptions at the boundary and map them to typed domain exceptions
5. **Domain** has zero `Illuminate\Http` imports
6. **Resources** never query — every relation goes through `whenLoaded()`

## Scaffolding Checklist

- [ ] Migration written, reversible, foreign keys + filter columns indexed
- [ ] Model has casts + relations only; `$fillable` explicit
- [ ] Enum used instead of magic status strings
- [ ] DTO created for the input; no arrays crossing the HTTP boundary
- [ ] Action created, single `handle()`, no framework coupling
- [ ] Repository interface + Eloquent implementation (or documented decision to skip)
- [ ] FormRequest with `rules()` + `authorize()`
- [ ] Policy registered for the model
- [ ] Controller thin, returns a Resource
- [ ] Resource uses `whenLoaded()` on all relations
- [ ] Route added under the versioned prefix with `throttle` + auth middleware
- [ ] Interface bound in `DomainServiceProvider`
- [ ] Factory created for the model
- [ ] Feature test: happy path + 401/403 + 422
- [ ] `config/{module}.php` entry + `.env.example` line if any new setting
