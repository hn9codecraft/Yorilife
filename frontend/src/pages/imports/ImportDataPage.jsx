import { useId, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataTable from '../../components/DataTable.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import { CSV_MAX_BYTES, IMPORT_TYPES } from '../../data/constants.js'
import { formatBytes, inspectCsv } from '../../utils/csv.js'

const PREVIEW_ROWS = 5

async function checkFile(file) {
  const errors = []
  if (!/\.csv$/i.test(file.name)) errors.push('Choose a file with a .csv extension.')
  if (file.size === 0) errors.push('The file is empty.')
  if (file.size > CSV_MAX_BYTES) errors.push(`The file is larger than ${formatBytes(CSV_MAX_BYTES)}.`)
  if (errors.length) return { errors, warnings: [], header: [], dataRows: [] }
  return inspectCsv(await file.text())
}

/**
 * CSV upload with client-side checks and a preview. The file is read in the
 * browser only — it is not uploaded, saved or imported, because backend import
 * processing does not exist yet.
 */
export default function ImportDataPage() {
  const [searchParams] = useSearchParams()
  const ids = useId()
  const initialType = IMPORT_TYPES.some((t) => t.value === searchParams.get('type')) ? searchParams.get('type') : IMPORT_TYPES[0].value
  const [importType, setImportType] = useState(initialType)
  const [file, setFile] = useState(null)
  const [result, setResult] = useState(null)
  const [checking, setChecking] = useState(false)

  async function handleFileChange(event) {
    const chosen = event.target.files?.[0] ?? null
    setFile(chosen)
    setResult(null)
    if (!chosen) return
    setChecking(true)
    setResult(await checkFile(chosen))
    setChecking(false)
  }

  const valid = result && result.errors.length === 0
  const previewColumns = (result?.header ?? []).map((name, index) => ({
    key: String(index),
    header: name || `(column ${index + 1})`,
    nowrap: true,
    render: (row) => row[index] ?? '',
  }))

  return (
    <PageShell>
      <div className="alert alert-warning" role="note">
        <i className="bi bi-exclamation-triangle-fill" aria-hidden="true" />
        <div>
          <strong>Import processing is not built yet.</strong> You can choose a CSV file to check its structure
          and preview it. The file stays in your browser: nothing is uploaded, saved or imported.
        </div>
      </div>

      <div className="row g-4">
        <div className="col-12 col-xl-5">
          <section className="card h-100">
            <div className="card-header"><h2 className="card-title">1. Choose a file</h2></div>
            <div className="card-body">
              <form className="row g-3" onSubmit={(e) => e.preventDefault()}>
                <div className="col-12">
                  <label htmlFor={`${ids}-type`} className="form-label">Data to import</label>
                  <select id={`${ids}-type`} className="form-select" value={importType} onChange={(e) => setImportType(e.target.value)}>
                    {IMPORT_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                  </select>
                  <div className="form-text">The column template for each import type is still to be confirmed.</div>
                </div>
                <div className="col-12">
                  <label htmlFor={`${ids}-file`} className="form-label">CSV file</label>
                  <input
                    id={`${ids}-file`}
                    type="file"
                    accept=".csv,text/csv"
                    className={`form-control${result && !valid ? ' is-invalid' : ''}${valid ? ' is-valid' : ''}`}
                    onChange={handleFileChange}
                    aria-describedby={`${ids}-file-help ${ids}-file-status`}
                  />
                  <div id={`${ids}-file-help`} className="form-text">.csv files up to {formatBytes(CSV_MAX_BYTES)}. The first row must contain column names.</div>
                </div>

                <div id={`${ids}-file-status`} className="col-12" aria-live="polite">
                  {checking && <p className="text-muted mb-0">Checking file…</p>}
                  {file && result && (
                    <div className="file-summary">
                      <i className="bi bi-file-earmark-spreadsheet file-summary__icon" aria-hidden="true" />
                      <div className="min-w-0">
                        <p className="file-summary__name">{file.name}</p>
                        <p className="file-summary__meta">
                          {formatBytes(file.size)}
                          {result.header.length > 0 && ` · ${result.header.length} columns · ${result.dataRows.length} data rows`}
                        </p>
                      </div>
                      <StatusBadge variant={valid ? 'success' : 'danger'}>{valid ? 'Structure OK' : 'Needs fixing'}</StatusBadge>
                    </div>
                  )}
                </div>

                {result?.errors.length > 0 && (
                  <div className="col-12">
                    <div className="alert alert-danger mb-0" role="alert">
                      <i className="bi bi-x-octagon-fill" aria-hidden="true" />
                      <ul className="mb-0 ps-3">{result.errors.map((e) => <li key={e}>{e}</li>)}</ul>
                    </div>
                  </div>
                )}
                {result?.warnings.length > 0 && (
                  <div className="col-12">
                    <div className="alert alert-warning mb-0" role="status">
                      <i className="bi bi-exclamation-triangle-fill" aria-hidden="true" />
                      <ul className="mb-0 ps-3">{result.warnings.map((w) => <li key={w}>{w}</li>)}</ul>
                    </div>
                  </div>
                )}

                <div className="col-12 d-flex flex-wrap align-items-center gap-2">
                  <button type="submit" className="btn btn-primary" disabled aria-describedby={`${ids}-import-note`}>
                    <i className="bi bi-upload" aria-hidden="true" /> Start import
                  </button>
                  <span id={`${ids}-import-note`} className="form-text mt-0">Available once backend import processing is built.</span>
                </div>
              </form>
            </div>
          </section>
        </div>

        <div className="col-12 col-xl-7">
          <section className="card h-100">
            <div className="card-header"><h2 className="card-title">2. Import results</h2></div>
            <div className="card-body">
              <dl className="stat-grid">
                <div className="stat-tile"><dt>Status</dt><dd>Not started</dd></div>
                <div className="stat-tile"><dt>Successful records</dt><dd>—</dd></div>
                <div className="stat-tile"><dt>Failed records</dt><dd>—</dd></div>
              </dl>
              <h3 className="eyebrow mt-4 mb-2">Row validation errors</h3>
              <DataTable
                caption="Row validation errors"
                columns={[
                  { key: 'line', header: 'Line' },
                  { key: 'column', header: 'Column' },
                  { key: 'message', header: 'Problem' },
                ]}
                rows={[]}
                emptyText="Row-level results will appear here once import processing is built."
              />
              <p className="form-text mt-3 mb-0">
                Past imports will be listed in <Link to="/imports/history">Import History</Link>.
              </p>
            </div>
          </section>
        </div>
      </div>

      {valid && result.dataRows.length > 0 && (
        <section className="card table-card mt-4" aria-labelledby={`${ids}-preview-title`}>
          <div className="card-header">
            <h2 id={`${ids}-preview-title`} className="card-title">
              Preview — first {Math.min(PREVIEW_ROWS, result.dataRows.length)} of {result.dataRows.length} rows
            </h2>
          </div>
          <DataTable
            caption={`Preview of ${file.name}`}
            columns={previewColumns}
            rows={result.dataRows.slice(0, PREVIEW_ROWS)}
            rowKey={(row) => result.dataRows.indexOf(row)}
          />
        </section>
      )}
    </PageShell>
  )
}
