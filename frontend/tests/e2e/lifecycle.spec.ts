import { expect, test, type BrowserContext, type Page } from "@playwright/test";

// Run only against a disposable database. The mounted-admin cases require active
// admin fixtures lifecycle-admin-{deactivate,suspend}@example.test (password).
test.setTimeout(120_000);

let adminContext: BrowserContext;
let admin: Page;

async function login(page: Page, email: string, password = "password", remember = false) {
  const me = page.waitForResponse((r) => r.url().endsWith("/api/me"));
  await page.goto("/login");
  await me;
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByLabel("Keep me signed in").setChecked(remember);
  const authenticated = page.waitForResponse((r) => r.url().endsWith("/api/login"));
  await page.getByRole("button", { name: "Login", exact: true }).click();
  const response = await authenticated;
  expect(response.status()).toBe(200);
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByRole("button", { name: "Logout" })).toBeVisible();
  if ((await response.json()).data.is_system_admin) {
    // Finish the dashboard's protected requests before an administrator disables
    // this identity; the tested next request must come from the Users navigation.
    await expect(page.getByRole("main").getByText("Users", { exact: true })).toBeVisible();
  }
}

async function status(action: "deactivate" | "suspend" | "reactivate") {
  await admin.getByRole("button", { name: new RegExp(`^${action}$`, "i") }).click();
  const changed = admin.waitForResponse((r) => r.url().endsWith(`/${action}`) && r.request().method() === "PATCH");
  await admin.getByRole("dialog").getByRole("button", { name: action, exact: true }).click();
  expect((await changed).status()).toBe(200);
  await expect(admin.getByRole("dialog")).not.toBeVisible();
}

async function rejected(page: Page, expected: number) {
  const me = page.waitForResponse((r) => r.url().endsWith("/api/me"));
  await page.goto("/dashboard");
  expect((await me).status()).toBe(expected);
  await expect(page).toHaveURL(/\/login\?next=%2Fdashboard$/);
  await expect(page.getByRole("button", { name: "Login", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Logout" })).not.toBeVisible();
  await expect(page.getByRole("navigation", { name: "Administration" })).not.toBeVisible();
}

test.beforeAll(async ({ browser }) => {
  adminContext = await browser.newContext();
  admin = await adminContext.newPage();
  await login(admin, "admin@central-iam.test");
});

test.afterAll(async () => { await adminContext?.close(); });

for (const action of ["deactivate", "suspend"] as const) {
  for (const offline of [false, true]) {
    test(`${action}: independent sessions, ${offline ? "B offline through reactivation" : "both browsers online"}, and remember cookies`, async ({ browser }) => {
      const contexts: BrowserContext[] = [];
      const context = async () => {
        const created = await browser.newContext();
        contexts.push(created);
        return created;
      };
      try {
        const email = `lifecycle-${action}-${offline}-${Date.now()}@example.test`;
        await admin.goto("/users/new");
        await admin.getByLabel("Name", { exact: true }).fill("Lifecycle browser target");
        await admin.getByLabel("Email").fill(email);
        await admin.getByLabel("Password", { exact: true }).fill("secure-password");
        await admin.getByRole("button", { name: "Save user" }).click();
        await expect(admin.getByRole("heading", { name: "Lifecycle browser target" })).toBeVisible();

        const a = await context(); const b = await context();
        const pageA = await a.newPage(); const pageB = await b.newPage();
        await login(pageA, email, "secure-password", true);
        await login(pageB, email, "secure-password", true);
        const oldA = await a.cookies(); const oldB = await b.cookies();
        const remembered = oldA.filter((cookie) => cookie.name.startsWith("remember_web_"));
        expect(remembered).toHaveLength(1);

        // A new context with only the real remember cookie must authenticate first,
        // proving later denial tests exercise a previously valid credential.
        const recall = await context();
        await recall.addCookies(remembered);
        const recallPage = await recall.newPage();
        const recalled = recallPage.waitForResponse((r) => r.url().endsWith("/api/me"));
        await recallPage.goto("/dashboard");
        expect((await recalled).status()).toBe(200);
        await expect(recallPage.getByRole("button", { name: "Logout" })).toBeVisible();

        if (offline) await b.setOffline(true);
        await status(action);
        await rejected(pageA, 403);
        if (!offline) await rejected(pageB, 403);
        await recall.clearCookies(); await recall.addCookies(remembered);
        await rejected(recallPage, 401);
        await status("reactivate");
        if (offline) { await b.setOffline(false); await rejected(pageB, 401); }

        // Replay cookies captured before disabling, including a session that may
        // have been offline; reactivation must never revive them.
        for (const [current, page, cookies] of [[a, pageA, oldA], [b, pageB, oldB]] as const) {
          await current.clearCookies(); await current.addCookies(cookies);
          await rejected(page, 401);
          await login(page, email, "secure-password");
        }
        await recall.clearCookies(); await recall.addCookies(remembered);
        await rejected(recallPage, 401);
      } finally {
        await Promise.all(contexts.map((current) => current.close()));
      }
    });
  }

  test(`${action}: mounted admin clients clear authentication on rejected navigation`, async ({ browser }) => {
    const a = await browser.newContext(); const b = await browser.newContext();
    try {
      const email = `lifecycle-admin-${action}@example.test`;
      await admin.goto("/users");
      await admin.getByRole("textbox", { name: "Search users" }).fill(email);
      await admin.getByRole("row").filter({ hasText: email }).getByRole("link", { name: "View" }).click();
      await expect(admin).toHaveURL(/\/users\/[0-9a-f-]+$/);
      await expect(admin.getByRole("heading", { name: email, exact: true })).toBeVisible();
      if (await admin.getByRole("button", { name: "Reactivate", exact: true }).isVisible()) {
        await status("reactivate");
      }
      const pageA = await a.newPage(); const pageB = await b.newPage();
      await login(pageA, email); await login(pageB, email);
      await status(action);
      for (const page of [pageA, pageB]) {
        const denied = page.waitForResponse((r) => r.url().includes("/api/admin/users"));
        await page.getByRole("navigation").getByRole("link", { name: "Users", exact: true }).click();
        expect((await denied).status()).toBe(403);
        await expect(page).toHaveURL(/\/login\?next=%2Fusers$/);
        await expect(page.getByRole("navigation", { name: "Administration" })).not.toBeVisible();
        await expect(page.getByRole("button", { name: "Logout" })).not.toBeVisible();
        await expect(page.getByRole("alert").filter({ hasText: "Your account is not active." })).toBeVisible();
      }
      await status("reactivate");
      await login(pageA, email); await login(pageB, email);

      // Neither mounted client makes a protected request between these changes.
      // The next client-side navigation must handle session-version rejection too.
      await status(action);
      await status("reactivate");
      for (const page of [pageA, pageB]) {
        const denied = page.waitForResponse((r) => r.url().includes("/api/admin/users"));
        await page.getByRole("navigation").getByRole("link", { name: "Users", exact: true }).click();
        expect((await denied).status()).toBe(401);
        await expect(page).toHaveURL(/\/login\?next=%2Fusers$/);
        await expect(page.getByRole("navigation", { name: "Administration" })).not.toBeVisible();
        await expect(page.getByRole("alert").filter({ hasText: "Your session has expired." })).toBeVisible();
      }
    } finally {
      await a.close(); await b.close();
    }
  });
}
