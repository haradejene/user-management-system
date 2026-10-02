import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";

const { applicationsService, usersService, navigation } = vi.hoisted(() => ({
  navigation: { query: "" },
  applicationsService: {
    list: vi.fn(), forUser: vi.fn(), grant: vi.fn(), revoke: vi.fn(),
  },
  usersService: { list: vi.fn(), get: vi.fn() },
}));

vi.mock("next/navigation", () => ({ useSearchParams: () => new URLSearchParams(navigation.query) }));
vi.mock("@/services/applications.service", () => ({ applicationsService }));
vi.mock("@/services/users.service", () => ({ usersService }));

import { ApplicationAccessManager } from "@/components/application-access/ApplicationAccessManager";

const user = { id: "user-1", name: "Hara", email: "hara@example.test", status: "active", is_system_admin: false, email_verified_at: null, created_at: null, updated_at: null } as const;
const application = { id: "app-1", name: "HRM", slug: "hrm", description: null, status: "active", created_at: null, updated_at: null } as const;
const page = <T,>(data: T[], last_page = 1) => ({ data, meta: { current_page: 1, last_page, per_page: 15, total: data.length } });

beforeEach(() => {
  vi.resetAllMocks();
  navigation.query = "";
  usersService.list.mockResolvedValue(page([user]));
  usersService.get.mockResolvedValue(user);
  applicationsService.list.mockResolvedValue(page([application], 2));
  applicationsService.forUser.mockResolvedValue(page([{
    ...application, access_status: "inactive", assignment_exists: true, effective_access: false, ineffective_reason: "assignment_inactive", granted_at: null,
  }]));
});
afterEach(cleanup);

it("shows assigned and ineffective separately and supports application pagination", async () => {
  render(<ApplicationAccessManager />);
  expect(await screen.findByText("assigned · ineffective: assignment inactive")).toBeInTheDocument();
  expect(screen.getByRole("button", { name: "Next" })).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  await waitFor(() => expect(applicationsService.list).toHaveBeenCalledWith("", 2, 15));
});

it("ignores a stale assignment response after switching users", async () => {
  const secondUser = { ...user, id: "user-2", name: "Sara", email: "sara@example.test" };
  usersService.list.mockResolvedValue(page([user, secondUser]));
  usersService.get.mockImplementation((id) => Promise.resolve(id === secondUser.id ? secondUser : user));
  let resolveFirst!: (value: unknown) => void;
  let resolveSecond!: (value: unknown) => void;
  applicationsService.forUser
    .mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }))
    .mockImplementationOnce(() => new Promise((resolve) => { resolveSecond = resolve; }));

  render(<ApplicationAccessManager />);
  await screen.findByRole("option", { name: /Sara/ });
  fireEvent.change(screen.getByLabelText("User"), { target: { value: "user-2" } });
  await waitFor(() => expect(resolveSecond).toBeTypeOf("function"));
  resolveSecond(page([{ ...application, access_status: "active", assignment_exists: true, effective_access: true, ineffective_reason: null, granted_at: null }]));
  await screen.findByText("effective access");
  resolveFirst(page([{ ...application, access_status: "inactive", assignment_exists: true, effective_access: false, ineffective_reason: "user_inactive", granted_at: null }]));
  await waitFor(() => expect(screen.getByText("effective access")).toBeInTheDocument());
});

it("keeps a null revoke override absent even when the fetched assignment still exists", async () => {
  applicationsService.revoke.mockResolvedValue(undefined);
  render(<ApplicationAccessManager />);
  const checkbox = await screen.findByRole("checkbox");
  expect(checkbox).toBeChecked();
  fireEvent.click(checkbox);
  fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Revoke access" }));
  await waitFor(() => expect(checkbox).not.toBeChecked());
  expect(screen.getByText("not assigned")).toBeInTheDocument();
  expect(screen.queryByText(/assigned · ineffective/)).not.toBeInTheDocument();
});

it("looks up only visible applications instead of assuming an unrelated assignment page is complete", async () => {
  const otherApp = { ...application, id: "app-20", name: "CRM" };
  applicationsService.list.mockResolvedValue(page([application, otherApp]));
  applicationsService.forUser.mockImplementation((_id, requestedPage, perPage, ids) => {
    expect(requestedPage).toBe(1);
    expect(perPage).toBe(2);
    expect(ids).toEqual(["app-1", "app-20"]);
    return Promise.resolve(page([{ ...otherApp, assignment_exists: true, effective_access: true, access_status: "active", ineffective_reason: null }]));
  });
  render(<ApplicationAccessManager />);
  const checkboxes = await screen.findAllByRole("checkbox");
  expect(checkboxes[0]).not.toBeChecked();
  expect(checkboxes[1]).toBeChecked();
});

