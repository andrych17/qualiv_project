<!-- ponytail: Group edit — profile + CRUD rights matrix + member assign -->
<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
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
      <div class="max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
        <h2 class="text-sm font-semibold text-gray-900">Profile</h2>
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

      <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="text-sm font-semibold text-gray-900">Menu access (CRUD)</h2>
            <p class="text-xs text-gray-500">Updating sequence here will update global menu order.</p>
          </div>
          <div class="flex flex-wrap gap-2 text-xs">
            <button type="button" class="rounded border px-2 py-1 hover:bg-gray-50" @click="setAll('read', true)">All R</button>
            <button type="button" class="rounded border px-2 py-1 hover:bg-gray-50" @click="setAll('create', true)">All C</button>
            <button type="button" class="rounded border px-2 py-1 hover:bg-gray-50" @click="setAll('update', true)">All U</button>
            <button type="button" class="rounded border px-2 py-1 hover:bg-gray-50" @click="setAll('delete', true)">All D</button>
            <button type="button" class="rounded border px-2 py-1 text-red-700 hover:bg-red-50" @click="form.rights.forEach(r => { r.create=r.read=r.update=r.delete=false })">Clear</button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b text-left text-xs uppercase tracking-wide text-gray-500">
                <th class="py-2 pr-4 font-semibold">Menu</th>
                <th class="py-2 px-2 font-semibold text-center w-24">Seq</th>
                <th class="py-2 px-2 font-semibold text-center">C</th>
                <th class="py-2 px-2 font-semibold text-center">R</th>
                <th class="py-2 px-2 font-semibold text-center">U</th>
                <th class="py-2 px-2 font-semibold text-center">D</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="right in form.rights"
                :key="right.menu_id"
                class="border-b border-gray-100"
              >
                <td class="py-2 pr-4">
                  <div class="font-medium text-gray-900">{{ menuMeta[right.menu_id]?.label }}</div>
                  <div class="text-xs text-gray-500">
                    {{ menuMeta[right.menu_id]?.header }} · {{ menuMeta[right.menu_id]?.code }}
                  </div>
                </td>
                <td class="py-2 px-2 text-center">
                  <input
                    v-model.number="right.seq"
                    type="number"
                    min="0"
                    step="1"
                    class="w-20 rounded-md border-gray-300 text-center text-xs py-1 px-2 focus:border-gray-900 focus:ring-gray-900"
                  />
                </td>
                <td class="py-2 px-2 text-center">
                  <input v-model="right.create" type="checkbox" class="rounded border-gray-300" />
                </td>
                <td class="py-2 px-2 text-center">
                  <input v-model="right.read" type="checkbox" class="rounded border-gray-300" />
                </td>
                <td class="py-2 px-2 text-center">
                  <input v-model="right.update" type="checkbox" class="rounded border-gray-300" />
                </td>
                <td class="py-2 px-2 text-center">
                  <input v-model="right.delete" type="checkbox" class="rounded border-gray-300" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="form.errors.rights" class="text-sm text-red-600">{{ form.errors.rights }}</p>
      </div>

      <div class="max-w-2xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
        <h2 class="text-sm font-semibold text-gray-900">Members</h2>
        <ul class="space-y-2">
          <li
            v-for="user in users"
            :key="user.id"
            class="flex items-center gap-3 rounded-md border border-gray-100 px-3 py-2"
          >
            <input
              type="checkbox"
              class="rounded border-gray-300"
              :checked="form.user_ids.includes(user.id)"
              @change="toggleUser(user.id)"
            />
            <div>
              <div class="text-sm font-medium text-gray-900">{{ user.name }}</div>
              <div class="text-xs text-gray-500">{{ user.email }}</div>
            </div>
          </li>
        </ul>
        <p v-if="users.length === 0" class="text-sm text-gray-400">No users in this tenant.</p>
      </div>

      <div class="flex items-center justify-end gap-3">
        <Link :href="route('config.groups.index')" class="text-sm font-semibold text-gray-900">
          Cancel
        </Link>
        <button
          type="submit"
          :disabled="form.processing"
          class="rounded-md bg-gray-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-800 disabled:opacity-50"
        >
          Save Group
        </button>
      </div>
    </form>
  </AppLayout>
</template>
