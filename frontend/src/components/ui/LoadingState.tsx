export function LoadingState({
  label = "Loading…",
  fullPage = false,
}: {
  label?: string;
  fullPage?: boolean;
}) {
  return (
    <div
      role="status"
      className={`flex items-center justify-center gap-3 text-sm text-slate-600 ${fullPage ? "min-h-screen" : "py-8"}`}
    >
      <span className="size-4 animate-spin rounded-full border-2 border-slate-300 border-t-slate-800" />
      {label}
    </div>
  );
}
