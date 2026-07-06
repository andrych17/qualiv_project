"use client";

import React, { useState, useEffect } from "react";
import { useRouter, useParams } from "next/navigation";
import Link from "next/link";
import AppLayout from "@/components/layout/AppLayout";
import PageHeader from "@/components/layout/PageHeader";
import FormInput from "@/components/forms/FormInput";
import FormSelect from "@/components/forms/FormSelect";
import { getCategories, getItemById, saveItem, Category } from "@/lib/storage";

export default function EditItemPage() {
  const router = useRouter();
  const params = useParams();
  const id = params?.id ? Number(params.id) : null;

  const [categories, setCategories] = useState<Category[]>([]);
  const [notFound, setNotFound] = useState(false);

  // Form Fields State
  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [stock, setStock] = useState("0");
  const [minimumStock, setMinimumStock] = useState("0");
  const [unit, setUnit] = useState("");
  const [status, setStatus] = useState("active");

  // Error States
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    setCategories(getCategories());

    if (id !== null && !isNaN(id)) {
      const item = getItemById(id);
      if (item) {
        setCode(item.code);
        setName(item.name);
        setDescription(item.description || "");
        setCategoryId(String(item.inventory_category_id));
        setStock(String(item.stock));
        setMinimumStock(String(item.minimum_stock));
        setUnit(item.unit);
        setStatus(item.status);
      } else {
        setNotFound(true);
      }
    } else {
      setNotFound(true);
    }
  }, [id]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    // Validate
    const newErrors: Record<string, string> = {};
    if (!code.trim()) newErrors.code = "Item code is required.";
    if (!name.trim()) newErrors.name = "Item name is required.";
    if (!categoryId) newErrors.categoryId = "Category is required.";
    if (!unit.trim()) newErrors.unit = "Unit of measurement is required.";
    if (isNaN(Number(stock)) || Number(stock) < 0) {
      newErrors.stock = "Stock must be a positive number.";
    }
    if (isNaN(Number(minimumStock)) || Number(minimumStock) < 0) {
      newErrors.minimumStock = "Minimum stock must be a positive number.";
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      return;
    }

    setIsSubmitting(true);

    try {
      saveItem({
        id: id!,
        code: code.trim().toUpperCase(),
        name: name.trim(),
        description: description.trim(),
        inventory_category_id: Number(categoryId),
        stock: Number(stock),
        minimum_stock: Number(minimumStock),
        unit: unit.trim(),
        status: status as "active" | "inactive" | "archived",
      });

      router.push("/inventory");
    } catch (err) {
      console.error(err);
      setIsSubmitting(false);
    }
  };

  if (notFound) {
    return (
      <AppLayout>
        <div className="text-center py-16 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl space-y-4 shadow-sm">
          <p className="text-sm font-semibold text-slate-800 dark:text-slate-200">
            Inventory item not found.
          </p>
          <Link
            href="/inventory"
            className="inline-flex items-center rounded-xl bg-indigo-650 px-4 py-2 text-xs font-bold text-white shadow-md shadow-indigo-650/10 hover:bg-indigo-600 transition-colors"
          >
            Back to Inventory List
          </Link>
        </div>
      </AppLayout>
    );
  }

  const categoryOptions = categories.map((c) => ({
    label: c.name,
    value: c.id,
  }));

  return (
    <AppLayout>
      <PageHeader
        title="Edit Inventory Item"
        description="Update inventory details for stock item."
      />

      <div className="mt-6 max-w-2xl rounded-xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm">
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FormInput
              name="code"
              label="Item Code"
              placeholder="e.g. RAW-001"
              value={code}
              onChange={setCode}
              error={errors.code}
              required
            />

            <FormInput
              name="name"
              label="Item Name"
              placeholder="e.g. Steel Plate"
              value={name}
              onChange={setName}
              error={errors.name}
              required
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FormSelect
              name="inventory_category_id"
              label="Category"
              placeholder="Select category"
              value={categoryId}
              onChange={setCategoryId}
              options={categoryOptions}
              error={errors.categoryId}
              required
            />

            <FormInput
              name="unit"
              label="Unit of Measurement"
              placeholder="e.g. pcs, box, kg"
              value={unit}
              onChange={setUnit}
              error={errors.unit}
              required
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FormInput
              name="stock"
              label="Current Stock"
              type="number"
              value={stock}
              onChange={setStock}
              error={errors.stock}
              required
            />

            <FormInput
              name="minimum_stock"
              label="Minimum Safety Stock"
              type="number"
              value={minimumStock}
              onChange={setMinimumStock}
              error={errors.minimumStock}
              required
            />
          </div>

          <FormSelect
            name="status"
            label="Status"
            placeholder="Select status"
            value={status}
            onChange={setStatus}
            options={[
              { label: "Active", value: "active" },
              { label: "Inactive", value: "inactive" },
              { label: "Archived", value: "archived" },
            ]}
            required
          />

          <div className="space-y-1.5">
            <label
              htmlFor="description"
              className="block text-xs font-semibold text-slate-700 dark:text-slate-350"
            >
              Description
            </label>
            <textarea
              id="description"
              rows={3}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Optional brief description..."
              className="w-full rounded-lg border border-slate-250 dark:border-slate-800 bg-white dark:bg-slate-900 px-3 py-2 text-xs shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/10 dark:text-slate-200"
            ></textarea>
          </div>

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
            <Link
              href="/inventory"
              className="text-xs font-bold text-slate-750 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white px-3 py-2"
            >
              Cancel
            </Link>
            <button
              type="submit"
              disabled={isSubmitting}
              className="rounded-xl bg-indigo-650 px-4 py-2 text-xs font-bold text-white shadow-md shadow-indigo-650/10 hover:bg-indigo-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 active:scale-[0.98] transition-all"
            >
              {isSubmitting ? "Saving..." : "Save Changes"}
            </button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
