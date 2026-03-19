import { Component, inject, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { FormControl, ReactiveFormsModule } from '@angular/forms';
import { CurrencyPipe, UpperCasePipe } from '@angular/common';
import { MatCardModule } from '@angular/material/card';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatIconModule } from '@angular/material/icon';
import { MatPaginatorModule, PageEvent } from '@angular/material/paginator';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { debounceTime, distinctUntilChanged } from 'rxjs';
import { BookService } from '../../../core/services/book.service';
import { Book } from '../../../core/models/book.model';

@Component({
  selector: 'app-book-list',
  standalone: true,
  imports: [
    RouterLink, ReactiveFormsModule, CurrencyPipe, UpperCasePipe,
    MatCardModule, MatButtonModule, MatFormFieldModule,
    MatInputModule, MatIconModule, MatPaginatorModule, MatProgressSpinnerModule,
  ],
  template: `
    <div class="header">
      <h1>Kitoblar</h1>
      <mat-form-field appearance="outline">
        <mat-label>Qidirish</mat-label>
        <input matInput [formControl]="searchControl" placeholder="Kitob nomi yoki muallif...">
        <mat-icon matSuffix>search</mat-icon>
      </mat-form-field>
    </div>

    @if (loading()) {
      <div class="loading"><mat-spinner></mat-spinner></div>
    } @else {
      <div class="books-grid">
        @for (book of books(); track book.id) {
          <mat-card class="book-card" [routerLink]="['/books', book.slug]">
            <img mat-card-image [src]="book.cover_thumbnail ?? 'assets/no-cover.png'" [alt]="book.title">
            <mat-card-content>
              <h3 class="title">{{ book.title }}</h3>
              <p class="author">{{ book.author?.name ?? 'Noma\'lum' }}</p>
              <p class="meta">
                @if (book.is_free) { 🆓 Bepul } @else { 💰 {{ book.price | currency:'UZS' }} }
              </p>
            </mat-card-content>
            <mat-card-actions>
              <a mat-button color="primary" [routerLink]="['/books', book.slug]">Batafsil</a>
            </mat-card-actions>
          </mat-card>
        }
      </div>

      <mat-paginator
        [length]="total()"
        [pageSize]="pageSize"
        [pageSizeOptions]="[12, 24, 48]"
        (page)="onPage($event)">
      </mat-paginator>
    }
  `,
  styles: [`
    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
    .books-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; margin-bottom: 24px; }
    .book-card { cursor: pointer; }
    .book-card img { height: 260px; object-fit: cover; }
    .title { font-size: 0.95rem; font-weight: 500; margin: 8px 0 4px; line-height: 1.3; }
    .author { color: #666; font-size: 0.8rem; margin: 0; }
    .meta { font-size: 0.8rem; color: #444; margin: 4px 0 0; }
    .loading { display: flex; justify-content: center; padding: 48px; }
  `],
})
export class BookListComponent implements OnInit {
  private readonly bookService = inject(BookService);

  readonly books = signal<Book[]>([]);
  readonly total = signal(0);
  readonly loading = signal(false);

  readonly searchControl = new FormControl('');
  readonly pageSize = 12;
  private currentPage = 1;

  ngOnInit(): void {
    this.loadBooks();

    this.searchControl.valueChanges.pipe(
      debounceTime(400),
      distinctUntilChanged(),
    ).subscribe(() => {
      this.currentPage = 1;
      this.loadBooks();
    });
  }

  onPage(event: PageEvent): void {
    this.currentPage = event.pageIndex + 1;
    this.loadBooks();
  }

  private loadBooks(): void {
    this.loading.set(true);
    this.bookService.getBooks({
      page: this.currentPage,
      per_page: this.pageSize,
      search: this.searchControl.value ?? undefined,
    }).subscribe({
      next: (res) => {
        this.books.set(res.data);
        this.total.set(res.meta.total);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }
}
