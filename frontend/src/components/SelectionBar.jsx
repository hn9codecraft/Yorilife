/** Shown while one or more table rows are checked. */
export default function SelectionBar({ count, onDelete, onClear }) {
  if (!count) return null

  return (
    <div className="selection-bar" role="region" aria-label="Selection">
      <p className="selection-bar__count">{count} selected</p>
      <button type="button" className="btn btn-sm btn-danger" onClick={onDelete}>Delete selected</button>
      <button type="button" className="btn btn-sm btn-outline-secondary" onClick={onClear}>Clear</button>
    </div>
  )
}
