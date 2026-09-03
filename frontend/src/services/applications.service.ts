import { apiClient } from "@/services/api-client";
import type { ApiResponse, PaginatedResponse } from "@/types/api";
import type { Application, ApplicationAccess, ApplicationUser } from "@/types/application";

export interface ApplicationInput { name: string; slug: string; description?: string | null }

export const applicationsService = {
  async list(search = ""): Promise<PaginatedResponse<Application>> {
    const response = await apiClient.get<PaginatedResponse<Application>>("/api/admin/applications", { params: { search: search || undefined, per_page: 100 } });
    return response.data;
  },
  async get(id: string): Promise<Application> {
    const response = await apiClient.get<ApiResponse<Application>>(`/api/admin/applications/${id}`);
    return response.data.data;
  },
  async create(input: ApplicationInput): Promise<Application> {
    const response = await apiClient.post<ApiResponse<Application>>("/api/admin/applications", input);
    return response.data.data;
  },
  async update(id: string, input: ApplicationInput): Promise<Application> {
    const response = await apiClient.patch<ApiResponse<Application>>(`/api/admin/applications/${id}`, input);
    return response.data.data;
  },
  async changeStatus(id: string, action: "activate" | "deactivate"): Promise<Application> {
    const response = await apiClient.patch<ApiResponse<Application>>(`/api/admin/applications/${id}/${action}`);
    return response.data.data;
  },
  async forUser(userId: string): Promise<ApplicationAccess[]> {
    const response = await apiClient.get<PaginatedResponse<ApplicationAccess>>(`/api/admin/users/${userId}/applications`, { params: { per_page: 100 } });
    return response.data.data;
  },
  async users(applicationId: string): Promise<ApplicationUser[]> {
    const response = await apiClient.get<PaginatedResponse<ApplicationUser>>(`/api/admin/applications/${applicationId}/users`, { params: { per_page: 100 } });
    return response.data.data;
  },
  async grant(userId: string, applicationId: string): Promise<ApplicationAccess> {
    const response = await apiClient.post<ApiResponse<ApplicationAccess>>(`/api/admin/users/${userId}/applications`, { application_id: applicationId });
    return response.data.data;
  },
  async revoke(userId: string, applicationId: string): Promise<void> {
    await apiClient.delete(`/api/admin/users/${userId}/applications/${applicationId}`);
  },
};
