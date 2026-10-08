import { expect, test, type Page } from "@playwright/test";

const app = "11111111-1111-4111-8111-111111111111";
const other = "33333333-3333-4333-8333-333333333333";
const clientId = "22222222-2222-4222-8222-222222222222";
const secret = "mock-only-one-time-secret";
async function fixtures(page: Page, admin = true, fail = false) {
  let clients = [{ id: clientId, application_id: app, name: "CRM OAuth", confidential: false, revoked: false, redirect_uris: ["https://example.test/callback"], grant_types: ["authorization_code", "refresh_token"], pkce_required: true, pkce_method: "S256", allowed_scopes: ["iam:read", "openid", "profile", "email"], issuer: "https://iam.example.test", discovery_url: "https://iam.example.test/.well-known/openid-configuration", created_at: "2026-01-02T00:00:00Z", updated_at: "2026-02-03T00:00:00Z" }];
  const mutations: { path: string; body: unknown }[] = [];
  await page.route("**/api/**", async (route) => {
    const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
    const headers = { "Access-Control-Allow-Origin": "http://localhost:3000", "Access-Control-Allow-Credentials": "true", "Access-Control-Allow-Methods": "GET,POST,PATCH,OPTIONS", "Access-Control-Allow-Headers": "Content-Type,Accept,X-XSRF-TOKEN" };
    const respond = (body: unknown, status = 200) => route.fulfill({ status, contentType: "application/json", headers, body: JSON.stringify(body) });
    if (request.method() === "OPTIONS") return route.fulfill({ status: 204, headers });
    if (path === "/api/me") return respond({ data: { id: "admin", name: "Admin", email: "admin@example.test", status: "active", is_system_admin: admin } });
    if (path === `/api/admin/applications/${app}` || path === `/api/admin/applications/${other}`) return respond({ data: { id: path.endsWith(other) ? other : app, name: path.endsWith(other) ? "ERP" : "CRM", slug: "crm", description: "Application", status: "active", created_at: null, updated_at: null } });
    const root = `/api/admin/applications/${app}/oauth-clients`;
    if (path === `/api/admin/applications/${app}/history`) return respond({ data: [{ id: 1, action: "application.access_granted", actor_id: "admin", subject_id: "user", client_id: null, created_at: "2026-10-07T00:00:00Z" }], last_page: 1 });
    if (path === `${root}/${clientId}/redirect-uris` && request.method() === "PATCH") {
      const input = request.postDataJSON(); mutations.push({ path, body: input });
      clients = clients.map((client) => client.id === clientId ? { ...client, redirect_uris: input.redirect_uris, updated_at: "2026-10-07T00:00:00Z" } : client);
      return respond({ data: clients[0] });
    }
    if (path === root && request.method() === "GET") {
      if (fail) return respond({ message: "Clients unavailable." }, 503);
      return respond({ data: clients, meta: { current_page: 1, last_page: 1, per_page: 25, total: clients.length } });
    }
    if (path === `/api/admin/applications/${other}/oauth-clients`) return respond({ data: [{ ...clients[0], id: "other-client", application_id: other, name: "ERP OAuth" }], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } });
    if (path === root && request.method() === "POST") {
      const input = request.postDataJSON(); mutations.push({ path, body: input });
      const created = { ...clients[0], ...input, id: "44444444-4444-4444-8444-444444444444" }; clients = [created, ...clients];
      return respond({ data: created, client_secret: secret }, 201);
    }
    if (path === `${root}/${clientId}/revoke` && request.method() === "PATCH") {
      mutations.push({ path, body: request.postData() }); clients = clients.map((client) => client.id === clientId ? { ...client, revoked: true } : client);
      return route.fulfill({ status: 204, headers });
    }
    if (path.startsWith(`${root}/`)) return respond({ data: clients.find((client) => path.endsWith(client.id)) });
    return respond({ message: "Not found." }, 404);
  });
  return { mutations, recover: () => { fail = false; } };
}

