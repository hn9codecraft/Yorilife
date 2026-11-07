import { useEffect, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import FormField from '../../components/FormField.jsx'
import { api } from '../../api/client.js'
import { formatDateTime } from '../../data/selectors.js'

const PURPOSES = [
  ['account', 'Account'],
  ['announcements', 'Announcements'],
  ['hr', 'HR records'],
  ['sales_operations', 'Sales operations'],
]

export default function PrivacyPage() {
  const [state, setState] = useState({ status: 'loading', consents: [], requests: [], error: null })
  const [notice, setNotice] = useState(null)
  const [busy, setBusy] = useState(false)
  const [exportView, setExportView] = useState(null)
  const [consentForm, setConsentForm] = useState({ subjectType: 'user', subjectId: '', purpose: 'account', notes: '' })
  const [requestForm, setRequestForm] = useState({ kind: 'export', subjectType: 'user', subjectId: '', detail: '' })

  function load() {
    return Promise.all([api('/api/privacy/consents'), api('/api/privacy/requests')]).then(([consents, requests]) => {
      setState({ status: 'ready', consents: consents.consents, requests: requests.requests, error: null })
    })
  }

  useEffect(() => {
    const controller = new AbortController()
    Promise.all([
      api('/api/privacy/consents', { signal: controller.signal }),
      api('/api/privacy/requests', { signal: controller.signal }),
    ])
      .then(([consents, requests]) => setState({ status: 'ready', consents: consents.consents, requests: requests.requests, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState({ status: 'error', consents: [], requests: [], error })
      })
    return () => controller.abort()
  }, [])

  async function recordConsent(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)
    try {
      await api('/api/privacy/consents', {
        method: 'POST',
        body: {
          subjectType: consentForm.subjectType,
          subjectId: Number(consentForm.subjectId),
          purpose: consentForm.purpose,
          status: 'granted',
          channel: 'admin',
          notes: consentForm.notes || null,
        },
      })
      setConsentForm((current) => ({ ...current, notes: '' }))
      setNotice({ variant: 'success', text: 'Consent recorded.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.fields?.subjectId || error.message })
    } finally {
      setBusy(false)
    }
  }

  async function withdraw(consent) {
    setBusy(true)
    setNotice(null)
    try {
      await api(`/api/privacy/consents/${consent.id}/withdraw`, { method: 'POST', body: {} })
      setNotice({ variant: 'success', text: 'Consent withdrawn. The original record is kept.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function openRequest(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)
    try {
      await api('/api/privacy/requests', {
        method: 'POST',
        body: {
          kind: requestForm.kind,
          subjectType: requestForm.subjectType,
          subjectId: Number(requestForm.subjectId),
          detail: requestForm.detail || null,
        },
      })
      setNotice({ variant: 'success', text: 'Request opened.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.fields?.detail || error.fields?.subjectId || error.message })
    } finally {
      setBusy(false)
    }
  }

  async function fulfill(request) {
    if (request.kind === 'anonymize' && !window.confirm('Mask this person’s name, email and phone in place? The row is kept. This cannot be undone.')) {
      return
    }
    let outcome = null
    if (request.kind === 'correction') {
      outcome = window.prompt('What was done with the correction?')
      if (!outcome) return
    }
    setBusy(true)
    setNotice(null)
    try {
      const data = await api(`/api/privacy/requests/${request.id}/fulfill`, {
        method: 'POST',
        body: request.kind === 'anonymize' ? { confirm: true } : request.kind === 'correction' ? { outcome } : {},
      })
      if (data.export) setExportView(data.export)
      setNotice({
        variant: 'success',
        text: request.kind === 'anonymize'
          ? 'Personal data was masked. The record was not deleted.'
          : request.kind === 'export'
            ? 'Export is ready below.'
            : 'Correction request marked done.',
      })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.fields?.outcome || error.fields?.confirm || error.message })
    } finally {
      setBusy(false)
    }
  }

  async function reject(request) {
    const outcome = window.prompt('Why is this request rejected?')
    if (!outcome) return
    setBusy(true)
    setNotice(null)
    try {
      await api(`/api/privacy/requests/${request.id}/reject`, { method: 'POST', body: { outcome } })
      setNotice({ variant: 'success', text: 'Request rejected. The record is kept.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <PageShell>
      {state.status === 'loading' && <LoadingIndicator label="Loading privacy records…" />}
      {state.status === 'error' && <div className="alert alert-danger" role="alert">{state.error.message}</div>}
      {notice && <div className={`alert alert-${notice.variant}`} role={notice.variant === 'danger' ? 'alert' : 'status'}>{notice.text}</div>}
      <div className="alert alert-info" role="status">
        <i className="bi bi-info-circle-fill" aria-hidden="true" />
        <div>Consent, exports, corrections and anonymising are recorded in the audit log. Anonymising masks personal data in the live record. It does not delete the row, and it does not rewrite the audit chain.</div>
      </div>
      {exportView && (
        <TableCard title="Personal data export" toolbar={<button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setExportView(null)}>Hide</button>}>
          <pre className="mb-0 small">{JSON.stringify(exportView, null, 2)}</pre>
        </TableCard>
      )}
      {state.status === 'ready' && (
        <>
          <TableCard title="Consent">
            <form className="row g-3 mb-3" onSubmit={recordConsent}>
              <FormField id="consent-type" label="Person">
                <select id="consent-type" className="form-select" value={consentForm.subjectType} onChange={(event) => setConsentForm((current) => ({ ...current, subjectType: event.target.value }))}>
                  <option value="user">User</option>
                  <option value="employee">Employee</option>
                </select>
              </FormField>
              <FormField id="consent-id" label="Id" required>
                <input id="consent-id" className="form-control" inputMode="numeric" value={consentForm.subjectId} onChange={(event) => setConsentForm((current) => ({ ...current, subjectId: event.target.value }))} />
              </FormField>
              <FormField id="consent-purpose" label="Purpose">
                <select id="consent-purpose" className="form-select" value={consentForm.purpose} onChange={(event) => setConsentForm((current) => ({ ...current, purpose: event.target.value }))}>
                  {PURPOSES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
              </FormField>
              <FormField id="consent-notes" label="Notes">
                <input id="consent-notes" className="form-control" value={consentForm.notes} onChange={(event) => setConsentForm((current) => ({ ...current, notes: event.target.value }))} />
              </FormField>
              <div className="col-12">
                <button type="submit" className="btn btn-primary" disabled={busy}>Record consent</button>
              </div>
            </form>
            <div className="table-responsive">
              <table className="table table-sm mb-0">
                <caption className="visually-hidden">Consent records</caption>
                <thead>
                  <tr>
                    <th scope="col">When</th>
                    <th scope="col">Person</th>
                    <th scope="col">Purpose</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span className="visually-hidden">Withdraw</span></th>
                  </tr>
                </thead>
                <tbody>
                  {state.consents.length === 0 && <tr><td colSpan={5} className="text-muted">No consent records yet.</td></tr>}
                  {state.consents.map((consent) => (
                    <tr key={consent.id}>
                      <td className="text-nowrap">{formatDateTime(consent.recordedAt)}</td>
                      <td>{consent.subjectName} <span className="text-muted">({consent.subjectType} {consent.subjectId})</span></td>
                      <td>{consent.purpose}</td>
                      <td>{consent.status}</td>
                      <td className="text-end">
                        {consent.status === 'granted' && (
                          <button type="button" className="btn btn-sm btn-outline-secondary" disabled={busy} onClick={() => withdraw(consent)}>Withdraw</button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </TableCard>

          <TableCard title="Requests">
            <form className="row g-3 mb-3" onSubmit={openRequest}>
              <FormField id="request-kind" label="Request">
                <select id="request-kind" className="form-select" value={requestForm.kind} onChange={(event) => setRequestForm((current) => ({ ...current, kind: event.target.value }))}>
                  <option value="export">Export personal data</option>
                  <option value="correction">Correct personal data</option>
                  <option value="anonymize">Anonymise</option>
                </select>
              </FormField>
              <FormField id="request-type" label="Person">
                <select id="request-type" className="form-select" value={requestForm.subjectType} onChange={(event) => setRequestForm((current) => ({ ...current, subjectType: event.target.value }))}>
                  <option value="user">User</option>
                  <option value="employee">Employee</option>
                </select>
              </FormField>
              <FormField id="request-id" label="Id" required>
                <input id="request-id" className="form-control" inputMode="numeric" value={requestForm.subjectId} onChange={(event) => setRequestForm((current) => ({ ...current, subjectId: event.target.value }))} />
              </FormField>
              <FormField id="request-detail" label="Detail" help={requestForm.kind === 'correction' ? 'Required. What should be corrected?' : 'Optional note. Do not paste secrets.'}>
                <input id="request-detail" className="form-control" value={requestForm.detail} onChange={(event) => setRequestForm((current) => ({ ...current, detail: event.target.value }))} />
              </FormField>
              <div className="col-12">
                <button type="submit" className="btn btn-primary" disabled={busy}>Open request</button>
              </div>
            </form>
            <div className="table-responsive">
              <table className="table table-sm mb-0">
                <caption className="visually-hidden">Privacy requests</caption>
                <thead>
                  <tr>
                    <th scope="col">When</th>
                    <th scope="col">Kind</th>
                    <th scope="col">Person</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span className="visually-hidden">Actions</span></th>
                  </tr>
                </thead>
                <tbody>
                  {state.requests.length === 0 && <tr><td colSpan={5} className="text-muted">No requests yet.</td></tr>}
                  {state.requests.map((request) => (
                    <tr key={request.id}>
                      <td className="text-nowrap">{formatDateTime(request.requestedAt)}</td>
                      <td>{request.kind}</td>
                      <td>{request.subjectName} <span className="text-muted">({request.subjectType} {request.subjectId})</span></td>
                      <td>{request.status}{request.outcome ? <div className="small text-muted">{request.outcome}</div> : null}</td>
                      <td className="text-end text-nowrap">
                        {request.status === 'open' && (
                          <>
                            <button type="button" className="btn btn-sm btn-outline-primary me-1" disabled={busy} onClick={() => fulfill(request)}>Complete</button>
                            <button type="button" className="btn btn-sm btn-outline-secondary" disabled={busy} onClick={() => reject(request)}>Reject</button>
                          </>
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
