import { useId, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import SegmentedControl from '../../components/SegmentedControl.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import FormModal from '../../components/FormModal.jsx'
import Modal from '../../components/Modal.jsx'
import { TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { CHANGED_BY_UNAVAILABLE, INCLUSION_STATUS, MASTER_SHEET_YEARS } from '../../data/constants.js'
import { byName, findById, formatDateTime, matchesSearch } from '../../data/selectors.js'

const inclusionLabel = (value) => INCLUSION_STATUS[value ?? 'unset'].label
const moneyPattern = /^\d+(\.\d{1,2})?$/

function classificationOf(product, terms) {
  const category = findById(terms, product.categoryId)
  const group = category?.parentId ? findById(terms, category.parentId) : null
  return { category, group }
}

function moneyOrDash(value) {
  return value || <span className="text-muted">—</span>
}

function EntryForm({ formId, year, product, entry, onSave }) {
  const form = useFormState(
    {
      inclusion: entry?.inclusion ?? '',
      stripPack: entry?.stripPack ?? '',
      mrp: entry?.mrp ?? '',
      ptr: entry?.ptr ?? '',
      pts: entry?.pts ?? '',
      scheme: product.scheme ?? '',
    },
    (values) => {
      const errors = {}
      for (const [field, label] of [['mrp', 'MRP'], ['ptr', 'PTR'], ['pts', 'PTS']]) {
        const value = String(values[field] ?? '').trim()
        if (value && !moneyPattern.test(value)) errors[field] = `${label} must be a number with at most two decimal places.`
      }
      return errors
    },
  )

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({
        ...values,
        stripPack: values.stripPack.trim(),
        scheme: values.scheme.trim(),
        mrp: String(values.mrp).trim(),
        ptr: String(values.ptr).trim(),
        pts: String(values.pts).trim(),
      }))}
    >
      <div className="col-12">
        <p className="mb-1"><strong>{product.name}</strong>{product.code && <span className="text-muted"> · {product.code}</span>}</p>
        <p className="text-muted mb-0">
          Strip pack, MRP, PTR, PTS and Extra Bonus / Incentive eligibility apply to {year} only.
          The scheme is the product’s current scheme.
        </p>
      </div>
      <TextField id={form.fieldId('stripPack')} label="Strip pack" value={form.values.stripPack} onChange={form.set('stripPack')} placeholder="e.g. 1x10" className="col-12 col-md-6" />
      <TextField id={form.fieldId('mrp')} label="MRP" value={form.values.mrp} onChange={form.set('mrp')} error={form.errors.mrp} className="col-12 col-md-6" />
      <TextField id={form.fieldId('ptr')} label="PTR" value={form.values.ptr} onChange={form.set('ptr')} error={form.errors.ptr} className="col-12 col-md-6" />
      <TextField id={form.fieldId('pts')} label="PTS" value={form.values.pts} onChange={form.set('pts')} error={form.errors.pts} className="col-12 col-md-6" />
      <TextField
        id={form.fieldId('scheme')}
        label="Scheme"
        value={form.values.scheme}
        onChange={form.set('scheme')}
        placeholder="e.g. 10+5"
        help="Stored on the product. Changing it does not create a separate scheme for this year."
      />
      <div className="col-12">
        <fieldset>
          <legend className="form-label">Included / Excluded for {year}</legend>
          <p className="form-text mt-0">Whether this product is eligible for the {year} Extra Bonus / Incentive Scheme. This is not the product’s active or inactive status.</p>
          <div className="choice-group choice-group--inline">
            {['included', 'excluded'].map((value) => (
              <div className="form-check" key={value}>
                <input
                  className="form-check-input"
                  type="radio"
                  name={form.fieldId('inclusion')}
                  id={form.fieldId(`inclusion-${value}`)}
                  checked={form.values.inclusion === value}
                  onChange={() => form.set('inclusion')(value)}
                />
                <label className="form-check-label" htmlFor={form.fieldId(`inclusion-${value}`)}>
                  {INCLUSION_STATUS[value].label}
                </label>
              </div>
            ))}
          </div>
          {!form.values.inclusion && <p className="form-text mb-0">Not set. Choose Included or Excluded to record eligibility for this year.</p>}
        </fieldset>
      </div>
    </form>
  )
}

