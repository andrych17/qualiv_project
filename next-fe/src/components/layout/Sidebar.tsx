"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import * as Icons from "lucide-react";

interface MenuItem {
  label: string;
  icon: keyof typeof Icons;
  href: string;
}

const menuItems: MenuItem[] = [
  { label: "Dashboard", icon: "LayoutDashboard", href: "/dashboard" },
  { label: "CRM", icon: "Users", href: "#" },
  { label: "Schedule", icon: "Calendar", href: "#" },
  { label: "CMS", icon: "FileText", href: "#" },
  { label: "Legal", icon: "Scale", href: "#" },
  { label: "HSE", icon: "ShieldCheck", href: "#" },
  { label: "Project", icon: "Kanban", href: "#" },
  { label: "Inventory", icon: "Boxes", href: "/inventory" },
  { label: "Sales", icon: "ShoppingCart", href: "#" },
  { label: "Procurement", icon: "SearchCode", href: "#" },
  { label: "HCM", icon: "UserCog", href: "#" },
  { label: "Payroll", icon: "CreditCard", href: "#" },
  { label: "Asset", icon: "Archive", href: "#" },
  { label: "Accounting", icon: "Calculator", href: "#" },
  { label: "Workflow", icon: "GitFork", href: "#" },
  { label: "Notifications", icon: "Bell", href: "#" },
  { label: "Delivery", icon: "Truck", href: "#" },
];

export default function Sidebar() {
  const pathname = usePathname();

  const getIcon = (name: keyof typeof Icons) => {
    const IconComponent = Icons[name] as React.ComponentType<{ className?: string }>;
    // Fallback to HelpCircle if icon is not found
    return IconComponent ? <IconComponent className="h-4 w-4 shrink-0" /> : <Icons.HelpCircle className="h-4 w-4 shrink-0" />;
  };

  const isActive = (href: string) => {
    if (href === "#") return false;
    if (href === "/dashboard" && pathname === "/dashboard") return true;
    if (href !== "/dashboard" && pathname.startsWith(href)) return true;
    return false;
  };

  return (
    <aside className="w-64 border-r border-slate-200/80 dark:border-slate-800 bg-slate-900 text-slate-400 flex flex-col h-screen sticky top-0 z-25">
      {/* Sidebar Header */}
      <div className="h-16 flex items-center px-6 border-b border-slate-800/80 bg-slate-950/40">
        <div className="flex items-center gap-2">
          <div className="h-8 w-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white font-bold text-lg shadow-md shadow-indigo-600/30">
            N
          </div>
          <span className="text-lg font-bold tracking-tight text-white">
            NusaEvo <span className="text-indigo-400">ERP</span>
          </span>
        </div>
      </div>

      {/* Navigation List */}
      <nav className="flex-1 overflow-y-auto p-4 space-y-1 scrollbar">
        {menuItems.map((item) => {
          const active = isActive(item.href);
          return (
            <Link
              key={item.label}
              href={item.href}
              className={`flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-all duration-250 ${
                active
                  ? "bg-indigo-600 text-white shadow-sm shadow-indigo-600/20"
                  : "hover:bg-slate-800 hover:text-slate-100"
              }`}
            >
              {getIcon(item.icon)}
              <span>{item.label}</span>
            </Link>
          );
        })}
      </nav>
      
      {/* Sidebar Footer */}
      <div className="p-4 border-t border-slate-800/80 bg-slate-950/20 text-xs text-slate-500 flex items-center justify-between">
        <span>Next.js Edition</span>
        <span className="px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 font-mono">v15.0</span>
      </div>
    </aside>
  );
}
