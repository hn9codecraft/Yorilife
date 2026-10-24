import { useState } from 'react'

/**
 * Password field with a show/hide toggle. Pass the usual input props
 * (id, value, onChange, autoComplete, aria-*); `onChange` receives the value.
 */
export default function PasswordInput({ id, value, onChange, invalid, ...inputProps }) {
  const [visible, setVisible] = useState(false)

  return (
    <div className="input-group password-input">
      <input
        id={id}
        type={visible ? 'text' : 'password'}
        className={`form-control${invalid ? ' is-invalid' : ''}`}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        aria-invalid={invalid ? 'true' : undefined}
        spellCheck={false}
        autoCapitalize="none"
        {...inputProps}
      />
      <button
        type="button"
        className="btn btn-outline-secondary password-input__toggle"
        onClick={() => setVisible((v) => !v)}
        aria-controls={id}
        aria-pressed={visible}
        aria-label={visible ? 'Hide password' : 'Show password'}
        title={visible ? 'Hide password' : 'Show password'}
      >
        <i className={`bi ${visible ? 'bi-eye-slash' : 'bi-eye'}`} aria-hidden="true" />
      </button>
    </div>
  )
}
