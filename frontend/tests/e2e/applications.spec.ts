import { expect, test, type Page } from "@playwright/test";
import type { Application } from "../../src/types/application";

// Browser UI contract tests: existing API responses are intercepted so these
// tests neither seed nor mutate the development database.
const application: Application = { id: "11111111-1111-4111-8111-111111111111", name: "Doxa CRM", slug: "crm", description: "Customer management", status: "active", created_at: "2026-01-02T00:00:00Z", updated_at: "2026-02-03T00:00:00Z" };

async function fixtures(page: Page, admin = true) {
  let current = { ...application };
  let failList = false;
  const mutations: { method: string; path: string; body: unknown }[] = [];
  await page.route("**/api/**", async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    const headers = { "Access-Control-Allow-Origin": "http://localhost:3000", "Access-Control-Allow-Credentials": "true", "Access-Control-Allow-Methods": "GET,PATCH,POST,OPTIONS", "Access-Control-Allow-Headers": "Content-Type,Accept,X-XSRF-TOKEN" };
    const respond = (body: unknown, status = 200) => route.fulfill({ status, contentType: "application/json", headers, body: JSON.stringify(body) });
    if (request.method() === "OPTIONS") return route.fulfill({ status: 204, headers });
    if (path === "/api/me") return respond({ data: { id: "22222222-2222-4222-8222-222222222222", name: "Administrator", email: "admin@example.test", status: "active", is_system_admin: admin } });
    if (path === "/api/admin/applications" && request.method() === "GET") {
      if (failList) { failList = false; return respond({ message: "Application list unavailable." }, 503); }
      const others: Application[] = Array.from({ length: 26 }, (_, index) => ({ ...application, id: `33333333-3333-4333-8333-${String(index).padStart(12, "0")}`, name: `Product ${String(index).padStart(2, "0")}`, slug: `product-${index}` }));
      let items = [current, ...others];
      const search = url.searchParams.get("search") ?? "";
      const status = url.searchParams.get("status");
      items = items.filter((item) => (!search || `${item.name} ${item.slug}`.toLowerCase().includes(search.toLowerCase())) && (!status || item.status === status));
      const requestedPage = Number(url.searchParams.get("page") ?? 1);
      const size = Number(url.searchParams.get("per_page") ?? 25);
      return respond({ data: items.slice((requestedPage - 1) * size, requestedPage * size), meta: { current_page: requestedPage, last_page: Math.max(1, Math.ceil(items.length / size)), per_page: size, total: items.length } });
    }
    if (path === `/api/admin/applications/${current.id}` && request.method() === "GET") return respond({ data: current });
    if (request.method() === "PATCH") {
      mutations.push({ method: request.method(), path, body: request.postDataJSON() });
      if (path === `/api/admin/applications/${current.id}`) current = { ...current, ...request.postDataJSON() };
      else if (path === `/api/admin/applications/${current.id}/deactivate`) current.status = "inactive";
      else if (path === `/api/admin/applications/${current.id}/activate`) current.status = "active";
      else return respond({ message: "Unexpected mutation." }, 400);
      return respond({ data: current });
    }
    return respond({ message: "Not found." }, 404);
  });
  return { mutations, failNextList: () => { failList = true; } };
}

test("admin searches, filters, opens details, edits metadata and changes lifecycle", async ({ page }) => {
  const api = await fixtures(page);
  await page.goto("/applications");
  await expect(page.getByRole("heading", { name: "Applications", exact: true })).toBeVisible();
  await page.getByRole("navigation", { name: "Administration" }).getByRole("link", { name: "Applications", exact: true }).click();
  await page.getByLabel("Search applications").fill("crm");
  await page.getByLabel("Filter applications by status").selectOption("inactive");
  await expect(page.getByText("No applications found.")).toBeVisible();
  await page.getByLabel("Filter applications by status").selectOption("active");
  await page.getByRole("link", { name: "View details", exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`/applications/${application.id}$`));
  await expect(page.getByRole("heading", { name: "Overview", exact: true })).toBeVisible();
  await expect(page.getByText(application.id, { exact: true })).toBeVisible();
  await page.getByRole("link", { name: "Settings", exact: true }).click();
  await expect(page.getByLabel("Name")).toHaveValue(application.name);
  await page.getByLabel("Name").fill("Doxa CRM Production");
  await page.getByLabel("Slug").fill("crm-production");
  await page.getByLabel("Description").fill("Updated CRM registration");
  await page.getByRole("button", { name: "Save application", exact: true }).click();
  await expect(page.getByText("Application settings saved.")).toBeVisible();
  await expect(page).toHaveURL(new RegExp(`/applications/${application.id}/edit$`));
  expect(api.mutations[0]).toEqual({ method: "PATCH", path: `/api/admin/applications/${application.id}`, body: { name: "Doxa CRM Production", slug: "crm-production", description: "Updated CRM registration" } });
  await page.getByRole("button", { name: "Deactivate application", exact: true }).click();
  expect(api.mutations).toHaveLength(1);
  await page.getByRole("dialog").getByRole("button", { name: "Deactivate", exact: true }).click();
  await expect(page.getByText("Application deactivated.")).toBeVisible();
  await page.getByRole("button", { name: "Activate application", exact: true }).click();
  await expect(page.getByText("Application activated.")).toBeVisible();
  expect(api.mutations.map((mutation) => mutation.path)).toEqual([`/api/admin/applications/${application.id}`, `/api/admin/applications/${application.id}/deactivate`, `/api/admin/applications/${application.id}/activate`]);
  await page.getByRole("link", { name: "Overview", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Overview", exact: true })).toBeVisible();
  await page.goBack();
  await expect(page.getByRole("link", { name: "Settings", exact: true })).toHaveAttribute("aria-current", "page");
  await page.goForward();
  await expect(page.getByRole("link", { name: "Overview", exact: true })).toHaveAttribute("aria-current", "page");
});

test("application pagination resets with search and failures can be retried", async ({ page }) => {
  const api = await fixtures(page);
  await page.goto("/applications");
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page.getByText("Page 2 of 2")).toBeVisible();
  await page.getByLabel("Search applications").fill("crm");
  await expect(page.getByRole("link", { name: "View details" })).toHaveCount(1);
  api.failNextList();
  await page.getByLabel("Search applications").fill("product");
  await expect(page.getByRole("main").getByRole("alert")).toHaveText("Application list unavailable.");
  await page.getByRole("button", { name: "Retry", exact: true }).click();
  await expect(page.getByText("Page 1 of 2")).toBeVisible();
  await expect(page.getByLabel("Search applications")).toHaveValue("product");
});

test("non-admin cannot mount application management through a direct URL", async ({ page }) => {
  const api = await fixtures(page, false);
  await page.goto(`/applications/${application.id}/edit`);
  await expect(page.getByRole("heading", { name: "Administrator access required" })).toBeVisible();
  await expect(page.getByLabel("Name")).toHaveCount(0);
  expect(api.mutations).toHaveLength(0);
});
