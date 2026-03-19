# Security Design — Kutubxona.uz

## 1. Authentication: JWT with Refresh Token Rotation

### Token Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      JWT ACCESS TOKEN                       │
│                                                             │
│  Header: { "alg": "RS256", "typ": "JWT" }                 │
│                                                             │
│  Payload:                                                   │
│  {                                                          │
│    "iss": "kutubxona.uz",                                  │
│    "sub": "user_ulid",           ← User identifier         │
│    "tid": 42,                    ← Tenant ID (critical!)   │
│    "jti": "unique_token_id",     ← For blacklisting        │
│    "roles": ["tenant_admin"],                              │
│    "iat": 1705313460,                                       │
│    "exp": 1705317060             ← 1 hour TTL              │
│  }                                                          │
│                                                             │
│  Signed with RS256 (asymmetric) — private key on server    │
└─────────────────────────────────────────────────────────────┘
```

### Token Lifecycle

```
Login → Access Token (1h) + Refresh Token (30d, httpOnly cookie or body)
     │
     ├── Access token used in Authorization: Bearer {token}
     │
     ├── On expiry (401 response) → POST /auth/refresh
     │       │
     │       ├── Validate refresh token (not expired, not blacklisted)
     │       ├── Rotate: invalidate old refresh token
     │       ├── Issue new access token + new refresh token
     │       └── Return new pair
     │
     └── On logout → Blacklist jti in Redis (TTL = remaining token lifetime)
```

### RS256 Key Rotation

- Private key stored in `.env` (or AWS Secrets Manager in production)
- Key rotation: generate new key pair, add `kid` (Key ID) to header
- Old tokens remain valid until expiry (grace period = 1h)
- Public key served via `GET /api/.well-known/jwks.json`

### Implementation (tymon/jwt-auth)

```php
// config/jwt.php
'ttl'             => env('JWT_TTL', 60),          // minutes
'refresh_ttl'     => env('JWT_REFRESH_TTL', 43200), // 30 days
'algo'            => env('JWT_ALGO', 'RS256'),
'blacklist_enabled' => true,
'blacklist_grace_period' => 0,

// Custom claims added in AuthService:
JWTAuth::customClaims(['tid' => $user->tenant_id])
       ->fromUser($user);
```

---

## 2. Authorization: RBAC with Spatie Permissions

### Role Hierarchy

```
super_admin
    │ (manages everything, no tenant scope)
    ▼
tenant_admin
    │ (manages own tenant: users, books, settings)
    ▼
tenant_manager
    │ (manages content: books, audiobooks, authors)
    ▼
user
    │ (reads, downloads, bookmarks, reviews)
    ▼
guest (unauthenticated)
    (reads public catalog only)
```

### Permission Matrix

| Permission | super_admin | tenant_admin | tenant_manager | user |
|------------|:-----------:|:------------:|:--------------:|:----:|
| tenants.view | ✓ | own | — | — |
| tenants.create | ✓ | — | — | — |
| tenants.update | ✓ | own | — | — |
| tenants.delete | ✓ | — | — | — |
| tenants.suspend | ✓ | — | — | — |
| users.view | ✓ | own tenant | — | own |
| users.create | ✓ | own tenant | — | — |
| users.update | ✓ | own tenant | — | own |
| users.delete | ✓ | own tenant | — | — |
| books.viewAny | ✓ | ✓ | ✓ | ✓ |
| books.view | ✓ | ✓ | ✓ | ✓ |
| books.create | ✓ | ✓ | ✓ | — |
| books.update | ✓ | ✓ | ✓ | — |
| books.delete | ✓ | ✓ | — | — |
| books.download | ✓ | ✓ | ✓ | ✓ |
| books.publish | ✓ | ✓ | ✓ | — |
| analytics.view | ✓ | own tenant | — | — |
| reviews.approve | ✓ | ✓ | ✓ | — |

### Multi-Tenant Roles

Spatie Permissions is configured with `teams` enabled, where `team_id = tenant_id`. This ensures:
- A `tenant_admin` for tenant #42 is **not** a `tenant_admin` for tenant #99
- Role assignments are strictly scoped to the tenant

```php
// Assigning a role within a tenant context
setPermissionsTeamId($tenant->id);
$user->assignRole('tenant_admin');

