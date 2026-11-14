import { useState } from 'react'

// Form building blocks. FormField = label + control + help/error text.
// The control (children) uses `id`; TextField / SelectField wire up
// aria-describedby and aria-invalid automatically.

const describedBy = (id, error, help) =>
  [help && `${id}-help`, error && `${id}-error`].filter(Boolean).join(' ') || undefined

export default function FormField({ id, label, required, error, help, className = 'col-12', children }) {
  return (
    <div className={className}>
      <label htmlFor={id} className="form-label">
        {label}
        {required && <span className="required" aria-hidden="true">*</span>}
        {required && <span className="visually-hidden"> (required)</span>}
      </label>
      {children}
      {help && <div id={`${id}-help`} className="form-text">{help}</div>}
      {error && <div id={`${id}-error`} className="invalid-feedback d-block">{error}</div>}
    </div>
  )
}

/** A short note beside a label in brackets, e.g. <LabelNote label="Pincode" note="6 digits" />. Styled by .label-note. */
export function LabelNote({ label, note }) {
  return (
    <>
      {label}
      {note && <small className="label-note">({note})</small>}
    </>
  )
}

const DATE_LIKE = new Set(['date', 'time', 'month', 'datetime-local'])

export function TextField({ id, label, required, error, help, className, value, onChange, type = 'text', ...inputProps }) {
  return (
    <FormField id={id} label={label} required={required} error={error} help={help} className={className}>
      <input
        id={id}
        type={type}
        className={`form-control${error ? ' is-invalid' : ''}`}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={describedBy(id, error, help)}
        aria-required={required || undefined}
        data-empty={DATE_LIKE.has(type) ? String(!value) : undefined}
        {...inputProps}
      />
    </FormField>
  )
}

// options: [{ value, label }]; `placeholder` adds an empty first option.
export function SelectField({ id, label, required, error, help, className, value, onChange, options, placeholder, disabled }) {
  return (
    <FormField id={id} label={label} required={required} error={error} help={help} className={className}>
      <select
        id={id}
        className={`form-select${error ? ' is-invalid' : ''}`}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={describedBy(id, error, help)}
        aria-required={required || undefined}
        disabled={disabled}
      >
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>{option.label}</option>
        ))}
      </select>
    </FormField>
  )
}

// Checkbox list with a filter. `value` is an array of option values.
// Works with the keyboard, a phone, and the light and dark theme.
export function MultiSelectField({
  id,
  label,
  error,
  help,
  className = 'col-12',
  value,
  onChange,
  options,
  placeholder = 'None selected',
  emptyText = 'Nothing to choose yet.',
}) {
  const selected = Array.isArray(value) ? value.map(String) : []
  const [query, setQuery] = useState('')
  const described = describedBy(id, error, help)
  const needle = query.trim().toLowerCase()
  const shown = needle ? options.filter((option) => option.label.toLowerCase().includes(needle)) : options
  const summary = options.filter((option) => selected.includes(String(option.value)))
  const toggle = (optionValue) => {
    const key = String(optionValue)
    onChange(selected.includes(key) ? selected.filter((item) => item !== key) : [...selected, key])
  }

  return (
    <div className={className}>
      <fieldset className={`multi-select${error ? ' is-invalid' : ''}`} aria-invalid={error ? 'true' : undefined} aria-describedby={described}>
        <legend className="form-label">{label}</legend>
        {summary.length > 0 ? (
          <ul className="pill-list multi-select__pills">
            {summary.map((option) => (
              <li key={option.value}>
                <span className="pill">
                  <span className="pill__label">{option.label}</span>
                  <button type="button" className="pill__remove" aria-label={`Remove ${option.label}`} onClick={() => toggle(option.value)}>
                    <i className="bi bi-x-lg" aria-hidden="true" />
                  </button>
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="multi-select__placeholder">{placeholder}</p>
        )}
        <input
          id={id}
          type="search"
          className={`form-control form-control-sm${error ? ' is-invalid' : ''}`}
          placeholder="Filter"
          aria-label={`Filter ${label}`}
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          autoComplete="off"
        />
        <div className="choice-group choice-group--scroll" role="group" aria-label={label}>
          {options.length === 0 && <span className="text-secondary small">{emptyText}</span>}
          {options.length > 0 && shown.length === 0 && <span className="text-secondary small">No matches.</span>}
          {shown.map((option) => (
            <label key={option.value} className="form-check">
              <input
                type="checkbox"
                className="form-check-input"
                checked={selected.includes(String(option.value))}
                onChange={() => toggle(option.value)}
              />
              <span className="form-check-label">{option.label}</span>
            </label>
          ))}
        </div>
      </fieldset>
      {help && <div id={`${id}-help`} className="form-text">{help}</div>}
      {error && <div id={`${id}-error`} className="invalid-feedback d-block">{error}</div>}
    </div>
  )
}
