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
    const response = error.response?.data as
      Partial<ValidationErrorResponse> | undefined;
    const firstValidationError = response?.errors
      ? Object.values(response.errors).flat()[0]
      : undefined;

    return (
      firstValidationError ??
      response?.message ??
      "The request could not be completed."
    );
  }

  return "Unable to connect to the identity service.";
}
