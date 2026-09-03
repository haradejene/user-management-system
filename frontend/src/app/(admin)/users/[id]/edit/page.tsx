import { UserForm } from "@/components/admin/UserForm";
export default async function EditUserPage({ params }: PageProps<"/users/[id]/edit">) { const { id } = await params; return <UserForm id={id} />; }
