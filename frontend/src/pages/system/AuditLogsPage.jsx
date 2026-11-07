import { useEffect, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import Modal from '../../components/Modal.jsx'
import ListPager from '../../components/ListPager.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import { api, apiDownload } from '../../api/client.js'
import { formatDateTime } from '../../data/selectors.js'

const PAGE_SIZE = 50

const RESULT_BADGE = {
  ok: { label: 'OK', variant: 'success' },
  denied: { label: 'Denied', variant: 'danger' },
  failed: { label: 'Failed', variant: 'warning' },
}

export default function AuditLogsPage() {
  const [state, setState] = useState({ status: 'loading', items: [], total: 0, limit: PAGE_SIZE, offset: 0, error: null })
  const [filters, setFilters] = useState({ q: '', module: '', result: '', from: '', to: '', archived: '' })
  const [applied, setApplied] = useState({ q: '', module: '', result: '', from: '', to: '', archived: '' })
  const [offset, setOffset] = useState(0)
  const [detail, setDetail] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [chain, setChain] = useState(null)
  const [archiveOpen, setArchiveOpen] = useState(false)
  const [notice, setNotice] = useState(null)

  useEffect(() => {
    const controller = new AbortController()
    const params = new URLSearchParams()
    params.set('limit', String(PAGE_SIZE))
    params.set('offset', String(offset))
    if (applied.q) params.set('q', applied.q)
    if (applied.module) params.set('module', applied.module)
    if (applied.result) params.set('result', applied.result)
    if (applied.from) params.set('from', applied.from)
    if (applied.to) params.set('to', applied.to)
    if (applied.archived) params.set('archived', applied.archived)
    setState((s) => ({ ...s, status: 'loading' }))
    api(`/api/audit-logs?${params}`, { signal: controller.signal })
      .then((data) => setState({ status: 'ready', items: data.items, total: data.total, limit: data.limit, offset: data.offset, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState((s) => ({ ...s, status: 'error', error }))
      })
    api('/api/audit-logs/verify', { signal: controller.signal })
      .then((data) => setChain(data))
      .catch((error) => {
        if (error.name !== 'AbortError') setChain({ intact: false, reason: 'unavailable' })
      })
    return () => controller.abort()
  }, [applied, offset, reloadKey])

  const columns = [
    { key: 'occurredAt', header: 'When', nowrap: true, render: (r) => formatDateTime(r.occurredAt) },
    { key: 'actorLabel', header: 'Who', render: (r) => r.actorLabel ?? <span className="text-muted">system</span> },
    { key: 'module', header: 'Module', nowrap: true },
    { key: 'action', header: 'Action', nowrap: true },
    {
      key: 'result',
      header: 'Result',
      render: (r) => {
        const badge = RESULT_BADGE[r.result] ?? RESULT_BADGE.ok
        return <StatusBadge variant={badge.variant}>{badge.label}</StatusBadge>
      },
    },
    {
      key: 'target',
      header: 'Target',
      render: (r) => (r.targetLabel ? <span>{r.targetLabel}{r.bridged ? <span className="text-muted"> (bridged)</span> : null}</span> : '—'),
    },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (r) => (
        <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDetail(r)}>
          <i className="bi bi-eye" aria-hidden="true" /> View
        </button>
      ),
    },
  ]

  return (
    <PageShell
      actions={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={() => setArchiveOpen(true)}>
            <i className="bi bi-archive" aria-hidden="true" /> Archive old logs
          </button>
          <button
            type="button"
            className="btn btn-outline-secondary"
            onClick={() => {
              const params = new URLSearchParams()
              if (applied.q) params.set('q', applied.q)
              if (applied.module) params.set('module', applied.module)
              if (applied.result) params.set('result', applied.result)
              if (applied.from) params.set('from', applied.from)
              if (applied.to) params.set('to', applied.to)
              if (applied.archived) params.set('archived', applied.archived)
              apiDownload(`/api/audit-logs/export?${params}`, 'yorilife-audit-logs.csv').catch(() => {})
            }}
          >
            <i className="bi bi-download" aria-hidden="true" /> Export CSV
          </button>
        </>
      }
    >
      {chain && (
        <div className={`alert ${chain.intact ? 'alert-success' : 'alert-danger'}`} role="status">
          <i className={`bi ${chain.intact ? 'bi-shield-check' : 'bi-shield-exclamation'}`} aria-hidden="true" />
          <div>
            {chain.intact
              ? `Audit chain intact${chain.checked != null ? ` (${chain.checked} entries checked)` : ''}.`
              : `Audit chain broken${chain.brokenAt ? ` at entry ${chain.brokenAt}` : ''}. ${chain.reason === 'hash_mismatch' ? 'A stored row no longer matches its hash.' : chain.reason === 'prev_mismatch' ? 'A row is missing or out of order.' : chain.reason === 'unsealed' ? 'A row has no hash.' : chain.reason === 'tail_truncated' ? 'The newest audit entries are missing.' : chain.reason === 'head_mismatch' ? 'The saved chain head does not match the last entry.' : 'The chain could not be verified.'}`}
          </div>
        </div>
      )}
      {notice && <div className={`alert alert-${notice.variant}`} role="status">{notice.text}</div>}
      <TableCard
        title={`Audit logs (${state.total})`}
        toolbar={
          <form
            className="row g-2 align-items-end"
            onSubmit={(e) => {
              e.preventDefault()
              setOffset(0)
              setApplied({ ...filters })
            }}
          >
            <div className="col-auto">
              <SearchInput value={filters.q} onChange={(q) => setFilters((f) => ({ ...f, q }))} label="Search" placeholder="Search actor, action, target" />
            </div>
            <div className="col-auto">
              <label className="form-label mb-0" htmlFor="audit-module">Module</label>
              <input id="audit-module" className="form-control form-control-sm" value={filters.module} onChange={(e) => setFilters((f) => ({ ...f, module: e.target.value }))} placeholder="auth, users…" />
            </div>
            <div className="col-auto">
              <label className="form-label mb-0" htmlFor="audit-result">Result</label>
              <select id="audit-result" className="form-select form-select-sm" value={filters.result} onChange={(e) => setFilters((f) => ({ ...f, result: e.target.value }))}>
                <option value="">All</option>
                <option value="ok">OK</option>
                <option value="denied">Denied</option>
                <option value="failed">Failed</option>
              </select>
            </div>
            <div className="col-auto">
              <label className="form-label mb-0" htmlFor="audit-from">From (IST)</label>
              <input id="audit-from" type="date" className="form-control form-control-sm" value={filters.from} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} />
            </div>
            <div className="col-auto">
              <label className="form-label mb-0" htmlFor="audit-to">To (IST)</label>
              <input id="audit-to" type="date" className="form-control form-control-sm" value={filters.to} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} />
            </div>
            <div className="col-auto">
              <label className="form-label mb-0" htmlFor="audit-archived">Rows</label>
              <select id="audit-archived" className="form-select form-select-sm" value={filters.archived} onChange={(e) => setFilters((f) => ({ ...f, archived: e.target.value }))}>
                <option value="">Active</option>
                <option value="only">Archived</option>
                <option value="all">All</option>
              </select>
            </div>
            <div className="col-auto">
              <button type="submit" className="btn btn-sm btn-primary">Apply</button>
            </div>
          </form>
        }
      >
        {state.status === 'loading' && <LoadingIndicator label="Loading audit logs…" />}
        {state.status === 'error' && (
          <div className="card-body">
            <div className="alert alert-danger mb-0" role="alert">
              {state.error.message}{' '}
              <button type="button" className="btn btn-link p-0 align-baseline" onClick={() => setReloadKey((k) => k + 1)}>Try again</button>
            </div>
          </div>
        )}
        {state.status === 'ready' && (
          <DataTable caption="Audit logs" columns={columns} rows={state.items} emptyText="No audit log entries yet." />
        )}
        {state.status === 'ready' && (
          <div className="card-body border-top">
            <ListPager total={state.total} limit={state.limit || PAGE_SIZE} offset={offset} onPage={(page) => setOffset((page - 1) * PAGE_SIZE)} label="Audit log pages" />
          </div>
        )}
      </TableCard>
      <ConfirmDialog
        open={archiveOpen}
        onClose={() => setArchiveOpen(false)}
        onConfirm={async () => {
          const data = await api('/api/audit-logs/archive', { method: 'POST', body: {} })
          setNotice({ variant: 'success', text: `Archived ${data.archived} audit log ${data.archived === 1 ? 'entry' : 'entries'} older than ${data.olderThanDays} days. Nothing was deleted.` })
          setReloadKey((k) => k + 1)
        }}
        title="Archive old audit logs?"
        confirmLabel="Archive"
        variant="primary"
      >
        <p className="mb-0">Rows older than the retention setting are marked archived and hidden from the active list. They stay in the database and in the hash chain.</p>
      </ConfirmDialog>

      <Modal open={Boolean(detail)} onClose={() => setDetail(null)} title="Audit log detail">
        {detail && (
          <dl className="detail-list">
            {[
              ['When', formatDateTime(detail.occurredAt)],
              ['Who', detail.actorLabel ?? 'system'],
              ['Module', detail.module],
              ['Action', detail.action],
              ['Result', detail.result],
              ['Target', detail.targetLabel ?? '—'],
              ['IP', detail.ipAddress ?? '—'],
              ['Request id', detail.requestId ?? '—'],
              ['Details', detail.details ? <pre key="details" className="mb-0 small">{JSON.stringify(detail.details, null, 2)}</pre> : '—'],
            ].map(([label, value]) => (
              <div key={label} className="detail-list__row">
                <dt>{label}</dt>
                <dd>{value}</dd>
              </div>
            ))}
          </dl>
        )}
      </Modal>
    </PageShell>
  )
}
