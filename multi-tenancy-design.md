# Multi-Tenancy Design — Kutubxona.uz

## 1. Strategy Comparison

| Criteria | Single DB + `tenant_id` | Separate DB per Tenant | Separate Schema per Tenant |
|----------|------------------------|----------------------|---------------------------|
| **Cost at scale** | Very low (shared infra) | Very high (N databases) | Medium (schema overhead) |
| **Isolation level** | Logical (app-enforced) | Physical (DB-enforced) | Logical (schema-enforced) |
| **Data breach risk** | Medium (bug risk) | Very low | Low |
| **Cross-tenant queries** | Easy (JOIN on tenant_id) | Impossible without federation | Complex |
| **Scaling to 1000+ tenants** | Excellent | Impractical | Difficult |
| **Database connections** | Single pool | N pools | N pools |
| **Migration complexity** | Run once | N times | N times |
| **Backup granularity** | Full DB backup | Per-tenant backup | Per-schema backup |
| **Tenant-specific customization** | Limited (JSON config) | Full schema flexibility | Some flexibility |
| **Setup complexity** | Low | Very high | High |
| **Compliance (GDPR)** | Export by tenant_id | Drop entire DB | Drop schema |
| **Performance** | Good with proper indexes | Excellent isolation | Good |
| **Connection pooling** | Optimal (shared pool) | Poor (N connections) | Poor |

---

## 2. Decision: Single Database with `tenant_id` Column

### Chosen Strategy: **Single MySQL Database, Logical Isolation via `tenant_id`**

**Justification:**

1. **Scale target**: Kutubxona.uz is designed for **thousands of tenants** (schools, libraries). At 5,000 tenants, a separate-DB approach would require 5,000 MySQL databases — operationally impossible.

2. **Cost efficiency**: A single well-tuned MySQL instance (or cluster) with proper indexing supports thousands of tenants at a fraction of the cost.

3. **Migration simplicity**: One schema migration run propagates to all tenants simultaneously. With separate DBs, you'd need distributed migration runners and risk partial failures.

4. **Laravel Scout / Full-text search**: Works transparently with tenant_id filtering. Cross-tenant search (platform-level analytics) remains possible.

5. **Connection pooling**: PHP-FPM with a single connection pool (70 connections) handles thousands of concurrent requests. N-database strategy would exhaust connections.

6. **The risk of logical isolation is mitigated by**:
   - Laravel Global Scopes applied at model level
   - Middleware that sets and validates current tenant
   - Policies checked at controller level
   - Database indexes on all `(tenant_id, ...)` composite keys
   - Automated test suite verifying tenant isolation

**Premium tenant option**: Tier-3 enterprise tenants can optionally get a dedicated database connection, routed transparently by the `TenantConnectionManager`. The application code does not change — only the connection resolver differs.

---

## 3. Tenant Detection Flow

```
Incoming Request
     │
     ▼
┌─────────────────────────────────────────────────────────┐
│                    DETECTION CHAIN                       │
│                                                         │
│  Step 1: Check X-Tenant-ID header                       │
│          (for mobile apps, Postman, API clients)        │
│                  │                                       │
│                  │ not found                             │
│                  ▼                                       │
│  Step 2: Check JWT claim "tid" (tenant_id)              │
│          (for authenticated API requests)               │
│                  │                                       │
│                  │ not found                             │
│                  ▼                                       │
│  Step 3: Check subdomain                                │
│          library1.kutubxona.uz → slug=library1          │
│          Lookup tenant_domains WHERE domain = host      │
│                  │                                       │
│                  │ not found                             │
│                  ▼                                       │
│  Step 4: Check full custom domain                       │
│          my-library.school.edu → exact domain match     │
│                  │                                       │
│                  │ not found                             │
│                  ▼                                       │
│  Return 404 {"success":false,"message":"Tenant not found"}│
└─────────────────────────────────────────────────────────┘
     │
     │ found
     ▼
Check tenant.status
     │
     ├── 'active'    → proceed
     ├── 'suspended' → 403 {"message":"Account suspended"}
     ├── 'pending'   → 403 {"message":"Account pending activation"}
     └── 'cancelled' → 410 {"message":"Account cancelled"}
     │
     ▼
Set app(tenant) binding
Set DB global scope (TenantScope)
Set Redis key prefix (tenant:{id}:)
```

---

## 4. TenantMiddleware Implementation Plan

