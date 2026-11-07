import { useEffect, useId, useRef, useState } from 'react'
import { Navigate, useNavigate, useSearchParams } from 'react-router'
import BrandLogo from '../components/BrandLogo.jsx'
import PasswordInput from '../components/PasswordInput.jsx'
import AuthStatusScreen from '../components/AuthStatusScreen.jsx'
import useAuth from '../auth/useAuth.js'
import { api } from '../api/client.js'
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
  const [step, setStep] = useState('password')
  const [challengeToken, setChallengeToken] = useState('')
  const [code, setCode] = useState('')
  const [setup, setSetup] = useState(null)
  const [recoveryCodes, setRecoveryCodes] = useState(null)
  const [pendingSession, setPendingSession] = useState(null)

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
      const data = await auth.login(values.email.trim(), values.password)
      if (data?.twoFactorRequired) {
        setChallengeToken(data.challengeToken)
        setStep('totp')
        setCode('')
        setSubmitting(false)
        return
      }
      if (data?.enrollmentRequired) {
        setChallengeToken(data.challengeToken)
        setStep('enroll')
        setSubmitting(false)
        return
      }
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
        <h1 className="auth-card__title">
          {step === 'password' ? `Sign in to ${APP_TITLE}` : step === 'totp' ? 'Authenticator code' : step === 'codes' ? 'Save recovery codes' : 'Set up an authenticator'}
        </h1>
        <p className="auth-card__subtitle">
          {step === 'password' && 'Use the email address and password from your administrator.'}
          {step === 'totp' && 'Enter the 6-digit code from your authenticator app, or a recovery code.'}
          {step === 'enroll' && 'Your role requires an authenticator app before sign-in can finish.'}
          {step === 'codes' && 'Each code works once. This is the only time they are shown.'}
        </p>

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

        {step === 'totp' && (
          <form
            className="auth-form"
            onSubmit={async (event) => {
              event.preventDefault()
              setFormError(null)
              if (!code.trim()) {
                setFormError('Enter the code from your authenticator app, or a recovery code.')
                return
              }
              setSubmitting(true)
              try {
                const data = await api('/api/auth/totp', { method: 'POST', body: { challengeToken, code: code.trim() }, notifyUnauthorized: false })
                auth.completeSignIn(data)
                navigate(next, { replace: true })
              } catch (error) {
                setSubmitting(false)
                setCode('')
                setFormError(describeError(error))
              }
            }}
          >
            <div>
              <label htmlFor={`${ids}-code`} className="form-label">Code</label>
              <input id={`${ids}-code`} className="form-control" value={code} onChange={(event) => setCode(event.target.value)} autoComplete="one-time-code" inputMode="text" disabled={submitting} />
            </div>
            <button type="submit" className="btn btn-primary btn-lg w-100" disabled={submitting}>{submitting ? 'Checking…' : 'Continue'}</button>
          </form>
        )}
        {step === 'enroll' && (
          <div className="auth-form">
            {!setup && (
              <button
                type="button"
                className="btn btn-primary btn-lg w-100"
                disabled={submitting}
                onClick={async () => {
                  setFormError(null)
                  setSubmitting(true)
                  try {
                    setSetup(await api('/api/auth/totp/enroll', { method: 'POST', body: { challengeToken }, notifyUnauthorized: false }))
                    setSubmitting(false)
                  } catch (error) {
                    setSubmitting(false)
                    setFormError(describeError(error))
                  }
                }}
              >
                Show setup key
              </button>
            )}
            {setup && (
              <form
                onSubmit={async (event) => {
                  event.preventDefault()
                  setFormError(null)
                  setSubmitting(true)
                  try {
                    const data = await api('/api/auth/totp/enroll/confirm', { method: 'POST', body: { challengeToken, code: code.trim() }, notifyUnauthorized: false })
                    setRecoveryCodes(data.recoveryCodes)
                    setPendingSession(data)
                    setStep('codes')
                    setSubmitting(false)
                  } catch (error) {
                    setSubmitting(false)
                    setFormError(error.fields?.code || describeError(error))
                  }
                }}
              >
                <p className="mb-1">Add Yorilife in your authenticator app with this key:</p>
                <p><code>{setup.secret}</code></p>
                <label htmlFor={`${ids}-enroll-code`} className="form-label">6-digit code</label>
                <input id={`${ids}-enroll-code`} className="form-control mb-3" value={code} onChange={(event) => setCode(event.target.value)} inputMode="numeric" autoComplete="one-time-code" disabled={submitting} />
                <button type="submit" className="btn btn-primary btn-lg w-100" disabled={submitting}>{submitting ? 'Checking…' : 'Turn on and continue'}</button>
              </form>
            )}
          </div>
        )}
        {step === 'codes' && recoveryCodes && (
          <div className="auth-form">
            <ul>
              {recoveryCodes.map((item) => <li key={item}><code>{item}</code></li>)}
            </ul>
            <button
              type="button"
              className="btn btn-primary btn-lg w-100"
              onClick={() => {
                auth.completeSignIn(pendingSession)
                navigate(next, { replace: true })
              }}
            >
              I have saved these codes
            </button>
          </div>
        )}
        {step === 'password' && (
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
        )}

        <p className="auth-card__footnote">
          Forgotten your password? Ask an administrator to help you.
        </p>
      </div>
      <p className="auth-page__legal">© {CURRENT_YEAR} Yorilife Sciences Pvt. Ltd.</p>
    </main>
  )
}
