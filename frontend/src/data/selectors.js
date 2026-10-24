import { MANAGER_LEVELS, PARENT_TYPES } from './constants.js'

// ---- Generic ---------------------------------------------------------------

export const byName = (a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })

export const findById = (list, id) => list.find((item) => item.id === id) ?? null

export const activeOnly = (list) => list.filter((item) => !item.deletedAt)

export function matchesSearch(record, query, fields = ['name', 'code']) {
  const q = query.trim().toLowerCase()
  if (!q) return true
  return fields.some((field) => String(record[field] ?? '').toLowerCase().includes(q))
}

// ---- Sales Office hierarchy -------------------------------------------------

export const managersAtLevel = (state, level) => state.managers.filter((m) => m.level === level)

// The record a manager reports to (a headquarters or another manager).
export function getParent(state, manager) {
  if (!manager) return null
  return manager.parentType === 'headquarters'
    ? findById(state.headquarters, manager.parentId)
    : findById(state.managers, manager.parentId)
}

/**
 * Reporting chain from a manager up to its headquarters, e.g. for a 3rd line
 * manager linked to a 2nd line manager:
 *   [3rd line (self), 2nd line, 1st line, headquarters]
 * Each step: { type: 'manager1'|'manager2'|'manager3'|'headquarters', label, record }.
 * Stops early if a link is missing.
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
 * Tree of everyone under one headquarters:
 *   HQ ─┬─ 1st line ─┬─ 2nd line ── 3rd line (linked to 2nd)
 *       │            └─ 3rd line (linked to 1st)
 *       └─ 3rd line (linked directly to HQ)
 * plus the stockists mapped to the HQ.
 */
export function buildHeadquartersTree(state, headquartersId) {
  const childrenOf = (parentType, parentId) =>
    state.managers
      .filter((m) => m.parentType === parentType && m.parentId === parentId)
      .sort((a, b) => a.level - b.level || byName(a, b))

  const toNode = (manager) => ({
    manager,
    children: childrenOf(`manager${manager.level}`, manager.id).map(toNode),
  })

  return {
    headquarters: findById(state.headquarters, headquartersId),
    managers: childrenOf('headquarters', headquartersId).map(toNode),
    stockists: state.stockists.filter((s) => s.headquartersId === headquartersId).sort(byName),
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
