import { useEffect, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import { api } from '../../api/client.js'
import { formatDateTime } from '../../data/selectors.js'

export default function SettingsPage() {
  const [state, setState] = useState({ status: 'loading', data: null, error: null })

  useEffect(() => {
    const controller = new AbortController()
    api('/api/settings', { signal: controller.signal })
      .then((data) => setState({ status: 'ready', data, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState({ status: 'error', data: null, error })
      })
    return () => controller.abort()
  }, [])

  return (
    <PageShell>
      {state.status === 'loading' && <LoadingIndicator label="Loading settings…" />}
      {state.status === 'error' && (
        <div className="alert alert-danger" role="alert">{state.error.message}</div>
      )}
      {state.status === 'ready' && (
        <>
          <div className="alert alert-info" role="status">
            <i className="bi bi-info-circle-fill" aria-hidden="true" />
            <div>{state.data.note}</div>
          </div>
          {state.data.groups.map((group) => (
            <TableCard key={group.id} title={group.label}>
              <div className="table-container">
                <table className="table table-sm mb-0">
                  <caption className="visually-hidden">{group.label}</caption>
                  <thead>
                    <tr>
                      <th scope="col">Setting</th>
                      <th scope="col">Value</th>
                      <th scope="col">Updated</th>
                    </tr>
                  </thead>
                  <tbody>
                    {group.items.map((item) => (
                      <tr key={item.key}>
                        <td data-label="Setting">
                          <strong className="fw-semibold">{item.label}</strong>
                          {item.source && <div className="small text-muted">{item.source}</div>}
                        </td>
                        <td data-label="Value"><code>{item.value === '' || item.value == null ? '—' : item.value}</code></td>
                        <td data-label="Updated">{item.updatedAt ? formatDateTime(item.updatedAt) : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </TableCard>
          ))}
        </>
      )}
    </PageShell>
  )
}
