<!-- ponytail: Reusable DataTable component -->
<script setup lang="ts">
type Column = {
  key: string
  label: string
  sortable?: boolean
  align?: 'left' | 'center' | 'right'
}

defineProps<{
  columns: Column[]
  items: Record<string, any>[]
  loading?: boolean
  emptyTitle?: string
  emptyDescription?: string
}>()

const alignClass = (align?: string) => {
  if (align === 'center') return 'text-center'
  if (align === 'right') return 'text-right'
  return 'text-left'
}
</script>

<template>
  <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th
              v-for="column in columns"
              :key="column.key"
              class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-gray-500"
              :class="alignClass(column.align)"
            >
              {{ column.label }}
            </th>
          </tr>
        </thead>

        <tbody class="divide-y divide-gray-100 bg-white">
          <tr v-if="loading">
            <td :colspan="columns.length" class="px-4 py-8 text-center text-sm text-gray-500">
              Loading data...
            </td>
          </tr>

          <tr v-else-if="items.length === 0">
            <td :colspan="columns.length" class="px-4 py-10 text-center">
              <slot name="empty">
                <div class="space-y-1">
                  <p class="text-sm font-medium text-gray-900">
                    {{ emptyTitle ?? 'No data found' }}
                  </p>
                  <p class="text-sm text-gray-500">
                    {{ emptyDescription ?? 'Try changing your search or filter.' }}
                  </p>
                </div>
              </slot>
            </td>
          </tr>

          <tr
            v-else
            v-for="item in items"
            :key="item.id"
            class="hover:bg-gray-50"
          >
            <td
              v-for="column in columns"
              :key="column.key"
              class="whitespace-nowrap px-4 py-3 text-sm text-gray-700"
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
