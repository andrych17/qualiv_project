<!-- ponytail: Batch / Phase UI (MES_SPECS.md §3I) -->
<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { Check, AlertTriangle } from 'lucide-vue-next'
import ShopFloorLayout from '@/Components/layout/ShopFloorLayout.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormNumberInput from '@/Components/forms/FormNumberInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import { formatNumber } from '@/Utils/formatters'

interface ParameterDef {
  id: number
  parameter_code: string
  target_value: number | null
  min_value: number | null
  max_value: number | null
  uom_code: string | null
}

interface ReadingRow {
  process_parameter_id: number
  value: number
  recorded_at: string | null
}

interface PhaseRow {
  id: number
  seq: number
  phase_name: string | null
  status: string
  start_at: string | null
  end_at: string | null
  is_last: boolean
  parameters: ParameterDef[]
  readings: ReadingRow[]
}

const props = defineProps<{
  order: {
    id: number
    order_number: string
    product: { sku: string; name: string } | null
    qty: number
    uom_code: string | null
    status: string
    recipe_batch_size: number | null
    warehouse_name: string | null
  }
  batch: {
    id: number
    batch_number: string
    status: string
    planned_qty: number
    actual_yield_pct: number | null
    ingredients: Array<{ sku: string | null; name: string | null; resolved_qty: number; uom_code: string | null }>
    phases: PhaseRow[]
  } | null
  currentPhaseId: number | null
  locations: Array<{ value: number; label: string }>
}>()

// Create Batch form
const createForm = useForm({
  planned_qty: props.order.qty,
})
const submitCreate = () => createForm.post(route('mes.shopFloor.batch.store', props.order.id), { preserveScroll: true })

const currentPhase = computed(() => props.batch?.phases.find((p) => p.id === props.currentPhaseId) ?? null)

const start = () => router.post(route('mes.shopFloor.batch.start', props.order.id), {}, { preserveScroll: true })
const pause = () => router.post(route('mes.shopFloor.batch.pause', props.order.id), {}, { preserveScroll: true })
const resume = () => router.post(route('mes.shopFloor.batch.resume', props.order.id), {}, { preserveScroll: true })

// Reading values keyed by process_parameter_id, seeded from the phase's own parameter list
const readingValues = ref<Record<number, number | null>>({})
const seedReadings = () => {
  readingValues.value = {}
  for (const p of currentPhase.value?.parameters ?? []) {
    readingValues.value[p.id] = p.target_value
  }
}
seedReadings()

const completeForm = useForm({
  readings: [] as Array<{ process_parameter_id: number; value: number }>,
  location_id: null as number | null,
})

const isOutOfRange = (param: ParameterDef, value: number | null): boolean => {
  if (value === null) return false
  if (param.min_value !== null && value < param.min_value) return true
  if (param.max_value !== null && value > param.max_value) return true
  return false
}

const submitCompletePhase = () => {
  completeForm.readings = (currentPhase.value?.parameters ?? [])
    .filter((p) => readingValues.value[p.id] !== null)
    .map((p) => ({ process_parameter_id: p.id, value: readingValues.value[p.id] as number }))

  completeForm.post(route('mes.shopFloor.batch.completePhase', props.order.id), {
    preserveScroll: true,
    onSuccess: () => seedReadings(),
  })
}

// Elapsed timer, ticking client-side off the current phase's start_at
const now = ref(Date.now())
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => { timer = setInterval(() => { now.value = Date.now() }, 1000) })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })

const elapsed = computed(() => {
  const start = currentPhase.value?.start_at
  if (!start) return '—'
  const diffMs = now.value - new Date(start.replace(' ', 'T')).getTime()
  const totalSeconds = Math.max(0, Math.floor(diffMs / 1000))
  const mm = Math.floor(totalSeconds / 60).toString().padStart(2, '0')
  const ss = (totalSeconds % 60).toString().padStart(2, '0')
  return `${mm}:${ss}`
})
</script>

