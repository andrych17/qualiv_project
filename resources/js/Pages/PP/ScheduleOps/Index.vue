<!-- ponytail: Detailed Scheduling board (PP_SPECS.md §3H) — a DataTable list rather than a
     bespoke drag-drop Gantt canvas, same translation §3F's own ASCII-bar mockup got in
     CapacityPlans/Index.vue: no shared timeline/Gantt primitive exists in the component library
     (CLAUDE.md §9D forbids inventing one for a single feature). -->
<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import DataTable, { type SortState } from '@/Components/tables/DataTable.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import { useConfirm } from '@/Composables/useConfirmDialog'

interface ScheduleOpRow {
  id: number
  plan_number: string | null
  product_label: string | null
  seq: number
  resource_type: string | null
  resource_ref_id: number | null
  planned_start: string
  planned_end: string
  status: 'draft' | 'committed' | 'released'
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

const props = defineProps<{
  ops: PaginatedData<ScheduleOpRow>
  filters: { resource_type?: string; status?: string; sort?: string; direction?: string; per_page?: string }
  strategyOptions: Array<{ value: string; label: string }>
  defaultStrategy: string
}>()

const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const selected = ref<Array<string | number>>([])
const perPage = ref(Number(props.filters.per_page) || props.ops.per_page)

const columns = [
  { key: 'plan_number', label: 'Planned Order' },
  { key: 'seq', label: 'Seq', align: 'right' as const },
  { key: 'resource', label: 'Resource' },
  { key: 'window', label: 'Window' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: 'Actions', align: 'right' as const },
]

const resourceLabel = (row: ScheduleOpRow) =>
  row.resource_type ? `${row.resource_type.replace('mes_', '').replace('_', ' ')} #${row.resource_ref_id}` : '—'

const filterStatus = (status: string) => {
  router.get(route('pp.scheduleOps.index'), { ...props.filters, status: status === 'all' ? undefined : status }, { preserveState: true, replace: true })
}

const { confirm } = useConfirm()

const confirmDelete = (row: ScheduleOpRow) => {
  confirm({
    title: `Remove operation on ${row.plan_number}?`,
    variant: 'destructive',
    confirmText: 'Remove',
    onConfirm: () => router.delete(route('pp.scheduleOps.destroy', row.id)),
  })
}

const confirmBulkDelete = () => {
  confirm({
    title: `Remove ${selected.value.length} selected operation(s)?`,
    variant: 'destructive',
    confirmText: 'Remove',
    onConfirm: () =>
      router.delete(route('pp.scheduleOps.bulkDestroy'), {
        data: { ids: selected.value },
        onSuccess: () => { selected.value = [] },
      }),
  })
}

const commit = (id: number) => router.patch(route('pp.scheduleOps.commit', id))
const release = (id: number) => {
  confirm({
    title: 'Release this operation?',
    description: 'Releasing hands the underlying planned order off to execution (§3D). Today this requires MES, which is not built yet, so the release will be rejected until then.',
    confirmText: 'Release',
    onConfirm: () => router.patch(route('pp.scheduleOps.release', id)),
  })
}

// Split
const splitTarget = ref<ScheduleOpRow | null>(null)
const splitForm = useForm({ split_at: '' })
const openSplit = (row: ScheduleOpRow) => {
  splitTarget.value = row
  splitForm.split_at = row.planned_start.slice(0, 16)
}
const submitSplit = () => {
  if (!splitTarget.value) return
  splitForm.post(route('pp.scheduleOps.split', splitTarget.value.id), {
    onSuccess: () => { splitTarget.value = null },
  })
}

// Merge — candidates are same planned order + same resource, on the current page.
const mergeTarget = ref<ScheduleOpRow | null>(null)
const mergeForm = useForm({ target_id: null as number | null })
const mergeCandidates = computed(() => {
  if (!mergeTarget.value) return []
  return props.ops.data
    .filter((o) => o.id !== mergeTarget.value!.id
      && o.plan_number === mergeTarget.value!.plan_number
      && o.resource_type === mergeTarget.value!.resource_type
      && o.resource_ref_id === mergeTarget.value!.resource_ref_id
      && o.status !== 'released')
    .map((o) => ({ value: o.id, label: `#${o.id} · ${o.planned_start} – ${o.planned_end}` }))
})
const openMerge = (row: ScheduleOpRow) => {
  mergeTarget.value = row
  mergeForm.target_id = null
}
const submitMerge = () => {
  if (!mergeTarget.value) return
  mergeForm.post(route('pp.scheduleOps.merge', mergeTarget.value.id), {
    onSuccess: () => { mergeTarget.value = null },
  })
}

// §3I Apply a dispatch strategy to one resource's draft queue.
const showApplyRule = ref(false)
const resourceTypeOptions = [
  { value: 'mes_work_center', label: 'Work Center' },
  { value: 'mes_machine', label: 'Machine' },
  { value: 'mes_station', label: 'Station' },
]
const applyRuleForm = useForm({
  resource_type: 'mes_work_center',
  resource_ref_id: null as number | null,
  strategy: props.defaultStrategy,
})
const submitApplyRule = () => {
  applyRuleForm.post(route('pp.scheduleOps.applyStrategy'), {
    onSuccess: () => { showApplyRule.value = false },
  })
}
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Detailed Scheduling"
      description="Finite-capacity proposal per production order operation — specific resource, specific window (§3H). Draft rows are exploratory and never conflict; committing/releasing checks the same resource's other committed/released windows."
    >
      <template #actions>
        <SecondaryButton @click="showApplyRule = true">Apply Rule</SecondaryButton>
        <PrimaryButton :href="route('pp.scheduleOps.create')">Schedule Operation</PrimaryButton>
      </template>
    </PageHeader>

