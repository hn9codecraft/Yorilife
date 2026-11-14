import { useId, useState } from 'react'
import SearchInput from './SearchInput.jsx'

/**
 * List filter bar (styles: theme.css "Filters").
 *
 *   <FilterBar
 *     search={{ value, onChange, label: 'Search stockists', placeholder: '…' }}
 *     chips={[{ key: 'hq', label: 'Headquarter: Ahmedabad', onRemove }]}
 *     onClearAll={clear}
 *     shown={rows.length} total={all.length} noun="stockists"
 *     actions={<ExportCsvButton … />}
 *   >
 *     <SearchableSelect className="filter-bar__control" labelHidden … />
 *   </FilterBar>
 *
 * Desktop: one row that wraps. Phones: the controls fold behind a "Filters" button.
 * Below the row: "Showing X of Y", one chip per active filter (× removes it) and Clear all.
 */
export default function FilterBar({ search, children, actions, chips = [], onClearAll, shown, total, noun = 'records', label = 'Filters' }) {
  const id = useId()
  const [open, setOpen] = useState(false)
  const active = chips.length
  const controlsId = `${id}-controls`

  return (
    <div className="filter-bar" role="search" aria-label={label}>
      <div className="filter-bar__row">
        {search && <SearchInput value={search.value} onChange={search.onChange} label={search.label} placeholder={search.placeholder} />}
        {children && (
          <button
            type="button"
            className="btn btn-outline-secondary filter-bar__toggle"
            aria-expanded={open}
            aria-controls={controlsId}
            onClick={() => setOpen((value) => !value)}
          >
            <i className="bi bi-funnel" aria-hidden="true" />
            Filters
            {active > 0 && <span className="filter-bar__badge">{active}</span>}
          </button>
        )}
        {children && <div id={controlsId} className={`filter-bar__controls${open ? ' is-open' : ''}`}>{children}</div>}
        {actions && <div className="filter-bar__actions">{actions}</div>}
      </div>
      <div className="filter-bar__summary">
        {total != null && (
          <p className="filter-bar__count" aria-live="polite">
            Showing <strong>{shown}</strong> of {total} {noun}
          </p>
        )}
        {active > 0 && (
          <ul className="filter-bar__chips" aria-label="Active filters">
            {chips.map((chip) => (
              <li key={chip.key} className="filter-chip">
                <span className="filter-chip__label" title={chip.label}>{chip.label}</span>
                <button type="button" className="filter-chip__remove" aria-label={`Remove filter ${chip.label}`} onClick={chip.onRemove}>
                  <i className="bi bi-x" aria-hidden="true" />
                </button>
              </li>
            ))}
          </ul>
        )}
        {active > 0 && onClearAll && (
          <button type="button" className="btn btn-link btn-sm filter-bar__clear" onClick={onClearAll}>Clear all</button>
        )}
      </div>
    </div>
  )
}