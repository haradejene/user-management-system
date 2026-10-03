import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { AxiosError } from "axios";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import type { OAuthClient } from "@/types/oauth-client";

const { service, auth } = vi.hoisted(() => ({ service: { oauthClients: vi.fn(), oauthClient: vi.fn(), createOAuthClient: vi.fn(), revokeOAuthClient: vi.fn() }, auth: { user: { is_system_admin: true }, isLoading: false, logout: vi.fn() } }));
vi.mock("@/services/applications.service", () => ({ applicationsService: service }));
vi.mock("@/hooks/useAuth", () => ({ useAuth: () => auth }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: vi.fn() }), usePathname: () => "/applications/app/oauth-clients" }));
import { ApplicationOAuthClients } from "@/components/admin/applications/ApplicationOAuthClients";
import { ProtectedShell } from "@/components/auth/ProtectedShell";

const client: OAuthClient = { id: "public-client-uuid", application_id: "app", name: "Browser client", confidential: false, revoked: false, grant_types: ["authorization_code", "refresh_token"], redirect_uris: ["https://example.test/callback?complete=value", "custom://full/uri"], created_at: "2026-01-02T00:00:00Z", updated_at: "2026-02-03T00:00:00Z" };
const page = (data = [client], current_page = 1, last_page = 1) => ({ data, meta: { current_page, last_page, total: data.length, per_page: 25 } });
const secret = "mock-one-time-secret";
beforeEach(() => {
  vi.resetAllMocks(); auth.user = { is_system_admin: true };
  service.oauthClients.mockResolvedValue(page()); service.oauthClient.mockResolvedValue(client);
  service.createOAuthClient.mockResolvedValue({ data: { ...client, confidential: true }, client_secret: secret });
  service.revokeOAuthClient.mockResolvedValue(undefined);
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText: vi.fn().mockResolvedValue(undefined) } });
});
afterEach(cleanup);
async function openForm(confidential = true) {
  fireEvent.click(await screen.findByRole("button", { name: "Register OAuth Client" }));
  fireEvent.change(screen.getByLabelText("Client name"), { target: { value: "New client" } });
  fireEvent.change(screen.getByLabelText("Redirect URIs"), { target: { value: "https://example.test/callback\ncustom://callback" } });
  fireEvent.change(screen.getByLabelText("Client type"), { target: { value: confidential ? "confidential" : "public" } });
}
const submit = () => fireEvent.click(screen.getByRole("button", { name: "Register client" }));

