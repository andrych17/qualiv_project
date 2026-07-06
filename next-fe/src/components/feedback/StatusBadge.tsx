import React from "react";

interface StatusBadgeProps {
  status: string;
  label?: string;
}

export default function StatusBadge({ status, label }: StatusBadgeProps) {
  const normalizedStatus = status?.toLowerCase() ?? "unknown";

  const displayLabel =
    label ??
    normalizedStatus
      .replace(/_/g, " ")
      .replace(/\b\w/g, (char) => char.toUpperCase());

  const getBadgeClass = (statusKey: string) => {
    const map: Record<string, string> = {
      active: "bg-emerald-50 text-emerald-700 border-emerald-200/60 dark:bg-emerald-950/20 dark:text-emerald-455 dark:border-emerald-900/30",
      approved: "bg-emerald-50 text-emerald-700 border-emerald-200/60 dark:bg-emerald-950/20 dark:text-emerald-455 dark:border-emerald-900/30",
      inactive: "bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/40 dark:text-slate-400 dark:border-slate-700/30",
      archived: "bg-rose-50 text-rose-700 border-rose-200/60 dark:bg-rose-950/20 dark:text-rose-455 dark:border-rose-900/30",
      rejected: "bg-rose-50 text-rose-700 border-rose-200/60 dark:bg-rose-950/20 dark:text-rose-455 dark:border-rose-900/30",
      pending: "bg-amber-50 text-amber-700 border-amber-200/60 dark:bg-amber-950/20 dark:text-amber-455 dark:border-amber-900/30",
    };

    return map[statusKey] ?? "bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/40 dark:text-slate-400 dark:border-slate-700/30";
  };

  return (
    <span
      className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold ${getBadgeClass(
        normalizedStatus
      )}`}
    >
      {displayLabel}
    </span>
  );
}
