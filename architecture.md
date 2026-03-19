# System Architecture — Kutubxona.uz Digital Library SaaS Platform

## 1. System Overview and Design Philosophy

Kutubxona.uz is a **multi-tenant digital library SaaS platform** designed to serve thousands of independent library tenants (schools, universities, public libraries, enterprises) from a single deployment. Each tenant operates in a fully isolated logical environment while sharing the same physical infrastructure.

### Core Design Principles

| Principle | Application |
|-----------|-------------|
| **Clean Architecture** | Business logic never depends on frameworks or infrastructure |
| **Domain-Driven Design** | Bounded contexts per business domain |
| **SOLID** | Single responsibility at every layer |
| **Multi-tenancy by default** | Every query, every resource, every file is tenant-scoped |
| **API-first** | Angular frontend consumes the same REST API as third-party clients |
| **Security-first** | Authorization checked at policy, service, and query layers |
| **Observability** | Structured logging, distributed tracing, metrics at every boundary |

---

## 2. Clean Architecture + DDD Layers

```
┌─────────────────────────────────────────────────────────────────────┐
│                        PRESENTATION LAYER                           │
│  HTTP Controllers  │  Form Requests  │  API Resources  │  Policies  │
│  (Interfaces/Http)                                                  │
└───────────────────────────────┬─────────────────────────────────────┘
                                │ calls
┌───────────────────────────────▼─────────────────────────────────────┐
│                        APPLICATION LAYER                            │
│  Services (UseCases)  │  DTOs  │  Events  │  Jobs  │  Listeners    │
│  (Application/Services, Application/DTOs)                          │
└───────────────────────────────┬─────────────────────────────────────┘
                                │ calls
┌───────────────────────────────▼─────────────────────────────────────┐
│                          DOMAIN LAYER                               │
│  Eloquent Models  │  Domain Events  │  Value Objects  │  Enums      │
│  (Domain/Tenant, Domain/Book, Domain/User, Domain/Reading, ...)    │
└───────────────────────────────┬─────────────────────────────────────┘
                                │ implemented by
┌───────────────────────────────▼─────────────────────────────────────┐
│                      INFRASTRUCTURE LAYER                           │
│  Eloquent Repositories  │  S3 Storage  │  Redis Cache  │  Mail      │
│  (Infrastructure/Repositories, Infrastructure/Cache, ...)          │
└─────────────────────────────────────────────────────────────────────┘
```

### Directory Structure

```
backend/
├── app/
│   ├── Domain/                        # Core business entities
│   │   ├── Tenant/
│   │   │   ├── Models/
│   │   │   │   ├── Tenant.php
│   │   │   │   └── TenantDomain.php
│   │   │   └── Enums/
│   │   │       └── TenantStatus.php
│   │   ├── Book/
│   │   │   ├── Models/
│   │   │   │   ├── Book.php
│   │   │   │   ├── BookFile.php
│   │   │   │   ├── Author.php
│   │   │   │   ├── Publisher.php
│   │   │   │   ├── Category.php
│   │   │   │   ├── Genre.php
│   │   │   │   └── Tag.php
│   │   │   └── Enums/
│   │   │       └── BookFileType.php
│   │   ├── AudioBook/
│   │   │   ├── Models/
│   │   │   │   ├── AudioBook.php
│   │   │   │   └── AudioBookChapter.php
│   │   ├── User/
│   │   │   └── Models/
│   │   │       └── User.php
│   │   └── Reading/
│   │       └── Models/
│   │           ├── ReadingProgress.php
│   │           ├── Bookmark.php
│   │           ├── Highlight.php
│   │           ├── Note.php
│   │           └── Favorite.php
│   │
│   ├── Application/                   # Use cases, orchestration
│   │   ├── DTOs/
│   │   │   ├── Auth/
│   │   │   ├── Book/
│   │   │   └── Tenant/
│   │   └── Services/
│   │       ├── TenantService.php
│   │       ├── BookService.php
│   │       ├── AudioBookService.php
│   │       ├── AuthService.php
│   │       ├── ReadingService.php
│   │       ├── SearchService.php
│   │       └── StorageService.php
│   │
│   ├── Infrastructure/                # Framework-specific implementations
│   │   ├── Repositories/
│   │   │   ├── BookRepository.php
│   │   │   ├── TenantRepository.php
│   │   │   ├── UserRepository.php
│   │   │   └── ReadingProgressRepository.php
│   │   └── Cache/
│   │       └── TenantCacheManager.php
│   │
│   ├── Interfaces/                    # Entry points
│   │   └── Http/
│   │       ├── Controllers/V1/
│   │       ├── Middleware/
│   │       ├── Requests/
│   │       └── Resources/
│   │
│   ├── Jobs/
│   ├── Events/
│   ├── Listeners/
│   └── Policies/
│
├── database/
│   ├── migrations/
│   └── seeders/
├── routes/
│   └── api.php
└── config/
    ├── tenancy.php
    └── storage.php
```

