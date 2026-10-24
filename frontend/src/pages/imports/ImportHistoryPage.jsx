import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'

// Structure for the import log. Stays empty until backend import processing exists.
const COLUMNS = [
  { key: 'at', header: 'Date & time' },
  { key: 'file', header: 'File' },
  { key: 'type', header: 'Data type' },
  { key: 'status', header: 'Status' },
  { key: 'successful', header: 'Successful', align: 'end' },
  { key: 'failed', header: 'Failed', align: 'end' },
  { key: 'by', header: 'Imported by' },
]

export default function ImportHistoryPage() {
  return (
    <PageShell>
      <div className="alert alert-info" role="note">
        <i className="bi bi-info-circle-fill" aria-hidden="true" />
        <div>
          Imports are recorded here once backend import processing is built. Until then the list stays empty.
          You can already check CSV files on <Link to="/imports/data">Import Data</Link>.
        </div>
      </div>
      <TableCard title="Imports (0)">
        <DataTable caption="Import history" columns={COLUMNS} rows={[]} emptyText="No imports yet." />
      </TableCard>
    </PageShell>
  )
}
