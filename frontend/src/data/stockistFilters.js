import { STOCKIST_LICENCES, licenceStatus, todayIso } from './stockists.js'

/** Filter values kept in the URL (?q=&hq=&state=&assigned=&licence=). */
export const STOCKIST_FILTER_DEFAULTS = Object.freeze({ q: '', hq: '', state: '', assigned: '', licence: '' })

/** "Assigned To" value for stockists with no employee. */
export const UNASSIGNED = 'none'

export const LICENCE_FILTERS = [
  { value: 'valid', label: 'Valid' },
  { value: 'soon', label: 'Expiring within 30 days' },
  { value: 'expired', label: 'Expired' },
  { value: 'none', label: 'No licence' },
]

/**
 * One status for all of a stockist's licences, worst first:
 * 'expired' > 'soon' > 'valid'; 'none' when no licence number or date is entered.
 * A licence with a number but no date counts as valid.
 */
export function licenceSummary(stockist, today = todayIso()) {
  let entered = false
  let worst = 'valid'
  for (const licence of STOCKIST_LICENCES) {
    const number = String(stockist?.[licence.number] ?? '').trim()
    const date = stockist?.[licence.validUpto] ?? ''
    if (!number && !date) continue
    entered = true
    const status = licenceStatus(date, today)
    if (status === 'expired') return 'expired'
    if (status === 'soon') worst = 'soon'
  }
  return entered ? worst : 'none'
}

const digits = (value) => String(value ?? '').replace(/\D/g, '')

/**
 * Stockists matching the filter values.
 *   search: name, code, phone (spaces ignored) or email
 *   hq / state: Headquarter List id / State List id
 *   assigned: employee id, or UNASSIGNED
 *   licence: a LICENCE_FILTERS value
 * officeOf(stockist) returns the Headquarter with stateId (findHeadquarter).
 */
export function filterStockists(stockists, filters, { officeOf, today = todayIso() }) {
  const q = String(filters.q ?? '').trim().toLowerCase()
  const qDigits = digits(q)
  return (stockists ?? []).filter((stockist) => {
    if (q) {
      const text = [stockist.name, stockist.code, stockist.phone, stockist.email].filter(Boolean).join(' ').toLowerCase()
      const phoneHit = qDigits.length >= 3 && digits(stockist.phone).includes(qDigits)
      if (!text.includes(q) && !phoneHit) return false
    }
    if (filters.hq && String(stockist.locationHeadquartersId ?? '') !== String(filters.hq)) return false
    if (filters.state && String(officeOf(stockist)?.stateId ?? '') !== String(filters.state)) return false
    if (filters.assigned) {
      const assigned = stockist.assignedEmployeeId == null || stockist.assignedEmployeeId === '' ? '' : String(stockist.assignedEmployeeId)
      if (filters.assigned === UNASSIGNED ? assigned !== '' : assigned !== String(filters.assigned)) return false
    }
    if (filters.licence && licenceSummary(stockist, today) !== filters.licence) return false
    return true
  })
}