# Angular Frontend Development Guide — Kutubxona.uz

## 1. Angular 17+ with Standalone Components

### Prerequisites

```bash
node >= 20.x
npm >= 10.x
Angular CLI >= 17.x

npm install -g @angular/cli@17
ng new kutubxona-frontend --standalone --routing --style=scss --ssr
```

### Core Technologies

| Technology | Version | Purpose |
|------------|---------|---------|
| Angular | 17+ | Framework (standalone components, signals) |
| NgRx | 17+ | State management |
| RxJS | 7.8+ | Reactive programming |
| Angular Material | 17+ | UI components |
| PDF.js | 4.x | PDF rendering |
| epub.js | 0.3.x | EPUB rendering |
| Howler.js | 2.x | Audio playback |
| TailwindCSS | 3.x | Utility-first CSS |

---

## 2. Project Structure (Feature-Based)

```
src/
├── app/
│   ├── core/                            # Singleton services, guards, interceptors
│   │   ├── auth/
│   │   │   ├── auth.service.ts
│   │   │   ├── token.service.ts
│   │   │   └── auth.interceptor.ts
│   │   ├── tenant/
│   │   │   ├── tenant.service.ts
│   │   │   └── tenant.model.ts
│   │   ├── guards/
│   │   │   ├── auth.guard.ts
│   │   │   ├── tenant.guard.ts
│   │   │   └── role.guard.ts
│   │   ├── http/
│   │   │   ├── error.interceptor.ts
│   │   │   ├── loading.interceptor.ts
│   │   │   └── api.service.ts
│   │   └── models/
│   │       ├── api-response.model.ts
│   │       └── pagination.model.ts
│   │
│   ├── features/                        # Feature modules (lazy loaded)
│   │   ├── auth/
│   │   │   ├── pages/
│   │   │   │   ├── login/
│   │   │   │   ├── register/
│   │   │   │   ├── forgot-password/
│   │   │   │   └── reset-password/
│   │   │   ├── components/
│   │   │   │   └── auth-form/
│   │   │   └── auth.routes.ts
│   │   │
│   │   ├── catalog/                     # Book browsing
│   │   │   ├── pages/
│   │   │   │   ├── book-list/
│   │   │   │   ├── book-detail/
│   │   │   │   ├── author-detail/
│   │   │   │   └── category-page/
│   │   │   ├── components/
│   │   │   │   ├── book-card/
│   │   │   │   ├── book-grid/
│   │   │   │   ├── filter-panel/
│   │   │   │   └── star-rating/
│   │   │   └── catalog.routes.ts
│   │   │
│   │   ├── reader/                      # Ebook reader
│   │   │   ├── pages/
│   │   │   │   └── reader-page/
│   │   │   ├── components/
│   │   │   │   ├── pdf-reader/
│   │   │   │   ├── epub-reader/
│   │   │   │   ├── reader-toolbar/
│   │   │   │   ├── bookmark-panel/
│   │   │   │   └── highlight-panel/
│   │   │   └── reader.routes.ts
│   │   │
│   │   ├── audio-player/               # Audiobook player
│   │   │   ├── components/
│   │   │   │   ├── audio-player/
│   │   │   │   ├── chapter-list/
│   │   │   │   └── waveform/
│   │   │   └── audio-player.routes.ts
│   │   │
│   │   ├── search/
│   │   │   ├── pages/
│   │   │   │   └── search-results/
│   │   │   └── components/
│   │   │       ├── search-bar/
│   │   │       └── filter-chips/
│   │   │
│   │   ├── user/                       # User profile, favorites, bookshelf
│   │   │   ├── pages/
│   │   │   │   ├── profile/
│   │   │   │   ├── bookshelf/
│   │   │   │   ├── favorites/
│   │   │   │   └── reading-history/
│   │   │   └── user.routes.ts
│   │   │
│   │   └── admin/                      # Admin panel (role-gated)
│   │       ├── pages/
│   │       │   ├── dashboard/
│   │       │   ├── books-management/
│   │       │   ├── users-management/
│   │       │   ├── analytics/
│   │       │   └── settings/
│   │       └── admin.routes.ts
│   │
│   ├── shared/                         # Shared components, pipes, directives
│   │   ├── components/
│   │   │   ├── loading-spinner/
│   │   │   ├── pagination/
│   │   │   ├── image-lazy/
│   │   │   ├── empty-state/
│   │   │   └── confirm-dialog/
│   │   ├── pipes/
│   │   │   ├── file-size.pipe.ts
│   │   │   ├── duration.pipe.ts
│   │   │   └── safe-url.pipe.ts
│   │   └── directives/
│   │       └── intersection-observer.directive.ts
│   │
│   ├── store/                          # NgRx state
│   │   ├── auth/
│   │   ├── books/
│   │   ├── audiobooks/
│   │   ├── reading/
│   │   └── ui/
│   │
│   ├── app.component.ts
│   ├── app.config.ts                   # Root providers (standalone)
│   └── app.routes.ts
│
├── assets/
│   ├── i18n/
│   │   ├── uz.json
│   │   ├── ru.json
│   │   └── en.json
│   └── icons/
│
└── environments/
    ├── environment.ts
    └── environment.production.ts
```

---

## 3. Tenant Domain Detection Service

