import { beforeEach, describe, expect, it, vi } from "vitest";

const { get, post } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}));

vi.mock("@/services/api-client", () => ({
  apiClient: { get, post },
}));

import { authService } from "@/services/auth.service";

const user = {
  id: "identity-id",
  name: "Test User",
  email: "user@example.test",
  status: "active" as const,
  is_system_admin: false,
  email_verified_at: null,
  created_at: null,
  updated_at: null,
};

describe("authService", () => {
  beforeEach(() => {
    get.mockReset();
    post.mockReset();
  });

  it("initializes CSRF before login", async () => {
    get.mockResolvedValueOnce({});
    post.mockResolvedValueOnce({ data: { data: user } });

    await expect(
      authService.login({ email: user.email, password: "password" }),
    ).resolves.toEqual(user);
    expect(get).toHaveBeenCalledWith("/sanctum/csrf-cookie");
    expect(post).toHaveBeenCalledWith("/api/login", {
      email: user.email,
      password: "password",
    });
    expect(get.mock.invocationCallOrder[0]).toBeLessThan(
      post.mock.invocationCallOrder[0],
    );
  });

  it("loads the current user and logs out through the Laravel API", async () => {
    get.mockResolvedValueOnce({ data: { data: user } });
    post.mockResolvedValueOnce({});

    await expect(authService.me()).resolves.toEqual(user);
    await expect(authService.logout()).resolves.toBeUndefined();
    expect(get).toHaveBeenCalledWith("/api/me");
    expect(post).toHaveBeenCalledWith("/api/logout");
  });
});
