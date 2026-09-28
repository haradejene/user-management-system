import { AxiosError } from "axios";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { afterEach, expect, it, vi } from "vitest";

vi.mock("@/services/auth.service", () => ({
  authService: { me: vi.fn(async () => ({ name: "Signed-in administrator" })) },
}));

import { AuthProvider } from "@/components/auth/AuthProvider";
import { useAuth } from "@/hooks/useAuth";
import { apiClient } from "@/services/api-client";

function Probe({ status, message }: { status: number; message: string }) {
  const { user, error } = useAuth();
  const [finished, setFinished] = useState(false);
  return <>
    <p>{user?.name ?? "Signed out"}</p>
    <p>{error}</p>
    <button onClick={() => {
      void apiClient.get("/api/admin/users", {
        adapter: async (config) => {
          throw new AxiosError("Rejected", "ERR_BAD_REQUEST", config, null, {
            config, data: { message }, status, statusText: "Denied", headers: {},
          });
        },
      }).catch(() => {}).finally(() => setFinished(true));
    }}>Protected request</button>
    {finished && <p>Request finished</p>}
  </>;
}

afterEach(cleanup);

it.each([
  [401, "Your session has expired. Please log in again."],
  [403, "Your account is not active."],
])("clears authenticated state after lifecycle rejection %s", async (status, message) => {
  render(<AuthProvider><Probe status={status} message={message} /></AuthProvider>);
  await screen.findByText("Signed-in administrator");
  fireEvent.click(screen.getByRole("button", { name: "Protected request" }));
  await screen.findByText("Request finished");
  expect(screen.getByText("Signed out")).toBeInTheDocument();
  expect(screen.getByText(message)).toBeInTheDocument();
});

it("retains authentication for an ordinary permission denial", async () => {
  render(<AuthProvider><Probe status={403} message="This action is unauthorized." /></AuthProvider>);
  await screen.findByText("Signed-in administrator");
  fireEvent.click(screen.getByRole("button", { name: "Protected request" }));
  await screen.findByText("Request finished");
  expect(screen.getByText("Signed-in administrator")).toBeInTheDocument();
  expect(screen.queryByText("Signed out")).not.toBeInTheDocument();
});
