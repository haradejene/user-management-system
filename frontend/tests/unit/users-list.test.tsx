import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";

const { list } = vi.hoisted(() => ({ list: vi.fn() }));
vi.mock("@/services/users.service", () => ({ usersService: { list } }));
import { UsersList } from "@/components/admin/UsersList";
import type { User } from "@/types/user";

const user: User = { id: "user-1", name: "Ada", email: "ada@example.test", status: "active", is_system_admin: false, email_verified_at: null, created_at: null, updated_at: null };
const page = (data = [user], current_page = 1, last_page = 1) => ({ data, meta: { current_page, last_page, per_page: 25, total: last_page * 25 } });

beforeEach(() => { vi.clearAllMocks(); list.mockResolvedValue(page()); });
afterEach(cleanup);

it("loads the first page and requests the next page", async () => {
  list.mockResolvedValueOnce(page([user], 1, 2)).mockResolvedValueOnce(page([{ ...user, id: "user-2", name: "Grace" }], 2, 2));
  render(<UsersList />);
  expect(await screen.findByText("Ada")).toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  await waitFor(() => expect(list).toHaveBeenLastCalledWith("", 2, 25, undefined));
  expect(await screen.findByText("Grace")).toBeInTheDocument();
});

it("resets to page one when a status filter changes", async () => {
  list.mockResolvedValue(page([user], 1, 2));
  render(<UsersList />);
  await screen.findByText("Ada");
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  await waitFor(() => expect(list).toHaveBeenLastCalledWith("", 2, 25, undefined));
  fireEvent.change(screen.getByLabelText("Filter users by status"), { target: { value: "suspended" } });
  await waitFor(() => expect(list).toHaveBeenLastCalledWith("", 1, 25, "suspended"));
});

it("does not let a stale page response replace the current page", async () => {
  let resolveFirst!: (value: unknown) => void;
  list.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }))
    .mockResolvedValueOnce(page([{ ...user, id: "user-2", name: "Current page" }], 2, 2));
  render(<UsersList />);
  await waitFor(() => expect(list).toHaveBeenCalledWith("", 1, 25, undefined));
  fireEvent.change(screen.getByLabelText("Search users"), { target: { value: "current" } });
  await waitFor(() => expect(list).toHaveBeenCalledWith("current", 1, 25, undefined));
  resolveFirst(page([{ ...user, name: "Stale page" }], 1, 1));
  expect(await screen.findByText("Current page")).toBeInTheDocument();
  expect(screen.queryByText("Stale page")).not.toBeInTheDocument();
});

it("shows empty and failed states", async () => {
  list.mockResolvedValueOnce(page([], 1, 1));
  render(<UsersList />);
  expect(await screen.findByText("No users found.")).toBeInTheDocument();
  cleanup();
  list.mockRejectedValueOnce(new Error("request failed"));
  render(<UsersList />);
  expect(await screen.findByRole("alert")).toBeInTheDocument();
});
