// Card wrapper for a list: title + toolbar (filters, search, buttons) + table.
// This is the one list-card look for the whole app (Sales Office lists).
//   title  "Products"; count (optional) is shown as "Products (12)"
//   toolbar  buttons on the right of the card header (e.g. this table's Add button)
export default function TableCard({ title, count, toolbar, children, footer, className = '' }) {
  return (
    <section className={`card table-card${className ? ` ${className}` : ''}`}>
      <div className="card-header">
        <h2 className="card-title">{count != null ? `${title} (${count})` : title}</h2>
        {toolbar && <div className="toolbar">{toolbar}</div>}
      </div>
      {children}
      {footer && <div className="card-footer bg-surface">{footer}</div>}
    </section>
  )
}