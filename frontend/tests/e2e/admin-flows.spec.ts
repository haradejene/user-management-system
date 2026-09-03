import { expect, test } from "@playwright/test";

test.setTimeout(120_000);

test("administrator manages users, companies, applications, and application access", async ({ page }) => {
  const suffix = Date.now();
  const userName = `Admin Flow User ${suffix}`;
  const userEmail = `admin-flow-${suffix}@example.test`;
  const companyName = `Admin Flow Company ${suffix}`;
  const applicationName = `Admin Flow App ${suffix}`;
  const applicationSlug = `admin-flow-${suffix}`;

  const initialSessionCheck = page.waitForResponse(
    (response) => response.url().endsWith("/api/me") && response.request().method() === "GET",
  );
  await page.goto("/login");
  await initialSessionCheck;
  await page.getByLabel("Email").fill("admin@central-iam.test");
  await page.getByLabel("Password").fill("password");
  await page.getByRole("button", { name: "Login" }).click();
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByText("Central IAM administration overview.")).toBeVisible();

  await page.goto("/users/new");
  await page.getByLabel("Name").fill(userName);
  await page.getByLabel("Email").fill(userEmail);
  await page.getByLabel("Password").fill("secure-password");
  const createUserResponse = page.waitForResponse(
    (response) => response.url().endsWith("/api/admin/users") && response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Save user" }).click();
  expect((await createUserResponse).status()).toBe(201);
  await expect(page.getByRole("heading", { name: userName })).toBeVisible({ timeout: 15_000 });

  await page.goto("/companies/new");
  await page.getByLabel("Company name").fill(companyName);
  const createCompanyResponse = page.waitForResponse(
    (response) => response.url().endsWith("/api/admin/companies") && response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Save company" }).click();
  expect((await createCompanyResponse).status()).toBe(201);
  await expect(page.getByRole("heading", { name: companyName })).toBeVisible({ timeout: 15_000 });
  await page.getByLabel("User to add").selectOption({ label: `${userName} — ${userEmail}` });
  const addMemberResponse = page.waitForResponse(
    (response) => response.url().includes("/api/admin/companies/") && response.url().endsWith("/members") && response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Add member" }).click();
  expect((await addMemberResponse).status()).toBe(201);
  await expect(page.getByRole("cell", { name: new RegExp(userEmail) })).toBeVisible({ timeout: 15_000 });

  await page.goto("/applications/new");
  await page.getByLabel("Name").fill(applicationName);
  await page.getByLabel("Slug").fill(applicationSlug);
  await page.getByLabel("Description").fill("Created by the real API browser flow.");
  const createApplicationResponse = page.waitForResponse(
    (response) => response.url().endsWith("/api/admin/applications") && response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Save application" }).click();
  expect((await createApplicationResponse).status()).toBe(201);
  await expect(page.getByRole("heading", { name: applicationName })).toBeVisible({ timeout: 15_000 });

  await page.goto("/application-access");
  await page.getByLabel("User").selectOption({ label: `${userName} — ${userEmail}` });
  const accessToggle = page.getByRole("checkbox", { name: new RegExp(applicationName) });
  const grantResponse = page.waitForResponse(
    (response) => response.url().includes("/api/admin/users/") && response.url().endsWith("/applications") && response.request().method() === "POST",
  );
  await accessToggle.click();
  expect((await grantResponse).status()).toBe(201);
  await expect(accessToggle).toBeChecked();
  await accessToggle.click();
  const revokeResponse = page.waitForResponse(
    (response) => response.url().includes("/api/admin/users/") && response.url().includes("/applications/") && response.request().method() === "DELETE",
  );
  await page.getByRole("button", { name: "Revoke access" }).click();
  expect((await revokeResponse).status()).toBe(204);
  await expect(accessToggle).not.toBeChecked();
});
