<!-- ponytail: Group edit — profile + CRUD rights matrix + member assign -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import { computed } from 'vue'

interface RightRow {
  menu_id: number
  code: string
  label: string
  header: string | null
  seq: number
  create: boolean
  read: boolean
  update: boolean
  delete: boolean
}

interface UserOption {
  id: number
  name: string
  email: string
}

const props = defineProps<{
  group: {
    id: number
    code: string
    descr: string | null
    status_code: string
  }
  accessMenus: RightRow[]
  users: UserOption[]
  user_ids: number[]
}>()

const form = useForm({
  code: props.group.code,
  descr: props.group.descr ?? '',
  status_code: props.group.status_code,
  rights: props.accessMenus.map((m) => ({
    menu_id: m.menu_id,
    seq: m.seq,
    create: m.create,
    read: m.read,
    update: m.update,
    delete: m.delete,
  })),
  user_ids: [...props.user_ids] as number[],
})

const menuMeta = computed(() =>
  Object.fromEntries(props.accessMenus.map((m) => [m.menu_id, m])),
)

const toggleUser = (userId: number) => {
  const idx = form.user_ids.indexOf(userId)
  if (idx >= 0) {
    form.user_ids.splice(idx, 1)
  } else {
    form.user_ids.push(userId)
  }
}

const setAll = (flag: 'create' | 'read' | 'update' | 'delete', value: boolean) => {
  form.rights.forEach((r) => {
    r[flag] = value
  })
}

const submit = () => form.put(route('config.groups.update', props.group.id))
</script>

<template>
  <AppLayout>
    <PageHeader
      title="Edit Group"
      :description="`Access for ${group.code}`"
    />

    <form class="mt-6 space-y-6" @submit.prevent="submit">
      <div class="max-w-2xl">
        <Panel title="Profile">
          <div class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormInput
                v-model="form.code"
                name="code"
                label="Code"
                :error="form.errors.code"
                required
              />
              <FormSelect
                v-model="form.status_code"
                name="status_code"
                label="Status"
                :options="[
                  { label: 'Active', value: 'A' },
                  { label: 'Inactive', value: 'I' },
                ]"
                :error="form.errors.status_code"
                required
              />
            </div>
            <FormInput
              v-model="form.descr"
              name="descr"
              label="Description"
              :error="form.errors.descr"
            />
          </div>
        </Panel>
      </div>

      <Panel title="Menu access (CRUD)" subtitle="Updating sequence here will update global menu order.">
        <template #actions>
          <div class="flex flex-wrap gap-1.5 text-xs">
            <button type="button" class="rounded border border-border bg-surface-0 px-2 py-1 text-ink-900 hover:bg-surface-50 cursor-pointer" @click="setAll('read', true)">All R</button>
            <button type="button" class="rounded border border-border bg-surface-0 px-2 py-1 text-ink-900 hover:bg-surface-50 cursor-pointer" @click="setAll('create', true)">All C</button>
            <button type="button" class="rounded border border-border bg-surface-0 px-2 py-1 text-ink-900 hover:bg-surface-50 cursor-pointer" @click="setAll('update', true)">All U</button>
            <button type="button" class="rounded border border-border bg-surface-0 px-2 py-1 text-ink-900 hover:bg-surface-50 cursor-pointer" @click="setAll('delete', true)">All D</button>
            <button type="button" class="rounded border border-signal-danger/30 bg-surface-0 px-2 py-1 text-signal-danger hover:bg-signal-danger/10 cursor-pointer" @click="form.rights.forEach(r => { r.create=r.read=r.update=r.delete=false })">Clear</button>
          </div>
        </template>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-ink-600">
                <th class="py-2.5 pr-4 font-semibold">Menu</th>
                <th class="py-2.5 px-2 font-semibold text-center w-24">Seq</th>
                <th class="py-2.5 px-2 font-semibold text-center">C</th>
                <th class="py-2.5 px-2 font-semibold text-center">R</th>
                <th class="py-2.5 px-2 font-semibold text-center">U</th>
                <th class="py-2.5 px-2 font-semibold text-center">D</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr
                v-for="right in form.rights"
                :key="right.menu_id"
                class="hover:bg-surface-50 transition-colors"
              >
                <td class="py-2.5 pr-4">
                  <div class="font-medium text-ink-900">{{ menuMeta[right.menu_id]?.label }}</div>
                  <div class="text-xs text-ink-600">
                    {{ menuMeta[right.menu_id]?.header }} · {{ menuMeta[right.menu_id]?.code }}
                  </div>
                </td>
                <td class="py-2.5 px-2 text-center">
                  <input
                    v-model.number="right.seq"
                    type="number"
                    min="0"
                    step="1"
                    class="w-20 rounded-md border border-border bg-surface-0 text-ink-900 text-center text-xs py-1 px-2 focus:border-accent focus:ring-accent/20 outline-none"
                  />
                </td>
                <td class="py-2.5 px-2 text-center">
                  <input v-model="right.create" type="checkbox" class="rounded border-border text-accent focus:ring-accent/20 cursor-pointer" />
                </td>
                <td class="py-2.5 px-2 text-center">
                  <input v-model="right.read" type="checkbox" class="rounded border-border text-accent focus:ring-accent/20 cursor-pointer" />
                </td>
                <td class="py-2.5 px-2 text-center">
                  <input v-model="right.update" type="checkbox" class="rounded border-border text-accent focus:ring-accent/20 cursor-pointer" />
                </td>
                <td class="py-2.5 px-2 text-center">
                  <input v-model="right.delete" type="checkbox" class="rounded border-border text-accent focus:ring-accent/20 cursor-pointer" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="form.errors.rights" class="mt-2 text-sm text-signal-danger">{{ form.errors.rights }}</p>
      </Panel>

      <div class="max-w-2xl">
        <Panel title="Members">
          <ul class="space-y-2">
            <li
              v-for="user in users"
              :key="user.id"
              class="flex items-center gap-3 rounded-md border border-border bg-surface-0 px-3 py-2"
            >
              <input
                type="checkbox"
                class="rounded border-border text-accent focus:ring-accent/20 cursor-pointer"
                :checked="form.user_ids.includes(user.id)"
                @change="toggleUser(user.id)"
              />
              <div>
                <div class="text-sm font-medium text-ink-900">{{ user.name }}</div>
                <div class="text-xs text-ink-600">{{ user.email }}</div>
              </div>
            </li>
          </ul>
          <p v-if="users.length === 0" class="text-sm text-ink-600">No users in this tenant.</p>
        </Panel>
      </div>

      <div class="flex items-center justify-end gap-3 pt-2">
        <SecondaryButton :href="route('config.groups.index')">
          Cancel
        </SecondaryButton>
        <PrimaryButton
          type="submit"
          :disabled="form.processing"
        >
          Save Group
        </PrimaryButton>
      </div>
    </form>
  </AppLayout>
</template>
