import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { AxiosError } from "axios";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import type { ApplicationUser } from "@/types/application";

const { http, auth } = vi.hoisted(() => ({ http: { get: vi.fn(), post: vi.fn(), delete: vi.fn() }, auth: { user: { is_system_admin: true }, isLoading: false, logout: vi.fn() } }));
// Exercise the actual applications/users service methods, mocking only HTTP.
vi.mock("@/services/api-client", async (original) => ({ ...await original<typeof import("@/services/api-client")>(), apiClient: http }));
vi.mock("@/hooks/useAuth", () => ({ useAuth: () => auth }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: vi.fn() }), usePathname: () => "/applications/app/user-access" }));
import { ApplicationUserAccess } from "@/components/admin/applications/ApplicationUserAccess";
import { ApplicationDetails } from "@/components/admin/applications/ApplicationDetails";
import { ProtectedShell } from "@/components/auth/ProtectedShell";
import { applicationsService } from "@/services/applications.service";

const user: ApplicationUser = { id: "user-1", name: "Hara", email: "hara@example.test", status: "active", access_status: "active", assignment_exists: true, effective_access: true, ineffective_reason: null, granted_at: null };
const second = { ...user, id: "user-2", name: "Sara", email: "sara@example.test", status: "suspended", effective_access: false, ineffective_reason: "user_suspended" };
const page = (data: unknown[] = [user], current_page = 1, last_page = 1) => ({ data, meta: { current_page, last_page, per_page: 25, total: data.length } });
const response = (data: unknown) => ({ data });
const app = { id: "app", name: "CRM", slug: "crm", status: "active", description: null, created_at: null, updated_at: null };
beforeEach(() => {
  vi.resetAllMocks(); auth.user = { is_system_admin: true }; auth.isLoading = false;
  http.get.mockImplementation((path: string) => {
    if (path === "/api/admin/users") return Promise.resolve(response(page([user, second])));
    if (path.startsWith("/api/admin/users/")) return Promise.resolve(response({ data: path.endsWith(second.id) ? second : user }));
    if (path.endsWith("/users")) return Promise.resolve(response(page()));
    return Promise.resolve(response({ data: app }));
  });
  http.post.mockResolvedValue(response({ data: { ...app, assignment_exists: true, access_status: "active", effective_access: false, ineffective_reason: "user_suspended" } }));
  http.delete.mockResolvedValue({ status: 204 });
});
afterEach(cleanup);
const renderAccess = () => render(<ApplicationUserAccess applicationId="app" applicationName="CRM" />);
async function picker() {
  fireEvent.click(screen.getByRole("button", { name: "Grant Access" }));
  await screen.findByRole("option", { name: /Sara/ });
  return screen.getByLabelText("User");
}
async function select(id = second.id) {
  fireEvent.change(await picker(), { target: { value: id } });
  await screen.findByText(`Selected user: ${id === second.id ? second.name : user.name}`);
}
const grant = () => fireEvent.click(screen.getByRole("button", { name: "Grant assignment" }));
const confirmRevoke = () => fireEvent.click(screen.getByRole("button", { name: "Revoke assignment" }));
function validation(messages = ["The user already has access to this application."]) {
  const error = new AxiosError("validation");
  error.response = { status: 422, data: { message: "Invalid assignment", errors: { application_id: messages } } } as AxiosError["response"];
  return error;
}

