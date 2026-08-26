/**
 * Standard utility formatters for Nusaevo ERP.
 * Used across tables, panels, and detail views to ensure consistent presentation.
 */

/**
 * Format a number or string as currency (default IDR).
 * Example: 1500000 -> "Rp 1.500.000"
 */
export function formatCurrency(
  value: number | string | null | undefined,
  currency: string = 'IDR',
  locale: string = 'id-ID'
): string {
  if (value === null || value === undefined || value === '') {
    return '-'
  }

  const num = typeof value === 'string' ? parseFloat(value) : value
  if (isNaN(num)) {
    return '-'
  }

  try {
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      maximumFractionDigits: currency.toUpperCase() === 'IDR' ? 0 : 2,
      minimumFractionDigits: 0,
    }).format(num)
  } catch {
    // Fallback if invalid currency code passed
    return `${currency} ${num.toLocaleString(locale)}`
  }
}

/**
 * Format a number with thousands separators and optional decimals.
 * Example: 12500.5 -> "12.500,5"
 */
export function formatNumber(
  value: number | string | null | undefined,
  decimals: number = 0,
  locale: string = 'id-ID'
): string {
  if (value === null || value === undefined || value === '') {
    return '-'
  }

  const num = typeof value === 'string' ? parseFloat(value) : value
  if (isNaN(num)) {
    return '-'
  }

  return new Intl.NumberFormat(locale, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(num)
}

/**
 * Format a date string or timestamp.
 * Example: "2026-08-26" -> "26 Agu 2026"
 */
export function formatDate(
  value: string | Date | null | undefined,
  style: 'short' | 'medium' | 'long' = 'medium',
  locale: string = 'id-ID'
): string {
  if (!value) return '-'

  const date = typeof value === 'string' ? new Date(value) : value
  if (isNaN(date.getTime())) return '-'

  const options: Intl.DateTimeFormatOptions =
    style === 'short'
      ? { day: 'numeric', month: 'numeric', year: 'numeric' }
      : style === 'long'
      ? { day: 'numeric', month: 'long', year: 'numeric' }
      : { day: 'numeric', month: 'short', year: 'numeric' }

  return new Intl.DateTimeFormat(locale, options).format(date)
}

/**
 * Format a date with hours and minutes.
 * Example: "2026-08-26T14:30:00" -> "26 Agu 2026, 14:30"
 */
export function formatDateTime(
  value: string | Date | null | undefined,
  locale: string = 'id-ID'
): string {
  if (!value) return '-'

  const date = typeof value === 'string' ? new Date(value) : value
  if (isNaN(date.getTime())) return '-'

  return new Intl.DateTimeFormat(locale, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

/**
 * Convert number to Indonesian words (Terbilang).
 * Example: 1500000 -> "Satu Juta Lima Ratus Ribu Rupiah"
 */
export function formatTerbilang(
  value: number | string | null | undefined,
  suffix: string = 'Rupiah'
): string {
  if (value === null || value === undefined || value === '') return ''
  const num = typeof value === 'string' ? parseFloat(value.replace(/[^\d.-]/g, '')) : value
  if (isNaN(num)) return ''
  if (num === 0) return `Nol ${suffix}`.trim()

  const units = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas']

  function terbilangHelper(n: number): string {
    n = Math.floor(n)
    if (n < 12) {
      return units[n]
    } else if (n < 20) {
      return terbilangHelper(n - 10) + ' Belas'
    } else if (n < 100) {
      return (terbilangHelper(Math.floor(n / 10)) + ' Puluh ' + terbilangHelper(n % 10)).trim()
    } else if (n < 200) {
      return ('Seratus ' + terbilangHelper(n - 100)).trim()
    } else if (n < 1000) {
      return (terbilangHelper(Math.floor(n / 100)) + ' Ratus ' + terbilangHelper(n % 100)).trim()
    } else if (n < 2000) {
      return ('Seribu ' + terbilangHelper(n - 1000)).trim()
    } else if (n < 1000000) {
      return (terbilangHelper(Math.floor(n / 1000)) + ' Ribu ' + terbilangHelper(n % 1000)).trim()
    } else if (n < 1000000000) {
      return (terbilangHelper(Math.floor(n / 1000000)) + ' Juta ' + terbilangHelper(n % 1000000)).trim()
    } else if (n < 1000000000000) {
      return (terbilangHelper(Math.floor(n / 1000000000)) + ' Miliar ' + terbilangHelper(n % 1000000000)).trim()
    } else if (n < 1000000000000000) {
      return (terbilangHelper(Math.floor(n / 1000000000000)) + ' Triliun ' + terbilangHelper(n % 1000000000000)).trim()
    }
    return String(n)
  }

  const isNeg = num < 0
  const absNum = Math.abs(num)
  const integerPart = Math.floor(absNum)
  const result = terbilangHelper(integerPart).replace(/\s+/g, ' ').trim()

  const formatted = (isNeg ? 'Minus ' : '') + result + (suffix ? ` ${suffix}` : '')
  return formatted.trim()
}

/**
 * Parse localized formatted string into a standard numeric float/integer.
 * Supports thousand separator '.' and decimal ',' (id-ID) or vice versa.
 */
export function parseFormattedNumber(
  value: string | number | null | undefined,
  thousandSeparator: string = '.',
  decimalSeparator: string = ','
): number | null {
  if (value === null || value === undefined || value === '') return null
  if (typeof value === 'number') return isNaN(value) ? null : value

  const str = value.trim()
  if (!str) return null

  const isNegative = str.startsWith('-')
  let cleaned = str
    .replace(new RegExp(`\\${thousandSeparator}`, 'g'), '')
    .replace(new RegExp(`\\${decimalSeparator}`), '.')
    .replace(/[^\d.]/g, '')

  if (!cleaned) return null

  const parts = cleaned.split('.')
  let sanitized = parts[0]
  if (parts.length > 1) {
    sanitized += '.' + parts.slice(1).join('')
  }

  const parsed = parseFloat(sanitized)
  if (isNaN(parsed)) return null

  return isNegative ? -parsed : parsed
}

