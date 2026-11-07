import { useState } from 'react'
import { SALES_OFFICE_CSV } from '../data/salesOfficeCsv.js'
import { api } from '../api/client.js'
import { downloadCsv, toCsv } from '../utils/csv.js'
import { maskRecord } from '../utils/pii.js'

/**
 * Export the rows currently shown on a Sales Office list.
 * Phone and email are masked. The export is recorded in the audit log first.
 */
export default function SalesOfficeCsv({ module, records }) {
  const spec = SALES_OFFICE_CSV[module]
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function exportFile() {
    if (busy) return
    setBusy(true)
    setError(null)
    const filename = `${spec.filename}.csv`
    try {
      await api('/api/exports', {
        method: 'POST',
        body: { kind: 'sales_office_csv', filename, rowCount: records.length },
      })
      downloadCsv(filename, toCsv(spec.headers, records.map(maskRecord)))
    } catch (err) {
      setError(err.message || 'The export could not be recorded.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="d-inline-flex flex-column align-items-start">
      <button type="button" className="btn btn-sm btn-outline-secondary" onClick={exportFile} disabled={busy}>
        <i className="bi bi-download" aria-hidden="true" /> {busy ? 'Exporting…' : 'Export CSV'}
      </button>
      {error && <span className="small text-danger" role="alert">{error}</span>}
    </div>
  )
}
