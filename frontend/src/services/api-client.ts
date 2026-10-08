import axios, { AxiosError } from "axios";

import type { ValidationErrorResponse } from "@/types/api";

export const apiClient = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000",
  headers: {
    Accept: "application/json",
  },
  withCredentials: true,
  withXSRFToken: true,
});

export function getApiErrorMessage(error: unknown): string {
  if (error instanceof AxiosError) {
    const status = error.response?.status;
    if (status === 401) return "Your session has expired. Sign in again.";
    if (status === 403) return "You are not authorized to perform this administrator action.";
    if (status === 404) return "The requested user, application, or client no longer exists.";
    if (status === 409) return "The configuration changed or is no longer editable. Close and reopen this view before trying again.";
    if (status === 429) return "Too many requests. Wait a moment before trying again.";
    if (status && status >= 500) return "The identity service could not complete the request. Try again later.";
    const response = error.response?.data as
      Partial<ValidationErrorResponse> | undefined;
    const firstValidationError = response?.errors
      ? Object.values(response.errors).flat()[0]
      : undefined;

    return (
      firstValidationError ??
      "The request could not be completed."
    );
  }

  return "Unable to connect to the identity service.";
}
