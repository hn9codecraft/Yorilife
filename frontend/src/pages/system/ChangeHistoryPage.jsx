import { useEffect, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import ChangeValue from '../../components/ChangeValue.jsx'
import { api, apiDownload } from '../../api/client.js'
import { formatDateTime } from '../../data/selectors.js'

/**
 * System → Change History (all modules) and Products → Change History
 * (module=products). Same API; products filter is passed by the parent route.
 */
export default function ChangeHistoryPage({ module = null }) {
  const [state, setState] = useState({ status: 'loading', items: [], total: 0, error: null })
  const [filters, setFilters] = useState({ q: '', entityType: '', from: '', to: '' })
  const [applied, setApplied] = useState({ q: '', entityType: '', from: '', to: '' })
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    const params = new URLSearchParams()
    params.set('limit', '50')
    params.set('offset', '0')
    if (module) params.set('module', module)
    if (applied.q) params.set('q', applied.q)
    if (applied.entityType) params.set('entityType', applied.entityType)
    if (applied.from) params.set('from', applied.from)
    if (applied.to) params.set('to', applied.to)
    setState((s) => ({ ...s, status: 'loading' }))
    api(`/api/change-history?${params}`, { signal: controller.signal })
      .then((data) => setState({ status: 'ready', items: data.items, total: data.total, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState((s) => ({ ...s, status: 'error', error }))
      })
    return () => controller.abort()
  }, [applied, reloadKey, module])

  const columns = [
    { key: 'changedAt', header: 'When', nowrap: true, render: (r) => formatDateTime(r.changedAt) },
    { key: 'entityType', header: 'Type', nowrap: true },
    {
      key: 'entityLabel',
      header: 'Record',
      render: (r) => (
        <>
          <strong className="fw-semibold">{r.entityLabel ?? `#${r.entityId}`}</strong>
          {r.bridged ? <span className="text-muted"> (bridged)</span> : null}
        </>
      ),
    },
    { key: 'field', header: 'Field', nowrap: true },
    {
      key: 'values',
      header: 'Change',
      render: (r) => <ChangeValue from={r.oldValue} to={r.newValue} />,
    },
    { key: 'changedByName', header: 'Changed by', render: (r) => r.changedByName ?? <span className="text-muted">—</span> },
  ]

  return (
    <PageShell
      actions={
        <button
          type="button"
          className="btn btn-outline-secondary"
          onClick={() => {
            const params = new URLSearchParams()
            if (module) params.set('module', module)
            if (applied.q) params.set('q', applied.q)
            if (applied.entityType) params.set('entityType', applied.entityType)
            if (applied.from) params.set('from', applied.from)
            if (applied.to) params.set('to', applied.to)
            apiDownload(`/api/change-history/export?${params}`, 'yorilife-change-history.csv').catch(() => {})
          }}
        >
          <i className="bi bi-download" aria-hidden="true" /> Export CSV
        </button>
      }
    >
      <TableCard
        title={`Changes (${state.total})`}
        toolbar={
          <form
            className="row g-2 align-items-end"
            onSubmit={(e) => {
              e.preventDefault()
              setApplied({ ...filters })
            }}
          >
            <div className="col-auto">
              <SearchInput value={filters.q} onChange={(q) => setFilters((f) => ({ ...f, q }))} label="Search" placeholder="Search record or field" />
            </div>
            {!module && (
              <div className="col-auto">
                <label className="form-label mb-0" htmlFor="ch-type">Entity type</label>
                <input id="ch-type" className="form-control form-control-sm" value={filters.entityType} onChange={(e) => setFilters((f) => ({ ...f, entityType: e.target.value }))} placeholder="user, product…" />
              </div>
            )}
            <div className="col-auto">
              <label className="form-label mb-0" htmlFor="ch-from">From (IST)</label>
              <input id="ch-from" type="date" className="form-control form-control-sm" value={filters.from} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} />
            </div>
            <div className="col-auto">
              <label className="form-label mb-0" htmlFor="ch-to">To (IST)</label>
              <input id="ch-to" type="date" className="form-control form-control-sm" value={filters.to} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} />
            </div>
            <div className="col-auto">
              <button type="submit" className="btn btn-sm btn-primary">Apply</button>
            </div>
          </form>
        }
      >
        {state.status === 'loading' && <LoadingIndicator label="Loading change history…" />}
        {state.status === 'error' && (
          <div className="card-body">
            <div className="alert alert-danger mb-0" role="alert">
              {state.error.message}{' '}
              <button type="button" className="btn btn-link p-0 align-baseline" onClick={() => setReloadKey((k) => k + 1)}>Try again</button>
            </div>
          </div>
        )}
        {state.status === 'ready' && (
          <DataTable
            caption="Change history"
            columns={columns}
            rows={state.items}
            emptyText={module === 'products' ? 'No product changes yet.' : 'No changes recorded yet.'}
          />
        )}
      </TableCard>
    </PageShell>
  )
}
