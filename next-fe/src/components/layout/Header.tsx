"use client";

import { useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { User as UserIcon, LogOut, Bell, Search, Moon, Sun, ChevronRight } from "lucide-react";
import Link from "next/link";

export default function Header() {
  const pathname = usePathname();
  const router = useRouter();
  const [isOpen, setIsOpen] = useState(false);
  const [isDarkMode, setIsDarkMode] = useState(false);

  // Generate breadcrumbs from pathname
  const segments = pathname.split("/").filter(Boolean);
  const breadcrumbs = segments.map((segment, index) => {
    const href = "/" + segments.slice(0, index + 1).join("/");
    const label = segment.charAt(0).toUpperCase() + segment.slice(1);
    return { href, label };
  });

  const handleLogout = () => {
    // Standard mock logout
    localStorage.removeItem("erp_user");
    router.push("/login");
  };

  // Quick helper to toggle HTML class for dark mode
  const toggleDarkMode = () => {
    const html = document.documentElement;
    if (html.classList.contains("dark")) {
      html.classList.remove("dark");
      setIsDarkMode(false);
    } else {
      html.classList.add("dark");
      setIsDarkMode(true);
    }
  };

  // Mock User Information
  const user = {
    name: "Administrator",
    email: "admin@nusaevo.com",
  };

  return (
    <header className="h-16 border-b border-slate-200/80 dark:border-slate-800 bg-white/80 dark:bg-slate-900/85 backdrop-blur-md flex items-center justify-between px-6 sticky top-0 z-20">
      {/* Breadcrumbs */}
      <div className="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
        <Link
          href="/dashboard"
          className="hover:text-slate-900 dark:hover:text-white transition-colors"
        >
          Home
        </Link>
        {breadcrumbs.map((crumb, idx) => (
          <div key={crumb.href} className="flex items-center gap-2">
            <ChevronRight className="h-3.5 w-3.5 text-slate-400" />
            <Link
              href={crumb.href}
              className={`transition-colors ${
                idx === breadcrumbs.length - 1
                  ? "text-slate-850 dark:text-white font-medium pointer-events-none"
                  : "hover:text-slate-900 dark:hover:text-white"
              }`}
            >
              {crumb.label}
            </Link>
          </div>
        ))}
      </div>

      {/* Right-side Actions */}
      <div className="flex items-center gap-4">
        {/* Search Bar */}
        <div className="relative hidden md:block w-64">
          <Search className="absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
          <input
            type="text"
            placeholder="Search transaction..."
            className="w-full pl-9 pr-4 py-1.5 text-xs rounded-lg border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950 focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 dark:text-slate-200"
          />
        </div>

        {/* Theme Toggle */}
        <button
          onClick={toggleDarkMode}
          className="p-2 rounded-lg text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
          title="Toggle Theme"
        >
          {isDarkMode ? <Sun className="h-4.5 w-4.5" /> : <Moon className="h-4.5 w-4.5" />}
        </button>

        {/* Notification Icon */}
        <button className="p-2 rounded-lg text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors relative">
          <Bell className="h-4.5 w-4.5" />
          <span className="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-rose-500 ring-2 ring-white dark:ring-slate-900"></span>
        </button>

        <div className="h-6 w-px bg-slate-200 dark:bg-slate-850"></div>

        {/* User Dropdown */}
        <div className="relative">
          <button
            onClick={() => setIsOpen(!isOpen)}
            className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white focus:outline-none"
          >
            <div className="h-8 w-8 rounded-full bg-indigo-50 dark:bg-indigo-950/50 flex items-center justify-center border border-indigo-100 dark:border-indigo-900/50">
              <UserIcon className="h-4 w-4 text-indigo-600 dark:text-indigo-400" />
            </div>
            <span className="hidden sm:inline text-xs font-semibold">{user.name}</span>
          </button>

          {isOpen && (
            <>
              <div
                onClick={() => setIsOpen(false)}
                className="fixed inset-0 z-10"
              ></div>

              <div className="absolute right-0 mt-2 w-48 rounded-lg border border-slate-100 dark:border-slate-800 bg-white dark:bg-slate-950 py-1 shadow-lg ring-1 ring-black/5 z-20">
                <div className="px-4 py-2 border-b border-slate-100 dark:border-slate-900">
                  <p className="text-[10px] text-slate-400 uppercase tracking-wider">Signed in as</p>
                  <p className="text-xs font-medium text-slate-750 dark:text-slate-200 truncate">
                    {user.email}
                  </p>
                </div>

                <button
                  onClick={handleLogout}
                  className="w-full flex items-center gap-2 px-4 py-2 text-left text-xs text-rose-600 hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors"
                >
                  <LogOut className="h-4 w-4" />
                  Log Out
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </header>
  );
}
