import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiService } from './api.service';
import { Book, PaginatedBooks } from '../models/book.model';

export interface BookFilters {
  page?: number;
  per_page?: number;
  search?: string;
  category_id?: number;
  author_id?: number;
  language?: string;
  is_free?: boolean;
  is_featured?: boolean;
  sort?: string;
}

@Injectable({ providedIn: 'root' })
export class BookService {
  private readonly api = inject(ApiService);

  getBooks(filters?: BookFilters): Observable<PaginatedBooks> {
    const params: Record<string, string | number> = {};
    if (filters) {
      Object.entries(filters).forEach(([k, v]) => {
        if (v !== undefined && v !== null) params[k] = v as string | number;
      });
    }
    return this.api.get<PaginatedBooks>('/books', params);
  }

  getBook(slug: string): Observable<{ success: boolean; data: Book }> {
    return this.api.get<{ success: boolean; data: Book }>(`/books/${slug}`);
  }

  getFeatured(): Observable<PaginatedBooks> {
    return this.getBooks({ is_featured: true, per_page: 10 });
  }

  getFree(): Observable<PaginatedBooks> {
    return this.getBooks({ is_free: true, per_page: 12 });
  }

  search(query: string, page = 1): Observable<PaginatedBooks> {
    return this.getBooks({ search: query, page, per_page: 12 });
  }

  getReadUrl(bookId: number): Observable<{ success: boolean; data: { url: string; expires_in: number } }> {
    return this.api.get(`/books/${bookId}/read`);
  }

  getDownloadUrl(bookId: number): Observable<{ success: boolean; data: { url: string } }> {
    return this.api.get(`/books/${bookId}/download`);
  }
}
