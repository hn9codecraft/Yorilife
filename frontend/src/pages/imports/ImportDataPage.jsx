import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import useErpData from '../../data/useErpData.js'
import { api, apiDownload, apiUpload } from '../../api/client.js'
import { ROW_ACTION, checkImportFile, formatBytes, previewSummary } from '../../utils/imports.js'

const STEPS = ['Upload file', 'Review preview', 'Confirm & result']
const PAGE_SIZE = 25
const MATRIX_LABEL = { required: 'Required', optional: 'Optional', blank: '-' }
const XLSX_TYPES = '.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

const PREVIEW_COLUMNS = [
  { key: 'line', header: 'Row', nowrap: true },
  { key: 'typeLabel', header: 'Data Type', nowrap: true, render: (row) => row.typeLabel || 'Unknown' },
  { key: 'code', header: 'Code', nowrap: true, render: (row) => row.code || '-' },
  { key: 'name', header: 'Name', render: (row) => row.name || '-' },
  {
    key: 'action',
    header: 'Result',
    nowrap: true,
    render: (row) => <StatusBadge variant={ROW_ACTION[row.action]?.variant}>{ROW_ACTION[row.action]?.label ?? row.action}</StatusBadge>,
  },
  {
    key: 'messages',
    header: 'Errors and warnings',
    render: (row) => (
      <>
        {row.messages?.map((message) => <div key={message} className="text-danger small">{message}</div>)}
        {row.warnings?.map((warning) => <div key={warning} className="text-warning-emphasis small">{warning}</div>)}
        {!row.messages?.length && !row.warnings?.length && <span className="text-secondary">-</span>}
      </>
    ),
  },
]

function Stepper({ current }) {
  return (
    <ol className="d-flex flex-wrap gap-2 list-unstyled mb-4" aria-label="Import steps">
      {STEPS.map((label, index) => {
        const step = index + 1
        const state = step < current ? 'done' : step === current ? 'current' : 'todo'
        return (
          <li key={label} className="d-flex align-items-center gap-2 me-3" aria-current={state === 'current' ? 'step' : undefined}>
            <span
              className={`badge rounded-pill ${state === 'todo' ? 'text-bg-light border' : state === 'done' ? 'text-bg-success' : 'text-bg-primary'}`}
              aria-hidden="true"
            >
              {state === 'done' ? <i className="bi bi-check-lg" /> : step}
            </span>
            <span className={state === 'current' ? 'fw-semibold' : 'text-secondary'}>{label}</span>
          </li>
        )
      })}
    </ol>
  )
}

