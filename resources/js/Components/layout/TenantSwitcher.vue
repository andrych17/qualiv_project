<!-- ponytail: netapp1-style tenant switcher — top of sidebar -->
<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Building2, Check, ChevronDown } from 'lucide-vue-next'

type TenantOption = { id: string; name: string }

const page = usePage()
const open = ref(false)

const current = computed(() => page.props.currentTenant as TenantOption | null)
const tenants = computed(() => (page.props.tenants as TenantOption[] | undefined) ?? [])
const canSwitch = computed(() => tenants.value.length > 1)

const switchTo = (tenantId: string) => {
  open.value = false
  if (!current.value || tenantId === current.value.id) return
  router.post(route('tenant.switch'), { tenant_id: tenantId }, {
    preserveScroll: false,
  })
}
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="flex w-full items-center gap-2 rounded-md border border-gray-200 bg-white px-3 py-2 text-left text-sm hover:bg-gray-50"
      :disabled="!canSwitch"
      @click="canSwitch && (open = !open)"
    >
      <Building2 class="h-4 w-4 shrink-0 text-gray-500" />
      <span class="min-w-0 flex-1 truncate font-medium text-gray-900">
        {{ current?.name ?? 'No tenant' }}
      </span>
      <ChevronDown v-if="canSwitch" class="h-4 w-4 shrink-0 text-gray-400" />
    </button>

    <div
      v-if="open && canSwitch"
      class="absolute left-0 right-0 z-20 mt-1 overflow-hidden rounded-md border border-gray-200 bg-white shadow-lg"
    >
      <button
        v-for="t in tenants"
        :key="t.id"
        type="button"
        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-gray-50"
        @click="switchTo(t.id)"
      >
        <span class="min-w-0 flex-1 truncate" :class="t.id === current?.id ? 'font-semibold text-gray-900' : 'text-gray-700'">
          {{ t.name }}
        </span>
        <Check v-if="t.id === current?.id" class="h-4 w-4 text-gray-900" />
      </button>
    </div>
  </div>
</template>
