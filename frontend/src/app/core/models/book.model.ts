export interface Book {
  id: number;
  title: string;
  slug: string;
  subtitle: string | null;
  description: string | null;
  author: Author | null;
  publisher: Publisher | null;
  category: Category | null;
  tags: Tag[];
  cover_image: string | null;
  cover_thumbnail: string | null;
  status: 'draft' | 'published' | 'archived' | 'processing';
  language: string;
  published_year: number | null;
  pages: number | null;
  isbn: string | null;
  is_featured: boolean;
  is_free: boolean;
  is_downloadable: boolean;
  price: number | null;
  average_rating: number | null;
  rating_count: number;
  view_count: number;
  download_count: number;
  published_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface Author {
  id: number;
  name: string;
  slug: string;
  bio: string | null;
  photo: string | null;
  birth_year: number | null;
  nationality: string | null;
}

export interface Publisher {
  id: number;
  name: string;
  slug: string;
  logo: string | null;
  country: string | null;
}

export interface Category {
  id: number;
  name: string;
  slug: string;
  icon: string | null;
  color: string | null;
  children?: Category[];
}

export interface Tag {
  id: number;
  name: string;
  slug: string;
}

export interface PaginatedBooks {
  data: Book[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
