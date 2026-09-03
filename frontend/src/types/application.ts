export type ApplicationStatus = "active" | "inactive";

export interface Application {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  status: ApplicationStatus;
  created_at: string | null;
  updated_at: string | null;
}

export interface ApplicationAccess extends Application {
  access_status: "active" | "inactive";
  granted_at: string | null;
}

export interface ApplicationUser {
  id: string;
  name: string;
  email: string;
  status: string;
  access_status: "active" | "inactive";
  granted_at: string | null;
}
