import { apiClient } from "@/services/api-client";
import type { ApiResponse } from "@/types/api";
import type { ProfileInput, UserProfile } from "@/types/profile";

export const profileService = {
  async me(): Promise<UserProfile> {
    const response = await apiClient.get<ApiResponse<UserProfile>>("/api/profile");
    return response.data.data;
  },
  async updateMe(input: ProfileInput): Promise<UserProfile> {
    const response = await apiClient.patch<ApiResponse<UserProfile>>("/api/profile", input);
    return response.data.data;
  },
  async forUser(id: string): Promise<UserProfile> {
    const response = await apiClient.get<ApiResponse<UserProfile>>(`/api/admin/users/${id}/profile`);
    return response.data.data;
  },
  async updateUser(id: string, input: ProfileInput): Promise<UserProfile> {
    const response = await apiClient.patch<ApiResponse<UserProfile>>(`/api/admin/users/${id}/profile`, input);
    return response.data.data;
  },
};