it("keeps preselected user, user list and application responses independent", async () => {
  navigation.query = "user=user-20";
  const preselected = { ...user, id: "user-20", name: "Preselected" };
  let resolveUsers!: (value: unknown) => void;
  usersService.list.mockImplementation(() => new Promise((resolve) => { resolveUsers = resolve; }));
  usersService.get.mockResolvedValue(preselected);
  render(<ApplicationAccessManager />);
  await screen.findByRole("checkbox");
  expect(screen.getByLabelText("User")).toHaveValue("user-20");
  expect(screen.getByRole("heading", { name: "Preselected" })).toBeInTheDocument();
  await act(async () => resolveUsers(page([user])));
  expect(await screen.findByRole("option", { name: /Hara/ })).toBeInTheDocument();
  expect(screen.getByLabelText("User")).toHaveValue("user-20");
  expect(applicationsService.forUser).toHaveBeenCalledWith("user-20", 1, 1, ["app-1"]);
});

it("ignores stale assignments after reselection during application pagination", async () => {
  const otherApp = { ...application, id: "app-2", name: "CRM" };
  let resolveOld!: (value: unknown) => void;
  let resolveNew!: (value: unknown) => void;
  applicationsService.list.mockImplementation((_search, requestedPage) => Promise.resolve({ ...page(requestedPage === 1 ? [application] : [otherApp], 2), meta: { ...page([], 2).meta, current_page: requestedPage } }));
  applicationsService.forUser.mockImplementationOnce(() => Promise.resolve(page([])))
    .mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; }))
    .mockImplementationOnce(() => new Promise((resolve) => { resolveNew = resolve; }));
  render(<ApplicationAccessManager />);
  await screen.findByRole("checkbox");
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  await waitFor(() => expect(resolveOld).toBeTypeOf("function"));
  // Switch users while page 2's assignment request is pending.
  fireEvent.change(screen.getByLabelText("User"), { target: { value: "" } });
  fireEvent.change(screen.getByLabelText("User"), { target: { value: "user-1" } });
  await waitFor(() => expect(resolveNew).toBeTypeOf("function"));
  await act(async () => resolveNew(page([{ ...otherApp, assignment_exists: true, effective_access: true, access_status: "active", ineffective_reason: null }])));
  await screen.findByText("effective access");
  await act(async () => resolveOld(page([])));
  expect(screen.getByRole("checkbox")).toBeChecked();
  expect(usersService.list).toHaveBeenCalledTimes(1);
});

it("ignores a late selected-user profile after switching users", async () => {
  const secondUser = { ...user, id: "user-2", name: "Sara" };
  let resolveOld!: (value: unknown) => void;
  usersService.list.mockResolvedValue(page([user, secondUser]));
  usersService.get.mockImplementationOnce(() => new Promise((resolve) => { resolveOld = resolve; })).mockResolvedValue(secondUser);
  render(<ApplicationAccessManager />);
  await screen.findByRole("option", { name: /Sara/ });
  fireEvent.change(screen.getByLabelText("User"), { target: { value: "user-2" } });
  await screen.findByRole("heading", { name: "Sara" });
  await act(async () => resolveOld(user));
  expect(screen.getByRole("heading", { name: "Sara" })).toBeInTheDocument();
  expect(screen.queryByRole("heading", { name: "Hara" })).not.toBeInTheDocument();
});

it("does not treat a failed or truncated lookup as an absent assignment", async () => {
  applicationsService.forUser.mockResolvedValue(page([], 2));
  render(<ApplicationAccessManager />);
  await screen.findByText("Unable to connect to the identity service.");
  expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
  expect(applicationsService.grant).not.toHaveBeenCalled();
});

it("does not let an old user's revoke close the new user's confirmation", async () => {
  const secondUser = { ...user, id: "user-2", name: "Sara" };
  let resolveRevoke!: () => void;
  usersService.list.mockResolvedValue(page([user, secondUser]));
  usersService.get.mockImplementation((id) => Promise.resolve(id === secondUser.id ? secondUser : user));
  applicationsService.revoke.mockImplementation(() => new Promise<void>((resolve) => { resolveRevoke = resolve; }));
  render(<ApplicationAccessManager />);
  fireEvent.click(await screen.findByRole("checkbox"));
  fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Revoke access" }));
  fireEvent.change(screen.getByLabelText("User"), { target: { value: "user-2" } });
  fireEvent.click(await screen.findByRole("checkbox"));
  await act(async () => resolveRevoke());
  expect(screen.getByRole("dialog")).toBeInTheDocument();
  expect(screen.getByRole("checkbox")).toBeChecked();
});

