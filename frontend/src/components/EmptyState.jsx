// Placeholder shown when a list or table has no records yet.
// `titleAs` sets the heading level so it fits the page outline (default h3).
export default function EmptyState({ icon = 'bi-inbox', title, text, action, titleAs: Title = 'h3' }) {
  return (
    <div className="empty-state">
      <span className="empty-state__icon" aria-hidden="true">
        <i className={`bi ${icon}`} />
      </span>
      <Title className="empty-state__title">{title}</Title>
      {text && <p className="empty-state__text">{text}</p>}
      {action}
    </div>
  )
}
