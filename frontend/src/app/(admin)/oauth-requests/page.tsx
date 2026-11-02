import { OAuthRequestsAdmin } from "@/components/admin/oauth-requests/OAuthRequestsAdmin";

export const metadata = {
  title: "OAuth Application Requests | Central IAM",
  description: "Review and approve inbound OAuth application requests submitted by developers.",
};

export default function OAuthRequestsPage() {
  return <OAuthRequestsAdmin />;
}

