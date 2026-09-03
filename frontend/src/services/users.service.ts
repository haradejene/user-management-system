import { apiClient } from "@/services/api-client";
import type { ApiResponse, PaginatedResponse } from "@/types/api";
import type { Company } from "@/types/company";
import type { User, UserInput } from "@/types/user";

export const usersService = {
  async list(search = ""): Promise<PaginatedResponse<User>> {
    const response = await apiClient.get<PaginatedResponse<User>>("/api/admin/users", { params: { search: search || undefined, per_page: 100 } });
    return response.data;
  },
  async get(id: string): Promise<User> {
    const response = await apiClient.get<ApiResponse<User>>(`/api/admin/users/${id}`);
    return response.data.data;
  },
  async create(input: UserInput): Promise<User> {
    const response = await apiClient.post<ApiResponse<User>>("/api/admin/users", input);
    return response.data.data;
  },
  async update(id: string, input: UserInput): Promise<User> {
    const response = await apiClient.patch<ApiResponse<User>>(`/api/admin/users/${id}`, input);
    return response.data.data;
  },
  async changeStatus(id: string, action: "deactivate" | "suspend" | "reactivate"): Promise<User> {
    const response = await apiClient.patch<ApiResponse<User>>(`/api/admin/users/${id}/${action}`);
    return response.data.data;
  },
  async companies(id: string): Promise<Company[]> {
    const response = await apiClient.get<PaginatedResponse<Company>>(`/api/admin/users/${id}/companies`, { params: { per_page: 100 } });
    return response.data.data;
  },
};