```typescript
// src/app/core/tenant/tenant.service.ts
import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, shareReplay, tap } from 'rxjs';
import { environment } from '../../../environments/environment';

export interface Tenant {
  id: number;
  name: string;
  slug: string;
  settings: TenantSettings;
  plan: string;
}

export interface TenantSettings {
  locale: string;
  theme: string;
  features: {
    audiobooks: boolean;
    reviews: boolean;
    downloads: boolean;
  };
  branding: {
    logo_url?: string;
    primary_color?: string;
    secondary_color?: string;
  };
}

@Injectable({ providedIn: 'root' })
export class TenantService {
  private http = inject(HttpClient);
  private currentTenant: Tenant | null = null;
  private tenant$: Observable<Tenant> | null = null;

  /**
   * Detects tenant from current domain/subdomain.
   * Called once at app initialization via APP_INITIALIZER.
   */
  detectAndLoadTenant(): Observable<Tenant> {
    if (this.tenant$) return this.tenant$;

    const detectedSlug = this.detectTenantSlug();

    this.tenant$ = this.http.get<{ data: Tenant }>(`${environment.apiUrl}/tenant/info`, {
      headers: { 'X-Detected-Slug': detectedSlug ?? '' }
    }).pipe(
      tap(response => {
        this.currentTenant = response.data;
        this.applyTenantTheme(response.data.settings);
        this.setHtmlLang(response.data.settings.locale);
      }),
      // Map to tenant object
      // shareReplay(1) ensures single HTTP request
      shareReplay(1)
    ) as unknown as Observable<Tenant>;

    return this.tenant$;
  }

  private detectTenantSlug(): string | null {
    const hostname = window.location.hostname;
    const baseDomain = environment.baseDomain; // 'kutubxona.uz'

    // Check if it's a subdomain
    if (hostname.endsWith(`.${baseDomain}`)) {
      return hostname.replace(`.${baseDomain}`, '');
    }

    // It's a custom domain — server will detect it from X-Forwarded-Host header
    return null;
  }

  private applyTenantTheme(settings: TenantSettings): void {
    const root = document.documentElement;
    if (settings.branding?.primary_color) {
      root.style.setProperty('--color-primary', settings.branding.primary_color);
    }
    if (settings.branding?.secondary_color) {
      root.style.setProperty('--color-secondary', settings.branding.secondary_color);
    }
  }

  private setHtmlLang(locale: string): void {
    document.documentElement.lang = locale;
  }

  get tenant(): Tenant | null {
    return this.currentTenant;
  }

  isFeatureEnabled(feature: keyof TenantSettings['features']): boolean {
    return this.currentTenant?.settings?.features?.[feature] ?? false;
  }
}
```

### APP_INITIALIZER Setup

```typescript
// src/app/app.config.ts
import { APP_INITIALIZER, ApplicationConfig } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { TenantService } from './core/tenant/tenant.service';
import { authInterceptor } from './core/auth/auth.interceptor';
import { errorInterceptor } from './core/http/error.interceptor';
import { routes } from './app.routes';
import { provideStore } from '@ngrx/store';
import { provideEffects } from '@ngrx/effects';
import { provideStoreDevtools } from '@ngrx/store-devtools';
import { rootReducers } from './store';
import { rootEffects } from './store/effects';

export const appConfig: ApplicationConfig = {
  providers: [
    provideRouter(routes),
    provideHttpClient(
      withInterceptors([authInterceptor, errorInterceptor])
    ),
    provideStore(rootReducers),
    provideEffects(rootEffects),
    provideStoreDevtools({ maxAge: 25 }),
    {
      provide: APP_INITIALIZER,
      useFactory: (tenantService: TenantService) => {
        return () => tenantService.detectAndLoadTenant();
      },
      deps: [TenantService],
      multi: true
    },
  ]
};
```

---

## 4. Auth Module: JWT Interceptor and Token Refresh

```typescript
// src/app/core/auth/auth.interceptor.ts
import { HttpInterceptorFn, HttpRequest, HttpHandlerFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, switchMap, throwError, BehaviorSubject, filter, take } from 'rxjs';
import { TokenService } from './token.service';
import { AuthService } from './auth.service';
import { Router } from '@angular/router';

let isRefreshing = false;
const refreshTokenSubject = new BehaviorSubject<string | null>(null);

export const authInterceptor: HttpInterceptorFn = (req: HttpRequest<unknown>, next: HttpHandlerFn) => {
  const tokenService = inject(TokenService);
  const authService = inject(AuthService);
  const router = inject(Router);

  const token = tokenService.getAccessToken();

  if (token && !req.url.includes('/auth/refresh') && !req.url.includes('/auth/login')) {
    req = addAuthHeader(req, token);
  }

  return next(req).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401 && !req.url.includes('/auth/')) {
        return handle401Error(req, next, authService, tokenService, router);
      }
      return throwError(() => error);
    })
  );
};

function addAuthHeader(req: HttpRequest<unknown>, token: string): HttpRequest<unknown> {
  return req.clone({
    setHeaders: { Authorization: `Bearer ${token}` }
  });
}

function handle401Error(
  req: HttpRequest<unknown>,
  next: HttpHandlerFn,
  authService: AuthService,
  tokenService: TokenService,
  router: Router
) {
  if (!isRefreshing) {
    isRefreshing = true;
    refreshTokenSubject.next(null);

    const refreshToken = tokenService.getRefreshToken();
    if (!refreshToken) {
      authService.logout();
      router.navigate(['/auth/login']);
      return throwError(() => new Error('No refresh token'));
    }

    return authService.refreshToken(refreshToken).pipe(
      switchMap(tokens => {
        isRefreshing = false;
        tokenService.setTokens(tokens.token, tokens.refresh_token);
        refreshTokenSubject.next(tokens.token);
        return next(addAuthHeader(req, tokens.token));
      }),
      catchError(err => {
        isRefreshing = false;
        authService.logout();
        router.navigate(['/auth/login']);
        return throwError(() => err);
      })
    );
  }

  return refreshTokenSubject.pipe(
    filter(token => token !== null),
    take(1),
    switchMap(token => next(addAuthHeader(req, token!)))
  );
}
```

