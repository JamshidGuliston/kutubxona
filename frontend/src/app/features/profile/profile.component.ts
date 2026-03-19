import { Component, inject, OnInit, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatButtonModule } from '@angular/material/button';
import { MatTabsModule } from '@angular/material/tabs';
import { MatSnackBar, MatSnackBarModule } from '@angular/material/snack-bar';
import { AuthService } from '../../core/services/auth.service';
import { ApiService } from '../../core/services/api.service';

@Component({
  selector: 'app-profile',
  standalone: true,
  imports: [
    ReactiveFormsModule,
    MatCardModule, MatFormFieldModule, MatInputModule,
    MatButtonModule, MatTabsModule, MatSnackBarModule,
  ],
  template: `
    <h1>Profil</h1>
    <mat-tab-group>
      <mat-tab label="Ma'lumotlar">
        <div class="tab-content">
          @if (auth.user()) {
            <div class="avatar-row">
              <img [src]="auth.user()!.avatar_url" [alt]="auth.user()!.name" class="avatar">
              <div>
                <h2>{{ auth.user()!.name }}</h2>
                <p>{{ auth.user()!.email }}</p>
                <p>{{ auth.user()!.tenant.name }}</p>
              </div>
            </div>
          }
          <form [formGroup]="profileForm" (ngSubmit)="saveProfile()">
            <mat-form-field appearance="outline" class="full-width">
              <mat-label>Ism</mat-label>
              <input matInput formControlName="name">
            </mat-form-field>
            <button mat-raised-button color="primary" type="submit" [disabled]="profileForm.invalid">
              Saqlash
            </button>
          </form>
        </div>
      </mat-tab>

      <mat-tab label="O'qish tarixi">
        <div class="tab-content">
          <p>O'qilgan kitoblar ro'yxati...</p>
        </div>
      </mat-tab>

      <mat-tab label="Saralganlar">
        <div class="tab-content">
          <p>Sevimli kitoblar...</p>
        </div>
      </mat-tab>
    </mat-tab-group>
  `,
  styles: [`
    h1 { margin-bottom: 24px; }
    .tab-content { padding: 24px 0; }
    .avatar-row { display: flex; align-items: center; gap: 20px; margin-bottom: 24px; }
    .avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; }
    .full-width { width: 100%; max-width: 400px; display: block; margin-bottom: 16px; }
  `],
})
export class ProfileComponent implements OnInit {
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly fb = inject(FormBuilder);
  private readonly snack = inject(MatSnackBar);

  readonly profileForm = this.fb.group({
    name: ['', [Validators.required, Validators.minLength(2)]],
  });

  ngOnInit(): void {
    const user = this.auth.user();
    if (user) {
      this.profileForm.patchValue({ name: user.name });
    }
  }

  saveProfile(): void {
    this.api.patch('/profile', this.profileForm.value).subscribe({
      next: () => {
        this.auth.me().subscribe();
        this.snack.open('Saqlandi!', 'Yopish', { duration: 3000 });
      },
      error: () => this.snack.open('Xatolik yuz berdi', 'Yopish', { duration: 3000 }),
    });
  }
}
