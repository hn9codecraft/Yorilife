export const IMPORT_STATUS = {
  previewed: { label: 'Preview only', variant: 'secondary' },
  completed: { label: 'Completed', variant: 'success' },
  failed: { label: 'Failed', variant: 'danger' },
  rolled_back: { label: 'Rolled back', variant: 'warning' },
  partially_rolled_back: { label: 'Partly rolled back', variant: 'warning' },
}

export const ROW_ACTION = {
  created: { label: 'New', variant: 'success' },
  updated: { label: 'Update', variant: 'info' },
  unchanged: { label: 'No change', variant: 'secondary' },
  error: { label: 'Error', variant: 'danger' },
}

export function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/** File checks the browser can do before uploading (the API checks again). */
export function checkImportFile(file, maxBytes) {
  if (!file) return 'Choose an Excel (.xlsx) file.'
  if (!/\.xlsx$/i.test(file.name)) return 'Only .xlsx files can be imported (not .xls, .xlsm or .csv).'
  if (file.size === 0) return 'The file is empty.'
  if (file.size > maxBytes) return `The file is larger than ${formatBytes(maxBytes)}.`
  return null
}

/**
 * Counts for an import preview: rows per Data Type (in file order), rows with
 * warnings, and how many Data Types have rows that will be saved.
 */
export function previewSummary(rows) {
  const types = []
  const saving = new Set()
  let warnings = 0
  for (const row of rows) {
    const key = row.type ?? 'unknown'
    let group = types.find((entry) => entry.key === key)
    if (!group) {
      group = { key, label: row.typeLabel ?? 'Unknown type', total: 0, errors: 0 }
      types.push(group)
    }
    group.total += 1
    if (row.action === 'error') group.errors += 1
    if (row.action === 'created' || row.action === 'updated') saving.add(key)
    if (row.warnings?.length) warnings += 1
  }
  return { types, warnings, savingTypes: saving.size }
}

export function formatDateTime(iso) {
  if (!iso) return '\u2014'
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '\u2014'
  return date.toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })
}

/** Rollback items that can be chosen: anything the rollback would not skip anyway. */
export function selectableRollbackIds(items) {
  return items.filter((item) => item.result === 'restored' || item.result === 'deleted').map((item) => item.id)
}

/**
 * Check or uncheck one rollback item and keep the selection consistent:
 * checking a created record also checks the records this import created under it
 * (they must go first, or it cannot be deleted); unchecking a record also unchecks
 * the created records above it. Restored (updated) records do not pull in others.
 */
export function toggleRollbackItem(items, selected, id, checked) {
  const byId = new Map(items.map((item) => [item.id, item]))
  const selectable = new Set(selectableRollbackIds(items))
  const next = new Set(selected)
  const walk = (start, step) => {
    const queue = [start]
    while (queue.length) {
      const current = queue.shift()
      if (!selectable.has(current)) continue
      if (checked) {
        if (current !== start && next.has(current)) continue
        next.add(current)
      } else {
        if (current !== start && !next.has(current)) continue
        next.delete(current)
      }
      queue.push(...step(current))
    }
  }
  if (checked) {
    walk(id, (current) => (byId.get(current)?.action === 'created' ? byId.get(current).children ?? [] : []))
  } else {
    walk(id, (current) => items.filter((item) => item.action === 'created' && (item.children ?? []).includes(current)).map((item) => item.id))
  }
  return next
}

/** Counts for the selected rollback items. */
export function rollbackSelectionSummary(items, selected) {
  let restore = 0
  let remove = 0
  for (const item of items) {
    if (!selected.has(item.id)) continue
    if (item.result === 'restored') restore += 1
    if (item.result === 'deleted') remove += 1
  }
  const selectable = selectableRollbackIds(items).length
  return { restore, delete: remove, skip: items.length - selectable, notSelected: selectable - restore - remove }
}

/** Created records this import made under a created record (all levels), in item order. */
export function linkedRollbackItems(items, id) {
  const byId = new Map(items.map((item) => [item.id, item]))
  const found = new Set()
  const queue = [...(byId.get(id)?.action === 'created' ? byId.get(id).children ?? [] : [])]
  while (queue.length) {
    const current = queue.shift()
    if (found.has(current) || current === id) continue
    found.add(current)
    queue.push(...(byId.get(current)?.children ?? []))
  }
  return items.filter((item) => found.has(item.id))
}