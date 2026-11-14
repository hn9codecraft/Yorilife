// Minimum width of a DataTable: the sum of its column min-widths (CSS
// variables from variable.css), so no column can slide under a pinned column.
// Pinned columns count only their small minimum: they size to their content.
const WIDTH_TOKENS = { sm: '--table-col-sm', md: '--table-col-md', lg: '--table-col-lg', xl: '--table-col-xl', money: '--table-col-money' }

function columnToken(column) {
  if (column.key === 'actions' || column.sticky === 'end') return '--table-col-actions'
  if (column.sticky === 'start') return '--table-col-pinned-min'
  return WIDTH_TOKENS[column.width] ?? '--table-col-auto'
}

/** calc() of every column's min-width (plus the select column), or undefined. */
export function tableMinWidth(columns = [], withSelection = false) {
  const parts = columns.map((column) => `var(${columnToken(column)})`)
  if (withSelection && parts.length) parts.unshift('var(--table-select-col-width)')
  return parts.length ? `calc(${parts.join(' + ')})` : undefined
}