---

## 3. Module Boundaries

```
┌──────────────────────────────────────────────────────────────────┐
│                         BOUNDED CONTEXTS                         │
│                                                                  │
│  ┌─────────────┐   ┌─────────────┐   ┌─────────────────────┐   │
│  │   IDENTITY  │   │   CATALOG   │   │      READING        │   │
│  │             │   │             │   │                     │   │
│  │ User        │   │ Book        │   │ ReadingProgress     │   │
│  │ Auth        │   │ AudioBook   │   │ Bookmark            │   │
│  │ Role        │   │ Author      │   │ Highlight           │   │
│  │ Permission  │   │ Publisher   │   │ Note                │   │
│  │             │   │ Category    │   │ Favorite            │   │
│  └──────┬──────┘   │ Genre, Tag  │   └─────────────────────┘   │
│         │          └──────┬──────┘                              │
│  ┌──────▼──────────────────▼──────┐   ┌─────────────────────┐   │
│  │           TENANCY              │   │      ANALYTICS      │   │
│  │                                │   │                     │   │
│  │ Tenant  TenantDomain           │   │ AnalyticsEvent      │   │
│  │ Subscription  Plan             │   │ Aggregation         │   │
│  └────────────────────────────────┘   └─────────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
```

### Module Communication Rules

- Modules communicate through **events and listeners** (no direct cross-module service injection)
- Shared data is accessed through **Domain Models** only (no raw DB queries across modules)
- The **Tenant** module is the **root aggregate** — every other module references `tenant_id`

---

## 4. Technology Stack Justification

| Component | Technology | Justification |
|-----------|------------|---------------|
| **Backend Framework** | Laravel 11 | Mature ecosystem, excellent ORM, built-in queue, events, policies |
| **PHP Version** | PHP 8.3 | Typed properties, enums, readonly classes, fibers |
| **Database** | MySQL 8.0 | Full-text search, JSON columns, window functions, partitioning |
| **Cache / Sessions** | Redis 7 | Sub-millisecond latency, pub/sub, Lua scripting for atomic operations |
| **Queue** | Laravel Horizon + Redis | Priority queues, monitoring UI, auto-scaling workers |
| **File Storage** | AWS S3 / MinIO | Infinitely scalable, signed URLs, lifecycle policies |
| **Search** | MySQL FTS → Elasticsearch | Start simple, migrate when needed (Laravel Scout abstraction) |
| **Frontend** | Angular 17+ | Strong typing, DI, RxJS, enterprise-grade state management |
| **State Management** | NgRx | Predictable state, DevTools, entity adapter |
| **Containerization** | Docker + Docker Compose | Reproducible environments, easy horizontal scaling |
| **Web Server** | Nginx + PHP-FPM | High concurrency, efficient static file serving |
| **Email** | Mailtrap (dev) / SES (prod) | Reliable delivery, bounce handling |
| **Auth** | JWT via tymon/jwt-auth | Stateless, multi-tenant capable, refresh token rotation |
| **Permissions** | spatie/laravel-permission | Battle-tested RBAC, tenant-aware roles |
| **Media** | spatie/laravel-medialibrary | Standardized file management, conversions |

---

## 5. Component Interaction Diagrams

### Request Lifecycle

