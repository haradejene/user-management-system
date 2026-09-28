import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";

const { profileService } = vi.hoisted(() => ({ profileService: { me: vi.fn(), updateMe: vi.fn(), forUser: vi.fn(), updateUser: vi.fn() } }));
vi.mock("@/services/profile.service", () => ({ profileService }));
import { ProfileEditor } from "@/components/profile/ProfileEditor";

const profile = { user_id: "user-1", first_name: "Ada", last_name: null, phone: null, photo: null, created_at: null, updated_at: null };

beforeEach(() => { vi.clearAllMocks(); profileService.me.mockResolvedValue(profile); profileService.updateMe.mockResolvedValue({ ...profile, first_name: "Grace" }); });
afterEach(cleanup);

it("renders the profile and submits an update", async () => {
  render(<ProfileEditor />);
  const input = await screen.findByLabelText("First name");
  expect(input).toHaveValue("Ada");
  fireEvent.change(input, { target: { value: "Grace" } });
  fireEvent.click(screen.getByRole("button", { name: "Save profile" }));
  await waitFor(() => expect(profileService.updateMe).toHaveBeenCalledWith(expect.objectContaining({ first_name: "Grace" })));
  expect(await screen.findByRole("status")).toHaveTextContent("Profile updated.");
});

it("shows a failed update without replacing the displayed profile", async () => {
  profileService.updateMe.mockRejectedValue(new Error("save failed"));
  render(<ProfileEditor />);
  const input = await screen.findByLabelText("First name");
  fireEvent.change(input, { target: { value: "Broken" } });
  fireEvent.click(screen.getByRole("button", { name: "Save profile" }));
  expect(await screen.findByRole("alert")).toBeInTheDocument();
  expect(input).toHaveValue("Broken");
});
