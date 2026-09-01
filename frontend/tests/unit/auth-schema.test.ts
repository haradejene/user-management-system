import { describe, expect, it } from "vitest";

import { loginSchema, registerSchema } from "@/schemas/auth";

describe("authentication form schemas", () => {
  it("accepts valid login input", () => {
    expect(
      loginSchema.safeParse({
        email: "user@example.test",
        password: "password",
      }).success,
    ).toBe(true);
  });

  it("rejects invalid registration input and mismatched passwords", () => {
    const result = registerSchema.safeParse({
      name: "",
      email: "invalid",
      password: "short",
      password_confirmation: "different",
    });

    expect(result.success).toBe(false);
    if (!result.success) {
      expect(result.error.issues.map((issue) => issue.path[0])).toEqual(
        expect.arrayContaining([
          "name",
          "email",
          "password",
          "password_confirmation",
        ]),
      );
    }
  });
});
