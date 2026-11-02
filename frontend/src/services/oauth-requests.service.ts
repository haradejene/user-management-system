import { apiClient } from "./api-client";
import type { OAuthApplicationRequestDetail } from "@/types/developers";

export interface ListOAuthRequestsParams {
  status?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

export interface PaginatedOAuthRequests {
  data: OAuthApplicationRequestDetail[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface OAuthRequestStats {
  pending: number;
  approved: number;
  rejected: number;
  total: number;
}

export const oauthRequestsService = {
  async list(params: ListOAuthRequestsParams = {}): Promise<PaginatedOAuthRequests> {
    const res = await apiClient.get<PaginatedOAuthRequests>("/api/admin/oauth-requests", {
      params,
    });
    return res.data;
  },

  async stats(): Promise<OAuthRequestStats> {
    const res = await apiClient.get<OAuthRequestStats>("/api/admin/oauth-requests/stats");
    return res.data;
  },

  async get(id: string): Promise<OAuthApplicationRequestDetail> {
    const res = await apiClient.get<{ data: OAuthApplicationRequestDetail }>(
      `/api/admin/oauth-requests/${encodeURIComponent(id)}`
    );
    return res.data.data;
  },

  async approve(
    id: string,
    notes?: string
  ): Promise<{ message: string; data: OAuthApplicationRequestDetail; client_secret?: string }> {
    const res = await apiClient.post<{
      message: string;
      data: OAuthApplicationRequestDetail;
      client_secret?: string;
    }>(`/api/admin/oauth-requests/${encodeURIComponent(id)}/approve`, {
      notes,
    });
    return res.data;
  },

  async reject(
    id: string,
    notes?: string
  ): Promise<{ message: string; data: OAuthApplicationRequestDetail }> {
    const res = await apiClient.post<{
      message: string;
      data: OAuthApplicationRequestDetail;
    }>(`/api/admin/oauth-requests/${encodeURIComponent(id)}/reject`, {
      notes,
    });
    return res.data;
  },
};

