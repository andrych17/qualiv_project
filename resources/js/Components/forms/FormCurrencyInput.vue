<!-- Reusable FormCurrencyInput component with thousand separator formatting and terbilang preview -->
<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import { formatTerbilang } from '@/Utils/formatters'
import { X } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    modelValue: number | string | null
    label?: string
    name?: string
    placeholder?: string
    error?: string
    required?: boolean
    disabled?: boolean
    readonly?: boolean
    prefix?: string
    suffix?: string
    decimals?: number
    thousandSeparator?: string
    decimalSeparator?: string
    allowNegative?: boolean
    min?: number
    max?: number
    showTerbilang?: boolean
    clearable?: boolean
    align?: 'left' | 'right'
  }>(),
  {
    label: '',
    name: '',
    placeholder: '0',
    required: false,
    disabled: false,
    readonly: false,
    prefix: 'Rp',
    suffix: '',
    decimals: 0,
    thousandSeparator: '.',
    decimalSeparator: ',',
    allowNegative: false,
    showTerbilang: false,
    clearable: false,
    align: 'right',
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
  change: [value: number | null]
  blur: [event: FocusEvent]
  focus: [event: FocusEvent]
}>()

const inputRef = ref<HTMLInputElement | null>(null)
const isFocused = ref(false)
const displayValue = ref('')

const inputId = computed(() => (props.name ? `currency-${props.name}` : undefined))

// Format a numeric value to display string with separators
function formatToDisplay(val: number | string | null | undefined, keepTrailingDecimal: boolean = false): string {
  if (val === null || val === undefined || val === '') return ''

  const str = String(val).trim()
  if (!str) return ''

  const isNeg = props.allowNegative && str.startsWith('-')
  const cleanStr = str.replace(/^-/, '')

  // Split integer and decimal parts
  const parts = cleanStr.split('.')
  let integerPart = parts[0].replace(/\D/g, '') || '0'
  // Remove leading zeroes unless just '0'
  if (integerPart.length > 1) {
    integerPart = integerPart.replace(/^0+/, '') || '0'
  }

  // Format integer with thousand separator
  const formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, props.thousandSeparator)

  if (props.decimals > 0) {
    if (parts.length > 1) {
      const decimalPart = parts[1].replace(/\D/g, '').slice(0, props.decimals)
      return (isNeg ? '-' : '') + `${formattedInteger}${props.decimalSeparator}${decimalPart}`
    } else if (keepTrailingDecimal) {
      return (isNeg ? '-' : '') + `${formattedInteger}${props.decimalSeparator}`
    }
  }

  return (isNeg ? '-' : '') + formattedInteger
}

// Parse display string into a pure float / integer number
function parseDisplayToNumber(display: string): number | null {
  if (!display || !display.trim()) return null

  const trimmed = display.trim()
  const isNeg = props.allowNegative && trimmed.startsWith('-')

  // Remove everything except digits and decimal separator
  let raw = trimmed
    .split(props.thousandSeparator)
    .join('')
    .replace(props.decimalSeparator, '.')
    .replace(/[^\d.]/g, '')

  if (!raw) return null

  const parts = raw.split('.')
  let sanitized = parts[0] || '0'
  if (parts.length > 1 && props.decimals > 0) {
    sanitized += '.' + parts.slice(1).join('').slice(0, props.decimals)
  }

  let num = parseFloat(sanitized)
  if (isNaN(num)) return null

  if (isNeg) num = -num

  if (props.min !== undefined && num < props.min) num = props.min
  if (props.max !== undefined && num > props.max) num = props.max

  return num
}

// Synchronize external modelValue changes
watch(
  () => props.modelValue,
  (newVal) => {
    if (!isFocused.value) {
      displayValue.value = formatToDisplay(newVal)
    }
  },
  { immediate: true }
)

const terbilangText = computed(() => {
  if (!props.showTerbilang || props.modelValue === null || props.modelValue === undefined || props.modelValue === '') {
    return ''
  }
  const num = typeof props.modelValue === 'number' ? props.modelValue : parseFloat(String(props.modelValue))
  if (isNaN(num) || num === 0) return ''
  return formatTerbilang(num, props.prefix === 'Rp' ? 'Rupiah' : '')
})

function handleInput(event: Event) {
  const input = event.target as HTMLInputElement
  const rawInput = input.value
  const cursor = input.selectionStart || 0

  // Check if user is typing trailing decimal separator
  const hasTrailingDecimal =
    props.decimals > 0 &&
    (rawInput.endsWith(props.decimalSeparator) || rawInput.endsWith('.'))

  // Count valid numeric / decimal chars before cursor to restore position
  let validCharsBefore = 0
  for (let i = 0; i < cursor; i++) {
    const ch = rawInput[i]
    if (/\d/.test(ch) || (ch === props.decimalSeparator && props.decimals > 0) || (ch === '-' && props.allowNegative)) {
      validCharsBefore++
    }
  }

  const numericVal = parseDisplayToNumber(rawInput)
  const formatted = numericVal !== null
    ? formatToDisplay(numericVal, hasTrailingDecimal)
    : (hasTrailingDecimal ? `0${props.decimalSeparator}` : '')

  displayValue.value = formatted
  input.value = formatted

  // Recalculate cursor position
  let newCursor = 0
  let validCharsCount = 0
  for (let i = 0; i < formatted.length; i++) {
    const ch = formatted[i]
    if (/\d/.test(ch) || (ch === props.decimalSeparator && props.decimals > 0) || (ch === '-' && props.allowNegative)) {
      validCharsCount++
    }
    if (validCharsCount >= validCharsBefore) {
      newCursor = i + 1
      break
    }
  }
  if (validCharsCount < validCharsBefore) {
    newCursor = formatted.length
  }

  nextTick(() => {
    input.setSelectionRange(newCursor, newCursor)
  })

  emit('update:modelValue', numericVal)
}

