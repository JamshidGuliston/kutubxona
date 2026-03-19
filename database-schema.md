# Database Schema — Kutubxona.uz Digital Library SaaS Platform

## 1. Tenant Isolation Strategy

All tenant-specific tables include a `tenant_id` column (indexed, non-nullable) referencing `tenants.id`. Application-level scoping via Laravel global scopes ensures every query automatically filters by the current tenant. No cross-tenant data leakage is possible via the ORM layer.

---

## 2. Entity Relationship Diagram (Text Format)

```
tenants
  │
  ├──< tenant_domains       (1 tenant : many domains)
  │
  ├──< users               (1 tenant : many users)
  │     │
  │     ├──< reading_progress
  │     ├──< bookmarks
  │     ├──< highlights
  │     ├──< notes
  │     ├──< favorites
  │     └──< reviews
  │
  ├──< books               (1 tenant : many books)
  │     │
  │     ├──< book_files
  │     ├──< book_tags >── tags
  │     ├──< book_categories >── categories
  │     ├──< reviews
  │     ├──< reading_progress
  │     ├──< bookmarks
  │     └──< highlights
  │
  ├──< audiobooks          (1 tenant : many audiobooks)
  │     └──< audiobook_chapters
  │
  ├──< authors             (1 tenant : many authors)
  │     └──< books (via author_id)
  │
  ├──< publishers          (1 tenant : many publishers)
  │     └──< books (via publisher_id)
  │
  ├──< categories          (1 tenant : many categories, self-referencing)
  │
  ├──< tags                (1 tenant : many tags)
  │
  └──< subscriptions
        └── plans (global)
```

---

## 3. Complete Table Definitions

### 3.1 `tenants`

