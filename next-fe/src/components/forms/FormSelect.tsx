import React from "react";

interface Option {
  label: string;
  value: string | number;
}

interface FormSelectProps extends Omit<React.SelectHTMLAttributes<HTMLSelectElement>, "value" | "onChange"> {
  label?: string;
  name: string;
  options: Option[];
  value: string | number | null | undefined;
  onChange: (value: string) => void;
  placeholder?: string;
  error?: string;
  required?: boolean;
}

export default function FormSelect({
  label,
  name,
  options,
  value,
  onChange,
  placeholder = "Select option...",
  error,
  required = false,
  ...props
}: FormSelectProps) {
  const selectId = `select-${name}`;

  return (
    <div className="space-y-1.5 w-full">
      {label && (
        <label
          htmlFor={selectId}
          className="block text-xs font-semibold text-slate-700 dark:text-slate-350"
        >
          {label} {required && <span className="text-rose-500">*</span>}
        </label>
      )}

      <select
        id={selectId}
        name={name}
        value={value ?? ""}
        onChange={(e) => onChange(e.target.value)}
        className={`w-full rounded-lg border bg-white dark:bg-slate-900 px-3 py-2 text-xs shadow-sm outline-none transition-all duration-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 dark:text-slate-200 ${
          error
            ? "border-rose-500 focus:border-rose-500 focus:ring-rose-500/10"
            : "border-slate-250 dark:border-slate-800 focus:border-indigo-500 dark:focus:border-indigo-500"
        }`}
        {...props}
      >
        <option value="" disabled>
          {placeholder}
        </option>
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>

      {error && (
        <p className="text-[11px] text-rose-600 dark:text-rose-450 font-medium">
          {error}
        </p>
      )}
    </div>
  );
}
