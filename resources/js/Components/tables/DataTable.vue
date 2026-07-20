<!-- ponytail: DataTable + optional Status Rail (DESIGN.md signature) -->
<script setup lang="ts">
type Column = {
  key: string
  label: string
  sortable?: boolean
  align?: 'left' | 'center' | 'right'
}

const props = defineProps<{
  columns: Column[]
  items: Record<string, any>[]
  loading?: boolean
  emptyTitle?: string
  emptyDescription?: string
  /** When set, left edge of row uses semantic rail color from item[statusRailKey]. */
  statusRailKey?: string
}>()

const alignClass = (align?: string) => {
  if (align === 'center') return 'text-center'
  if (align === 'right') return 'text-right'
  return 'text-left'
}

const railClass = (item: Record<string, any>) => {
  if (!props.statusRailKey) return ''
  const status = String(item[props.statusRailKey] ?? '').toLowerCase()
  const map: Record<string, string> = {
    open: 'border-l-[3px] border-l-signal-info',
    pending: 'border-l-[3px] border-l-signal-warning',
    closed: 'border-l-[3px] border-l-signal-success',
    active: 'border-l-[3px] border-l-signal-success',
    overdue: 'border-l-[3px] border-l-signal-danger',
    rejected: 'border-l-[3px] border-l-signal-danger',
  }
  return map[status] ?? 'border-l-[3px] border-l-border'
}
</script>

<template>
  <div class="overflow-hidden rounded-md border border-border bg-surface-0 shadow-sm">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-border">
        <thead class="bg-surface-50">
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-ink-600"
              :class="alignClass(column.align)"
            >
              {{ column.label }}
            </th>
          </tr>
        </thead>

        <tbody class="divide-y divide-border bg-surface-0">
          <tr v-if="loading">
            <td :colspan="columns.length" class="px-4 py-8 text-center text-sm text-ink-600">
              Loading…
            </td>
          </tr>

          <tr v-else-if="items.length === 0">
            <td :colspan="columns.length" class="px-4 py-10 text-center">
              <slot name="empty">
                <div class="space-y-1">
                  <p class="text-sm font-medium text-ink-900">
                    {{ emptyTitle ?? 'Nothing here yet' }}
                  </p>
                  <p class="text-sm text-ink-600">
                    {{ emptyDescription ?? 'Add the first item to get started.' }}
                  </p>
                </div>
              </slot>
            </td>
          </tr>

          <tr
            v-else
            v-for="item in items"
            :key="item.id"
            class="hover:bg-surface-50"
            :class="railClass(item)"
          >
            <td
              v-for="column in columns"
              :key="column.key"
              class="whitespace-nowrap px-4 py-3 text-sm text-ink-900"
              :class="alignClass(column.align)"
            >
              <slot :name="`cell-${column.key}`" :item="item" :value="item[column.key]">
                {{ item[column.key] }}
              </slot>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