```typescript
// src/app/core/auth/token.service.ts
import { Injectable } from '@angular/core';

const ACCESS_TOKEN_KEY = 'access_token';
const REFRESH_TOKEN_KEY = 'refresh_token';

@Injectable({ providedIn: 'root' })
export class TokenService {
  setTokens(accessToken: string, refreshToken: string): void {
    localStorage.setItem(ACCESS_TOKEN_KEY, accessToken);
    // Store refresh token in sessionStorage or memory for better security
    sessionStorage.setItem(REFRESH_TOKEN_KEY, refreshToken);
  }

  getAccessToken(): string | null {
    return localStorage.getItem(ACCESS_TOKEN_KEY);
  }

  getRefreshToken(): string | null {
    return sessionStorage.getItem(REFRESH_TOKEN_KEY);
  }

  clearTokens(): void {
    localStorage.removeItem(ACCESS_TOKEN_KEY);
    sessionStorage.removeItem(REFRESH_TOKEN_KEY);
  }

  isTokenExpired(token?: string): boolean {
    const t = token ?? this.getAccessToken();
    if (!t) return true;
    try {
      const payload = JSON.parse(atob(t.split('.')[1]));
      return payload.exp < Math.floor(Date.now() / 1000);
    } catch {
      return true;
    }
  }

  decodeToken(token?: string): Record<string, unknown> | null {
    const t = token ?? this.getAccessToken();
    if (!t) return null;
    try {
      return JSON.parse(atob(t.split('.')[1]));
    } catch {
      return null;
    }
  }
}
```

---

## 5. Route Guards

```typescript
// src/app/core/guards/auth.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { TokenService } from '../auth/token.service';
import { AuthService } from '../auth/auth.service';

export const authGuard: CanActivateFn = (route, state) => {
  const tokenService = inject(TokenService);
  const router = inject(Router);
  const authService = inject(AuthService);

  const token = tokenService.getAccessToken();

  if (!token || tokenService.isTokenExpired(token)) {
    tokenService.clearTokens();
    return router.createUrlTree(['/auth/login'], {
      queryParams: { returnUrl: state.url }
    });
  }

  return true;
};

// src/app/core/guards/role.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router, ActivatedRouteSnapshot } from '@angular/router';
import { AuthService } from '../auth/auth.service';
import { map } from 'rxjs';

export const roleGuard: CanActivateFn = (route: ActivatedRouteSnapshot) => {
  const authService = inject(AuthService);
  const router = inject(Router);
  const requiredRoles: string[] = route.data['roles'] ?? [];

  const user = authService.getCurrentUser();
  if (!user) return router.createUrlTree(['/auth/login']);

  const hasRole = requiredRoles.some(role => user.roles.includes(role));
  if (!hasRole) return router.createUrlTree(['/unauthorized']);

  return true;
};

// src/app/core/guards/tenant.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { TenantService } from '../tenant/tenant.service';

export const tenantGuard: CanActivateFn = () => {
  const tenantService = inject(TenantService);
  const router = inject(Router);

  if (!tenantService.tenant) {
    return router.createUrlTree(['/tenant-not-found']);
  }
  return true;
};
```

---

## 6. State Management with NgRx

### Store Structure

```typescript
// src/app/store/index.ts
import { ActionReducerMap } from '@ngrx/store';
import { authReducer, AuthState } from './auth/auth.reducer';
import { booksReducer, BooksState } from './books/books.reducer';
import { readingReducer, ReadingState } from './reading/reading.reducer';
import { uiReducer, UiState } from './ui/ui.reducer';

export interface AppState {
  auth: AuthState;
  books: BooksState;
  reading: ReadingState;
  ui: UiState;
}

export const rootReducers: ActionReducerMap<AppState> = {
  auth: authReducer,
  books: booksReducer,
  reading: readingReducer,
  ui: uiReducer,
};
```

### Books Store

```typescript
// src/app/store/books/books.actions.ts
import { createActionGroup, props, emptyProps } from '@ngrx/store';
import { Book, BookFilters, PaginatedResult } from '../../core/models';

export const BooksActions = createActionGroup({
  source: 'Books',
  events: {
    'Load Books': props<{ filters: BookFilters; page: number }>(),
    'Load Books Success': props<{ result: PaginatedResult<Book> }>(),
    'Load Books Failure': props<{ error: string }>(),

    'Load Book Detail': props<{ id: number }>(),
    'Load Book Detail Success': props<{ book: Book }>(),
    'Load Book Detail Failure': props<{ error: string }>(),

    'Search Books': props<{ query: string; filters: BookFilters }>(),
    'Search Books Success': props<{ result: PaginatedResult<Book> }>(),
    'Search Books Failure': props<{ error: string }>(),

    'Toggle Favorite': props<{ bookId: number }>(),
    'Toggle Favorite Success': props<{ bookId: number; isFavorited: boolean }>(),
  }
});

// src/app/store/books/books.reducer.ts
import { createReducer, on } from '@ngrx/store';
import { EntityState, EntityAdapter, createEntityAdapter } from '@ngrx/entity';
import { Book } from '../../core/models';
import { BooksActions } from './books.actions';

export interface BooksState extends EntityState<Book> {
  loading: boolean;
  searchLoading: boolean;
  error: string | null;
  currentPage: number;
  totalPages: number;
  total: number;
  searchQuery: string;
  filters: Record<string, unknown>;
}

export const booksAdapter: EntityAdapter<Book> = createEntityAdapter<Book>();

const initialState: BooksState = booksAdapter.getInitialState({
  loading: false,
  searchLoading: false,
  error: null,
  currentPage: 1,
  totalPages: 0,
  total: 0,
  searchQuery: '',
  filters: {}
});

export const booksReducer = createReducer(
  initialState,
  on(BooksActions.loadBooks, state => ({ ...state, loading: true, error: null })),
  on(BooksActions.loadBooksSuccess, (state, { result }) => booksAdapter.setAll(result.data, {
    ...state,
    loading: false,
    currentPage: result.meta.current_page,
    totalPages: result.meta.last_page,
    total: result.meta.total
  })),
  on(BooksActions.loadBooksFailure, (state, { error }) => ({ ...state, loading: false, error })),
  on(BooksActions.searchBooksSuccess, (state, { result }) => booksAdapter.setAll(result.data, {
    ...state,
    searchLoading: false,
    total: result.meta.total
  })),
  on(BooksActions.toggleFavoriteSuccess, (state, { bookId, isFavorited }) =>
    booksAdapter.updateOne({ id: bookId, changes: { is_favorited: isFavorited } }, state)
  )
);

// src/app/store/books/books.selectors.ts
import { createFeatureSelector, createSelector } from '@ngrx/store';
import { booksAdapter, BooksState } from './books.reducer';

const selectBooksState = createFeatureSelector<BooksState>('books');

const { selectAll, selectEntities, selectTotal } = booksAdapter.getSelectors();

export const selectAllBooks = createSelector(selectBooksState, selectAll);
export const selectBooksLoading = createSelector(selectBooksState, s => s.loading);
export const selectBooksTotal = createSelector(selectBooksState, s => s.total);
export const selectCurrentPage = createSelector(selectBooksState, s => s.currentPage);
export const selectTotalPages = createSelector(selectBooksState, s => s.totalPages);
export const selectBooksError = createSelector(selectBooksState, s => s.error);
export const selectBookById = (id: number) =>
  createSelector(selectEntities, entities => entities[id]);

// src/app/store/books/books.effects.ts
import { Injectable, inject } from '@angular/core';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { catchError, map, switchMap, debounceTime } from 'rxjs/operators';
import { of } from 'rxjs';
import { BooksActions } from './books.actions';
import { BookApiService } from '../../core/http/book-api.service';

@Injectable()
export class BooksEffects {
  private actions$ = inject(Actions);
  private bookApi = inject(BookApiService);

  loadBooks$ = createEffect(() =>
    this.actions$.pipe(
      ofType(BooksActions.loadBooks),
      switchMap(({ filters, page }) =>
        this.bookApi.getBooks(filters, page).pipe(
          map(result => BooksActions.loadBooksSuccess({ result })),
          catchError(error => of(BooksActions.loadBooksFailure({ error: error.message })))
        )
      )
    )
  );

  searchBooks$ = createEffect(() =>
    this.actions$.pipe(
      ofType(BooksActions.searchBooks),
      debounceTime(300), // Debounce search
      switchMap(({ query, filters }) =>
        this.bookApi.searchBooks(query, filters).pipe(
          map(result => BooksActions.searchBooksSuccess({ result })),
          catchError(error => of(BooksActions.searchBooksFailure({ error: error.message })))
        )
      )
    )
  );
}
```

