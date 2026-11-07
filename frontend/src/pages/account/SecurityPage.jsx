import { useEffect, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import PasswordInput from '../../components/PasswordInput.jsx'
import { api } from '../../api/client.js'
import { formatDateTime } from '../../data/selectors.js'

export default function SecurityPage() {
  const [status, setStatus] = useState({ loading: true, data: null, error: null })
  const [setup, setSetup] = useState(null)
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [recoveryCodes, setRecoveryCodes] = useState(null)
  const [tokens, setTokens] = useState([])
  const [deviceName, setDeviceName] = useState('')
  const [expiresInDays, setExpiresInDays] = useState('90')
  const [newToken, setNewToken] = useState(null)
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)

  async function load() {
    const [factor, list] = await Promise.all([api('/api/auth/2fa'), api('/api/auth/tokens')])
    setStatus({ loading: false, data: factor, error: null })
    setTokens(list.tokens)
  }

  useEffect(() => {
    const controller = new AbortController()
    Promise.all([
      api('/api/auth/2fa', { signal: controller.signal }),
      api('/api/auth/tokens', { signal: controller.signal }),
    ])
      .then(([factor, list]) => {
        setStatus({ loading: false, data: factor, error: null })
        setTokens(list.tokens)
      })
      .catch((error) => {
        if (error.name !== 'AbortError') setStatus({ loading: false, data: null, error })
      })
    return () => controller.abort()
  }, [])

  async function startSetup() {
    setBusy(true)
    setNotice(null)
    try {
      setSetup(await api('/api/auth/2fa/setup', { method: 'POST', body: {} }))
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function confirmSetup(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)
    try {
      const data = await api('/api/auth/2fa/confirm', { method: 'POST', body: { code: code.trim() } })
      setRecoveryCodes(data.recoveryCodes)
      setSetup(null)
      setCode('')
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function disable(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)
    try {
      await api('/api/auth/2fa/disable', { method: 'POST', body: { password, code: code.trim() } })
      setPassword('')
      setCode('')
      setNotice({ variant: 'success', text: 'The authenticator app is turned off. Other sessions and device tokens for this account were signed out.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function createToken(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)
    setNewToken(null)
    try {
      const data = await api('/api/auth/tokens', {
        method: 'POST',
        body: { name: deviceName.trim(), expiresInDays: Number(expiresInDays) },
      })
      setNewToken(data.token)
      setDeviceName('')
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function revoke(id) {
    setBusy(true)
    setNotice(null)
    try {
      await api(`/api/auth/tokens/${id}/revoke`, { method: 'POST', body: {} })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <PageShell>
      {status.loading && <LoadingIndicator label="Loading security…" />}
      {status.error && <div className="alert alert-danger" role="alert">{status.error.message}</div>}
      {notice && <div className={`alert alert-${notice.variant}`} role={notice.variant === 'danger' ? 'alert' : 'status'}>{notice.text}</div>}
      {recoveryCodes && (
        <div className="alert alert-warning" role="status">
          <div>
            <strong>Save these recovery codes.</strong> They are shown only this once. Each code works one time.
            <ul className="font-monospace text-break mb-0 mt-2">
              {recoveryCodes.map((item) => <li key={item}>{item}</li>)}
            </ul>
          </div>
        </div>
      )}
      {newToken && (
        <div className="alert alert-warning" role="status">
          <div>
            <strong>Copy this device token now.</strong> It will not be shown again.
            <div className="font-monospace text-break mt-2">{newToken}</div>
          </div>
        </div>
      )}

      {status.data && (
        <TableCard className="mb-4" title="Authenticator app">
          <div className="card-body">
          <p className="mb-3">
            {status.data.enabled
              ? `On. ${status.data.recoveryCodesRemaining} recovery code${status.data.recoveryCodesRemaining === 1 ? '' : 's'} left.`
              : 'Off. You can turn it on with any authenticator app.'}
            {status.data.required && ' Your role requires an authenticator app.'}
          </p>
          {!status.data.enabled && !setup && (
            <button type="button" className="btn btn-primary" disabled={busy} onClick={startSetup}>Set up authenticator</button>
          )}
          {setup && (
            <form onSubmit={confirmSetup} className="row g-3">
              <div className="col-12">
                <label className="form-label" htmlFor="setup-key">Setup key</label>
                <input id="setup-key" className="form-control font-monospace" readOnly value={setup.secret} />
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="setup-code">6-digit code</label>
                <input id="setup-code" className="form-control" inputMode="numeric" value={code} onChange={(event) => setCode(event.target.value)} />
              </div>
              <div className="col-12">
                <button type="submit" className="btn btn-primary" disabled={busy}>Turn on</button>
              </div>
            </form>
          )}
          {status.data.enabled && (
            <form onSubmit={disable} className="row g-3">
              <div className="col-md-6">
                <label className="form-label" htmlFor="disable-password">Password</label>
                <PasswordInput id="disable-password" value={password} onChange={setPassword} autoComplete="current-password" />
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="disable-code">Authenticator or recovery code</label>
                <input id="disable-code" className="form-control" value={code} onChange={(event) => setCode(event.target.value)} />
              </div>
              <div className="col-12">
                <button type="submit" className="btn btn-outline-danger" disabled={busy}>Turn off authenticator</button>
              </div>
            </form>
          )}
          </div>
        </TableCard>
      )}

      <TableCard className="mb-4" title="Device tokens">
        <div className="card-body">
        <p className="text-muted">For a future mobile app. The token is shown once. Send it as <code>Authorization: Bearer</code>. A password reset or a locked account revokes these tokens.</p>
        <form onSubmit={createToken} className="row g-3 mb-0">
          <div className="col-md-6">
            <label className="form-label" htmlFor="device-name">Device name</label>
            <input id="device-name" className="form-control" value={deviceName} onChange={(event) => setDeviceName(event.target.value)} />
          </div>
          <div className="col-md-3">
            <label className="form-label" htmlFor="device-days">Expires in (days)</label>
            <input id="device-days" className="form-control" type="number" min="1" max="365" value={expiresInDays} onChange={(event) => setExpiresInDays(event.target.value)} />
          </div>
          <div className="col-12 col-md-3 d-flex align-items-md-end">
            <button type="submit" className="btn btn-primary w-100" disabled={busy}>Create token</button>
          </div>
        </form>
        </div>
        <div className="table-container">
          <table className="table table-sm mb-0">
            <caption className="visually-hidden">Device tokens</caption>
            <thead>
              <tr>
                <th scope="col">Name</th>
                <th scope="col">Prefix</th>
                <th scope="col">Last used</th>
                <th scope="col">Expires</th>
                <th scope="col"><span className="visually-hidden">Revoke</span></th>
              </tr>
            </thead>
            <tbody>
              {tokens.length === 0 && (
                <tr><td colSpan={5} className="table-empty" data-label="">No device tokens yet.</td></tr>
              )}
              {tokens.map((token) => (
                <tr key={token.id}>
                  <td data-label="Name">{token.name}</td>
                  <td data-label="Prefix" className="font-monospace">{token.prefix}…</td>
                  <td data-label="Last used">{token.lastUsedAt ? formatDateTime(token.lastUsedAt) : 'Not used'}</td>
                  <td data-label="Expires">{token.revokedAt ? 'Revoked' : formatDateTime(token.expiresAt)}</td>
                  <td data-label="Revoke" className="text-end">
                    {!token.revokedAt && (
                      <button type="button" className="btn btn-sm btn-outline-danger" disabled={busy} onClick={() => revoke(token.id)}>Revoke</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </TableCard>
    </PageShell>
  )
}
