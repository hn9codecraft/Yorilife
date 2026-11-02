/**
 * Small RFC 4180-style CSV parser: commas, CRLF/LF line endings, quoted
 * fields with embedded commas, newlines and doubled quotes. Strips a UTF-8 BOM.
 * Returns an array of rows (arrays of strings); blank lines are skipped.
 */
export function parseCsv(text) {
  const input = text.charCodeAt(0) === 0xfeff ? text.slice(1) : text
  const rows = []
  let row = []
  let field = ''
  let inQuotes = false

  const endField = () => { row.push(field); field = '' }
  const endRow = () => {
    endField()
    if (!(row.length === 1 && row[0] === '')) rows.push(row)
    row = []
  }

  for (let i = 0; i < input.length; i++) {
    const char = input[i]
    if (inQuotes) {
      if (char === '"' && input[i + 1] === '"') { field += '"'; i++ }
      else if (char === '"') inQuotes = false
      else field += char
    } else if (char === '"') inQuotes = true
    else if (char === ',') endField()
    else if (char === '\n') endRow()
    else if (char === '\r') { if (input[i + 1] === '\n') i++; endRow() }
    else field += char
  }
  if (field !== '' || row.length > 0) endRow()

  return { rows, unterminatedQuote: inQuotes }
}

/**
 * Structural checks that don't depend on an import template (the templates
 * are not confirmed yet). Returns { errors[], warnings[], header[], dataRows[] }.
 */
export function inspectCsv(text) {
  const { rows, unterminatedQuote } = parseCsv(text)
  const errors = []
  const warnings = []

  if (rows.length === 0) {
    errors.push('The file has no content.')
    return { errors, warnings, header: [], dataRows: [] }
  }

  const header = rows[0].map((h) => h.trim())
  const dataRows = rows.slice(1)

  if (unterminatedQuote) errors.push('A quoted value is never closed ("). Check the file for a missing quote.')
  if (header.some((h) => h === '')) errors.push('The header row has an empty column name.')

  const seen = new Set()
  const duplicates = new Set(header.filter((h) => h && (seen.has(h.toLowerCase()) || !seen.add(h.toLowerCase()))))
  if (duplicates.size) errors.push(`Duplicate column names: ${[...duplicates].join(', ')}.`)

  if (dataRows.length === 0) warnings.push('The file has a header row but no data rows.')

  const badRows = dataRows
    .map((r, index) => ({ line: index + 2, count: r.length }))
    .filter((r) => r.count !== header.length)
  if (badRows.length) {
    const sample = badRows.slice(0, 5).map((r) => `line ${r.line} (${r.count})`).join(', ')
    errors.push(`${badRows.length} row(s) have a different number of columns than the header (${header.length}): ${sample}${badRows.length > 5 ? ', …' : ''}.`)
  }

  return { errors, warnings, header, dataRows }
}

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

export function downloadCsv(filename, text) {
  const blob = new Blob([text], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}

export function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
