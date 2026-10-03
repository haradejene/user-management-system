import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { AxiosError } from "axios";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import type { Application } from "@/types/application";

const { service, auth, router } = vi.hoisted(() => ({
  service: { list: vi.fn(), get: vi.fn(), update: vi.fn(), changeStatus: vi.fn() },
  auth: { user: { is_system_admin: true }, isLoading: false, logout: vi.fn() },
  router: { replace: vi.fn(), refresh: vi.fn() },
}));
vi.mock("@/services/applications.service", () => ({ applicationsService: service }));
vi.mock("@/hooks/useAuth", () => ({ useAuth: () => auth }));
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/applications" }));
import { ApplicationsList } from "@/components/admin/applications/ApplicationsList";
import { ApplicationDetails } from "@/components/admin/applications/ApplicationDetails";
import { ProtectedShell } from "@/components/auth/ProtectedShell";

const application: Application = { id: "11111111-1111-4111-8111-111111111111", name: "Doxa CRM", slug: "crm", description: "Customer management", status: "active", created_at: "2026-01-02T00:00:00Z", updated_at: "2026-02-03T00:00:00Z" };
const page = (data = [application], current_page = 1, last_page = 1) => ({ data, meta: { current_page, last_page, per_page: 25, total: data.length } });

beforeEach(() => {
  vi.resetAllMocks(); auth.user = { is_system_admin: true }; auth.isLoading = false;
  service.list.mockResolvedValue(page()); service.get.mockResolvedValue(application);
  service.update.mockImplementation((_id, input) => Promise.resolve({ ...application, ...input }));
  service.changeStatus.mockImplementation((_id, action) => Promise.resolve({ ...application, status: action === "activate" ? "active" : "inactive" }));
});
afterEach(cleanup);

it("lists safe metadata, public detail links and a create action", async () => {
  render(<ApplicationsList />);
  expect(screen.getByText("Loading applications…")).toBeInTheDocument();
  await screen.findByText(application.name);
  expect(screen.getByText(application.description!)).toBeInTheDocument();
  expect(screen.getByText("Jan 2, 2026")).toBeInTheDocument();
  expect(screen.getByRole("link", { name: "View details" })).toHaveAttribute("href", `/applications/${application.id}`);
  expect(screen.getByRole("link", { name: "Create application" })).toHaveAttribute("href", "/applications/new");
});

it("paginates and resets search and status changes to page one", async () => {
  service.list.mockImplementation((_search, currentPage) => Promise.resolve(page([application], currentPage, 2)));
  render(<ApplicationsList />);
  await screen.findByText(application.name);
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  await screen.findByText("Page 2 of 2");
  fireEvent.change(screen.getByLabelText("Search applications"), { target: { value: "crm" } });
  await waitFor(() => expect(service.list).toHaveBeenLastCalledWith("crm", 1, 25, undefined));
  await screen.findByText("Page 1 of 2");
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  await screen.findByText("Page 2 of 2");
  fireEvent.change(screen.getByLabelText("Filter applications by status"), { target: { value: "inactive" } });
  await waitFor(() => expect(service.list).toHaveBeenLastCalledWith("crm", 1, 25, "inactive"));
});

it("ignores stale search results and debounces rapid typing", async () => {
  let resolveOld!: (value: unknown) => void;
  service.list.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; })).mockResolvedValue(page([{ ...application, name: "Latest" }]));
  render(<ApplicationsList />);
  await waitFor(() => expect(service.list).toHaveBeenCalledTimes(1));
  fireEvent.change(screen.getByLabelText("Search applications"), { target: { value: "c" } });
  fireEvent.change(screen.getByLabelText("Search applications"), { target: { value: "crm" } });
  await screen.findByText("Latest");
  await act(async () => resolveOld(page([{ ...application, name: "Stale" }])));
  expect(screen.queryByText("Stale")).not.toBeInTheDocument();
  expect(service.list).toHaveBeenCalledTimes(2);
  expect(service.list).toHaveBeenLastCalledWith("crm", 1, 25, undefined);
});

it("ignores late errors after rapidly changing status", async () => {
  let rejectOld!: (error: Error) => void;
  service.list.mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectOld = reject; })).mockResolvedValue(page());
  render(<ApplicationsList />);
  await waitFor(() => expect(service.list).toHaveBeenCalledTimes(1));
  const filter = screen.getByLabelText("Filter applications by status");
  fireEvent.change(filter, { target: { value: "inactive" } });
  fireEvent.change(filter, { target: { value: "active" } });
  await screen.findByText(application.name);
  await act(async () => rejectOld(new Error("stale")));
  expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  expect(service.list).toHaveBeenLastCalledWith("", 1, 25, "active");
});

