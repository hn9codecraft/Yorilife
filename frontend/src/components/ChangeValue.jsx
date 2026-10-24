// "old → new" display for audit/history rows. Empty values show as "—".
export default function ChangeValue({ from, to, format = (value) => value }) {
  const show = (value) => (value === null || value === undefined || value === '' ? '—' : format(value))
  return (
    <span className="change-value">
      <span className="change-value__old">{show(from)}</span>
      <i className="bi bi-arrow-right change-value__arrow" aria-hidden="true" />
      <span className="visually-hidden"> changed to </span>
      <span className="change-value__new">{show(to)}</span>
    </span>
  )
}