```
Client Request
     │
     ▼
┌─────────────┐
│   Nginx     │ ──── Static files served directly
└──────┬──────┘
       │ Dynamic requests
       ▼
┌─────────────┐
│  PHP-FPM    │
└──────┬──────┘
       │
       ▼
┌─────────────────────────────────────────┐
│           Laravel Kernel                │
│                                         │
│  1. TenantMiddleware                    │
│     ↓ detect tenant from domain/header  │
│  2. AuthMiddleware (JWT)                │
│     ↓ validate token, set user          │
│  3. RateLimitMiddleware                 │
│     ↓ check per-tenant limits           │
│  4. Route → Controller                  │
│     ↓                                   │
│  5. FormRequest validation              │
│     ↓                                   │
│  6. Application Service (use case)      │
│     ↓                                   │
│  7. Policy check                        │
│     ↓                                   │
│  8. Repository (Eloquent + tenant scope)│
│     ↓                                   │
│  9. API Resource (response transform)   │
└─────────────────────────────────────────┘
       │
       ▼
JSON Response {success, data, message, meta}
```

### Book Upload Flow

```
Client → POST /api/v1/books
         │
         ▼
    BookController
         │
         ▼
    CreateBookRequest (validation)
         │
         ▼
    BookService::createBook()
         │
         ├──► BookRepository::create()
         │         │
         │         └──► books table (MySQL)
         │
         ├──► event(new BookUploaded($book, $file))
         │
         └──► Response

Event: BookUploaded
    │
    ▼
Listener: ProcessBookAfterUpload
    │
    ▼
Job: ProcessBookFile (queued)
    │
    ├──► Validate MIME type
    ├──► Extract metadata (title, pages, ISBN)
    ├──► Generate cover thumbnail
    ├──► Upload to S3: tenants/{id}/books/{id}/file.pdf
    └──► Update book record with S3 path
```

---

## 6. Design Patterns Used

### Repository Pattern

```php
// Interface in Domain
interface BookRepositoryInterface {
    public function findById(int $id): ?Book;
    public function findByTenant(int $tenantId, array $filters): LengthAwarePaginator;
    public function create(array $data): Book;
    public function update(Book $book, array $data): Book;
    public function delete(Book $book): bool;
}

// Implementation in Infrastructure
class BookRepository implements BookRepositoryInterface {
    public function __construct(private readonly Book $model) {}
    // Eloquent implementations
}
```

### Service Layer (Application Service / Use Case)

Each service method = one use case:
- `BookService::createBook(CreateBookDTO $dto, User $actor): Book`
- `BookService::searchBooks(SearchFiltersDTO $dto): LengthAwarePaginator`

Services are **not** controllers — they contain business logic, not HTTP concerns.

### DTOs (Data Transfer Objects)

```php
// Immutable, typed, constructed from request data
readonly class CreateBookDTO {
    public function __construct(
        public string $title,
        public int $authorId,
        public ?string $isbn,
        // ...
    ) {}

    public static function fromRequest(CreateBookRequest $request): self { ... }
}
```

### CQRS Concepts

Although not strict CQRS, commands (writes) go through Services → Repositories with full validation, while queries (reads) can use optimized read methods, query builders, and cached results.

### Observer / Events

Laravel Events decouple side-effects from core business logic:
- `TenantCreated` → `SetupTenantStorage`, `SendTenantWelcomeEmail`
- `BookUploaded` → `ProcessBookAfterUpload`
- `UserRegistered` → `SendVerificationEmail`

---

## 7. Error Handling Strategy

### HTTP Error Response Format

```json
{
  "success": false,
  "data": null,
  "message": "Validation failed",
  "errors": {
    "title": ["The title field is required."],
    "isbn": ["ISBN must be 13 digits."]
  },
  "meta": {
    "request_id": "req_abc123",
    "timestamp": "2024-01-15T10:30:00Z"
  }
}
```

### Exception Hierarchy

```
\Exception
  └── \App\Exceptions\AppException
        ├── \App\Exceptions\TenantNotFoundException
        ├── \App\Exceptions\TenantSuspendedException
        ├── \App\Exceptions\UnauthorizedException
        ├── \App\Exceptions\ResourceNotFoundException
        ├── \App\Exceptions\ValidationException (wraps Laravel's)
        └── \App\Exceptions\StorageException
```

