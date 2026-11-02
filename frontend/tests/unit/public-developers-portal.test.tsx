import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import { PublicDeveloperPortal } from "@/components/developers/PublicDeveloperPortal";
import { developersService } from "@/services/developers.service";

vi.mock("@/services/developers.service", () => ({
  developersService: {
    getSdks: vi.fn(),
    getCompanies: vi.fn(),
    getDiagnostics: vi.fn(),
    getDownloadUrl: vi.fn((fw: string) => `http://localhost:8000/api/developers/sdks/${fw}/download`),
    submitOAuthRequest: vi.fn(),
    getOAuthRequestStatus: vi.fn(),
  },
}));

const mockSdks = [
  {
    framework: "laravel" as const,
    name: "Doxa Laravel SDK",
    language: "PHP",
    runtime: "PHP 8.2+",
    package_name: "doxa/oauth-client",
    supported_versions: "Laravel 10.x, 11.x, 12.x",
    version: "1.0.0",
    install_command: "composer require doxa/oauth-client",
    download_url: "http://localhost:8000/api/developers/sdks/laravel/download",
    contract_version: "v1.3",
    description: "Official Laravel SDK",
    features: ["S256 PKCE", "Fail-closed access check"],
    env_template: { DOXA_CLIENT_ID: "sample" },
  },
  {
    framework: "node" as const,
    name: "Doxa Node.js SDK",
    language: "TypeScript",
    runtime: "Node.js 18+",
    package_name: "@doxa/auth-node",
    supported_versions: "Express, Next.js",
    version: "1.0.0",
    install_command: "npm install @doxa/auth-node",
    download_url: "http://localhost:8000/api/developers/sdks/node/download",
    contract_version: "v1.3",
    description: "Official Node.js SDK",
    features: ["Zero dependencies", "TypeScript"],
    env_template: { DOXA_CLIENT_ID: "sample" },
  },
];

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(developersService.getSdks).mockResolvedValue(mockSdks);
  vi.mocked(developersService.getCompanies).mockResolvedValue([
    { id: "comp-1111", name: "Acme Corp" },
    { id: "comp-2222", name: "Global Industries" },
  ]);
  vi.mocked(developersService.getDiagnostics).mockResolvedValue({
    issuer: "https://central-iam.test",
    status: "healthy",
    contract_version: "v1.3",
    endpoints: {
      discovery: "https://central-iam.test/.well-known/openid-configuration",
      jwks: "https://central-iam.test/oauth/jwks",
      authorization: "https://central-iam.test/oauth/authorize",
      token: "https://central-iam.test/oauth/token",
      userinfo: "https://central-iam.test/oauth/userinfo",
      application_access: "https://central-iam.test/oauth/application-access",
    },
    requirements: {},
    applications: [],
  });
  Object.defineProperty(navigator, "clipboard", {
    configurable: true,
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
  });
});

afterEach(cleanup);

it("renders the public developer portal with SDKs and download links without any admin login button", async () => {
  render(<PublicDeveloperPortal />);

  await screen.findByRole("heading", { name: /Official Central IAM Client SDKs/i });
  expect(screen.queryByRole("link", { name: /Sign In|Admin Dashboard/i })).not.toBeInTheDocument();
  expect(screen.getByText("composer require doxa/oauth-client")).toBeInTheDocument();
  expect(screen.getByRole("link", { name: /Download Doxa Laravel SDK/i })).toHaveAttribute(
    "href",
    "http://localhost:8000/api/developers/sdks/laravel/download"
  );
});

it("switches frameworks and displays correct install commands and specifications", async () => {
  render(<PublicDeveloperPortal />);

  await screen.findByRole("heading", { name: /Official Central IAM Client SDKs/i });
  fireEvent.click(screen.getByRole("button", { name: /Node\.js/i }));

  expect(screen.getByText("npm install @doxa/auth-node")).toBeInTheDocument();
  expect(screen.getByRole("link", { name: /Download Doxa Node\.js SDK/i })).toHaveAttribute(
    "href",
    "http://localhost:8000/api/developers/sdks/node/download"
  );
});

