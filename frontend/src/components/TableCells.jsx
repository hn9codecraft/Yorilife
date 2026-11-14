import { splitChips } from '../utils/chips.js'

/**
 * Shared cell layouts for DataTable (styles: theme.css "Data tables").
 *
 *   <CellStack primary="Ambica Medical" secondary="STK-AHM-001" strong />
 *   <ChipList items={['Gujarat', 'Maharashtra', 'Rajasthan']} max={2} />
 *   <StatusTag tone="danger">Expired</StatusTag>
 */

export function EmptyValue() {
  return <span className="table-empty-value">—</span>
}

/** Primary text (cut with … and a tooltip) with optional muted secondary text under it. */
export function CellStack({ primary, secondary, strong = false, title }) {
  const hasPrimary = primary != null && primary !== ''
  const hasSecondary = secondary != null && secondary !== '' && secondary !== false
  if (!hasPrimary && !hasSecondary) return <EmptyValue />
  const tooltip = title ?? (typeof primary === 'string' ? primary : undefined)
  return (
    <div className={`cell-stack${strong ? ' cell-stack--strong' : ''}`}>
      <span className="cell-stack__primary table-truncate" title={tooltip}>{hasPrimary ? primary : <EmptyValue />}</span>
      {hasSecondary && <span className="cell-stack__secondary">{secondary}</span>}
    </div>
  )
}

/** Compact chips; anything past `max` becomes a "+N" chip with the rest in its tooltip. */
export function ChipList({ items, max = 2, label }) {
  const { shown, hidden, moreLabel } = splitChips(items, max)
  if (shown.length === 0) return <EmptyValue />
  return (
    <ul className="chip-list" aria-label={label}>
      {shown.map((item) => (
        <li key={item.key} className="chip" title={item.label}>{item.label}</li>
      ))}
      {hidden.length > 0 && (
        <li className="chip chip--more" title={moreLabel}>
          <span aria-hidden="true">+{hidden.length}</span>
          <span className="visually-hidden">and {hidden.length} more: {moreLabel}</span>
        </li>
      )}
    </ul>
  )
}

/** Small status label. tone: 'danger' | 'warning' | 'success' | 'neutral'. */
export function StatusTag({ tone = 'neutral', title, children }) {
  return <span className={`status-tag status-tag--${tone}`} title={title}>{children}</span>
}