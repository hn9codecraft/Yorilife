import DataTable from './DataTable.jsx'
import ChangeValue from './ChangeValue.jsx'
import StatusBadge from './StatusBadge.jsx'
import { CHANGED_BY_UNAVAILABLE } from '../data/constants.js'
import { formatDateTime } from '../data/selectors.js'

const ACTION_BADGES = {
  created: { label: 'Created', variant: 'success' },
  updated: { label: 'Updated', variant: 'info' },
  deleted: { label: 'Deleted', variant: 'danger' },
}

// Product audit trail: one row per create / update / delete event, newest first.
export default function ProductHistoryTable({ entries, showProduct = true, emptyText = 'No changes recorded yet.' }) {
  const rows = [...entries].sort((a, b) => b.at.localeCompare(a.at))

  const columns = [
    { key: 'at', header: 'Date & time', nowrap: true, render: (e) => formatDateTime(e.at) },
    showProduct && { key: 'productName', header: 'Product', render: (e) => <strong className="fw-semibold">{e.productName}</strong> },
    {
      key: 'action',
      header: 'Action',
      render: (e) => <StatusBadge variant={ACTION_BADGES[e.action].variant}>{ACTION_BADGES[e.action].label}</StatusBadge>,
    },
    {
      key: 'changes',
      header: 'Changes',
      render: (e) => (
        <ul className="change-list">
          {e.changes.map((change) => (
            <li key={change.field}>
              <span className="change-list__field">{change.field}:</span> <ChangeValue from={change.from} to={change.to} />
            </li>
          ))}
        </ul>
      ),
    },
    { key: 'by', header: 'Changed by', render: (e) => e.by ?? <span className="text-muted">{CHANGED_BY_UNAVAILABLE}</span> },
  ].filter(Boolean)

  return <DataTable caption="Product change history" columns={columns} rows={rows} emptyText={emptyText} />
}
