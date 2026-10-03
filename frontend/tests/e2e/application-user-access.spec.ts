import { expect, test, type Page } from "@playwright/test";
import type { ApplicationUser } from "../../src/types/application";

const appId = "11111111-1111-4111-8111-111111111111";
const otherId = "33333333-3333-4333-8333-333333333333";
const app = { id: appId, name: "CRM", slug: "crm", description: "Customer management", status: "active", created_at: null, updated_at: null };
const access = (index: number): ApplicationUser => ({ id: `22222222-2222-4222-8222-${String(index).padStart(12, "0")}`, name: `Person ${String(index).padStart(2, "0")}`, email: `person${index}@example.test`, status: "active", access_status: "active", assignment_exists: true, effective_access: true, ineffective_reason: null, granted_at: null });
const candidate = { ...access(99), name: "New person", email: "new@example.test", assignment_exists: false, effective_access: false, ineffective_reason: "not_assigned" };
const suspended = { ...access(1), name: "Suspended person", status: "suspended", effective_access: false, ineffective_reason: "user_suspended" };
async function fixtures(page: Page, admin = true, fail = false) {
  let assigned = [access(0), suspended, ...Array.from({ length: 24 }, (_, index) => access(index + 2))];
  const directory = [...assigned, candidate];
  const requests: { method: string; path: string; search: string; page: number; body: unknown }[] = [];
  await page.route("**/api/**", async (route) => {
    const request = route.request(); const url = new URL(request.url()); const path = url.pathname; const method = request.method();
    const headers = { "Access-Control-Allow-Origin": "http://localhost:3000", "Access-Control-Allow-Credentials": "true", "Access-Control-Allow-Methods": "GET,POST,DELETE,OPTIONS", "Access-Control-Allow-Headers": "Content-Type,Accept,X-XSRF-TOKEN" };
    const respond = (body: unknown, status = 200) => route.fulfill({ status, contentType: "application/json", headers, body: JSON.stringify(body) });
    if (method === "OPTIONS") return route.fulfill({ status: 204, headers });
    const requestedPage = Number(url.searchParams.get("page") ?? 1); const size = Number(url.searchParams.get("per_page") ?? 25); const search = url.searchParams.get("search") ?? "";
    requests.push({ method, path, search, page: requestedPage, body: request.postData() ? request.postDataJSON() : null });
    const paginate = (items: unknown[]) => ({ data: items.slice((requestedPage - 1) * size, requestedPage * size), meta: { current_page: requestedPage, last_page: Math.max(1, Math.ceil(items.length / size)), per_page: size, total: items.length } });
    if (path === "/api/me") return respond({ data: { id: "admin", name: "Admin", email: "admin@example.test", status: "active", is_system_admin: admin } });
    if (path === "/api/admin/applications") return respond(paginate([app]));
    if (path === `/api/admin/applications/${appId}`) return respond({ data: app });
    if (path === `/api/admin/applications/${otherId}`) return respond({ data: { ...app, id: otherId, name: "ERP" } });
    if (path === `/api/admin/applications/${appId}/users`) return fail ? respond({ message: "User access unavailable." }, 503) : respond(paginate(assigned));
    if (path === `/api/admin/applications/${otherId}/users`) return respond(paginate([{ ...access(55), name: "ERP person" }]));
    if (path === "/api/admin/users") return respond(paginate(directory.filter((user) => `${user.name} ${user.email}`.toLowerCase().includes(search.toLowerCase()))));
    for (const user of directory) {
      if (path === `/api/admin/users/${user.id}`) return respond({ data: user });
      if (path === `/api/admin/users/${user.id}/applications` && method === "GET") return respond(paginate(assigned.some((item) => item.id === user.id) ? [{ ...app, assignment_exists: true, access_status: "active", effective_access: user.effective_access, ineffective_reason: user.ineffective_reason, granted_at: null }] : []));
      if (path === `/api/admin/users/${user.id}/applications` && method === "POST") {
        if (request.postDataJSON().application_id !== appId) return respond({ message: "Wrong context" }, 422);
        if (assigned.some((item) => item.id === user.id)) return respond({ errors: { application_id: ["The user already has access to this application."] } }, 422);
        assigned = [...assigned, { ...user, assignment_exists: true, effective_access: true, ineffective_reason: null }];
        return respond({ data: { ...app, assignment_exists: true, access_status: "active", effective_access: true, ineffective_reason: null, granted_at: null } }, 201);
      }
      if (path === `/api/admin/users/${user.id}/applications/${appId}` && method === "DELETE") { assigned = assigned.filter((item) => item.id !== user.id); return route.fulfill({ status: 204, headers }); }
    }
    return respond({ message: "Unexpected API request" }, 404);
  });
  return { requests, recover: () => { fail = false; } };
}
async function open(page: Page) { await page.goto(`/applications/${appId}/user-access`); await expect(page.getByRole("heading", { name: "User Access", exact: true })).toBeVisible({ timeout: 15_000 }); }
async function openPicker(page: Page) { await page.getByRole("button", { name: "Grant Access" }).click(); await expect(page.getByLabel("User", { exact: true })).toBeVisible(); }

