import { useEffect, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import FormField from '../../components/FormField.jsx'
import PasswordInput from '../../components/PasswordInput.jsx'
import { api } from '../../api/client.js'
import { formatDateTime } from '../../data/selectors.js'

export default function SecurityPage() {
  const [state, setState] = useState({ status: 'loading', security: null, devices: [], error: null })
  const [notice, setNotice] = useState(null)
  const [setup, setSetup] = useState(null)
  const [code, setCode] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [recoveryCodes, setRecoveryCodes] = useState(null)
  const [newKey, setNewKey] = useState(null)
  const [deviceName, setDeviceName] = useState('')
  const [deviceDays, setDeviceDays] = useState('90')

  function load() {
    return Promise.all([api('/api/account/security'), api('/api/account/tokens')]).then(([security, tokens]) => {
      setState({ status: 'ready', security, devices: tokens.devices, error: null })
    })
  }

  useEffect(() => {
    const controller = new AbortController()
    Promise.all([
      api('/api/account/security', { signal: controller.signal }),
      api('/api/account/tokens', { signal: controller.signal }),
    ])
      .then(([security, tokens]) => setState({ status: 'ready', security, devices: tokens.devices, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState({ status: 'error', security: null, devices: [], error })
      })
    return () => controller.abort()
  }, [])

  async function startSetup() {
    setBusy(true)
    setNotice(null)
    try {
      setSetup(await api('/api/account/totp/setup', { method: 'POST', body: {} }))
      setCode('')
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
      const data = await api('/api/account/totp/confirm', { method: 'POST', body: { code } })
      setRecoveryCodes(data.recoveryCodes)
      setSetup(null)
      setCode('')
      setNotice({ variant: 'success', text: 'Authenticator app is on. Save the recovery codes below. They are shown once.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.fields?.code || error.message })
    } finally {
      setBusy(false)
    }
  }

  async function disable(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)
    try {
      await api('/api/account/totp/disable', { method: 'POST', body: { password, code } })
      setPassword('')
      setCode('')
      setRecoveryCodes(null)
      setNotice({ variant: 'success', text: 'Authenticator app is off. Existing device keys were revoked.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.fields?.code || error.fields?.password || error.message })
    } finally {
      setBusy(false)
    }
  }

  async function regenerate(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)
    try {
      const data = await api('/api/account/totp/recovery-codes', { method: 'POST', body: { password, code } })
      setRecoveryCodes(data.recoveryCodes)
      setPassword('')
      setCode('')
      setNotice({ variant: 'success', text: 'New recovery codes are below. The previous codes no longer work.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.fields?.code || error.fields?.password || error.message })
    } finally {
      setBusy(false)
    }
  }

  async function createDevice(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)
    setNewKey(null)
    try {
      const data = await api('/api/account/tokens', { method: 'POST', body: { name: deviceName, expiresInDays: Number(deviceDays) } })
      setNewKey(data.key)
      setDeviceName('')
      setNotice({ variant: 'success', text: 'Device key created. Copy it now. It is not shown again.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.fields?.name || error.fields?.expiresInDays || error.message })
    } finally {
      setBusy(false)
    }
  }

  async function revoke(device) {
    setBusy(true)
    setNotice(null)
    try {
      await api(`/api/account/tokens/${device.id}/revoke`, { method: 'POST', body: {} })
      setNotice({ variant: 'success', text: `${device.name} can no longer sign in.` })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  const security = state.security

  return (
    <PageShell>
      {state.status === 'loading' && <LoadingIndicator label="Loading security…" />}
      {state.status === 'error' && <div className="alert alert-danger" role="alert">{state.error.message}</div>}
      {notice && <div className={`alert alert-${notice.variant}`} role={notice.variant === 'danger' ? 'alert' : 'status'}>{notice.text}</div>}
      {recoveryCodes && (
        <div className="alert alert-warning" role="status">
          <div>
            <strong>Recovery codes</strong>
            <p className="mb-2">Each code works once if you lose the authenticator app. Store them somewhere safe.</p>
            <ul className="mb-0">
              {recoveryCodes.map((item) => <li key={item}><code>{item}</code></li>)}
            </ul>
          </div>
        </div>
      )}
      {state.status === 'ready' && (
        <>
          <TableCard
            title="Authenticator app"
            toolbar={security.totpEnabled ? <span className="badge text-success-emphasis bg-success-subtle">On</span> : <span className="badge text-secondary-emphasis bg-secondary-subtle">Off</span>}
          >
            <p className="text-muted">
              {security.totpRequired
                ? 'Your role requires an authenticator app at sign-in.'
                : 'Optional. When it is on, sign-in asks for a 6-digit code after your password.'}
              {security.totpEnabled && ` ${security.recoveryCodesRemaining} recovery code${security.recoveryCodesRemaining === 1 ? '' : 's'} left.`}
            </p>
            {!security.totpEnabled && !setup && (
              <button type="button" className="btn btn-primary" onClick={startSetup} disabled={busy}>Set up authenticator</button>
            )}
            {setup && (
              <form className="row g-3" onSubmit={confirmSetup}>
                <div className="col-12">
                  <p className="mb-1">Add this account in your authenticator app with the key below, or paste the setup link.</p>
                  <p className="mb-1"><code>{setup.secret}</code></p>
                  <p className="small text-break mb-0">{setup.otpauthUri}</p>
                </div>
                <FormField id="totp-confirm" label="6-digit code" required>
                  <input id="totp-confirm" className="form-control" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} />
                </FormField>
                <div className="col-12">
                  <button type="submit" className="btn btn-primary" disabled={busy}>Confirm and turn on</button>
                </div>
              </form>
            )}
            {security.totpEnabled && (
              <form className="row g-3" onSubmit={disable}>
                <FormField id="totp-password" label="Password" required>
                  <PasswordInput id="totp-password" value={password} onChange={setPassword} autoComplete="current-password" />
                </FormField>
                <FormField id="totp-code" label="Authenticator or recovery code" required>
                  <input id="totp-code" className="form-control" value={code} onChange={(event) => setCode(event.target.value)} autoComplete="one-time-code" />
                </FormField>
                <div className="col-12 d-flex gap-2 flex-wrap">
                  <button type="submit" className="btn btn-outline-danger" disabled={busy}>Turn off</button>
                  <button type="button" className="btn btn-outline-secondary" disabled={busy} onClick={regenerate}>New recovery codes</button>
                </div>
              </form>
            )}
          </TableCard>

          <TableCard title="Device sign-in keys">
            <p className="text-muted">A personal key for a phone or other app. It is shown once, can expire, and can be revoked. The web app keeps using the signed-in browser session.</p>
            {newKey && (
              <div className="alert alert-warning" role="status">
                <div>
                  <strong>Copy this key now.</strong>
                  <div className="text-break"><code>{newKey}</code></div>
                </div>
              </div>
            )}
            <form className="row g-3 mb-3" onSubmit={createDevice}>
              <FormField id="device-name" label="Device name" required>
                <input id="device-name" className="form-control" value={deviceName} onChange={(event) => setDeviceName(event.target.value)} maxLength={80} />
              </FormField>
              <FormField id="device-days" label="Expires in (days)" help="From 1 to 365.">
                <input id="device-days" className="form-control" type="number" min={1} max={365} value={deviceDays} onChange={(event) => setDeviceDays(event.target.value)} />
              </FormField>
              <div className="col-12">
                <button type="submit" className="btn btn-primary" disabled={busy}>Create key</button>
              </div>
            </form>
            <div className="table-responsive">
              <table className="table table-sm mb-0">
                <caption className="visually-hidden">Device sign-in keys</caption>
                <thead>
                  <tr>
                    <th scope="col">Device</th>
                    <th scope="col">Key</th>
                    <th scope="col">Last used</th>
                    <th scope="col">Expires</th>
                    <th scope="col"><span className="visually-hidden">Revoke</span></th>
                  </tr>
                </thead>
                <tbody>
                  {state.devices.length === 0 && (
                    <tr><td colSpan={5} className="text-muted">No device keys yet.</td></tr>
                  )}
                  {state.devices.map((device) => (
                    <tr key={device.id}>
                      <td>{device.name}</td>
                      <td><code>{device.prefix}…</code></td>
                      <td>{formatDateTime(device.lastUsedAt)}</td>
                      <td>{device.revokedAt ? <span className="text-muted">Revoked {formatDateTime(device.revokedAt)}</span> : formatDateTime(device.expiresAt)}</td>
                      <td className="text-end">
                        {!device.revokedAt && (
                          <button type="button" className="btn btn-sm btn-outline-danger" disabled={busy} onClick={() => revoke(device)}>Revoke</button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </TableCard>
        </>
      )}
    </PageShell>
  )
}
