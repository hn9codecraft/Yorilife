import { maskRecord } from './pii.js'

/** Quote a cell when it contains a comma, quote, or line break. Values stay text. */
export function csvCell(value) {
  const text = value == null ? '' : String(value)
  if (/[",\r\n]/.test(text)) return `"${text.replaceAll('"', '""')}"`
  return text
}

/** Header row plus one row per record. A leading BOM keeps Excel on UTF-8. */
export function toCsv(headers, records) {
  const lines = [headers.map(csvCell).join(',')]
  records.forEach((record) => {
    lines.push(headers.map((header) => csvCell(record[header] ?? '')).join(','))
  })
  return `\uFEFF${lines.join('\r\n')}`
}

/** A safe dated file name, e.g. csvFilename('Stockists') -> "stockists-2026-11-14.csv". */
export function csvFilename(base, date = new Date()) {
  const slug = String(base ?? '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'export'
  const pad = (value) => String(value).padStart(2, '0')
  return `${slug}-${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}.csv`
}

/** CSV text for an export. Email and phone columns are masked unless mask is false. */
export function buildCsv(headers, records, { mask = true } = {}) {
  return toCsv(headers, mask ? records.map(maskRecord) : records)
}

/** Join a list of names into one cell ("Gujarat; Maharashtra"). Empty values are skipped. */
export function joinCell(values) {
  return (values ?? []).map((value) => String(value ?? '').trim()).filter(Boolean).join('; ')
}

export function downloadCsv(filename, text) {
  const blob = new Blob([text], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
