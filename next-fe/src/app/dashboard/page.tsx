"use client";

import { useEffect, useState } from "react";
import AppLayout from "@/components/layout/AppLayout";
import PageHeader from "@/components/layout/PageHeader";
import Link from "next/link";
import * as Icons from "lucide-react";
import { getItems, getActivities, getCategories, InventoryItem, Activity } from "@/lib/storage";

interface CardData {
  title: string;
  value: string | number;
  description: string;
  icon: keyof typeof Icons;
  color: string;
}

export default function Dashboard() {
  const [items, setItems] = useState<InventoryItem[]>([]);
  const [activities, setActivities] = useState<Activity[]>([]);
  const [summaryCards, setSummaryCards] = useState<CardData[]>([]);

  useEffect(() => {
    const loadedItems = getItems();
    const loadedActivities = getActivities();
    setItems(loadedItems);
    setActivities(loadedActivities);

    // Calculate low stock items
    const lowStock = loadedItems.filter((i) => i.stock <= i.minimum_stock).length;

    setSummaryCards([
      {
        title: "Total Inventory Items",
        value: loadedItems.length,
        description: `Active catalog`,
        icon: "Boxes",
        color: "text-indigo-650 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/40",
      },
      {
        title: "Low Stock Items",
        value: lowStock,
        description: lowStock > 0 ? `${lowStock} require replenishment` : "All stocks healthy",
        icon: "TriangleAlert",
        color: lowStock > 0 
          ? "text-rose-650 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/40 animate-pulse-subtle" 
          : "text-slate-500 bg-slate-50 dark:bg-slate-800/40",
      },
      {
        title: "Active ERP Modules",
        value: "17",
        description: "All services operational",
        icon: "LayoutGrid",
        color: "text-emerald-650 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40",
      },
      {
        title: "Pending Approvals",
        value: "9",
        description: "Awaiting approval workflow",
        icon: "Clock",
        color: "text-amber-650 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40",
      },
    ]);
  }, []);

  const getIcon = (name: keyof typeof Icons) => {
    const IconComponent = Icons[name] as React.ComponentType<{ className?: string }>;
    if (!IconComponent) return <Icons.HelpCircle className="h-5 w-5" />;
    return <IconComponent className="h-5 w-5" />;
  };

  // Group stock data for SVG Chart visualization
  const categories = getCategories();
  const chartData = categories.map((cat) => {
    const catItems = items.filter((i) => i.inventory_category_id === cat.id);
    const totalStock = catItems.reduce((sum, item) => sum + item.stock, 0);
    return {
      label: cat.name,
      value: totalStock,
    };
  });

  const maxChartValue = Math.max(...chartData.map((d) => d.value), 1);

  return (
    <AppLayout>
      <PageHeader
        title="Dashboard"
        description="Welcome to NusaEvo ERP dashboard overview."
      />

      <div className="mt-6 space-y-6">
        {/* Summary Cards Grid */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {summaryCards.map((card) => (
            <div
              key={card.title}
              className="rounded-xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm flex items-center justify-between hover:shadow-md transition-all duration-300"
            >
              <div className="space-y-1">
                <p className="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">{card.title}</p>
                <p className="text-3xl font-bold text-slate-900 dark:text-white">{card.value}</p>
                <p className="text-xs text-slate-400 dark:text-slate-500">{card.description}</p>
              </div>
              <div className={`h-12 w-12 rounded-lg border border-slate-100 dark:border-slate-800 flex items-center justify-center ${card.color}`}>
                {getIcon(card.icon)}
              </div>
            </div>
          ))}
        </div>

        {/* Chart and Quick Actions */}
        <div className="grid gap-6 md:grid-cols-3">
          {/* Stock Levels Visualizer Chart (Premium SVG representation) */}
          <div className="md:col-span-2 rounded-xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm">
            <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-5">
              <div>
                <h2 className="text-base font-semibold text-slate-900 dark:text-white">Stock Level by Category</h2>
                <p className="text-[10px] text-slate-400">Total physical stock balance</p>
              </div>
              <span className="text-xs font-semibold text-indigo-500 dark:text-indigo-400">Real-time</span>
            </div>

            <div className="space-y-4 pt-2">
              {chartData.map((data) => {
                const percentage = (data.value / maxChartValue) * 100;
                return (
                  <div key={data.label} className="space-y-1">
                    <div className="flex items-center justify-between text-xs font-medium">
                      <span className="text-slate-700 dark:text-slate-350">{data.label}</span>
                      <span className="text-slate-900 dark:text-white font-bold">{data.value} units</span>
                    </div>
                    <div className="h-3 w-full rounded-full bg-slate-100 dark:bg-slate-950 overflow-hidden">
                      <div
                        className="h-full rounded-full bg-gradient-to-r from-indigo-550 to-indigo-600 dark:from-indigo-600 dark:to-indigo-500 transition-all duration-1000 ease-out"
                        style={{ width: `${percentage}%` }}
                      ></div>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          {/* Quick Access Shortcuts */}
          <div className="rounded-xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm flex flex-col justify-between">
            <div>
              <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-4">
                <h2 className="text-base font-semibold text-slate-900 dark:text-white">Quick Actions</h2>
              </div>

              <div className="grid gap-3">
                <Link
                  href="/inventory"
                  className="flex items-center justify-between rounded-xl border border-slate-100 dark:border-slate-800 p-3 bg-slate-50/50 dark:bg-slate-950/30 hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20 hover:border-indigo-100 dark:hover:border-indigo-950 transition-all duration-200 group"
                >
                  <div className="flex items-center gap-3">
                    <Icons.Boxes className="h-5 w-5 text-slate-500 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors" />
                    <span className="text-xs font-semibold text-slate-700 dark:text-slate-300">Manage Inventory</span>
                  </div>
                  <Icons.ChevronRight className="h-4 w-4 text-slate-400 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors" />
                </Link>

                <Link
                  href="/inventory/create"
                  className="flex items-center justify-between rounded-xl border border-slate-100 dark:border-slate-800 p-3 bg-slate-50/50 dark:bg-slate-950/30 hover:bg-indigo-50/50 dark:hover:bg-indigo-950/20 hover:border-indigo-100 dark:hover:border-indigo-950 transition-all duration-200 group"
                >
                  <div className="flex items-center gap-3">
                    <Icons.PlusCircle className="h-5 w-5 text-slate-500 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors" />
                    <span className="text-xs font-semibold text-slate-700 dark:text-slate-300">Create Stock Item</span>
                  </div>
                  <Icons.ChevronRight className="h-4 w-4 text-slate-400 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors" />
                </Link>
              </div>
            </div>

            <div className="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800 text-xs text-slate-450 text-center">
              ERP Sandbox - Next.js App Router
            </div>
          </div>
        </div>

        {/* Recent Activities Log */}
        <div className="rounded-xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm">
          <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-4 mb-4">
            <h2 className="text-base font-semibold text-slate-900 dark:text-white">Recent Activities</h2>
            <span className="text-xs text-slate-400">Live operation logs</span>
          </div>

          <div className="overflow-x-auto scrollbar">
            <table className="min-w-full divide-y divide-slate-100 dark:divide-slate-800 text-xs">
              <thead>
                <tr className="text-left text-xs font-bold text-slate-400 dark:text-slate-500 uppercase tracking-wider">
                  <th className="py-2.5">Module</th>
                  <th className="py-2.5">Action</th>
                  <th className="py-2.5">User</th>
                  <th className="py-2.5 text-right">Time</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50 dark:divide-slate-850 text-slate-700 dark:text-slate-300">
                {activities.map((act) => (
                  <tr key={act.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-850/10 transition-colors">
                    <td className="py-3 font-semibold">
                      <span className="inline-flex items-center rounded-full bg-slate-100 dark:bg-slate-800 px-2.5 py-0.5 text-[10px] font-bold text-slate-800 dark:text-slate-300">
                        {act.module}
                      </span>
                    </td>
                    <td className="py-3">{act.action}</td>
                    <td className="py-3 text-slate-500 dark:text-slate-400">{act.user}</td>
                    <td className="py-3 text-right text-slate-400 dark:text-slate-500">{act.time}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
