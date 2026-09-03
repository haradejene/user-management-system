export function EmptyState({
  message = "No records found.",
}: {
  message?: string;
}) {
  return <p className="rounded-lg border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-500">{message}</p>;
}
