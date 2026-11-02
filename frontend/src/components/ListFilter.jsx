import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react'

const empty = { stateIds: [], reportIds: [] }

function isSelected(selected, id) {
  return selected.some((item) => String(item) === String(id))
}

function selectionText(selected, options, placeholder) {
  const names = selected
    .map((id) => options.find((option) => String(option.id) === String(id))?.name)
    .filter(Boolean)
  if (names.length === 0) return { text: placeholder, empty: true }
  if (names.length <= 2) return { text: names.join(', '), empty: false }
  return { text: `${names.length} selected`, empty: false }
}

function MultiSelect({ label, placeholder, options, selected, onChange, emptyText, open, onOpenChange }) {
  const labelId = useId()
  const buttonId = useId()
  const menuId = useId()
  const rootRef = useRef(null)
  const onOpenChangeRef = useRef(onOpenChange)
  const summary = selectionText(selected, options, placeholder)

  useEffect(() => {
    onOpenChangeRef.current = onOpenChange
  }, [onOpenChange])

  useEffect(() => {
    if (!open) return undefined
    const close = (event) => {
      if (!rootRef.current?.contains(event.target)) onOpenChangeRef.current(false)
    }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  function toggle(id) {
    onChange(isSelected(selected, id)
      ? selected.filter((item) => String(item) !== String(id))
      : [...selected, id])
  }

  return (
    <div className="list-filter__field" ref={rootRef}>
      <label className="form-label" id={labelId} htmlFor={buttonId}>{label}</label>
      <div className="list-filter__control">
        <button
          id={buttonId}
          type="button"
          className={`form-select form-select-sm list-filter__toggle${summary.empty ? ' is-placeholder' : ''}`}
          aria-label={`${label}: ${summary.text}`}
          aria-haspopup="true"
          aria-expanded={open}
          aria-controls={menuId}
          disabled={options.length === 0}
          onClick={() => onOpenChange(!open)}
        >
          <span className="truncate">{summary.text}</span>
        </button>
        {open && options.length > 0 && (
          <div className="list-filter__menu" id={menuId} role="group" aria-labelledby={labelId}>
            {options.map((option) => {
              const checked = isSelected(selected, option.id)
              return (
                <label key={option.id} className="list-filter__option">
                  <input
                    type="checkbox"
                    className="form-check-input"
                    checked={checked}
                    onChange={() => toggle(option.id)}
                  />
                  <span>{option.name}</span>
                </label>
              )
            })}
          </div>
        )}
      </div>
      {options.length === 0 && <p className="text-muted small mb-0">{emptyText}</p>}
    </div>
  )
}

/**
 * Filter button beside a list search box.
 * `reports` is null when the list has no parent (3rd line managers).
 * The filter icon opens one dropdown panel. Choices stay in draft until Apply.
 * Closing the panel keeps that draft until the user applies or clears it.
 */
export default function ListFilter({ locations, reports = null, applied, onApply }) {
  const controlsId = useId()
  const rootRef = useRef(null)
  const panelRef = useRef(null)
  const panel = true
  const [open, setOpen] = useState(false)
  const [menu, setMenu] = useState(null)
  const [draft, setDraft] = useState(applied ?? empty)
  const activeCount = (applied?.stateIds?.length ?? 0) + (applied?.reportIds?.length ?? 0)

  useEffect(() => {
    if (!open) return undefined
    const onKey = (event) => {
      if (event.key !== 'Escape') return
      if (menu) {
        setMenu(null)
        return
      }
      setOpen(false)
    }
    const close = (event) => {
      if (!panel || rootRef.current?.contains(event.target)) return
      setOpen(false)
    }
    document.addEventListener('keydown', onKey)
    document.addEventListener('mousedown', close)
    return () => {
      document.removeEventListener('keydown', onKey)
      document.removeEventListener('mousedown', close)
    }
  }, [open, menu, panel])

  useLayoutEffect(() => {
    if (!panel || !open) return undefined
    const place = () => {
      const button = rootRef.current?.querySelector(':scope > button')
      const panelEl = panelRef.current
      if (!button || !panelEl) return
      const margin = 16
      const box = button.getBoundingClientRect()
      const rootSize = Number.parseFloat(getComputedStyle(document.documentElement).fontSize) || 16
      const single = panelEl.querySelectorAll('.list-filter__field').length < 2
      const width = Math.min((single ? 16 : 36) * rootSize, window.innerWidth - margin * 2)
      let left = box.right - width
      if (left < margin) left = margin
      if (left + width > window.innerWidth - margin) left = Math.max(margin, window.innerWidth - margin - width)
      panelEl.style.width = `${width}px`
      panelEl.style.right = 'auto'
      panelEl.style.left = `${left - box.left}px`
      const menuEl = panelEl.querySelector('.list-filter__menu')
      if (!menuEl) return
      const cap = 14 * rootSize
      const available = window.innerHeight - menuEl.getBoundingClientRect().top - margin
      menuEl.style.maxHeight = `${Math.max(0, Math.min(cap, available))}px`
    }
    const panelEl = panelRef.current
    place()
    window.addEventListener('resize', place)
    return () => {
      window.removeEventListener('resize', place)
      if (panelEl) {
        panelEl.style.width = ''
        panelEl.style.left = ''
        panelEl.style.right = ''
      }
    }
  }, [panel, open, menu])

  const toggle = () => {
    setMenu(null)
    setOpen((current) => {
      if (!current && !panel) setDraft(applied ?? empty)
      return !current
    })
  }

  const showMenu = (key) => (next) => setMenu(next ? key : null)
  const fields = (
    <>
      <MultiSelect
        label="State"
        placeholder="Select state"
        options={locations.map((location) => ({ id: location.id, name: location.name }))}
        selected={draft.stateIds}
        onChange={(stateIds) => setDraft((current) => ({ ...current, stateIds }))}
        emptyText="No states yet."
        open={menu === 'state'}
        onOpenChange={showMenu('state')}
      />
      {reports && (
        <MultiSelect
          label="Reports To"
          placeholder="Select manager"
          options={reports.options}
          selected={draft.reportIds}
          onChange={(reportIds) => setDraft((current) => ({ ...current, reportIds }))}
          emptyText={`No ${reports.label.toLowerCase()} yet.`}
          open={menu === 'reports'}
          onOpenChange={showMenu('reports')}
        />
      )}
      <div className="list-filter__actions">
        <button
          type="button"
          className="btn btn-sm btn-outline-secondary"
          onClick={() => {
            setDraft(empty)
            onApply(empty)
            setOpen(false)
          }}
        >
          Clear Filters
        </button>
        <button
          type="button"
          className="btn btn-sm btn-primary"
          onClick={() => {
            onApply(draft)
            setOpen(false)
          }}
        >
          Apply
        </button>
      </div>
    </>
  )

  return (
    <div className="list-filter list-filter--panel" ref={rootRef}>
      <button
        type="button"
        className={`btn btn-outline-secondary btn-icon${activeCount ? ' is-active' : ''}`}
        aria-label={activeCount ? `Filter list, ${activeCount} active` : 'Filter list'}
        aria-expanded={open}
        aria-controls={controlsId}
        onClick={toggle}
      >
        <i className="bi bi-funnel" aria-hidden="true" />
        {activeCount > 0 && <span className="list-filter__count">{activeCount}</span>}
      </button>
      {open && (
        <div className="list-filter__panel" id={controlsId} role="dialog" aria-label="Filter list" ref={panelRef}>
          <div className="list-filter__fields">{fields}</div>
        </div>
      )}
    </div>
  )
}
