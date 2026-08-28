<!-- ponytail: Achievements log (§3I) — every auto-award and manual award, newest first. -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import Modal from '@/Components/Modal.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable, { type FilterFieldDef, type SortState } from '@/Components/tables/DataTable.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import FormRadioGroup from '@/Components/forms/FormRadioGroup.vue'
import PerformanceSubNav from '@/Components/performance/PerformanceSubNav.vue'
import { debounce } from '@/Composables/debounce'

interface AchievementRow {
  id: number
  badge_name: string | null
  badge_icon: string | null
  subject_label: string
  kpi_name: string | null
  okr_text: string | null
  period_label: string | null
  earned_at_formatted: string | null
  awarded_by_name: string | null
  is_auto: boolean
}

interface PaginatedData<T> {
  data: T[]
  links: Array<{ url: string | null; label: string; active: boolean }>
  total: number
  from: number | null
  to: number | null
  per_page: number
}

interface Option { id: number; name?: string; objective_text?: string; label?: string; full_name?: string; employee_no?: string }

const props = defineProps<{
  achievements: PaginatedData<AchievementRow>
  filters: { badge_id?: string; subject_type?: string; sort?: string; direction?: string; per_page?: string }
  badges: Option[]
  activeBadges: Option[]
  kpis: Option[]
  okrs: Option[]
  periods: Option[]
  orgUnits: Option[]
  employees: Option[]
}>()

const filters = ref({
  badge_id: props.filters.badge_id ?? '',
  subject_type: props.filters.subject_type ?? '',
})
const sort = ref<SortState>(
  props.filters.sort ? { key: props.filters.sort, direction: props.filters.direction === 'desc' ? 'desc' : 'asc' } : null,
)
const perPage = ref(Number(props.filters.per_page) || props.achievements.per_page)

const filterFields: FilterFieldDef[] = [
  { key: 'badge_id', label: 'Badge', type: 'select', options: props.badges.map((b) => ({ label: b.name ?? '', value: String(b.id) })) },
  {
    key: 'subject_type',
    label: 'Subject',
    type: 'select',
    options: [
      { label: 'Company', value: 'company' },
      { label: 'Org Unit', value: 'org_unit' },
      { label: 'Employee', value: 'employee' },
    ],
  },
]

const columns = [
  { key: 'badge_name', label: 'Badge' },
  { key: 'subject_label', label: 'Subject' },
  { key: 'context', label: 'Context' },
  { key: 'earned_at_formatted', label: 'Earned' },
  { key: 'source', label: 'Source' },
]

watch([filters, sort, perPage], debounce(() => {
  router.get(route('performance.achievements.index'), {
    badge_id: filters.value.badge_id || undefined,
    subject_type: filters.value.subject_type || undefined,
    sort: sort.value?.key,
    direction: sort.value?.direction,
    per_page: perPage.value,
  }, { preserveState: true, replace: true })
}, 400))

const showModal = ref(false)

const form = useForm({
  badge_id: null as number | null,
  subject_type: 'company',
  subject_id: null as number | null,
  kpi_id: null as number | null,
  okr_id: null as number | null,
  period_id: null as number | null,
})

const openAward = () => {
  form.reset()
  showModal.value = true
}

const subjectOptions = computed(() => (form.subject_type === 'org_unit' ? props.orgUnits : props.employees))

const submit = () => {
  form
    .transform((data) => ({
      ...data,
      subject_id: data.subject_type === 'company' ? null : data.subject_id,
    }))
    .post(route('performance.achievements.store'), {
      onSuccess: () => { showModal.value = false },
    })
}
</script>

<template>
  <AppLayout>
    <PageHeader title="Achievements" description="Auto-awarded and manually-awarded badges, newest first.">
      <template #actions>
        <PrimaryButton type="button" @click="openAward">Award badge</PrimaryButton>
      </template>
    </PageHeader>

    <PerformanceSubNav active="achievements" class="mt-6" />

    <div class="mt-6">
      <DataTable
        :columns="columns"
        :items="achievements.data"
        v-model:sort="sort"
        v-model:filters="filters"
        v-model:per-page="perPage"
        sticky-header
        storage-key="performance.achievements"
        :filter-fields="filterFields"
        export-filename="performance-achievements"
        :total="achievements.total"
        :from="achievements.from"
        :to="achievements.to"
        :links="achievements.links"
        empty-title="No achievements yet"
        empty-description="Badges will appear here automatically, or award one manually."
      >
        <template #cell-context="{ item }">
          <span class="text-xs text-ink-600">
            {{ (item as AchievementRow).kpi_name ?? (item as AchievementRow).okr_text ?? '—' }}
            <template v-if="(item as AchievementRow).period_label"> · {{ (item as AchievementRow).period_label }}</template>
          </span>
        </template>
        <template #cell-source="{ item }">
          <StatusBadge
            :status="(item as AchievementRow).is_auto ? 'info' : 'success'"
            :label="(item as AchievementRow).is_auto ? 'Auto' : (item as AchievementRow).awarded_by_name ?? 'Manual'"
          />
        </template>
      </DataTable>
    </div>

    <Modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="p-6 bg-surface-0 border border-border text-ink-900 rounded-lg">
        <h3 class="text-lg font-bold text-ink-900">Award Badge</h3>

        <form class="mt-4 space-y-4" @submit.prevent="submit">
          <FormSelect
            v-model="form.badge_id"
            name="badge_id"
            label="Badge"
            :options="activeBadges.map((b) => ({ label: b.name ?? '', value: b.id }))"
            :error="form.errors.badge_id"
            required
          />

          <FormRadioGroup
            v-model="form.subject_type"
            name="subject_type"
            label="Subject"
            inline
            :options="[
              { label: 'Company', value: 'company' },
              { label: 'Org Unit', value: 'org_unit' },
              { label: 'Employee', value: 'employee' },
            ]"
          />

          <FormSelect
            v-if="form.subject_type !== 'company'"
            v-model="form.subject_id"
            name="subject_id"
            :label="form.subject_type === 'org_unit' ? 'Org Unit' : 'Employee'"
            :options="subjectOptions.map((o) => ({ label: o.name ?? o.full_name ?? '', value: o.id }))"
            :error="form.errors.subject_id"
            required
          />

          <FormSelect
            v-model="form.kpi_id"
            name="kpi_id"
            label="Related KPI (optional)"
            :options="kpis.map((k) => ({ label: k.name ?? '', value: k.id }))"
            placeholder="None"
          />

          <FormSelect
            v-model="form.okr_id"
            name="okr_id"
            label="Related OKR (optional)"
            :options="okrs.map((o) => ({ label: o.objective_text ?? '', value: o.id }))"
            placeholder="None"
          />

          <FormSelect
            v-model="form.period_id"
            name="period_id"
            label="Period (optional)"
            :options="periods.map((p) => ({ label: p.label ?? '', value: p.id }))"
            placeholder="None"
          />

          <div class="flex justify-end gap-3 pt-2">
            <SecondaryButton type="button" @click="showModal = false">Cancel</SecondaryButton>
            <PrimaryButton type="submit" :disabled="form.processing">Award</PrimaryButton>
          </div>
        </form>
      </div>
    </Modal>
  </AppLayout>
</template>
