import React from "react";
import { Search } from "lucide-react";

interface FormInputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, "onChange"> {
  label?: string;
  name: string;
  value: string | number;
  onChange: (value: string) => void;
  error?: string;
  required?: boolean;
  isSearch?: boolean;
}

export default function FormInput({
  label,
  name,
  value,
  onChange,
  error,
  required = false,
  isSearch = false,
  className = "",
  ...props
}: FormInputProps) {
  const inputId = `input-${name}`;

  return (
    <div className={`space-y-1.5 w-full ${className}`}>
      {label && (
        <label
          htmlFor={inputId}
          className="block text-xs font-semibold text-slate-700 dark:text-slate-350"
        >
          {label} {required && <span className="text-rose-500">*</span>}
        </label>
      )}

      <div className="relative">
        {isSearch && (
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 dark:text-slate-500" />
        )}
        <input
          id={inputId}
          name={name}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className={`w-full rounded-lg border bg-white dark:bg-slate-900 py-2 pr-3 text-xs shadow-sm outline-none transition-all duration-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 dark:text-slate-200 ${
            isSearch ? "pl-9" : "pl-3"
          } ${
            error
              ? "border-rose-500 focus:border-rose-500 focus:ring-rose-500/10"
              : "border-slate-250 dark:border-slate-800 focus:border-indigo-500 dark:focus:border-indigo-500"
          }`}
          {...props}
        />
      </div>

      {error && (
        <p className="text-[11px] text-rose-600 dark:text-rose-450 font-medium">
          {error}
        </p>
      )}
    </div>
  );
}