```sql
CREATE TABLE tenants (
    id              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    ulid            CHAR(26)          NOT NULL,          -- public-facing ID
    name            VARCHAR(255)      NOT NULL,
    slug            VARCHAR(100)      NOT NULL,          -- URL-safe identifier
    status          ENUM('active','suspended','pending','cancelled')
                                      NOT NULL DEFAULT 'pending',
    plan_id         BIGINT UNSIGNED   NULL,
    settings        JSON              NULL,              -- tenant-level config
    metadata        JSON              NULL,              -- billing / contact info
    storage_quota   BIGINT UNSIGNED   NOT NULL DEFAULT 10737418240,  -- 10GB in bytes
    storage_used    BIGINT UNSIGNED   NOT NULL DEFAULT 0,
    max_users       INT UNSIGNED      NOT NULL DEFAULT 100,
    max_books       INT UNSIGNED      NOT NULL DEFAULT 1000,
    trial_ends_at   TIMESTAMP         NULL,
    suspended_at    TIMESTAMP         NULL,
    created_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenants_ulid (ulid),
    UNIQUE KEY uk_tenants_slug (slug),
    KEY idx_tenants_status (status),
    KEY idx_tenants_plan_id (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Columns explanation:**
- `ulid` — Universally Unique Lexicographically Sortable Identifier for external use
- `slug` — used in subdomain routing (e.g., `library1.kutubxona.uz`)
- `settings` — JSON blob: `{"locale":"uz","theme":"blue","features":{"audiobooks":true}}`
- `storage_quota` / `storage_used` — tracked for billing enforcement

---

### 3.2 `tenant_domains`

```sql
CREATE TABLE tenant_domains (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED  NOT NULL,
    domain      VARCHAR(255)     NOT NULL,  -- e.g., "library.myschool.edu"
    type        ENUM('subdomain','custom')  NOT NULL DEFAULT 'subdomain',
    is_primary  TINYINT(1)       NOT NULL DEFAULT 0,
    ssl_status  ENUM('pending','active','failed') NOT NULL DEFAULT 'pending',
    verified_at TIMESTAMP        NULL,
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenant_domains_domain (domain),
    KEY idx_tenant_domains_tenant_id (tenant_id),
    CONSTRAINT fk_tenant_domains_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.3 `users`

```sql
CREATE TABLE users (
    id                      BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id               BIGINT UNSIGNED  NOT NULL,
    ulid                    CHAR(26)         NOT NULL,
    name                    VARCHAR(255)     NOT NULL,
    email                   VARCHAR(255)     NOT NULL,
    email_verified_at       TIMESTAMP        NULL,
    password                VARCHAR(255)     NOT NULL,
    avatar                  VARCHAR(500)     NULL,
    status                  ENUM('active','inactive','banned') NOT NULL DEFAULT 'active',
    locale                  VARCHAR(10)      NOT NULL DEFAULT 'uz',
    preferences             JSON             NULL,
    last_login_at           TIMESTAMP        NULL,
    last_login_ip           VARCHAR(45)      NULL,  -- supports IPv6
    password_changed_at     TIMESTAMP        NULL,
    remember_token          VARCHAR(100)     NULL,
    email_verification_token VARCHAR(64)     NULL,
    password_reset_token    VARCHAR(64)      NULL,
    password_reset_expires  TIMESTAMP        NULL,
    two_factor_secret       TEXT             NULL,
    two_factor_recovery     JSON             NULL,
    created_at              TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMP        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_ulid (ulid),
    UNIQUE KEY uk_users_tenant_email (tenant_id, email),  -- email unique per tenant
    KEY idx_users_tenant_id (tenant_id),
    KEY idx_users_status (status),
    KEY idx_users_email (email),
    CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Note:** Email uniqueness is **per tenant** (`uk_users_tenant_email`), allowing same email across different tenants.

---

### 3.4 `roles` (spatie/laravel-permission managed, extended)

```sql
-- Standard Spatie tables, tenant-aware via team_id
-- Roles: super_admin, tenant_admin, tenant_manager, user, guest

CREATE TABLE roles (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255)     NOT NULL,
    guard_name  VARCHAR(255)     NOT NULL,
    team_id     BIGINT UNSIGNED  NULL,  -- maps to tenant_id
    created_at  TIMESTAMP        NULL,
    updated_at  TIMESTAMP        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_roles_name_guard_team (name, guard_name, team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.5 `authors`

```sql
CREATE TABLE authors (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED  NOT NULL,
    name        VARCHAR(255)     NOT NULL,
    slug        VARCHAR(255)     NOT NULL,
    bio         TEXT             NULL,
    photo       VARCHAR(500)     NULL,
    birth_year  SMALLINT         NULL,
    death_year  SMALLINT         NULL,
    nationality VARCHAR(100)     NULL,
    website     VARCHAR(500)     NULL,
    metadata    JSON             NULL,
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_authors_tenant_slug (tenant_id, slug),
    KEY idx_authors_tenant_id (tenant_id),
    FULLTEXT KEY ft_authors_name (name),
    CONSTRAINT fk_authors_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.6 `publishers`

```sql
CREATE TABLE publishers (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED  NOT NULL,
    name        VARCHAR(255)     NOT NULL,
    slug        VARCHAR(255)     NOT NULL,
    description TEXT             NULL,
    logo        VARCHAR(500)     NULL,
    website     VARCHAR(500)     NULL,
    country     VARCHAR(100)     NULL,
    founded_year SMALLINT        NULL,
    metadata    JSON             NULL,
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_publishers_tenant_slug (tenant_id, slug),
    KEY idx_publishers_tenant_id (tenant_id),
    CONSTRAINT fk_publishers_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.7 `categories`

```sql
CREATE TABLE categories (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED  NOT NULL,
    parent_id   BIGINT UNSIGNED  NULL,       -- self-referencing for tree structure
    name        VARCHAR(255)     NOT NULL,
    slug        VARCHAR(255)     NOT NULL,
    description TEXT             NULL,
    icon        VARCHAR(255)     NULL,
    color       CHAR(7)          NULL,       -- hex color code
    sort_order  INT UNSIGNED     NOT NULL DEFAULT 0,
    is_active   TINYINT(1)       NOT NULL DEFAULT 1,
    lft         INT UNSIGNED     NULL,       -- nested set left
    rgt         INT UNSIGNED     NULL,       -- nested set right
    depth       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_categories_tenant_slug (tenant_id, slug),
    KEY idx_categories_tenant_id (tenant_id),
    KEY idx_categories_parent_id (parent_id),
    KEY idx_categories_lft_rgt (lft, rgt),
    CONSTRAINT fk_categories_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id)
        REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.8 `tags`

```sql
CREATE TABLE tags (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED  NOT NULL,
    name        VARCHAR(100)     NOT NULL,
    slug        VARCHAR(100)     NOT NULL,
    color       CHAR(7)          NULL,
    usage_count INT UNSIGNED     NOT NULL DEFAULT 0,
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tags_tenant_slug (tenant_id, slug),
    KEY idx_tags_tenant_id (tenant_id),
    CONSTRAINT fk_tags_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.9 `books`

```sql
CREATE TABLE books (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED  NOT NULL,
    author_id       BIGINT UNSIGNED  NULL,
    publisher_id    BIGINT UNSIGNED  NULL,
    category_id     BIGINT UNSIGNED  NULL,
    title           VARCHAR(500)     NOT NULL,
    slug            VARCHAR(500)     NOT NULL,
    subtitle        VARCHAR(500)     NULL,
    description     TEXT             NULL,
    isbn            VARCHAR(20)      NULL,
    isbn13          VARCHAR(20)      NULL,
    language        VARCHAR(10)      NOT NULL DEFAULT 'uz',
    published_year  YEAR             NULL,
    edition         VARCHAR(50)      NULL,
    pages           MEDIUMINT UNSIGNED NULL,
    cover_image     VARCHAR(500)     NULL,
    cover_thumbnail VARCHAR(500)     NULL,
    status          ENUM('draft','published','archived','processing')
                                     NOT NULL DEFAULT 'draft',
    is_featured     TINYINT(1)       NOT NULL DEFAULT 0,
    is_downloadable TINYINT(1)       NOT NULL DEFAULT 1,
    is_free         TINYINT(1)       NOT NULL DEFAULT 1,
    price           DECIMAL(10,2)    NULL,
    download_count  INT UNSIGNED     NOT NULL DEFAULT 0,
    view_count      INT UNSIGNED     NOT NULL DEFAULT 0,
    average_rating  DECIMAL(3,2)     NULL,
    rating_count    INT UNSIGNED     NOT NULL DEFAULT 0,
    metadata        JSON             NULL,
    published_at    TIMESTAMP        NULL,
    created_by      BIGINT UNSIGNED  NULL,
    updated_by      BIGINT UNSIGNED  NULL,
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_books_tenant_slug (tenant_id, slug),
    KEY idx_books_tenant_id (tenant_id),
    KEY idx_books_author_id (author_id),
    KEY idx_books_publisher_id (publisher_id),
    KEY idx_books_category_id (category_id),
    KEY idx_books_status (status),
    KEY idx_books_language (language),
    KEY idx_books_published_year (published_year),
    KEY idx_books_is_featured (is_featured),
    KEY idx_books_created_at (created_at),
    KEY idx_books_download_count (download_count),
    FULLTEXT KEY ft_books_title_desc (title, description),
    CONSTRAINT fk_books_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_books_author FOREIGN KEY (author_id)
        REFERENCES authors (id) ON DELETE SET NULL,
    CONSTRAINT fk_books_publisher FOREIGN KEY (publisher_id)
        REFERENCES publishers (id) ON DELETE SET NULL,
    CONSTRAINT fk_books_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.10 `book_files`

```sql
CREATE TABLE book_files (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED  NOT NULL,
    book_id         BIGINT UNSIGNED  NOT NULL,
    file_type       ENUM('pdf','epub','mobi','djvu','fb2','txt')
                                     NOT NULL DEFAULT 'pdf',
    s3_key          VARCHAR(1000)    NOT NULL,  -- full S3 object key
    s3_bucket       VARCHAR(255)     NOT NULL,
    original_name   VARCHAR(500)     NOT NULL,
    file_size       BIGINT UNSIGNED  NOT NULL,  -- bytes
    mime_type       VARCHAR(100)     NOT NULL,
    checksum_md5    CHAR(32)         NULL,
    checksum_sha256 CHAR(64)         NULL,
    is_primary      TINYINT(1)       NOT NULL DEFAULT 1,
    processing_status ENUM('pending','processing','ready','failed')
                                     NOT NULL DEFAULT 'pending',
    processing_error TEXT            NULL,
    metadata        JSON             NULL,      -- extracted: pages, toc, etc.
    uploaded_by     BIGINT UNSIGNED  NULL,
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_book_files_tenant_id (tenant_id),
    KEY idx_book_files_book_id (book_id),
    KEY idx_book_files_file_type (file_type),
    KEY idx_book_files_processing_status (processing_status),
    CONSTRAINT fk_book_files_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_book_files_book FOREIGN KEY (book_id)
        REFERENCES books (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.11 `book_categories` (pivot)

```sql
CREATE TABLE book_categories (
    book_id     BIGINT UNSIGNED  NOT NULL,
    category_id BIGINT UNSIGNED  NOT NULL,
    PRIMARY KEY (book_id, category_id),
    KEY idx_book_categories_category_id (category_id),
    CONSTRAINT fk_bc_book FOREIGN KEY (book_id)
        REFERENCES books (id) ON DELETE CASCADE,
    CONSTRAINT fk_bc_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.12 `book_tags` (pivot)

```sql
CREATE TABLE book_tags (
    book_id BIGINT UNSIGNED  NOT NULL,
    tag_id  BIGINT UNSIGNED  NOT NULL,
    PRIMARY KEY (book_id, tag_id),
    KEY idx_book_tags_tag_id (tag_id),
    CONSTRAINT fk_bt_book FOREIGN KEY (book_id)
        REFERENCES books (id) ON DELETE CASCADE,
    CONSTRAINT fk_bt_tag FOREIGN KEY (tag_id)
        REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.13 `audiobooks`

```sql
CREATE TABLE audiobooks (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED  NOT NULL,
    book_id         BIGINT UNSIGNED  NULL,  -- linked book if exists
    author_id       BIGINT UNSIGNED  NULL,
    publisher_id    BIGINT UNSIGNED  NULL,
    category_id     BIGINT UNSIGNED  NULL,
    title           VARCHAR(500)     NOT NULL,
    slug            VARCHAR(500)     NOT NULL,
    description     TEXT             NULL,
    narrator        VARCHAR(255)     NULL,
    language        VARCHAR(10)      NOT NULL DEFAULT 'uz',
    published_year  YEAR             NULL,
    cover_image     VARCHAR(500)     NULL,
    cover_thumbnail VARCHAR(500)     NULL,
    total_duration  INT UNSIGNED     NULL,  -- seconds
    total_chapters  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    status          ENUM('draft','published','archived','processing')
                                     NOT NULL DEFAULT 'draft',
    is_featured     TINYINT(1)       NOT NULL DEFAULT 0,
    is_free         TINYINT(1)       NOT NULL DEFAULT 1,
    price           DECIMAL(10,2)    NULL,
    listen_count    INT UNSIGNED     NOT NULL DEFAULT 0,
    average_rating  DECIMAL(3,2)     NULL,
    rating_count    INT UNSIGNED     NOT NULL DEFAULT 0,
    metadata        JSON             NULL,
    published_at    TIMESTAMP        NULL,
    created_by      BIGINT UNSIGNED  NULL,
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      TIMESTAMP        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_audiobooks_tenant_slug (tenant_id, slug),
    KEY idx_audiobooks_tenant_id (tenant_id),
    KEY idx_audiobooks_author_id (author_id),
    KEY idx_audiobooks_status (status),
    FULLTEXT KEY ft_audiobooks_title (title, description),
    CONSTRAINT fk_audiobooks_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.14 `audiobook_chapters`

```sql
CREATE TABLE audiobook_chapters (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED  NOT NULL,
    audiobook_id    BIGINT UNSIGNED  NOT NULL,
    title           VARCHAR(500)     NOT NULL,
    chapter_number  SMALLINT UNSIGNED NOT NULL,
    s3_key          VARCHAR(1000)    NOT NULL,
    s3_bucket       VARCHAR(255)     NOT NULL,
    duration        INT UNSIGNED     NULL,  -- seconds
    file_size       BIGINT UNSIGNED  NULL,
    mime_type       VARCHAR(100)     NOT NULL DEFAULT 'audio/mpeg',
    waveform_data   JSON             NULL,  -- pre-computed waveform points
    processing_status ENUM('pending','processing','ready','failed')
                                     NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ab_chapters_tenant_id (tenant_id),
    KEY idx_ab_chapters_audiobook_id (audiobook_id),
    KEY idx_ab_chapters_number (audiobook_id, chapter_number),
    CONSTRAINT fk_ab_chapters_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_ab_chapters_audiobook FOREIGN KEY (audiobook_id)
        REFERENCES audiobooks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.15 `reading_progress`

```sql
CREATE TABLE reading_progress (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED  NOT NULL,
    user_id         BIGINT UNSIGNED  NOT NULL,
    book_id         BIGINT UNSIGNED  NULL,
    audiobook_id    BIGINT UNSIGNED  NULL,
    book_file_id    BIGINT UNSIGNED  NULL,
    current_page    MEDIUMINT UNSIGNED NULL,    -- for PDF
    current_cfi     VARCHAR(500)     NULL,      -- for EPUB (CFI location)
    current_chapter SMALLINT UNSIGNED NULL,     -- for audiobooks
    current_position INT UNSIGNED    NULL,      -- audio position in seconds
    total_pages     MEDIUMINT UNSIGNED NULL,
    percentage      DECIMAL(5,2)     NOT NULL DEFAULT 0,
    is_completed    TINYINT(1)       NOT NULL DEFAULT 0,
    completed_at    TIMESTAMP        NULL,
    reading_time    INT UNSIGNED     NOT NULL DEFAULT 0,  -- total seconds spent
    last_read_at    TIMESTAMP        NULL,
    device_info     JSON             NULL,     -- device, app version
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_reading_progress (tenant_id, user_id, book_id, audiobook_id),
    KEY idx_reading_progress_tenant_id (tenant_id),
    KEY idx_reading_progress_user_id (user_id),
    KEY idx_reading_progress_book_id (book_id),
    KEY idx_reading_progress_last_read (last_read_at),
    CONSTRAINT fk_rp_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.16 `bookmarks`

```sql
CREATE TABLE bookmarks (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED  NOT NULL,
    user_id     BIGINT UNSIGNED  NOT NULL,
    book_id     BIGINT UNSIGNED  NOT NULL,
    page        MEDIUMINT UNSIGNED NULL,
    cfi         VARCHAR(500)     NULL,
    title       VARCHAR(255)     NULL,
    note        TEXT             NULL,
    color       VARCHAR(20)      NULL DEFAULT 'yellow',
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bookmarks_tenant_id (tenant_id),
    KEY idx_bookmarks_user_book (user_id, book_id),
    CONSTRAINT fk_bookmarks_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_bookmarks_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_bookmarks_book FOREIGN KEY (book_id)
        REFERENCES books (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.17 `highlights`

```sql
CREATE TABLE highlights (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED  NOT NULL,
    user_id         BIGINT UNSIGNED  NOT NULL,
    book_id         BIGINT UNSIGNED  NOT NULL,
    page            MEDIUMINT UNSIGNED NULL,
    cfi_start       VARCHAR(500)     NULL,
    cfi_end         VARCHAR(500)     NULL,
    selected_text   TEXT             NOT NULL,
    note            TEXT             NULL,
    color           ENUM('yellow','green','blue','pink','purple')
                                     NOT NULL DEFAULT 'yellow',
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_highlights_tenant_id (tenant_id),
    KEY idx_highlights_user_book (user_id, book_id),
    CONSTRAINT fk_highlights_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_highlights_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_highlights_book FOREIGN KEY (book_id)
        REFERENCES books (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.18 `favorites`

```sql
CREATE TABLE favorites (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED  NOT NULL,
    user_id     BIGINT UNSIGNED  NOT NULL,
    book_id     BIGINT UNSIGNED  NULL,
    audiobook_id BIGINT UNSIGNED NULL,
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_favorites_user_book (user_id, book_id),
    UNIQUE KEY uk_favorites_user_audio (user_id, audiobook_id),
    KEY idx_favorites_tenant_id (tenant_id),
    KEY idx_favorites_user_id (user_id),
    CONSTRAINT fk_favorites_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.19 `reviews`

```sql
CREATE TABLE reviews (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED  NOT NULL,
    user_id     BIGINT UNSIGNED  NOT NULL,
    book_id     BIGINT UNSIGNED  NULL,
    audiobook_id BIGINT UNSIGNED NULL,
    rating      TINYINT UNSIGNED NOT NULL,  -- 1-5
    title       VARCHAR(255)     NULL,
    body        TEXT             NULL,
    is_approved TINYINT(1)       NOT NULL DEFAULT 0,
    is_featured TINYINT(1)       NOT NULL DEFAULT 0,
    helpful_count INT UNSIGNED   NOT NULL DEFAULT 0,
    approved_by BIGINT UNSIGNED  NULL,
    approved_at TIMESTAMP        NULL,
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  TIMESTAMP        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_reviews_user_book (user_id, book_id),
    UNIQUE KEY uk_reviews_user_audio (user_id, audiobook_id),
    KEY idx_reviews_tenant_id (tenant_id),
    KEY idx_reviews_book_id (book_id),
    KEY idx_reviews_rating (rating),
    KEY idx_reviews_is_approved (is_approved),
    CONSTRAINT fk_reviews_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.20 `analytics_events`

```sql
CREATE TABLE analytics_events (
    id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED  NOT NULL,
    user_id     BIGINT UNSIGNED  NULL,   -- nullable for anonymous
    session_id  CHAR(36)         NULL,
    event_type  VARCHAR(100)     NOT NULL, -- 'book_view', 'book_download', etc.
    entity_type VARCHAR(50)      NULL,   -- 'book', 'audiobook', 'author'
    entity_id   BIGINT UNSIGNED  NULL,
    properties  JSON             NULL,  -- flexible event data
    ip_address  VARCHAR(45)      NULL,
    user_agent  VARCHAR(500)     NULL,
    referer     VARCHAR(500)     NULL,
    country     CHAR(2)          NULL,
    device_type ENUM('desktop','mobile','tablet') NULL,
    created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_analytics_tenant_id (tenant_id),
    KEY idx_analytics_user_id (user_id),
    KEY idx_analytics_event_type (event_type),
    KEY idx_analytics_entity (entity_type, entity_id),
    KEY idx_analytics_created_at (created_at),
    CONSTRAINT fk_analytics_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
    PARTITION p202401 VALUES LESS THAN (202402),
    PARTITION p202402 VALUES LESS THAN (202403),
    PARTITION p202403 VALUES LESS THAN (202404),
    PARTITION p202404 VALUES LESS THAN (202405),
    PARTITION p202405 VALUES LESS THAN (202406),
    PARTITION p202406 VALUES LESS THAN (202407),
    PARTITION p202407 VALUES LESS THAN (202408),
    PARTITION p202408 VALUES LESS THAN (202409),
    PARTITION p202409 VALUES LESS THAN (202410),
    PARTITION p202410 VALUES LESS THAN (202411),
    PARTITION p202411 VALUES LESS THAN (202412),
    PARTITION p202412 VALUES LESS THAN (202501),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

---

### 3.21 `plans`

```sql
CREATE TABLE plans (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name            VARCHAR(100)     NOT NULL,
    slug            VARCHAR(100)     NOT NULL,
    description     TEXT             NULL,
    price_monthly   DECIMAL(10,2)    NOT NULL DEFAULT 0,
    price_yearly    DECIMAL(10,2)    NOT NULL DEFAULT 0,
    max_users       INT UNSIGNED     NOT NULL DEFAULT 10,
    max_books       INT UNSIGNED     NOT NULL DEFAULT 100,
    storage_quota   BIGINT UNSIGNED  NOT NULL DEFAULT 1073741824,  -- 1GB
    features        JSON             NOT NULL,  -- {"audiobooks": true, ...}
    is_active       TINYINT(1)       NOT NULL DEFAULT 1,
    sort_order      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_plans_slug (slug),
    KEY idx_plans_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 3.22 `subscriptions`

```sql
CREATE TABLE subscriptions (
    id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id       BIGINT UNSIGNED  NOT NULL,
    plan_id         BIGINT UNSIGNED  NOT NULL,
    status          ENUM('active','trialing','past_due','cancelled','expired')
                                     NOT NULL DEFAULT 'trialing',
    billing_cycle   ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    amount          DECIMAL(10,2)    NOT NULL,
    currency        CHAR(3)          NOT NULL DEFAULT 'USD',
    trial_ends_at   TIMESTAMP        NULL,
    current_period_start TIMESTAMP   NULL,
    current_period_end   TIMESTAMP   NULL,
    cancelled_at    TIMESTAMP        NULL,
    cancel_reason   TEXT             NULL,
    payment_gateway VARCHAR(50)      NULL,  -- 'stripe', 'payme', etc.
    gateway_subscription_id VARCHAR(255) NULL,
    metadata        JSON             NULL,
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subscriptions_tenant_id (tenant_id),
    KEY idx_subscriptions_plan_id (plan_id),
    KEY idx_subscriptions_status (status),
    CONSTRAINT fk_subscriptions_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id)
        REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. Migration Order

The migrations must be run in this order to satisfy foreign key constraints:

```
1.  create_tenants_table
2.  create_plans_table
3.  create_tenant_domains_table        (FK: tenants)
4.  create_subscriptions_table         (FK: tenants, plans)
5.  create_users_table                 (FK: tenants)
6.  spatie_permission_tables           (team_id = tenant_id)
7.  create_authors_table               (FK: tenants)
8.  create_publishers_table            (FK: tenants)
9.  create_categories_table            (FK: tenants, self-ref)
10. create_tags_table                  (FK: tenants)
11. create_books_table                 (FK: tenants, authors, publishers, categories)
12. create_book_files_table            (FK: tenants, books)
13. create_book_categories_table       (FK: books, categories)
14. create_book_tags_table             (FK: books, tags)
15. create_audiobooks_table            (FK: tenants)
16. create_audiobook_chapters_table    (FK: tenants, audiobooks)
17. create_reading_progress_table      (FK: tenants, users)
18. create_bookmarks_table             (FK: tenants, users, books)
19. create_highlights_table            (FK: tenants, users, books)
20. create_favorites_table             (FK: tenants, users)
21. create_reviews_table               (FK: tenants, users)
22. create_analytics_events_table      (FK: tenants)
```

---

## 5. Index Strategy

### Composite Indexes for Common Queries

```sql
-- Books listing for a tenant with filters
ALTER TABLE books ADD INDEX idx_books_tenant_status_lang (tenant_id, status, language);

-- Books by tenant ordered by download count (popular books)
ALTER TABLE books ADD INDEX idx_books_tenant_downloads (tenant_id, download_count DESC);

-- Reading history by user ordered by date
ALTER TABLE reading_progress ADD INDEX idx_rp_user_last_read (user_id, last_read_at DESC);

-- Analytics event queries by date range per tenant
ALTER TABLE analytics_events ADD INDEX idx_analytics_tenant_date (tenant_id, created_at);
```

---

## 6. Partitioning Strategy

### `analytics_events` — Range Partitioning by Month

The `analytics_events` table uses `RANGE` partitioning on `YEAR * 100 + MONTH`. This allows:
- Dropping old partitions without full table scans (`ALTER TABLE DROP PARTITION p202401`)
- Partition pruning on date-range queries
- Faster aggregation within monthly partitions

### Future: `reading_progress` Archival

For tenants with very high reading activity, a separate `reading_progress_archive` table (identical schema) can hold completed readings older than 1 year, keeping the active table small.

---

## 7. Character Set and Collation

All tables use `utf8mb4` (full Unicode including emoji) with `utf8mb4_unicode_ci` collation for case-insensitive comparison. Uzbek, Russian, and English content is fully supported.
