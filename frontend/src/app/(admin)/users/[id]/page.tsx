import { UserDetails } from "@/components/admin/UserDetails";
export default async function UserDetailsPage({ params }: PageProps<"/users/[id]">) { const { id } = await params; return <UserDetails id={id} />; }
