import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import Modal from '../../components/Modal.jsx'
import useErpData from '../../data/useErpData.js'
import { api, apiDownload } from '../../api/client.js'
import { IMPORT_STATUS, formatDateTime, linkedRollbackItems, rollbackSelectionSummary, selectableRollbackIds, toggleRollbackItem } from '../../utils/imports.js'

const RESULT = {
  restored: { label: 'Restore', variant: 'info' },
  deleted: { label: 'Delete', variant: 'warning' },
  skipped: { label: 'Skip', variant: 'secondary' },
  not_selected: { label: 'Keep (not selected)', variant: 'light' },
}
const TABLE_LABEL = {
  locations: 'State',
  location_headquarters: 'Location HQ',
  managers: 'Manager',
  headquarters: 'Headquarters',
  stockists: 'Stockist',
}

/** Rollback popup: shows what would happen, lets the user pick records, then rolls back on confirm. */
function RollbackModal({ record, onClose, onDone }) {
  const [plan, setPlan] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)
  const [selected, setSelected] = useState(() => new Set())
  const [linkMessage, setLinkMessage] = useState(null)

  const [shownFor, setShownFor] = useState(record)

  // A new record starts with a fresh plan (state reset during render).
  if (record !== shownFor) {
    setShownFor(record)
    setPlan(null)
    setError(null)
    setResult(null)
    setSelected(new Set())
    setLinkMessage(null)
  }

  useEffect(() => {
    if (!record) return undefined
    let cancelled = false
    api(`/api/imports/${record.id}/rollback/preview`, { method: 'POST', body: {} })
      .then((data) => {
        if (cancelled) return
        setPlan(data.rollback)
        setSelected(new Set(selectableRollbackIds(data.rollback.items)))
      })
      .catch((err) => !cancelled && setError(err.message))
    return () => {
      cancelled = true
    }
  }, [record])

  const items = plan?.items ?? []
  const selectable = selectableRollbackIds(items)
  const summary = rollbackSelectionSummary(items, selected)
  const label = (item) => `${TABLE_LABEL[item.table] ?? item.table} ${item.code ?? ''}`.trim()
  const names = (list) => list.map(label).join(', ')

  const toggle = (item) => {
    const checked = !selected.has(item.id)
    const next = toggleRollbackItem(items, selected, item.id, checked)
    const changed = items.filter((other) => other.id !== item.id && next.has(other.id) !== selected.has(other.id))
    setSelected(next)
    if (changed.length === 0) setLinkMessage(null)
    else if (checked) setLinkMessage(`Rolling back ${label(item)} also rolls back the records this import created under it: ${names(changed)}.`)
    else setLinkMessage(`${label(item)} will stay, so these records above it cannot be deleted and were unticked too: ${names(changed)}.`)
  }
  const selection = plan && !result ? {
    isSelected: (item) => selected.has(item.id),
    isDisabled: (item) => !selectable.includes(item.id),
    onToggle: toggle,
    onToggleAll: () => {
      setSelected(selected.size === selectable.length ? new Set() : new Set(selectable))
      setLinkMessage(null)
    },
    allSelected: selectable.length > 0 && selected.size === selectable.length,
    indeterminate: selected.size > 0 && selected.size < selectable.length,
    label: (item) => `Roll back ${label(item)}`,
  } : null

  const run = async () => {
    if (busy || selected.size === 0) return
    setBusy(true)
    setError(null)
    try {
      const data = await api(`/api/imports/${record.id}/rollback`, { method: 'POST', body: { changeIds: [...selected] } })
      setResult(data.rollback)
      onDone()
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  const note = (item) => {
    if (item.note) return item.note
    const linked = result ? [] : linkedRollbackItems(items, item.id)
    return linked.length ? `Also rolls back: ${names(linked)}.` : '-'
  }
  const columns = [
    { key: 'line', header: 'Row', nowrap: true },
    { key: 'table', header: 'Record', nowrap: true, render: label },
    { key: 'action', header: 'Import', nowrap: true, render: (item) => (item.action === 'created' ? 'Created' : 'Updated') },
    {
      key: 'result',
      header: result ? 'Result' : 'Rollback will',
      nowrap: true,
      render: (item) => {
        const shown = !result && selectable.includes(item.id) && !selected.has(item.id) ? 'not_selected' : item.result
        return <StatusBadge variant={RESULT[shown]?.variant}>{RESULT[shown]?.label}</StatusBadge>
      },
    },
    { key: 'note', header: 'Note', render: note },
  ]

  return (
    <Modal
      open={Boolean(record)}
      onClose={onClose}
      title={record ? `Roll back import #${record.id}` : 'Roll back import'}
      dialogClassName="modal-form-wide"
      footer={(
        <>
          <button type="button" className="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">{result ? 'Close' : 'Cancel'}</button>
          {!result && (
            <button type="button" className="btn btn-sm btn-danger" disabled={!plan || selected.size === 0} aria-busy={busy || undefined} onClick={run}>
              {busy && <span className="spinner-border spinner-border-sm" aria-hidden="true" />} Roll back {plan ? `${selected.size} selected` : ''}
            </button>
          )}
        </>
      )}
    >
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      {!plan && !error && <p className="text-secondary mb-0">Checking what can be undone...</p>}
      {result && (
        <p className="alert alert-success" role="status">
          Rolled back: {result.restored} restored, {result.deleted} deleted, {result.skipped} skipped{result.notSelected ? `, ${result.notSelected} not selected (left as imported)` : ''}.{result.pendingLeft > 0 && ` ${result.pendingLeft} record(s) can still be rolled back later.`}
        </p>
      )}
      {plan && !result && (
        <>
          <p className="mb-2" data-testid="rollback-summary">
            Selected: {summary.restore} to restore, {summary.delete} to delete.
            {summary.notSelected > 0 && ` ${summary.notSelected} not selected (left as imported).`}
            {summary.skip > 0 && ` ${summary.skip} will be skipped anyway.`}
          </p>
          <p className="small text-secondary mb-3">
            Tick the records to undo. Updated records go back to how they were before the import; records it created are deleted, together with the records this import created under them. A record changed later, or linked to other records, is skipped. Unticked records stay as imported and can be rolled back later. Records already rolled back are not listed again.
          </p>
          {linkMessage && <div className="alert alert-info py-2 small" role="status">{linkMessage}</div>}
        </>
      )}
      {plan && (
        <DataTable caption="Rollback items" columns={columns} rows={(result ?? plan).items} rowKey={(item) => item.id} selection={selection} emptyText="This import made no changes." />
      )}
    </Modal>
  )
}
/** The rollback runs of one import, shown under its row in the history. */
function RollbackHistory({ row, open, onToggle }) {
  const runs = row.rollbacks ?? []
  if (runs.length === 0) return null
  return (
    <div className="ps-3" data-testid={`rollback-history-${row.id}`}>
      <button type="button" className="btn btn-link btn-sm p-0" aria-expanded={open} onClick={onToggle}>
        <i className={`bi ${open ? 'bi-chevron-down' : 'bi-chevron-right'}`} aria-hidden="true" /> Rollback history ({runs.length})
      </button>
      {open && (
        <ol className="list-unstyled mb-0 mt-2">
          {runs.map((run, index) => (
            <li key={run.id} className="border-start border-2 ps-3 mb-3" data-testid="rollback-entry">
              <div className="small">
                <strong>Rollback {index + 1}</strong> - {formatDateTime(run.at)} by {run.by ?? 'unknown user'}:{' '}
                {run.restored} restored, {run.deleted} deleted, {run.skipped} skipped
                {run.notSelected > 0 && `, ${run.notSelected} left for later`}
              </div>
              {run.items.length > 0 && (
                <ul className="small text-secondary mb-0">
                  {run.items.map((item) => (
                    <li key={`${item.code}-${item.type}-${item.action}`}>
                      {item.code} ({item.type ?? TABLE_LABEL[item.table] ?? 'record'}) - {item.action}
                      {item.result === 'skipped' && ` - skipped${item.note ? `: ${item.note}` : ''}`}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}

export default function ImportHistoryPage() {
  const { actions } = useErpData()
  const [imports, setImports] = useState(null)
  const [error, setError] = useState(null)
  const [rollingBack, setRollingBack] = useState(null)
  const [downloadError, setDownloadError] = useState(null)
  const [collapsed, setCollapsed] = useState(() => new Set())
  const toggleHistory = (id) => setCollapsed((current) => {
    const next = new Set(current)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    return next
  })

  const [version, setVersion] = useState(0)
  const load = useCallback(() => setVersion((value) => value + 1), [])

  useEffect(() => {
    let cancelled = false
    api('/api/imports')
      .then((data) => {
        if (cancelled) return
        setImports(data.imports)
        setError(null)
      })
      .catch((err) => !cancelled && setError(err.message))
    return () => {
      cancelled = true
    }
  }, [version])

  const download = (path, name) => {
    setDownloadError(null)
    apiDownload(path, name).catch((err) => setDownloadError(err.message))
  }

  const columns = [
    { key: 'id', header: '#', nowrap: true },
    { key: 'typeLabel', header: 'List', nowrap: true },
    { key: 'filename', header: 'File', render: (row) => <span className="text-break">{row.filename}</span> },
    { key: 'status', header: 'Status', nowrap: true, render: (row) => <StatusBadge variant={IMPORT_STATUS[row.status]?.variant}>{IMPORT_STATUS[row.status]?.label ?? row.status}</StatusBadge> },
    { key: 'counts', header: 'Rows', nowrap: true, render: (row) => `${row.totalRows} · ${row.created} new · ${row.updated} upd · ${row.errorRows} err` },
    { key: 'by', header: 'By', render: (row) => row.importedBy ?? row.uploadedBy ?? '—' },
    { key: 'at', header: 'When', nowrap: true, render: (row) => formatDateTime(row.importedAt ?? row.uploadedAt) },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (row) => (
        <div className="btn-group btn-group-sm" role="group" aria-label={`Import ${row.id} actions`}>
          <button type="button" className="btn btn-outline-secondary" onClick={() => download(`/api/imports/${row.id}/file`, row.type === 'unified' ? 'import.xlsx' : 'import.csv')}>
            <i className="bi bi-filetype-csv" aria-hidden="true" /> CSV
          </button>
          {row.errorRows > 0 && (
            <button type="button" className="btn btn-outline-secondary" onClick={() => download(`/api/imports/${row.id}/errors`, 'import-errors.csv')}>
              <i className="bi bi-exclamation-triangle" aria-hidden="true" /> Errors
            </button>
          )}
          {row.hasBackup && (
            <button type="button" className="btn btn-outline-secondary" onClick={() => download(`/api/imports/${row.id}/backup`, `import-${row.id}-backup.csv`)}>
              <i className="bi bi-shield-check" aria-hidden="true" /> Backup
            </button>
          )}
          {row.canRollback && (
            <button type="button" className="btn btn-outline-danger" onClick={() => setRollingBack(row)}>
              <i className="bi bi-arrow-counterclockwise" aria-hidden="true" /> Rollback
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={(
        <Link to="/imports/data" className="btn btn-primary">
          <i className="bi bi-upload" aria-hidden="true" /> Import Data
        </Link>
      )}
    >
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      {downloadError && <div className="alert alert-danger" role="alert">{downloadError}</div>}
      <TableCard title={`Imports (${imports?.length ?? 0})`}>
        <DataTable
          caption="Import history"
          columns={columns}
          rows={imports ?? []}
          renderAfterRow={(row) => (row.rollbacks?.length
            ? <RollbackHistory row={row} open={!collapsed.has(row.id)} onToggle={() => toggleHistory(row.id)} />
            : null)}
          emptyText={imports === null ? 'Loading…' : 'No imports yet.'}
        />
      </TableCard>
      <RollbackModal
        record={rollingBack}
        onClose={() => setRollingBack(null)}
        onDone={() => {
          load()
          actions.reload()
        }}
      />
    </PageShell>
  )
}
