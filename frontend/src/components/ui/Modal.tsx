import type { ReactNode } from "react";

export function Modal({
  open,
  children,
  title,
  onClose,
}: {
  open: boolean;
  children: ReactNode;
  title: string;
  onClose: () => void;
}) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4" onMouseDown={onClose}>
      <div role="dialog" aria-modal="true" aria-labelledby="modal-title" className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" onMouseDown={(event) => event.stopPropagation()}>
        <h2 id="modal-title" className="text-lg font-semibold text-slate-900">{title}</h2>
        <div className="mt-4">{children}</div>
      </div>
    </div>
  );
}