<template>
  <ShopFloorLayout :exit-href="route('mes.prodOrders.show', order.id)" :title="`${order.order_number} — Batch`">
    <div class="mx-auto max-w-3xl space-y-6">
      <Panel>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">Order</div>
            <div class="mt-1 font-mono text-lg font-bold text-ink-900">{{ order.order_number }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">Product</div>
            <div class="mt-1 text-lg font-semibold text-ink-900">{{ order.product?.sku }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">Target</div>
            <div class="mt-1 font-mono text-lg font-bold text-ink-900">{{ formatNumber(order.qty) }} {{ order.uom_code }}</div>
          </div>
          <div>
            <div class="text-xs font-semibold uppercase tracking-wide text-ink-500">Status</div>
            <div class="mt-1"><StatusBadge :status="order.status" /></div>
          </div>
        </div>
      </Panel>

      <Panel v-if="!batch" title="No batch yet">
        <p class="text-sm text-ink-600">This recipe's batch size is {{ order.recipe_batch_size }} {{ order.uom_code }} — a batch is scaled to whatever planned quantity you enter here.</p>
        <form class="mt-4 flex items-end gap-3" @submit.prevent="submitCreate">
          <FormNumberInput v-model="createForm.planned_qty" name="planned_qty" label="Planned Quantity" :decimals="4" :error="createForm.errors.planned_qty" class="w-48" />
          <PrimaryButton type="submit" :disabled="createForm.processing">Create Batch</PrimaryButton>
        </form>
      </Panel>

      <template v-else>
        <Panel :title="`BATCH ${batch.batch_number}`">
          <template #actions>
            <StatusBadge :status="batch.status" />
          </template>

          <div v-if="batch.status === 'draft'">
            <PrimaryButton class="py-4 text-base" @click="start">START BATCH</PrimaryButton>
          </div>

          <div v-else-if="currentPhase" class="space-y-4">
            <div>
              <p class="text-lg font-semibold text-ink-900">{{ currentPhase.phase_name }}</p>
              <p class="text-sm text-ink-600">Elapsed {{ elapsed }}</p>
            </div>

            <div class="space-y-2">
              <div
                v-for="param in currentPhase.parameters"
                :key="param.id"
                class="flex items-center gap-3 rounded-md border border-border p-3"
              >
                <div class="w-32 text-sm font-medium text-ink-900">{{ param.parameter_code }}</div>
                <FormNumberInput
                  v-model="readingValues[param.id]"
                  :name="`reading_${param.id}`"
                  :decimals="4"
                  :suffix="param.uom_code ?? ''"
                  class="w-40"
                  :disabled="currentPhase.status === 'completed'"
                />
                <span class="text-xs text-ink-500">target {{ param.target_value }} [{{ param.min_value }}–{{ param.max_value }}]</span>
                <Check v-if="!isOutOfRange(param, readingValues[param.id])" class="h-4 w-4 text-signal-success" />
                <AlertTriangle v-else class="h-4 w-4 text-signal-warning" />
              </div>
            </div>

            <FormSelect
              v-if="currentPhase.is_last"
              v-model="completeForm.location_id"
              name="location_id"
              label="Finished Output Location"
              :options="locations"
              :error="completeForm.errors.location_id"
              required
            />

            <div class="flex gap-3">
              <SecondaryButton v-if="currentPhase.status === 'running'" class="py-4 text-base" @click="pause">PAUSE</SecondaryButton>
              <PrimaryButton v-if="currentPhase.status === 'paused'" class="py-4 text-base" @click="resume">RESUME</PrimaryButton>
              <PrimaryButton class="bg-signal-success py-4 text-base hover:bg-signal-success/90" :disabled="completeForm.processing" @click="submitCompletePhase">
                COMPLETE PHASE{{ currentPhase.is_last ? ' (finishes batch)' : '' }}
              </PrimaryButton>
            </div>
          </div>

          <div v-else class="text-sm text-ink-600">
            Batch complete — all phases done.
            <span v-if="batch.actual_yield_pct !== null"> Yield: {{ batch.actual_yield_pct }}% (§3N).</span>
          </div>
        </Panel>

        <Panel title="Phases">
          <div class="space-y-2">
            <div v-for="p in batch.phases" :key="p.id" class="flex items-center justify-between rounded-md border border-border p-3">
              <span class="text-sm font-medium text-ink-900">{{ p.seq }} — {{ p.phase_name }}</span>
              <StatusBadge :status="p.status" />
            </div>
          </div>
        </Panel>

        <Panel title="Resolved Ingredients (scaled from the recipe)">
          <div class="space-y-1.5 text-sm">
            <div v-for="(i, idx) in batch.ingredients" :key="i.sku ?? idx" class="flex justify-between">
              <span class="text-ink-900">{{ i.sku }} — {{ i.name }}</span>
              <span class="font-mono text-ink-600">{{ formatNumber(i.resolved_qty) }} {{ i.uom_code }}</span>
            </div>
          </div>
        </Panel>
      </template>
    </div>
  </ShopFloorLayout>
</template>
