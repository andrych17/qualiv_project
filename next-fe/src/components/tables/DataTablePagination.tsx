import React from "react";
import Link from "next/link";

export interface PaginationLink {
  url: string | null;
  label: string;
  active: boolean;
}

interface DataTablePaginationProps {
  links: PaginationLink[];
  onPageChange?: (pageUrl: string) => void;
}

export default function DataTablePagination({
  links,
  onPageChange,
}: DataTablePaginationProps) {
  if (!links || links.length <= 3) return null;

  // Render HTML labels safely (like &laquo; Previous)
  const renderLabel = (label: string) => {
    if (label.includes("Previous")) {
      return "Previous";
    }
    if (label.includes("Next")) {
      return "Next";
    }
    return label;
  };

  const handlePageClick = (e: React.MouseEvent, url: string | null) => {
    if (!url) {
      e.preventDefault();
      return;
    }
    if (onPageChange) {
      e.preventDefault();
      onPageChange(url);
    }
  };

  return (
    <div className="flex items-center justify-between border-t border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 px-4 py-3 sm:px-6 rounded-xl shadow-sm">
      {/* Mobile view buttons */}
      <div className="flex flex-1 justify-between sm:hidden">
        <a
          href={links[0].url || "#"}
          onClick={(e) => handlePageClick(e, links[0].url)}
          className={`relative inline-flex items-center rounded-lg border border-slate-350 dark:border-slate-800 bg-white dark:bg-slate-900 px-4 py-2 text-xs font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors ${
            !links[0].url ? "pointer-events-none opacity-50" : ""
          }`}
        >
          Previous
        </a>
        <a
          href={links[links.length - 1].url || "#"}
          onClick={(e) => handlePageClick(e, links[links.length - 1].url)}
          className={`relative ml-3 inline-flex items-center rounded-lg border border-slate-350 dark:border-slate-800 bg-white dark:bg-slate-900 px-4 py-2 text-xs font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors ${
            !links[links.length - 1].url ? "pointer-events-none opacity-50" : ""
          }`}
        >
          Next
        </a>
      </div>

      {/* Desktop view */}
      <div className="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
        <div>
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Showing paging navigation
          </p>
        </div>
        <div>
          <nav
            className="isolate inline-flex -space-x-px rounded-lg shadow-sm bg-slate-50 dark:bg-slate-950 p-1 border border-slate-200/60 dark:border-slate-800"
            aria-label="Pagination"
          >
            {links.map((link, idx) => {
              const cleanedLabel = renderLabel(link.label);
              return (
                <a
                  key={idx}
                  href={link.url || "#"}
                  onClick={(e) => handlePageClick(e, link.url)}
                  className={`relative inline-flex items-center px-3 py-1.5 text-xs font-bold rounded-md transition-all duration-200 ${
                    link.active
                      ? "z-10 bg-indigo-600 text-white shadow-sm shadow-indigo-600/25"
                      : "text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800"
                  } ${!link.url ? "pointer-events-none opacity-40" : ""}`}
                >
                  {cleanedLabel}
                </a>
              );
            })}
          </nav>
        </div>
      </div>
    </div>
  );
}