it("renders account, assignment and authoritative effective access separately with the exact reason", async () => {
  http.get.mockResolvedValue(response(page([user, second, { ...user, id: "third", name: "Unassigned", assignment_exists: false, effective_access: false, ineffective_reason: "not_assigned" }])));
  renderAccess(); expect(screen.getByText("Loading user access…")).toBeInTheDocument(); await screen.findByText("Sara");
  const row = screen.getByText("Sara").closest("tr")!;
  expect(within(row).getByText("suspended")).toBeInTheDocument(); expect(within(row).getByText("active")).toBeInTheDocument();
  expect(within(row).getByText("Assigned")).toBeInTheDocument(); expect(within(row).getByText("No — Not effective")).toBeInTheDocument(); expect(within(row).getByText("user_suspended")).toBeInTheDocument();
  expect(screen.getByText("Yes — Granted")).toBeInTheDocument();
  const absent = screen.getByText("Unassigned").closest("tr")!;
  expect(within(absent).getByText("Not assigned")).toBeInTheDocument(); expect(within(absent).queryByRole("button")).not.toBeInTheDocument();
  expect(http.get).toHaveBeenCalledWith("/api/admin/applications/app/users", { params: { page: 1, per_page: 25 } });
  expect(screen.queryByLabelText("Search assigned users")).not.toBeInTheDocument();
});
it("renders backend inactive assignment without revoking an inactive account", async () => {
  http.get.mockResolvedValue(response(page([{ ...user, status: "inactive", access_status: "inactive", effective_access: false, ineffective_reason: "assignment_inactive" }])));
  renderAccess(); await screen.findByText("assignment_inactive"); expect(screen.getAllByText("inactive")).toHaveLength(2);
  expect(screen.getByRole("button", { name: "Revoke access" })).toBeEnabled(); expect(http.delete).not.toHaveBeenCalled();
});
it("shows an empty assignment list with a grant action", async () => {
  http.get.mockResolvedValue(response(page([]))); renderAccess(); await screen.findByText("No users currently have an assignment for this application.");
  expect(screen.getByRole("button", { name: "Grant Access" })).toBeEnabled();
});
it("shows failures independently and retries the current application and page", async () => {
  http.get.mockRejectedValueOnce(new Error("failed")).mockResolvedValue(response(page())); renderAccess(); await screen.findByRole("alert");
  expect(screen.queryByText("No users currently have an assignment for this application.")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Retry" })); await screen.findByText(user.email);
  expect(http.get).toHaveBeenLastCalledWith("/api/admin/applications/app/users", { params: { page: 1, per_page: 25 } });
});
it("paginates in the current application and displays loading", async () => {
  http.get.mockImplementation((_path, options) => Promise.resolve(response(page([user], options.params.page, 2)))); renderAccess(); await screen.findByText(user.email);
  fireEvent.click(screen.getByRole("button", { name: "Next" })); expect(screen.getByText("Loading user access…")).toBeInTheDocument();
  await screen.findByText("Page 2 of 2"); expect(http.get).toHaveBeenLastCalledWith("/api/admin/applications/app/users", { params: { page: 2, per_page: 25 } });
});
it("does not display a previous application's users while switching or after its late response", async () => {
  let resolve!: (value: unknown) => void;
  http.get.mockImplementationOnce(() => new Promise((done) => { resolve = done; })).mockResolvedValue(response(page([second])));
  const view = renderAccess(); view.rerender(<ApplicationUserAccess applicationId="other" applicationName="ERP" />);
  expect(screen.queryByText(user.name)).not.toBeInTheDocument(); await screen.findByText(second.name);
  await act(async () => resolve(response(page()))); expect(screen.queryByText(user.name)).not.toBeInTheDocument(); expect(screen.getByText("Application: ERP")).toBeInTheDocument();
});
it("opens the picker, keeps the application fixed and grants only after an authoritative user lookup", async () => {
  renderAccess(); await select();
  expect(screen.getByRole("dialog")).toHaveTextContent("Application: CRM");
  expect(screen.queryByLabelText(/application id/i)).not.toBeInTheDocument();
  http.get.mockImplementation((path: string) => Promise.resolve(response(path.endsWith("/users") ? page([second]) : { data: second })));
  grant(); await screen.findByText(`Assignment granted for Sara (${second.email}) to CRM.`);
  expect(http.post).toHaveBeenCalledWith("/api/admin/users/user-2/applications", { application_id: "app" });
  expect(http.get).toHaveBeenLastCalledWith("/api/admin/applications/app/users", { params: { page: 1, per_page: 25 } });
  expect(await screen.findByText("user_suspended")).toBeInTheDocument(); expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
});
it("does not assume a selected user absent from the current list page is unassigned", async () => {
  http.post.mockRejectedValue(validation()); renderAccess(); await select(); grant();
  await screen.findByText("The user already has access to this application."); expect(screen.getByRole("dialog")).toBeInTheDocument(); expect(screen.getByText(user.name)).toBeInTheDocument();
  expect(http.get.mock.calls.filter(([path]) => path === "/api/admin/applications/app/users")).toHaveLength(1);
});
it("displays every backend grant validation error and preserves selection", async () => {
  http.post.mockRejectedValue(validation(["Application unavailable", "Duplicate assignment"])); renderAccess(); await select(); grant();
  await screen.findByText("Duplicate assignment"); expect(screen.getByText("Application unavailable")).toBeInTheDocument(); expect(screen.getByLabelText("User")).toHaveValue(second.id);
});
it("prevents duplicate grants, disables selection and ignores a late grant after switching applications", async () => {
  let resolve!: (value: unknown) => void; http.post.mockImplementation(() => new Promise((done) => { resolve = done; }));
  const view = renderAccess(); await select(); grant();
  expect(screen.getByLabelText("User")).toBeDisabled(); expect(screen.getByRole("button", { name: "Please wait…" })).toBeDisabled();
  fireEvent.submit(screen.getByLabelText("User").closest("form")!); expect(http.post).toHaveBeenCalledTimes(1);
  view.rerender(<ApplicationUserAccess applicationId="other" applicationName="ERP" />); await screen.findByText(user.email);
  const calls = http.get.mock.calls.length; await act(async () => resolve(response({ data: app })));
  expect(http.get).toHaveBeenCalledTimes(calls); expect(screen.queryByText(/Assignment granted/)).not.toBeInTheDocument();
});
it("debounces picker search, resets pagination and preserves the fixed application", async () => {
  const original = http.get.getMockImplementation()!;
  http.get.mockImplementation((path, options) => path === "/api/admin/users" ? Promise.resolve(response(page([user, second], options.params.page, 2))) : original(path, options));
  renderAccess(); await picker(); fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Next" })); await screen.findByText("Page 2 of 2");
  const search = screen.getByLabelText("Search users to grant access");
  const count = http.get.mock.calls.filter(([path]) => path === "/api/admin/users").length;
  fireEvent.change(search, { target: { value: "s" } }); fireEvent.change(search, { target: { value: "sar" } });
  expect(http.get.mock.calls.filter(([path]) => path === "/api/admin/users")).toHaveLength(count);
  await waitFor(() => expect(http.get).toHaveBeenLastCalledWith("/api/admin/users", { params: { search: "sar", page: 1, per_page: 15, status: undefined } }));
  await screen.findByText("Page 1 of 2"); expect(screen.getByRole("dialog")).toHaveTextContent("Application: CRM");
  expect(http.get.mock.calls.filter(([path]) => path === "/api/admin/users")).toHaveLength(count + 1);
});
it("ignores stale picker search responses", async () => {
  let resolve!: (value: unknown) => void; const original = http.get.getMockImplementation()!;
  http.get.mockImplementation((path, options) => path === "/api/admin/users" && !options.params.search ? new Promise((done) => { resolve = done; }) : original(path, options));
  renderAccess(); fireEvent.click(screen.getByRole("button", { name: "Grant Access" })); await waitFor(() => expect(resolve).toBeTypeOf("function"));
  fireEvent.change(screen.getByLabelText("Search users to grant access"), { target: { value: "Sara" } }); await screen.findByRole("option", { name: /Sara/ });
  await act(async () => resolve(response(page([{ ...user, name: "Stale user" }])))); expect(screen.queryByRole("option", { name: /Stale user/ })).not.toBeInTheDocument();
});
it("ignores a slow lookup after selecting a newer user", async () => {
  let resolve!: (value: unknown) => void; const original = http.get.getMockImplementation()!;
  http.get.mockImplementation((path, options) => path === "/api/admin/users/user-1" ? new Promise((done) => { resolve = done; }) : original(path, options));
  renderAccess(); const control = await picker(); fireEvent.change(control, { target: { value: user.id } });
  expect(screen.getByRole("button", { name: "Grant assignment" })).toBeDisabled(); fireEvent.change(control, { target: { value: second.id } }); await screen.findByText("Selected user: Sara");
  await act(async () => resolve(response({ data: user }))); expect(screen.queryByText("Selected user: Hara")).not.toBeInTheDocument(); grant();
  await waitFor(() => expect(http.post).toHaveBeenCalledWith("/api/admin/users/user-2/applications", { application_id: "app" }));
});
it("retries independent picker list and selected-user errors", async () => {
  let listFailed = true; let profileFailed = true; const original = http.get.getMockImplementation()!;
  http.get.mockImplementation((path, options) => (path === "/api/admin/users" && listFailed) || (path === "/api/admin/users/user-2" && profileFailed) ? Promise.reject(new Error("failed")) : original(path, options));
  renderAccess(); fireEvent.click(screen.getByRole("button", { name: "Grant Access" })); await screen.findByRole("button", { name: "Retry user search" });
  listFailed = false; fireEvent.click(screen.getByRole("button", { name: "Retry user search" })); await screen.findByRole("option", { name: /Sara/ });
  fireEvent.change(screen.getByLabelText("User"), { target: { value: second.id } }); await screen.findByRole("button", { name: "Retry selected user" });
  profileFailed = false; fireEvent.click(screen.getByRole("button", { name: "Retry selected user" })); await screen.findByText("Selected user: Sara");
});
it("confirms the precise user/application pair and prevents duplicate revoke requests", async () => {
  let resolve!: (value: unknown) => void; http.delete.mockImplementation(() => new Promise((done) => { resolve = done; }));
  renderAccess(); fireEvent.click(await screen.findByRole("button", { name: "Revoke access" }));
  expect(screen.getByRole("dialog")).toHaveTextContent(`Hara (${user.email}) from CRM`); expect(http.delete).not.toHaveBeenCalled(); confirmRevoke();
  expect(screen.getByRole("button", { name: "Please wait…" })).toBeDisabled(); expect(screen.getByRole("button", { name: "Revoke access" })).toBeDisabled();
  expect(http.delete).toHaveBeenCalledWith("/api/admin/users/user-1/applications/app"); expect(http.delete).toHaveBeenCalledTimes(1);
  http.get.mockResolvedValue(response(page([]))); await act(async () => resolve({ status: 204 }));
  await screen.findByText("No users currently have an assignment for this application."); expect(screen.queryByText(user.email)).not.toBeInTheDocument();
});
it("preserves valid rows after revoke failure", async () => {
  http.delete.mockRejectedValue(new Error("failure")); renderAccess(); fireEvent.click(await screen.findByRole("button", { name: "Revoke access" })); confirmRevoke();
  await screen.findByRole("alert"); expect(screen.getByText("Yes — Granted")).toBeInTheDocument(); expect(screen.getByRole("dialog")).toBeInTheDocument(); expect(screen.getByRole("button", { name: "Revoke assignment" })).toBeEnabled();
});
it("a late revoke cannot update another application or remove its row", async () => {
  let resolve!: (value: unknown) => void; http.delete.mockImplementation(() => new Promise((done) => { resolve = done; }));
  const view = renderAccess(); fireEvent.click(await screen.findByRole("button", { name: "Revoke access" })); confirmRevoke();
  http.get.mockResolvedValue(response(page([second]))); view.rerender(<ApplicationUserAccess applicationId="other" applicationName="ERP" />); await screen.findByText(second.email);
  const calls = http.get.mock.calls.length; await act(async () => resolve({ status: 204 })); expect(http.get).toHaveBeenCalledTimes(calls); expect(screen.getByText(second.email)).toBeInTheDocument(); expect(screen.queryByText(/Assignment removed/)).not.toBeInTheDocument();
});
it("ignores an earlier page response after a successful grant refresh", async () => {
  let resolve!: (value: unknown) => void; const original = http.get.getMockImplementation()!;
  let listCalls = 0;
  http.get.mockImplementation((path, options) => {
    if (path === "/api/admin/applications/app/users") {
      listCalls++; if (listCalls === 2) return new Promise((done) => { resolve = done; });
      return Promise.resolve(response(page(listCalls > 2 ? [second] : [user], options.params.page, 2)));
    }
    return original(path, options);
  });
  renderAccess(); await screen.findByText(user.email); fireEvent.click(screen.getByRole("button", { name: "Next" })); await select(); grant(); await screen.findByText(second.email);
  await act(async () => resolve(response(page([{ ...user, name: "Old page" }], 2, 2)))); expect(screen.queryByText("Old page")).not.toBeInTheDocument();
});
it("keeps the current page after revoke and falls back when the page no longer exists", async () => {
  let deleted = false;
  http.get.mockImplementation((_path, options) => Promise.resolve(response(page(deleted ? [] : [user], options.params.page, deleted ? 1 : 2))));
  http.delete.mockImplementation(() => { deleted = true; return Promise.resolve({ status: 204 }); });
  renderAccess(); await screen.findByText(user.email); fireEvent.click(screen.getByRole("button", { name: "Next" })); await screen.findByText("Page 2 of 2");
  fireEvent.click(screen.getByRole("button", { name: "Revoke access" })); confirmRevoke(); await screen.findByText("No users currently have an assignment for this application.");
  expect(http.get).toHaveBeenLastCalledWith("/api/admin/applications/app/users", { params: { page: 1, per_page: 25 } });
});
it("a revoke completed after page navigation refreshes the latest selected page", async () => {
  let resolve!: (value: unknown) => void;
  http.get.mockImplementation((_path, options) => Promise.resolve(response(page([user], options.params.page, 2))));
  http.delete.mockImplementation(() => new Promise((done) => { resolve = done; }));
  renderAccess(); fireEvent.click(await screen.findByRole("button", { name: "Revoke access" })); confirmRevoke();
  fireEvent.click(screen.getByRole("button", { name: "Next" })); await screen.findByText("Page 2 of 2");
  await act(async () => resolve({ status: 204 }));
  await waitFor(() => expect(http.get).toHaveBeenLastCalledWith("/api/admin/applications/app/users", { params: { page: 2, per_page: 25 } }));
});
it("does not mount user access when the parent application is unavailable", async () => {
  http.get.mockRejectedValue(new Error("not found")); render(<ApplicationDetails id="missing" tab="user-access" />); await screen.findByRole("alert"); expect(screen.queryByRole("button", { name: "Grant Access" })).not.toBeInTheDocument(); expect(http.get).toHaveBeenCalledTimes(1);
});
it("non-admin and loading authorization prevent mounting access management", () => {
  auth.user = { is_system_admin: false }; const view = render(<ProtectedShell><ApplicationUserAccess applicationId="app" applicationName="CRM" /></ProtectedShell>);
  expect(screen.getByText("Administrator access required")).toBeInTheDocument(); expect(http.get).not.toHaveBeenCalled();
  auth.isLoading = true; view.rerender(<ProtectedShell><ApplicationUserAccess applicationId="app" applicationName="CRM" /></ProtectedShell>); expect(http.get).not.toHaveBeenCalled();
});
it("bounds the application-user list pagination in the actual service", async () => {
  await applicationsService.users("app", 0, 200); expect(http.get).toHaveBeenLastCalledWith("/api/admin/applications/app/users", { params: { page: 1, per_page: 100 } });
});
