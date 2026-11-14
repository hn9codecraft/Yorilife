import { useState } from 'react'
import { api } from '../api/client.js'
import { buildCsv, csvFilename, downloadCsv } from '../utils/csv.js'

/**
 * Icon-only "Export CSV" button for list toolbars.
 *
 *   <ExportCsvButton name="Stockists" headers={[...]} records={rowsAsObjects} />
 *
 * - records: the rows currently shown (after search and filters), keyed by header.
 * - The export is recorded in the audit log (POST /api/exports) before the file downloads.
 * - Email and phone columns are masked unless mask={false}.
 * - Disabled when there is nothing to export.
 */
export default function ExportCsvButton({
  name,
  headers,
  records,
  auditKind = 'sales_office_csv',
  mask = true,
  label = 'Export CSV',
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const empty = records.length === 0
  const title = empty ? `${label} (nothing to export)` : label

  async function exportFile() {
    if (busy || empty) return
    setBusy(true)
    setError(null)
    const filename = csvFilename(name)
    try {
      await api('/api/exports', { method: 'POST', body: { kind: auditKind, filename, rowCount: records.length } })
      downloadCsv(filename, buildCsv(headers, records, { mask }))
    } catch (err) {
      setError(err.message || 'The export could not be recorded.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <span className="export-csv">
      <button
        type="button"
        className="btn export-csv__button"
        onClick={exportFile}
        disabled={busy || empty}
        aria-label={label}
        aria-busy={busy || undefined}
        title={title}
      >
        {busy
          ? <span className="spinner-border spinner-border-sm" aria-hidden="true" />
          : <i className="bi bi-filetype-csv export-csv__icon" aria-hidden="true" />}
      </button>
      {error && <span className="export-csv__error small text-danger" role="alert">{error}</span>}
    </span>
  )
}