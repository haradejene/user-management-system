import { Button } from "@/components/ui/Button";
import { Modal } from "@/components/ui/Modal";

export function ConfirmDialog({ open, title, message, confirmLabel, busy, onCancel, onConfirm }: { open: boolean; title: string; message: string; confirmLabel: string; busy?: boolean; onCancel: () => void; onConfirm: () => void }) {
  return <Modal open={open} title={title} onClose={onCancel}><p className="text-sm text-slate-600">{message}</p><div className="mt-6 flex justify-end gap-3"><Button variant="secondary" onClick={onCancel}>Cancel</Button><Button variant="danger" isLoading={busy} onClick={onConfirm}>{confirmLabel}</Button></div></Modal>;
}
