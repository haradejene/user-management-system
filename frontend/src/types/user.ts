import type { AccountStatus } from "@/types/auth";

export interface User {
  id: string;
  name: string;
  email: string;
  status: AccountStatus;
  is_system_admin: boolean;
  email_verified_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface UserInput {
  name: string;
  email: string;
  password?: string;
  password_confirmation?: string;
}
