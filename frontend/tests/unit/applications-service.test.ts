import { beforeEach, expect, it, vi } from "vitest";

const { get, patch } = vi.hoisted(() => ({ get: vi.fn(), patch: vi.fn() }));
vi.mock("@/services/api-client", () => ({ apiClient: { get, patch } }));
import { applicationsService } from "@/services/applications.service";

beforeEach(() => { vi.resetAllMocks(); get.mockResolvedValue({ data: { data: [], meta: {} } }); patch.mockResolvedValue({ data: { data: { id: "public-id" } } }); });

it("sends search, pagination and the existing status filter to the application API", async () => {
  await applicationsService.list("crm", 2, 25, "inactive");
  expect(get).toHaveBeenCalledWith("/api/admin/applications", { params: { search: "crm", page: 2, per_page: 25, status: "inactive" } });
});

it("uses dedicated lifecycle routes rather than a metadata patch", async () => {
  await applicationsService.changeStatus("public-id", "deactivate");
  expect(patch).toHaveBeenCalledWith("/api/admin/applications/public-id/deactivate");
  await applicationsService.changeStatus("public-id", "activate");
  expect(patch).toHaveBeenLastCalledWith("/api/admin/applications/public-id/activate");
});
