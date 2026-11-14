// Placeholder shown when a list or table has no records yet.
// `titleAs` sets the heading level so it fits the page outline (default h3).
// variant="table": borderless and compact, for the empty row inside a DataTable.
export default function EmptyState({ icon = 'bi-inbox', title, text, action, titleAs: Title = 'h3', variant }) {
  return (
    <div className={`empty-state${variant ? ` empty-state--${variant}` : ''}`}>
      <span className="empty-state__icon" aria-hidden="true">
        <i className={`bi ${icon}`} />
      </span>
      <Title className="empty-state__title">{title}</Title>
      {text && <p className="empty-state__text">{text}</p>}
      {action}
    </div>
  )
}
