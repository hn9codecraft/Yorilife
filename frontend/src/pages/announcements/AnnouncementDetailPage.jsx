import { useCallback, useEffect, useId, useState } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import Modal from '../../components/Modal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import AnnouncementView from '../../components/AnnouncementView.jsx'
import AnnouncementFiles from '../../components/AnnouncementFiles.jsx'
import usePermission from '../../data/usePermission.js'
import { api, apiDownload } from '../../api/client.js'
import { ANNOUNCEMENT_STATUS, HISTORY_ACTION, PRIORITY, RECIPIENT_TYPE, changeText, formatIst, toIstInput } from '../../utils/announcements.js'

const FIELD_LABEL = { title: 'Title', type: 'Type', priority: 'Priority', requiresAck: 'Read confirmation', expiresAt: 'Expiry', audienceAll: 'All employees', message: 'Message', audience: 'Audience', publishAt: 'Publish time', recipients: 'Recipients', reason: 'Reason', name: 'File', kind: 'Kind', by: 'By', copiedFrom: 'Copied from', markedUpdated: 'Marked updated' }

function PublishModal({ open, announcement, onClose, onDone }) {
  const [mode, setMode] = useState('now')
  const [when, setWhen] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [count, setCount] = useState(null)
  const [wasOpen, setWasOpen] = useState(open)
  const name = useId()
  const whenId = useId()

  if (open !== wasOpen) {
    setWasOpen(open)
    if (open) {
      setMode(announcement?.status === 'scheduled' ? 'schedule' : 'now')
      setWhen(toIstInput(announcement?.publishAt))
      setError(null)
      setBusy(false)
      setCount(null)
    }
  }

  useEffect(() => {
    if (!open || !announcement) return undefined
    let cancelled = false
    api('/api/announcements/audience-preview', { method: 'POST', body: { audienceAll: announcement.audienceAll, audience: announcement.audience } })
      .then((data) => !cancelled && setCount(data.audience.total))
      .catch(() => !cancelled && setCount(null))
    return () => {
      cancelled = true
    }
  }, [open, announcement])

  const run = async () => {
    if (busy) return
    if (mode === 'schedule' && !when) {
      setError('Choose the date and time to publish.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      const data = await api(`/api/announcements/${announcement.id}/publish`, { method: 'POST', body: mode === 'schedule' ? { publishAt: when } : {} })
      onDone(data.announcement, mode === 'schedule' ? 'Scheduled.' : 'Published.')
      onClose()
    } catch (err) {
      setError(Object.values(err.fields ?? {}).join(' ') || err.message)
    } finally {
      setBusy(false)
    }
  }

  const priority = PRIORITY[announcement?.priority] ?? PRIORITY.normal
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Publish announcement"
      footer={(
        <>
          <button type="button" className="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" className="btn btn-primary" onClick={run} aria-busy={busy || undefined} aria-disabled={busy || undefined}>
            {busy && <span className="spinner-border spinner-border-sm" aria-hidden="true" />} {mode === 'schedule' ? 'Schedule' : 'Publish now'}
          </button>
        </>
      )}
    >
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      <p data-testid="publish-summary">
        <strong>{announcement?.title}</strong> will go to <strong>{count ?? '...'}</strong> {count === 1 ? 'person' : 'people'}.{' '}
        Priority: <StatusBadge variant={priority.variant}>{priority.label}</StatusBadge>
      </p>
      <div className="choice-group mb-2" role="radiogroup" aria-label="When">
        <label className="form-check">
          <input type="radio" className="form-check-input" name={name} checked={mode === 'now'} onChange={() => setMode('now')} />
          <span className="form-check-label">Publish now</span>
        </label>
        <label className="form-check">
          <input type="radio" className="form-check-input" name={name} checked={mode === 'schedule'} onChange={() => setMode('schedule')} />
          <span className="form-check-label">Schedule for later</span>
        </label>
      </div>
      {mode === 'schedule' && (
        <div>
          <label htmlFor={whenId} className="form-label">Publish at (India time)</label>
          <input id={whenId} type="datetime-local" className="form-control" value={when} onChange={(event) => setWhen(event.target.value)} />
        </div>
      )}
      <p className="small text-secondary mt-3 mb-0">The list of people is saved when it goes live. Notifications will be sent once the employee app is connected.</p>
    </Modal>
  )
}

