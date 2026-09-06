/**
 * Standard utility formatters for Nusaevo ERP.
 * Used across tables, panels, and detail views to ensure consistent presentation.
 */

/**
 * Resolve active system locale ('id-ID' vs 'en-US') dynamically from DOM or fallback.
 */
export function getActiveLocale(overrideLocale?: string): string {
  if (overrideLocale) {
    if (overrideLocale === 'id' || overrideLocale === 'id-ID' || overrideLocale === 'id_ID') return 'id-ID'
    if (overrideLocale === 'en' || overrideLocale === 'en-US' || overrideLocale === 'en_US') return 'en-US'
    return overrideLocale
  }

  if (typeof document !== 'undefined' && document.documentElement) {
    const docLang = document.documentElement.getAttribute('lang') || document.documentElement.lang
    if (docLang) {
      const lower = docLang.toLowerCase()
      if (lower.startsWith('en')) return 'en-US'
      if (lower.startsWith('id')) return 'id-ID'
    }
  }

  return 'id-ID'
}

/**
 * Format a number or string as currency.
 * Example (IDR / id-ID): 1500000 -> "Rp 1.500.000"
 * Example (IDR / en-US): 1500000 -> "IDR 1,500,000"
 * Example (USD / en-US): 1500000 -> "$1,500,000.00"
 */
export function formatCurrency(
  value: number | string | null | undefined,
  currency: string = 'IDR',
  locale?: string
): string {
  if (value === null || value === undefined || value === '') {
    return '-'
  }

  const num = typeof value === 'string' ? parseFloat(value) : value
  if (isNaN(num)) {
    return '-'
  }

  const resolvedLocale = getActiveLocale(locale)

  try {
    return new Intl.NumberFormat(resolvedLocale, {
      style: 'currency',
      currency,
      maximumFractionDigits: currency.toUpperCase() === 'IDR' ? 0 : 2,
      minimumFractionDigits: 0,
    }).format(num)
  } catch {
    // Fallback if invalid currency code passed
    return `${currency} ${num.toLocaleString(resolvedLocale)}`
  }
}

/**
 * Format a number with thousands separators and optional decimals.
 * Example (id-ID): 12500.5 -> "12.500,5"
 * Example (en-US): 12500.5 -> "12,500.5"
 */
export function formatNumber(
  value: number | string | null | undefined,
  decimals: number = 0,
  locale?: string
): string {
  if (value === null || value === undefined || value === '') {
    return '-'
  }

  const num = typeof value === 'string' ? parseFloat(value) : value
  if (isNaN(num)) {
    return '-'
  }

  const resolvedLocale = getActiveLocale(locale)

  return new Intl.NumberFormat(resolvedLocale, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(num)
}

/**
 * Format a date string or timestamp.
 * Example (id-ID): "2026-08-26" -> "26 Agu 2026"
 * Example (en-US): "2026-08-26" -> "Aug 26, 2026"
 */
export function formatDate(
  value: string | Date | null | undefined,
  style: 'short' | 'medium' | 'long' = 'medium',
  locale?: string
): string {
  if (!value) return '-'

  const date = typeof value === 'string' ? new Date(value) : value
  if (isNaN(date.getTime())) return '-'

  const resolvedLocale = getActiveLocale(locale)

  const options: Intl.DateTimeFormatOptions =
    style === 'short'
      ? { day: 'numeric', month: 'numeric', year: 'numeric' }
      : style === 'long'
      ? { day: 'numeric', month: 'long', year: 'numeric' }
      : { day: 'numeric', month: 'short', year: 'numeric' }

  return new Intl.DateTimeFormat(resolvedLocale, options).format(date)
}

/**
 * Format a date with hours and minutes.
 * Example (id-ID): "2026-08-26T14:30:00" -> "26 Agu 2026, 14:30"
 * Example (en-US): "2026-08-26T14:30:00" -> "Aug 26, 2026, 02:30 PM"
 */
export function formatDateTime(
  value: string | Date | null | undefined,
  locale?: string
): string {
  if (!value) return '-'

  const date = typeof value === 'string' ? new Date(value) : value
  if (isNaN(date.getTime())) return '-'

  const resolvedLocale = getActiveLocale(locale)

  return new Intl.DateTimeFormat(resolvedLocale, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

/**
 * Convert number to English words.
 * Example: 1500000 -> "One Million Five Hundred Thousand Dollars"
 */
export function formatNumberToWords(
  value: number | string | null | undefined,
  suffix: string = 'Dollars'
): string {
  if (value === null || value === undefined || value === '') return ''
  const num = typeof value === 'string' ? parseFloat(value.replace(/[^\d.-]/g, '')) : value
  if (isNaN(num)) return ''
  if (num === 0) return `Zero ${suffix}`.trim()

  const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen']
  const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety']
  const scales = ['', 'Thousand', 'Million', 'Billion', 'Trillion']

  function convertGroup(n: number): string {
    let result = ''
    if (n >= 100) {
      result += ones[Math.floor(n / 100)] + ' Hundred '
      n %= 100
    }
    if (n >= 20) {
      result += tens[Math.floor(n / 10)] + ' '
      n %= 10
    }
    if (n > 0) {
      result += ones[n] + ' '
    }
    return result.trim()
  }

  const isNeg = num < 0
  let integerPart = Math.floor(Math.abs(num))
  let scaleIndex = 0
  let words = ''

  while (integerPart > 0) {
    const chunk = integerPart % 1000
    if (chunk !== 0) {
      const groupStr = convertGroup(chunk)
      const scaleStr = scales[scaleIndex] ? ' ' + scales[scaleIndex] : ''
      words = groupStr + scaleStr + (words ? ' ' + words : '')
    }
    integerPart = Math.floor(integerPart / 1000)
    scaleIndex++
  }

  const formatted = (isNeg ? 'Minus ' : '') + words.trim() + (suffix ? ` ${suffix}` : '')
  return formatted.trim()
}

/**
 * Convert number to words based on locale (Indonesian / English).
 * Example (ID): 1500000 -> "Satu Juta Lima Ratus Ribu Rupiah"
 * Example (EN): 1500000 -> "One Million Five Hundred Thousand Rupiah"
 */
export function formatTerbilang(
  value: number | string | null | undefined,
  suffix: string = 'Rupiah',
  locale?: string
): string {
  if (value === null || value === undefined || value === '') return ''
  const num = typeof value === 'string' ? parseFloat(value.replace(/[^\d.-]/g, '')) : value
  if (isNaN(num)) return ''

  const resolved = getActiveLocale(locale)

  if (resolved === 'en-US' || resolved.startsWith('en')) {
    return formatNumberToWords(num, suffix)
  }

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
