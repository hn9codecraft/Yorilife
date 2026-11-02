import { NAV_ITEMS } from '../config/navigation.js'
import { safeRedirect } from '../auth/safeRedirect.js'

const PAGE_LIMIT = 8

/**
 * Pages from NAV_MENU the user can open. A match is the page label or the
 * label of a group that contains it. Standalone routes are not in the menu.
 */
export function searchPages(query, hasPermission) {
  const q = query.trim().toLowerCase()
  if (!q) return []

  return NAV_ITEMS
    .filter((item) => !item.permission || hasPermission(item.permission))
    .filter((item) => [item.label, ...item.parents.map((parent) => parent.label)]
      .some((label) => label.toLowerCase().includes(q)))
    .slice(0, PAGE_LIMIT)
    .map((item) => ({
      id: `page:${item.path}`,
      label: item.label,
      detail: item.parents.map((parent) => parent.label).join(' · '),
      path: item.path,
    }))
}

/** Keep record groups from GET /api/search that point at an in-app path. */
export function acceptedRecordGroups(groups) {
  if (!Array.isArray(groups)) return []

  return groups.flatMap((group) => {
    if (!group || typeof group.label !== 'string' || typeof group.type !== 'string' || !Array.isArray(group.items)) {
      return []
    }
    const items = group.items.flatMap((item) => {
      if (!item || typeof item.id !== 'string' || typeof item.label !== 'string' || typeof item.path !== 'string') {
        return []
      }
      const path = safeRedirect(item.path)
      if (path === '/dashboard' && item.path !== '/dashboard') return []
      return [{
        id: item.id,
        label: item.label,
        detail: typeof item.detail === 'string' ? item.detail : '',
        path,
      }]
    })
    return items.length ? [{ type: group.type, label: group.label, items }] : []
  })
}

export function buildSearchGroups(pageItems, recordGroups) {
  const groups = []
  if (pageItems.length > 0) groups.push({ type: 'pages', label: 'Pages', items: pageItems })
  groups.push(...acceptedRecordGroups(recordGroups))
  return groups
}

export function flattenResults(groups) {
  return groups.flatMap((group) => group.items.map((item) => ({ ...item, groupLabel: group.label })))
}
