import { SALES_OFFICE_CSV } from '../data/salesOfficeCsv.js'
import { downloadCsv, toCsv } from '../utils/csv.js'

/**
 * Export the rows currently shown on a Sales Office list.
 * Search and filters are already applied by the page.
 */
export default function SalesOfficeCsv({ module, records }) {
  const spec = SALES_OFFICE_CSV[module]
  const exportFile = () => downloadCsv(`${spec.filename}.csv`, toCsv(spec.headers, records))

  return (
    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={exportFile}>
      <i className="bi bi-download" aria-hidden="true" /> Export CSV
    </button>
  )
}
