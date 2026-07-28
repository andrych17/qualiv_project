<!-- ponytail: RecordCount + PageSize selector + existing DataTablePagination, in one footer row. -->
<script setup lang="ts">
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'

withDefaults(
  defineProps<{
    total?: number
    from?: number | null
    to?: number | null
    links?: Array<{ url: string | null; label: string; active: boolean }>
    perPageOptions?: number[]
  }>(),
  { perPageOptions: () => [10, 20, 50, 100] },
)

const perPage = defineModel<number>('perPage')
</script>

<template>
  <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-3 text-sm text-ink-600">
      <span v-if="total !== undefined">
        Showing {{ from ?? 0 }}–{{ to ?? 0 }} of {{ total }}
      </span>
      <label v-if="perPage !== undefined" class="flex items-center gap-1.5">
        <span>Rows:</span>
        <select v-model.number="perPage" class="rounded-md border border-border py-1 text-sm">
          <option v-for="n in perPageOptions" :key="n" :value="n">{{ n }}</option>
        </select>
      </label>
    </div>

    <DataTablePagination v-if="links" :links="links" />
  </div>
</template>