```php
// app/Interfaces/Http/Middleware/TenantMiddleware.php

class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveTenant($request);

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
            ], 404);
        }

        $this->assertTenantIsActive($tenant);

        // Bind to IoC container (available via app('tenant'))
        app()->instance('tenant', $tenant);

        // Set DB connection for premium tenants
        if ($tenant->hasDedicatedConnection()) {
            TenantConnectionManager::switch($tenant);
        }

        return $next($request);
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        // Priority order: header > JWT claim > subdomain > custom domain
        return $this->fromHeader($request)
            ?? $this->fromJwt($request)
            ?? $this->fromSubdomain($request)
            ?? $this->fromCustomDomain($request);
    }

    private function fromHeader(Request $request): ?Tenant
    {
        $tenantId = $request->header('X-Tenant-ID');
        if (!$tenantId) return null;
        return TenantRepository::findById((int) $tenantId);
    }

    private function fromJwt(Request $request): ?Tenant
    {
        try {
            $token = JWTAuth::parseToken()->getPayload();
            $tenantId = $token->get('tid');
            return $tenantId ? TenantRepository::findById((int) $tenantId) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fromSubdomain(Request $request): ?Tenant
    {
        $host = $request->getHost();
        $baseDomain = config('app.base_domain', 'kutubxona.uz');
        if (!str_ends_with($host, '.' . $baseDomain)) return null;
        $slug = str_replace('.' . $baseDomain, '', $host);
        return TenantRepository::findBySlug($slug);
    }

    private function fromCustomDomain(Request $request): ?Tenant
    {
        $host = $request->getHost();
        $domain = TenantDomain::where('domain', $host)->first();
        return $domain?->tenant;
    }
}
```

---

## 5. TenantScope / GlobalScope Strategy

### Global Scope Applied to All Tenant Models

```php
// app/Domain/Shared/Scopes/TenantScope.php

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->has('tenant')) {
            $tenant = app('tenant');
            $builder->where($model->getTable() . '.tenant_id', $tenant->id);
        }
    }
}
```

### Base Tenant Model (all domain models extend this)

```php
// app/Domain/Shared/Models/TenantModel.php

abstract class TenantModel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (self $model) {
            if (!$model->tenant_id && app()->has('tenant')) {
                $model->tenant_id = app('tenant')->id;
            }
        });
    }
}
```

### Opting Out of Tenant Scope (Super Admin only)

```php
// In SuperAdmin controllers:
$allBooks = Book::withoutGlobalScope(TenantScope::class)->get();

// Or via a dedicated scope:
Book::allTenants()->where('status', 'published')->count();
```

### Validation in Tests

```php
// Ensure TenantScope works:
test('user cannot see other tenant books', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();
    $book = Book::factory()->for($tenant2)->create();

    actingAsTenantUser($tenant1);

    get('/api/v1/books/' . $book->id)
        ->assertStatus(404); // Not found due to tenant scope
});
```

---

## 6. Database Connection Switching for Premium Tenants

```php
// app/Infrastructure/Tenancy/TenantConnectionManager.php

class TenantConnectionManager
{
    public static function switch(Tenant $tenant): void
    {
        $config = $tenant->getDatabaseConfig();
        if (!$config) return; // Fall back to shared DB

        config([
            'database.connections.tenant' => [
                'driver'   => 'mysql',
                'host'     => $config['host'],
                'database' => $config['database'],
                'username' => $config['username'],
                'password' => decrypt($config['password']),
                'charset'  => 'utf8mb4',
                'collation'=> 'utf8mb4_unicode_ci',
            ]
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');
        DB::setDefaultConnection('tenant');
    }

    public static function reset(): void
    {
        DB::setDefaultConnection(config('database.default'));
    }
}
```

**Premium tier flow:**
1. `tenants.settings` JSON contains `{"dedicated_db": {"host": "...", "database": "...", "username": "...", "password": "encrypted:..."}}`
2. `TenantMiddleware` calls `TenantConnectionManager::switch($tenant)` if config exists
3. After request: `TenantConnectionManager::reset()` in middleware's `terminate()` method

---

## 7. Tenant Onboarding Flow

```
SuperAdmin creates tenant via POST /api/v1/super-admin/tenants
          │
          ▼
     TenantService::createTenant($dto)
          │
          ├── Create Tenant record (status = 'pending')
          ├── Create primary TenantDomain (slug.kutubxona.uz)
          ├── Create tenant admin User
          ├── Assign 'tenant_admin' role (team = tenant_id)
          ├── Create trial Subscription (14 days)
          ├── Fire event(new TenantCreated($tenant))
          │       │
          │       ├── Listener: SetupTenantStorage
          │       │     - Create S3 prefix: tenants/{tenant_id}/
          │       │     - Create sub-prefixes: books/, audio/, images/
          │       │     - Set S3 bucket policy for tenant prefix
          │       │
          │       └── Listener: SendTenantWelcomeEmail (queued)
          │             - Welcome email with login URL
          │             - Getting started guide link
          │
          ├── Update tenant status to 'active'
          └── Return TenantResource
```

### Self-Service Onboarding (Future)

1. Public registration form at `kutubxona.uz/register`
2. Email verification
3. Plan selection (free trial available)
4. Subdomain input + validation
5. Automated provisioning (same flow as above)
6. Redirect to `{slug}.kutubxona.uz` with onboarding wizard