---

## 7. HTTP Services for All API Endpoints

```typescript
// src/app/core/http/book-api.service.ts
import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse, PaginatedResult, Book, BookFilters } from '../models';

@Injectable({ providedIn: 'root' })
export class BookApiService {
  private http = inject(HttpClient);
  private baseUrl = `${environment.apiUrl}/books`;

  getBooks(filters: BookFilters = {}, page = 1, perPage = 20): Observable<PaginatedResult<Book>> {
    let params = new HttpParams()
      .set('page', page.toString())
      .set('per_page', perPage.toString());

    Object.entries(filters).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        params = params.set(key, value.toString());
      }
    });

    return this.http.get<ApiResponse<Book[]>>(this.baseUrl, { params }).pipe(
      map(response => ({
        data: response.data as Book[],
        meta: response.meta!
      }))
    );
  }

  getBook(id: number): Observable<Book> {
    return this.http.get<ApiResponse<Book>>(`${this.baseUrl}/${id}`).pipe(
      map(r => r.data as Book)
    );
  }

  searchBooks(query: string, filters: BookFilters = {}): Observable<PaginatedResult<Book>> {
    return this.getBooks({ ...filters, search: query });
  }

  createBook(formData: FormData): Observable<Book> {
    return this.http.post<ApiResponse<Book>>(this.baseUrl, formData).pipe(
      map(r => r.data as Book)
    );
  }

  updateBook(id: number, data: Partial<Book>): Observable<Book> {
    return this.http.put<ApiResponse<Book>>(`${this.baseUrl}/${id}`, data).pipe(
      map(r => r.data as Book)
    );
  }

  deleteBook(id: number): Observable<void> {
    return this.http.delete<void>(`${this.baseUrl}/${id}`);
  }

  getDownloadUrl(bookId: number, fileType: string = 'pdf'): Observable<{ download_url: string }> {
    return this.http.get<ApiResponse<{ download_url: string }>>(
      `${this.baseUrl}/${bookId}/download`,
      { params: { file_type: fileType } }
    ).pipe(map(r => r.data as { download_url: string }));
  }

  getStreamUrl(bookId: number): Observable<string> {
    return this.http.get<ApiResponse<{ stream_url: string }>>(
      `${this.baseUrl}/${bookId}/stream`
    ).pipe(map(r => (r.data as { stream_url: string }).stream_url));
  }

  autocomplete(query: string): Observable<{ type: string; id: number; title: string }[]> {
    return this.http.get<ApiResponse<unknown[]>>(
      `${environment.apiUrl}/search/autocomplete`,
      { params: { q: query, limit: '8' } }
    ).pipe(map(r => r.data as { type: string; id: number; title: string }[]));
  }
}
```

---

## 8. Ebook Reader Component (PDF.js Integration)

```typescript
// src/app/features/reader/components/pdf-reader/pdf-reader.component.ts
import {
  Component, Input, OnInit, OnDestroy, ViewChild, ElementRef,
  ChangeDetectionStrategy, ChangeDetectorRef, inject, signal
} from '@angular/core';
import { CommonModule } from '@angular/common';
import * as pdfjsLib from 'pdfjs-dist';
import { ReadingApiService } from '../../../../core/http/reading-api.service';

pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/pdf.worker.min.js';

@Component({
  selector: 'app-pdf-reader',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="pdf-reader" #container>
      <!-- Toolbar -->
      <div class="reader-toolbar">
        <button (click)="prevPage()" [disabled]="currentPage() <= 1">‹ Prev</button>
        <span>Page {{ currentPage() }} of {{ totalPages() }}</span>
        <button (click)="nextPage()" [disabled]="currentPage() >= totalPages()">Next ›</button>
        <select (change)="setZoom($any($event.target).value)">
          <option value="0.75">75%</option>
          <option value="1.0" selected>100%</option>
          <option value="1.25">125%</option>
          <option value="1.5">150%</option>
          <option value="2.0">200%</option>
        </select>
        <button (click)="toggleFullscreen()">⛶</button>
      </div>

      <!-- Canvas container with virtual scroll -->
      <div class="canvas-container" #canvasContainer>
        <canvas #pdfCanvas></canvas>
      </div>

      <!-- Loading indicator -->
      @if (loading()) {
        <div class="loading-overlay">
          <div class="spinner"></div>
        </div>
      }
    </div>
  `,
  styleUrls: ['./pdf-reader.component.scss']
})
export class PdfReaderComponent implements OnInit, OnDestroy {
  @Input({ required: true }) streamUrl!: string;
  @Input({ required: true }) bookId!: number;
  @Input() initialPage = 1;

