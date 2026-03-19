import { Component, inject } from '@angular/core';
import { Router, RouterLink, RouterOutlet } from '@angular/router';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-layout',
  standalone: true,
  imports: [RouterOutlet, RouterLink, MatToolbarModule, MatButtonModule, MatIconModule, MatMenuModule],
  template: `
    <mat-toolbar color="primary">
      <a routerLink="/" class="brand">📚 Kutubxona.uz</a>
      <span class="spacer"></span>
      <a mat-button routerLink="/books">Kitoblar</a>
      @if (auth.isLoggedIn()) {
        <button mat-icon-button [matMenuTriggerFor]="menu">
          <mat-icon>account_circle</mat-icon>
        </button>
        <mat-menu #menu>
          <a mat-menu-item routerLink="/profile">
            <mat-icon>person</mat-icon> Profil
          </a>
          <button mat-menu-item (click)="logout()">
            <mat-icon>logout</mat-icon> Chiqish
          </button>
        </mat-menu>
      } @else {
        <a mat-button routerLink="/login">Kirish</a>
      }
    </mat-toolbar>

    <main class="main-content">
      <router-outlet />
    </main>
  `,
  styles: [`
    .brand { color: white; text-decoration: none; font-size: 1.2rem; font-weight: bold; }
    .spacer { flex: 1; }
    .main-content { max-width: 1200px; margin: 0 auto; padding: 24px 16px; }
  `],
})
export class LayoutComponent {
  readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  logout(): void {
    this.auth.logout().subscribe({
      next: () => this.router.navigate(['/login']),
      error: () => { this.auth.clearSession(); },
    });
  }
}
