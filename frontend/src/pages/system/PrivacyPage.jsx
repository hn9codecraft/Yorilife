import { useEffect, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import { api } from '../../api/client.js'
import { formatDateTime } from '../../data/selectors.js'

const TYPES = [
  { value: 'user', label: 'User' },
  { value: 'employee', label: 'Employee' },
  { value: 'manager', label: 'Manager' },
  { value: 'headquarters', label: 'Headquarters' },
  { value: 'stockist', label: 'Stockist' },
]

const PURPOSES = [
  { value: 'account', label: 'Account' },
  { value: 'communications', label: 'Communications' },
  { value: 'operations', label: 'Operations' },
]

export default function PrivacyPage() {
  const [requests, setRequests] = useState({ status: 'loading', items: [], error: null })
  const [notice, setNotice] = useState(null)
  const [exportText, setExportText] = useState(null)
  const [busy, setBusy] = useState(false)
  const [consent, setConsent] = useState({ subjectType: 'employee', subjectId: '', purpose: 'communications', granted: true, note: '' })
  const [request, setRequest] = useState({ subjectType: 'user', subjectId: '', kind: 'correction', details: '' })

  async function load() {
    const data = await api('/api/privacy/requests')
    setRequests({ status: 'ready', items: data.items, error: null })
  }

  useEffect(() => {
    const controller = new AbortController()
    api('/api/privacy/requests', { signal: controller.signal })
      .then((data) => setRequests({ status: 'ready', items: data.items, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setRequests({ status: 'error', items: [], error })
      })
    return () => controller.abort()
  }, [])

  async function saveConsent(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)
    try {
      await api('/api/privacy/consents', {
        method: 'POST',
        body: {
          subjectType: consent.subjectType,
          subjectId: Number(consent.subjectId),
          purpose: consent.purpose,
          granted: consent.granted,
          note: consent.note.trim() || undefined,
        },
      })
      setNotice({ variant: 'success', text: 'Consent recorded.' })
      setConsent((current) => ({ ...current, note: '' }))
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
          subjectType: request.subjectType,
          subjectId: Number(request.subjectId),
          kind: request.kind,
          details: request.details.trim() || undefined,
        },
      })
      setRequest((current) => ({ ...current, details: '' }))
      setNotice({ variant: 'success', text: 'Request opened.' })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function act(id, action, body) {
    setBusy(true)
    setNotice(null)
    setExportText(null)
    try {
      const data = await api(`/api/privacy/requests/${id}/${action}`, { method: 'POST', body: body ?? {} })
      if (action === 'export') {
        setExportText(JSON.stringify(data.export, null, 2))
        setNotice({ variant: 'success', text: 'Personal data exported. It is shown below and was not stored in the audit log.' })
      } else {
        setNotice({ variant: 'success', text: 'The record was masked in place. It was not deleted.' })
      }
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  async function updateStatus(item, status) {
    const resolution = status === 'completed' || status === 'rejected'
      ? window.prompt('How was this request resolved?')
      : null
    if ((status === 'completed' || status === 'rejected') && !resolution) return
    setBusy(true)
    setNotice(null)
    try {
      await api(`/api/privacy/requests/${item.id}`, {
        method: 'PATCH',
        body: { status, resolution: resolution || undefined },
      })
      await load()
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setBusy(false)
    }
  }

  return (
    <PageShell>
      {notice && <div className={`alert alert-${notice.variant}`} role={notice.variant === 'danger' ? 'alert' : 'status'}>{notice.text}</div>}
      <div className="alert alert-info" role="status">
        Consent, exports and correction requests are kept. Anonymising masks personal details on the existing row. It does not delete the row, and it does not rewrite the audit hash chain.
      </div>

      <TableCard className="mb-4" title="Record consent">
        <div className="card-body">
        <form onSubmit={saveConsent} className="row g-3">
          <div className="col-md-3">
            <label className="form-label" htmlFor="consent-type">Record</label>
            <select id="consent-type" className="form-select" value={consent.subjectType} onChange={(event) => setConsent({ ...consent, subjectType: event.target.value })}>
              {TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
            </select>
          </div>
          <div className="col-md-2">
            <label className="form-label" htmlFor="consent-id">Id</label>
            <input id="consent-id" className="form-control" inputMode="numeric" value={consent.subjectId} onChange={(event) => setConsent({ ...consent, subjectId: event.target.value })} required />
          </div>
          <div className="col-md-3">
            <label className="form-label" htmlFor="consent-purpose">Purpose</label>
            <select id="consent-purpose" className="form-select" value={consent.purpose} onChange={(event) => setConsent({ ...consent, purpose: event.target.value })}>
              {PURPOSES.map((purpose) => <option key={purpose.value} value={purpose.value}>{purpose.label}</option>)}
            </select>
          </div>
          <div className="col-md-4">
            <label className="form-label" htmlFor="consent-note">Note</label>
            <input id="consent-note" className="form-control" value={consent.note} onChange={(event) => setConsent({ ...consent, note: event.target.value })} />
          </div>
          <div className="col-12">
            <div className="form-check">
              <input id="consent-granted" className="form-check-input" type="checkbox" checked={consent.granted} onChange={(event) => setConsent({ ...consent, granted: event.target.checked })} />
              <label className="form-check-label" htmlFor="consent-granted">Consent given</label>
            </div>
          </div>
          <div className="col-12">
            <button type="submit" className="btn btn-primary" disabled={busy}>Save consent</button>
          </div>
        </form>
        </div>
      </TableCard>

      <TableCard className="mb-4" title="Open a request">
        <div className="card-body">
        <form onSubmit={openRequest} className="row g-3">
          <div className="col-md-3">
            <label className="form-label" htmlFor="request-type">Record</label>
            <select id="request-type" className="form-select" value={request.subjectType} onChange={(event) => setRequest({ ...request, subjectType: event.target.value })}>
              {TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
            </select>
          </div>
          <div className="col-md-2">
            <label className="form-label" htmlFor="request-id">Id</label>
            <input id="request-id" className="form-control" inputMode="numeric" value={request.subjectId} onChange={(event) => setRequest({ ...request, subjectId: event.target.value })} required />
          </div>
          <div className="col-md-3">
            <label className="form-label" htmlFor="request-kind">Kind</label>
            <select id="request-kind" className="form-select" value={request.kind} onChange={(event) => setRequest({ ...request, kind: event.target.value })}>
              <option value="export">Export</option>
              <option value="correction">Correction</option>
              <option value="anonymize">Anonymise</option>
            </select>
          </div>
          <div className="col-12">
            <label className="form-label" htmlFor="request-details">Details</label>
            <textarea id="request-details" className="form-control" rows={2} value={request.details} onChange={(event) => setRequest({ ...request, details: event.target.value })} />
          </div>
          <div className="col-12">
            <button type="submit" className="btn btn-primary" disabled={busy}>Open request</button>
          </div>
        </form>
        </div>
      </TableCard>

      <TableCard className="mb-4" title="Requests">
        {requests.status === 'loading' && <div className="card-body"><LoadingIndicator label="Loading requests…" /></div>}
        {requests.status === 'error' && <div className="card-body"><div className="alert alert-danger mb-0" role="alert">{requests.error.message}</div></div>}
        {requests.status === 'ready' && (
          <div className="table-container">
            <table className="table table-sm mb-0">
              <caption className="visually-hidden">Privacy requests</caption>
              <thead>
                <tr>
                  <th scope="col">When</th>
                  <th scope="col">Record</th>
                  <th scope="col">Kind</th>
                  <th scope="col">Status</th>
                  <th scope="col">Details</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody>
                {requests.items.length === 0 && (
                  <tr><td colSpan={6} className="table-empty" data-label="">No privacy requests yet.</td></tr>
                )}
                {requests.items.map((item) => (
                  <tr key={item.id}>
                    <td data-label="When">{formatDateTime(item.requestedAt)}</td>
                    <td data-label="Record">{item.subjectType} {item.subjectId}</td>
                    <td data-label="Kind">{item.kind}</td>
                    <td data-label="Status">{item.status}</td>
                    <td data-label="Details">{item.details || item.resolution || '—'}</td>
                    <td data-label="Actions">
                      <div className="d-flex flex-wrap gap-1 justify-content-end">
                      {item.kind === 'correction' && item.status === 'open' && (
                        <button type="button" className="btn btn-sm btn-outline-secondary" disabled={busy} onClick={() => updateStatus(item, 'in_progress')}>Start</button>
                      )}
                      {item.kind === 'correction' && item.status !== 'completed' && item.status !== 'rejected' && (
                        <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={() => updateStatus(item, 'completed')}>Complete</button>
                      )}
                      {item.kind === 'export' && (
                        <button type="button" className="btn btn-sm btn-outline-primary" disabled={busy} onClick={() => act(item.id, 'export')}>Export</button>
                      )}
                      {item.kind === 'anonymize' && item.status !== 'completed' && (
                        <button type="button" className="btn btn-sm btn-outline-danger" disabled={busy} onClick={() => act(item.id, 'anonymize')}>Anonymise</button>
                      )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </TableCard>

      {exportText && (
        <TableCard className="mb-4" title="Exported personal data">
          <div className="card-body">
          <pre className="mb-0 small text-break">{exportText}</pre>
          </div>
        </TableCard>
      )}
    </PageShell>
  )
}
