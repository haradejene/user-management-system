export type CompanyStatus = "active" | "inactive";

export interface Company {
  id: string;
  name: string;
  status: CompanyStatus;
  membership_status?: CompanyStatus;
  created_at: string | null;
  updated_at: string | null;
}

export interface CompanyMember {
  id: string;
  name: string;
  email: string;
  status: string;
  membership_status: CompanyStatus;
}
