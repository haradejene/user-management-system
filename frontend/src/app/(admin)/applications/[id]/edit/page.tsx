import { ApplicationForm } from "@/components/admin/ApplicationsAdmin";
export default async function EditApplicationPage({ params }: PageProps<"/applications/[id]/edit">) { const { id } = await params; return <ApplicationForm id={id} />; }
