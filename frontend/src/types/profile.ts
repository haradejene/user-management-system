export interface UserProfile {
  user_id: string;
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
  photo: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export type ProfileInput = Partial<Pick<UserProfile, "first_name" | "last_name" | "phone" | "photo">>;
