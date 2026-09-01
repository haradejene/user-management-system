"use client";

import { createContext, useContext } from "react";

import type { AuthUser, LoginInput, RegisterInput } from "@/types/auth";

export interface AuthContextValue {
  user: AuthUser | null;
  isLoading: boolean;
  error: string | null;
  login: (input: LoginInput) => Promise<AuthUser>;
  register: (input: RegisterInput) => Promise<AuthUser>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
}

export const AuthContext = createContext<AuthContextValue | null>(null);

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error("useAuth must be used within AuthProvider.");
  }

  return context;
}
