/** Helpers for the Stockists page: licences, headquarter choices and employee names. */

/** Licence fields shown on the stockist form and list, in order. */
export const STOCKIST_LICENCES = [
  { key: 'drug', label: 'Drug Licence', number: 'drugLicenceNo', validUpto: 'drugLicenceValidUpto' },
  { key: 'food', label: 'Food Licence', number: 'foodLicenceNo', validUpto: 'foodLicenceValidUpto' },
  { key: 'fssai', label: 'FSSAI Licence', number: 'fssaiLicenceNo', validUpto: 'fssaiLicenceValidUpto' },
]

/** Days before expiry when a licence is flagged as expiring soon. */
export const EXPIRY_WARNING_DAYS = 30

/** Today's date as YYYY-MM-DD in local time. */
export function todayIso(now = new Date()) {
  const pad = (value) => String(value).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/**
 * 'expired', 'soon' (within EXPIRY_WARNING_DAYS), 'valid', or null when there is no date.
 * Dates are YYYY-MM-DD strings; a licence valid up to today is still valid.
 */
export function licenceStatus(validUpto, today = todayIso()) {
  if (!validUpto || !/^\d{4}-\d{2}-\d{2}$/.test(validUpto)) return null
  if (validUpto < today) return 'expired'
  const days = Math.round((Date.parse(`${validUpto}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) / 86400000)
  return days <= EXPIRY_WARNING_DAYS ? 'soon' : 'valid'
}

/** YYYY-MM-DD shown as DD-MM-YYYY. */
export function formatDate(value) {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return ''
  const [year, month, day] = value.split('-')
  return `${day}-${month}-${year}`
}

/** Headquarter list (under State List) as dropdown options, with the state name. */
export function headquarterOptions(locations) {
  const options = []
  for (const location of locations ?? []) {
    for (const office of location.headquarters ?? []) {
      const name = office.code ? `${office.name} (${office.code})` : office.name
      options.push({ value: String(office.id), label: `${name} — ${location.name}` })
    }
  }
  return options.sort((a, b) => a.label.localeCompare(b.label))
}

/** Find a headquarter from the Headquarter list with its state name. */
export function findHeadquarter(locations, id) {
  if (id === null || id === undefined || id === '') return null
  for (const location of locations ?? []) {
    for (const office of location.headquarters ?? []) {
      if (String(office.id) === String(id)) return { ...office, stateId: location.id, stateName: location.name }
    }
  }
  return null
}

/** An employee's display name: first + last name when present, else name. */
export function employeeName(employee) {
  if (!employee) return ''
  const full = [employee.firstName, employee.lastName].map((part) => (part ?? '').trim()).filter(Boolean).join(' ')
  return full || (employee.name ?? '').trim()
}

/** "Name (CODE)" for the Assigned To dropdown and list. */
export function employeeLabel(employee) {
  if (!employee) return ''
  const name = employeeName(employee) || 'Unnamed employee'
  const code = employee.employeeCode ?? employee.code
  return code ? `${name} (${code})` : name
}

/** A stockist's display name: name, else code, else "Stockist #id". */
export function stockistLabel(stockist) {
  if (!stockist) return ''
  return (stockist.name ?? '').trim() || (stockist.code ?? '').trim() || (stockist.id ? `Stockist #${stockist.id}` : 'Stockist')
}

/**
 * Stockists as SearchableSelect options: "Name (CODE) — Headquarter".
 * Code, Headquarter and state are searchable. Pass a headquarter id to keep only its stockists.
 */
export function stockistOptions(stockists, locations, headquarterId = '') {
  return (stockists ?? [])
    .filter((stockist) => !headquarterId || String(stockist.locationHeadquartersId ?? '') === String(headquarterId))
    .map((stockist) => {
      const office = findHeadquarter(locations, stockist.locationHeadquartersId)
      const name = stockistLabel(stockist)
      const withCode = stockist.code && stockist.name ? `${name} (${stockist.code})` : name
      return {
        value: String(stockist.id),
        label: office ? `${withCode} — ${office.name}` : withCode,
        keywords: [stockist.code, office?.name, office?.code, office?.stateName].filter(Boolean),
      }
    })
    .sort((a, b) => a.label.localeCompare(b.label))
}

/** True when another stockist already has this code (trimmed, any letter case). */
export function stockistCodeTaken(stockists, code, exceptId) {
  const wanted = (code ?? '').trim().toLowerCase()
  if (!wanted) return false
  return (stockists ?? []).some(
    (stockist) => String(stockist.id) !== String(exceptId ?? '') && (stockist.code ?? '').trim().toLowerCase() === wanted,
  )
}