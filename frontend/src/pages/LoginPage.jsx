import { useEffect, useId, useRef, useState } from 'react'
import { Navigate, useNavigate, useSearchParams } from 'react-router'
import BrandLogo from '../components/BrandLogo.jsx'
import PasswordInput from '../components/PasswordInput.jsx'
import AuthStatusScreen from '../components/AuthStatusScreen.jsx'
import useAuth from '../auth/useAuth.js'
import { safeRedirect } from '../auth/safeRedirect.js'
import { APP_TITLE } from '../config/navigation.js'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const CURRENT_YEAR = new Date().getFullYear()

function validate({ email, password }) {
  const errors = {}
  if (!email.trim()) errors.email = 'Enter your email address.'
  else if (!EMAIL_PATTERN.test(email.trim())) errors.email = 'Enter a valid email address.'
  if (!password) errors.password = 'Enter your password.'
  return errors
}

// Friendly text for each failure; server messages are already safe to show.
function describeError(error) {
  if (error.code === 'too_many_attempts') {
    const minutes = Math.max(1, Math.ceil((error.retryAfter ?? 60) / 60))
    return `${error.message} You can try again in about ${minutes} minute${minutes === 1 ? '' : 's'}.`
  }
  return error.message
}

export default function LoginPage() {
  const auth = useAuth()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const ids = useId()
  const alertRef = useRef(null)
  const next = safeRedirect(searchParams.get('next'))

  const [values, setValues] = useState({ email: '', password: '' })
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    document.title = `Sign in · ${APP_TITLE}`
  }, [])

  useEffect(() => {
    if (formError) alertRef.current?.focus()
  }, [formError])

  if (auth.status === 'loading') return <AuthStatusScreen loading title="Checking your session…" />
  if (auth.status === 'authenticated' && !submitting) return <Navigate to={next} replace />

  const set = (field) => (value) => setValues((v) => ({ ...v, [field]: value }))

  async function handleSubmit(event) {
    event.preventDefault()
    setFormError(null)
    const found = validate(values)
    setErrors(found)
    if (Object.keys(found).length) {
      document.getElementById(`${ids}-${Object.keys(found)[0]}`)?.focus()
      return
    }

    setSubmitting(true)
    try {
      await auth.login(values.email.trim(), values.password)
      navigate(next, { replace: true })
    } catch (error) {
      setSubmitting(false)
      setValues((v) => ({ ...v, password: '' }))
      if (error.code === 'validation_failed' && Object.keys(error.fields).length) {
        setErrors(error.fields)
      } else {
        setFormError(describeError(error))
      }
    }
  }

  const sessionEnded = auth.reason === 'expired'
  const signedOut = auth.reason === 'signed_out'

  return (
    <main className="auth-page">
      <div className="auth-card">
        <BrandLogo className="auth-card__logo" />
        <h1 className="auth-card__title">Sign in to {APP_TITLE}</h1>
        <p className="auth-card__subtitle">Use the email address and password from your administrator.</p>

        {sessionEnded && !formError && (
          <div className="alert alert-warning" role="status">
            <i className="bi bi-clock-history" aria-hidden="true" />
            <div>Your session has ended. Please sign in again to continue.</div>
          </div>
        )}
        {signedOut && !formError && (
          <div className="alert alert-success" role="status">
            <i className="bi bi-check-circle-fill" aria-hidden="true" />
            <div>You have signed out.</div>
          </div>
        )}
        {formError && (
          <div ref={alertRef} className="alert alert-danger" role="alert" tabIndex={-1}>
            <i className="bi bi-exclamation-octagon-fill" aria-hidden="true" />
            <div>{formError}</div>
          </div>
        )}

        <form className="auth-form" noValidate onSubmit={handleSubmit} aria-busy={submitting}>
          <div>
            <label htmlFor={`${ids}-email`} className="form-label">Email address</label>
            <input
              id={`${ids}-email`}
              type="email"
              className={`form-control${errors.email ? ' is-invalid' : ''}`}
              value={values.email}
              onChange={(e) => set('email')(e.target.value)}
              autoComplete="username"
              inputMode="email"
              autoCapitalize="none"
              spellCheck={false}
              aria-invalid={errors.email ? 'true' : undefined}
              aria-describedby={errors.email ? `${ids}-email-error` : undefined}
              disabled={submitting}
            />
            {errors.email && <div id={`${ids}-email-error`} className="invalid-feedback d-block">{errors.email}</div>}
          </div>

          <div>
            <label htmlFor={`${ids}-password`} className="form-label">Password</label>
            <PasswordInput
              id={`${ids}-password`}
              value={values.password}
              onChange={set('password')}
              invalid={Boolean(errors.password)}
              autoComplete="current-password"
              aria-describedby={errors.password ? `${ids}-password-error` : undefined}
              disabled={submitting}
            />
            {errors.password && <div id={`${ids}-password-error`} className="invalid-feedback d-block">{errors.password}</div>}
          </div>

          <button type="submit" className="btn btn-primary btn-lg w-100" disabled={submitting}>
            {submitting ? (
              <>
                <span className="spinner-border spinner-border-sm" aria-hidden="true" />
                <span role="status">Signing in…</span>
              </>
            ) : (
              'Sign in'
            )}
          </button>
        </form>

        <p className="auth-card__footnote">
          Forgotten your password? Ask an administrator to help you.
        </p>
      </div>
      <p className="auth-page__legal">© {CURRENT_YEAR} Yorilife Sciences Pvt. Ltd.</p>
    </main>
  )
}