function RecallModal({ open, announcement, onClose, onDone }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [wasOpen, setWasOpen] = useState(open)
  const reasonId = useId()

  if (open !== wasOpen) {
    setWasOpen(open)
    if (open) {
      setReason('')
      setError(null)
      setBusy(false)
    }
  }

  const run = async () => {
    if (busy) return
    if (!reason.trim()) {
      setError('Enter the reason for the recall.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      const data = await api(`/api/announcements/${announcement.id}/recall`, { method: 'POST', body: { reason } })
      onDone(data.announcement, 'Recalled.')
      onClose()
    } catch (err) {
      setError(err.fields?.reason ?? err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Recall announcement"
      footer={(
        <>
          <button type="button" className="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" className="btn btn-danger" onClick={run} aria-busy={busy || undefined}>Recall</button>
        </>
      )}
    >
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      <p>It will be taken back from all {announcement?.recipientCount ?? 0} recipients and can no longer be edited. You can copy it as a new draft later.</p>
      <label htmlFor={reasonId} className="form-label">Reason<span className="required" aria-hidden="true">*</span></label>
      <textarea id={reasonId} className="form-control" rows={3} maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} />
    </Modal>
  )
}

function Tracking({ announcement }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false
    api(`/api/announcements/${announcement.id}/tracking`)
      .then((result) => !cancelled && setData(result.tracking))
      .catch((err) => !cancelled && setError(err.message))
    return () => {
      cancelled = true
    }
  }, [announcement.id, announcement.status, announcement.version])

  const download = (pending) => apiDownload(`/api/announcements/${announcement.id}/tracking/export${pending ? '?pending=1' : ''}`, 'recipients.csv').catch((err) => setError(err.message))
  const counts = data?.counts ?? { total: 0, delivered: 0, read: 0, acknowledged: 0 }
  const columns = [
    { key: 'type', header: 'Type', nowrap: true, render: (row) => RECIPIENT_TYPE[row.type] },
    { key: 'name', header: 'Name' },
    { key: 'contact', header: 'Email / Phone', render: (row) => row.contact || '\u2014' },
    { key: 'read', header: 'Read', nowrap: true, render: (row) => (row.readAt ? formatIst(row.readAt) : <StatusBadge variant="secondary">Pending</StatusBadge>) },
    ...(announcement.requiresAck ? [{ key: 'ack', header: 'Acknowledged', nowrap: true, render: (row) => (row.acknowledgedAt ? formatIst(row.acknowledgedAt) : <StatusBadge variant="secondary">Pending</StatusBadge>) }] : []),
  ]

  return (
    <TableCard
      title="Tracking"
      toolbar={counts.total > 0 && (
        <>
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => download(false)}><i className="bi bi-download" aria-hidden="true" /> Recipients CSV</button>
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => download(true)}><i className="bi bi-download" aria-hidden="true" /> Not read CSV</button>
        </>
      )}
    >
      <div className="card-body">
        {error && <div className="alert alert-danger" role="alert">{error}</div>}
        <div className="tracking-stats" data-testid="tracking-stats">
          <div className="tracking-stats__item"><span>Sent to</span><strong>{counts.total}</strong></div>
          <div className="tracking-stats__item"><span>Delivered</span><strong>{data?.appConnected ? counts.delivered : '\u2014'}</strong></div>
          <div className="tracking-stats__item"><span>Read</span><strong>{data?.appConnected ? counts.read : '\u2014'}</strong></div>
          <div className="tracking-stats__item"><span>Acknowledged</span><strong>{announcement.requiresAck ? (data?.appConnected ? counts.acknowledged : '\u2014') : 'Not required'}</strong></div>
        </div>
        {data && !data.appConnected && (
          <div className="alert alert-info py-2 mt-3 mb-0" role="status">
            <i className="bi bi-phone" aria-hidden="true" /> App not connected yet. Delivered, read and acknowledged will fill in once the employee app is built; until then everyone shows as pending.
          </div>
        )}
      </div>
      {counts.total > 0 && <DataTable caption="Recipients" columns={columns} rows={data?.recipients ?? []} rowKey={(row) => `${row.type}-${row.id}`} />}
      {counts.total === 0 && <p className="text-secondary px-3 pb-3 mb-0">The recipient list is saved when the announcement goes live.</p>}
    </TableCard>
  )
}

