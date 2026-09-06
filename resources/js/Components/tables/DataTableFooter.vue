<!-- ponytail: RecordCount + PageSize selector + existing DataTablePagination, in one footer row. -->
<script setup lang="ts">
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'
import { useI18n } from '@/Composables/useI18n'

const { t } = useI18n()

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
        {{ t('table.showing_range', { from: from ?? 0, to: to ?? 0, total: total }) }}
      </span>
      <label v-if="perPage !== undefined" class="flex items-center gap-1.5">
        <span>{{ t('table.rows') }}</span>
        <select v-model.number="perPage" class="rounded-md border border-border bg-surface-0 px-2 py-1 text-sm text-ink-900 outline-none focus:border-accent focus:ring-1 focus:ring-accent/20">
          <option v-for="n in perPageOptions" :key="n" :value="n" class="bg-surface-0 text-ink-900">{{ n }}</option>
        </select>
      </label>
    </div>

    <DataTablePagination v-if="links" :links="links" />
  </div>
</template>
