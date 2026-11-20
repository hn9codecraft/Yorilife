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
 * Pixel width of a name column: the longest label as a header or as a cell,
 * plus the table's horizontal cell padding. Capped in CSS at the pinned max.
 */
export function measureNameColumnWidth(labels = []) {
  if (typeof document === 'undefined') return undefined
  const header = document.createElement('span')
  const cell = document.createElement('span')
  header.className = 'table-measure table-measure--header'
  cell.className = 'table-measure table-measure--cell'
  const padProbe = document.createElement('div')
  padProbe.className = 'table-measure'
  padProbe.style.width = 'var(--table-cell-padding-x)'
  document.body.append(header, cell, padProbe)
  let text = 0
  for (const label of labels) {
    const value = String(label ?? '').trim()
    if (!value) continue
    header.textContent = value
    cell.textContent = value
    text = Math.max(text, header.getBoundingClientRect().width, cell.getBoundingClientRect().width)
  }
  const pad = padProbe.getBoundingClientRect().width
  header.remove()
  cell.remove()
  padProbe.remove()
  if (text <= 0) return undefined
  return `${Math.ceil(text + pad * 2)}px`
}

/** calc() of every column's min-width (plus the select column), or undefined. */
export function tableMinWidth(columns = [], withSelection = false, nameColumn) {
  const parts = columns.map((column) => (
    column.sticky === 'start' && nameColumn ? nameColumn : `var(${columnToken(column)})`
  ))
  if (withSelection && parts.length) parts.unshift('var(--table-select-col-width)')
  return parts.length ? `calc(${parts.join(' + ')})` : undefined
}