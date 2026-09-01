export type AccountStatus = "active" | "inactive" | "suspended";

export interface AuthUser {
  id: string;
  name: string;
  email: string;
  status: AccountStatus;
  is_system_admin: boolean;
  email_verified_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface LoginInput {
  email: string;
  password: string;
  remember?: boolean;
}

export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}