it("rejects stale user-list pages while preserving loaded application assignments", async () => {
  let resolveThird!: (value: unknown) => void;
  usersService.list.mockImplementation((_search, requestedPage) => {
    if (requestedPage === 3) return new Promise((resolve) => { resolveThird = resolve; });
    return Promise.resolve({ ...page([user], 3), meta: { ...page([], 3).meta, current_page: requestedPage } });
  });
  render(<ApplicationAccessManager />);
  await screen.findByRole("checkbox");
  const userPagination = screen.getAllByRole("navigation", { name: "Pagination" })[0];
  fireEvent.click(within(userPagination).getByRole("button", { name: "Next" }));
  await screen.findByText("Page 2 of 3");
  fireEvent.click(within(userPagination).getByRole("button", { name: "Next" }));
  await waitFor(() => expect(resolveThird).toBeTypeOf("function"));
  fireEvent.click(within(userPagination).getByRole("button", { name: "Previous" }));
  await screen.findByText("Page 1 of 3");
  await act(async () => resolveThird({ ...page([{ ...user, id: "stale-user", name: "Stale" }], 3), meta: { ...page([], 3).meta, current_page: 3 } }));
  expect(screen.queryByRole("option", { name: /Stale/ })).not.toBeInTheDocument();
  expect(screen.getByRole("checkbox")).toBeChecked();
  expect(applicationsService.forUser).toHaveBeenCalledTimes(1);
});

it("preserves an assignment when revocation fails", async () => {
  applicationsService.revoke.mockRejectedValue(new Error("failure"));
  render(<ApplicationAccessManager />);
  fireEvent.click(await screen.findByRole("checkbox"));
  fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Revoke access" }));
  await screen.findByText("Unable to connect to the identity service.");
  expect(screen.getByRole("checkbox")).toBeChecked();
  expect(screen.getByRole("dialog")).toBeInTheDocument();
});

it("does not replace a completed revoke with a lookup started during that mutation", async () => {
  let resolveRevoke!: () => void;
  let resolveLookup!: (value: unknown) => void;
  applicationsService.revoke.mockImplementation(() => new Promise<void>((resolve) => { resolveRevoke = resolve; }));
  applicationsService.list.mockImplementation((_search, requestedPage) => Promise.resolve({ ...page([application], 2), meta: { ...page([], 2).meta, current_page: requestedPage } }));
  applicationsService.forUser.mockImplementationOnce(() => Promise.resolve(page([{ ...application, assignment_exists: true, effective_access: true, access_status: "active", ineffective_reason: null }])))
    .mockImplementationOnce(() => new Promise((resolve) => { resolveLookup = resolve; }));
  render(<ApplicationAccessManager />);
  fireEvent.click(await screen.findByRole("checkbox"));
  fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Revoke access" }));
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  await waitFor(() => expect(resolveLookup).toBeTypeOf("function"));
  await act(async () => resolveRevoke());
  await act(async () => resolveLookup(page([{ ...application, assignment_exists: true, effective_access: true, access_status: "active", ineffective_reason: null }])));
  expect(await screen.findByRole("checkbox")).not.toBeChecked();
  expect(screen.getByText("not assigned")).toBeInTheDocument();
});

it("waits for the actual application list before looking up preselected assignments", async () => {
  navigation.query = "user=user-1";
  let resolveApps!: (value: unknown) => void;
  applicationsService.list.mockImplementation(() => new Promise((resolve) => { resolveApps = resolve; }));
  render(<ApplicationAccessManager />);
  await screen.findByRole("option", { name: /Hara/ });
  expect(applicationsService.forUser).not.toHaveBeenCalled();
  await act(async () => resolveApps(page([application])));
  expect(await screen.findByRole("checkbox")).toBeChecked();
  expect(applicationsService.list).toHaveBeenCalledTimes(1);
});

it("updates assignment state only after a successful grant and revoke", async () => {
  applicationsService.forUser.mockResolvedValue(page([]));
  applicationsService.grant.mockResolvedValue({ ...application, access_status: "active", assignment_exists: true, effective_access: true, ineffective_reason: null, granted_at: null });
  render(<ApplicationAccessManager />);
  const checkbox = (await screen.findAllByRole("checkbox"))[0];
  fireEvent.click(checkbox);
  await waitFor(() => expect(checkbox).toBeChecked());
  fireEvent.click(checkbox);
  fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Revoke access" }));
  await waitFor(() => expect(applicationsService.revoke).toHaveBeenCalledWith("user-1", "app-1"));
  expect(checkbox).not.toBeChecked();
});
