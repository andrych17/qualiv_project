"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import AppLayout from "@/components/layout/AppLayout";
import PageHeader from "@/components/layout/PageHeader";
import DataTable, { Column } from "@/components/tables/DataTable";
import DataTablePagination, { PaginationLink } from "@/components/tables/DataTablePagination";
import StatusBadge from "@/components/feedback/StatusBadge";
import FormSelect from "@/components/forms/FormSelect";
import FormInput from "@/components/forms/FormInput";
import { getItems, deleteItem, InventoryItem } from "@/lib/storage";

export default function InventoryPage() {
  const [allItems, setAllItems] = useState<InventoryItem[]>([]);
  const [filteredItems, setFilteredItems] = useState<InventoryItem[]>([]);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 8;

  useEffect(() => {
    // Load items on mount
    loadItems();
  }, []);

  const loadItems = () => {
    const data = getItems();
    setAllItems(data);
  };

  // Perform search, filter, and paginate client-side
  useEffect(() => {
    let result = [...allItems];

    if (search.trim()) {
      const q = search.toLowerCase();
      result = result.filter(
        (item) =>
          item.name.toLowerCase().includes(q) ||
          item.code.toLowerCase().includes(q) ||
          (item.description && item.description.toLowerCase().includes(q))
      );
    }

    if (status) {
      result = result.filter((item) => item.status === status);
    }

    setFilteredItems(result);
    setCurrentPage(1); // Reset page on filter
  }, [search, status, allItems]);

  const handleDelete = (id: number, name: string) => {
    if (!confirm(`Are you sure you want to delete item "${name}"?`)) return;

    const success = deleteItem(id);
    if (success) {
      loadItems(); // Reload storage state
    }
  };

  // Pagination calculation
  const totalItems = filteredItems.length;
  const totalPages = Math.ceil(totalItems / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const paginatedItems = filteredItems.slice(startIndex, startIndex + itemsPerPage);

  // Generate pagination links matching the Laravel structure expected by the component
  const generatePaginationLinks = (): PaginationLink[] => {
    const links: PaginationLink[] = [];

    // Previous link
    links.push({
      url: currentPage > 1 ? String(currentPage - 1) : null,
      label: "&laquo; Previous",
      active: false,
    });

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
      links.push({
        url: String(i),
        label: String(i),
        active: i === currentPage,
      });
    }

    // Next link
    links.push({
      url: currentPage < totalPages ? String(currentPage + 1) : null,
      label: "Next &raquo;",
      active: false,
    });

    return links;
  };

  const handlePageChange = (pageUrl: string) => {
    const pageNum = parseInt(pageUrl, 10);
    if (!isNaN(pageNum)) {
      setCurrentPage(pageNum);
    }
  };

  const columns: Column[] = [
    { key: "code", label: "Code" },
    { key: "name", label: "Name" },
    { key: "category_name", label: "Category" },
    {
      key: "stock",
      label: "Stock",
      align: "right",
      render: (item: InventoryItem) => (
        <span
          className={
            item.stock <= item.minimum_stock
              ? "font-bold text-rose-600 dark:text-rose-400"
              : "font-semibold text-slate-800 dark:text-slate-200"
          }
        >
          {item.stock}
        </span>
      ),
    },
    { key: "unit", label: "Unit" },
    {
      key: "status",
      label: "Status",
      render: (item: InventoryItem) => <StatusBadge status={item.status} />,
    },
    {
      key: "created_at_formatted",
      label: "Created Date",
      render: (item: InventoryItem) => (
        <span className="text-slate-400 dark:text-slate-500 font-mono">
          {item.created_at_formatted || "-"}
        </span>
      ),
    },
    {
      key: "actions",
      label: "Actions",
      align: "right",
      render: (item: InventoryItem) => (
        <div className="flex items-center justify-end gap-3">
          <Link
            href={`/inventory/${item.id}/edit`}
            className="text-xs font-semibold text-indigo-650 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300 transition-colors"
          >
            Edit
          </Link>
          <button
            onClick={() => handleDelete(item.id, item.name)}
            className="text-xs font-semibold text-rose-600 hover:text-rose-500 dark:text-rose-400 dark:hover:text-rose-300 transition-colors"
          >
            Delete
          </button>
        </div>
      ),
    },
  ];

  return (
    <AppLayout>
      <PageHeader
        title="Inventory Items"
        description="Manage inventory items, stock, categories, and status."
      >
        <Link
          href="/inventory/create"
          className="inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-md shadow-indigo-600/10 hover:bg-indigo-500 transition-colors active:scale-[0.98]"
        >
          Create Item
        </Link>
      </PageHeader>

      <div className="mt-6 space-y-4">
        {/* Search & Status Filters */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between bg-white dark:bg-slate-900 p-4 rounded-xl border border-slate-200/80 dark:border-slate-800 shadow-sm">
          <div className="flex-1 max-w-sm">
            <FormInput
              name="search"
              placeholder="Search by code or name..."
              value={search}
              onChange={setSearch}
              isSearch
            />
          </div>
          <div className="w-full sm:w-48">
            <FormSelect
              name="status"
              placeholder="All Status"
              value={status}
              onChange={setStatus}
              options={[
                { label: "Active", value: "active" },
                { label: "Inactive", value: "inactive" },
                { label: "Archived", value: "archived" },
              ]}
            />
          </div>
        </div>

        {/* Data Table */}
        <DataTable
          columns={columns}
          items={paginatedItems}
          emptyTitle="No inventory items found"
          emptyDescription="Try broadening your search keywords or checking other status filters."
        />

        {/* Pagination */}
        <DataTablePagination
          links={generatePaginationLinks()}
          onPageChange={handlePageChange}
        />
      </div>
    </AppLayout>
  );
}