it("rejects a stale page response after changing the filter", async () => {
  let resolveOld!: (value: unknown) => void;
  service.list.mockResolvedValueOnce(page([application], 1, 2))
    .mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; }))
    .mockResolvedValue(page([{ ...application, name: "Filtered" }]));
  render(<ApplicationsList />);
  await screen.findByText(application.name);
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  await waitFor(() => expect(resolveOld).toBeTypeOf("function"));
  fireEvent.change(screen.getByLabelText("Filter applications by status"), { target: { value: "active" } });
  await screen.findByText("Filtered");
  await act(async () => resolveOld(page([{ ...application, name: "Old page" }], 2, 2)));
  expect(screen.queryByText("Old page")).not.toBeInTheDocument();
});

it("shows an empty list and retries a failed request with the same filters", async () => {
  service.list.mockRejectedValueOnce(new Error("failed")).mockResolvedValue(page([]));
  render(<ApplicationsList />);
  await screen.findByRole("alert");
  fireEvent.click(screen.getByRole("button", { name: "Retry" }));
  expect(await screen.findByText("No applications found.")).toBeInTheDocument();
  expect(service.list).toHaveBeenLastCalledWith("", 1, 25, undefined);
});

it("renders detail loading and overview with only actual application fields", async () => {
  render(<ApplicationDetails id={application.id} />);
  expect(screen.getByText("Loading application…")).toBeInTheDocument();
  await screen.findByRole("heading", { name: application.name });
  expect(screen.getByText("Public application ID")).toBeInTheDocument();
  expect(screen.getByText(application.id)).toBeInTheDocument();
  expect(screen.getByText("Feb 3, 2026")).toBeInTheDocument();
  expect(screen.queryByText(/client count|assigned-user count/i)).not.toBeInTheDocument();
  const navigation = screen.getByRole("navigation", { name: "Application sections" });
  expect(within(navigation).getByRole("link", { name: "Overview" })).toHaveAttribute("aria-current", "page");
  expect(within(navigation).getByRole("link", { name: "Settings" })).toHaveAttribute("href", `/applications/${application.id}/edit`);
  expect(within(navigation).getByRole("link", { name: "OAuth Clients" })).toHaveAttribute("href", `/applications/${application.id}/oauth-clients`);
  expect(within(navigation).getByRole("link", { name: "User Access" })).toHaveAttribute("href", `/applications/${application.id}/user-access`);
});

it("shows detail errors and retries without rendering an editable form", async () => {
  service.get.mockRejectedValueOnce(new Error("not found")).mockResolvedValue(application);
  render(<ApplicationDetails id={application.id} tab="settings" />);
  await screen.findByRole("alert"); expect(screen.queryByLabelText("Name")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Retry" }));
  expect(await screen.findByLabelText("Name")).toHaveValue(application.name);
});

it("ignores detail responses for a previously selected application", async () => {
  let resolveOld!: (value: unknown) => void;
  service.get.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; })).mockResolvedValue({ ...application, id: "second", name: "ERP" });
  const view = render(<ApplicationDetails id={application.id} />);
  view.rerender(<ApplicationDetails id="second" />);
  await screen.findByRole("heading", { name: "ERP" });
  await act(async () => resolveOld(application));
  expect(screen.queryByRole("heading", { name: application.name })).not.toBeInTheDocument();
});

it("loads existing settings and saves only editable metadata", async () => {
  render(<ApplicationDetails id={application.id} tab="settings" />);
  const name = await screen.findByLabelText("Name");
  expect(name).toHaveValue(application.name); expect(screen.getByLabelText("Slug")).toHaveValue("crm");
  expect(screen.getByLabelText("Description")).toHaveValue(application.description);
  fireEvent.change(name, { target: { value: "New CRM" } });
  fireEvent.change(screen.getByLabelText("Slug"), { target: { value: "new-crm" } });
  fireEvent.change(screen.getByLabelText("Description"), { target: { value: "" } });
  fireEvent.click(screen.getByRole("button", { name: "Save application" }));
  await screen.findByText("Application settings saved.");
  expect(service.update).toHaveBeenCalledWith(application.id, { name: "New CRM", slug: "new-crm", description: null });
  expect(screen.getByRole("heading", { name: "New CRM" })).toBeInTheDocument();
  expect(screen.getByRole("link", { name: "Settings" })).toHaveAttribute("aria-current", "page");
  expect(screen.queryByLabelText("Public application ID")).not.toBeInTheDocument();
});

