# Scalability Architecture — Kutubxona.uz

## 1. Horizontal Scaling Strategy

```
                           ┌─────────────────┐
                           │   Load Balancer  │
                           │  (AWS ALB / Nginx)│
                           └────────┬────────┘
                                    │
               ┌────────────────────┼────────────────────┐
               │                    │                    │
        ┌──────▼──────┐      ┌──────▼──────┐    ┌──────▼──────┐
        │  App Server  │      │  App Server  │    │  App Server  │
        │  (PHP-FPM)   │      │  (PHP-FPM)   │    │  (PHP-FPM)   │
        │  Nginx       │      │  Nginx       │    │  Nginx       │
        └──────┬───────┘      └──────┬───────┘    └──────┬───────┘
               │                    │                    │
               └────────────────────┼────────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    │               │               │
             ┌──────▼──────┐  ┌────▼─────┐  ┌─────▼──────┐
             │  MySQL       │  │  Redis   │  │  S3 / MinIO │
             │  Primary +   │  │  Cluster │  │  (Files)    │
             │  Read Replicas│  └──────────┘  └────────────┘
             └─────────────┘
                    │
             ┌──────▼──────────────────────────────┐
             │          Queue Workers               │
             │  ┌──────────┐  ┌──────────────────┐ │
             │  │  Horizon │  │  Separate queues: │ │
             │  │  Monitor │  │  emails, files,   │ │
             │  └──────────┘  │  analytics, notif │ │
             │                └──────────────────┘ │
             └─────────────────────────────────────┘
```

### Stateless Application Servers

- No local file storage (all files on S3)
- Sessions stored in Redis (not filesystem)
- Config cached (`php artisan config:cache`) — identical across all instances
- Shared queue via Redis — any worker can pick up any job

### Auto-Scaling

- **AWS ECS / Kubernetes**: Horizontal pod autoscaler based on CPU (target 70%) and RPS
- **Scale-out trigger**: CPU > 70% for 3 consecutive minutes OR queue depth > 500 jobs
- **Scale-in trigger**: CPU < 30% for 10 consecutive minutes
- **Minimum instances**: 2 (for HA), **Maximum**: 20
- **Cold start mitigation**: Warm minimum capacity, pre-loaded opcache

---

## 2. Redis Caching Layers

### Cache Architecture

```
Request
  │
  ├── [Hit] Redis Cache → Return cached response
  │
  └── [Miss] MySQL query → Store in Redis → Return response
```

### Cache Configuration

```php
// config/cache.php
'default' => 'redis',
'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ],
    'file' => [...],  // Fallback if Redis unavailable
],
```

### Cache Layers and TTLs

| Layer | What | TTL | Invalidation |
|-------|------|-----|--------------|
| **Query cache** | Book listings, author lists | 5–15 min | On write |
| **Object cache** | Individual book/author records | 30 min | On update/delete |
| **Aggregation cache** | Popular books, stats | 1 hour | Scheduled |
| **Search cache** | Search results by query+filters | 2 min | Time-based |
| **Session store** | User JWT blacklist, refresh tokens | Token TTL | Explicit |
| **Rate limiting** | Request counters per user/IP | 1 min windows | Time-based |
| **Config cache** | Tenant settings, feature flags | 10 min | On settings update |

### Cache Invalidation Strategy

```php
// Proactive invalidation on write:
public function updateBook(Book $book, array $data): Book
{
    $book->update($data);

    // Invalidate specific caches
    Cache::forget("tenant:{$book->tenant_id}:book:{$book->id}");
    Cache::forget("tenant:{$book->tenant_id}:books:popular");
    Cache::tags(["tenant:{$book->tenant_id}:books"])->flush();

    return $book->fresh();
}
```

### Redis Cluster Configuration

```
Redis Cluster: 3 master nodes + 3 replicas
- Node 1: Slots 0-5460 (cache + sessions)
- Node 2: Slots 5461-10922 (queues)
- Node 3: Slots 10923-16383 (rate limiting + locks)

Persistence: AOF (Append-Only File) with fsync everysec
Maxmemory: 8GB per node
Maxmemory-policy: allkeys-lru
```

---

## 3. Queue Workers: Queue Separation

### Queue Configuration

```php
// config/queue.php — multiple named queues via Horizon
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
    ],
],
```

### Queue Priority and Worker Allocation

