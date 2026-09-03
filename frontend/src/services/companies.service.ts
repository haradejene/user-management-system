import { apiClient } from "@/services/api-client";
import type { ApiResponse, PaginatedResponse } from "@/types/api";
import type { Company, CompanyMember } from "@/types/company";

export const companiesService = {
  async list(search = ""): Promise<PaginatedResponse<Company>> {
    const response = await apiClient.get<PaginatedResponse<Company>>("/api/admin/companies", { params: { search: search || undefined, per_page: 100 } });
    return response.data;
  },
  async get(id: string): Promise<Company> {
    const response = await apiClient.get<ApiResponse<Company>>(`/api/admin/companies/${id}`);
    return response.data.data;
  },
  async create(name: string): Promise<Company> {
    const response = await apiClient.post<ApiResponse<Company>>("/api/admin/companies", { name });
    return response.data.data;
  },
  async update(id: string, name: string): Promise<Company> {
    const response = await apiClient.patch<ApiResponse<Company>>(`/api/admin/companies/${id}`, { name });
    return response.data.data;
  },
  async changeStatus(id: string, action: "deactivate" | "reactivate"): Promise<Company> {
    const response = await apiClient.patch<ApiResponse<Company>>(`/api/admin/companies/${id}/${action}`);
    return response.data.data;
  },
  async members(id: string): Promise<CompanyMember[]> {
    const response = await apiClient.get<PaginatedResponse<CompanyMember>>(`/api/admin/companies/${id}/members`, { params: { per_page: 100 } });
    return response.data.data;
  },
  async addMember(companyId: string, userId: string): Promise<CompanyMember> {
    const response = await apiClient.post<ApiResponse<CompanyMember>>(`/api/admin/companies/${companyId}/members`, { user_id: userId });
    return response.data.data;
  },
  async removeMember(companyId: string, userId: string): Promise<void> {
    await apiClient.delete(`/api/admin/companies/${companyId}/members/${userId}`);
  },
};
