export default function AdminLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return <ProtectedShell>{children}</ProtectedShell>;
}
import { ProtectedShell } from "@/components/auth/ProtectedShell";
