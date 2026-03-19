# API Endpoints Reference — Kutubxona.uz

## API Versioning Strategy

All endpoints are prefixed with `/api/v1/`. When breaking changes are required, a new version `/api/v2/` is introduced while `/api/v1/` remains operational for a deprecation period (minimum 6 months).

### Standard Response Format

```json
{
  "success": true,
  "data": { ... },
  "message": "Operation successful",
  "meta": {
    "request_id": "req_01HXYZ123",
    "timestamp": "2024-01-15T10:30:00Z",
    "version": "v1"
  }
}
```

### Paginated Response Format

```json
{
  "success": true,
  "data": [ ... ],
  "message": "Books retrieved",
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 500,
    "last_page": 25,
    "from": 1,
    "to": 20,
    "links": {
      "first": "/api/v1/books?page=1",
      "prev": null,
      "next": "/api/v1/books?page=2",
      "last": "/api/v1/books?page=25"
    }
  }
}
```

### Error Response Format

```json
{
  "success": false,
  "data": null,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
  },
  "meta": {
    "request_id": "req_01HXYZ123",
    "timestamp": "2024-01-15T10:30:00Z"
  }
}
```

---

## Rate Limiting Rules

| Route Group | Limit | Window | Per |
|-------------|-------|--------|-----|
| `auth/*` (login, register) | 5 | 1 minute | IP |
| `forgot-password` | 3 | 15 minutes | IP |
| `api/v1/*` (authenticated) | 120 | 1 minute | User |
| `api/v1/*` (unauthenticated) | 30 | 1 minute | IP |
| `books/*/download` | 10 | 1 hour | User |
| `books/*/stream` | 20 | 1 hour | User |
| `search` | 60 | 1 minute | User |
| `super-admin/*` | 300 | 1 minute | User |

Responses include headers:
```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1705313460
Retry-After: 30  (only when rate limited)
```

---

## 1. Authentication

Base path: `/api/v1/auth`

### POST `/api/v1/auth/register`

| Property | Value |
|----------|-------|
| Auth | Not required |
| Rate Limit | 5/min per IP |

**Request Body:**
```json
{
  "name": "Alisher Navoiy",
  "email": "user@example.com",
  "password": "SecurePass123!",
  "password_confirmation": "SecurePass123!",
  "locale": "uz"
}
```

**Response 201:**
```json
{
  "success": true,
  "data": {
    "user": { "id": "01HX...", "name": "Alisher Navoiy", "email": "user@example.com" },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
    "refresh_token": "def50200...",
    "expires_in": 3600
  },
  "message": "Registration successful. Please verify your email."
}
```

**Status Codes:** 201 Created | 422 Validation Error | 409 Email Already Exists

---

### POST `/api/v1/auth/login`

| Property | Value |
|----------|-------|
| Auth | Not required |
| Rate Limit | 5/min per IP |

**Request Body:**
```json
{
  "email": "user@example.com",
  "password": "SecurePass123!",
  "device_name": "Chrome on Windows",
  "remember_me": false
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "user": {
      "id": "01HX...",
      "name": "Alisher Navoiy",
      "email": "user@example.com",
      "roles": ["user"],
      "permissions": ["books.read", "books.download"]
    },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
    "refresh_token": "def50200...",
    "token_type": "Bearer",
    "expires_in": 3600
  },
  "message": "Login successful"
}
```

**Status Codes:** 200 OK | 401 Invalid Credentials | 403 Account Suspended | 422 Validation Error | 429 Too Many Requests

---

### POST `/api/v1/auth/logout`

| Property | Value |
|----------|-------|
| Auth | Required (Bearer token) |

**Response 200:**
```json
{
  "success": true,
  "data": null,
  "message": "Logged out successfully"
}
```

---

### POST `/api/v1/auth/refresh`

| Property | Value |
|----------|-------|
| Auth | Not required |

**Request Body:**
```json
{
  "refresh_token": "def50200..."
}
```

