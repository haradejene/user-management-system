import { ApplicationDetails } from "@/components/admin/ApplicationsAdmin";
export default async function ApplicationDetailsPage({ params }: PageProps<"/applications/[id]">) { const { id } = await params; return <ApplicationDetails id={id} />; }
