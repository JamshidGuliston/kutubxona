import { Component, inject, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { MatCardModule } from '@angular/material/card';
import { MatButtonModule } from '@angular/material/button';
import { MatChipsModule } from '@angular/material/chips';
import { BookService } from '../../core/services/book.service';
import { Book } from '../../core/models/book.model';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [RouterLink, MatCardModule, MatButtonModule, MatChipsModule],
  template: `
    <section class="hero">
      <h1>O'zbek kitoblari kutubxonasi</h1>
      <p>Minglab kitoblar bir joyda — bepul o'qing, yuklab oling</p>
      <a mat-raised-button color="primary" routerLink="/books">Kitoblarni ko'rish</a>
    </section>

    @if (featured().length) {
      <section class="section">
        <h2>Tavsiya etilgan kitoblar</h2>
        <div class="books-grid">
          @for (book of featured(); track book.id) {
            <mat-card class="book-card" [routerLink]="['/books', book.slug]">
              <img mat-card-image [src]="book.cover_thumbnail ?? 'assets/no-cover.png'" [alt]="book.title">
              <mat-card-content>
                <h3>{{ book.title }}</h3>
                <p class="author">{{ book.author?.name ?? 'Noma\'lum' }}</p>
                @if (book.is_free) {
                  <mat-chip color="primary" highlighted>Bepul</mat-chip>
                }
              </mat-card-content>
            </mat-card>
          }
        </div>
      </section>
    }
  `,
  styles: [`
    .hero { text-align: center; padding: 48px 16px; background: linear-gradient(135deg, #3f51b5, #2196f3); color: white; border-radius: 8px; margin-bottom: 32px; }
    .hero h1 { font-size: 2rem; margin-bottom: 8px; }
    .section { margin-bottom: 32px; }
    .section h2 { font-size: 1.5rem; margin-bottom: 16px; }
    .books-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; }
    .book-card { cursor: pointer; transition: box-shadow 0.2s; }
    .book-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    .book-card img { height: 240px; object-fit: cover; }
    .author { color: #666; font-size: 0.85rem; }
  `],
})
export class HomeComponent implements OnInit {
  private readonly bookService = inject(BookService);

  readonly featured = signal<Book[]>([]);

  ngOnInit(): void {
    this.bookService.getFeatured().subscribe({
      next: (res) => this.featured.set(res.data),
    });
  }
}