| Queue | Priority | Workers | Jobs |
|-------|----------|---------|------|
| `critical` | 10 | 2 | Password reset emails, subscription alerts |
| `emails` | 8 | 3 | Welcome emails, notifications, digests |
| `file-processing` | 7 | 5 | Book/audio file processing, thumbnail gen |
| `analytics` | 3 | 2 | Event aggregation, report generation |
| `notifications` | 5 | 2 | Push notifications, in-app alerts |
| `default` | 1 | 3 | Everything else |

### Horizon Configuration

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'maxProcesses' => 5,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'queue' => ['critical', 'emails'],
        ],
        'supervisor-2' => [
            'maxProcesses' => 8,
            'queue' => ['file-processing'],
            'timeout' => 600,  // 10 min for large files
            'tries' => 3,
            'backoff' => [60, 300, 900],  // Retry: 1m, 5m, 15m
        ],
        'supervisor-3' => [
            'maxProcesses' => 4,
            'queue' => ['analytics', 'notifications', 'default'],
        ],
    ],
],
```

---

## 4. CDN Integration

### Asset Delivery

```
User → CDN (CloudFront) → Origin (S3 or App Server)
         │
         ├── Static assets: Angular build files (JS, CSS, images)
         │   Cache-Control: public, max-age=31536000, immutable
         │
         ├── Book cover images: /books/covers/*
         │   Cache-Control: public, max-age=86400 (24h)
         │
         ├── API responses: NOT cached at CDN (dynamic, tenant-specific)
         │
         └── Book files: Signed S3 URLs (bypass CDN, direct S3)
```

### CloudFront Distribution

```
Behaviors:
  /assets/*        → S3 static assets bucket (cache: 1 year)
  /books/covers/*  → S3 via CloudFront (cache: 24h)
  /api/*           → Pass-through to origin (no cache)
  /*               → Angular app (cache: 1h with stale-while-revalidate)
```

### Cache Invalidation

```bash
# After Angular build deployment:
aws cloudfront create-invalidation \
  --distribution-id $DISTRIBUTION_ID \
  --paths "/index.html" "/main.*.js" "/polyfills.*.js"
```

---

## 5. Database Read Replicas

### Architecture

```
                   ┌─────────────────┐
                   │   MySQL Primary  │ (writes)
                   └────────┬────────┘
                             │ replication (async, < 1s lag)
               ┌─────────────┼─────────────┐
        ┌──────▼──────┐ ┌──────▼──────┐ ┌──────▼──────┐
        │   Replica 1  │ │   Replica 2  │ │   Replica 3  │
        │   (reads)    │ │   (reads)    │ │   (analytics)│
        └─────────────┘ └─────────────┘ └──────────────┘
```

### Laravel Read/Write Connection Splitting

```php
// config/database.php
'mysql' => [
    'read' => [
        'host' => [
            env('DB_REPLICA_1_HOST', '127.0.0.1'),
            env('DB_REPLICA_2_HOST', '127.0.0.1'),
        ],
    ],
    'write' => [
        'host' => env('DB_HOST', '127.0.0.1'),
    ],
    'sticky' => true,  // After write, same connection for reads in same request
    // ... other settings
],
```

### Read/Write Routing Rules

- **Reads** (SELECT): Automatically routed to replicas
- **Writes** (INSERT/UPDATE/DELETE): Always go to primary
- **After write + `sticky: true`**: Reads in same request go to primary (prevents stale reads)
- **Analytics replica**: Dedicated replica for heavy aggregation queries, isolated from app traffic

---

## 6. Search: MySQL Full-Text vs Elasticsearch Migration Path

### Phase 1: MySQL Full-Text Search (Current)

```php
// Laravel Scout with database driver + custom implementation
Book::whereFullText(['title', 'description'], $query)
    ->where('tenant_id', $tenantId)
    ->where('status', 'published')
    ->orderByRaw("MATCH(title, description) AGAINST(? IN BOOLEAN MODE) DESC", [$query])
    ->paginate(20);
```

Suitable for:
- Up to ~500,000 books per tenant
- Simple keyword matching
- Single-language search (uz/ru/en separately)

### Phase 2: Elasticsearch Migration (When Needed)

**Trigger**: When any of these conditions are met:
- Search response time > 200ms at P95
- Need for advanced features (fuzzy matching, synonyms, multi-language stemming)
- Books corpus > 1M records across platform

**Migration Path (Zero Downtime):**

```
Step 1: Add Elasticsearch config alongside MySQL FTS
Step 2: Laravel Scout driver: 'elasticsearch'
Step 3: Dual-write: new books indexed in both MySQL FTS + ES
Step 4: Background job: index existing books to ES
Step 5: Read from ES (shadow mode, compare results)
Step 6: Promote ES as primary search
Step 7: Remove MySQL FTS queries
```

**Index Template:**
```json
{
  "settings": {
    "analysis": {
      "analyzer": {
        "uzbek_analyzer": {
          "tokenizer": "standard",
          "filter": ["lowercase", "stop", "uzbek_stemmer"]
        }
      }
    }
  },
  "mappings": {
    "properties": {
      "tenant_id": { "type": "keyword" },
      "title":     { "type": "text", "analyzer": "uzbek_analyzer", "boost": 3 },
      "description": { "type": "text", "analyzer": "uzbek_analyzer" },
      "author_name": { "type": "text", "boost": 2 },
      "tags":       { "type": "keyword" }
    }
  }
}
```

---

## 7. S3 Storage Structure

```
s3://kutubxona-files/
│
├── tenants/
│   └── {tenant_id}/
│       ├── books/
│       │   └── {book_id}/
│       │       ├── original_{hash}.pdf       ← Original uploaded file
│       │       ├── processed_{hash}.pdf      ← Optimized/linearized PDF
│       │       ├── original_{hash}.epub      ← If EPUB provided
│       │       ├── cover_original.jpg        ← Full-size cover
│       │       └── cover_thumb_300.jpg       ← Thumbnail (300px wide)
│       │
│       ├── audio/
│       │   └── {audiobook_id}/
│       │       ├── chapter_01_{hash}.mp3
│       │       ├── chapter_02_{hash}.mp3
│       │       └── cover_audio.jpg
│       │
│       ├── authors/
│       │   └── {author_id}/
│       │       └── photo.jpg
│       │
│       ├── publishers/
│       │   └── {publisher_id}/
│       │       └── logo.png
│       │
│       ├── users/
│       │   └── {user_id}/
│       │       └── avatar.jpg
│       │
│       └── temp/                             ← Cleaned up by lifecycle rule (24h TTL)
│           └── upload_{uuid}.pdf
│
└── platform/
    ├── exports/                              ← Data exports (GDPR, reports)
    ├── backups/                              ← Database backups
    └── logs/                                ← Archived logs
```

### S3 Lifecycle Rules

```json
[
  {
    "ID": "cleanup-temp",
    "Filter": { "Prefix": "tenants/*/temp/" },
    "Expiration": { "Days": 1 }
  },
  {
    "ID": "archive-old-exports",
    "Filter": { "Prefix": "platform/exports/" },
    "Transitions": [
      { "Days": 30, "StorageClass": "STANDARD_IA" },
      { "Days": 90, "StorageClass": "GLACIER" }
    ],
    "Expiration": { "Days": 365 }
  }
]
```

---

## 8. Background Jobs: File Processing Pipeline

### Book File Processing Pipeline

```
Job: ProcessBookFile (queue: file-processing, timeout: 600s)
  │
  ├── Step 1: Download from temp S3 path to local /tmp/
  │
  ├── Step 2: Validate file integrity
  │   └── Verify MD5 checksum matches uploaded checksum
  │
  ├── Step 3: Extract metadata
  │   ├── PDF: pdfinfo (poppler) → pages, title, author
  │   └── EPUB: extract OPF metadata
  │
  ├── Step 4: Optimize PDF (optional)
  │   └── qpdf --linearize (for web streaming / fast first page)
  │
  ├── Step 5: Generate cover thumbnail
  │   ├── PDF: extract first page with ImageMagick/GhostScript
  │   └── Resize to 300px wide, save as WebP + JPG
  │
  ├── Step 6: Upload processed files to S3
  │   ├── tenants/{id}/books/{id}/processed_{hash}.pdf
  │   └── tenants/{id}/books/{id}/cover_thumb_300.webp
  │
  ├── Step 7: Update book_files record
  │   ├── processing_status = 'ready'
  │   ├── metadata = { pages, title, toc }
  │   ├── s3_key = final S3 path
  │   └── file_size = actual size
  │
  ├── Step 8: Update book record
  │   ├── status = 'published'
  │   ├── pages = extracted page count
  │   └── cover_thumbnail = S3 URL
  │
  └── Step 9: Clean up local /tmp/ file
