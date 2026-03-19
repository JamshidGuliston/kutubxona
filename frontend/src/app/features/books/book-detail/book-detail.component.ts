import { Component, inject, OnInit, signal } from '@angular/core';
import { UpperCasePipe } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatChipsModule } from '@angular/material/chips';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatDividerModule } from '@angular/material/divider';
import { BookService } from '../../../core/services/book.service';
import { AuthService } from '../../../core/services/auth.service';
import { Book } from '../../../core/models/book.model';

@Component({
  selector: 'app-book-detail',
  standalone: true,
  imports: [RouterLink, UpperCasePipe, MatButtonModule, MatIconModule, MatChipsModule, MatProgressSpinnerModule, MatDividerModule],
  template: `
    @if (loading()) {
      <div class="loading"><mat-spinner></mat-spinner></div>
    } @else if (book()) {
      <div class="detail">
        <div class="cover-section">
          <img [src]="book()!.cover_image ?? 'assets/no-cover.png'" [alt]="book()!.title" class="cover">
          <div class="actions">
            @if (auth.isLoggedIn()) {
              <a mat-raised-button color="primary" [routerLink]="['/reading', book()!.id]">
                <mat-icon>menu_book</mat-icon> O'qish
              </a>
              @if (book()!.is_downloadable) {
                <button mat-stroked-button (click)="download()">
                  <mat-icon>download</mat-icon> Yuklab olish
                </button>
              }
            } @else {
              <a mat-raised-button color="primary" routerLink="/login">Kirish kerak</a>
            }
          </div>
        </div>

        <div class="info-section">
          <h1>{{ book()!.title }}</h1>
          @if (book()!.subtitle) { <p class="subtitle">{{ book()!.subtitle }}</p> }

          <p class="author">
            <mat-icon inline>person</mat-icon>
            {{ book()!.author?.name ?? 'Noma\'lum muallif' }}
          </p>

          @if (book()!.category) {
            <p><mat-icon inline>category</mat-icon> {{ book()!.category!.name }}</p>
          }

          <div class="meta-row">
            @if (book()!.pages) { <span>📄 {{ book()!.pages }} bet</span> }
            @if (book()!.language) { <span>🌐 {{ book()!.language | uppercase }}</span> }
            @if (book()!.published_year) { <span>📅 {{ book()!.published_year }}</span> }
            @if (book()!.is_free) {
              <mat-chip color="primary" highlighted>Bepul</mat-chip>
            }
          </div>

          <mat-divider></mat-divider>

          @if (book()!.description) {
            <div class="description">
              <h3>Tavsif</h3>
              <p>{{ book()!.description }}</p>
            </div>
          }

          @if (book()!.tags.length) {
            <mat-chip-set>
              @for (tag of book()!.tags; track tag.id) {
                <mat-chip>{{ tag.name }}</mat-chip>
              }
            </mat-chip-set>
          }
        </div>
      </div>
    }
  `,
  styles: [`
    .loading { display:flex; justify-content:center; padding:48px; }
    .detail { display:grid; grid-template-columns:280px 1fr; gap:32px; }
    @media (max-width:768px) { .detail { grid-template-columns:1fr; } }
    .cover { width:100%; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,0.2); }
    .actions { display:flex; flex-direction:column; gap:12px; margin-top:16px; }
    h1 { font-size:1.8rem; margin-bottom:8px; }
    .subtitle { color:#666; font-style:italic; }
    .author { display:flex; align-items:center; gap:4px; color:#444; }
    .meta-row { display:flex; flex-wrap:wrap; gap:16px; margin:16px 0; }
    .description { margin:16px 0; }
  `],
})
export class BookDetailComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly bookService = inject(BookService);
  readonly auth = inject(AuthService);

  readonly book = signal<Book | null>(null);
  readonly loading = signal(false);

  ngOnInit(): void {
    const slug = this.route.snapshot.paramMap.get('slug')!;
    this.loading.set(true);
    this.bookService.getBook(slug).subscribe({
      next: (res) => { this.book.set(res.data); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }

  download(): void {
    if (!this.book()) return;
    this.bookService.getDownloadUrl(this.book()!.id).subscribe({
      next: (res) => window.open(res.data.url, '_blank'),
    });
  }
}
