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
    <main className="flex min-h-screen items-center justify-center bg-[#f4f7fa] px-4 py-8 sm:py-16">
      <section aria-labelledby="auth-title" className="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-6 shadow-[0_12px_40px_#14253808] sm:p-10">
        <div className="mb-8 flex items-center gap-3">
          <span aria-hidden="true" className="grid size-10 place-items-center rounded-xl bg-slate-900 text-2xl font-bold text-white">d</span>
          <span className="text-2xl font-bold tracking-tight text-slate-900">doxa</span>
          <span className="ml-auto text-xs font-semibold uppercase tracking-widest text-teal-800">One identity</span>
        </div>
        <div className="mb-7">
          <h1 id="auth-title" className="text-3xl font-semibold tracking-tight text-slate-900">{title}</h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">{description}</p>
        </div>
        {children}
        <p className="mt-6 text-center text-sm text-slate-600">
          <Link
            href={linkHref}
            className="rounded font-medium text-slate-900 underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700"
          >
            {linkLabel}
          </Link>
        </p>
        <p className="mt-7 border-t border-slate-100 pt-5 text-center text-xs leading-5 text-slate-500">Doxa Identity · Secure access to your workplace</p>
      </section>
    </main>
  );
}
