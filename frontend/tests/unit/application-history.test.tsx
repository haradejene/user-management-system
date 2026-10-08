import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock("@/services/api-client", async (original) => ({ ...await original<typeof import("@/services/api-client")>(), apiClient: { get } }));
import { ApplicationHistory } from "@/components/admin/applications/ApplicationHistory";
afterEach(() => { cleanup(); vi.resetAllMocks(); });
it("loads only stored application events when opened and paginates history", async () => {
  get.mockResolvedValue({ data: { data: [{ id: 1, action: "application.access_granted", actor_id: "admin", subject_id: "user", client_id: null, created_at: "2026-10-07T00:00:00Z" }], last_page: 2 } });
  render(<ApplicationHistory applicationId="application" />);
  expect(get).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole("button", { name: "Administrative history" }));
  await screen.findByText("application.access_granted");
  expect(get).toHaveBeenCalledWith("/api/admin/applications/application/history", { params: { page: 1 } });
  fireEvent.click(screen.getByRole("button", { name: "Next history" }));
  await waitFor(() => expect(get).toHaveBeenLastCalledWith("/api/admin/applications/application/history", { params: { page: 2 } }));
});
it("shows a retryable history failure without inventing events", async () => {
  get.mockRejectedValueOnce(new Error("private failure")).mockResolvedValue({ data: { data: [], last_page: 1 } });
  render(<ApplicationHistory applicationId="application" />);
  fireEvent.click(screen.getByRole("button", { name: "Administrative history" }));
  await screen.findByRole("alert");
  expect(screen.queryByText("private failure")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Retry history" }));
  await screen.findByText("No recorded events.");
});