**Response 200:**
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGci...",
    "refresh_token": "newrefresh...",
    "expires_in": 3600
  },
  "message": "Token refreshed"
}
```

---

### POST `/api/v1/auth/forgot-password`

**Request Body:** `{ "email": "user@example.com" }`

**Response 200:** Always returns 200 (prevents email enumeration)

---

### POST `/api/v1/auth/reset-password`

**Request Body:**
```json
{
  "token": "reset-token-from-email",
  "email": "user@example.com",
  "password": "NewPass123!",
  "password_confirmation": "NewPass123!"
}
```

---

### POST `/api/v1/auth/verify-email`

**Request Body:** `{ "token": "email-verification-token" }`

---

### GET `/api/v1/auth/me`

| Property | Value |
|----------|-------|
| Auth | Required |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id": "01HX...",
    "name": "Alisher Navoiy",
    "email": "user@example.com",
    "avatar": "https://...",
    "roles": ["tenant_admin"],
    "permissions": ["*"],
    "tenant": { "id": 42, "name": "Toshkent University Library", "slug": "tashkent-uni" },
    "preferences": {},
    "created_at": "2024-01-01T00:00:00Z"
  },
  "message": "Profile retrieved"
}
```

---

## 2. Books

Base path: `/api/v1/books`

### GET `/api/v1/books`

| Property | Value |
|----------|-------|
| Auth | Optional (auth unlocks user-specific data) |
| Rate Limit | 120/min per user |

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Items per page (default: 20, max: 100) |
| `search` | string | Full-text search query |
| `author_id` | integer | Filter by author |
| `publisher_id` | integer | Filter by publisher |
| `category_id` | integer | Filter by category |
| `tag` | string | Filter by tag slug |
| `language` | string | Filter by language code (uz, ru, en) |
| `year_from` | integer | Publication year from |
| `year_to` | integer | Publication year to |
| `status` | string | published (default), draft (admin only) |
| `is_featured` | boolean | Featured books only |
| `is_free` | boolean | Free books only |
| `sort` | string | title, created_at, download_count, average_rating |
| `order` | string | asc, desc (default: desc) |

**Response 200:** Paginated book collection

---

