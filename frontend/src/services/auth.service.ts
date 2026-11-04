import { apiClient } from "@/services/api-client";
import type { ApiResponse } from "@/types/api";
import type { AuthUser, LoginInput, RegisterInput } from "@/types/auth";

async function initializeCsrf(): Promise<void> {
  await apiClient.get("/sanctum/csrf-cookie");
}

export const authService = {
  async accountChoice(token: string): Promise<{application: string}> {
    return (await apiClient.get<{application: string}>(`/oauth/account-choice/${encodeURIComponent(token)}`)).data;
  },

  async selectAccount(token: string, expectedEmail: string): Promise<string> {
    await initializeCsrf();
    const response = await apiClient.post<{redirect_url: string}>("/oauth/select-account", {
      choice_token: token, choice: "current", expected_email: expectedEmail,
    });
    return response.data.redirect_url;
  },

  async cancelAccountChoice(token: string): Promise<string> {
    await initializeCsrf();
    const response = await apiClient.post<{redirect_url: string}>("/oauth/select-account", {choice_token: token, choice: "cancel"});
    return response.data.redirect_url;
  },
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

  async switchAccount(email: string): Promise<AuthUser> {
    await initializeCsrf();
    const response = await apiClient.post<ApiResponse<AuthUser>>("/api/switch-account", { email });
    return response.data.data;
  },

  async me(): Promise<AuthUser> {
    const response = await apiClient.get<ApiResponse<AuthUser>>("/api/me");
    return response.data.data;
  },

  async logout(): Promise<void> {
    try {
      await initializeCsrf();
      await apiClient.post("/api/logout");
    } catch {
      // Ignored: session is already expired or terminated on server.
    }
  },
};