/** Which columns each Data Type needs. */
function ColumnReference({ unified }) {
  const columns = unified.columns.slice(1, -1)
  return (
    <div className="table-container">
      <table className="table table-sm small mb-0" aria-label="Columns for each data type">
        <thead>
          <tr>
            <th scope="col">Data Type</th>
            {columns.map((column) => <th scope="col" key={column} className="text-nowrap">{column}</th>)}
          </tr>
        </thead>
        <tbody>
          {unified.dataTypes.map((entry) => (
            <tr key={entry.type}>
              <th scope="row" className="text-nowrap fw-normal">{entry.label}</th>
              {columns.map((column) => (
                <td key={column} className={entry.columns[column] === 'required' ? 'fw-semibold' : 'text-secondary'}>
                  {MATRIX_LABEL[entry.columns[column]]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function SummaryCard({ label, value, variant, testId }) {
  return (
    <div className="col-6 col-md">
      <div className={`card h-100 border-${variant}-subtle`} data-testid={testId}>
        <div className="card-body py-2">
          <div className="small text-secondary">{label}</div>
          <div className={`fs-4 fw-semibold text-${variant}-emphasis`}>{value}</div>
        </div>
      </div>
    </div>
  )
}

/**
 * Import Data: one Excel file holds every Sales Office list.
 * 1. Upload the file (nothing is saved). 2. Review the server's preview.
 * 3. Confirm: a backup is taken, the valid rows are saved, and the import
 * can be rolled back once from Import History.
 */
export default function ImportDataPage() {
  const { actions } = useErpData()
  const [config, setConfig] = useState(null)
  const [loadError, setLoadError] = useState(null)
  const [file, setFile] = useState(null)
  const [fileError, setFileError] = useState(null)
  const [dragging, setDragging] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [preview, setPreview] = useState(null)
  const [confirming, setConfirming] = useState(false)
  const [done, setDone] = useState(null)
  const [typeFilter, setTypeFilter] = useState('')
  const [resultFilter, setResultFilter] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const fileInput = useRef(null)

  useEffect(() => {
    let cancelled = false
    api('/api/imports/types')
      .then((data) => !cancelled && setConfig({ ...data.unified, maxBytes: data.maxBytes }))
      .catch((error) => !cancelled && setLoadError(error.message))
    return () => {
      cancelled = true
    }
  }, [])

  const rows = useMemo(() => preview?.preview ?? [], [preview])
  const summary = useMemo(() => previewSummary(rows), [rows])
  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase()
    return rows.filter((row) => (!typeFilter || (row.type ?? 'unknown') === typeFilter)
      && (!resultFilter || row.action === resultFilter)
      && (!needle || [row.code, row.name, row.typeLabel, ...(row.messages ?? [])].some((value) => String(value ?? '').toLowerCase().includes(needle))))
  }, [rows, typeFilter, resultFilter, query])
  const pages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))
  const currentPage = Math.min(page, pages)
  const pageRows = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  const reset = () => {
    setFile(null)
    setFileError(null)
    setPreview(null)
    setDone(null)
    setTypeFilter('')
    setResultFilter('')
    setQuery('')
    setPage(1)
    if (fileInput.current) fileInput.current.value = ''
  }

  const chooseFile = (chosen) => {
    setFile(chosen ?? null)
    setFileError(chosen ? checkImportFile(chosen, config.maxBytes) : null)
  }

  const upload = async (event) => {
    event.preventDefault()
    const problem = checkImportFile(file, config.maxBytes)
    setFileError(problem)
    if (problem || uploading) return
    setUploading(true)
    try {
      const form = new FormData()
      form.append('type', config.type)
      form.append('file', file)
      setPreview((await apiUpload('/api/imports', form)).import)
      setPage(1)
    } catch (error) {
      setFileError(error.fields?.file ?? error.fields?.type ?? error.message)
    } finally {
      setUploading(false)
    }
  }

  const confirmImport = async () => {
    const result = await api(`/api/imports/${preview.id}/confirm`, { method: 'POST', body: {} })
    setDone(result.import)
    setPreview(null)
    actions.reload()
  }

  if (loadError) {
    return (
      <PageShell>
        <div className="alert alert-danger" role="alert">{loadError}</div>
      </PageShell>
    )
  }
  if (!config) {
    return (
      <PageShell>
        <LoadingIndicator label="Loading import settings" />
      </PageShell>
    )
  }

  const saving = preview ? preview.created + preview.updated : 0
  const step = done ? 3 : preview ? 2 : 1
  const historyLink = (
    <Link to="/imports/history" className="btn btn-outline-secondary">
      <i className="bi bi-clock-history" aria-hidden="true" /> Import History
    </Link>
  )

  return (
    <PageShell actions={historyLink}>
      <Stepper current={step} />

      {step === 1 && (
        <div className="row g-4">
          <div className="col-lg-7">
            <section className="card h-100">
              <div className="card-body">
                <h2 className="h6">Upload your Excel file</h2>
                <p className="text-secondary small">
                  One .xlsx file with every Sales Office list on a sheet named &quot;Import&quot;. Rows can be in any order.
                  Up to {config.maxRows} rows and {formatBytes(config.maxBytes)}. Nothing is saved until you confirm.
                </p>
                <form onSubmit={upload} noValidate>
                  <label
                    htmlFor="import-file"
                    className={`d-block text-center rounded-3 p-4 mb-2 ${dragging ? 'bg-primary-subtle border-primary' : 'bg-body-tertiary'} ${fileError ? 'border-danger' : ''}`}
                    style={{ border: '2px dashed var(--bs-border-color)', cursor: 'pointer' }}
                    data-testid="import-dropzone"
                    onDragOver={(event) => {
                      event.preventDefault()
                      setDragging(true)
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={(event) => {
                      event.preventDefault()
                      setDragging(false)
                      chooseFile(event.dataTransfer.files?.[0])
                    }}
                  >
                    <i className="bi bi-file-earmark-spreadsheet fs-2 text-primary" aria-hidden="true" />
                    <span className="d-block fw-semibold mt-2">{file ? file.name : 'Drag and drop the file here, or click to choose'}</span>
                    <span className="d-block small text-secondary">{file ? formatBytes(file.size) : 'Excel workbook (.xlsx) only'}</span>
                    <span className="visually-hidden">Excel file</span>
                  </label>
                  <input
                    ref={fileInput}
                    id="import-file"
                    type="file"
                    accept={XLSX_TYPES}
                    className="visually-hidden"
                    aria-invalid={fileError ? true : undefined}
                    aria-describedby={fileError ? 'import-file-error' : undefined}
                    onChange={(event) => chooseFile(event.target.files?.[0])}
                  />
                  {fileError && <div id="import-file-error" className="text-danger small mb-2" role="alert">{fileError}</div>}
                  <button type="submit" className="btn btn-primary" aria-busy={uploading || undefined} aria-disabled={uploading || undefined}>
                    {uploading && <span className="spinner-border spinner-border-sm" aria-hidden="true" />} {uploading ? 'Checking...' : 'Upload and preview'}
                  </button>
                </form>
              </div>
            </section>
          </div>
          <div className="col-lg-5">
            <section className="card h-100">
              <div className="card-body">
                <h2 className="h6">Template</h2>
                <p className="text-secondary small">
                  Start from the template: its cells are formatted as text so codes, phones and pincodes keep their leading zeros.
                  Do not use formulas.
                </p>
                <button type="button" className="btn btn-outline-primary" onClick={() => apiDownload('/api/imports/template', 'yorilife-import-template.xlsx')}>
                  <i className="bi bi-download" aria-hidden="true" /> Download template
                </button>
                <p className="text-secondary small mt-3 mb-0">
                  Parent Code links a row to its parent: the Location for a Location HQ, the manager one level up, the 1st Line Manager
                  for a Headquarters and the Headquarters for a Stockist. Notes is optional for every type.
                </p>
              </div>
            </section>
          </div>
          <div className="col-12">
            <section className="card">
              <div className="card-body">
                <h2 className="h6">Column reference</h2>
                <ColumnReference unified={config} />
              </div>
            </section>
          </div>
        </div>
      )}

      {step === 2 && (
        <>
          <div className="row g-3 mb-3" data-testid="import-summary">
            <SummaryCard label="Total rows" value={preview.totalRows} variant="secondary" testId="summary-total" />
            <SummaryCard label="To create" value={preview.created} variant="success" testId="summary-created" />
            <SummaryCard label="To update" value={preview.updated} variant="info" testId="summary-updated" />
            <SummaryCard label="Failed" value={preview.errorRows} variant="danger" testId="summary-failed" />
            <SummaryCard label="Warnings" value={summary.warnings} variant="warning" testId="summary-warnings" />
          </div>
          {saving === 0 && (
            <div className="alert alert-warning" role="status">
              There is nothing to save: every row is unchanged or has an error. Fix the file and upload it again.
            </div>
          )}
          <TableCard
            title={`Preview: ${preview.filename}`}
            toolbar={(
              <>
                <SearchInput value={query} onChange={(value) => { setQuery(value); setPage(1) }} label="Search preview" placeholder="Search code, name or error" />
                <label htmlFor="import-type-filter" className="visually-hidden">Data Type</label>
                <select id="import-type-filter" className="form-select form-select-sm w-auto" value={typeFilter} onChange={(event) => { setTypeFilter(event.target.value); setPage(1) }}>
                  <option value="">All data types</option>
                  {summary.types.map((group) => <option key={group.key} value={group.key}>{group.label} ({group.total})</option>)}
                </select>
                <label htmlFor="import-result-filter" className="visually-hidden">Result</label>
                <select id="import-result-filter" className="form-select form-select-sm w-auto" value={resultFilter} onChange={(event) => { setResultFilter(event.target.value); setPage(1) }}>
                  <option value="">All results</option>
                  {Object.entries(ROW_ACTION).map(([key, value]) => <option key={key} value={key}>{value.label}</option>)}
                </select>
                {preview.errorRows > 0 && (
                  <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => apiDownload(`/api/imports/${preview.id}/errors`, 'import-errors.csv')}>
                    <i className="bi bi-exclamation-triangle" aria-hidden="true" /> Download errors
                  </button>
                )}
              </>
            )}
          >
            <DataTable caption="Import preview" columns={PREVIEW_COLUMNS} rows={pageRows} rowKey={(row) => row.line} emptyText="No rows match these filters." />
            <div className="d-flex flex-wrap align-items-center gap-2 p-3 border-top">
              <span className="small text-secondary me-auto" data-testid="preview-range">
                {filtered.length === 0 ? 'No rows' : `Rows ${(currentPage - 1) * PAGE_SIZE + 1}-${Math.min(currentPage * PAGE_SIZE, filtered.length)} of ${filtered.length}`}
              </span>
              <button type="button" className="btn btn-sm btn-outline-secondary" disabled={currentPage <= 1} onClick={() => setPage(currentPage - 1)}>Previous</button>
              <span className="small">Page {currentPage} of {pages}</span>
              <button type="button" className="btn btn-sm btn-outline-secondary" disabled={currentPage >= pages} onClick={() => setPage(currentPage + 1)}>Next</button>
            </div>
          </TableCard>
          <div className="d-flex flex-wrap justify-content-end gap-2 mt-3">
            <button type="button" className="btn btn-outline-secondary" onClick={reset}>Cancel</button>
            <button type="button" className="btn btn-primary" disabled={saving === 0} onClick={() => setConfirming(true)}>
              <i className="bi bi-check2-circle" aria-hidden="true" /> Confirm import
            </button>
          </div>
        </>
      )}

      {step === 3 && (
        <section className="card border-success-subtle">
          <div className="card-body text-center py-5" role="status">
            <i className="bi bi-check-circle-fill text-success fs-1" aria-hidden="true" />
            <h2 className="h5 mt-2">Import complete</h2>
            <p className="mb-1">
              {done.created} created, {done.updated} updated, {done.unchanged} unchanged{done.errorRows ? `, ${done.errorRows} rows skipped with errors` : ''}.
            </p>
            <p className="text-secondary small">A backup was taken first. You can roll this import back once from Import History.</p>
            <div className="d-flex justify-content-center gap-2">
              <Link to="/imports/history" className="btn btn-primary">View in Import History</Link>
              <button type="button" className="btn btn-outline-secondary" onClick={reset}>Import another file</button>
            </div>
          </div>
        </section>
      )}

      <ConfirmDialog
        open={confirming}
        onClose={() => setConfirming(false)}
        onConfirm={confirmImport}
        title="Confirm import"
        confirmLabel="Import"
        variant="primary"
      >
        {preview && (
          <p className="mb-0">
            Save {preview.created} new and {preview.updated} updated records across {summary.savingTypes} data types?
            {preview.errorRows > 0 && ` ${preview.errorRows} rows with errors will be skipped.`} A backup of the Sales Office data is taken first,
            and the import can be rolled back once from Import History.
          </p>
        )}
      </ConfirmDialog>
    </PageShell>
  )
}