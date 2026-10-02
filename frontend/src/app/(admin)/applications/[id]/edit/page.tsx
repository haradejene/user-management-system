import { ApplicationDetails } from "@/components/admin/ApplicationsAdmin";
export default async function EditApplicationPage({ params }: PageProps<"/applications/[id]/edit">) { const { id } = await params; return <ApplicationDetails id={id} tab="settings" />; }
