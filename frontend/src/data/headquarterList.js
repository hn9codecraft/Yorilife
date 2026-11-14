/** Helpers for the Headquarter List table. */

/**
 * Stockist and employee counts per Headquarter List id.
 * employees may be null while the Employee List is not loaded.
 */
export function headquarterUsage(stockists, employees) {
  const count = (map, id) => {
    if (id == null || id === '') return
    const key = String(id)
    map.set(key, (map.get(key) ?? 0) + 1)
  }
  const stockistCounts = new Map()
  ;(stockists ?? []).forEach((stockist) => count(stockistCounts, stockist.locationHeadquartersId))
  const employeeCounts = new Map()
  ;(employees ?? []).forEach((employee) => {
    new Set((employee.locationHeadquartersIds ?? []).map(String)).forEach((id) => count(employeeCounts, id))
  })
  return { stockists: stockistCounts, employees: employeeCounts }
}

/** ISO timestamp as "14 Nov 2026, 3:26 pm" in the browser's time zone; '' when invalid. */
export function formatDateTime(iso, locale = 'en-IN') {
  const date = new Date(iso ?? '')
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString(locale, { day: '2-digit', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}