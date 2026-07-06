export interface Category {
  id: number;
  name: string;
  code: string;
}

export interface InventoryItem {
  id: number;
  code: string;
  name: string;
  description: string;
  inventory_category_id: number;
  category_name?: string;
  stock: number;
  minimum_stock: number;
  unit: string;
  status: "active" | "inactive" | "archived";
  created_at_formatted?: string;
}

export interface Activity {
  id: number;
  module: string;
  action: string;
  user: string;
  time: string;
}

const INITIAL_CATEGORIES: Category[] = [
  { id: 1, name: "Raw Material", code: "RAW" },
  { id: 2, name: "Finished Goods", code: "FG" },
  { id: 3, name: "Sparepart", code: "SP" },
  { id: 4, name: "Office Supply", code: "OS" },
  { id: 5, name: "Packaging", code: "PKG" },
  { id: 6, name: "Asset", code: "AST" },
];

const INITIAL_ITEMS: InventoryItem[] = [
  {
    id: 1,
    code: "RAW-001",
    name: "Steel Plate 2mm",
    description: "Raw material for production",
    inventory_category_id: 1,
    stock: 120,
    minimum_stock: 30,
    unit: "pcs",
    status: "active",
    created_at_formatted: "01 Jul 2026",
  },
  {
    id: 2,
    code: "RAW-002",
    name: "Aluminium Sheet",
    description: "Aluminium sheet for assembly",
    inventory_category_id: 1,
    stock: 75,
    minimum_stock: 20,
    unit: "pcs",
    status: "active",
    created_at_formatted: "02 Jul 2026",
  },
  {
    id: 3,
    code: "FG-001",
    name: "Finished Product A",
    description: "Ready to sell product",
    inventory_category_id: 2,
    stock: 45,
    minimum_stock: 10,
    unit: "box",
    status: "active",
    created_at_formatted: "03 Jul 2026",
  },
  {
    id: 4,
    code: "SP-001",
    name: "Bearing 6202",
    description: "Machine sparepart",
    inventory_category_id: 3,
    stock: 8,
    minimum_stock: 15,
    unit: "pcs",
    status: "active",
    created_at_formatted: "04 Jul 2026",
  },
  {
    id: 5,
    code: "OS-001",
    name: "A4 Paper",
    description: "Office printing paper",
    inventory_category_id: 4,
    stock: 30,
    minimum_stock: 10,
    unit: "rim",
    status: "active",
    created_at_formatted: "04 Jul 2026",
  },
  {
    id: 6,
    code: "PKG-001",
    name: "Carton Box Medium",
    description: "Packaging carton box",
    inventory_category_id: 5,
    stock: 200,
    minimum_stock: 50,
    unit: "pcs",
    status: "active",
    created_at_formatted: "05 Jul 2026",
  },
  {
    id: 7,
    code: "AST-001",
    name: "Barcode Scanner",
    description: "Warehouse scanner device",
    inventory_category_id: 6,
    stock: 5,
    minimum_stock: 2,
    unit: "unit",
    status: "active",
    created_at_formatted: "05 Jul 2026",
  },
  {
    id: 8,
    code: "AST-002",
    name: "Old Printer",
    description: "Archived office asset",
    inventory_category_id: 6,
    stock: 1,
    minimum_stock: 0,
    unit: "unit",
    status: "archived",
    created_at_formatted: "06 Jul 2026",
  },
];

const INITIAL_ACTIVITIES: Activity[] = [
  { id: 1, module: "Inventory", action: "Created item RAW-001", user: "Admin User", time: "5 mins ago" },
  { id: 2, module: "Sales", action: "Updated sales order SO-2026-001", user: "Sales User", time: "20 mins ago" },
  { id: 3, module: "Accounting", action: "Posted journal entry JE-1001", user: "Finance User", time: "1 hour ago" },
  { id: 4, module: "Workflow", action: "Approved purchase request PR-5501", user: "Manager User", time: "2 hours ago" },
];

