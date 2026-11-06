import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import SegmentedControl from '../../components/SegmentedControl.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import usePermission from '../../data/usePermission.js'
import { api } from '../../api/client.js'
import { ANNOUNCEMENT_STATUS, PRIORITIES, PRIORITY, TYPES, formatIst, statusCounts } from '../../utils/announcements.js'

const TABS = ['all', 'draft', 'scheduled', 'published', 'expired', 'recalled']

export default function AnnouncementsListPage() {
  const canManage = usePermission('announcements.manage')
  const [rows, setRows] = useState(null)
  const [error, setError] = useState(null)
  const [tab, setTab] = useState('all')
  const [query, setQuery] = useState('')
  const [type, setType] = useState('')
  const [priority, setPriority] = useState('')

  useEffect(() => {
    let cancelled = false
    api('/api/announcements')
      .then((data) => !cancelled && setRows(data.announcements))
      .catch((err) => !cancelled && setError(err.message))
    return () => {
      cancelled = true
    }
  }, [])

  const counts = statusCounts(rows ?? [])
  const shown = useMemo(() => {
    const q = query.trim().toLowerCase()
    return (rows ?? []).filter((row) => (tab === 'all' || row.status === tab)
      && (!type || row.type === type)
      && (!priority || row.priority === priority)
      && (!q || `${row.title} ${row.reference} ${row.excerpt}`.toLowerCase().includes(q)))
  }, [rows, tab, type, priority, query])

  const columns = [
    {
      key: 'title',
      header: 'Announcement',
      render: (row) => (
        <div className="min-w-0">
          {row.isPinned && <i className="bi bi-pin-angle-fill text-primary me-1" title="Pinned" aria-label="Pinned" />}
          <Link to={`/announcements/${row.id}`} className="fw-semibold">{row.title}</Link>
          {row.isUpdated && <StatusBadge variant="info">Updated</StatusBadge>}
          <div className="small text-secondary">{row.reference} · {row.typeLabel}</div>
        </div>
      ),
    },
    { key: 'priority', header: 'Priority', nowrap: true, render: (row) => <StatusBadge variant={PRIORITY[row.priority]?.variant}>{row.priorityLabel}</StatusBadge> },
    { key: 'status', header: 'Status', nowrap: true, render: (row) => <StatusBadge variant={ANNOUNCEMENT_STATUS[row.status]?.variant}>{ANNOUNCEMENT_STATUS[row.status]?.label}</StatusBadge> },
    {
      key: 'audience',
      header: 'Audience',
      render: (row) => (
        <span className="small">
          {row.status === 'published' || row.status === 'expired' || row.status === 'recalled' ? `${row.recipientCount} recipients` : row.audienceSummary}
        </span>
      ),
    },
    {
      key: 'when',
      header: 'Date (IST)',
      nowrap: true,
      render: (row) => {
        if (row.status === 'scheduled') return <span className="small">Goes live {formatIst(row.publishAt)}</span>
        if (row.publishedAt) return <span className="small">{formatIst(row.publishedAt)}{row.expiresAt ? <><br />Expires {formatIst(row.expiresAt)}</> : null}</span>
        return <span className="small text-secondary">Edited {formatIst(row.updatedAt)}</span>
      },
    },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (row) => (
        <div className="btn-group btn-group-sm" role="group" aria-label={`${row.title} actions`}>
          <Link to={`/announcements/${row.id}`} className="btn btn-outline-secondary">View</Link>
          {canManage && row.can.edit && <Link to={`/announcements/${row.id}/edit`} className="btn btn-outline-secondary">Edit</Link>}
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={canManage && (
        <Link to="/announcements/new" className="btn btn-primary">
          <i className="bi bi-plus-lg" aria-hidden="true" /> New Announcement
        </Link>
      )}
    >
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      <div className="mb-3 overflow-auto">
        <SegmentedControl
          label="Status"
          value={tab}
          onChange={setTab}
          options={TABS.map((value) => ({ value, label: value === 'all' ? 'All' : ANNOUNCEMENT_STATUS[value].label, count: counts[value] }))}
        />
      </div>
      <TableCard
        title={`Announcements (${shown.length})`}
        toolbar={(
          <>
            <select className="form-select form-select-sm w-auto" aria-label="Type" value={type} onChange={(event) => setType(event.target.value)}>
              <option value="">All types</option>
              {TYPES.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
            <select className="form-select form-select-sm w-auto" aria-label="Priority" value={priority} onChange={(event) => setPriority(event.target.value)}>
              <option value="">All priorities</option>
              {PRIORITIES.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </select>
            <SearchInput value={query} onChange={setQuery} label="Search announcements" placeholder="Search title or ANN number" />
          </>
        )}
      >
        <DataTable
          caption="Announcements"
          columns={columns}
          rows={shown}
          empty={rows !== null && rows.length === 0 ? (
            <EmptyState
              icon="bi-megaphone"
              title="No announcements yet"
              text="Write the first announcement. It stays a draft until you publish it."
              action={canManage && <Link to="/announcements/new" className="btn btn-primary btn-sm">New Announcement</Link>}
            />
          ) : undefined}
          emptyText={rows === null ? 'Loading...' : 'No announcements match these filters.'}
        />
      </TableCard>
    </PageShell>
  )
}