function handleKeyDown(event: KeyboardEvent) {
  if (props.disabled || props.readonly) return

  // Handle Backspace when immediately right of a thousand separator
  if (event.key === 'Backspace' && inputRef.value) {
    const pos = inputRef.value.selectionStart || 0
    const end = inputRef.value.selectionEnd || 0

    if (pos === end && pos > 0 && displayValue.value[pos - 1] === props.thousandSeparator) {
      event.preventDefault()
      const current = displayValue.value
      // Delete separator AND preceding digit
      const updated = current.slice(0, pos - 2) + current.slice(pos)
      const num = parseDisplayToNumber(updated)
      displayValue.value = formatToDisplay(num)
      emit('update:modelValue', num)

      nextTick(() => {
        const newPos = Math.max(0, pos - 2)
        inputRef.value?.setSelectionRange(newPos, newPos)
      })
    }
  }
}

function handleFocus(event: FocusEvent) {
  isFocused.value = true
  emit('focus', event)
}

function handleBlur(event: FocusEvent) {
  isFocused.value = false
  // Re-format on blur to clean up any loose decimal points
  displayValue.value = formatToDisplay(props.modelValue)
  emit('blur', event)
  emit('change', typeof props.modelValue === 'number' ? props.modelValue : parseDisplayToNumber(displayValue.value))
}

function handleClear(event: MouseEvent) {
  event.stopPropagation()
  if (props.disabled || props.readonly) return
  displayValue.value = ''
  emit('update:modelValue', null)
  emit('change', null)
  nextTick(() => {
    inputRef.value?.focus()
  })
}
</script>

<template>
  <div class="space-y-1.5">
    <!-- Header Label & Required Asterisk -->
    <div v-if="label" class="flex items-center justify-between">
      <label :for="inputId" class="text-sm font-medium text-ink-900">
        {{ label }}
        <span v-if="required" class="text-signal-danger">*</span>
      </label>
    </div>

    <!-- Input Box Container with Integrated Prefix & Suffix -->
    <div
      class="group relative flex w-full items-stretch overflow-hidden rounded-md border border-border bg-white shadow-sm transition focus-within:border-ink-900 focus-within:ring-2 focus-within:ring-ink-900/10"
      :class="[
        error ? 'border-signal-danger focus-within:border-signal-danger focus-within:ring-signal-danger/10' : '',
        disabled ? 'bg-surface-50 cursor-not-allowed text-ink-600' : '',
      ]"
    >
      <!-- Prefix (e.g. Rp, $, €) -->
      <span
        v-if="prefix"
        class="inline-flex items-center select-none border-r border-border bg-surface-50 px-3 text-sm font-medium text-ink-600 shrink-0"
        :class="disabled ? 'bg-surface-50/50' : ''"
      >
        {{ prefix }}
      </span>

      <!-- Main Number/Text Input -->
      <input
        :id="inputId"
        ref="inputRef"
        :name="name"
        type="text"
        inputmode="numeric"
        :value="displayValue"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        class="w-full min-w-0 border-0 bg-transparent px-3 py-2 text-sm text-ink-900 outline-none placeholder:text-ink-600/50 focus:ring-0"
        :class="[
          align === 'right' ? 'text-right' : 'text-left',
          disabled ? 'cursor-not-allowed text-ink-600' : '',
        ]"
        @input="handleInput"
        @keydown="handleKeyDown"
        @focus="handleFocus"
        @blur="handleBlur"
      />

      <!-- Clear Button (Optional) -->
      <div v-if="clearable && modelValue !== null && modelValue !== '' && !disabled && !readonly" class="flex items-center pr-2">
        <button
          type="button"
          class="rounded p-1 text-ink-600 hover:bg-surface-50 hover:text-ink-900 focus:outline-none"
          title="Hapus"
          @click="handleClear"
        >
          <X class="h-3.5 w-3.5" />
        </button>
      </div>

      <!-- Suffix (e.g. pcs, kg, /bln) -->
      <span
        v-if="suffix"
        class="inline-flex items-center select-none border-l border-border bg-surface-50 px-3 text-sm font-medium text-ink-600 shrink-0"
      >
        {{ suffix }}
      </span>
    </div>

    <!-- Live Terbilang Preview (Indonesian Words for Nominal) -->
    <p v-if="showTerbilang && terbilangText" class="text-xs italic text-ink-600 font-medium tracking-tight">
      Terbaca: <span class="text-ink-900">{{ terbilangText }}</span>
    </p>

    <!-- Error Message -->
    <p v-if="error" class="text-sm text-signal-danger">
      {{ error }}
    </p>
  </div>
</template>
