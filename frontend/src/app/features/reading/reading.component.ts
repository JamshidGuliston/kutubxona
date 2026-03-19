import { Component, inject, OnInit, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatSliderModule } from '@angular/material/slider';
import { BookService } from '../../core/services/book.service';

@Component({
  selector: 'app-reading',
  standalone: true,
  imports: [MatProgressSpinnerModule, MatButtonModule, MatIconModule, MatSliderModule],
  template: `
    @if (loading()) {
      <div class="loading"><mat-spinner></mat-spinner></div>
    } @else if (readUrl()) {
      <div class="reader-container">
        <div class="reader-toolbar">
          <button mat-icon-button (click)="history.back()">
            <mat-icon>arrow_back</mat-icon>
          </button>
          <span>O'qish rejimi</span>
        </div>
        <iframe [src]="readUrl()!" class="pdf-viewer" allow="fullscreen"></iframe>
      </div>
    } @else {
      <p>Kitobni yuklashda xatolik yuz berdi.</p>
    }
  `,
  styles: [`
    .loading { display:flex; justify-content:center; padding:48px; }
    .reader-container { display:flex; flex-direction:column; height:calc(100vh - 64px); }
    .reader-toolbar { display:flex; align-items:center; gap:12px; padding:8px; background:#f5f5f5; }
    .pdf-viewer { flex:1; border:none; width:100%; }
  `],
})
export class ReadingComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly bookService = inject(BookService);

  readonly history = window.history;
  readonly readUrl = signal<string | null>(null);
  readonly loading = signal(true);

  ngOnInit(): void {
    const bookId = Number(this.route.snapshot.paramMap.get('bookId'));
    this.bookService.getReadUrl(bookId).subscribe({
      next: (res) => { this.readUrl.set(res.data.url); this.loading.set(false); },
      error: () => this.loading.set(false),
    });
  }
}
