export interface LoginRequest {
  email: string;
  password: string;
}

export interface AuthResponse {
  success: boolean;
  data: {
    user: User;
    token: string;
    refresh_token: string;
    token_type: string;
    expires_in: number;
  };
  message: string;
}

export interface User {
  id: number;
  ulid: string;
  name: string;
  email: string;
  avatar_url: string;
  status: string;
  locale: string;
  is_email_verified: boolean;
  roles: string[];
  tenant: {
    id: number;
    name: string;
    slug: string;
  };
  created_at: string;
  updated_at: string;
}
