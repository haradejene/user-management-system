import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { AxiosError } from "axios";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
const { update } = vi.hoisted(() => ({ update: vi.fn() }));
vi.mock("@/services/applications.service", () => ({ applicationsService: { updateOAuthRedirects: update } }));
import { OAuthClientIntegration, OAuthRedirectEditor } from "@/components/admin/applications/OAuthClientIntegration";
import { getApiErrorMessage } from "@/services/api-client";
const client = { id: "client", application_id: "application", name: "Server", confidential: true, revoked: false, redirect_uris: ["https://client.test/callback?Case=Value%2F"], grant_types: ["authorization_code"], pkce_required: true, pkce_method: "S256", allowed_scopes: ["openid", "profile", "email", "iam:read"], issuer: "https://iam.test", discovery_url: "https://iam.test/.well-known/openid-configuration", created_at: null, updated_at: "2026-10-07T00:00:00Z" };
beforeEach(() => { vi.resetAllMocks(); Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText: vi.fn().mockResolvedValue(undefined) } }); });
afterEach(cleanup);
it("shows and copies non-secret SDK information, with mandatory S256 and explicit access navigation", async () => {
  render(<OAuthClientIntegration client={{ ...client, client_secret: "hidden-secret", access_token: "hidden-token" } as typeof client} />);
  expect(screen.getByText(/PKCE: Required \(S256\)/)).toBeInTheDocument();
  expect(screen.getByRole("link", { name: "Manage application access" })).toHaveAttribute("href", "/applications/application/user-access");
  fireEvent.click(screen.getByRole("button", { name: "Copy environment example" }));
  await screen.findByText("Copied.");
  const example = vi.mocked(navigator.clipboard.writeText).mock.calls[0][0];
  expect(example).toContain(client.issuer); expect(example).toContain(client.redirect_uris[0]);
  expect(example).not.toContain("hidden-secret"); expect(example).not.toContain("hidden-token");
});
it("adds/removes exact URIs, prevents duplicates, and sends the optimistic version", async () => {
  const changed = vi.fn(); update.mockResolvedValue(client);
  render(<OAuthRedirectEditor applicationId="application" client={client} onChanged={changed} />);
  fireEvent.click(screen.getByRole("button", { name: "Add URI" }));
  fireEvent.change(screen.getByLabelText("Redirect URI 2"), { target: { value: client.redirect_uris[0] } });
  fireEvent.click(screen.getByRole("button", { name: "Save redirect URIs" }));
  expect(screen.getByRole("alert")).toHaveTextContent("Duplicate"); expect(update).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText("Redirect URI 2"), { target: { value: " https://other.test/callback " } });
  fireEvent.click(screen.getByRole("button", { name: "Remove URI 1" }));
  fireEvent.click(screen.getByRole("button", { name: "Save redirect URIs" }));
  await waitFor(() => expect(update).toHaveBeenCalledWith("application", "client", [" https://other.test/callback "], client.updated_at));
  expect(changed).toHaveBeenCalledWith(client);
});
it("revoked clients cannot change redirect URIs", () => {
  render(<OAuthRedirectEditor applicationId="application" client={{ ...client, revoked: true }} onChanged={vi.fn()} />);
  expect(screen.getByRole("button", { name: "Save redirect URIs" })).toBeDisabled();
});
it("never shows exception-bearing server failures", () => {
  for (const status of [400, 401, 403, 404, 409, 429, 500]) {
    const error = new AxiosError("secret"); error.response = { status, data: { message: "SQLSTATE stack trace secret" } } as AxiosError["response"];
    expect(getApiErrorMessage(error)).not.toMatch(/SQLSTATE|stack trace|secret|token/);
  }
});