    <div class="mt-6 flex items-center gap-2">
      <button
        v-for="s in ['draft', 'committed', 'released', 'all']"
        :key="s"
        type="button"
        class="px-3 py-1.5 text-xs font-medium rounded-md transition"
        :class="(filters.status ?? 'all') === s ? 'bg-accent text-accent-text font-semibold' : 'bg-surface-0 border border-border text-ink-600 hover:bg-surface-50'"
        @click="filterStatus(s)"
      >
        {{ s.charAt(0).toUpperCase() + s.slice(1) }}
      </button>
    </div>

    <div class="mt-4">
      <DataTable
        :columns="columns"
        :items="ops.data"
        v-model:sort="sort"
        v-model:selected="selected"
        v-model:per-page="perPage"
        selectable
        sticky-header
        storage-key="pp.scheduleOps"
        :total="ops.total"
        :from="ops.from"
        :to="ops.to"
        :links="ops.links"
        empty-title="No scheduled operations"
        empty-description="Schedule an operation against a production planned order's specific resource and time window."
      >
        <template #bulk-actions>
          <button
            type="button"
            class="text-sm font-medium text-signal-danger hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            @click="confirmBulkDelete"
          >
            Remove selected
          </button>
        </template>
        <template #cell-plan_number="{ item }">
          <div class="text-sm font-medium text-ink-900">{{ (item as ScheduleOpRow).plan_number }}</div>
          <div class="text-xs text-ink-500">{{ (item as ScheduleOpRow).product_label }}</div>
        </template>
        <template #cell-resource="{ item }">
          <span class="text-xs text-ink-700 capitalize">{{ resourceLabel(item as ScheduleOpRow) }}</span>
        </template>
        <template #cell-window="{ item }">
          <span class="font-mono text-xs text-ink-600">{{ (item as ScheduleOpRow).planned_start }} – {{ (item as ScheduleOpRow).planned_end }}</span>
        </template>
        <template #cell-status="{ item }">
          <StatusBadge :status="(item as ScheduleOpRow).status" />
        </template>
        <template #cell-actions="{ item }">
          <div class="flex items-center justify-end gap-3">
            <Link
              v-if="(item as ScheduleOpRow).status !== 'released'"
              :href="route('pp.scheduleOps.edit', item.id)"
              class="text-xs font-medium text-accent hover:underline"
            >
              Edit
            </Link>
            <button
              v-if="(item as ScheduleOpRow).status === 'draft'"
              type="button"
              class="text-xs font-medium text-signal-info hover:underline"
              @click="commit((item as ScheduleOpRow).id)"
            >
              Commit
            </button>
            <button
              v-if="(item as ScheduleOpRow).status === 'committed'"
              type="button"
              class="text-xs font-medium text-signal-success hover:underline"
              @click="release((item as ScheduleOpRow).id)"
            >
              Release
            </button>
            <button
              v-if="(item as ScheduleOpRow).status !== 'released'"
              type="button"
              class="text-xs font-medium text-ink-600 hover:underline"
              @click="openSplit(item as ScheduleOpRow)"
            >
              Split
            </button>
            <button
              v-if="(item as ScheduleOpRow).status !== 'released'"
              type="button"
              class="text-xs font-medium text-ink-600 hover:underline"
              @click="openMerge(item as ScheduleOpRow)"
            >
              Merge
            </button>
            <button
              v-if="(item as ScheduleOpRow).status !== 'released'"
              type="button"
              class="text-xs font-medium text-signal-danger hover:underline"
              @click="confirmDelete(item as ScheduleOpRow)"
            >
              Remove
            </button>
          </div>
        </template>
      </DataTable>
    </div>

