import { apiClient } from "@/services/api-client";
import type { ApiResponse } from "@/types/api";
import type { AuthUser, LoginInput, RegisterInput } from "@/types/auth";

async function initializeCsrf(): Promise<void> {
  await apiClient.get("/sanctum/csrf-cookie");
}

export const authService = {
  async register(input: RegisterInput): Promise<AuthUser> {
    await initializeCsrf();
    const response = await apiClient.post<ApiResponse<AuthUser>>(
      "/api/register",
      input,
    );
    return response.data.data;
  },

  async login(input: LoginInput): Promise<AuthUser> {
    await initializeCsrf();
    const response = await apiClient.post<ApiResponse<AuthUser>>(
      "/api/login",
      input,
    );
    return response.data.data;
  },

  async me(): Promise<AuthUser> {
    const response = await apiClient.get<ApiResponse<AuthUser>>("/api/me");
    return response.data.data;
  },

  async logout(): Promise<void> {
    await apiClient.post("/api/logout");
  },
};