  @ViewChild('pdfCanvas') canvas!: ElementRef<HTMLCanvasElement>;
  @ViewChild('canvasContainer') container!: ElementRef<HTMLDivElement>;

  private cdr = inject(ChangeDetectorRef);
  private readingApi = inject(ReadingApiService);

  currentPage = signal(1);
  totalPages = signal(0);
  loading = signal(true);

  private pdf: pdfjsLib.PDFDocumentProxy | null = null;
  private renderTask: pdfjsLib.RenderTask | null = null;
  private scale = 1.0;
  private progressSaveTimer: ReturnType<typeof setTimeout> | null = null;

  async ngOnInit(): Promise<void> {
    this.currentPage.set(this.initialPage);
    await this.loadPdf();
  }

  ngOnDestroy(): void {
    if (this.renderTask) this.renderTask.cancel();
    if (this.pdf) this.pdf.destroy();
    if (this.progressSaveTimer) clearTimeout(this.progressSaveTimer);
    // Save final progress
    this.saveProgress();
  }

  private async loadPdf(): Promise<void> {
    this.loading.set(true);
    try {
      const loadingTask = pdfjsLib.getDocument({
        url: this.streamUrl,
        withCredentials: false, // S3 signed URL — no credentials needed
        cMapUrl: '/assets/cmaps/',
        cMapPacked: true,
      });
      this.pdf = await loadingTask.promise;
      this.totalPages.set(this.pdf.numPages);
      await this.renderPage(this.currentPage());
    } catch (error) {
      console.error('Failed to load PDF:', error);
    } finally {
      this.loading.set(false);
    }
  }

  private async renderPage(pageNum: number): Promise<void> {
    if (!this.pdf) return;

    if (this.renderTask) {
      this.renderTask.cancel();
    }

    this.loading.set(true);
    const page = await this.pdf.getPage(pageNum);
    const viewport = page.getViewport({ scale: this.scale });

    const canvas = this.canvas.nativeElement;
    canvas.height = viewport.height;
    canvas.width = viewport.width;

    const ctx = canvas.getContext('2d')!;
    this.renderTask = page.render({ canvasContext: ctx, viewport });

    try {
      await this.renderTask.promise;
    } catch {
      // Render cancelled — normal when navigating quickly
    } finally {
      this.loading.set(false);
      this.cdr.markForCheck();
    }

    // Schedule progress save
    this.scheduleSaveProgress();
  }

  async nextPage(): Promise<void> {
    if (this.currentPage() < this.totalPages()) {
      this.currentPage.update(p => p + 1);
      await this.renderPage(this.currentPage());
    }
  }

  async prevPage(): Promise<void> {
    if (this.currentPage() > 1) {
      this.currentPage.update(p => p - 1);
      await this.renderPage(this.currentPage());
    }
  }

  setZoom(scale: string): void {
    this.scale = parseFloat(scale);
    this.renderPage(this.currentPage());
  }

  toggleFullscreen(): void {
    const el = this.container.nativeElement;
    if (!document.fullscreenElement) {
      el.requestFullscreen();
    } else {
      document.exitFullscreen();
    }
  }

  private scheduleSaveProgress(): void {
    if (this.progressSaveTimer) clearTimeout(this.progressSaveTimer);
    this.progressSaveTimer = setTimeout(() => this.saveProgress(), 3000);
  }

  private saveProgress(): void {
    const percentage = (this.currentPage() / this.totalPages()) * 100;
    this.readingApi.updateProgress(this.bookId, {
      current_page: this.currentPage(),
      percentage: Math.round(percentage * 100) / 100
    }).subscribe();
  }
}
```

---

## 9. Audiobook Player Component

```typescript
// src/app/features/audio-player/components/audio-player/audio-player.component.ts
import {
  Component, Input, OnInit, OnDestroy, signal, computed,
  ChangeDetectionStrategy, inject
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReadingApiService } from '../../../../core/http/reading-api.service';
import { AudiobookChapter } from '../../../../core/models';

