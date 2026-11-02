import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { OAuthRequestsAdmin } from "@/components/admin/oauth-requests/OAuthRequestsAdmin";
import { oauthRequestsService } from "@/services/oauth-requests.service";

vi.mock("@/services/oauth-requests.service", () => ({
  oauthRequestsService: {
    list: vi.fn(),
    stats: vi.fn(),
    get: vi.fn(),
    approve: vi.fn(),
    reject: vi.fn(),
  },
}));

const mockRequests = [
  {
    id: "req-1",
    public_id: "11111111-1111-1111-1111-111111111111",
    reference_code: "REQ-PENDING1",
    name: "Customer Onboarding",
    developer_name: "Alice Brown",
    developer_email: "alice@onboard.test",
    organization: "Onboarding Co",
    description: "App for new client sign-ins",
    redirect_uris: ["https://onboard.test/auth/callback"],
    confidential: true,
    status: "pending" as const,
    created_at: "2026-11-03T00:00:00Z",
  },
  {
    id: "req-2",
    public_id: "22222222-2222-2222-2222-222222222222",
    reference_code: "REQ-APPROVED2",
    name: "HR Platform",
    developer_name: "Bob White",
    developer_email: "bob@hr.test",
    organization: "HR Dept",
    description: "Internal HR management",
    redirect_uris: ["https://hr.test/callback"],
    confidential: true,
    status: "approved" as const,
    created_at: "2026-11-02T00:00:00Z",
    application: {
      id: "app-2",
      name: "HR Platform",
      slug: "hr-platform",
    },
    oauth_client_id: "client-uuid-hr",
  },
];

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(oauthRequestsService.list).mockResolvedValue({
    data: mockRequests,
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 2,
  });
  vi.mocked(oauthRequestsService.stats).mockResolvedValue({
    pending: 1,
    approved: 1,
    rejected: 0,
    total: 2,
  });
});

afterEach(cleanup);

it("lists pending and approved requests with status badges", async () => {
  render(<OAuthRequestsAdmin />);

  await screen.findByRole("heading", { name: "OAuth Application Requests" });
  expect(screen.getByText("REQ-PENDING1")).toBeInTheDocument();
  expect(screen.getByText("Customer Onboarding")).toBeInTheDocument();
  expect(screen.getByText("alice@onboard.test")).toBeInTheDocument();
  expect(screen.getByText("REQ-APPROVED2")).toBeInTheDocument();
  expect(screen.getByText("HR Platform")).toBeInTheDocument();
});

it("opens review modal and allows approving a pending request", async () => {
  vi.mocked(oauthRequestsService.approve).mockResolvedValue({
    message: "Approved successfully",
    data: {
      ...mockRequests[0],
      status: "approved",
      application: { id: "app-new", name: "Customer Onboarding", slug: "customer-onboarding" },
      oauth_client_id: "new-client-id-uuid",
      client_secret: "generated-one-time-secret",
    },
    client_secret: "generated-one-time-secret",
  });

  render(<OAuthRequestsAdmin />);

  await screen.findByText("Customer Onboarding");

  // Click review button on first row
  const reviewButtons = screen.getAllByRole("button", { name: /Review & Decide|View Details/ });
  fireEvent.click(reviewButtons[0]);

  // Modal opens
  await screen.findByRole("heading", { name: "Review Request: Customer Onboarding" });
  expect(screen.getAllByText("Onboarding Co").length).toBeGreaterThanOrEqual(1);
  expect(screen.getByText("https://onboard.test/auth/callback")).toBeInTheDocument();

  // Click approve
  const approveBtn = screen.getByRole("button", { name: /Approve Application & Issue Client/i });
  fireEvent.click(approveBtn);

  await screen.findByText("Application Created & Credentials Generated!");
  expect(screen.getByText("new-client-id-uuid")).toBeInTheDocument();
  expect(screen.getByText("generated-one-time-secret")).toBeInTheDocument();
  expect(oauthRequestsService.approve).toHaveBeenCalledWith(
    "11111111-1111-1111-1111-111111111111",
    undefined
  );
});

it("allows rejecting a pending request with custom notes", async () => {
  vi.mocked(oauthRequestsService.reject).mockResolvedValue({
    message: "Rejected",
    data: {
      ...mockRequests[0],
      status: "rejected",
      admin_notes: "Please submit with a corporate email domain.",
    },
  });

  render(<OAuthRequestsAdmin />);

  await screen.findByText("Customer Onboarding");

  const reviewButtons = screen.getAllByRole("button", { name: /Review & Decide|View Details/ });
  fireEvent.click(reviewButtons[0]);

  await screen.findByRole("heading", { name: "Review Request: Customer Onboarding" });

  // Click Reject Request to enter reject mode
  fireEvent.click(screen.getByRole("button", { name: /Reject Request…/i }));

  // Enter reason
  const textarea = screen.getByPlaceholderText(/Please use official production domain/i);
  fireEvent.change(textarea, {
    target: { value: "Please submit with a corporate email domain." },
  });

  // Confirm rejection
  fireEvent.click(screen.getByRole("button", { name: "Confirm Rejection" }));

  await screen.findByText('Request for "Customer Onboarding" rejected.');
  expect(oauthRequestsService.reject).toHaveBeenCalledWith(
    "11111111-1111-1111-1111-111111111111",
    "Please submit with a corporate email domain."
  );
});