// Checking permissions in middleware/controllers
$user->hasRole('tenant_admin') // Only true for current tenant
```

### Policy Enforcement Pattern

```php
// BookPolicy.php — always checks tenant_id equality
public function update(User $user, Book $book): bool
{
    // 1. Super admin can update any book
    if ($user->hasRole('super_admin')) {
        return true;
    }

    // 2. Must be same tenant (prevents cross-tenant access even if URL is guessed)
    if ($user->tenant_id !== $book->tenant_id) {
        return false;
    }

    // 3. Must have appropriate role
    return $user->hasAnyRole(['tenant_admin', 'tenant_manager']);
}
```

---

## 3. Multi-Tenant Data Isolation Enforcement

### Defense in Depth Layers

```
Request
  │
  ├── [L1] TenantMiddleware: Resolves and sets tenant context
  │         - Rejects if tenant not found, suspended, cancelled
  │
  ├── [L2] AuthMiddleware: Validates JWT, checks tid claim = current tenant
  │         - Rejects if token's tid doesn't match resolved tenant
  │
  ├── [L3] TenantScope (Eloquent Global Scope): Filters all queries
  │         - Book::all() → SELECT * FROM books WHERE tenant_id = 42
  │
  ├── [L4] Policy: Checks user.tenant_id === resource.tenant_id
  │         - Even if global scope is somehow bypassed
  │
  └── [L5] Audit log: All write operations logged with tenant context
```

### JWT Tenant Claim Validation

```php
// In TenantMiddleware, after resolving tenant from domain:
$token = JWTAuth::parseToken()->getPayload();
$tokenTenantId = $token->get('tid');

if ($tokenTenantId && $tokenTenantId !== $resolvedTenant->id) {
    return response()->json([
        'success' => false,
        'message' => 'Token tenant mismatch'
    ], 403);
}
```

---

## 4. API Rate Limiting Per Tenant

### Implementation with Redis

```php
// routes/api.php
RateLimiter::for('api', function (Request $request) {
    $tenant = app()->bound('tenant') ? app('tenant') : null;
    $tenantId = $tenant?->id ?? 'anonymous';

    return Limit::perMinute(
        $tenant?->plan?->api_rate_limit ?? 120
    )->by(sprintf('tenant:%s:user:%s', $tenantId, $request->user()?->id ?? $request->ip()));
});

RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(5)->by($request->ip());
});

RateLimiter::for('downloads', function (Request $request) {
    return Limit::perHour(10)->by(
        sprintf('tenant:%s:download:user:%s', app('tenant')?->id, $request->user()?->id)
    );
});
```

### Rate Limit Headers

All responses include:
```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1705313520
```

---

## 5. File Upload Security

### MIME Type Validation

```php
// CreateBookRequest.php
'book_file' => [
    'required',
    'file',
    'max:102400', // 100MB max
    function (string $attribute, mixed $value, \Closure $fail) {
        $allowedMimes = ['application/pdf', 'application/epub+zip', 'image/vnd.djvu'];
        $detectedMime = mime_content_type($value->getRealPath());

        if (!in_array($detectedMime, $allowedMimes, true)) {
            $fail("File type not allowed. Detected: {$detectedMime}");
        }

        // Double-check file extension
        $extension = strtolower($value->getClientOriginalExtension());
        if (!in_array($extension, ['pdf', 'epub', 'djvu', 'mobi', 'fb2'], true)) {
            $fail("File extension not allowed.");
        }
    },
],
'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
```

### Virus Scanning Hook

```php
// app/Jobs/ProcessBookFile.php
private function scanForViruses(string $tempPath): void
{
    if (!config('storage.virus_scan_enabled')) {
        return;
    }

    // ClamAV via exec (or cloud service like VirusTotal API)
    $output = shell_exec(sprintf('clamscan --no-summary %s', escapeshellarg($tempPath)));
    if (str_contains($output, 'FOUND')) {
        // Log, alert, delete file
        throw new SecurityException("Virus detected in uploaded file");
    }
}
```

### File Size Limits Per Plan

```php
$plan = app('tenant')->plan;
$maxFileSizeMb = $plan->settings['max_book_size_mb'] ?? 100;
```

### Double Extension Attack Prevention

```php
$filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
$extension = $file->guessExtension() ?? 'bin';
$safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
$storedName = $safeFilename . '_' . uniqid() . '.' . $extension;
```

---

## 6. SQL Injection Prevention

Laravel's Eloquent ORM uses PDO prepared statements for all queries. Raw query protection:

```php
// NEVER do this:
DB::select("SELECT * FROM books WHERE title = '$title'");

