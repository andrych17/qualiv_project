<!-- ponytail: Profile Settings page using AppLayout, PageHeader, and Panel cards -->
<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue'
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue'
import DeleteUserForm from './Partials/DeleteUserForm.vue'
import { User, KeyRound, ShieldAlert, Building2, Mail, AlertTriangle } from 'lucide-vue-next'

defineProps<{
  mustVerifyEmail?: boolean
  status?: string
}>()

const page = usePage()
const user = page.props.auth.user
const currentTenant = page.props.currentTenant as { id: string; name: string; plan: string } | null
</script>

<template>
  <Head title="Pengaturan Profil" />

  <AppLayout>
    <PageHeader
      title="Pengaturan Profil"
      description="Kelola informasi pribadi, ubah kata sandi, dan keamanan akun Anda."
    />

    <div class="mt-6 space-y-6 max-w-5xl">
      <div
        v-if="user?.must_change_password"
        class="flex items-start gap-3 rounded-md border border-signal-warning/25 bg-signal-warning/5 p-4"
      >
        <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-signal-warning" />
        <p class="text-sm text-ink-900">
          Kata sandi Anda dibuat oleh admin. Silakan atur kata sandi baru sebelum melanjutkan — halaman lain tidak dapat diakses hingga kata sandi diubah.
        </p>
      </div>

      <!-- User Summary Card -->
      <div class="rounded-md border border-border bg-surface-0 p-6 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div class="flex items-center gap-4">
          <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-900 text-xl font-bold text-white shadow-md border-2 border-border shrink-0">
            {{ user?.name ? user.name.charAt(0).toUpperCase() : 'U' }}
          </div>
          <div class="space-y-1">
            <h3 class="text-lg font-semibold text-ink-900 flex items-center gap-2">
              <span>{{ user?.name }}</span>
              <StatusBadge status="active" label="Aktif" />
            </h3>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-600">
              <span class="flex items-center gap-1.5">
                <Mail class="h-3.5 w-3.5" />
                {{ user?.email }}
              </span>
              <span v-if="currentTenant" class="flex items-center gap-1.5">
                <Building2 class="h-3.5 w-3.5" />
                Perusahaan: <strong class="text-ink-900">{{ currentTenant.name }}</strong>
              </span>
            </div>
          </div>
        </div>

        <div v-if="currentTenant" class="rounded-lg bg-surface-50 border border-border px-4 py-3 text-right shrink-0">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-ink-600">Paket Langganan</p>
          <p class="text-sm font-bold text-accent uppercase mt-0.5">{{ currentTenant.plan || 'Starter' }}</p>
        </div>
      </div>

      <!-- Grid for Profile & Security forms -->
      <div class="grid gap-6 md:grid-cols-2">
        <!-- Profile Info Panel -->
        <Panel title="Informasi Profil" subtitle="Perbarui nama dan alamat email akun Anda.">
          <template #actions>
            <User class="h-4 w-4 text-ink-600" />
          </template>

          <UpdateProfileInformationForm
            :must-verify-email="mustVerifyEmail"
            :status="status"
          />
        </Panel>

        <!-- Change Password Panel -->
        <Panel title="Ubah Kata Sandi" subtitle="Pastikan akun Anda menggunakan kata sandi yang aman.">
          <template #actions>
            <KeyRound class="h-4 w-4 text-ink-600" />
          </template>

          <UpdatePasswordForm />
        </Panel>
      </div>

      <!-- Delete Account Panel -->
      <Panel title="Hapus Akun" subtitle="Tindakan permanen penghapusan akun Anda.">
        <template #actions>
          <ShieldAlert class="h-4 w-4 text-signal-danger" />
        </template>

        <DeleteUserForm />
      </Panel>
    </div>
  </AppLayout>
</template>