function FinancialYearForm({ formId, onSave }) {
  const form = useFormState({ label: '' }, (values) => (/^\d{4}-\d{4}$/.test(values.label.trim()) ? {} : { label: 'Enter a year such as 2027-2028. The year runs from April to March.' }))
  return (
    <form id={formId} className="row g-3" noValidate onSubmit={form.handleSubmit((values) => onSave(values.label.trim()))}>
      <TextField
        id={form.fieldId('label')}
        label="Financial year"
        required
        value={form.values.label}
        onChange={form.set('label')}
        error={form.errors.label}
        placeholder="2027-2028"
        help="A new year starts empty. It does not copy another year’s sheet."
      />
    </form>
  )
}

function schemeRows(history, productId) {
  return history
    .filter((entry) => entry.productId === productId)
    .flatMap((entry) => entry.changes
      .filter((change) => change.field === 'Scheme')
      .map((change) => ({ id: `${entry.id}-${change.field}`, from: change.from, to: change.to, at: entry.at, by: entry.by })))
    .sort((a, b) => b.at.localeCompare(a.at))
}

function SchemeHistory({ product, history }) {
  const rows = schemeRows(history, product.id)
  return (
    <DataTable
      caption={`Scheme change history for ${product.name}`}
      columns={[
        { key: 'from', header: 'Previous scheme', render: (row) => row.from || <span className="text-muted">—</span> },
        { key: 'to', header: 'Updated scheme', render: (row) => row.to || <span className="text-muted">—</span> },
        { key: 'by', header: 'Changed by', render: (row) => row.by ?? <span className="text-muted">{CHANGED_BY_UNAVAILABLE}</span> },
        { key: 'at', header: 'Date & time', nowrap: true, render: (row) => formatDateTime(row.at) },
      ]}
      rows={rows}
      emptyText="No scheme changes for this product yet."
    />
  )
}

function resultText(result) {
  if (!result) return ''
  return `Added ${result.added}. Skipped ${result.skipped}. Failed ${result.failed}.`
}

