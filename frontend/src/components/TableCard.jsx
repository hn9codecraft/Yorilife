// Card wrapper for a list: title + toolbar (filters, search, buttons) + table.
export default function TableCard({ title, toolbar, children, footer, className = '' }) {
  return (
    <section className={`card table-card${className ? ` ${className}` : ''}`}>
      <div className="card-header">
        <h2 className="card-title">{title}</h2>
        {toolbar && <div className="toolbar">{toolbar}</div>}
      </div>
      {children}
      {footer && <div className="card-footer bg-surface">{footer}</div>}
    </section>
  )
}
