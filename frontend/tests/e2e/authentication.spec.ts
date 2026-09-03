import { expect, test } from "@playwright/test";

test("register, login, refresh, me, logout, and dashboard protection", async ({
  page,
}) => {
  const email = `frontend-${Date.now()}@example.test`;
  const password = "secure-password";

  const initialSessionCheck = page.waitForResponse(
    (response) => response.url().endsWith("/api/me") && response.request().method() === "GET",
  );
  await page.goto("/register");
  await initialSessionCheck;
  await page.getByLabel("Name").fill("Frontend Test User");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByLabel("Confirm password").fill(password);
  await page.getByRole("button", { name: "Create account" }).click();

  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole("heading", { name: "Administrator access required" })).toBeVisible();

  await page.getByRole("button", { name: "Logout" }).click();
  await expect(page).toHaveURL(/\/login$/);

  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Login" }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole("heading", { name: "Administrator access required" })).toBeVisible();

  await page.reload();
  await expect(page.getByRole("heading", { name: "Administrator access required" })).toBeVisible();

  const meResponse = await page.evaluate(async () => {
    const response = await fetch("http://localhost:8000/api/me", {
      credentials: "include",
      headers: { Accept: "application/json" },
    });
    return { body: await response.json(), status: response.status };
  });
  expect(meResponse.status).toBe(200);
  expect(meResponse.body.data.email).toBe(email);

  await page.getByRole("button", { name: "Logout" }).click();
  await expect(page).toHaveURL(/\/login$/);
  await page.goto("/dashboard");
  await expect(page).toHaveURL(/\/login\?next=%2Fdashboard$/);
});
