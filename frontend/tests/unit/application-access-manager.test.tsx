import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";

const { applicationsService, usersService } = vi.hoisted(() => ({
  applicationsService: {
    list: vi.fn(), forUser: vi.fn(), grant: vi.fn(), revoke: vi.fn(),
  },
  usersService: { list: vi.fn(), get: vi.fn() },
}));

vi.mock("next/navigation", () => ({ useSearchParams: () => new URLSearchParams() }));
vi.mock("@/services/applications.service", () => ({ applicationsService }));
vi.mock("@/services/users.service", () => ({ usersService }));

import { ApplicationAccessManager } from "@/components/application-access/ApplicationAccessManager";

const user = { id: "user-1", name: "Hara", email: "hara@example.test", status: "active", is_system_admin: false, email_verified_at: null, created_at: null, updated_at: null } as const;
const application = { id: "app-1", name: "HRM", slug: "hrm", description: null, status: "active", created_at: null, updated_at: null } as const;
const page = <T,>(data: T[], last_page = 1) => ({ data, meta: { current_page: 1, last_page, per_page: 15, total: data.length } });

beforeEach(() => {
  vi.clearAllMocks();
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
