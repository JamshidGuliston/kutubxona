import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { ApiService } from '../../../core/services/api.service';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  selector: 'app-register',
  standalone: true,
  imports: [
    ReactiveFormsModule, RouterLink,
    MatCardModule, MatFormFieldModule, MatInputModule,
    MatButtonModule, MatIconModule, MatProgressSpinnerModule,
  ],
  template: `
    <div class="register-container">
      <mat-card class="register-card">
        <mat-card-header>
          <mat-card-title>Ro'yxatdan o'tish</mat-card-title>
        </mat-card-header>
        <mat-card-content>
          <form [formGroup]="form" (ngSubmit)="onSubmit()">
            <mat-form-field appearance="outline" class="full-width">
              <mat-label>Ism</mat-label>
              <input matInput formControlName="name">
            </mat-form-field>
            <mat-form-field appearance="outline" class="full-width">
              <mat-label>Email</mat-label>
              <input matInput type="email" formControlName="email">
            </mat-form-field>
            <mat-form-field appearance="outline" class="full-width">
              <mat-label>Parol</mat-label>
              <input matInput type="password" formControlName="password">
            </mat-form-field>
            @if (error()) { <p class="error-msg">{{ error() }}</p> }
            <button mat-raised-button color="primary" type="submit"
              class="full-width" [disabled]="loading() || form.invalid">
              @if (loading()) { <mat-spinner diameter="20"></mat-spinner> }
              @else { Ro'yxatdan o'tish }
            </button>
          </form>
        </mat-card-content>
        <mat-card-actions>
          <a routerLink="/login">Kirish</a>
        </mat-card-actions>
      </mat-card>
    </div>
  `,
  styles: [`
    .register-container { display:flex; justify-content:center; align-items:center; min-height:100vh; background:#f5f5f5; }
    .register-card { width:400px; padding:16px; }
    .full-width { width:100%; margin-bottom:16px; }
    .error-msg { color:red; margin-bottom:8px; }
  `],
})
export class RegisterComponent {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly error = signal('');

  readonly form = this.fb.group({
    name: ['', [Validators.required, Validators.minLength(2)]],
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(8)]],
  });

  onSubmit(): void {
    if (this.form.invalid) return;
    this.loading.set(true);
    this.error.set('');

    this.api.post<any>('/auth/register', this.form.value).subscribe({
      next: () => this.auth.login({ email: this.form.value.email!, password: this.form.value.password! })
        .subscribe({ next: () => this.router.navigate(['/']), error: () => this.router.navigate(['/login']) }),
      error: (err) => { this.error.set(err?.error?.message ?? 'Xatolik'); this.loading.set(false); },
    });
  }
}