test("admin opens the OAuth tab and inspects safe client details", async ({ page }) => {
  await fixtures(page); await page.goto(`/applications/${app}`);
  await page.getByRole("link", { name: "OAuth Clients", exact: true }).click();
  await expect(page).toHaveURL(`/applications/${app}/oauth-clients`, { timeout: 15_000 });
  await expect(page.getByText("CRM OAuth", { exact: true })).toBeVisible();
  await expect(page.getByText(clientId, { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "View details" }).click();
  await expect(page.getByRole("dialog").getByText("https://example.test/callback").first()).toBeVisible();
});
test("confidential registration shows a one-time secret, copies and discards it", async ({ page }) => {
  const { mutations } = await fixtures(page);
  await page.addInitScript(() => { Object.defineProperty(navigator, "clipboard", { value: { writeText: async () => {} }, configurable: true }); });
  await page.goto(`/applications/${app}/oauth-clients`);
  await page.getByRole("button", { name: "Register OAuth Client" }).click();
  await page.getByLabel("Client name").fill("Production client"); await page.getByLabel("Client type").selectOption("confidential"); await page.getByLabel("Redirect URIs").fill("https://example.test/production");
  await page.getByRole("button", { name: "Register client", exact: true }).click();
  await page.getByRole("button", { name: "Create client", exact: true }).click();
  await expect(page.getByText(secret, { exact: true })).toBeVisible();
  expect(mutations[0].body).toEqual({ name: "Production client", confidential: true, redirect_uris: ["https://example.test/production"] });
  await page.getByRole("button", { name: "Copy secret" }).click(); await expect(page.getByText("Copied.")).toBeVisible();
  await page.getByRole("button", { name: "Close", exact: true }).click(); await expect(page.getByText(secret)).toHaveCount(0);
  await expect(page.getByText("Production client", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Register OAuth Client" }).click(); await expect(page.getByText(secret)).toHaveCount(0);
});
test("revocation requires confirmation and refreshes revoked state", async ({ page }) => {
  const { mutations } = await fixtures(page); await page.goto(`/applications/${app}/oauth-clients`);
  await page.getByRole("button", { name: "Revoke", exact: true }).click(); expect(mutations).toHaveLength(0);
  await page.getByRole("button", { name: "Revoke client", exact: true }).click();
  await expect(page.getByText("revoked", { exact: true })).toBeVisible(); expect(mutations[0].path).toBe(`/api/admin/applications/${app}/oauth-clients/${clientId}/revoke`);
  await expect(page.getByRole("button", { name: "Revoke", exact: true })).toHaveCount(0);
});
test("OAuth API failures remain visible and Retry loads clients", async ({ page }) => {
  const api = await fixtures(page, true, true); await page.goto(`/applications/${app}/oauth-clients`);
  await expect(page.getByRole("main").getByRole("alert")).toHaveText("The identity service could not complete the request. Try again later.", { timeout: 15_000 }); api.recover(); await page.getByRole("button", { name: "Retry" }).click(); await expect(page.getByText("CRM OAuth")).toBeVisible();
});
test("non-admin cannot mount the OAuth management route", async ({ page }) => {
  await fixtures(page, false); await page.goto(`/applications/${app}/oauth-clients`);
  await expect(page.getByRole("heading", { name: "Administrator access required" })).toBeVisible(); await expect(page.getByRole("button", { name: "Register OAuth Client" })).toHaveCount(0);
});
test("tab history and application switching preserve correct context", async ({ page }) => {
  await fixtures(page); await page.goto(`/applications/${app}`); await page.getByRole("link", { name: "OAuth Clients", exact: true }).click(); await expect(page.getByText("CRM OAuth")).toBeVisible();
  await page.getByRole("link", { name: "Settings", exact: true }).click(); await expect(page.getByLabel("Name")).toBeVisible(); await page.goBack(); await expect(page.getByText("CRM OAuth")).toBeVisible(); await page.goForward(); await expect(page.getByLabel("Name")).toBeVisible();
  await page.goto(`/applications/${other}/oauth-clients`); await expect(page.getByText("ERP OAuth")).toBeVisible(); await expect(page.getByText("CRM OAuth")).toHaveCount(0);
});

test("admin manages exact callbacks and reviews integration and real audit history", async ({ page }) => {
  const { mutations } = await fixtures(page); await page.goto(`/applications/${app}/oauth-clients`);
  await page.getByRole("button", { name: "View details" }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByText("Required; method S256")).toBeVisible();
  await dialog.getByRole("button", { name: "Add URI", exact: true }).click();
  await dialog.getByLabel("Redirect URI 2", { exact: true }).fill("https://example.test/Callback?Case=Value%2F");
  await dialog.getByRole("button", { name: "Remove URI 1" }).click();
  await dialog.getByRole("button", { name: "Save redirect URIs" }).click();
  await expect(dialog.getByText("Redirect URIs saved.")).toBeVisible();
  expect(mutations[0].body).toEqual({ redirect_uris: ["https://example.test/Callback?Case=Value%2F"], updated_at: "2026-02-03T00:00:00Z" });
  await expect(dialog.getByText("https://iam.example.test", { exact: true })).toBeVisible();
  await expect(dialog.getByRole("link", { name: "Manage application access" })).toHaveAttribute("href", `/applications/${app}/user-access`);
  await dialog.getByRole("button", { name: "Close", exact: true }).click();
  await page.getByRole("button", { name: "Administrative history" }).click();
  await expect(page.getByText("application.access_granted", { exact: true })).toBeVisible();
});