test("admin opens application User Access and sees assigned users with separate account and access state", async ({ page }) => {
  await fixtures(page); await page.goto(`/applications/${appId}`); await page.getByRole("link", { name: "User Access", exact: true }).click();
  await expect(page).toHaveURL(`/applications/${appId}/user-access`, { timeout: 15_000 }); await expect(page.getByText("Application: CRM")).toBeVisible();
  await expect(page.getByRole("cell", { name: "person0@example.test", exact: true })).toBeVisible(); await expect(page.getByText("Page 1 of 2")).toBeVisible();
});
test("picker search filters users, resets pagination, and preserves application context", async ({ page }) => {
  const api = await fixtures(page); await open(page); await openPicker(page);
  const dialog = page.getByRole("dialog"); await dialog.getByRole("button", { name: "Next", exact: true }).click(); await expect(dialog.getByText("Page 2 of 2")).toBeVisible();
  await page.getByLabel("Search users to grant access").fill("New person"); await expect(page.getByRole("option", { name: /New person/ })).toHaveCount(1);
  expect(api.requests.filter((request) => request.path === "/api/admin/users").at(-1)).toMatchObject({ search: "New person", page: 1 });
  await expect(dialog.getByText("Application: CRM")).toBeVisible(); await expect(dialog.getByText("Page 2 of 2")).toHaveCount(0);
});
test("grant uses the fixed route application and refreshes authoritative assignments", async ({ page }) => {
  const api = await fixtures(page); await open(page); await openPicker(page); await page.getByLabel("Search users to grant access").fill("new@example.test");
  await page.getByLabel("User", { exact: true }).selectOption(candidate.id); await expect(page.getByText("Selected user: New person")).toBeVisible(); await page.getByRole("button", { name: "Grant assignment" }).click();
  await expect(page.getByText("Assignment granted for New person (new@example.test) to CRM.")).toBeVisible();
  expect(api.requests.find((request) => request.method === "POST")).toMatchObject({ path: `/api/admin/users/${candidate.id}/applications`, body: { application_id: appId } });
  await page.getByRole("button", { name: "Next", exact: true }).click(); await expect(page.getByRole("cell", { name: candidate.email, exact: true })).toBeVisible();
});
test("revoke confirms the user and application and detaches only that assignment", async ({ page }) => {
  const api = await fixtures(page); await open(page); const row = page.getByRole("row").filter({ hasText: "person0@example.test" }); await row.getByRole("button", { name: "Revoke access" }).click();
  await expect(page.getByRole("dialog")).toContainText("Person 00 (person0@example.test) from CRM"); expect(api.requests.filter((request) => request.method === "DELETE")).toHaveLength(0);
  await page.getByRole("button", { name: "Revoke assignment" }).click(); await expect(page.getByRole("cell", { name: "person0@example.test", exact: true })).toHaveCount(0);
  await expect(page.getByRole("cell", { name: "Suspended person", exact: true })).toBeVisible(); expect(api.requests.find((request) => request.method === "DELETE")?.path).toBe(`/api/admin/users/${access(0).id}/applications/${appId}`);
});
test("assigned but ineffective access retains the assignment and exact backend reason", async ({ page }) => {
  await fixtures(page); await open(page); const row = page.getByRole("row").filter({ hasText: "Suspended person" });
  await expect(row.getByText("suspended", { exact: true })).toBeVisible(); await expect(row.getByRole("cell", { name: "Assigned active", exact: true })).toBeVisible(); await expect(row.getByText("active", { exact: true })).toBeVisible();
  await expect(row.getByText("No — Not effective")).toBeVisible(); await expect(row.getByText("user_suspended")).toBeVisible(); await expect(row.getByRole("button", { name: "Revoke access" })).toBeEnabled();
});
test("application assignment API failure stays visible until Retry succeeds", async ({ page }) => {
  const api = await fixtures(page, true, true); await open(page); await expect(page.getByRole("main").getByRole("alert")).toHaveText("User access unavailable.");
  api.recover(); await page.getByRole("button", { name: "Retry", exact: true }).click(); await expect(page.getByRole("cell", { name: "Person 00", exact: true })).toBeVisible();
});
test("switching applications replaces assigned users", async ({ page }) => {
  await fixtures(page); await open(page); await expect(page.getByRole("cell", { name: "Person 00", exact: true })).toBeVisible();
  await page.goto(`/applications/${otherId}/user-access`); await expect(page.getByText("Application: ERP")).toBeVisible(); await expect(page.getByRole("cell", { name: "ERP person", exact: true })).toBeVisible(); await expect(page.getByRole("cell", { name: "Person 00", exact: true })).toHaveCount(0);
});
test("application tab history preserves Overview, User Access and Settings", async ({ page }) => {
  await fixtures(page); await open(page); await page.getByRole("link", { name: "Settings", exact: true }).click(); await expect(page.getByLabel("Name", { exact: true })).toBeVisible();
  await page.goBack(); await expect(page.getByRole("link", { name: "User Access", exact: true })).toHaveAttribute("aria-current", "page"); await expect(page.getByRole("cell", { name: "Person 00", exact: true })).toBeVisible();
  await page.goForward(); await expect(page.getByLabel("Name", { exact: true })).toBeVisible(); await page.getByRole("link", { name: "Overview", exact: true }).click(); await expect(page.getByRole("heading", { name: "Overview", exact: true })).toBeVisible();
});
test("non-admin cannot mount application User Access", async ({ page }) => {
  const api = await fixtures(page, false); await page.goto(`/applications/${appId}/user-access`); await expect(page.getByRole("heading", { name: "Administrator access required" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Grant Access" })).toHaveCount(0); expect(api.requests.some((request) => request.path.endsWith(`/${appId}/users`))).toBe(false);
});
test("global Application Access remains a separate functional view", async ({ page }) => {
  await fixtures(page); await page.goto("/application-access"); await expect(page.getByRole("heading", { name: "Application access", exact: true })).toBeVisible({ timeout: 15_000 });
  await expect(page.getByLabel("User", { exact: true })).toBeVisible(); await expect(page.getByRole("checkbox")).toBeChecked();
  await expect(page.getByText("effective access", { exact: true })).toBeVisible();
});