---

## 8. Data Isolation Guarantee Mechanisms

### Layer 1: Database (Indexes + Constraints)

All tenant tables have `tenant_id BIGINT UNSIGNED NOT NULL` with a FK to `tenants.id`. No tenant data can exist without a valid tenant reference.

### Layer 2: ORM Global Scope

`TenantScope` applied via `TenantModel::booted()` ensures every Eloquent query automatically includes `WHERE tenant_id = {current_tenant_id}`.

### Layer 3: Application Services

Services receive the current tenant from the IoC container and explicitly scope operations:

```php
public function getBooks(int $page): LengthAwarePaginator
{
    $tenant = app('tenant'); // Always explicit
    return $this->bookRepository->findByTenant($tenant->id, ...);
}
```

### Layer 4: Policies

Laravel Policies verify ownership before any write operation:

```php
public function update(User $user, Book $book): bool
{
    return $user->tenant_id === $book->tenant_id // Cross-tenant protection
        && ($user->hasRole('tenant_admin') || $user->hasRole('tenant_manager'));
}
```

### Layer 5: Route Middleware Stack

All tenant API routes are wrapped in `['auth:api', 'tenant', 'tenant.scope']` middleware — if any fails, request is rejected before reaching business logic.

### Layer 6: Automated Testing

Integration tests run for every PR:
- Test that `tenant_1` user cannot read `tenant_2` data
- Test that cross-tenant book ID references are rejected
- Test that admin operations are scoped to caller's tenant

---

## 9. Storage Isolation in S3

### Bucket Structure

```
s3://kutubxona-files/
└── tenants/
    └── {tenant_id}/               ← tenant root (e.g., 42/)
        ├── books/
        │   └── {book_id}/
        │       ├── original.pdf
        │       ├── processed.pdf
        │       └── cover.jpg
        ├── audio/
        │   └── {audiobook_id}/
        │       ├── chapter_01.mp3
        │       └── chapter_02.mp3
        ├── images/
        │   ├── authors/
        │   └── publishers/
        └── temp/                  ← cleaned up by lifecycle rule after 24h
```

### S3 Bucket Policy (IAM Role per Tenant)

For enterprise tenants requiring strict isolation, a dedicated IAM role with path-based policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::kutubxona-files/tenants/42/*"
    }
  ]
}
```

### Signed URLs for Access Control

File access never goes through a public S3 URL. Every file request:
1. User requests file via `GET /api/v1/books/{id}/download`
2. `BookController` validates access via `BookPolicy::download()`
3. `StorageService::getSignedUrl($s3Key, 3600)` generates a 1-hour signed URL
4. Response redirects user to signed URL
5. User downloads directly from S3 (no bandwidth through app server)

---

## 10. Cache Isolation with Redis Key Namespacing

### Key Pattern

All Redis keys include the tenant ID as a namespace prefix:

```
tenant:{tenant_id}:{domain}:{key}
```

Examples:
```
tenant:42:books:list:page:1              ← Book listing cache
tenant:42:books:popular:top10            ← Popular books
tenant:42:search:q:laravel:page:1        ← Search results
tenant:42:user:1001:reading_progress     ← User-specific data
tenant:42:stats:daily:2024-01-15         ← Analytics aggregation
```

### Implementation

```php
// app/Infrastructure/Cache/TenantCacheManager.php

class TenantCacheManager
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $tenantId
    ) {}

    public function key(string ...$parts): string
    {
        return sprintf('tenant:%s:%s', $this->tenantId, implode(':', $parts));
    }

    public function remember(string $key, int $ttl, \Closure $callback): mixed
    {
        $fullKey = $this->key($key);
        return Cache::remember($fullKey, $ttl, $callback);
    }

    public function flush(string $pattern = '*'): void
    {
        // Flush only this tenant's keys
        $keys = $this->redis->keys($this->key($pattern));
        if ($keys) {
            $this->redis->del($keys);
        }
    }
}
```

### Cache TTL Strategy

| Data Type | TTL | Reason |
|-----------|-----|--------|
| Book listings | 5 minutes | Updated frequently |
| Book detail | 15 minutes | Changes less often |
| Popular books | 1 hour | Stable over time |
| Author/Publisher | 30 minutes | Rarely changes |
| Search results | 2 minutes | Dynamic |
| User reading progress | No cache | Always fresh |
| Analytics aggregations | 1 hour | Pre-computed |
| Tenant settings | 10 minutes | Config changes infrequently |

### Session Isolation

Sessions (JWT blacklist, refresh tokens) stored as:
```
session:{token_jti}                     ← JWT blacklist
refresh:{user_id}:{device_id}          ← Refresh token store
rate_limit:tenant:{id}:ip:{ip}         ← Rate limiting counter
```
