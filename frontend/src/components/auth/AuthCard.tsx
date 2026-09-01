import Link from "next/link";

export function AuthCard({
  children,
  description,
  linkHref,
  linkLabel,
  title,
}: Readonly<{
  children: React.ReactNode;
  description: string;
  linkHref: string;
  linkLabel: string;
  title: string;
}>) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12">
      <section className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <div className="mb-6">
          <p className="mb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">
            Central IAM
          </p>
          <h1 className="text-2xl font-semibold text-slate-900">{title}</h1>
          <p className="mt-2 text-sm text-slate-600">{description}</p>
        </div>
        {children}
        <p className="mt-6 text-center text-sm text-slate-600">
          <Link
            href={linkHref}
            className="font-medium text-slate-900 underline-offset-4 hover:underline"
          >
            {linkLabel}
          </Link>
        </p>
      </section>
    </main>
  );
}
