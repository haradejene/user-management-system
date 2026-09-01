export function EmptyState({
  message = "No records found.",
}: {
  message?: string;
}) {
  return <p>{message}</p>;
}