// Always use bindings:
DB::select("SELECT * FROM books WHERE tenant_id = ? AND title = ?", [$tenantId, $title]);

// Or better, use Eloquent:
Book::where('tenant_id', $tenantId)->where('title', $title)->get();

// For search (FULLTEXT):
Book::whereFullText(['title', 'description'], $query)->get();
// Laravel escapes the search query automatically
```

Validation rules prevent malicious input at the boundary:
```php
'search' => ['nullable', 'string', 'max:255', 'regex:/^[\p{L}\p{N}\s\-]+$/u'],
```

---

## 7. XSS Prevention

### Output Encoding

All user-generated content is HTML-encoded before storage and escaped on output:

```php
// In resources/Book/BookResource.php
'description' => e($this->description), // HTML encode for JSON API
// Angular's template engine auto-escapes {{ interpolations }}
```

### Content Security Policy Header

```nginx
# nginx.conf
add_header Content-Security-Policy "
    default-src 'self';
    script-src 'self' 'nonce-{NONCE}' cdn.jsdelivr.net;
    style-src 'self' 'unsafe-inline' fonts.googleapis.com;
    img-src 'self' data: *.amazonaws.com cdn.kutubxona.uz;
    font-src 'self' fonts.gstatic.com;
    connect-src 'self' api.kutubxona.uz *.amazonaws.com;
    frame-src 'none';
    object-src 'none';
" always;
```

---

## 8. CORS Configuration

```php
// config/cors.php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter([
        env('FRONTEND_URL'),        // Primary Angular app
        env('FRONTEND_URL_ALT'),    // Alt URL
    ]),
    'allowed_origins_patterns' => [
        '/^https:\/\/[a-z0-9\-]+\.kutubxona\.uz$/',  // All subdomains
    ],
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-Tenant-ID',
        'Accept',
        'X-CSRF-Token',
    ],
    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
    ],
    'max_age' => 86400,           // 24 hours preflight cache
    'supports_credentials' => true,
];
```

---

## 9. Encryption at Rest and in Transit

### In Transit (TLS)

- All traffic over HTTPS (TLS 1.2 minimum, TLS 1.3 preferred)
- HTTP → HTTPS redirect enforced at nginx level
- HSTS header: `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`

### At Rest

```php
// Sensitive fields encrypted in database
protected $casts = [
    'two_factor_secret' => 'encrypted',  // Laravel's Crypt facade
    'database_config'   => 'encrypted',  // Premium tenant DB credentials
];

// Laravel uses AES-256-CBC with APP_KEY (32-byte base64 key)
// APP_KEY must be stored securely (AWS Secrets Manager, Vault)
```

### S3 Server-Side Encryption

```php
// StorageService.php
Storage::disk('s3')->put($key, $content, [
    'ServerSideEncryption' => 'AES256', // or 'aws:kms'
    'visibility' => 'private',          // Never public
]);
```

---

## 10. Signed URLs for S3 File Access

```php
// app/Application/Services/StorageService.php