### Handler Registration (bootstrap/app.php)

```php
$exceptions->render(function (TenantNotFoundException $e) {
    return response()->json(['success' => false, 'message' => 'Tenant not found'], 404);
});

$exceptions->render(function (AuthorizationException $e) {
    return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
});
```

---

## 8. Logging and Observability

### Structured Logging

All log entries include:
```json
{
  "level": "error",
  "message": "Book upload failed",
  "context": {
    "tenant_id": 42,
    "user_id": 1001,
    "book_id": 5500,
    "exception": "StorageException",
    "trace": "...",
    "request_id": "req_abc123"
  },
  "timestamp": "2024-01-15T10:30:00Z"
}
```

### Log Channels

| Channel | Usage | Destination |
|---------|-------|-------------|
| `daily` | General application logs | Files (rotated daily) |
| `stack` | Combined channels | daily + stderr |
| `audit` | Sensitive actions | Separate audit.log |
| `queue` | Job processing logs | queue.log |
| `tenant` | Tenant-specific events | tenant-{id}.log (optional) |

### Monitoring Stack

- **Laravel Telescope** (development): Query, request, job, event inspection
- **Laravel Horizon**: Queue monitoring UI
- **Prometheus + Grafana**: Metrics (response time, queue depth, error rate)
- **Sentry**: Error tracking with release tracking
- **Datadog / New Relic**: APM for production (optional)

### Health Checks

`GET /api/health` returns:
```json
{
  "status": "healthy",
  "checks": {
    "database": "ok",
    "redis": "ok",
    "storage": "ok",
    "queue": "ok"
  }
}
```

---

## 9. CI/CD Pipeline Recommendations

### Pipeline Stages

```
┌─────────────────────────────────────────────────────────────────┐
│                      GitHub Actions Pipeline                    │
│                                                                 │
│  Push/PR                                                        │
│    │                                                            │
│    ▼                                                            │
│  ┌─────────┐                                                   │
│  │  Lint   │ PHP CS Fixer, ESLint, Prettier                    │
│  └────┬────┘                                                   │
│       │ pass                                                    │
│    ┌──▼──────────┐                                             │
│    │  Unit Tests  │ PHPUnit (Domain + Application layer)       │
│    └──────┬───────┘                                            │
│           │ pass                                               │
│    ┌──────▼──────────────┐                                     │
│    │  Integration Tests   │ Feature tests with SQLite in-memory│
│    └──────┬──────────────┘                                     │
│           │ pass                                               │
│    ┌──────▼────────────┐                                       │
│    │  Security Scan    │ composer audit, npm audit, SAST       │
│    └──────┬────────────┘                                       │
│           │ pass (on main/develop)                             │
│    ┌──────▼────────────┐                                       │
│    │  Docker Build     │ Multi-stage build, push to ECR        │
│    └──────┬────────────┘                                       │
│           │                                                    │
│    ┌──────▼────────────┐                                       │
│    │  Deploy Staging   │ ECS/K8s rolling deploy                │
│    └──────┬────────────┘                                       │
│           │ manual approval                                    │
│    ┌──────▼────────────┐                                       │
│    │  Deploy Production│ Blue/green deployment                 │
│    └───────────────────┘                                       │
└─────────────────────────────────────────────────────────────────┘
```

### Key CI/CD Files

- `.github/workflows/ci.yml` — Lint + test on every push
- `.github/workflows/deploy-staging.yml` — Deploy on push to `develop`
- `.github/workflows/deploy-production.yml` — Deploy on push to `main` with approval gate
- `Dockerfile` — Multi-stage PHP build
- `docker-compose.yml` — Local development stack

### Deployment Checklist

1. Run `php artisan migrate --force` (zero-downtime with backward-compatible migrations)
2. Run `php artisan config:cache && php artisan route:cache && php artisan view:cache`
3. Clear old caches: `php artisan cache:clear`
4. Restart queue workers: `php artisan horizon:terminate`
5. Health check endpoint returns 200
6. Smoke tests pass

### Rollback Strategy

- Database migrations must be reversible (always write `down()` methods)
- Docker images tagged with git SHA — rollback = redeploy previous image
- Feature flags (via environment variables) for gradual rollout
