import React from "react";

export interface Column {
  key: string;
  label: string;
  align?: "left" | "center" | "right";
  render?: (item: any, value: any) => React.ReactNode;
}

interface DataTableProps {
  columns: Column[];
  items: Record<string, any>[];
  loading?: boolean;
  emptyTitle?: string;
  emptyDescription?: string;
}

export default function DataTable({
  columns,
  items,
  loading = false,
  emptyTitle = "No data found",
  emptyDescription = "Try changing your search or filter.",
}: DataTableProps) {
  const alignClass = (align?: string) => {
    if (align === "center") return "text-center";
    if (align === "right") return "text-right";
    return "text-left";
  };

  return (
    <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
      <div className="overflow-x-auto scrollbar">
        <table className="min-w-full divide-y divide-slate-100 dark:divide-slate-800">
          <thead className="bg-slate-50 dark:bg-slate-950/40">
            <tr>
              {columns.map((column) => (
                <th
                  key={column.key}
                  className={`px-6 py-3.5 text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 ${alignClass(
                    column.align
                  )}`}
                >
                  {column.label}
                </th>
              ))}
            </tr>
          </thead>

          <tbody className="divide-y divide-slate-100 dark:divide-slate-800 bg-white dark:bg-slate-900">
            {loading ? (
              <tr>
                <td
                  colSpan={columns.length}
                  className="px-6 py-12 text-center text-sm text-slate-500 dark:text-slate-400"
                >
                  <div className="flex flex-col items-center justify-center gap-2">
                    <div className="h-6 w-6 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent"></div>
                    <span>Loading data...</span>
                  </div>
                </td>
              </tr>
            ) : items.length === 0 ? (
              <tr>
                <td colSpan={columns.length} className="px-6 py-16 text-center">
                  <div className="max-w-md mx-auto space-y-2">
                    <p className="text-sm font-semibold text-slate-900 dark:text-white">
                      {emptyTitle}
                    </p>
                    <p className="text-xs text-slate-450 dark:text-slate-400">
                      {emptyDescription}
                    </p>
                  </div>
                </td>
              </tr>
            ) : (
              items.map((item, index) => (
                <tr
                  key={item.id ?? index}
                  className="hover:bg-slate-50/50 dark:hover:bg-slate-850/30 transition-colors"
                >
                  {columns.map((column) => {
                    const value = item[column.key];
                    return (
                      <td
                        key={column.key}
                        className={`whitespace-nowrap px-6 py-4 text-xs text-slate-700 dark:text-slate-350 ${alignClass(
                          column.align
                        )}`}
                      >
                        {column.render ? (
                          column.render(item, value)
                        ) : (
                          <span className="font-medium">{value !== undefined ? String(value) : ""}</span>
                        )}
                      </td>
                    );
                  })}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
