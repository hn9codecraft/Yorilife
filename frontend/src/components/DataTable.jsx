import { Fragment, useEffect, useLayoutEffect, useRef } from 'react'
import EmptyState from './EmptyState.jsx'
import { measureNameColumnWidth, stickyEndRight, tableMinWidth } from '../utils/tableWidth.js'

/**
 * Responsive data table inside the standard .table-container.
 * columns: [{ key, header, render?(row), align?: 'end', nowrap?: bool, srOnlyHeader?: bool,
 *             width?: 'sm'|'md'|'lg'|'xl'|'money', sticky?: 'start'|'end', truncate?: bool, title?(row) }]
 *   width     min-width from the --table-col-* tokens ('money': right-aligned amount)
 *   The table's min-width is the sum of its column widths, so no column can
 *   hide under a pinned column; narrower screens scroll sideways instead.
 *   sticky    pin the column while the table scrolls sideways.
 *             Several 'end' columns stack from the right (Total, then Actions).
 *   truncate  one line with … ; the tooltip is title(row) or the rendered text
 *   compact   as narrow as its content (one line); wide columns take the rest
 *   key 'actions' is always compact, right-aligned and on one line.
 * striped (optional): zebra rows.
 * nameColumn (optional): shared width for the pinned first column, from
 *   measureNameColumnWidth. Sibling tables pass the same value so they line up.
 *   Without it, the column is measured from this table's own header and cells.
 * scroll (optional): sticky header inside a max-height box. Lists leave this off
 *   so the table grows to its rows. Bonus Calc's year table still opts in.
 * Empty cell values (null, '', '—') show a muted "—".
 * renderAfterRow (optional): (row) => node shown in a full-width row under that row, or null.
 * selection (optional): { isSelected(row), onToggle(row), onToggleAll(), allSelected, indeterminate, label(row), isDisabled?(row) }
 * With no rows, shows `empty` (if given) or a shared EmptyState row under the
 * headers: icon (emptyIcon), title (emptyText), hint (emptyHint) and an
 * optional button (emptyAction).
 */
function sortLabel(column, sort) {
  if (!sort || sort.key !== column.key) return `${column.header}, not sorted`
  return `${column.header}, sorted ${sort.direction === 'asc' ? 'ascending' : 'descending'}`
}

const EMPTY = <span className="table-empty-value">—</span>

function isBlank(value) {
  return value == null || value === false || value === '' || value === '—' || value === '-'
}

function cellContent(column, row) {
  const value = column.render ? column.render(row) : row[column.key]
  if (column.key === 'actions') return value || null
  if (isBlank(value)) return EMPTY
  if (!column.truncate) return value
  const title = column.title?.(row) ?? (typeof value === 'string' || typeof value === 'number' ? String(value) : undefined)
  return <span className="table-truncate" title={title}>{value}</span>
}

/** Tooltip for the pinned first column, whose content is cut with … past its max width. */
function pinnedTitle(column, row) {
  const title = column.title?.(row)
  if (title) return title
  const value = row[column.key]
  return typeof value === 'string' || typeof value === 'number' ? String(value) : undefined
}

function SelectCheckbox({ checked, indeterminate = false, onChange, label, disabled = false }) {
  const ref = useRef(null)

  useEffect(() => {
    if (ref.current) ref.current.indeterminate = Boolean(indeterminate) && !checked
  }, [indeterminate, checked])

  return (
    <input
      ref={ref}
      type="checkbox"
      className="form-check-input table-select"
      checked={checked}
      disabled={disabled}
      aria-label={label}
      onChange={onChange}
    />
  )
}

/**
 * Mark the scroll wrapper with is-scrolled-start / has-more-end so pinned
 * columns show an edge shadow only while content is hidden behind them.
 */
