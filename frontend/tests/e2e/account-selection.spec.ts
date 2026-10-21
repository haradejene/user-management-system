import {expect, test, type Page} from "@playwright/test";

const choice = "c".repeat(64);
const current = {id:"current",name:"Current User",email:"current@example.test",status:"active",is_system_admin:false};
const other = {id:"other",name:"Remembered User",email:"remembered@example.test",status:"active",is_system_admin:false};
const key = "doxa.remembered-accounts.v1";

async function setup(page: Page, authenticated = true, expired = false) {
  let user: typeof current | null = authenticated ? current : null;
  const events: string[] = [];
  await page.addInitScript(({key,other,current}) => localStorage.setItem(key, JSON.stringify([
    {name:other.name,email:other.email,expiresAt:Date.now()+24*60*60*1000},
    {name:current.name,email:current.email,expiresAt:Date.now()+24*60*60*1000},
  ])), {key,other,current});
  await page.route("**/*", async route => {
    const request = route.request();
    const url = new URL(request.url());
    const headers = {"Access-Control-Allow-Origin":"http://localhost:3001","Access-Control-Allow-Credentials":"true",
      "Access-Control-Allow-Headers":request.headers()["access-control-request-headers"] ?? "content-type,x-xsrf-token",
      "Access-Control-Allow-Methods":"GET,POST,OPTIONS"};
    if (request.method()==="OPTIONS") return route.fulfill({status:204,headers});
    const json = (status: number, data: unknown) => route.fulfill({status,headers,contentType:"application/json",body:JSON.stringify(data)});
    if (url.pathname==="/api/me") return json(user?200:401,user?{data:user}:{message:"Unauthenticated."});
    if (url.pathname.startsWith("/oauth/account-choice/")) return json(expired?410:200,expired?{message:"Expired"}:{application:"HRM"});
    if (url.pathname==="/sanctum/csrf-cookie") return route.fulfill({status:204,headers});
    if (url.pathname==="/api/login") {
      events.push("login");
      const body = request.postDataJSON();
      if (body.email!==other.email || body.password!=="test-password") return json(422,{errors:{email:["The provided credentials are incorrect."]}});
      user=other; return json(200,{data:other});
    }
    if (url.pathname==="/oauth/select-account") {
      const body=request.postDataJSON();
      expect(body.choice_token).toBe(choice);
      if (body.choice==="cancel") {events.push("cancel");return json(200,{redirect_url:"http://localhost:3001/cancelled-preview"});}
      expect(user).not.toBeNull(); expect(body.expected_email).toBe(user?.email);
      events.push("selection");return json(200,{redirect_url:"http://localhost:3001/authorized-preview"});
    }
    if (["/authorized-preview","/cancelled-preview"].includes(url.pathname)) return route.fulfill({status:200,contentType:"text/html",body:"<h1>Preview destination</h1>"});
    return route.continue();
  });
  await page.goto(`/login?account_choice=${choice}`);
  return events;
}

test("current valid session continues without a credential request", async ({page}) => {
  const events=await setup(page);
  await page.getByRole("button",{name:/Current User/}).click();
  await expect(page).toHaveURL(/authorized-preview$/);
  expect(events).toEqual(["selection"]);
});

test("remembered guest must authenticate; a failed password cannot resume authorization", async ({page}) => {
  const events=await setup(page,false);
  await page.getByRole("button",{name:/Remembered User/}).click();
  await expect(page.getByLabel("Email",{exact:true})).toHaveValue(other.email);
  await expect(page.getByLabel("Password",{exact:true})).toHaveValue("");
  expect(events).toEqual([]);
  await page.getByLabel("Password",{exact:true}).fill("incorrect-password");
  await page.getByRole("button",{name:"Sign in",exact:true}).click();
  await expect(page.getByRole("form").getByRole("alert")).toContainText("credentials are incorrect");
  expect(events).toEqual(["login"]);
  await page.getByLabel("Password",{exact:true}).fill("test-password");
  await page.getByRole("button",{name:"Sign in",exact:true}).click();
  await expect(page).toHaveURL(/authorized-preview$/);
  expect(events).toEqual(["login","login","selection"]);
  const saved=await page.evaluate(key=>JSON.parse(localStorage.getItem(key) ?? "[]"),key);
  expect(Object.keys(saved[0]).sort()).toEqual(["email","expiresAt","name"]);
});

test("use another account displays credentials and changes identity only after authentication", async ({page}) => {
  const events=await setup(page);
  await page.getByRole("button",{name:"Use another account",exact:true}).click();
  await expect(page.getByLabel("Email",{exact:true})).toHaveValue("");
  expect(events).toEqual([]);
  await page.getByLabel("Email",{exact:true}).fill(other.email);
  await page.getByLabel("Password",{exact:true}).fill("test-password");
  await page.getByRole("button",{name:"Sign in",exact:true}).click();
  await expect(page).toHaveURL(/authorized-preview$/);
  expect(events).toEqual(["login","selection"]);
});

test("saved labels can be removed without authentication or session changes", async ({page}) => {
  const events=await setup(page);
  await page.getByRole("button",{name:`Remove remembered account ${other.email}`}).click();
  await expect(page.getByText(other.email,{exact:true})).not.toBeVisible();
  await expect(page.getByText(current.email,{exact:true})).toBeVisible();
  expect(events).toEqual([]);
});

test("account cancellation does not authenticate or approve anything", async ({page}) => {
  const events=await setup(page,false);
  await page.getByRole("button",{name:"Cancel account selection",exact:true}).click();
  await expect(page).toHaveURL(/cancelled-preview$/);
  expect(events).toEqual(["cancel"]);
});

test("a lost server-side choice fails closed rather than resuming from remembered data", async ({page}) => {
  const events=await setup(page,false,true);
  await expect(page.getByRole("main").getByRole("alert")).toContainText("request expired or your session changed");
  await expect(page.getByLabel("Password",{exact:true})).not.toBeVisible();
  expect(events).toEqual([]);
});
