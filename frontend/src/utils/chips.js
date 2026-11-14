/**
 * Split a list for compact chips: the first `max` items are shown, the rest
 * become one "+N" chip whose tooltip lists them. Blank labels are skipped.
 * items: strings or { key, label }.
 */
export function splitChips(items, max = 2) {
  const list = (items ?? [])
    .map((item, index) => (typeof item === 'object' && item !== null
      ? { key: item.key ?? index, label: String(item.label ?? '').trim() }
      : { key: index, label: String(item ?? '').trim() }))
    .filter((item) => item.label !== '')
  const limit = Math.max(1, max)
  const shown = list.length > limit + 1 ? list.slice(0, limit) : list
  const hidden = list.slice(shown.length)
  return { shown, hidden, moreLabel: hidden.map((item) => item.label).join(', ') }
}