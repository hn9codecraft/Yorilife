import { useEffect, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import Modal from '../../components/Modal.jsx'
import { api } from '../../api/client.js'
import { formatDateTime } from '../../data/selectors.js'

function fieldValue(item, draft) {
  return draft[item.key] ?? item.value ?? ''
}

export default function SettingsPage() {
  const [state, setState] = useState({ status: 'loading', data: null, error: null })
  const [draft, setDraft] = useState({})
  const [saving, setSaving] = useState(null)
  const [notice, setNotice] = useState(null)
  const [history, setHistory] = useState({ open: false, key: '', label: '', status: 'idle', items: [], error: null })
  const [applying, setApplying] = useState(false)

  useEffect(() => {
    const controller = new AbortController()
    api('/api/settings', { signal: controller.signal })
      .then((data) => setState({ status: 'ready', data, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState({ status: 'error', data: null, error })
      })
    return () => controller.abort()
  }, [])

  async function saveGroup(group) {
    const values = {}
    group.items.forEach((item) => {
      if (!item.editable) return
      const next = fieldValue(item, draft)
      const current = item.value ?? ''
      if (String(next) !== String(current)) values[item.key] = item.input === 'bool' ? next === '1' || next === true : next
    })
    if (Object.keys(values).length === 0) {
      setNotice({ variant: 'info', text: 'Nothing to save in this group.' })
      return
    }
    setSaving(group.id)
    setNotice(null)
    try {
      const data = await api('/api/settings', { method: 'PATCH', body: { values } })
      setState({ status: 'ready', data, error: null })
      setDraft({})
      setNotice({ variant: 'success', text: `${group.label} settings saved. The previous values are kept in the history.` })
    } catch (error) {
      const fields = error.fields ?? {}
      const first = Object.values(fields)[0]
      setNotice({ variant: 'danger', text: first || error.message })
    } finally {
      setSaving(null)
    }
  }

  async function applyRetention() {
    setApplying(true)
    setNotice(null)
    try {
      const data = await api('/api/retention/apply', { method: 'POST', body: {} })
      const archived = data.archived ?? {}
      const total = Object.values(archived).reduce((sum, count) => sum + Number(count || 0), 0)
      setNotice({
        variant: 'success',
        text: `Retention applied. ${total} old row${total === 1 ? '' : 's'} marked archived. No records were deleted.`,
      })
    } catch (error) {
      setNotice({ variant: 'danger', text: error.message })
    } finally {
      setApplying(false)
    }
  }

  async function openHistory(item) {
    setHistory({ open: true, key: item.key, label: item.label, status: 'loading', items: [], error: null })
    try {
      const data = await api(`/api/settings/history?key=${encodeURIComponent(item.key)}`)
      setHistory({ open: true, key: item.key, label: item.label, status: 'ready', items: data.items, error: null })
    } catch (error) {
      setHistory({ open: true, key: item.key, label: item.label, status: 'error', items: [], error })
    }
  }

  return (
    <PageShell>
      {state.status === 'loading' && <LoadingIndicator label="Loading settings…" />}
      {state.status === 'error' && (
        <div className="alert alert-danger" role="alert">{state.error.message}</div>
      )}
      {notice && (
        <div className={`alert alert-${notice.variant}`} role={notice.variant === 'danger' ? 'alert' : 'status'}>{notice.text}</div>
      )}
      {state.status === 'ready' && (
        <>
          <div className="alert alert-info" role="status">
            <i className="bi bi-info-circle-fill" aria-hidden="true" />
            <div>{state.data.note}</div>
          </div>
          {state.data.groups.map((group) => (
            <TableCard
              key={group.id}
              title={group.label}
              toolbar={group.editable ? (
                <div className="d-flex gap-2">
                  {group.id === 'retention' && (
                    <button type="button" className="btn btn-sm btn-outline-secondary" disabled={applying} onClick={applyRetention}>
                      {applying ? 'Applying…' : 'Apply retention'}
                    </button>
                  )}
                  <button type="button" className="btn btn-sm btn-primary" disabled={saving === group.id} onClick={() => saveGroup(group)}>
                    {saving === group.id ? 'Saving…' : 'Save'}
                  </button>
                </div>
              ) : (
                <span className="small text-muted">View only</span>
              )}
            >
              <div className="table-responsive">
                <table className="table table-sm mb-0">
                  <caption className="visually-hidden">{group.label}</caption>
                  <thead>
                    <tr>
                      <th scope="col">Setting</th>
                      <th scope="col">Value</th>
                      <th scope="col">Updated</th>
                      <th scope="col"><span className="visually-hidden">History</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    {group.items.map((item) => {
                      const value = fieldValue(item, draft)
                      const id = `setting-${item.key.replaceAll('.', '-')}`
                      return (
                        <tr key={item.key}>
                          <td>
                            <label className="fw-semibold mb-0" htmlFor={id}>{item.label}</label>
                            {item.help && <div className="small text-muted">{item.help}</div>}
                          </td>
                          <td>
                            {item.input === 'bool' ? (
                              <div className="form-check">
                                <input
                                  id={id}
                                  className="form-check-input"
                                  type="checkbox"
                                  checked={value === '1' || value === true}
                                  disabled={!item.editable}
                                  onChange={(event) => setDraft((current) => ({ ...current, [item.key]: event.target.checked ? '1' : '0' }))}
                                />
                                <label className="form-check-label" htmlFor={id}>{value === '1' || value === true ? 'Yes' : 'No'}</label>
                              </div>
                            ) : item.input === 'textarea' ? (
                              <textarea id={id} className="form-control form-control-sm" rows={2} value={value} disabled={!item.editable} onChange={(event) => setDraft((current) => ({ ...current, [item.key]: event.target.value }))} />
                            ) : (
                              <input
                                id={id}
                                className="form-control form-control-sm"
                                type={item.input === 'number' ? 'number' : item.input === 'email' ? 'email' : 'text'}
                                min={item.min ?? undefined}
                                max={item.max ?? undefined}
                                value={value}
                                disabled={!item.editable}
                                onChange={(event) => setDraft((current) => ({ ...current, [item.key]: event.target.value }))}
                              />
                            )}
                          </td>
                          <td className="text-nowrap">
                            {item.updatedAt ? formatDateTime(item.updatedAt) : '—'}
                            {item.updatedByName && <div className="small text-muted">{item.updatedByName}</div>}
                          </td>
                          <td className="text-end">
                            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => openHistory(item)}>History</button>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </TableCard>
          ))}
        </>
      )}

      <Modal open={history.open} onClose={() => setHistory((current) => ({ ...current, open: false }))} title={history.label ? `${history.label} history` : 'Setting history'}>
        {history.status === 'loading' && <LoadingIndicator label="Loading history…" />}
        {history.status === 'error' && <div className="alert alert-danger" role="alert">{history.error.message}</div>}
        {history.status === 'ready' && history.items.length === 0 && <p className="mb-0 text-muted">No changes recorded yet.</p>}
        {history.status === 'ready' && history.items.length > 0 && (
          <div className="table-responsive">
            <table className="table table-sm">
              <caption className="visually-hidden">Previous values</caption>
              <thead>
                <tr>
                  <th scope="col">When</th>
                  <th scope="col">Who</th>
                  <th scope="col">Previous</th>
                  <th scope="col">New</th>
                </tr>
              </thead>
              <tbody>
                {history.items.map((item) => (
                  <tr key={item.id}>
                    <td className="text-nowrap">{formatDateTime(item.changedAt)}</td>
                    <td>{item.changedByName ?? '—'}</td>
                    <td><code>{item.oldValue === '' || item.oldValue == null ? '—' : item.oldValue}</code></td>
                    <td><code>{item.newValue === '' || item.newValue == null ? '—' : item.newValue}</code></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Modal>
    </PageShell>
  )
}