export default function AnnouncementDetailPage() {
  const { id } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const canManage = usePermission('announcements.manage')
  const canPublish = usePermission('announcements.publish')
  const canReports = usePermission('announcements.reports')
  const [record, setRecord] = useState(null)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(location.state?.notice ?? null)
  const [dialog, setDialog] = useState(null)

  const load = useCallback(() => api(`/api/announcements/${id}`).then((data) => setRecord(data.announcement)).catch((err) => setError(err.message)), [id])

  useEffect(() => {
    let cancelled = false
    api(`/api/announcements/${id}`)
      .then((data) => !cancelled && setRecord(data.announcement))
      .catch((err) => !cancelled && setError(err.message))
    return () => {
      cancelled = true
    }
  }, [id])

  const done = (announcement, message) => {
    setRecord(announcement)
    setNotice(message)
  }
  const action = async (path, message) => {
    const data = await api(`/api/announcements/${id}/${path}`, { method: 'POST', body: path === 'pin' ? { pinned: !record.isPinned } : {} })
    if (path === 'copy') {
      navigate(`/announcements/${data.announcement.id}/edit`)
      return
    }
    done(data.announcement, message)
  }
  const download = (file) => apiDownload(`/api/announcements/${id}/attachments/${file.id}`, file.name).catch((err) => setError(err.message))

  if (error && !record) return <PageShell><div className="alert alert-danger" role="alert">{error}</div><Link to="/announcements/list">Back to all announcements</Link></PageShell>
  if (!record) return <PageShell><p className="text-secondary">Loading...</p></PageShell>

  const status = ANNOUNCEMENT_STATUS[record.status]
  const files = (record.attachments ?? []).filter((file) => file.kind === 'file')
  return (
    <PageShell
      actions={(
        <>
          {canManage && record.can.edit && <Link to={`/announcements/${id}/edit`} className="btn btn-outline-secondary"><i className="bi bi-pencil" aria-hidden="true" /> Edit</Link>}
          {canPublish && record.can.publish && <button type="button" className="btn btn-primary" onClick={() => setDialog('publish')}><i className="bi bi-send" aria-hidden="true" /> {record.status === 'scheduled' ? 'Publish / reschedule' : 'Publish'}</button>}
          {canPublish && record.can.unschedule && <button type="button" className="btn btn-outline-secondary" onClick={() => setDialog('unschedule')}>Cancel schedule</button>}
          {canPublish && record.can.pin && <button type="button" className="btn btn-outline-secondary" onClick={() => action('pin', record.isPinned ? 'Unpinned.' : 'Pinned.').catch((err) => setError(err.message))}><i className={`bi ${record.isPinned ? 'bi-pin-angle-fill' : 'bi-pin-angle'}`} aria-hidden="true" /> {record.isPinned ? 'Unpin' : 'Pin'}</button>}
          {canPublish && record.can.recall && <button type="button" className="btn btn-outline-danger" onClick={() => setDialog('recall')}><i className="bi bi-arrow-counterclockwise" aria-hidden="true" /> Recall</button>}
          {canManage && (record.status === 'expired' || record.status === 'recalled') && <button type="button" className="btn btn-outline-secondary" onClick={() => setDialog('copy')}><i className="bi bi-copy" aria-hidden="true" /> Copy as new</button>}
          <a href={`/announcements/${id}/print`} target="_blank" rel="noreferrer" className="btn btn-outline-secondary"><i className="bi bi-printer" aria-hidden="true" /> Print / PDF</a>
          {canManage && record.can.delete && <button type="button" className="btn btn-outline-danger" onClick={() => setDialog('delete')}><i className="bi bi-trash3" aria-hidden="true" /> Delete draft</button>}
        </>
      )}
    >
      {notice && <div className="alert alert-success" role="status">{notice}</div>}
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      {record.status === 'recalled' && <div className="alert alert-danger" role="status"><strong>Recalled</strong> {formatIst(record.recalledAt)} by {record.recalledBy ?? 'an admin'}. Reason: {record.recallReason}</div>}

      <div className="announcement-detail">
        <section className="card" aria-label="Content">
          <div className="card-body">
            <AnnouncementView
              announcement={record}
              bannerUrl={record.bannerId ? `/api/announcements/${id}/attachments/${record.bannerId}?inline=1` : null}
              attachments={files}
              onDownload={download}
            />
          </div>
        </section>
        <aside className="card" aria-label="Details">
          <div className="card-body">
            <dl className="detail-list">
              <div className="detail-list__row"><dt>Status</dt><dd><StatusBadge variant={status.variant}>{status.label}</StatusBadge></dd></div>
              <div className="detail-list__row"><dt>Reference</dt><dd>{record.reference}</dd></div>
              <div className="detail-list__row"><dt>Type</dt><dd>{record.typeLabel}</dd></div>
              <div className="detail-list__row"><dt>Priority</dt><dd><StatusBadge variant={PRIORITY[record.priority].variant}>{record.priorityLabel}</StatusBadge></dd></div>
              <div className="detail-list__row"><dt>Audience</dt><dd>{record.audienceSummary}</dd></div>
              <div className="detail-list__row"><dt>Recipients</dt><dd>{record.status === 'draft' || record.status === 'scheduled' ? 'Saved when it goes live' : record.recipientCount}</dd></div>
              <div className="detail-list__row"><dt>Read confirmation</dt><dd>{record.requiresAck ? 'Required' : 'Not required'}</dd></div>
              {record.publishAt && <div className="detail-list__row"><dt>{record.status === 'scheduled' ? 'Goes live' : 'Publish time'}</dt><dd>{formatIst(record.publishAt)}</dd></div>}
              {record.publishedAt && <div className="detail-list__row"><dt>Published</dt><dd>{formatIst(record.publishedAt)}{record.publishedBy ? ` by ${record.publishedBy}` : ''}</dd></div>}
              <div className="detail-list__row"><dt>Expires</dt><dd>{record.expiresAt ? formatIst(record.expiresAt) : 'Never'}</dd></div>
              <div className="detail-list__row"><dt>Created</dt><dd>{formatIst(record.createdAt)}{record.createdBy ? ` by ${record.createdBy}` : ''}</dd></div>
              <div className="detail-list__row"><dt>Version</dt><dd>{record.version}</dd></div>
            </dl>
          </div>
        </aside>
      </div>

      {canManage && record.can.edit && (
        <section className="card mt-3" aria-label="Banner and files">
          <div className="card-header"><h2 className="card-title">Banner and files</h2></div>
          <div className="card-body">
            <AnnouncementFiles announcementId={id} attachments={record.attachments ?? []} onChanged={load} editable={record.status !== 'published' || canPublish} />
          </div>
        </section>
      )}

      {canReports && <div className="mt-3"><Tracking announcement={record} /></div>}

      <section className="card mt-3" aria-label="History">
        <div className="card-header"><h2 className="card-title">History</h2></div>
        <div className="card-body">
          <ol className="list-unstyled mb-0 announcement-history" data-testid="announcement-history">
            {record.history.map((entry) => (
              <li key={entry.id} className="border-start border-2 ps-3 mb-3">
                <div className="small"><strong>{HISTORY_ACTION[entry.action] ?? entry.action}</strong> - {formatIst(entry.at)} by {entry.by ?? 'the scheduler'}</div>
                {entry.changes && (
                  <ul className="small text-secondary mb-0">
                    {Object.entries(entry.changes).map(([field, value]) => (
                      <li key={field}>
                        {FIELD_LABEL[field] ?? field}: {value && typeof value === 'object' && 'from' in value ? `${changeText(value.from)} \u2192 ${changeText(value.to)}` : changeText(value)}
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ol>
        </div>
      </section>

      <PublishModal open={dialog === 'publish'} announcement={record} onClose={() => setDialog(null)} onDone={done} />
      <RecallModal open={dialog === 'recall'} announcement={record} onClose={() => setDialog(null)} onDone={done} />
      <ConfirmDialog open={dialog === 'unschedule'} onClose={() => setDialog(null)} title="Cancel schedule" confirmLabel="Cancel schedule" variant="primary" onConfirm={() => action('unschedule', 'Schedule cancelled. It is a draft again.')}>
        It goes back to draft and will not be published at {formatIst(record.publishAt)}.
      </ConfirmDialog>
      <ConfirmDialog open={dialog === 'copy'} onClose={() => setDialog(null)} title="Copy as new" confirmLabel="Copy" variant="primary" onConfirm={() => action('copy')}>
        A new draft with the same content and audience will be created (files are not copied).
      </ConfirmDialog>
      <ConfirmDialog
        open={dialog === 'delete'}
        onClose={() => setDialog(null)}
        title="Delete draft"
        confirmLabel="Delete"
        onConfirm={async () => {
          await api(`/api/announcements/${id}/delete`, { method: 'POST', body: {} })
          navigate('/announcements/list')
        }}
      >
        Delete the draft &quot;{record.title}&quot;? It is removed from the list (kept in the database for history).
      </ConfirmDialog>
    </PageShell>
  )
}