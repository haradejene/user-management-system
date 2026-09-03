import { CompanyForm } from "@/components/admin/CompaniesAdmin";
export default async function EditCompanyPage({ params }: PageProps<"/companies/[id]/edit">) { const { id } = await params; return <CompanyForm id={id} />; }
