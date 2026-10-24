/**
 * Responsive data table inside the standard .table-container.
 * columns: [{ key, header, render?(row), align?: 'end', nowrap?: bool, srOnlyHeader?: bool }]
 * With no rows, shows `empty` (if given) or a single "emptyText" row so the
 * column structure stays visible.
 */
export default function DataTable({ columns, rows, caption, empty, emptyText = 'No records yet.', rowKey = (row) => row.id }) {
  if (rows.length === 0 && empty) return empty

  const cellClass = (column) =>
    [column.align === 'end' && 'text-end', column.nowrap && 'text-nowrap'].filter(Boolean).join(' ') || undefined

  return (
    <div className="table-container">
      <table className="table table-hover">
        {caption && <caption className="visually-hidden">{caption}</caption>}
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key} scope="col" className={cellClass(column)}>
                {column.srOnlyHeader ? <span className="visually-hidden">{column.header}</span> : column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="table-empty">{emptyText}</td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr key={rowKey(row)}>
                {columns.map((column) => (
                  <td key={column.key} className={cellClass(column)}>
                    {column.render ? column.render(row) : (row[column.key] || '—')}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  )
}