```

### Audiobook Processing Pipeline

```
Job: ProcessAudioBook (queue: file-processing)
  │
  ├── Download chapter files from temp S3
  ├── Validate audio format (MP3, AAC, OGG)
  ├── Extract audio metadata (ffprobe): duration, bitrate, sample rate
  ├── Generate waveform data (audiowaveform) → JSON points array
  ├── Upload to final S3 path
  └── Update audiobook_chapters record
```

### Analytics Aggregation

```
Job: AggregateAnalytics (queue: analytics, scheduled: every 15 min)
  │
  ├── Query analytics_events for past 15 min
  ├── Aggregate: top books, download counts, user counts by tenant
  ├── Store aggregates in Redis (sorted sets for top-N queries)
  └── Update daily summary in separate aggregation table
```

---

## 9. Performance Benchmark Targets

| Metric | Target | Notes |
|--------|--------|-------|
| **API response time (P50)** | < 50ms | Cached responses |
| **API response time (P95)** | < 200ms | With DB queries |
| **API response time (P99)** | < 500ms | Complex queries |
| **Search response (P95)** | < 300ms | With MySQL FTS |
| **Book listing (1000 books, paginated)** | < 100ms | With cache |
| **File download URL generation** | < 50ms | S3 presign |
| **Login endpoint** | < 200ms | Including bcrypt |
| **Queue job: book processing** | < 5 min | Large PDFs |
| **Concurrent users per server** | 500 | PHP-FPM with 100 processes |
| **Database queries per request** | < 10 | Eager loading, N+1 prevention |

### N+1 Query Prevention

```php
// Always eager load relationships
Book::with(['author', 'publisher', 'categories', 'tags'])
    ->where('tenant_id', $tenantId)
    ->paginate(20);

