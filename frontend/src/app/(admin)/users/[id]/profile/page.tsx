import { AdminProfilePage } from "@/components/profile/ProfileEditor";

export default async function UserProfilePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <AdminProfilePage userId={id} />;
}
