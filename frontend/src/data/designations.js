/** Sales Office designations, top of the reporting line first. Keep in step with SalesEmployeeService::DESIGNATIONS. */
export const DESIGNATIONS = [
  { code: 'GM', label: 'GM - General Manager' },
  { code: 'ZSM', label: 'ZSM - Zonal Sales Manager' },
  { code: 'DSM', label: 'DSM - Direct Sales Manager' },
  { code: 'RBM', label: 'RBM - Region Business Manager' },
  { code: 'DBM', label: 'DBM - Deputy Business Manager' },
  { code: 'ABM', label: 'ABM - Area Business Manager' },
  { code: 'BO', label: 'BO - Business Officer' },
]

export function designationLabel(code) {
  return DESIGNATIONS.find((item) => item.code === code)?.label ?? ''
}

/** Designations strictly above `code`. An empty designation has none. */
export function designationsAbove(code) {
  const index = DESIGNATIONS.findIndex((item) => item.code === code)
  if (index <= 0) return []
  return DESIGNATIONS.slice(0, index)
}

/**
 * Drop manager choices that are not above the selected designation.
 * `slots` is `{ GM: idOrEmpty, ... }`. Other designations are left untouched.
 */
export function clearManagerSlots(designation, slots) {
  const allowed = new Set(designationsAbove(designation).map((item) => item.code))
  const next = { ...slots }
  for (const item of DESIGNATIONS) {
    if (!allowed.has(item.code)) next[item.code] = ''
  }
  return next
}
