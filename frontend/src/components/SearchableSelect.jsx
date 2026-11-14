import { useEffect, useMemo, useRef, useState } from 'react'
import FormField from './FormField.jsx'
import { DEFAULT_RESULT_LIMIT, filterOptions, moveActiveIndex } from '../utils/searchOptions.js'

const describedBy = (id, error, help) =>
  [help && `${id}-help`, error && `${id}-error`].filter(Boolean).join(' ') || undefined

/**
 * Searchable dropdown for long lists (ARIA 1.2 combobox + listbox).
 * Same props as SelectField, plus:
 *   options      [{ value, label, keywords? }] — keywords are searched but not shown (e.g. a code)
 *   placeholder  text shown when nothing is chosen
 *   clearable    show a clear (×) button when a value is chosen (default true)
 *   limit        how many matches to render at once (default 100); type to narrow
 *   noResultsText, missingLabel (shown when the saved value is not in options)
 *   ariaLabel    plain-text name for the list and clear button when `label` is JSX
 *   labelHidden  keep the label for screen readers only (toolbars)
 * Keyboard: type to filter, ↑/↓ to move, Enter to choose, Escape to close, Tab to leave.
 */
export default function SearchableSelect({
  id,
  label,
  required,
  error,
  help,
  className,
  value,
  onChange,
  options,
  placeholder = 'Select…',
  disabled = false,
  clearable = true,
  limit = DEFAULT_RESULT_LIMIT,
  noResultsText = 'No results',
  missingLabel = 'No longer listed',
  ariaLabel,
  labelHidden = false,
}) {
  const name = ariaLabel ?? (typeof label === 'string' ? label : undefined)
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(-1)
  const inputRef = useRef(null)
  const wrapRef = useRef(null)
  const openRef = useRef(false)
  const listId = `${id}-listbox`
  const optionId = (index) => `${id}-option-${index}`

  const current = value === null || value === undefined ? '' : String(value)
  const selected = useMemo(
    () => (current ? (options ?? []).find((option) => String(option.value) === current) : null),
    [options, current],
  )
  const selectedLabel = current ? (selected?.label ?? missingLabel) : ''
  const { items, total } = useMemo(() => filterOptions(options, query, limit), [options, query, limit])

  // Escape must close the list without closing a surrounding Bootstrap modal,
  // which listens on a parent element before React sees the event.
  useEffect(() => {
    const input = inputRef.current
    if (!input) return undefined
    const stop = (event) => {
      if (event.key === 'Escape' && openRef.current) event.stopPropagation()
    }
    input.addEventListener('keydown', stop)
    return () => input.removeEventListener('keydown', stop)
  }, [])

  useEffect(() => {
    openRef.current = open
  }, [open])

  useEffect(() => {
    if (open && active >= 0) document.getElementById(optionId(active))?.scrollIntoView?.({ block: 'nearest' })
  })

  function openList() {
    if (disabled || open) return
    setQuery('')
    const index = selected ? filterOptions(options, '', limit).items.findIndex((option) => String(option.value) === current) : -1
    setActive(index)
    setOpen(true)
  }

  function close() {
    setOpen(false)
    setQuery('')
    setActive(-1)
  }

  function choose(option) {
    onChange(String(option.value))
    close()
  }

  function clear() {
    onChange('')
    close()
    inputRef.current?.focus()
  }

  function onKeyDown(event) {
    if (disabled) return
    switch (event.key) {
      case 'ArrowDown':
      case 'ArrowUp': {
        event.preventDefault()
        const delta = event.key === 'ArrowDown' ? 1 : -1
        if (!open) {
          openList()
          return
        }
        setActive((index) => moveActiveIndex(index, delta, items.length))
        return
      }
      case 'Home':
      case 'End':
        if (open && items.length) {
          event.preventDefault()
          setActive(event.key === 'Home' ? 0 : items.length - 1)
        }
        return
      case 'Enter':
        if (open) {
          event.preventDefault()
          if (active >= 0 && items[active]) choose(items[active])
        }
        return
      case 'Escape':
        if (open) {
          event.preventDefault()
          close()
        }
        return
      case 'Tab':
        if (open) close()
        return
      default:
    }
  }

  function onBlur(event) {
    if (!wrapRef.current?.contains(event.relatedTarget)) close()
  }

  return (
    <FormField id={id} label={label} required={required} error={error} help={help} className={className} labelHidden={labelHidden}>
      <div className="searchable-select" ref={wrapRef} onBlur={onBlur}>
        <div className="searchable-select__control">
          <input
            ref={inputRef}
            id={id}
            type="text"
            role="combobox"
            className={`form-control searchable-select__input${error ? ' is-invalid' : ''}`}
            value={open ? query : selectedLabel}
            placeholder={open && selectedLabel ? selectedLabel : placeholder}
            onChange={(event) => {
              if (!open) setOpen(true)
              setQuery(event.target.value)
              setActive(event.target.value.trim() ? 0 : -1)
            }}
            onClick={openList}
            onKeyDown={onKeyDown}
            disabled={disabled}
            autoComplete="off"
            spellCheck={false}
            aria-autocomplete="list"
            aria-expanded={open}
            aria-controls={listId}
            aria-haspopup="listbox"
            aria-activedescendant={open && active >= 0 ? optionId(active) : undefined}
            aria-invalid={error ? 'true' : undefined}
            aria-describedby={describedBy(id, error, help)}
            aria-required={required || undefined}
          />
          <div className="searchable-select__actions">
            {clearable && current && !disabled && (
              <button type="button" className="searchable-select__clear" aria-label={`Clear ${name ?? 'selection'}`} onClick={clear}>
                <i className="bi bi-x-lg" aria-hidden="true" />
              </button>
            )}
            <button
              type="button"
              className="searchable-select__caret"
              tabIndex={-1}
              aria-hidden="true"
              disabled={disabled}
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => {
                if (open) close()
                else {
                  inputRef.current?.focus()
                  openList()
                }
              }}
            >
              <i className={`bi ${open ? 'bi-chevron-up' : 'bi-chevron-down'}`} aria-hidden="true" />
            </button>
          </div>
        </div>
        {open && (
          <ul className="searchable-select__menu" id={listId} role="listbox" aria-label={name}>
            {items.map((option, index) => (
              <li
                key={option.value}
                id={optionId(index)}
                role="option"
                aria-selected={String(option.value) === current}
                className={`searchable-select__option${index === active ? ' is-active' : ''}`}
                onMouseDown={(event) => event.preventDefault()}
                onMouseMove={() => index !== active && setActive(index)}
                onClick={() => choose(option)}
              >
                {option.label}
              </li>
            ))}
            {items.length === 0 && (
              <li className="searchable-select__empty" role="presentation">{noResultsText}</li>
            )}
            {total > items.length && (
              <li className="searchable-select__more" role="presentation">
                Showing {items.length} of {total}. Type to narrow the list.
              </li>
            )}
          </ul>
        )}
      </div>
    </FormField>
  )
}