### GET `/api/v1/books/{id}`

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id": 100,
    "title": "O'tgan Kunlar",
    "slug": "otgan-kunlar",
    "subtitle": null,
    "description": "...",
    "isbn": "978-0000000000",
    "language": "uz",
    "published_year": 1925,
    "pages": 352,
    "cover_image": "https://cdn.../cover.jpg",
    "status": "published",
    "is_featured": true,
    "is_downloadable": true,
    "is_free": true,
    "download_count": 5420,
    "view_count": 12000,
    "average_rating": 4.8,
    "rating_count": 320,
    "author": { "id": 1, "name": "Abdulla Qodiriy", "slug": "abdulla-qodiriy" },
    "publisher": { "id": 5, "name": "Sharq Nashriyoti" },
    "categories": [{ "id": 2, "name": "Klassik Adabiyot", "slug": "klassik-adabiyot" }],
    "tags": [{ "id": 1, "name": "Roman", "slug": "roman" }],
    "files": [{ "id": 1, "file_type": "pdf", "file_size": 2048000 }],
    "user_progress": null,
    "is_favorited": false,
    "published_at": "2024-01-01T00:00:00Z",
    "created_at": "2024-01-01T00:00:00Z"
  },
  "message": "Book retrieved"
}
```

**Status Codes:** 200 OK | 404 Not Found

---

### POST `/api/v1/books`

| Property | Value |
|----------|-------|
| Auth | Required (tenant_admin, tenant_manager) |
| Permission | books.create |

**Request Body (multipart/form-data):**
```json
{
  "title": "O'tgan Kunlar",
  "author_id": 1,
  "publisher_id": 5,
  "category_id": 2,
  "description": "...",
  "isbn": "978-0000000000",
  "language": "uz",
  "published_year": 1925,
  "pages": 352,
  "is_featured": false,
  "is_downloadable": true,
  "is_free": true,
  "tag_ids": [1, 2, 3],
  "book_file": "(binary PDF/EPUB)",
  "cover_image": "(binary image, optional)"
}
```

**Response 201:** BookResource

---

### PUT `/api/v1/books/{id}`

| Property | Value |
|----------|-------|
| Auth | Required (tenant_admin, tenant_manager) |
| Permission | books.update |

**Request Body:** Same as POST, all fields optional

**Response 200:** Updated BookResource

---

### DELETE `/api/v1/books/{id}`

| Property | Value |
|----------|-------|
| Auth | Required (tenant_admin) |
| Permission | books.delete |

**Response 200:** `{ "success": true, "data": null, "message": "Book deleted" }`

---

### GET `/api/v1/books/{id}/download`

| Property | Value |
|----------|-------|
| Auth | Required |
| Rate Limit | 10/hour per user |

**Query Parameters:** `file_type` (pdf, epub — default: pdf)

**Response 200:**
```json
{
  "success": true,
  "data": {
    "download_url": "https://s3.amazonaws.com/...?X-Amz-Signature=...",
    "expires_at": "2024-01-15T11:30:00Z",
    "file_type": "pdf",
    "file_size": 2048000
  },
  "message": "Download URL generated"
}
```

---

### GET `/api/v1/books/{id}/stream`

| Property | Value |
|----------|-------|
| Auth | Required |

Returns a short-lived signed streaming URL (15 minutes) for PDF.js or epub.js to load the file directly from S3.

---

### GET `/api/v1/books/popular`

Top 20 most downloaded books in the last 30 days.

---

### GET `/api/v1/books/featured`

Featured books (is_featured = true).

---

### GET `/api/v1/books/new-arrivals`

Books added in the last 30 days, ordered by created_at desc.

---

## 3. Search

### GET `/api/v1/search`

| Property | Value |
|----------|-------|
| Auth | Optional |
| Rate Limit | 60/min |

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | string | Search query (required, min 2 chars) |
| `type` | string | books, audiobooks, authors, all (default: all) |
| `page` | integer | Page number |
| `per_page` | integer | Items per page |
| `language` | string | Filter language |
| `category_id` | integer | Category filter |
| `year_from` | integer | Year filter |
| `year_to` | integer | Year filter |

**Response 200:**
```json
{
  "success": true,
  "data": {
    "books": { "total": 15, "items": [...] },
    "audiobooks": { "total": 3, "items": [...] },
    "authors": { "total": 2, "items": [...] }
  },
  "message": "Search results"
}
```

---

### GET `/api/v1/search/autocomplete`

| Property | Value |
|----------|-------|
| Rate Limit | 60/min |

**Query Parameters:** `q` (min 2 chars), `limit` (max 10, default 5)

**Response 200:**
```json
{
  "success": true,
  "data": [
    { "type": "book", "id": 100, "title": "O'tgan Kunlar", "cover": "..." },
    { "type": "author", "id": 1, "name": "Abdulla Qodiriy" }
  ],
  "message": "Autocomplete results"
}
```

---

## 4. Audiobooks

Base path: `/api/v1/audiobooks`

### GET `/api/v1/audiobooks` — List audiobooks (same query params as books)

### GET `/api/v1/audiobooks/{id}` — Get audiobook with chapters

**Response includes:**
```json
{
  "data": {
    "id": 10,
    "title": "...",
    "narrator": "...",
    "total_duration": 72000,
    "total_chapters": 24,
    "chapters": [
      {
        "id": 1,
        "chapter_number": 1,
        "title": "Kirish",
        "duration": 3600,
        "stream_url": "https://signed-url..."
      }
    ]
  }
}
```

### POST `/api/v1/audiobooks` — Create (admin/manager)
### PUT `/api/v1/audiobooks/{id}` — Update
### DELETE `/api/v1/audiobooks/{id}` — Delete

### GET `/api/v1/audiobooks/{id}/chapters/{chapterId}/stream`

Returns signed S3 URL for streaming audio chapter (15-minute validity).

---

## 5. Authors

Base path: `/api/v1/authors`

### GET `/api/v1/authors`

**Query Parameters:** `search`, `nationality`, `page`, `per_page`, `sort` (name, books_count)

### GET `/api/v1/authors/{id}` — Author detail with books list
### GET `/api/v1/authors/{id}/books` — Paginated books by author
### POST `/api/v1/authors` — Create (admin/manager)
### PUT `/api/v1/authors/{id}` — Update
### DELETE `/api/v1/authors/{id}` — Delete (soft)

---

## 6. Publishers

Base path: `/api/v1/publishers`

### GET `/api/v1/publishers` — List with `search`, `country`, `page`
### GET `/api/v1/publishers/{id}` — Detail with books
### GET `/api/v1/publishers/{id}/books` — Books by publisher
### POST/PUT/DELETE `/api/v1/publishers/{id}` — CRUD (admin/manager)

---

## 7. Categories

Base path: `/api/v1/categories`

### GET `/api/v1/categories` — Full tree structure

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Adabiyot",
      "slug": "adabiyot",
      "children": [
        { "id": 2, "name": "Klassik", "slug": "klassik", "children": [] },
        { "id": 3, "name": "Zamonaviy", "slug": "zamonaviy", "children": [] }
      ]
    }
  ]
}
```

