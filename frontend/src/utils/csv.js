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
