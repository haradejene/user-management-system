import { ApplicationDetails } from "@/components/admin/applications/ApplicationDetails";

export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <ApplicationDetails id={id} tab="oauth-clients" />;
}