### GET `/api/v1/categories/{id}/books` — Books in category (recursive)
### POST/PUT/DELETE `/api/v1/categories/{id}` — CRUD (admin/manager)

---

## 8. Tags

Base path: `/api/v1/tags`

### GET `/api/v1/tags` — All tags with usage count
### GET `/api/v1/tags/cloud` — Tag cloud data (weighted)
### POST/PUT/DELETE `/api/v1/tags/{id}` — CRUD (admin/manager)

---

## 9. Reading Progress

Base path: `/api/v1/reading`

### GET `/api/v1/reading/progress`

**Auth:** Required

Returns all books/audiobooks in progress for current user.

**Response:**
```json
{
  "data": [
    {
      "book": { "id": 100, "title": "...", "cover_thumbnail": "..." },
      "current_page": 45,
      "total_pages": 352,
      "percentage": 12.78,
      "last_read_at": "2024-01-15T08:30:00Z"
    }
  ]
}
```

---

### PUT `/api/v1/reading/progress/{bookId}`

**Auth:** Required

**Request Body:**
```json
{
  "current_page": 45,
  "current_cfi": "epubcfi(/6/4[chap01ref]!/4[body01]/10[para05]/2/1:3)",
  "percentage": 12.78,
  "reading_time": 300
}
```

---

### GET `/api/v1/reading/history`

Completed and in-progress books, ordered by last_read_at desc.

---

## 10. Bookmarks

Base path: `/api/v1/books/{bookId}/bookmarks`

### GET `/api/v1/books/{bookId}/bookmarks` — All user's bookmarks for a book
### POST `/api/v1/books/{bookId}/bookmarks`

**Request Body:**
```json
{
  "page": 45,
  "cfi": "epubcfi(/6/4...)",
  "title": "Important quote",
  "note": "Remember this for essay",
  "color": "yellow"
}
```

### PUT `/api/v1/books/{bookId}/bookmarks/{id}` — Update bookmark
### DELETE `/api/v1/books/{bookId}/bookmarks/{id}` — Delete bookmark

---

## 11. Highlights

Base path: `/api/v1/books/{bookId}/highlights`

### GET `/api/v1/books/{bookId}/highlights`
### POST `/api/v1/books/{bookId}/highlights`

**Request Body:**
```json
{
  "page": 45,
  "cfi_start": "epubcfi(/6/4[chap01ref]!/4/10/2/1:0)",
  "cfi_end": "epubcfi(/6/4[chap01ref]!/4/10/2/1:40)",
  "selected_text": "Bu davr, bu zamon...",
  "note": "Poetic line",
  "color": "green"
}
```

### PUT/DELETE `/api/v1/books/{bookId}/highlights/{id}`

---

## 12. User Profile & Favorites

### GET `/api/v1/user/profile` — Current user profile
### PUT `/api/v1/user/profile` — Update profile (name, locale, avatar, preferences)
### PUT `/api/v1/user/password` — Change password
### DELETE `/api/v1/user/account` — Delete account (soft)

### GET `/api/v1/user/favorites` — User's favorite books and audiobooks
### POST `/api/v1/user/favorites` — Add to favorites `{ "book_id": 100 }`
### DELETE `/api/v1/user/favorites/{type}/{id}` — Remove from favorites

### GET `/api/v1/user/bookshelf` — All books user has interacted with
### GET `/api/v1/user/downloads` — Download history

---

## 13. Reviews

Base path: `/api/v1/books/{bookId}/reviews`

### GET `/api/v1/books/{bookId}/reviews` — Approved reviews (paginated)
**Query Parameters:** `sort` (helpful, recent), `rating` (1-5 filter)

### POST `/api/v1/books/{bookId}/reviews`

**Request Body:**
```json
{
  "rating": 5,
  "title": "Ajoyib kitob!",
  "body": "Bu kitobni o'qib juda ko'p narsa o'rgandim..."
}
```

### PUT `/api/v1/books/{bookId}/reviews/{id}` — Update own review
### DELETE `/api/v1/books/{bookId}/reviews/{id}` — Delete own review
### POST `/api/v1/books/{bookId}/reviews/{id}/helpful` — Mark review as helpful

---

## 14. Admin Endpoints

Base path: `/api/v1/admin`
**Auth:** Required, role: `tenant_admin` or `tenant_manager`

### Dashboard

### GET `/api/v1/admin/dashboard`

