import { CompanyDetails } from "@/components/admin/CompaniesAdmin";
export default async function CompanyDetailsPage({ params }: PageProps<"/companies/[id]">) { const { id } = await params; return <CompanyDetails id={id} />; }
