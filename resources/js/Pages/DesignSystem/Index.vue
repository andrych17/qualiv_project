<!-- ponytail: Single-page documentation & showcase for all NusaEvo ERP UI components -->
<script setup lang="ts">
import { ref } from 'vue'
import AppLayout from '@/Components/layout/AppLayout.vue'
import PageHeader from '@/Components/layout/PageHeader.vue'
import Panel from '@/Components/cards/Panel.vue'
import StatCard from '@/Components/cards/StatCard.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import FormInput from '@/Components/forms/FormInput.vue'
import FormSelect from '@/Components/forms/FormSelect.vue'
import SearchInput from '@/Components/filters/SearchInput.vue'
import Checkbox from '@/Components/Checkbox.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import CustomFieldInputs, { type CustomFieldDef } from '@/Components/forms/CustomFieldInputs.vue'
import StatusBadge from '@/Components/feedback/StatusBadge.vue'
import DataTable from '@/Components/tables/DataTable.vue'
import DataTablePagination from '@/Components/tables/DataTablePagination.vue'
import ApplicationLogo from '@/Components/ApplicationLogo.vue'
import Dropdown from '@/Components/Dropdown.vue'
import DropdownLink from '@/Components/DropdownLink.vue'
import { showToast } from '@/Composables/useFlashToast'
import { useConfirm } from '@/Composables/useConfirmDialog'
import { Palette, Layers, MousePointerClick, FormInput as FormIcon, Activity, Table, Layout, Sparkles } from 'lucide-vue-next'

const { confirm } = useConfirm()

// Interactive state demo models
const searchDemo = ref('')
const inputDemo = ref('PT NusaEvo Indonesia')
const inputErrorDemo = ref('Format email tidak valid')
const selectDemo = ref('legal')
const checkboxDemo = ref(true)
const customFieldsModel = ref<Record<string, string>>({
  court_name: 'Pengadilan Negeri Jakarta Selatan',
  hearing_date: '2026-08-15',
  case_category: 'civil',
})

const customFieldDefs = ref<CustomFieldDef[]>([
  {
    code: 'court_name',
    label: 'Nama Pengadilan (Custom Field)',
    field_type: 'text',
    options: null,
    is_required: true,
    seq: 1,
    value: null,
  },
  {
    code: 'hearing_date',
    label: 'Tanggal Sidang Perdana',
    field_type: 'date',
    options: null,
    is_required: false,
    seq: 2,
    value: null,
  },
  {
    code: 'case_category',
    label: 'Kategori Kasus Hukum',
    field_type: 'select',
    options: [
      { label: 'Perdata Umum', value: 'civil' },
      { label: 'Pidana Khusus', value: 'criminal' },
      { label: 'Sengketa Niaga', value: 'commercial' },
    ],
    is_required: true,
    seq: 3,
    value: null,
  },
])

// Table demo data
const tableColumns = [
  { key: 'case_number', label: 'Nomor Perkara', align: 'left' as const },
  { key: 'client', label: 'Klien', align: 'left' as const },
  { key: 'type', label: 'Tipe Layanan', align: 'left' as const },
  { key: 'status', label: 'Status Perkara', align: 'center' as const },
  { key: 'amount', label: 'Nilai Klaim', align: 'right' as const },
]

const tableItems = [
  { id: 1, case_number: 'LEGAL-2026-001', client: 'PT Maju Bersama', type: 'Sengketa Kontrak', status: 'active', statusRail: 'active', amount: 'Rp 450.000.000' },
  { id: 2, case_number: 'LEGAL-2026-002', client: 'Firma Hukum Prima', type: 'Konsultasi HAKI', status: 'pending', statusRail: 'pending', amount: 'Rp 125.000.000' },
  { id: 3, case_number: 'LEGAL-2026-003', client: 'Budi Santoso & Partners', type: 'Arbitrase Niaga', status: 'open', statusRail: 'open', amount: 'Rp 890.000.000' },
  { id: 4, case_number: 'LEGAL-2026-004', client: 'CV Global Perkasa', type: 'Audit Kepatuhan', status: 'overdue', statusRail: 'overdue', amount: 'Rp 75.000.000' },
  { id: 5, case_number: 'LEGAL-2026-005', client: 'Lembaga Keuangan Mandiri', type: 'Restrukturisasi', status: 'completed', statusRail: 'completed', amount: 'Rp 1.200.000.000' },
]

const paginationLinks = [
  { url: null, label: '&laquo; Previous', active: false },
  { url: '#', label: '1', active: true },
  { url: '#', label: '2', active: false },
  { url: '#', label: '3', active: false },
  { url: '#', label: 'Next &raquo;', active: false },
]

