export interface ApiResponse<T> {
  data: T;
}

export interface ValidationErrorResponse {
  message: string;
  errors: Record<string, string[]>;
}
