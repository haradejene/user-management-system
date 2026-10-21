import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  login: vi.fn(), replace: vi.fn(), refresh: vi.fn(),
  auth: { user: null as null | { name: string; email: string; status: string }, isLoading: false, error: null as null | string },
}));
vi.mock("next/navigation", () => ({useRouter: () => ({replace: mocks.replace, refresh: mocks.refresh}), useSearchParams: () => new URLSearchParams()}));
vi.mock("@/hooks/useAuth", () => ({useAuth: () => ({...mocks.auth, login: mocks.login})}));

import { LoginForm } from "@/components/auth/LoginForm";
import {rememberAccount} from "@/services/remembered-accounts";

afterEach(() => {cleanup(); localStorage.clear();});
beforeEach(() => {
  vi.clearAllMocks();
  localStorage.clear();
  mocks.auth = {user: null, isLoading: false, error: null};
  mocks.login.mockResolvedValue({});
});

describe("Doxa sign in", () => {
  it("treats an unverified remembered identity as an email hint and requires its password", () => {
    rememberAccount({name:"Remembered user",email:"remembered@example.test"});
    render(<LoginForm />);
    fireEvent.click(screen.getByRole("button",{name:/Remembered user/}));
    expect(screen.getByLabelText("Email")).toHaveValue("remembered@example.test");
    expect(screen.getByLabelText("Password")).toHaveValue("");
    expect(mocks.login).not.toHaveBeenCalled();
    expect(mocks.replace).not.toHaveBeenCalled();
  });

  it("removes a saved hint without signing in or hiding the current authenticated account", () => {
    rememberAccount({name:"Remembered user",email:"remembered@example.test"});
    render(<LoginForm />);
    fireEvent.click(screen.getByRole("button",{name:"Remove remembered account remembered@example.test"}));
    expect(screen.queryByText("remembered@example.test")).not.toBeInTheDocument();
    expect(mocks.login).not.toHaveBeenCalled();
  });

  it("does not continue a different remembered identity using the current session", () => {
    mocks.auth.user={name:"Current user",email:"current@example.test",status:"active"};
    rememberAccount({name:"Another user",email:"another@example.test"});
    render(<LoginForm />);
    fireEvent.click(screen.getByRole("button",{name:/Another user/}));
    expect(screen.getByLabelText("Password")).toHaveValue("");
    expect(mocks.replace).not.toHaveBeenCalled();
  });
  it("requires credentials without a session and associates validation with fields", async () => {
    render(<LoginForm />);
    expect(screen.queryByText("Choose an account")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", {name: "Sign in"}));
    await screen.findByRole("alert");
    expect(screen.getByLabelText("Email")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByLabelText("Password")).toHaveAttribute("aria-invalid", "true");
    expect(mocks.login).not.toHaveBeenCalled();
  });

  it("shows only the authenticated account and continues without impersonation", () => {
    mocks.auth.user = {name: "Ada Example", email: "ada@example.test", status: "active"};
    render(<LoginForm />);
    expect(screen.getByRole("heading", {name: "Choose an account"})).toBeInTheDocument();
    expect(screen.getByText("ada@example.test")).toBeInTheDocument();
    expect(screen.queryByLabelText("Password")).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", {name: /Ada Example/}));
    expect(mocks.replace).toHaveBeenCalledWith("/dashboard");
    expect(mocks.login).not.toHaveBeenCalled();
  });

  it("requires fresh credentials for another identity and allows returning to the picker", () => {
    mocks.auth.user = {name: "Ada Example", email: "ada@example.test", status: "active"};
    render(<LoginForm />);
    fireEvent.click(screen.getByRole("button", {name: "Use another account"}));
    expect(screen.getByLabelText("Email")).toHaveValue("");
    expect(screen.getByLabelText("Password")).toHaveValue("");
    expect(mocks.login).not.toHaveBeenCalled();
    expect(mocks.replace).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", {name: "Back to account selection"}));
    expect(screen.getByText("ada@example.test")).toBeInTheDocument();
  });

  it("submits credentials before navigating and can reveal a password accessibly", async () => {
    render(<LoginForm />);
    fireEvent.change(screen.getByLabelText("Email"), {target: {value: "ada@example.test"}});
    fireEvent.change(screen.getByLabelText("Password"), {target: {value: "secure-password"}});
    fireEvent.click(screen.getByRole("button", {name: "Show password"}));
    expect(screen.getByLabelText("Password")).toHaveAttribute("type", "text");
    expect(screen.getByRole("button", {name: "Hide password"})).toHaveAttribute("aria-pressed", "true");
    fireEvent.click(screen.getByRole("button", {name: "Sign in"}));
    await waitFor(() => expect(mocks.replace).toHaveBeenCalledWith("/dashboard"));
    expect(mocks.login).toHaveBeenCalledWith({email: "ada@example.test", password: "secure-password", remember: false});
  });

  it("announces authentication failures, focuses the error, and stays on the form", async () => {
    mocks.login.mockRejectedValue(new Error("Rejected"));
    render(<LoginForm />);
    fireEvent.change(screen.getByLabelText("Email"), {target: {value: "ada@example.test"}});
    fireEvent.change(screen.getByLabelText("Password"), {target: {value: "wrong-password"}});
    fireEvent.click(screen.getByRole("button", {name: "Sign in"}));
    const alert = await screen.findByRole("alert");
    await waitFor(() => expect(alert.parentElement).toHaveFocus());
    expect(mocks.replace).not.toHaveBeenCalled();
  });

  it("waits for session verification and never offers an inactive account", () => {
    mocks.auth.isLoading = true;
    const view = render(<LoginForm />);
    expect(screen.getByRole("status")).toHaveTextContent("Checking your session");
    expect(screen.queryByLabelText("Email")).not.toBeInTheDocument();
    mocks.auth = {isLoading: false, error: null, user: {name: "Inactive", email: "inactive@example.test", status: "inactive"}};
    view.rerender(<LoginForm />);
    expect(screen.queryByText("Choose an account")).not.toBeInTheDocument();
    expect(screen.getByLabelText("Password")).toBeInTheDocument();
  });
});