```json
{
  "data": {
    "stats": {
      "total_books": 1250,
      "total_users": 890,
      "total_downloads": 45200,
      "active_readers": 120,
      "storage_used_mb": 8540,
      "storage_quota_mb": 10240
    },
    "recent_books": [...],
    "recent_users": [...],
    "popular_books": [...]
  }
}
```

### User Management

### GET `/api/v1/admin/users` — List users (paginated, searchable)
**Query Parameters:** `search`, `role`, `status`, `page`, `per_page`

### GET `/api/v1/admin/users/{id}` — User detail with activity
### POST `/api/v1/admin/users` — Create user
### PUT `/api/v1/admin/users/{id}` — Update user
### PUT `/api/v1/admin/users/{id}/status` — Suspend/activate user `{ "status": "suspended" }`
### PUT `/api/v1/admin/users/{id}/role` — Assign role `{ "role": "tenant_manager" }`
### DELETE `/api/v1/admin/users/{id}` — Soft delete user

### Content Management

### GET `/api/v1/admin/books` — All books including drafts
### PUT `/api/v1/admin/books/{id}/publish` — Publish book
### PUT `/api/v1/admin/books/{id}/archive` — Archive book
### GET `/api/v1/admin/reviews` — All reviews (pending approval)
### PUT `/api/v1/admin/reviews/{id}/approve` — Approve review
### DELETE `/api/v1/admin/reviews/{id}` — Delete review

### Analytics

### GET `/api/v1/admin/analytics/overview`
**Query Parameters:** `period` (7d, 30d, 90d, 1y), `start_date`, `end_date`

### GET `/api/v1/admin/analytics/books` — Book performance metrics
### GET `/api/v1/admin/analytics/users` — User engagement metrics
### GET `/api/v1/admin/analytics/downloads` — Download statistics
### GET `/api/v1/admin/analytics/search` — Top search queries

---

## 15. SuperAdmin Endpoints

Base path: `/api/v1/super-admin`
**Auth:** Required, role: `super_admin`

### Tenant Management

### GET `/api/v1/super-admin/tenants` — All tenants (paginated)
**Query Parameters:** `search`, `status`, `plan_id`, `page`, `per_page`

### GET `/api/v1/super-admin/tenants/{id}` — Tenant detail

### POST `/api/v1/super-admin/tenants` — Create tenant

**Request Body:**
```json
{
  "name": "Toshkent University Library",
  "slug": "tashkent-uni",
  "plan_id": 2,
  "admin_name": "Librarian Admin",
  "admin_email": "admin@tashkent-uni.uz",
  "admin_password": "TempPass123!",
  "settings": {
    "locale": "uz",
    "features": { "audiobooks": true }
  },
  "custom_domain": "library.tashkent-uni.uz"
}
```

### PUT `/api/v1/super-admin/tenants/{id}` — Update tenant
### POST `/api/v1/super-admin/tenants/{id}/suspend` — Suspend `{ "reason": "Payment overdue" }`
### POST `/api/v1/super-admin/tenants/{id}/activate` — Activate
### DELETE `/api/v1/super-admin/tenants/{id}` — Cancel (soft delete, data retained 90 days)

### GET `/api/v1/super-admin/tenants/{id}/stats` — Tenant-specific stats
### GET `/api/v1/super-admin/tenants/{id}/users` — All users of tenant

### Plan Management

### GET `/api/v1/super-admin/plans` — All plans
### POST `/api/v1/super-admin/plans` — Create plan
### PUT `/api/v1/super-admin/plans/{id}` — Update plan
### DELETE `/api/v1/super-admin/plans/{id}` — Delete (if no active subscriptions)

### Platform Analytics

### GET `/api/v1/super-admin/analytics/platform` — Platform-wide metrics
### GET `/api/v1/super-admin/analytics/revenue` — Revenue & subscription metrics
### GET `/api/v1/super-admin/analytics/tenants` — Per-tenant activity summary

### System

### GET `/api/v1/super-admin/system/health` — Detailed system health
### POST `/api/v1/super-admin/system/cache-clear` — Clear all caches
### GET `/api/v1/super-admin/jobs` — Queue job status (Horizon data)

---

## Pagination Format (Standard)

```
GET /api/v1/books?page=2&per_page=20
```

All paginated endpoints accept `page` (integer, ≥ 1) and `per_page` (integer, 1–100, default 20). The response `meta` object always contains: `current_page`, `per_page`, `total`, `last_page`, `from`, `to`, `links`.