    <Modal :show="splitTarget !== null" max-width="sm" @close="splitTarget = null">
      <div class="bg-surface-0 p-6">
        <h2 class="mb-4 font-serif text-lg font-semibold text-ink-900">Split Operation</h2>
        <FormInput v-model="splitForm.split_at" name="split_at" type="datetime-local" label="Split at" :error="splitForm.errors.split_at" required />
        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="splitTarget = null">Cancel</SecondaryButton>
          <PrimaryButton :disabled="splitForm.processing" @click="submitSplit">Split</PrimaryButton>
        </div>
      </div>
    </Modal>

    <Modal :show="mergeTarget !== null" max-width="sm" @close="mergeTarget = null">
      <div class="bg-surface-0 p-6">
        <h2 class="mb-4 font-serif text-lg font-semibold text-ink-900">Merge Operation</h2>
        <p class="mb-4 text-xs text-ink-600">Only operations on the same planned order and resource are offered.</p>
        <FormSelect
          v-model="mergeForm.target_id"
          name="target_id"
          label="Merge into"
          :options="mergeCandidates"
          :error="mergeForm.errors.target_id"
          required
        />
        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="mergeTarget = null">Cancel</SecondaryButton>
          <PrimaryButton :disabled="mergeForm.processing || !mergeForm.target_id" @click="submitMerge">Merge</PrimaryButton>
        </div>
      </div>
    </Modal>
    <Modal :show="showApplyRule" max-width="sm" @close="showApplyRule = false">
      <div class="bg-surface-0 p-6">
        <h2 class="mb-1 font-serif text-lg font-semibold text-ink-900">Apply Scheduling Rule</h2>
        <p class="mb-4 text-xs text-ink-600">
          Reorders one resource's draft queue by the chosen strategy — only sequence changes; committed and released windows are never moved (§3I).
        </p>
        <div class="space-y-4">
          <FormSelect v-model="applyRuleForm.resource_type" name="apply_resource_type" label="Resource type" :options="resourceTypeOptions" :error="applyRuleForm.errors.resource_type" required />
          <FormInput v-model.number="applyRuleForm.resource_ref_id" name="apply_resource_ref_id" type="number" label="Resource #" :error="applyRuleForm.errors.resource_ref_id" required />
          <FormSelect v-model="applyRuleForm.strategy" name="apply_strategy" label="Strategy" :options="strategyOptions" :error="applyRuleForm.errors.strategy" required />
        </div>
        <div class="mt-6 flex justify-end gap-3">
          <SecondaryButton @click="showApplyRule = false">Cancel</SecondaryButton>
          <PrimaryButton :disabled="applyRuleForm.processing || !applyRuleForm.resource_ref_id" @click="submitApplyRule">Apply</PrimaryButton>
        </div>
      </div>
    </Modal>
  </AppLayout>
</template>