@Component({
  selector: 'app-audio-player',
  standalone: true,
  imports: [CommonModule, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="audio-player">
      <!-- Chapter list -->
      <div class="chapter-list">
        @for (chapter of chapters; track chapter.id) {
          <div
            class="chapter-item"
            [class.active]="currentChapter()?.id === chapter.id"
            (click)="loadChapter(chapter)"
          >
            <span class="chapter-number">{{ chapter.chapter_number }}</span>
            <span class="chapter-title">{{ chapter.title }}</span>
            <span class="chapter-duration">{{ chapter.duration | duration }}</span>
          </div>
        }
      </div>

      <!-- Player controls -->
      <div class="player-controls">
        <div class="progress-bar">
          <input
            type="range"
            min="0"
            [max]="duration()"
            [value]="currentTime()"
            (input)="seek($any($event.target).value)"
            class="progress-slider"
          />
          <div class="time-display">
            <span>{{ currentTime() | duration }}</span>
            <span>{{ duration() | duration }}</span>
          </div>
        </div>

        <div class="buttons">
          <button (click)="skipBack(15)" title="15s back">-15</button>
          <button (click)="prevChapter()" [disabled]="!hasPrevChapter()">⏮</button>
          <button (click)="togglePlayPause()" class="play-btn">
            {{ isPlaying() ? '⏸' : '▶' }}
          </button>
          <button (click)="nextChapter()" [disabled]="!hasNextChapter()">⏭</button>
          <button (click)="skipForward(30)" title="30s forward">+30</button>
        </div>

        <div class="secondary-controls">
          <select (change)="setSpeed($any($event.target).value)">
            <option value="0.5">0.5x</option>
            <option value="0.75">0.75x</option>
            <option value="1" selected>1x</option>
            <option value="1.25">1.25x</option>
            <option value="1.5">1.5x</option>
            <option value="2">2x</option>
          </select>
          <input type="range" min="0" max="1" step="0.1"
            [value]="volume()" (input)="setVolume($any($event.target).value)" />
        </div>
      </div>
    </div>
  `,
  styleUrls: ['./audio-player.component.scss']
})
export class AudioPlayerComponent implements OnInit, OnDestroy {
  @Input({ required: true }) audiobookId!: number;
  @Input({ required: true }) chapters!: AudiobookChapter[];
  @Input() initialChapterId?: number;
  @Input() initialPosition = 0;

  private readingApi = inject(ReadingApiService);
  private audio = new Audio();
  private progressTimer: ReturnType<typeof setInterval> | null = null;

  currentChapter = signal<AudiobookChapter | null>(null);
  isPlaying = signal(false);
  currentTime = signal(0);
  duration = signal(0);
  volume = signal(1);
  isLoading = signal(false);

  hasPrevChapter = computed(() => {
    if (!this.currentChapter()) return false;
    const idx = this.chapters.findIndex(c => c.id === this.currentChapter()!.id);
    return idx > 0;
  });

  hasNextChapter = computed(() => {
    if (!this.currentChapter()) return false;
    const idx = this.chapters.findIndex(c => c.id === this.currentChapter()!.id);
    return idx < this.chapters.length - 1;
  });

  ngOnInit(): void {
    this.setupAudioListeners();
    const initial = this.initialChapterId
      ? this.chapters.find(c => c.id === this.initialChapterId)
      : this.chapters[0];

    if (initial) {
      this.loadChapter(initial, this.initialPosition);
    }

    this.progressTimer = setInterval(() => this.saveProgress(), 10000);
  }

  ngOnDestroy(): void {
    this.audio.pause();
    this.audio.src = '';
    if (this.progressTimer) clearInterval(this.progressTimer);
    this.saveProgress();
  }

  private setupAudioListeners(): void {
    this.audio.addEventListener('timeupdate', () => {
      this.currentTime.set(Math.floor(this.audio.currentTime));
    });

    this.audio.addEventListener('durationchange', () => {
      this.duration.set(Math.floor(this.audio.duration));
    });

    this.audio.addEventListener('ended', () => {
      this.isPlaying.set(false);
      if (this.hasNextChapter()) this.nextChapter();
    });

    this.audio.addEventListener('canplay', () => {
      this.isLoading.set(false);
    });

    this.audio.addEventListener('waiting', () => {
      this.isLoading.set(true);
    });
  }

  loadChapter(chapter: AudiobookChapter, startPosition = 0): void {
    this.currentChapter.set(chapter);
    this.audio.src = chapter.stream_url!;
    this.audio.load();
    if (startPosition > 0) {
      this.audio.currentTime = startPosition;
    }
  }

  togglePlayPause(): void {
    if (this.isPlaying()) {
      this.audio.pause();
      this.isPlaying.set(false);
    } else {
      this.audio.play().then(() => this.isPlaying.set(true));
    }
  }

  seek(value: string): void {
    this.audio.currentTime = parseFloat(value);
    this.currentTime.set(Math.floor(this.audio.currentTime));
  }

  skipBack(seconds: number): void {
    this.audio.currentTime = Math.max(0, this.audio.currentTime - seconds);
  }

  skipForward(seconds: number): void {
    this.audio.currentTime = Math.min(this.audio.duration, this.audio.currentTime + seconds);
  }

  setSpeed(speed: string): void {
    this.audio.playbackRate = parseFloat(speed);
  }

  setVolume(value: string): void {
    this.audio.volume = parseFloat(value);
    this.volume.set(this.audio.volume);
  }

  prevChapter(): void {
    const idx = this.chapters.findIndex(c => c.id === this.currentChapter()!.id);
    if (idx > 0) this.loadChapter(this.chapters[idx - 1]);
  }

  nextChapter(): void {
    const idx = this.chapters.findIndex(c => c.id === this.currentChapter()!.id);
    if (idx < this.chapters.length - 1) this.loadChapter(this.chapters[idx + 1]);
  }

  private saveProgress(): void {
    if (!this.currentChapter()) return;
    const chapterIdx = this.chapters.findIndex(c => c.id === this.currentChapter()!.id);
    this.readingApi.updateAudioProgress(this.audiobookId, {
      current_chapter: this.currentChapter()!.chapter_number,
      current_position: this.currentTime(),
      percentage: ((chapterIdx / this.chapters.length) + (this.currentTime() / this.duration()) / this.chapters.length) * 100
    }).subscribe();
  }
}
```

---

## 10. Search UI with Debounced Autocomplete

```typescript
// src/app/features/search/components/search-bar/search-bar.component.ts
import {
  Component, OnInit, signal, inject, ChangeDetectionStrategy,
  Output, EventEmitter
} from '@angular/core';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  debounceTime, distinctUntilChanged, filter, switchMap, catchError
} from 'rxjs/operators';
import { of } from 'rxjs';
import { BookApiService } from '../../../../core/http/book-api.service';

@Component({
  selector: 'app-search-bar',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="search-bar" [class.has-results]="suggestions().length > 0">
      <input
        [formControl]="searchControl"
        type="search"
        placeholder="Kitob, muallif, mavzu izlang..."
        class="search-input"
        (keydown.enter)="search()"
        (keydown.escape)="clearSuggestions()"
        autocomplete="off"
        aria-label="Search books"
        [attr.aria-expanded]="suggestions().length > 0"
        role="combobox"
      />

      @if (isLoading()) {
        <div class="search-spinner"></div>
      } @else {
        <button (click)="search()" class="search-btn" aria-label="Search">
          <svg><!-- search icon SVG --></svg>
        </button>
      }

      <!-- Autocomplete dropdown -->
      @if (suggestions().length > 0) {
        <div class="autocomplete-dropdown" role="listbox">
          @for (item of suggestions(); track item.id + item.type) {
            <div
              class="suggestion-item suggestion-item--{{ item.type }}"
              role="option"
              (click)="selectSuggestion(item)"
            >
              @if (item.cover) {
                <img [src]="item.cover" [alt]="item.title" class="suggestion-cover" loading="lazy" />
              }
              <div class="suggestion-text">
                <span class="suggestion-type">{{ item.type | titlecase }}</span>
                <span class="suggestion-title">{{ item.title || item.name }}</span>
              </div>
            </div>
          }
          <div class="suggestion-footer" (click)="search()">
            Barcha natijalarni ko'rish →
          </div>
        </div>
      }
    </div>
  `,
  styleUrls: ['./search-bar.component.scss']
})
export class SearchBarComponent implements OnInit {
  @Output() searched = new EventEmitter<string>();

  private bookApi = inject(BookApiService);
  private router = inject(Router);

  searchControl = new FormControl('');
  suggestions = signal<{ type: string; id: number; title?: string; name?: string; cover?: string }[]>([]);
  isLoading = signal(false);

  ngOnInit(): void {
    this.searchControl.valueChanges.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      filter(query => (query?.length ?? 0) >= 2)
    ).subscribe(query => {
      if (!query) { this.clearSuggestions(); return; }
      this.isLoading.set(true);
      this.bookApi.autocomplete(query).pipe(
        catchError(() => of([]))
      ).subscribe(results => {
        this.suggestions.set(results);
        this.isLoading.set(false);
      });
    });

    // Clear suggestions if query is cleared
    this.searchControl.valueChanges.pipe(
      filter(q => !q || q.length < 2)
    ).subscribe(() => this.clearSuggestions());
  }

  search(): void {
    const query = this.searchControl.value;
    if (!query?.trim()) return;
    this.clearSuggestions();
    this.router.navigate(['/search'], { queryParams: { q: query.trim() } });
    this.searched.emit(query.trim());
  }

  selectSuggestion(item: { type: string; id: number }): void {
    this.clearSuggestions();
    if (item.type === 'book') {
      this.router.navigate(['/books', item.id]);
    } else if (item.type === 'author') {
      this.router.navigate(['/authors', item.id]);
    } else if (item.type === 'audiobook') {
      this.router.navigate(['/audiobooks', item.id]);
    }
  }

  clearSuggestions(): void {
    this.suggestions.set([]);
  }
}
```

---

## 11. Admin Panel Module

```typescript
// src/app/features/admin/admin.routes.ts
import { Routes } from '@angular/router';
import { authGuard } from '../../core/guards/auth.guard';
import { roleGuard } from '../../core/guards/role.guard';

