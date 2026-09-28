"use client";

export function Pagination({ currentPage, lastPage, onPageChange }: { currentPage: number; lastPage: number; onPageChange: (page: number) => void }) {
  if (lastPage <= 1) return null;

  return <nav aria-label="Pagination" className="mt-4 flex items-center justify-between text-sm">
    <button type="button" className="rounded border px-3 py-2 disabled:opacity-40" disabled={currentPage <= 1} onClick={() => onPageChange(currentPage - 1)}>Previous</button>
    <span>Page {currentPage} of {lastPage}</span>
    <button type="button" className="rounded border px-3 py-2 disabled:opacity-40" disabled={currentPage >= lastPage} onClick={() => onPageChange(currentPage + 1)}>Next</button>
  </nav>;
}
