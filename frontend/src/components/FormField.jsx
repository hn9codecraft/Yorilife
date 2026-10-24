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