export function initializeStorage() {
  if (typeof window === "undefined") return;

  if (!localStorage.getItem("erp_categories")) {
    localStorage.setItem("erp_categories", JSON.stringify(INITIAL_CATEGORIES));
  }
  if (!localStorage.getItem("erp_items")) {
    localStorage.setItem("erp_items", JSON.stringify(INITIAL_ITEMS));
  }
  if (!localStorage.getItem("erp_activities")) {
    localStorage.setItem("erp_activities", JSON.stringify(INITIAL_ACTIVITIES));
  }
}

export function getCategories(): Category[] {
  initializeStorage();
  if (typeof window === "undefined") return INITIAL_CATEGORIES;
  const cats = localStorage.getItem("erp_categories");
  return cats ? JSON.parse(cats) : INITIAL_CATEGORIES;
}

export function getItems(): InventoryItem[] {
  initializeStorage();
  if (typeof window === "undefined") return INITIAL_ITEMS;
  const itemsStr = localStorage.getItem("erp_items");
  if (!itemsStr) return INITIAL_ITEMS;

  const items: InventoryItem[] = JSON.parse(itemsStr);
  const categories = getCategories();

  // Attach category names
  return items.map((item) => {
    const cat = categories.find((c) => c.id === Number(item.inventory_category_id));
    return {
      ...item,
      category_name: cat ? cat.name : "Uncategorized",
    };
  });
}

export function getItemById(id: number): InventoryItem | undefined {
  const items = getItems();
  return items.find((item) => item.id === id);
}

export function saveItem(item: Omit<InventoryItem, "id"> & { id?: number }): InventoryItem {
  initializeStorage();
  const items = getItems().map(({ category_name, ...rest }) => rest as InventoryItem);

  let savedItem: InventoryItem;

  if (item.id) {
    // Update
    savedItem = {
      ...item,
      id: item.id,
      created_at_formatted: item.created_at_formatted || new Date().toLocaleDateString("en-GB", {
        day: "2-digit",
        month: "short",
        year: "numeric",
      }),
    } as InventoryItem;

    const index = items.findIndex((i) => i.id === item.id);
    if (index !== -1) {
      items[index] = savedItem;
    }
  } else {
    // Create
    const maxId = items.reduce((max, i) => (i.id > max ? i.id : max), 0);
    savedItem = {
      ...item,
      id: maxId + 1,
      created_at_formatted: new Date().toLocaleDateString("en-GB", {
        day: "2-digit",
        month: "short",
        year: "numeric",
      }),
    } as InventoryItem;

    items.push(savedItem);

    // Add activity log
    logActivity("Inventory", `Created item ${savedItem.code}`, "Admin User");
  }

  localStorage.setItem("erp_items", JSON.stringify(items));
  return savedItem;
}

export function deleteItem(id: number): boolean {
  initializeStorage();
  const items = getItems().map(({ category_name, ...rest }) => rest as InventoryItem);
  const itemToDelete = items.find((i) => i.id === id);

  if (!itemToDelete) return false;

  const filtered = items.filter((item) => item.id !== id);
  localStorage.setItem("erp_items", JSON.stringify(filtered));

  logActivity("Inventory", `Deleted item ${itemToDelete.code}`, "Admin User");
  return true;
}

export function getActivities(): Activity[] {
  initializeStorage();
  if (typeof window === "undefined") return INITIAL_ACTIVITIES;
  const acts = localStorage.getItem("erp_activities");
  return acts ? JSON.parse(acts) : INITIAL_ACTIVITIES;
}

export function logActivity(module: string, action: string, user: string) {
  if (typeof window === "undefined") return;
  const acts: Activity[] = getActivities();
  const maxId = acts.reduce((max, a) => (a.id > max ? a.id : max), 0);
  const newActivity: Activity = {
    id: maxId + 1,
    module,
    action,
    user,
    time: "Just now",
  };
  acts.unshift(newActivity);
  // Keep last 15 activities
  localStorage.setItem("erp_activities", JSON.stringify(acts.slice(0, 15)));
}