function useScrollEdges(ref, deps) {
  useEffect(() => {
    const box = ref.current
    if (!box) return undefined
    const update = () => {
      const max = box.scrollWidth - box.clientWidth
      box.classList.toggle('is-scrolled-start', box.scrollLeft > 1)
      box.classList.toggle('has-more-end', max - box.scrollLeft > 1)
    }
    update()
    box.addEventListener('scroll', update, { passive: true })
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(update)
    observer?.observe(box)
    if (box.firstElementChild) observer?.observe(box.firstElementChild)
    return () => {
      box.removeEventListener('scroll', update)
      observer?.disconnect()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)
}


export default function DataTable({ columns, rows, caption, empty, emptyText = 'No records yet.', emptyHint = null, emptyIcon = 'bi-inbox', emptyAction = null, rowKey = (row) => row.id, rowClassName = null, sort = null, onSort = null, selection = null, renderAfterRow = null, striped = false, nameColumn, scroll = false, scrollAfter = 10 }) {
  const boxRef = useRef(null)
  const showEmpty = rows.length === 0 && Boolean(empty)
  useScrollEdges(boxRef, [showEmpty, rows.length, columns.length])
  useLayoutEffect(() => {
    const table = boxRef.current?.querySelector('table')
    if (!table || scroll) return undefined
    if (nameColumn) {
      table.style.setProperty('--table-name-col', nameColumn)
      return undefined
    }
    const labels = [...table.querySelectorAll('.table-col--sticky-start')].map((cell) => cell.innerText.replace(/\s+/g, ' ').trim())
    const width = measureNameColumnWidth(labels)
    if (width) table.style.setProperty('--table-name-col', width)
    return undefined
  }, [nameColumn, scroll, rows, columns])
  if (showEmpty) return empty

  const scrolling = scroll && rows.length > scrollAfter
  const stickyEnds = columns.filter((column) => column.sticky === 'end')
  const stickyEndEdge = stickyEnds[0]?.key
  const stickyEndLast = stickyEnds[stickyEnds.length - 1]?.key
  const cellClass = (column) =>
    [
      (column.align === 'end' || column.width === 'money') && 'text-end',
      column.width === 'money' && 'text-nowrap',
      column.nowrap && 'text-nowrap',
      column.width && `table-col--${column.width}`,
      column.sticky && `table-col--sticky-${column.sticky}`,
      column.sticky === 'end' && column.key === stickyEndEdge && 'table-col--sticky-end-edge',
      column.sticky === 'end' && column.key === stickyEndLast && 'table-col--sticky-end-last',
      column.key === 'actions' && 'table-col--actions',
      column.compact && 'table-col--compact',
    ].filter(Boolean).join(' ') || undefined
  const cellStyle = (column) => (column.sticky === 'end' ? { right: stickyEndRight(columns, column) } : undefined)
  const columnCount = columns.length + (selection ? 1 : 0)
  const stickyStart = columns.some((column) => column.sticky === 'start')
  const tableClass = [
    'table table-hover',
    striped && 'table--striped',
    stickyStart && 'table--sticky-start',
    selection && 'table--has-select',
  ].filter(Boolean).join(' ')

  return (
    <div ref={boxRef} className={`table-container${scrolling ? ' table-container--scroll' : ''}`}>
      <table className={tableClass} style={{ minWidth: tableMinWidth(columns, Boolean(selection)) }}>
        {caption && <caption className="visually-hidden">{caption}</caption>}
        <thead>
          <tr>
            {selection && (
              <th scope="col" className="table-select-cell">
                <SelectCheckbox
                  checked={selection.allSelected}
                  indeterminate={selection.indeterminate}
                  disabled={rows.length === 0}
                  label="Select all"
                  onChange={selection.onToggleAll}
                />
              </th>
            )}
            {columns.map((column) => {
              const active = Boolean(sort && column.sortable && sort.key === column.key)
              const ariaSort = column.sortable && sort
                ? (active ? (sort.direction === 'asc' ? 'ascending' : 'descending') : 'none')
                : undefined
              return (
                <th key={column.key} scope="col" className={cellClass(column)} style={cellStyle(column)} aria-sort={ariaSort}>
                  {column.srOnlyHeader ? <span className="visually-hidden">{column.header}</span> : column.sortable && onSort ? (
                    <button type="button" className={`table-sort${active ? ' is-active' : ''}`} aria-label={sortLabel(column, active ? sort : null)} onClick={() => onSort(column.key)}>
                      {column.header}
                      <i className={`bi ${active ? (sort.direction === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up'}`} aria-hidden="true" />
                    </button>
                  ) : column.header}
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={columnCount} className="table-empty">
                <div className="table-empty__inner">
                  <EmptyState icon={emptyIcon} title={emptyText} text={emptyHint} action={emptyAction} titleAs="p" variant="table" />
                </div>
              </td>
            </tr>
          ) : (
            rows.map((row) => {
              const after = renderAfterRow?.(row)
              return (
              <Fragment key={rowKey(row)}>
              <tr className={[selection?.isSelected(row) ? 'is-selected' : '', rowClassName?.(row)].filter(Boolean).join(' ') || undefined}>
                {selection && (
                  <td className="table-select-cell" data-label="Select">
                    <SelectCheckbox
                      checked={selection.isSelected(row)}
                      label={selection.label(row)}
                      disabled={Boolean(selection.isDisabled?.(row))}
                      onChange={() => selection.onToggle(row)}
                    />
                  </td>
                )}
                {columns.map((column) => (
                  <td key={column.key} className={cellClass(column)} style={cellStyle(column)} data-label={column.header}>
                    {column.sticky === 'start'
                      ? <span className="table-pinned" title={pinnedTitle(column, row)}>{cellContent(column, row)}</span>
                      : cellContent(column, row)}
                  </td>
                ))}
              </tr>
              {after && (
                <tr className="table-subrow">
                  <td colSpan={columnCount}>{after}</td>
                </tr>
              )}
              </Fragment>
              )
            })
          )}
        </tbody>
      </table>
    </div>
  )
}
