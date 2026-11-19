// Minimum width of a DataTable: the sum of its column min-widths (CSS
// variables from variable.css), so no column can slide under a pinned column.
// Pinned columns count only their small minimum: they size to their content.
const WIDTH_TOKENS = { sm: '--table-col-sm', md: '--table-col-md', lg: '--table-col-lg', xl: '--table-col-xl', money: '--table-col-money' }

function columnToken(column) {
  if (column.key === 'actions') return '--table-col-actions'
  if (column.sticky === 'start') return '--table-col-pinned-min'
  if (column.sticky === 'end') return WIDTH_TOKENS[column.width] ?? '--table-col-actions'
  return WIDTH_TOKENS[column.width] ?? '--table-col-auto'
}

/**
 * CSS `right` for a column pinned to the end. The last one sits on the edge.
 * Any pinned column to its left is offset by the widths of the pinned columns
 * after it, so Total can sit beside Actions instead of covering it.
 */
export function stickyEndRight(columns = [], column) {
  if (column?.sticky !== 'end') return undefined
  const pinned = columns.filter((item) => item.sticky === 'end')
  const index = pinned.findIndex((item) => item.key === column.key)
  if (index < 0) return undefined
  const after = pinned.slice(index + 1)
  if (after.length === 0) return '0px'
  return `calc(${after.map((item) => `var(${columnToken(item)})`).join(' + ')})`
}

/**
 * Width for a name column shared by sibling tables. Long enough for the
 * longest label, then clamped by the pinned min and max in CSS.
 */
export function contentColumnWidth(labels = []) {
  const longest = labels.reduce((max, label) => Math.max(max, [...String(label ?? '')].length), 0)
  return `calc(${longest}ch + 2 * var(--table-cell-padding-x))`
}

/** calc() of every column's min-width (plus the select column), or undefined. */
export function tableMinWidth(columns = [], withSelection = false) {
  const parts = columns.map((column) => `var(${columnToken(column)})`)
  if (withSelection && parts.length) parts.unshift('var(--table-select-col-width)')
  return parts.length ? `calc(${parts.join(' + ')})` : undefined
}