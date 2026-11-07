import { useEffect, useId, useState } from 'react'
import { useNavigate } from 'react-router'
import BrandLogo from '../components/BrandLogo.jsx'
import useAuth from '../auth/useAuth.js'
import { api } from '../api/client.js'
import { APP_TITLE } from '../config/navigation.js'

/**
 * Second sign-in step: a code from an authenticator app, a recovery code,
 * or first-time setup when the role requires an authenticator.
 */
export default function TwoFactorStep({ mode, next }) {
  const auth = useAuth()
  const navigate = useNavigate()
  const ids = useId()
  const enroll = mode === 'enroll'
  const [setup, setSetup] = useState(null)
  const [code, setCode] = useState('')
  const [useRecovery, setUseRecovery] = useState(false)
  const [recoveryCodes, setRecoveryCodes] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    document.title = `Authenticator · ${APP_TITLE}`
  }, [])

  useEffect(() => {
    if (!enroll) return undefined
    const controller = new AbortController()
    api('/api/auth/2fa/setup', { method: 'POST', body: {}, signal: controller.signal })
      .then((data) => setSetup(data))
      .catch((err) => {
        if (err.name !== 'AbortError') setError(err.message)
      })
    return () => controller.abort()
  }, [enroll])

  async function finish(data) {
    auth.completeSignIn(data)
    navigate(next, { replace: true })
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setError(null)
    const trimmed = code.trim()
    if (!trimmed) {
      setError(useRecovery ? 'Enter a recovery code.' : 'Enter the 6-digit code.')
      return
    }
    setBusy(true)
    try {
      if (enroll) {
        const confirmed = await api('/api/auth/2fa/confirm', { method: 'POST', body: { code: trimmed } })
        setRecoveryCodes(confirmed.recoveryCodes)
        setBusy(false)
        return
      }
      const path = useRecovery ? '/api/auth/2fa/recover' : '/api/auth/2fa/verify'
      const data = await api(path, { method: 'POST', body: { code: trimmed } })
      await finish(data)
    } catch (err) {
      setBusy(false)
      setError(err.message)
    }
  }

  async function continueAfterCodes() {
    setBusy(true)
    try {
      const data = await api('/api/auth/me')
      await finish(data)
    } catch (err) {
      setBusy(false)
      setError(err.message)
    }
  }

  return (
    <main className="auth-page">
      <div className="auth-card">
        <BrandLogo className="auth-card__logo" />
        <h1 className="auth-card__title">{enroll ? 'Set up an authenticator app' : 'Authenticator code'}</h1>
        <p className="auth-card__subtitle">
          {enroll
            ? 'Add Yorilife to an authenticator app, then enter the 6-digit code.'
            : 'Enter the code from your authenticator app to finish signing in.'}
        </p>

        {error && (
          <div className="alert alert-danger" role="alert">
            <i className="bi bi-exclamation-octagon-fill" aria-hidden="true" />
            <div>{error}</div>
          </div>
        )}

        {recoveryCodes && (
          <>
            <div className="alert alert-warning" role="status">
              <i className="bi bi-key" aria-hidden="true" />
              <div>Save these recovery codes now. They are shown only this once. Each code works one time.</div>
            </div>
            <ul className="list-unstyled font-monospace mb-3">
              {recoveryCodes.map((item) => <li key={item}>{item}</li>)}
            </ul>
            <button type="button" className="btn btn-primary w-100" disabled={busy} onClick={continueAfterCodes}>
              I have saved these codes
            </button>
          </>
        )}

        {!recoveryCodes && enroll && setup && (
          <div className="mb-3">
            <p className="small text-muted mb-1">Account name in the app: {auth.user?.email}</p>
            <label className="form-label" htmlFor={`${ids}-secret`}>Setup key</label>
            <input id={`${ids}-secret`} className="form-control font-monospace" readOnly value={setup.secret} />
          </div>
        )}

        {!recoveryCodes && (
          <form className="auth-form" onSubmit={handleSubmit}>
            <div>
              <label className="form-label" htmlFor={`${ids}-code`}>{useRecovery ? 'Recovery code' : '6-digit code'}</label>
              <input
                id={`${ids}-code`}
                className="form-control"
                inputMode={useRecovery ? 'text' : 'numeric'}
                autoComplete="one-time-code"
                value={code}
                onChange={(event) => setCode(event.target.value)}
                disabled={busy || (enroll && !setup)}
              />
            </div>
            <button type="submit" className="btn btn-primary w-100" disabled={busy || (enroll && !setup)}>
              {busy ? 'Checking…' : enroll ? 'Turn on authenticator' : 'Continue'}
            </button>
            {!enroll && (
              <button
                type="button"
                className="btn btn-link px-0"
                onClick={() => {
                  setUseRecovery((current) => !current)
                  setCode('')
                  setError(null)
                }}
              >
                {useRecovery ? 'Use an authenticator code' : 'Use a recovery code'}
              </button>
            )}
          </form>
        )}

        <button type="button" className="btn btn-link px-0" onClick={() => auth.logout()}>
          Sign out
        </button>
      </div>
    </main>
  )
}
