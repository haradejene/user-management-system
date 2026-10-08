"use client";

import { AxiosError } from "axios";
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";

import { AuthContext } from "@/hooks/useAuth";
import { apiClient, getApiErrorMessage } from "@/services/api-client";
import { authService } from "@/services/auth.service";
import type { AuthUser, LoginInput, RegisterInput } from "@/types/auth";

export function AuthProvider({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const authenticated = useRef(false);
  useLayoutEffect(() => { authenticated.current = user !== null; }, [user]);

  useEffect(() => {
    const interceptor = apiClient.interceptors.response.use(
      (response) => response,
      (requestError: unknown) => {
        if (requestError instanceof AxiosError) {
          const status = requestError.response?.status;
          // A permission-only 403 must not sign out an otherwise valid user.
          const inactiveAccount = status === 403 &&
            requestError.response?.data?.message === "Your account is not active.";
          if (status === 401 || inactiveAccount) {
            setUser(null);
            if (authenticated.current) {
              setError(inactiveAccount
                ? "Your account is not active."
                : "Your session has expired. Please log in again.");
            }
          }
        }
        return Promise.reject(requestError);
      },
    );

    return () => apiClient.interceptors.response.eject(interceptor);
  }, []);

  const refresh = useCallback(async (): Promise<void> => {
    setIsLoading(true);
    setError(null);

    try {
      setUser(await authService.me());
    } catch (requestError) {
      setUser(null);
      if (!(
        requestError instanceof AxiosError &&
        requestError.response?.status === 401
      )) {
        setError(getApiErrorMessage(requestError));
      }
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    let isCurrent = true;

    authService
      .me()
      .then((currentUser) => {
        if (isCurrent) setUser(currentUser);
      })
      .catch((requestError: unknown) => {
        if (!isCurrent) return;
        setUser(null);
        if (!(
          requestError instanceof AxiosError &&
          requestError.response?.status === 401
        )) {
          setError(getApiErrorMessage(requestError));
        }
      })
      .finally(() => {
        if (isCurrent) setIsLoading(false);
      });

    return () => {
      isCurrent = false;
    };
  }, []);

  const value = useMemo(
    () => ({
      user,
      isLoading,
      error,
      refresh,
      async login(input: LoginInput): Promise<AuthUser> {
        const authenticatedUser = await authService.login(input);
        setUser(authenticatedUser);
        setError(null);
        return authenticatedUser;
      },
      async register(input: RegisterInput): Promise<AuthUser> {
        const registeredUser = await authService.register(input);
        setUser(registeredUser);
        setError(null);
        return registeredUser;
      },
      async logout(): Promise<void> {
        try {
          await authService.logout();
        } finally {
          setUser(null);
          setError(null);
        }
      },
    }),
    [error, isLoading, refresh, user],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
