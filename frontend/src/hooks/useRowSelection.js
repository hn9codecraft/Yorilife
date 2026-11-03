import { useCallback, useState } from 'react'

const defaultRowKey = (row) => row.id

function withoutHidden(selected, visible) {
  let changed = false
  const next = new Set()
  selected.forEach((id) => {
    if (visible.has(id)) next.add(id)
    else changed = true
  })
  return changed ? next : selected
}

/**
 * Checkbox selection for the rows currently on screen.
 * A row that search or a filter hides is dropped from the selection.
 */
export default function useRowSelection(rows, rowKey = defaultRowKey) {
  const [selected, setSelected] = useState(() => new Set())
  const visibleIds = rows.map((row) => String(rowKey(row)))
  const visibleKey = visibleIds.join('\u0000')
  const shown = withoutHidden(selected, new Set(visibleIds))

  if (shown !== selected) setSelected(shown)

  const selectedIds = [...shown]
  const visibleSelectedCount = visibleIds.filter((id) => shown.has(id)).length
  const allSelected = visibleIds.length > 0 && visibleSelectedCount === visibleIds.length

  const toggle = useCallback((id) => {
    const key = String(id)
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }, [])

  const toggleAll = useCallback(() => {
    const ids = visibleKey === '' ? [] : visibleKey.split('\u0000')
    setSelected((current) => {
      const every = ids.length > 0 && ids.every((id) => current.has(id))
      const next = new Set(current)
      if (every) ids.forEach((id) => next.delete(id))
      else ids.forEach((id) => next.add(id))
      return next
    })
  }, [visibleKey])

  const clear = useCallback(() => setSelected(new Set()), [])
  const isSelected = useCallback((id) => shown.has(String(id)), [shown])

  return {
    selectedIds,
    count: selectedIds.length,
    isSelected,
    toggle,
    toggleAll,
    clear,
    allSelected,
    indeterminate: visibleSelectedCount > 0 && !allSelected,
  }
}