it("loads all safe client metadata, complete URIs and server grants without credentials", async () => {
  service.oauthClients.mockResolvedValue(page([{ ...client, secret: "hidden-hash", client_secret: secret } as OAuthClient]));
  render(<ApplicationOAuthClients applicationId="app" />);
  expect(screen.getByText("Loading OAuth clients…")).toBeInTheDocument();
  await screen.findByText(client.name);
  for (const value of [client.id, ...client.redirect_uris, "authorization_code, refresh_token", "Jan 2, 2026", "Feb 3, 2026", "Public", "active"]) expect(screen.getByText(value)).toBeInTheDocument();
  expect(screen.queryByText(secret)).not.toBeInTheDocument(); expect(screen.queryByText("hidden-hash")).not.toBeInTheDocument();
  expect(service.oauthClients).toHaveBeenCalledWith("app", 1, 25);
});
it("shows independent error, retries, and offers registration on an empty list", async () => {
  service.oauthClients.mockRejectedValueOnce(new Error("failed")).mockResolvedValue(page([]));
  render(<ApplicationOAuthClients applicationId="app" />);
  await screen.findByRole("alert"); expect(screen.queryByText("This application has no OAuth clients.")).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Retry" }));
  await screen.findByText("This application has no OAuth clients.");
  expect(screen.getByRole("button", { name: "Register OAuth Client" })).toBeEnabled();
});
it("paginates using existing controls", async () => {
  service.oauthClients.mockImplementation((_app, number) => Promise.resolve(page([client], number, 2)));
  render(<ApplicationOAuthClients applicationId="app" />); await screen.findByText(client.name);
  fireEvent.click(screen.getByRole("button", { name: "Next" })); await screen.findByText("Page 2 of 2");
  expect(service.oauthClients).toHaveBeenLastCalledWith("app", 2, 25);
});
it("creation resets pagination and a previous page response cannot overwrite the refreshed list", async () => {
  let resolve!: (value: unknown) => void;
  service.oauthClients.mockResolvedValueOnce(page([client], 1, 2)).mockImplementationOnce(() => new Promise((done) => { resolve = done; })).mockResolvedValue(page([{ ...client, name: "Refreshed list client" }], 1, 2));
  render(<ApplicationOAuthClients applicationId="app" />); await screen.findByText(client.name);
  fireEvent.click(screen.getByRole("button", { name: "Next" }));
  await waitFor(() => expect(service.oauthClients).toHaveBeenCalledTimes(2));
  await openForm(); submit(); await screen.findByText("Refreshed list client");
  expect(service.oauthClients).toHaveBeenLastCalledWith("app", 1, 25);
  await act(async () => resolve(page([{ ...client, name: "Stale previous page" }], 2, 2)));
  expect(screen.queryByText("Stale previous page")).not.toBeInTheDocument();
});
it("validates required registration fields before submitting", async () => {
  render(<ApplicationOAuthClients applicationId="app" />);
  fireEvent.click(screen.getByRole("button", { name: "Register OAuth Client" })); submit();
  expect(screen.getByText("Client name is required.")).toBeInTheDocument();
  expect(screen.getByText("At least one redirect URI is required.")).toBeInTheDocument();
  expect(service.createOAuthClient).not.toHaveBeenCalled();
});
it("submits only backend fields and displays authoritative creation metadata with a one-time secret", async () => {
  const storage = vi.spyOn(Storage.prototype, "setItem");
  service.createOAuthClient.mockResolvedValue({ data: { ...client, name: "Created on server", confidential: true }, client_secret: secret });
  render(<ApplicationOAuthClients applicationId="app" />); await openForm(); submit();
  await screen.findByText(secret);
  expect(service.createOAuthClient).toHaveBeenCalledWith("app", { name: "New client", redirect_uris: ["https://example.test/callback", "custom://callback"], confidential: true });
  expect(screen.getByText("Created on server")).toBeInTheDocument();
  await waitFor(() => expect(service.oauthClients).toHaveBeenCalledTimes(2));
  fireEvent.click(screen.getByRole("button", { name: "Copy secret" })); await screen.findByText("Copied.");
  expect(navigator.clipboard.writeText).toHaveBeenCalledWith(secret);
  fireEvent.click(screen.getByRole("button", { name: "Close" }));
  expect(screen.queryByText(secret)).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "Register OAuth Client" }));
  expect(screen.queryByText(secret)).not.toBeInTheDocument(); expect(storage).not.toHaveBeenCalled(); storage.mockRestore();
});
it("handles clipboard failure without credential-bearing error text", async () => {
  vi.mocked(navigator.clipboard.writeText).mockRejectedValue(new Error(secret));
  render(<ApplicationOAuthClients applicationId="app" />); await openForm(); submit(); await screen.findByText(secret);
  fireEvent.click(screen.getByRole("button", { name: "Copy secret" }));
  expect(await screen.findByText("Unable to copy. Select and copy the secret manually.")).toBeInTheDocument();
});
it("public creation displays no invented secret", async () => {
  service.createOAuthClient.mockResolvedValue({ data: client });
  render(<ApplicationOAuthClients applicationId="app" />); await openForm(false); submit();
  await screen.findByText("OAuth client registered successfully.");
  expect(screen.queryByText("Save Your Client Secret")).not.toBeInTheDocument(); expect(screen.queryByRole("button", { name: "Copy secret" })).not.toBeInTheDocument();
});
it("preserves inputs and renders all indexed server validation errors", async () => {
  const error = new AxiosError("invalid"); error.response = { status: 422, data: { errors: { name: ["Name invalid", "Name unavailable"], "redirect_uris.0": ["URI invalid", "Fragment forbidden"], confidential: ["Type rejected"] } } } as AxiosError["response"];
  service.createOAuthClient.mockRejectedValue(error);
  render(<ApplicationOAuthClients applicationId="app" />); await openForm(); submit(); await screen.findByText("Type rejected");
  expect(screen.getByLabelText("Client name")).toHaveValue("New client");
  expect(screen.getByLabelText("Client name")).toHaveAccessibleDescription("Name invalid Name unavailable");
  expect(screen.getByText("URI invalid")).toBeInTheDocument(); expect(screen.getByText("Fragment forbidden")).toBeInTheDocument();
});
it("prevents duplicate creation and discards late creation after application switching", async () => {
  let resolve!: (value: unknown) => void;
  service.createOAuthClient.mockImplementation(() => new Promise((done) => { resolve = done; }));
  const view = render(<ApplicationOAuthClients applicationId="app" />); await openForm(); submit();
  expect(screen.getByLabelText("Client name")).toBeDisabled();
  fireEvent.submit(screen.getByLabelText("Client name").closest("form")!); expect(service.createOAuthClient).toHaveBeenCalledTimes(1);
  view.rerender(<ApplicationOAuthClients applicationId="other" />);
  await act(async () => resolve({ data: client, client_secret: secret }));
  expect(screen.queryByRole("dialog")).not.toBeInTheDocument(); expect(screen.queryByText(secret)).not.toBeInTheDocument();
});
it("loads safe detail metadata while ignoring extra credential fields", async () => {
  service.oauthClient.mockResolvedValue({ ...client, secret: "secret-hash", client_secret: secret, access_token: "token-material" });
  render(<ApplicationOAuthClients applicationId="app" />);
  fireEvent.click(await screen.findByRole("button", { name: "View details" }));
  await waitFor(() => expect(within(screen.getByRole("dialog")).getByText(client.id)).toBeInTheDocument());
  expect(service.oauthClient).toHaveBeenCalledWith("app", client.id);
  for (const value of [secret, "secret-hash", "token-material"]) expect(screen.queryByText(value)).not.toBeInTheDocument();
});
it("retries detail errors", async () => {
  service.oauthClient.mockRejectedValueOnce(new Error("failed")).mockResolvedValue(client);
  render(<ApplicationOAuthClients applicationId="app" />); fireEvent.click(await screen.findByRole("button", { name: "View details" }));
  await screen.findByRole("alert"); fireEvent.click(screen.getByRole("button", { name: "Retry" }));
  await waitFor(() => expect(within(screen.getByRole("dialog")).getByText(client.id)).toBeInTheDocument());
});
it("confirms the correct revoke, blocks duplicates and refreshes authoritative revoked state", async () => {
  let resolve!: () => void; service.revokeOAuthClient.mockImplementation(() => new Promise<void>((done) => { resolve = done; }));
  render(<ApplicationOAuthClients applicationId="app" />); fireEvent.click(await screen.findByRole("button", { name: "Revoke" }));
  expect(service.revokeOAuthClient).not.toHaveBeenCalled(); expect(screen.getByRole("dialog")).toHaveTextContent("There is no restore action.");
  fireEvent.click(screen.getByRole("button", { name: "Revoke client" }));
  expect(within(screen.getByRole("dialog")).getByRole("button", { name: "Please wait…" })).toBeDisabled();
  expect(service.revokeOAuthClient).toHaveBeenCalledWith("app", client.id);
  service.oauthClients.mockResolvedValue(page([{ ...client, revoked: true }])); await act(async () => resolve());
  await screen.findByText("revoked"); expect(screen.queryByRole("button", { name: "Revoke" })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole("button", { name: "View details" })); expect(service.oauthClient).toHaveBeenCalled();
});
it("failed revoke preserves the client and allows retry", async () => {
  service.revokeOAuthClient.mockRejectedValueOnce(new Error("failed"));
  render(<ApplicationOAuthClients applicationId="app" />); fireEvent.click(await screen.findByRole("button", { name: "Revoke" }));
  fireEvent.click(screen.getByRole("button", { name: "Revoke client" })); await screen.findByRole("alert");
  expect(screen.getByText("active")).toBeInTheDocument(); expect(screen.getByRole("button", { name: "Revoke client" })).toBeEnabled();
});
it("stale revoke cannot refresh or change another application's client", async () => {
  let resolve!: () => void; service.revokeOAuthClient.mockImplementation(() => new Promise<void>((done) => { resolve = done; }));
  const view = render(<ApplicationOAuthClients applicationId="app" />); fireEvent.click(await screen.findByRole("button", { name: "Revoke" })); fireEvent.click(screen.getByRole("button", { name: "Revoke client" }));
  service.oauthClients.mockResolvedValue(page([{ ...client, id: "other-client", name: "Other client" }])); view.rerender(<ApplicationOAuthClients applicationId="other" />);
  await screen.findByText("Other client"); await act(async () => resolve());
  expect(service.oauthClients).toHaveBeenCalledTimes(2); expect(screen.queryByText(`${client.name} revoked.`)).not.toBeInTheDocument(); expect(screen.getByText("active")).toBeInTheDocument();
});
it("application switching discards stale list responses", async () => {
  let resolve!: (value: unknown) => void; service.oauthClients.mockImplementationOnce(() => new Promise((done) => { resolve = done; })).mockResolvedValue(page([{ ...client, name: "Other application client" }]));
  const view = render(<ApplicationOAuthClients applicationId="app" />); view.rerender(<ApplicationOAuthClients applicationId="other" />);
  await screen.findByText("Other application client"); await act(async () => resolve(page()));
  expect(screen.queryByText(client.name)).not.toBeInTheDocument();
});
it("a dismissed detail request cannot overwrite a newly selected client's details", async () => {
  let resolve!: (value: unknown) => void;
  service.oauthClients.mockResolvedValue(page([client, { ...client, id: "second", name: "Second client" }]));
  service.oauthClient.mockImplementationOnce(() => new Promise((done) => { resolve = done; })).mockResolvedValue({ ...client, id: "second", name: "Second client" });
  render(<ApplicationOAuthClients applicationId="app" />); await screen.findByText(client.name);
  fireEvent.click(screen.getAllByRole("button", { name: "View details" })[0]);
  fireEvent.click(screen.getByRole("button", { name: "Close" }));
  fireEvent.click(screen.getAllByRole("button", { name: "View details" })[1]);
  await waitFor(() => expect(within(screen.getByRole("dialog")).getByText("Second client")).toBeInTheDocument());
  await act(async () => resolve(client));
  expect(within(screen.getByRole("dialog")).queryByText(client.name)).not.toBeInTheDocument();
});
it("non-admin authorization prevents mounting OAuth management", () => {
  auth.user = { is_system_admin: false }; render(<ProtectedShell><ApplicationOAuthClients applicationId="app" /></ProtectedShell>);
  expect(screen.getByText("Administrator access required")).toBeInTheDocument(); expect(service.oauthClients).not.toHaveBeenCalled(); expect(screen.queryByRole("button", { name: "Register OAuth Client" })).not.toBeInTheDocument();
});