it("shows backend field validation and preserves unsaved values", async () => {
  const error = new AxiosError("validation");
  error.response = { status: 422, data: { message: "Invalid metadata", errors: { slug: ["The slug has already been taken."], description: ["Description rejected."] } } } as AxiosError["response"];
  service.update.mockRejectedValue(error);
  render(<ApplicationDetails id={application.id} tab="settings" />);
  fireEvent.change(await screen.findByLabelText("Slug"), { target: { value: "duplicate" } });
  fireEvent.click(screen.getByRole("button", { name: "Save application" }));
  await screen.findByText("Description rejected.");
  expect(screen.getByLabelText("Slug")).toHaveValue("duplicate");
  expect(screen.getByLabelText("Slug")).toHaveAttribute("aria-invalid", "true");
  expect(screen.getByLabelText("Slug")).toHaveAccessibleDescription("The slug has already been taken.");
});

it("disables editing while a metadata update is pending and handles server failure", async () => {
  let rejectUpdate!: (error: Error) => void;
  service.update.mockImplementation(() => new Promise((_resolve, reject) => { rejectUpdate = reject; }));
  render(<ApplicationDetails id={application.id} tab="settings" />);
  await screen.findByLabelText("Name");
  fireEvent.click(screen.getByRole("button", { name: "Save application" }));
  expect(screen.getByLabelText("Name")).toBeDisabled();
  expect(screen.getByRole("button", { name: "Deactivate application" })).toBeDisabled();
  await act(async () => rejectUpdate(new Error("failed")));
  expect(await screen.findByRole("alert")).toBeInTheDocument();
  expect(screen.getByLabelText("Name")).not.toBeDisabled();
});

it("requires confirmation for deactivation, refreshes status and preserves unsaved metadata", async () => {
  render(<ApplicationDetails id={application.id} tab="settings" />);
  fireEvent.change(await screen.findByLabelText("Name"), { target: { value: "Unsaved name" } });
  fireEvent.click(screen.getByRole("button", { name: "Deactivate application" }));
  expect(service.changeStatus).not.toHaveBeenCalled();
  fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Cancel" }));
  expect(service.changeStatus).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole("button", { name: "Deactivate application" }));
  fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: /^Deactivate$/ }));
  await screen.findByText("Application deactivated.");
  expect(service.changeStatus).toHaveBeenCalledWith(application.id, "deactivate");
  expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  expect(screen.getByLabelText("Name")).toHaveValue("Unsaved name");
  expect(screen.getByRole("link", { name: "Settings" })).toHaveAttribute("aria-current", "page");
  expect(service.update).not.toHaveBeenCalled();
});

it("activates an inactive application through its dedicated endpoint", async () => {
  service.get.mockResolvedValue({ ...application, status: "inactive" });
  render(<ApplicationDetails id={application.id} tab="settings" />);
  fireEvent.click(await screen.findByRole("button", { name: "Activate application" }));
  await screen.findByText("Application activated.");
  expect(service.changeStatus).toHaveBeenCalledWith(application.id, "activate");
  expect(screen.getByText("active", { exact: true })).toBeInTheDocument();
});

it("keeps the current status and dialog after a failed status mutation", async () => {
  service.changeStatus.mockRejectedValue(new Error("failed"));
  render(<ApplicationDetails id={application.id} tab="settings" />);
  fireEvent.click(await screen.findByRole("button", { name: "Deactivate application" }));
  fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: /^Deactivate$/ }));
  await screen.findByRole("alert");
  expect(screen.getByText("active", { exact: true })).toBeInTheDocument();
  expect(screen.getByRole("dialog")).toBeInTheDocument();
  expect(screen.queryByText("Application deactivated.")).not.toBeInTheDocument();
});

it("blocks non-admin application components rather than merely hiding their controls", () => {
  auth.user = { is_system_admin: false };
  render(<ProtectedShell><ApplicationsList /></ProtectedShell>);
  expect(screen.getByText("Administrator access required")).toBeInTheDocument();
  expect(service.list).not.toHaveBeenCalled();
  expect(screen.queryByRole("link", { name: "Create application" })).not.toBeInTheDocument();
});

it("does not mount application settings while authorization is loading", () => {
  auth.isLoading = true;
  render(<ProtectedShell><ApplicationDetails id={application.id} tab="settings" /></ProtectedShell>);
  expect(service.get).not.toHaveBeenCalled();
  expect(screen.queryByRole("button", { name: "Save application" })).not.toBeInTheDocument();
});