export const adminRoutes: Routes = [
  {
    path: '',
    canActivate: [authGuard, roleGuard],
    data: { roles: ['tenant_admin', 'tenant_manager', 'super_admin'] },
    loadComponent: () => import('./pages/admin-layout/admin-layout.component')
      .then(m => m.AdminLayoutComponent),
    children: [
      {
        path: '',
        redirectTo: 'dashboard',
        pathMatch: 'full'
      },
      {
        path: 'dashboard',
        loadComponent: () => import('./pages/dashboard/dashboard.component')
          .then(m => m.DashboardComponent)
      },
      {
        path: 'books',
        loadComponent: () => import('./pages/books-management/books-management.component')
          .then(m => m.BooksManagementComponent)
      },
      {
        path: 'books/create',
        loadComponent: () => import('./pages/book-form/book-form.component')
          .then(m => m.BookFormComponent)
      },
      {
        path: 'books/:id/edit',
        loadComponent: () => import('./pages/book-form/book-form.component')
          .then(m => m.BookFormComponent)
      },
      {
        path: 'users',
        canActivate: [roleGuard],
        data: { roles: ['tenant_admin', 'super_admin'] },
        loadComponent: () => import('./pages/users-management/users-management.component')
          .then(m => m.UsersManagementComponent)
      },
      {
        path: 'analytics',
        loadComponent: () => import('./pages/analytics/analytics.component')
          .then(m => m.AnalyticsComponent)
      },
      {
        path: 'settings',
        canActivate: [roleGuard],
        data: { roles: ['tenant_admin'] },
        loadComponent: () => import('./pages/settings/settings.component')
          .then(m => m.SettingsComponent)
      }
    ]
  }
];
```

---

## 12. Lazy Loading Strategy

```typescript
// src/app/app.routes.ts
import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';
import { tenantGuard } from './core/guards/tenant.guard';

export const routes: Routes = [
  {
    path: '',
    canActivate: [tenantGuard],
    children: [
      {
        path: '',
        loadComponent: () => import('./features/catalog/pages/home/home.component')
          .then(m => m.HomeComponent)
      },
      {
        path: 'books',
        loadChildren: () => import('./features/catalog/catalog.routes')
          .then(m => m.catalogRoutes)
      },
      {
        path: 'audiobooks',
        loadChildren: () => import('./features/audio-player/audio-player.routes')
          .then(m => m.audioPlayerRoutes)
      },
      {
        path: 'search',
        loadComponent: () => import('./features/search/pages/search-results/search-results.component')
          .then(m => m.SearchResultsComponent)
      },
      {
        path: 'read/:bookId',
        canActivate: [authGuard],
        loadChildren: () => import('./features/reader/reader.routes')
          .then(m => m.readerRoutes)
      },
      {
        path: 'auth',
        loadChildren: () => import('./features/auth/auth.routes')
          .then(m => m.authRoutes)
      },
      {
        path: 'user',
        canActivate: [authGuard],
        loadChildren: () => import('./features/user/user.routes')
          .then(m => m.userRoutes)
      },
      {
        path: 'admin',
        loadChildren: () => import('./features/admin/admin.routes')
          .then(m => m.adminRoutes)
      }
    ]
  },
  { path: 'tenant-not-found', loadComponent: () => import('./shared/components/tenant-not-found/tenant-not-found.component').then(m => m.TenantNotFoundComponent) },
  { path: 'unauthorized', loadComponent: () => import('./shared/components/unauthorized/unauthorized.component').then(m => m.UnauthorizedComponent) },
  { path: '**', loadComponent: () => import('./shared/components/not-found/not-found.component').then(m => m.NotFoundComponent) }
];
```

---

## 13. Performance Optimizations

### Virtual Scroll for Long Lists

```typescript
// Use CDK Virtual Scroll for book lists with thousands of items
import { ScrollingModule } from '@angular/cdk/scrolling';