const triggerToastDemo = (type: 'success' | 'error') => {
  if (type === 'success') {
    showToast('Aksi berhasil dilakukan pada NusaEvo ERP!', 'success')
  } else {
    showToast('Terjadi kesalahan pada validasi data.', 'error')
  }
}

const triggerConfirmDemo = (variant: 'default' | 'destructive') => {
  confirm({
    title: variant === 'destructive' ? 'Hapus Berkas Perkara?' : 'Konfirmasi Penyimpanan',
    description: variant === 'destructive'
      ? 'Apakah Anda yakin ingin menghapus berkas perkara ini? Tindakan ini tidak dapat dibatalkan.'
      : 'Simpan perubahan konfigurasi modul pada tenant saat ini?',
    confirmText: variant === 'destructive' ? 'Ya, Hapus Data' : 'Ya, Simpan',
    cancelText: 'Batal',
    variant,
    onConfirm: () => {
      showToast(variant === 'destructive' ? 'Data berhasil dihapus' : 'Perubahan berhasil disimpan', 'success')
    },
  })
}
</script>

<template>
  <AppLayout>
    <div class="space-y-8 pb-16">
      <!-- Header Halaman Utama -->
      <PageHeader
        title="Katalog Komponen UI NusaEvo ERP"
        subtitle="Katalog dan penjelasan lengkap seluruh komponen UI standar NusaEvo ERP per pedoman DESIGN.md."
      >
        <template #actions>
          <PrimaryButton @click="triggerToastDemo('success')">
            <Sparkles class="mr-1.5 h-4 w-4" />
            Uji Notifikasi Toast
          </PrimaryButton>
        </template>
      </PageHeader>

      <!-- Navigasi Ringkas Seksi Komponen -->
      <div class="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-surface-0 p-3 shadow-sm text-xs font-medium">
        <span class="text-ink-600 font-semibold px-2">Lompat ke Seksi:</span>
        <a href="#tokens" class="rounded bg-surface-50 px-2.5 py-1 text-ink-900 hover:bg-accent/10 hover:text-accent transition">Tokens & Warna</a>
        <a href="#buttons" class="rounded bg-surface-50 px-2.5 py-1 text-ink-900 hover:bg-accent/10 hover:text-accent transition">Tombol & Aksi</a>
        <a href="#inputs" class="rounded bg-surface-50 px-2.5 py-1 text-ink-900 hover:bg-accent/10 hover:text-accent transition">Input & Form</a>
        <a href="#feedback" class="rounded bg-surface-50 px-2.5 py-1 text-ink-900 hover:bg-accent/10 hover:text-accent transition">Status & Feedback</a>
        <a href="#cards" class="rounded bg-surface-50 px-2.5 py-1 text-ink-900 hover:bg-accent/10 hover:text-accent transition">Kartu & Panel</a>
        <a href="#tables" class="rounded bg-surface-50 px-2.5 py-1 text-ink-900 hover:bg-accent/10 hover:text-accent transition">Tabel Data</a>
        <a href="#layout" class="rounded bg-surface-50 px-2.5 py-1 text-ink-900 hover:bg-accent/10 hover:text-accent transition">Layout & Merek</a>
      </div>

      <!-- SEKSI 1: DESIGN TOKENS & MOTIF -->
      <section id="tokens" class="scroll-mt-6 space-y-4">
        <div class="flex items-center gap-2 text-ink-900">
          <Palette class="h-5 w-5 text-accent" />
          <h2 class="font-serif text-xl font-bold">1. Design Tokens & Theme Philosophy ("Ink & Signal")</h2>
        </div>
        <Panel subtitle="Pedoman palet warna netral presisi tinggi dan tipografi profesional untuk ERP industri legal/enterprise.">
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Ink Colors -->
            <div class="space-y-2 rounded-md border border-border p-3">
              <p class="text-xs font-bold uppercase tracking-wide text-ink-600">Base Ink & Surface</p>
              <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded bg-[#12181F] border border-border shadow-inner"></div>
                <div>
                  <p class="text-xs font-mono font-semibold">--color-ink-900 (#12181F)</p>
                  <p class="text-[11px] text-ink-600">Teks Utama & Heading</p>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded bg-[#4A5563] border border-border shadow-inner"></div>
                <div>
                  <p class="text-xs font-mono font-semibold">--color-ink-600 (#4A5563)</p>
                  <p class="text-[11px] text-ink-600">Teks Sekunder & Label</p>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded bg-[#F4F6F8] border border-border shadow-inner"></div>
                <div>
                  <p class="text-xs font-mono font-semibold">--color-surface-50 (#F4F6F8)</p>
                  <p class="text-[11px] text-ink-600">Latar Belakang Aplikasi</p>
                </div>
              </div>
            </div>

            <!-- Accent & Signals -->
            <div class="space-y-2 rounded-md border border-border p-3">
              <p class="text-xs font-bold uppercase tracking-wide text-ink-600">Action & Signals</p>
              <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded bg-[#1F5FBF]"></div>
                <div>
                  <p class="text-xs font-mono font-semibold">--color-accent (#1F5FBF)</p>
                  <p class="text-[11px] text-ink-600">Primary Action Blue</p>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded bg-[#1E7F5C]"></div>
                <div>
                  <p class="text-xs font-mono font-semibold">--color-signal-success (#1E7F5C)</p>
                  <p class="text-[11px] text-ink-600">Selesai / Aktif / Disetujui</p>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded bg-[#B5760A]"></div>
                <div>
                  <p class="text-xs font-mono font-semibold">--color-signal-warning (#B5760A)</p>
                  <p class="text-[11px] text-ink-600">Menunggu / Segera Jatuh Tempo</p>
                </div>
              </div>
            </div>

            <div class="space-y-2 rounded-md border border-border p-3">
              <p class="text-xs font-bold uppercase tracking-wide text-ink-600">Alert & Info Signals</p>
              <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded bg-[#C1332B]"></div>
                <div>
                  <p class="text-xs font-mono font-semibold">--color-signal-danger (#C1332B)</p>
                  <p class="text-[11px] text-ink-600">Terlambat / Terblokir / Error</p>
                </div>
              </div>
              <div class="flex items-center gap-3">
                <div class="h-8 w-8 rounded bg-[#5B4FCF]"></div>
                <div>
                  <p class="text-xs font-mono font-semibold">--color-signal-info (#5B4FCF)</p>
                  <p class="text-[11px] text-ink-600">Notifikasi Sistem Otomatis</p>
                </div>
              </div>
            </div>

            <!-- Signature Motif Status Rail -->
            <div class="space-y-2 rounded-md border border-border p-3 bg-surface-50">
              <p class="text-xs font-bold uppercase tracking-wide text-ink-600">Signature Element: Status Rail</p>
              <p class="text-xs text-ink-600">Garis vertikal 3px pada tepi kiri kartu/baris untuk scannability instan:</p>
              <div class="space-y-1 text-xs font-medium">
                <div class="border-l-[3px] border-l-signal-success bg-white px-2 py-1 rounded-r">Active Rail</div>
                <div class="border-l-[3px] border-l-signal-warning bg-white px-2 py-1 rounded-r">Pending Rail</div>
                <div class="border-l-[3px] border-l-signal-danger bg-white px-2 py-1 rounded-r">Overdue Rail</div>
              </div>
            </div>
          </div>
        </Panel>
      </section>

      <!-- SEKSI 2: BUTTONS & ACTIONS -->
      <section id="buttons" class="scroll-mt-6 space-y-4">
        <div class="flex items-center gap-2 text-ink-900">
          <MousePointerClick class="h-5 w-5 text-accent" />
          <h2 class="font-serif text-xl font-bold">2. Buttons & Actions</h2>
        </div>
        <Panel subtitle="Komponen tombol standar untuk hierarki aksi user.">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Primary Button -->
            <div class="space-y-3 rounded-md border border-border p-4">
              <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold">PrimaryButton</h3>
                <span class="font-mono text-[10px] text-ink-600">PrimaryButton.vue</span>
              </div>
              <p class="text-xs text-ink-600">Digunakan untuk aksi utama halaman (Simpan, Buat Baru, Submit Form).</p>
              <div class="flex items-center gap-3">
                <PrimaryButton>Simpan Perkara</PrimaryButton>
                <PrimaryButton disabled>Disabled</PrimaryButton>
              </div>
            </div>

            <!-- Secondary Button -->
            <div class="space-y-3 rounded-md border border-border p-4">
              <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold">SecondaryButton</h3>
                <span class="font-mono text-[10px] text-ink-600">SecondaryButton.vue</span>
              </div>
              <p class="text-xs text-ink-600">Digunakan untuk aksi sekunder (Batal, Filter, Export, Kembali).</p>
              <div class="flex items-center gap-3">
                <SecondaryButton>Batal / Kembali</SecondaryButton>
                <SecondaryButton disabled>Disabled</SecondaryButton>
              </div>
            </div>

            <!-- Danger Button -->
            <div class="space-y-3 rounded-md border border-border p-4">
              <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold">DangerButton</h3>
                <span class="font-mono text-[10px] text-ink-600">DangerButton.vue</span>
              </div>
              <p class="text-xs text-ink-600">Digunakan khusus aksi destruktif (Hapus Record, Anulir Transaksi).</p>
              <div class="flex items-center gap-3">
                <DangerButton>Hapus Data</DangerButton>
                <DangerButton disabled>Disabled</DangerButton>
              </div>
            </div>
          </div>
        </Panel>
      </section>

      <!-- SEKSI 3: FORM INPUTS & CONTROLS -->
      <section id="inputs" class="scroll-mt-6 space-y-4">
        <div class="flex items-center gap-2 text-ink-900">
          <FormIcon class="h-5 w-5 text-accent" />
          <h2 class="font-serif text-xl font-bold">3. Form Inputs & Controls</h2>
        </div>
        <Panel subtitle="Koleksi input form terstruktur dengan label, validasi error, dan custom fields EAV.">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- FormInput & FormSelect -->
            <div class="space-y-4 rounded-md border border-border p-4">
              <h3 class="text-sm font-semibold flex items-center justify-between">
                FormInput & FormSelect
                <span class="font-mono text-[10px] text-ink-600">forms/FormInput.vue</span>
              </h3>

              <FormInput
                v-model="inputDemo"
                name="company_name"
                label="Nama Firma / Perusahaan"
                placeholder="Masukkan nama firma..."
                required
              />

              <FormInput
                v-model="inputErrorDemo"
                name="email"
                label="Alamat Email Kontak"
                type="email"
                error="Format email tidak valid atau sudah terdaftar"
                required
              />

              <FormSelect
                v-model="selectDemo"
                name="module_type"
                label="Pilih Vertikal Modul"
                :options="[
                  { label: 'Legal Practice Management', value: 'legal' },
                  { label: 'Property Management', value: 'property' },
                  { label: 'General Corporate ERP', value: 'general' },
                ]"
                required
              />
            </div>

            <!-- SearchInput, Checkbox & Custom Fields -->
            <div class="space-y-4 rounded-md border border-border p-4">
              <h3 class="text-sm font-semibold flex items-center justify-between">
                SearchInput & CustomFieldInputs
                <span class="font-mono text-[10px] text-ink-600">filters/ & forms/</span>
              </h3>

              <div>
                <InputLabel value="Filter Pencarian Realtime (SearchInput)" />
                <SearchInput v-model="searchDemo" placeholder="Cari nomor perkara, klien..." class="mt-1" />
                <p class="mt-1 text-[11px] text-ink-600">State: <code class="font-mono">{{ searchDemo || 'kosong' }}</code></p>
              </div>

              <div class="flex items-center gap-2 pt-2">
                <Checkbox id="demo-check" v-model:checked="checkboxDemo" />
                <label for="demo-check" class="text-sm text-ink-900 cursor-pointer">Aktifkan Notifikasi Jatuh Tempo (Checkbox)</label>
              </div>

              <!-- Custom Fields Engine -->
              <CustomFieldInputs
                v-model="customFieldsModel"
                :fields="customFieldDefs"
              />
            </div>
          </div>
        </Panel>
      </section>

      <!-- SEKSI 4: STATUS & FEEDBACK -->
      <section id="feedback" class="scroll-mt-6 space-y-4">
        <div class="flex items-center gap-2 text-ink-900">
          <Activity class="h-5 w-5 text-accent" />
          <h2 class="font-serif text-xl font-bold">4. Status & Feedback Components</h2>
        </div>
        <Panel subtitle="Indikator status visual (StatusBadge) dan dialog responsif (Toast & ConfirmDialog).">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Status Badges Showcase -->
            <div class="space-y-3 rounded-md border border-border p-4">
              <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold">StatusBadge (Pill reserved for status)</h3>
                <span class="font-mono text-[10px] text-ink-600">feedback/StatusBadge.vue</span>
              </div>
              <p class="text-xs text-ink-600">Satu-satunya chrome ber-bentuk pill di NusaEvo ERP, khusus untuk status visual:</p>
              <div class="flex flex-wrap gap-2 pt-2">
                <StatusBadge status="active" label="Active / Selesai" />
                <StatusBadge status="open" label="Open / Aktif" />
                <StatusBadge status="pending" label="Pending / Due Soon" />
                <StatusBadge status="overdue" label="Overdue / Terlambat" />
                <StatusBadge status="completed" label="Completed" />
                <StatusBadge status="inactive" label="Inactive / Draft" />
              </div>
            </div>

            <!-- Interactive Toast & Confirm Dialog triggers -->
            <div class="space-y-3 rounded-md border border-border p-4">
              <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold">Toast & ConfirmDialog Actions</h3>
                <span class="font-mono text-[10px] text-ink-600">Composables / Modals</span>
              </div>
              <p class="text-xs text-ink-600">Uji langsung pemanggilan dialog global tanpa props overhead:</p>
              <div class="flex flex-wrap gap-3 pt-2">
                <SecondaryButton @click="triggerToastDemo('success')">Toast Success</SecondaryButton>
                <SecondaryButton @click="triggerToastDemo('error')">Toast Error</SecondaryButton>
                <SecondaryButton @click="triggerConfirmDemo('default')">Confirm Modal</SecondaryButton>
                <DangerButton @click="triggerConfirmDemo('destructive')">Destructive Confirm</DangerButton>
              </div>
            </div>
          </div>
        </Panel>
      </section>

      <!-- SEKSI 5: CARDS & STAT PANELS -->
      <section id="cards" class="scroll-mt-6 space-y-4">
        <div class="flex items-center gap-2 text-ink-900">
          <Layers class="h-5 w-5 text-accent" />
          <h2 class="font-serif text-xl font-bold">5. Cards & Metric Containers</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <StatCard
            title="Total Perkara Aktif"
            value="42"
            description="8 perkara akan sidang minggu ini"
            icon="Scale"
          />
          <StatCard
            title="Tenggat Waktu Mendatang"
            value="15"
            description="3 tugas butuh tindakan segera"
            icon="CalendarDays"
          />
          <StatCard
            title="Kepatuhan Tenant ERP"
            value="98.5%"
            description="Seluruh modul beroperasi normal"
            icon="ShieldCheck"
          />
        </div>
      </section>

      <!-- SEKSI 6: DATA TABLES & PAGINATION -->
      <section id="tables" class="scroll-mt-6 space-y-4">
        <div class="flex items-center gap-2 text-ink-900">
          <Table class="h-5 w-5 text-accent" />
          <h2 class="font-serif text-xl font-bold">6. Data Tables & Pagination</h2>
        </div>
        <Panel subtitle="Tabel data standar dengan pengkodean Status Rail pada batas kiri baris.">
          <div class="space-y-4">
            <DataTable
              :columns="tableColumns"
              :items="tableItems"
              status-rail-key="statusRail"
            >
              <template #cell-status="{ value }">
                <StatusBadge :status="value" />
              </template>
            </DataTable>

            <DataTablePagination :links="paginationLinks" />
          </div>
        </Panel>
      </section>

      <!-- SEKSI 7: LAYOUT, SHELL & BRAND -->
      <section id="layout" class="scroll-mt-6 space-y-4">
        <div class="flex items-center gap-2 text-ink-900">
          <Layout class="h-5 w-5 text-accent" />
          <h2 class="font-serif text-xl font-bold">7. Navigation Shell & Brand Assets</h2>
        </div>
        <Panel subtitle="Elemen tata letak aplikasi (AppLayout, AppSidebar, PageHeader, ApplicationLogo).">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Brand Logo -->
            <div class="space-y-3 rounded-md border border-border p-4">
              <h3 class="text-sm font-semibold">ApplicationLogo</h3>
              <p class="text-xs text-ink-600">Logo resmi NusaEvo ERP yang digunakan pada halaman Login, Auth shell, dan Header navigation.</p>
              <div class="flex items-center justify-center p-6 border border-border rounded bg-surface-50">
                <ApplicationLogo class="h-12 w-auto text-accent" />
              </div>
            </div>

            <!-- Context Dropdown Showcase -->
            <div class="space-y-3 rounded-md border border-border p-4">
              <h3 class="text-sm font-semibold">Dropdown Menu (Dropdown & DropdownLink)</h3>
              <p class="text-xs text-ink-600">Komponen popover dropdown yang digunakan untuk menu profil, aksi tabel, dan tenant switcher.</p>
              <div class="pt-2">
                <Dropdown align="left" width="48">
                  <template #trigger>
                    <SecondaryButton>
                      Buka Menu Opsi Dropdown
                    </SecondaryButton>
                  </template>
                  <template #content>
                    <DropdownLink href="#">Lihat Profil Pengguna</DropdownLink>
                    <DropdownLink href="#">Pengaturan Modul</DropdownLink>
                    <DropdownLink href="#" class="text-red-600">Keluar Sesi</DropdownLink>
                  </template>
                </Dropdown>
              </div>
            </div>
          </div>
        </Panel>
      </section>
    </div>
  </AppLayout>
</template>
