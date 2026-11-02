import { MANAGER_LEVELS, PARENT_TYPES } from './constants.js'

// ---- Generic ---------------------------------------------------------------

export const byName = (a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })

/** Case-insensitive text compare. Numeric chunks stay in numeric order, so ER-M1-002 comes before ER-M1-010. */
export function compareNatural(left, right) {
  return String(left ?? '').localeCompare(String(right ?? ''), undefined, { numeric: true, sensitivity: 'base' })
}

export function sortByField(rows, field, direction = 'asc') {
  const factor = direction === 'desc' ? -1 : 1
  return [...rows].sort((left, right) => factor * compareNatural(left[field], right[field]))
}

export const findById = (list, id) => list.find((item) => item.id === id) ?? null

export const activeOnly = (list) => list.filter((item) => !item.deletedAt)

/** A row matches when every active filter group hits. An empty group does not restrict the list. */
export function matchesFilters(stateIds, reportId, filters) {
  const states = filters?.stateIds ?? []
  const reports = filters?.reportIds ?? []
  if (states.length && !states.some((id) => stateIds.includes(id))) return false
  if (reports.length && !reports.includes(reportId)) return false
  return true
}

export function matchesSearch(record, query, fields = ['name', 'code']) {
  const q = query.trim().toLowerCase()
  if (!q) return true
  return fields.some((field) => String(record[field] ?? '').toLowerCase().includes(q))
}

// ---- Sales Office hierarchy -------------------------------------------------

export const managersAtLevel = (state, level) => state.managers.filter((m) => m.level === level)

// The manager one level above this one. A 3rd line manager has no parent.
export function getParent(state, manager) {
  if (!manager?.parentId || manager.parentType === 'headquarters') return manager?.parentType === 'headquarters' ? findById(state.headquarters, manager.parentId) : null
  return findById(state.managers, manager.parentId)
}

/**
 * Chain from this manager up to the top: 1st line, then 2nd line, then 3rd line.
 * Each step: { type, label, record }. Stops if a link is missing.
 */
export function getManagerChain(state, manager) {
  const chain = []
  let current = manager
  let currentType = manager ? `manager${manager.level}` : null
  const seen = new Set()

  while (current && !seen.has(current.id)) {
    seen.add(current.id)
    chain.push({
      type: currentType,
      label: currentType === 'headquarters' ? PARENT_TYPES.headquarters.label : MANAGER_LEVELS[current.level].label,
      record: current,
    })
    if (currentType === 'headquarters') break
    const parent = getParent(state, current)
    currentType = current.parentType
    current = parent
  }
  return chain
}

export function resolveHeadquarters(state, manager) {
  const top = getManagerChain(state, manager).at(-1)
  return top?.type === 'headquarters' ? top.record : null
}

/**
 * The branch that contains one headquarters, from the 3rd line manager down
 * to that headquarters and its stockists. Missing links are left out.
 */
export function buildHeadquartersBranch(state, headquartersId) {
  const headquarters = findById(state.headquarters, headquartersId)
  if (!headquarters) return null
  const first = findById(state.managers, headquarters.parentManagerId)
  const second = first?.parentType === 'manager2' ? getParent(state, first) : null
  const third = second?.parentType === 'manager3' ? getParent(state, second) : null
  return {
    managers: [third, second, first].filter(Boolean),
    headquarters,
    stockists: state.stockists.filter((stockist) => stockist.headquartersId === headquartersId).sort(byName),
  }
}

/** Full forest: each 3rd line manager, then the levels beneath it. */
export function buildSalesOfficeForest(state) {
  const children = (level, parentId, parentType) =>
    state.managers
      .filter((manager) => manager.level === level && manager.parentType === parentType && manager.parentId === parentId)
      .sort(byName)

  return state.managers
    .filter((manager) => manager.level === 3 && !manager.parentId)
    .sort(byName)
    .map((third) => ({
      manager: third,
      children: children(2, third.id, 'manager3').map((second) => ({
        manager: second,
        children: children(1, second.id, 'manager2').map((first) => ({
          manager: first,
          headquarters: state.headquarters
            .filter((office) => office.parentManagerId === first.id)
            .sort(byName)
            .map((office) => ({
              headquarters: office,
              stockists: state.stockists.filter((stockist) => stockist.headquartersId === office.id).sort(byName),
            })),
        })),
      })),
    }))
}

export function unplacedSalesOfficeRecords(state) {
  const forest = buildSalesOfficeForest(state)
  const placed = new Set()
  forest.forEach((third) => {
    placed.add(`manager:${third.manager.id}`)
    third.children.forEach((second) => {
      placed.add(`manager:${second.manager.id}`)
      second.children.forEach((first) => {
        placed.add(`manager:${first.manager.id}`)
        first.headquarters.forEach((office) => {
          placed.add(`headquarters:${office.headquarters.id}`)
          office.stockists.forEach((stockist) => placed.add(`stockist:${stockist.id}`))
        })
      })
    })
  })
  return {
    managers: state.managers.filter((manager) => !placed.has(`manager:${manager.id}`)),
    headquarters: state.headquarters.filter((office) => !placed.has(`headquarters:${office.id}`)),
    stockists: state.stockists.filter((stockist) => !placed.has(`stockist:${stockist.id}`)),
  }
}

// ---- Formatting ------------------------------------------------------------

const numberFormat = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const formatNumber = (value) => (value === null || value === undefined || value === '' ? '—' : numberFormat.format(value))

export function formatDateTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })
}

export function formatMonth(month) {
  if (!month) return '—'
  const [year, m] = month.split('-').map(Number)
  return new Date(year, m - 1, 1).toLocaleString('en-IN', { month: 'short', year: 'numeric' })
}

// Local-time 'YYYY-MM' (toISOString would use UTC and can be a month off).
export function currentMonth() {
  const today = new Date()
  return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`
}