export default function MasterSheetPage() {
  const { state, actions } = useErpData()
  const canUpdate = usePermission('master_sheet.update')
  const canAddYear = usePermission('financial_years.manage')
  const editor = useEditor()
  const yearEditor = useEditor()
  const years = state.financialYears?.length ? state.financialYears : MASTER_SHEET_YEARS
  const [year, setYear] = useState(MASTER_SHEET_YEARS.at(-1))
  const [statusFilter, setStatusFilter] = useState('all')
  const [query, setQuery] = useState('')
  const [historyOf, setHistoryOf] = useState({ open: false, product: null })
  const [summary, setSummary] = useState(null)
  const [busy, setBusy] = useState(false)
  const [scan, setScan] = useState({ open: false, loading: false, missing: [], selected: [], error: '' })
  const yearSelectId = useId()
  const selectedYear = years.includes(year) ? year : years.at(-1)

  const yearEntries = state.masterSheet[selectedYear] ?? {}
  const statusOf = (product) => yearEntries[product.id]?.inclusion ?? 'unset'
  const products = state.products
    .filter((product) => yearEntries[product.id])
    .sort(byName)
    .map((product, index) => ({ ...product, sr: index + 1 }))
  const counts = products.reduce((acc, product) => ({ ...acc, [statusOf(product)]: (acc[statusOf(product)] ?? 0) + 1 }), {})
  const rows = products.filter((product) => matchesSearch(product, query) && (statusFilter === 'all' || statusOf(product) === statusFilter))

  const openScan = async () => {
    setScan({ open: true, loading: true, missing: [], selected: [], error: '' })
    try {
      const data = await actions.scanMasterSheet(selectedYear)
      setScan({ open: true, loading: false, missing: data.missing ?? [], selected: [], error: '' })
    } catch (error) {
      setScan({ open: true, loading: false, missing: [], selected: [], error: error.message || 'The scan could not be completed.' })
    }
  }

  const confirmScan = async () => {
    setBusy(true)
    try {
      const result = await actions.addMasterSheetProducts(selectedYear, scan.selected)
      setSummary(result)
      setScan({ open: false, loading: false, missing: [], selected: [], error: '' })
    } catch (error) {
      setScan((current) => ({ ...current, error: error.message || 'The products could not be added.' }))
    } finally {
      setBusy(false)
    }
  }

  const updateProducts = async () => {
    setBusy(true)
    setSummary(null)
    try {
      setSummary(await actions.updateMasterSheetProducts(selectedYear))
    } catch (error) {
      setSummary({ message: error.message || 'Update Products could not be completed.' })
    } finally {
      setBusy(false)
    }
  }

  const columns = [
    { key: 'sr', header: 'Sr. No.', nowrap: true, render: (product) => product.sr },
    {
      key: 'name',
      header: 'Product name',
      render: (product) => (
        <>
          <strong className="fw-semibold">{product.name}</strong>
          {product.deletedAt && <> <StatusBadge variant="danger">Deleted product</StatusBadge></>}
        </>
      ),
    },
    { key: 'code', header: 'Product code', nowrap: true },
    { key: 'group', header: 'Group category', render: (product) => classificationOf(product, state.terms).group?.name || <span className="text-muted">—</span> },
    { key: 'category', header: 'Product category', render: (product) => classificationOf(product, state.terms).category?.name || <span className="text-muted">—</span> },
    { key: 'stripPack', header: 'Strip pack', nowrap: true, render: (product) => yearEntries[product.id]?.stripPack || <span className="text-muted">—</span> },
    { key: 'mrp', header: 'MRP', nowrap: true, render: (product) => moneyOrDash(yearEntries[product.id]?.mrp) },
    { key: 'ptr', header: 'PTR', nowrap: true, render: (product) => moneyOrDash(yearEntries[product.id]?.ptr) },
    { key: 'pts', header: 'PTS', nowrap: true, render: (product) => moneyOrDash(yearEntries[product.id]?.pts) },
    { key: 'scheme', header: 'Scheme', nowrap: true, render: (product) => product.scheme || <span className="text-muted">—</span> },
    {
      key: 'status',
      header: 'Included / Excluded',
      nowrap: true,
      render: (product) => <StatusBadge variant={INCLUSION_STATUS[statusOf(product)].variant}>{inclusionLabel(statusOf(product))}</StatusBadge>,
    },
    { key: 'updatedAt', header: 'Last updated', nowrap: true, render: (product) => formatDateTime(yearEntries[product.id]?.updatedAt) },
    {
      key: 'actions',
      header: 'Info',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (product) => (
        <div className="table-actions">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary btn-icon"
            aria-label={`Scheme history for ${product.name}`}
            title="Scheme history"
            onClick={() => setHistoryOf({ open: true, product })}
          >
            <i className="bi bi-info-circle" aria-hidden="true" />
          </button>
          {!product.deletedAt && canUpdate && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(product)}>
              <i className="bi bi-pencil" aria-hidden="true" /> Update<span className="visually-hidden"> {product.name} for {selectedYear}</span>
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={canUpdate && (
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={updateProducts} disabled={busy}>Update Products</button>
          <button type="button" className="btn btn-outline-secondary" onClick={openScan} disabled={busy}>Scan and Update</button>
        </>
      )}
    >
      <DataStatusNotice />

      <div className="card mb-4">
        <div className="card-body d-flex flex-wrap align-items-end gap-3">
          <div className="toolbar__field">
            <label htmlFor={yearSelectId} className="form-label">Financial year</label>
            <select id={yearSelectId} className="form-select" value={selectedYear} onChange={(event) => { setYear(event.target.value); setSummary(null) }}>
              {years.map((item) => <option key={item} value={item}>{item}</option>)}
            </select>
          </div>
          {canAddYear && (
            <button type="button" className="btn btn-outline-secondary" onClick={yearEditor.openNew}>
              <i className="bi bi-plus-lg" aria-hidden="true" /> Add financial year
            </button>
          )}
          <p className="text-muted mb-0 flex-grow-1 min-w-0">
            Included and Excluded record eligibility for this year’s Extra Bonus / Incentive Scheme.
            Strip pack, MRP, PTR and PTS belong to the selected year. The scheme belongs to the product.
          </p>
        </div>
      </div>

      {summary && (
        <div className={`alert ${summary.message ? 'alert-danger' : 'alert-success'}`} role="status">
          {summary.message || resultText(summary)}
        </div>
      )}

      <TableCard
        title={`Master Product Sheet ${selectedYear}`}
        toolbar={
          <>
            <SegmentedControl
              label="Filter by Extra Bonus / Incentive eligibility"
              value={statusFilter}
              onChange={setStatusFilter}
              options={[
                { value: 'all', label: 'All', count: products.length },
                { value: 'included', label: 'Included', count: counts.included ?? 0 },
                { value: 'excluded', label: 'Excluded', count: counts.excluded ?? 0 },
                { value: 'unset', label: 'Not set', count: counts.unset ?? 0 },
              ]}
            />
            <SearchInput value={query} onChange={setQuery} label="Search products" placeholder="Search name or code" />
          </>
        }
      >
        <DataTable
          caption={`Master Product Sheet ${selectedYear}`}
          columns={columns}
          rows={rows}
          emptyText={
            products.length === 0 ? (
              <>No products on this year’s sheet. Add products in the <Link to="/products/list">Product List</Link>, then use Update Products or Scan and Update.</>
            ) : 'No products match these filters.'
          }
        />
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={`Update Master Sheet ${selectedYear}`}
        size="lg"
        renderForm={(formId) =>
          editor.record && (
            <EntryForm
              key={editor.session}
              formId={formId}
              year={selectedYear}
              product={editor.record}
              entry={yearEntries[editor.record.id]}
              onSave={async (values) => {
                await actions.setMasterSheetEntry(selectedYear, editor.record.id, values)
                editor.close()
              }}
            />
          )
        }
      />

      <FormModal
        open={yearEditor.open}
        onClose={yearEditor.close}
        title="Add financial year"
        submitLabel="Add year"
        renderForm={(formId) => (
          <FinancialYearForm
            key={yearEditor.session}
            formId={formId}
            onSave={async (label) => {
              await actions.createFinancialYear(label)
              setYear(label.trim())
              yearEditor.close()
            }}
          />
        )}
      />

      <Modal
        open={historyOf.open}
        onClose={() => setHistoryOf({ open: false, product: null })}
        title={historyOf.product ? `${historyOf.product.name} — scheme history` : 'Scheme history'}
        size="lg"
      >
        {historyOf.product && <SchemeHistory product={historyOf.product} history={state.productHistory} />}
      </Modal>

      <Modal
        open={scan.open}
        onClose={() => setScan((current) => ({ ...current, open: false }))}
        title={`New products found — ${selectedYear}`}
        size="lg"
      >
        {scan.loading ? <p className="mb-0">Comparing the Product List with this year’s sheet.</p> : (
          <>
            {scan.error && <div className="alert alert-danger" role="alert">{scan.error}</div>}
            <p>Scanning does not add products. Choose the products to add to {selectedYear}, then confirm. Existing prices for this year are left as they are.</p>
            <DataTable
              caption="New products found"
              columns={[
                {
                  key: 'select',
                  header: 'Select',
                  render: (product) => (
                    <input
                      className="form-check-input"
                      type="checkbox"
                      aria-label={`Select ${product.name}`}
                      checked={scan.selected.includes(product.id)}
                      onChange={() => setScan((current) => ({
                        ...current,
                        selected: current.selected.includes(product.id)
                          ? current.selected.filter((id) => id !== product.id)
                          : [...current.selected, product.id],
                      }))}
                    />
                  ),
                },
                { key: 'name', header: 'Product name', render: (product) => <strong className="fw-semibold">{product.name}</strong> },
                { key: 'code', header: 'Product code' },
                { key: 'groupCategory', header: 'Group category' },
                { key: 'category', header: 'Product category' },
              ]}
              rows={scan.missing}
              emptyText="Every active product is already on this year’s sheet."
            />
            <div className="d-flex flex-wrap gap-2 mt-3">
              <button type="button" className="btn btn-primary" disabled={busy || scan.selected.length === 0} onClick={confirmScan}>
                Add new products to this year
              </button>
              <button type="button" className="btn btn-outline-secondary" disabled={busy} onClick={openScan}>Scan again</button>
            </div>
          </>
        )}
      </Modal>
    </PageShell>
  )
}
