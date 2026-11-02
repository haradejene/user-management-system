import { PublicDeveloperPortal } from "@/components/developers/PublicDeveloperPortal";

export const metadata = {
  title: "Central IAM Developer Portal & Client SDKs",
  description:
    "Official client SDKs for Laravel, Node.js, and Django. Download packages and request OAuth application client credentials.",
};

export default function DevelopersPage() {
  return <PublicDeveloperPortal />;
}