it("submits a new OAuth application request selecting a registered company from user management", async () => {
  vi.mocked(developersService.submitOAuthRequest).mockResolvedValue({
    message: "Submitted",
    data: {
      reference_code: "REQ-778899AA",
      name: "Partner Service",
      developer_name: "John Doe",
      developer_email: "john@partner.test",
      company_id: "comp-1111",
      organization: "Acme Corp",
      redirect_uris: ["https://partner.test/callback"],
      confidential: true,
      status: "pending",
      created_at: "2026-11-03T00:00:00Z",
    },
  });

  render(<PublicDeveloperPortal />);

  await screen.findByRole("heading", { name: /Official Central IAM Client SDKs/i });
  fireEvent.click(screen.getByRole("button", { name: "Request OAuth Application" }));

  expect(screen.getByRole("heading", { name: "Request Central IAM OAuth Application" })).toBeInTheDocument();

  // Companies dropdown populated
  await screen.findByRole("option", { name: "Acme Corp" });
  expect(screen.getByRole("option", { name: "Other / Not Listed" })).toBeInTheDocument();

  fireEvent.change(screen.getByPlaceholderText(/Acme Employee Portal/i), {
    target: { value: "Partner Service" },
  });
  fireEvent.change(screen.getByLabelText(/Company \/ Organization/i), {
    target: { value: "comp-1111" },
  });
  fireEvent.change(screen.getByPlaceholderText(/Alex Mercer/i), {
    target: { value: "John Doe" },
  });
  fireEvent.change(screen.getByPlaceholderText(/alex@example\.com/i), {
    target: { value: "john@partner.test" },
  });
  fireEvent.change(screen.getByPlaceholderText(/https:\/\/your-app\.test\/auth\/doxa\/callback/i), {
    target: { value: "https://partner.test/callback" },
  });

  fireEvent.click(screen.getByRole("button", { name: "Submit Application Request" }));

  await screen.findByText("Application Request Submitted Successfully!");
  expect(screen.getByText("REQ-778899AA")).toBeInTheDocument();
  expect(developersService.submitOAuthRequest).toHaveBeenCalledWith(
    expect.objectContaining({
      name: "Partner Service",
      company_id: "comp-1111",
      organization: "Acme Corp",
      developer_name: "John Doe",
      developer_email: "john@partner.test",
      redirect_uris: ["https://partner.test/callback"],
      confidential: true,
    })
  );
});

it("submits request with 'other' option when developer company is not registered", async () => {
  vi.mocked(developersService.submitOAuthRequest).mockResolvedValue({
    message: "Submitted",
    data: {
      reference_code: "REQ-OTHER99",
      name: "Independent App",
      developer_name: "Jane Solo",
      developer_email: "jane@independent.test",
      organization: "Freelance Studio",
      redirect_uris: ["https://freelance.test/callback"],
      confidential: true,
      status: "pending",
      created_at: "2026-11-03T00:00:00Z",
    },
  });

  render(<PublicDeveloperPortal />);

  await screen.findByRole("heading", { name: /Official Central IAM Client SDKs/i });
  fireEvent.click(screen.getByRole("button", { name: "Request OAuth Application" }));

  await screen.findByRole("option", { name: "Other / Not Listed" });

  fireEvent.change(screen.getByPlaceholderText(/Acme Employee Portal/i), {
    target: { value: "Independent App" },
  });
  fireEvent.change(screen.getByLabelText(/Company \/ Organization/i), {
    target: { value: "other" },
  });

  // Custom company input appears
  const customInput = await screen.findByPlaceholderText(/Enter organization or company name/i);
  fireEvent.change(customInput, { target: { value: "Freelance Studio" } });

  fireEvent.change(screen.getByPlaceholderText(/Alex Mercer/i), {
    target: { value: "Jane Solo" },
  });
  fireEvent.change(screen.getByPlaceholderText(/alex@example\.com/i), {
    target: { value: "jane@independent.test" },
  });
  fireEvent.change(screen.getByPlaceholderText(/https:\/\/your-app\.test\/auth\/doxa\/callback/i), {
    target: { value: "https://freelance.test/callback" },
  });

  fireEvent.click(screen.getByRole("button", { name: "Submit Application Request" }));

  await screen.findByText("Application Request Submitted Successfully!");
  expect(developersService.submitOAuthRequest).toHaveBeenCalledWith(
    expect.objectContaining({
      name: "Independent App",
      company_id: undefined,
      organization: "Freelance Studio",
      developer_name: "Jane Solo",
    })
  );
});

it("looks up request status by reference code and displays credentials if approved", async () => {
  vi.mocked(developersService.getOAuthRequestStatus).mockResolvedValue({
    reference_code: "REQ-APPROVED12",
    name: "Billing App",
    developer_name: "Jane Smith",
    developer_email: "jane@billing.test",
    redirect_uris: ["https://billing.test/callback"],
    confidential: true,
    status: "approved",
    created_at: "2026-11-03T00:00:00Z",
    client_id: "00000000-0000-0000-0000-000000000001",
    client_secret: "super-secret-client-token",
  });

  render(<PublicDeveloperPortal />);

  await screen.findByRole("heading", { name: /Official Central IAM Client SDKs/i });
  fireEvent.click(screen.getByRole("button", { name: "Track Request Status" }));

  const input = screen.getByPlaceholderText(/REQ-A1B2C3D4/i);
  fireEvent.change(input, { target: { value: "REQ-APPROVED12" } });
  fireEvent.click(screen.getByRole("button", { name: "Check Status" }));

  await screen.findByText("Approved & Ready");
  expect(screen.getByText("00000000-0000-0000-0000-000000000001")).toBeInTheDocument();

  // Test apply credentials to .env
  fireEvent.click(screen.getByRole("button", { name: /Configure in SDK \.env Generator/i }));

  // Should navigate back to SDKs tab and have custom credentials populated
  expect(screen.getByText(/Configured for: Billing App/)).toBeInTheDocument();
  expect(screen.getByText(/00000000-0000-0000-0000-000000000001/)).toBeInTheDocument();
});