public function getSignedUrl(string $s3Key, int $expirySeconds = 3600): string
{
    return Storage::disk('s3')->temporaryUrl(
        $s3Key,
        now()->addSeconds($expirySeconds),
        [
            'ResponseContentDisposition' => 'attachment; filename="' . basename($s3Key) . '"',
            'ResponseContentType' => 'application/pdf',
        ]
    );
}

public function getStreamingUrl(string $s3Key): string
{
    // Short-lived URL for streaming (PDF.js, audio player)
    return Storage::disk('s3')->temporaryUrl($s3Key, now()->addMinutes(15));
}
```

Files are **never** publicly accessible. Even "public" books require a signed URL generated after authorization check.

---

## 11. Audit Logging

### Events Logged

| Action | Details Captured |
|--------|-----------------|
| Login success | user_id, ip, user_agent, timestamp |
| Login failure | email attempted, ip, timestamp |
| Password change | user_id, ip, timestamp |
| Role assignment | admin_id, target_user_id, role, timestamp |
| Tenant creation | super_admin_id, tenant_id, timestamp |
| Tenant suspension | admin_id, tenant_id, reason, timestamp |
| Book creation | user_id, book_id, tenant_id, timestamp |
| Book deletion | user_id, book_id, tenant_id, timestamp |
| File download | user_id, book_id, file_id, ip, timestamp |
| Admin access | admin_id, action, resource, timestamp |

```php
// app/Infrastructure/Audit/AuditLogger.php

class AuditLogger
{
    public static function log(string $action, array $context = []): void
    {
        Log::channel('audit')->info($action, array_merge([
            'tenant_id' => app()->has('tenant') ? app('tenant')->id : null,
            'user_id'   => auth()->id(),
            'ip'        => request()->ip(),
            'user_agent'=> request()->userAgent(),
            'request_id'=> request()->header('X-Request-ID'),
        ], $context));
    }
}

// Usage:
AuditLogger::log('book.deleted', ['book_id' => $book->id, 'reason' => $reason]);
```

---

## 12. Brute Force Protection

### Multi-Layer Protection

```php
// 1. Rate Limiting (see section 4)
// 5 login attempts per minute per IP

// 2. Progressive delays (via Redis counters)
$attempts = Cache::increment("login_attempts:{$ip}");
if ($attempts > 3) {
    // Artificial delay: 2^(attempts-3) seconds, max 32s
    sleep(min(pow(2, $attempts - 3), 32));
}

// 3. Account lockout after 10 failed attempts
if ($user->failed_login_attempts >= 10) {
    $user->update(['status' => 'locked', 'locked_until' => now()->addHour()]);
    // Send unlock email
}

// 4. CAPTCHA trigger after 3 failed attempts from same IP
// Frontend shows Google reCAPTCHA v3, backend verifies score

// 5. Suspicious IP blocking (manual via admin panel or auto via fail2ban)
```

---

## 13. OWASP Top 10 Mitigations

| OWASP Risk | Mitigation |
|------------|------------|
| **A01: Broken Access Control** | RBAC via Spatie, Policies on every resource, tenant scope on every query, tests verify isolation |
| **A02: Cryptographic Failures** | TLS 1.3, AES-256 at rest, RS256 JWT, no MD5/SHA1, bcrypt passwords |
| **A03: Injection** | Eloquent ORM with PDO bindings, validated/sanitized input, no raw SQL with user input |
| **A04: Insecure Design** | Clean Architecture, defense in depth, threat modeling |
| **A05: Security Misconfiguration** | Docker secrets management, ENV validation on startup, security headers, CSP |
| **A06: Vulnerable Components** | Automated `composer audit` in CI, Dependabot PRs, monthly update cycle |
| **A07: Auth Failures** | JWT + refresh rotation, rate limiting, brute force protection, bcrypt(12) |
| **A08: Software Integrity Failures** | Signed Docker images, locked composer.lock, SBOM generation |
| **A09: Logging Failures** | Structured audit logs, Sentry alerts, log integrity monitoring |
| **A10: SSRF** | Outbound HTTP via allowlist, no user-controlled URLs for internal fetches |

### Additional Security Headers (nginx)

```nginx
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
```
