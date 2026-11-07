import { Fragment, useEffect, useRef } from 'react'

/**
 * Responsive data table inside the standard .table-container.
 * columns: [{ key, header, render?(row), align?: 'end', nowrap?: bool, srOnlyHeader?: bool }]
 * renderAfterRow (optional): (row) => node shown in a full-width row under that row, or null.
 * selection (optional): { isSelected(row), onToggle(row), onToggleAll(), allSelected, indeterminate, label(row), isDisabled?(row) }
 * With no rows, shows `empty` (if given) or a single "emptyText" row so the
 * column structure stays visible.
 */
function sortLabel(column, sort) {
  if (!sort || sort.key !== column.key) return `${column.header}, not sorted`
  return `${column.header}, sorted ${sort.direction === 'asc' ? 'ascending' : 'descending'}`
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

export default function DataTable({ columns, rows, caption, empty, emptyText = 'No records yet.', rowKey = (row) => row.id, sort = null, onSort = null, selection = null, renderAfterRow = null }) {
  if (rows.length === 0 && empty) return empty

  const cellClass = (column) =>
    [column.align === 'end' && 'text-end', column.nowrap && 'text-nowrap'].filter(Boolean).join(' ') || undefined
  const columnCount = columns.length + (selection ? 1 : 0)

  return (
    <div className="table-container">
      <table className="table table-hover">
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
                    {column.render ? column.render(row) : (row[column.key] || '—')}
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