@Component({
  template: `
    <cdk-virtual-scroll-viewport itemSize="280" class="book-scroll-viewport">
      <div *cdkVirtualFor="let book of books; trackBy: trackByBookId" class="book-item">
        <app-book-card [book]="book" />
      </div>
    </cdk-virtual-scroll-viewport>
  `
})
export class BookListComponent {
  trackByBookId = (_: number, book: Book) => book.id;
}
```

### OnPush Change Detection

All components use `ChangeDetectionStrategy.OnPush` — Angular only checks for changes when:
1. An `@Input()` reference changes
2. A signal emits (Angular 17 signals)
3. An Observable bound via `async` pipe emits
4. `markForCheck()` is called explicitly

### Image Lazy Loading

```typescript
// src/app/shared/components/image-lazy/image-lazy.component.ts
@Component({
  selector: 'app-image-lazy',
  standalone: true,
  template: `
    <img
      [src]="loaded ? src : placeholder"
      [alt]="alt"
      loading="lazy"
      decoding="async"
      (load)="onLoad()"
      [class.loaded]="loaded"
      [class.loading]="!loaded"
    />
  `
})
export class ImageLazyComponent {
  @Input({ required: true }) src!: string;
  @Input() alt = '';
  @Input() placeholder = '/assets/placeholder-book.svg';
  loaded = false;

  onLoad(): void { this.loaded = true; }
}
```

### Bundle Optimization

```json
// angular.json build config
{
  "optimization": {
    "scripts": true,
    "styles": { "minify": true, "inlineCritical": true },
    "fonts": true
  },
  "outputHashing": "all",
  "budgets": [
    { "type": "initial", "maximumWarning": "500kb", "maximumError": "1mb" },
    { "type": "anyComponentStyle", "maximumWarning": "4kb" }
  ]
}
```

---

## 14. SSR with Angular Universal

```typescript
// src/app/app.config.server.ts
import { mergeApplicationConfig, ApplicationConfig } from '@angular/core';
import { provideServerRendering } from '@angular/platform-server';
import { appConfig } from './app.config';

const serverConfig: ApplicationConfig = {
  providers: [
    provideServerRendering(),
  ]
};

export const config = mergeApplicationConfig(appConfig, serverConfig);
```

### SSR Considerations for Multi-Tenant

```typescript
// Tenant detection in SSR context (server.ts)
// Read tenant from request headers/hostname before rendering
app.get('*', (req, res, next) => {
  const { hostname } = req;
  // Pass hostname as transfer state for client hydration
  commonEngine.render({
    bootstrap,
    documentFilePath: indexHtml,
    url: `${protocol}://${headers.host}${originalUrl}`,
    publicPath: browserDistFolder,
    providers: [
      { provide: APP_BASE_HREF, useValue: req.baseUrl },
      { provide: 'SSR_HOSTNAME', useValue: hostname },
    ],
  }).then(html => res.send(html));
});
```

---

## 15. Error Handling and Retry Logic

```typescript
// src/app/core/http/error.interceptor.ts
import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { retry, catchError, throwError } from 'rxjs';
import { NotificationService } from '../services/notification.service';

export const errorInterceptor: HttpInterceptorFn = (req, next) => {
  const notif = inject(NotificationService);

  return next(req).pipe(
    retry({
      count: 2,
      delay: (error: HttpErrorResponse, retryCount) => {
        // Retry network errors but not 4xx errors
        if (error.status >= 400 && error.status < 500) {
          return throwError(() => error);
        }
        return new Promise(resolve => setTimeout(resolve, retryCount * 1000));
      }
    }),
    catchError((error: HttpErrorResponse) => {
      switch (error.status) {
        case 0:
          notif.error('Internetga ulanishda muammo. Iltimos, tekshiring.');
          break;
        case 403:
          notif.error('Bu amalni bajarish uchun ruxsatingiz yo\'q.');
          break;
        case 404:
          // Let components handle 404 individually
          break;
        case 422:
          // Validation errors handled by forms
          break;
        case 429:
          notif.warning('So\'rovlar haddan tashqari ko\'p. Iltimos, kuting.');
          break;
        case 500:
        case 503:
          notif.error('Server xatosi. Texnik xizmat xabardor qilindi.');
          break;
      }
      return throwError(() => error);
    })
  );
};
```

---

## 16. Internationalization (i18n)

```typescript
// Using @ngx-translate/core for dynamic language switching

// src/assets/i18n/uz.json
{
  "COMMON": {
    "LOADING": "Yuklanmoqda...",
    "ERROR": "Xato yuz berdi",
    "SAVE": "Saqlash",
    "CANCEL": "Bekor qilish",
    "DELETE": "O'chirish"
  },
  "AUTH": {
    "LOGIN": "Kirish",
    "REGISTER": "Ro'yxatdan o'tish",
    "EMAIL": "Elektron pochta",
    "PASSWORD": "Parol",
    "FORGOT_PASSWORD": "Parolni unutdingizmi?"
  },
  "BOOKS": {
    "TITLE": "Kitob nomi",
    "AUTHOR": "Muallif",
    "DOWNLOAD": "Yuklab olish",
    "READ_NOW": "Hozir o'qish",
    "ADD_FAVORITE": "Sevimlilarga qo'shish"
  }
}

// app.config.ts — setup TranslateModule
import { TranslateHttpLoader } from '@ngx-translate/http-loader';
export function HttpLoaderFactory(http: HttpClient) {
  return new TranslateHttpLoader(http, '/assets/i18n/', '.json');
}
```