// Use Laravel Debugbar in dev to detect N+1
// Use Telescope to profile queries in staging
```

---

## 10. Load Testing Recommendations

### Tool: K6 or Locust

```javascript
// k6 test scenario
import http from 'k6/http';

export const options = {
  stages: [
    { duration: '2m', target: 50 },   // Ramp up to 50 users
    { duration: '5m', target: 100 },  // Hold at 100 users
    { duration: '2m', target: 500 },  // Spike to 500 users
    { duration: '5m', target: 500 },  // Hold spike
    { duration: '2m', target: 0 },    // Ramp down
  ],
  thresholds: {
    http_req_duration: ['p(95)<200', 'p(99)<500'],
    http_req_failed: ['rate<0.01'],  // < 1% error rate
  },
};

export default function () {
  // Simulate tenant user browsing
  const headers = { 'Authorization': `Bearer ${__ENV.TEST_TOKEN}` };

  http.get('http://api.kutubxona.uz/api/v1/books', { headers });
  sleep(Math.random() * 2);

  http.get(`http://api.kutubxona.uz/api/v1/books/${Math.floor(Math.random() * 1000)}`, { headers });
  sleep(Math.random() * 3);
}
```

### Load Test Scenarios

1. **Normal load**: 50 concurrent users, 100 RPS — baseline performance
2. **Peak load**: 500 concurrent users — expected max production traffic
3. **Spike test**: 0 → 1000 users in 30 seconds — autoscaling trigger test
4. **Soak test**: 200 users for 4 hours — memory leak detection
5. **File upload stress**: 20 concurrent large PDF uploads

---

## 11. Auto-Scaling Triggers (AWS ECS / Kubernetes)

### ECS Auto Scaling Policies

```json
{
  "ScalingPolicies": [
    {
      "PolicyName": "cpu-scale-out",
      "TargetTrackingScaling": {
        "TargetValue": 70.0,
        "PredefinedMetricType": "ECSServiceAverageCPUUtilization",
        "ScaleOutCooldown": 180,
        "ScaleInCooldown": 600
      }
    },
    {
      "PolicyName": "alb-requests-scale-out",
      "TargetTrackingScaling": {
        "TargetValue": 200,
        "PredefinedMetricType": "ALBRequestCountPerTarget",
        "ScaleOutCooldown": 60,
        "ScaleInCooldown": 300
      }
    }
  ],
  "MinCapacity": 2,
  "MaxCapacity": 20
}
```

### Queue Worker Auto-Scaling

```bash
# Scale based on Redis queue depth (custom metric via CloudWatch)
aws cloudwatch put-metric-alarm \
  --alarm-name "HorizonQueueDepth" \
  --metric-name "QueueDepth" \
  --namespace "Kutubxona/Horizon" \
  --comparison-operator GreaterThanThreshold \
  --threshold 500 \
  --evaluation-periods 2 \
  --alarm-actions arn:aws:autoscaling:...
```
