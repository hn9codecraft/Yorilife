import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { api } from '../api/client.js'
import useAuth from '../auth/useAuth.js'
import { safeRedirect } from '../auth/safeRedirect.js'
import { buildSearchGroups, flattenResults, searchPages } from '../search/globalSearch.js'

const DEBOUNCE_MS = 200
const NO_RECORDS = []

/**
 * Header search. Pages come from the menu the user can open. Records come
 * from GET /api/search, which applies the same view permissions as the modules.
 */
export default function GlobalSearch() {
  const { hasPermission } = useAuth()
  const navigate = useNavigate()
  const rootRef = useRef(null)
  const requestRef = useRef(0)
  const listId = useId()
  const inputId = useId()
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const [recordGroups, setRecordGroups] = useState([])
  const [recordStatus, setRecordStatus] = useState('idle')
  const [activeIndex, setActiveIndex] = useState(0)
  const [trackedQuery, setTrackedQuery] = useState('')
  const [trackedKey, setTrackedKey] = useState('')

  const trimmed = query.trim()
  // Drop the previous query's records during render so a new query never shows them.
  if (trackedQuery !== trimmed) {
    setTrackedQuery(trimmed)
    setRecordGroups(NO_RECORDS)
    setRecordStatus(trimmed === '' ? 'idle' : 'loading')
  }
  const activeRecords = trackedQuery !== trimmed || trimmed === '' ? NO_RECORDS : recordGroups
  const activeStatus = trimmed === '' ? 'idle' : (trackedQuery !== trimmed ? 'loading' : recordStatus)
  const pageItems = useMemo(() => searchPages(trimmed, hasPermission), [trimmed, hasPermission])
  const groups = useMemo(() => buildSearchGroups(pageItems, activeRecords), [pageItems, activeRecords])
  const rows = useMemo(() => flattenResults(groups), [groups])
  const resultKey = rows.map((row) => row.id).join('\n')
  if (resultKey !== trackedKey) {
    setTrackedKey(resultKey)
    setActiveIndex(rows.length > 0 ? 0 : -1)
  }
  const indexById = useMemo(() => new Map(rows.map((row, index) => [row.id, index])), [rows])

  useEffect(() => {
    if (!trimmed) return undefined

    const requestId = requestRef.current + 1
    requestRef.current = requestId
    let cancelled = false
    const timer = setTimeout(() => {
      api(`/api/search?q=${encodeURIComponent(trimmed)}`)
        .then((data) => {
          if (cancelled || requestId !== requestRef.current) return
          setRecordGroups(Array.isArray(data?.groups) ? data.groups : [])
          setRecordStatus('ready')
        })
        .catch((error) => {
          if (cancelled || requestId !== requestRef.current || error?.name === 'AbortError') return
          setRecordGroups([])
          setRecordStatus('error')
        })
    }, DEBOUNCE_MS)

    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [trimmed])

  useEffect(() => {
    if (!open) return undefined
    function onPointerDown(event) {
      if (!rootRef.current?.contains(event.target)) setOpen(false)
    }
    document.addEventListener('pointerdown', onPointerDown)
    return () => document.removeEventListener('pointerdown', onPointerDown)
  }, [open])

  useEffect(() => {
    if (!open || activeIndex < 0) return
    document.getElementById(`${listId}-option-${activeIndex}`)?.scrollIntoView({ block: 'nearest' })
  }, [open, activeIndex, listId])

  function close() {
    setOpen(false)
  }

  function choose(item) {
    if (!item) return
    close()
    setQuery('')
    navigate(safeRedirect(item.path))
  }

  function onKeyDown(event) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      setOpen(true)
      if (rows.length === 0) return
      setActiveIndex((current) => {
        const start = current < 0 ? 0 : current
        if (event.key === 'ArrowDown') return Math.min(start + (current < 0 ? 0 : 1), rows.length - 1)
        return Math.max(start - 1, 0)
      })
      return
    }
    if (event.key === 'Escape') {
      if (open) {
        event.preventDefault()
        close()
      }
      return
    }
    if (event.key === 'Enter') {
      event.preventDefault()
      const item = rows[activeIndex] ?? rows[0]
      if (item) choose(item)
    }
  }

  const showPanel = open
  const showEmpty = showPanel && trimmed !== '' && activeStatus !== 'loading' && rows.length === 0 && activeStatus !== 'error'
  const activeId = activeIndex >= 0 ? `${listId}-option-${activeIndex}` : undefined

  return (
    <form
      ref={rootRef}
      role="search"
      className="app-search"
      onSubmit={(event) => {
        event.preventDefault()
        choose(rows[activeIndex] ?? rows[0])
      }}
    >
      <label htmlFor={inputId} className="visually-hidden">Search</label>
      <div className="search-field">
        <i className="bi bi-search search-field__icon" aria-hidden="true" />
        <input
          id={inputId}
          type="search"
          className="form-control"
          placeholder="Search anything..."
          value={query}
          role="combobox"
          autoComplete="off"
          aria-autocomplete="list"
          aria-expanded={showPanel}
          aria-controls={showPanel ? listId : undefined}
          aria-activedescendant={showPanel ? activeId : undefined}
          onChange={(event) => {
            setQuery(event.target.value)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          onClick={() => setOpen(true)}
          onKeyDown={onKeyDown}
        />
      </div>

      {showPanel && (
        <div className="app-search__panel" id={listId} role="listbox" aria-label="Search results">
          {trimmed === '' && (
            <p className="app-search__status">Type to search pages and records.</p>
          )}

          {groups.map((group) => (
            <div key={group.type} className="app-search__group">
              <div className="app-search__group-label">{group.label}</div>
              <ul className="app-search__list">
                {group.items.map((item) => {
                  const index = indexById.get(item.id)
                  const selected = index === activeIndex
                  return (
                    <li key={item.id}>
                      <Link
                        id={`${listId}-option-${index}`}
                        role="option"
                        aria-selected={selected}
                        to={safeRedirect(item.path)}
                        className={`app-search__option${selected ? ' is-active' : ''}`}
                        onMouseEnter={() => setActiveIndex(index)}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={(event) => {
                          if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return
                          event.preventDefault()
                          choose(item)
                        }}
                      >
                        <span className="app-search__option-label truncate">{item.label}</span>
                        {item.detail && <span className="app-search__option-detail truncate">{item.detail}</span>}
                      </Link>
                    </li>
                  )
                })}
              </ul>
            </div>
          ))}

          {activeStatus === 'loading' && trimmed !== '' && (
            <p className="app-search__status">Searching…</p>
          )}
          {activeStatus === 'error' && (
            <p className="app-search__status">Records could not be searched. Pages above are still available.</p>
          )}
          {showEmpty && (
            <p className="app-search__status">No matches for “{trimmed}”.</p>
          )}
        </div>
      )}
    </form>
  )
}
