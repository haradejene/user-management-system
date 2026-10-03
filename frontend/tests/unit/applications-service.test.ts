import { beforeEach, expect, it, vi } from "vitest";

const { get, patch, post } = vi.hoisted(() => ({ get: vi.fn(), patch: vi.fn(), post: vi.fn() }));
vi.mock("@/services/api-client", () => ({ apiClient: { get, patch, post } }));
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

it("uses scoped OAuth list/detail routes and bounded pagination", async () => {
  await applicationsService.oauthClients("app", 0, 200);
  expect(get).toHaveBeenLastCalledWith("/api/admin/applications/app/oauth-clients", { params: { page: 1, per_page: 100 } });
  await applicationsService.oauthClient("app", "client");
  expect(get).toHaveBeenLastCalledWith("/api/admin/applications/app/oauth-clients/client");
});

it("whitelists creation fields, returns the creation envelope and handles no-content revoke", async () => {
  const envelope = { data: { id: "client" }, client_secret: "mock-secret" };
  post.mockResolvedValue({ data: envelope });
  const input = { name: "Client", redirect_uris: ["https://example.test"], confidential: true, application_id: "wrong", grant_types: ["unsupported"] };
  expect(await applicationsService.createOAuthClient("app", input)).toEqual(envelope);
  expect(post).toHaveBeenCalledWith("/api/admin/applications/app/oauth-clients", { name: "Client", redirect_uris: input.redirect_uris, confidential: true });
  patch.mockResolvedValue({ status: 204 });
  expect(await applicationsService.revokeOAuthClient("app", "client")).toBeUndefined();
  expect(patch).toHaveBeenLastCalledWith("/api/admin/applications/app/oauth-clients/client/revoke");
});
