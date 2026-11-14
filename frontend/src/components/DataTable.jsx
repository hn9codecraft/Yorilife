import { Fragment, useEffect, useRef } from 'react'

/**
 * Responsive data table inside the standard .table-container.
 * columns: [{ key, header, render?(row), align?: 'end', nowrap?: bool, srOnlyHeader?: bool,
 *             width?: 'sm'|'md'|'lg'|'xl', sticky?: 'start'|'end', truncate?: bool, title?(row) }]
 *   width     min-width from the --table-col-* tokens
 *   sticky    pin the column while the table scrolls sideways
 *   truncate  one line with … ; the tooltip is title(row) or the rendered text
 *   key 'actions' is always compact, right-aligned and on one line.
 * striped (optional): zebra rows. scroll (optional): sticky header inside a max-height box.
 * Empty cell values (null, '', '—') show a muted "—".
 * renderAfterRow (optional): (row) => node shown in a full-width row under that row, or null.
 * selection (optional): { isSelected(row), onToggle(row), onToggleAll(), allSelected, indeterminate, label(row), isDisabled?(row) }
 * With no rows, shows `empty` (if given) or a single "emptyText" row so the
 * column structure stays visible.
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

export default function DataTable({ columns, rows, caption, empty, emptyText = 'No records yet.', rowKey = (row) => row.id, sort = null, onSort = null, selection = null, renderAfterRow = null, striped = false, scroll = false }) {
  const boxRef = useRef(null)
  const showEmpty = rows.length === 0 && Boolean(empty)
  useScrollEdges(boxRef, [showEmpty, rows.length, columns.length])
  if (showEmpty) return empty

  const cellClass = (column) =>
    [
      column.align === 'end' && 'text-end',
      column.nowrap && 'text-nowrap',
      column.width && `table-col--${column.width}`,
      column.sticky && `table-col--sticky-${column.sticky}`,
      column.key === 'actions' && 'table-col--actions',
    ].filter(Boolean).join(' ') || undefined
  const columnCount = columns.length + (selection ? 1 : 0)
  const stickyStart = columns.some((column) => column.sticky === 'start')
  const tableClass = [
    'table table-hover',
    striped && 'table--striped',
    stickyStart && 'table--sticky-start',
    selection && 'table--has-select',
  ].filter(Boolean).join(' ')

  return (
    <div ref={boxRef} className={`table-container${scroll ? ' table-container--scroll' : ''}`}>
      <table className={tableClass}>
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
                <th key={column.key} scope="col" className={cellClass(column)} aria-sort={ariaSort}>
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
              <td colSpan={columnCount} className="table-empty">{emptyText}</td>
            </tr>
          ) : (
            rows.map((row) => {
              const after = renderAfterRow?.(row)
              return (
              <Fragment key={rowKey(row)}>
              <tr className={selection?.isSelected(row) ? 'is-selected' : undefined}>
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
                  <td key={column.key} className={cellClass(column)} data-label={column.header}>
                    {cellContent(column, row)}
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
