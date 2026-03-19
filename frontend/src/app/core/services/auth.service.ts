import { Injectable, inject, signal, computed } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';
import { ApiService } from './api.service';
import { AuthResponse, LoginRequest, User } from '../models/auth.model';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);

  private readonly _user = signal<User | null>(this.loadUser());
  private readonly _token = signal<string | null>(localStorage.getItem('access_token'));

  readonly user = this._user.asReadonly();
  readonly token = this._token.asReadonly();
  readonly isLoggedIn = computed(() => !!this._token());
  readonly isAdmin = computed(() => this._user()?.roles?.includes('tenant_admin') ?? false);
  readonly isSuperAdmin = computed(() => this._user()?.roles?.includes('super_admin') ?? false);

  login(credentials: LoginRequest): Observable<AuthResponse> {
    return this.api.post<AuthResponse>('/auth/login', credentials).pipe(
      tap((res) => {
        if (res.success) {
          this.storeSession(res.data.token, res.data.refresh_token, res.data.user);
        }
      })
    );
  }

  logout(): Observable<unknown> {
    return this.api.post('/auth/logout', {}).pipe(
      tap(() => this.clearSession())
    );
  }

  refreshToken(): Observable<AuthResponse> {
    return this.api.post<AuthResponse>('/auth/refresh', {}).pipe(
      tap((res) => {
        if (res.success) {
          this.storeSession(res.data.token, res.data.refresh_token, res.data.user);
        }
      })
    );
  }

  me(): Observable<{ success: boolean; data: User }> {
    return this.api.get<{ success: boolean; data: User }>('/auth/me').pipe(
      tap((res) => {
        if (res.success) {
          this._user.set(res.data);
          localStorage.setItem('user', JSON.stringify(res.data));
        }
      })
    );
  }

  private storeSession(token: string, refreshToken: string, user: User): void {
    localStorage.setItem('access_token', token);
    localStorage.setItem('refresh_token', refreshToken);
    localStorage.setItem('user', JSON.stringify(user));
    localStorage.setItem('tenant_id', String(user.tenant.id));
    this._token.set(token);
    this._user.set(user);
  }

  clearSession(): void {
    localStorage.removeItem('access_token');
    localStorage.removeItem('refresh_token');
    localStorage.removeItem('user');
    this._token.set(null);
    this._user.set(null);
    this.router.navigate(['/login']);
  }

  private loadUser(): User | null {
    try {
      const raw = localStorage.getItem('user');
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }
}
