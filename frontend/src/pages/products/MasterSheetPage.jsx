import { useId, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import PreviewNotice from '../../components/PreviewNotice.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import SegmentedControl from '../../components/SegmentedControl.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import ChangeValue from '../../components/ChangeValue.jsx'
import FormModal from '../../components/FormModal.jsx'
import Modal from '../../components/Modal.jsx'
import { TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { CHANGED_BY_UNAVAILABLE, INCLUSION_STATUS, MASTER_SHEET_YEARS } from '../../data/constants.js'
import { byName, formatDateTime, matchesSearch } from '../../data/selectors.js'

const inclusionLabel = (value) => INCLUSION_STATUS[value ?? 'unset'].label

function EntryForm({ formId, year, product, entry, onSave }) {
  const form = useFormState(
    { inclusion: entry?.inclusion ?? '', scheme: entry?.scheme ?? '' },
    (v) => (v.inclusion ? {} : { inclusion: 'Choose Included or Excluded.' }),
  )
  const errorId = form.fieldId('inclusion-error')

  return (
    <form id={formId} className="row g-3" noValidate onSubmit={form.handleSubmit(onSave)}>
      <div className="col-12">
        <p className="mb-1"><strong>{product.name}</strong>{product.code && <span className="text-muted"> · {product.code}</span>}</p>
        <p className="text-muted mb-0">Master Product Sheet {year}. Changes apply to this year only.</p>
      </div>
      <div className="col-12">
        <fieldset aria-describedby={form.errors.inclusion ? errorId : undefined}>
          <legend className="form-label">
            Status for {year}<span className="required" aria-hidden="true">*</span>
            <span className="visually-hidden"> (required)</span>
          </legend>
          <div className="choice-group choice-group--inline">
            {['included', 'excluded'].map((value, index) => (
              <div className="form-check" key={value}>
                <input
                  className={`form-check-input${form.errors.inclusion ? ' is-invalid' : ''}`}
                  type="radio"
                  name={form.fieldId('inclusion')}
                  id={index === 0 ? form.fieldId('inclusion') : form.fieldId(`inclusion-${value}`)}
                  checked={form.values.inclusion === value}
                  onChange={() => form.set('inclusion')(value)}
                />
                <label className="form-check-label" htmlFor={index === 0 ? form.fieldId('inclusion') : form.fieldId(`inclusion-${value}`)}>
                  {INCLUSION_STATUS[value].label}
                </label>
              </div>
            ))}
          </div>
          {form.errors.inclusion && <div id={errorId} className="invalid-feedback d-block">{form.errors.inclusion}</div>}
        </fieldset>
      </div>
      <TextField
        id={form.fieldId('scheme')}
        label="Scheme"
        value={form.values.scheme}
        onChange={form.set('scheme')}
        placeholder="e.g. 10+5"
        help="Recorded exactly as entered. No scheme calculation is applied."
      />
    </form>
  )
}

// Body of the history modal. Re-keyed per opening so "all years" resets.
function HistoryContent({ product, year, history }) {
  const [allYears, setAllYears] = useState(false)
  const toggleId = useId()
  const entries = history
    .filter((h) => h.productId === product?.id && (allYears || h.year === year))
    .sort((a, b) => b.at.localeCompare(a.at))

  const columns = [
    { key: 'at', header: 'Date & time', nowrap: true, render: (h) => formatDateTime(h.at) },
    { key: 'year', header: 'Year', nowrap: true },
    { key: 'field', header: 'Field' },
    {
      key: 'change',
      header: 'Previous → updated',
      // A missing previous inclusion means the product was "Not set" for that year.
      render: (h) =>
        h.field === 'Inclusion'
          ? <ChangeValue from={h.from ?? 'unset'} to={h.to} format={inclusionLabel} />
          : <ChangeValue from={h.from} to={h.to} />,
    },
    { key: 'by', header: 'Changed by', render: (h) => h.by ?? <span className="text-muted">{CHANGED_BY_UNAVAILABLE}</span> },
  ]

  return (
    <>
      <div className="form-check form-switch mb-3">
        <input className="form-check-input" type="checkbox" role="switch" id={toggleId} checked={allYears} onChange={(e) => setAllYears(e.target.checked)} />
        <label className="form-check-label" htmlFor={toggleId}>Show all years (otherwise {year} only)</label>
      </div>
      <DataTable
        caption="Master Product Sheet change history"
        columns={columns}
        rows={entries}
        emptyText={allYears ? 'No Master Sheet changes for this product yet.' : `No changes for this product in ${year} yet.`}
      />
    </>
  )
}

export default function MasterSheetPage() {
  const { state, actions } = useErpData()
  const editor = useEditor()
  const [year, setYear] = useState(MASTER_SHEET_YEARS.at(-1))
  const [statusFilter, setStatusFilter] = useState('all')
  const [query, setQuery] = useState('')
  const [historyOf, setHistoryOf] = useState({ open: false, product: null, session: 0 })
  const yearSelectId = useId()

  const yearEntries = state.masterSheet[year] ?? {}
  const statusOf = (product) => yearEntries[product.id]?.inclusion ?? 'unset'

  // Active products, plus deleted products that are part of this year's sheet
  // (kept so historical sheets stay complete).
  const products = state.products.filter((p) => !p.deletedAt || yearEntries[p.id]).sort(byName)
  const counts = products.reduce((acc, p) => ({ ...acc, [statusOf(p)]: (acc[statusOf(p)] ?? 0) + 1 }), {})
  const rows = products.filter((p) => matchesSearch(p, query) && (statusFilter === 'all' || statusOf(p) === statusFilter))

  const columns = [
    {
      key: 'name',
      header: 'Product',
      render: (p) => (
        <>
          <strong className="fw-semibold">{p.name}</strong>
          {p.deletedAt && <> <StatusBadge variant="danger">Deleted product</StatusBadge></>}
        </>
      ),
    },
    { key: 'code', header: 'Code', nowrap: true },
    {
      key: 'status',
      header: `Status ${year}`,
      nowrap: true,
      render: (p) => <StatusBadge variant={INCLUSION_STATUS[statusOf(p)].variant}>{INCLUSION_STATUS[statusOf(p)].label}</StatusBadge>,
    },
    { key: 'scheme', header: 'Scheme', nowrap: true, render: (p) => yearEntries[p.id]?.scheme || <span className="text-muted">—</span> },
    { key: 'updatedAt', header: 'Last changed', nowrap: true, render: (p) => formatDateTime(yearEntries[p.id]?.updatedAt) },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (p) => (
        <div className="table-actions">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary btn-icon"
            aria-label={`Change history for ${p.name}`}
            title="Change history"
            onClick={() => setHistoryOf((h) => ({ open: true, product: p, session: h.session + 1 }))}
          >
            <i className="bi bi-info-circle" aria-hidden="true" />
          </button>
          {!p.deletedAt && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(p)}>
              <i className="bi bi-pencil" aria-hidden="true" /> Update<span className="visually-hidden"> {p.name} for {year}</span>
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <PageShell>
      <PreviewNotice />

      <div className="card mb-4">
        <div className="card-body d-flex flex-wrap align-items-end gap-3">
          <div className="toolbar__field">
            <label htmlFor={yearSelectId} className="form-label">Master Sheet year</label>
            <select id={yearSelectId} className="form-select" value={year} onChange={(e) => setYear(e.target.value)}>
              {MASTER_SHEET_YEARS.map((y) => <option key={y} value={y}>{y}</option>)}
            </select>
          </div>
          <p className="text-muted mb-0 flex-grow-1 min-w-0">
            Each year keeps its own Included / Excluded selections and scheme values. Changing one year never
            changes another, and every change is kept in the history (<i className="bi bi-info-circle" aria-hidden="true" /><span className="visually-hidden">info</span> button).
          </p>
        </div>
      </div>

      <TableCard
        title={`Master Product Sheet ${year}`}
        toolbar={
          <>
            <SegmentedControl
              label="Filter by status"
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
          caption={`Master Product Sheet ${year}`}
          columns={columns}
          rows={rows}
          emptyText={
            products.length === 0 ? (
              <>No products yet. Add products in the <Link to="/products/list">Product List</Link> first.</>
            ) : 'No products match these filters.'
          }
        />
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={`Update Master Sheet ${year}`}
        renderForm={(formId) =>
          editor.record && (
            <EntryForm
              key={editor.session}
              formId={formId}
              year={year}
              product={editor.record}
              entry={yearEntries[editor.record.id]}
              onSave={(values) => {
                actions.setMasterSheetEntry(year, editor.record.id, values)
                editor.close()
              }}
            />
          )
        }
      />

      <Modal
        open={historyOf.open}
        onClose={() => setHistoryOf((h) => ({ ...h, open: false }))}
        title={historyOf.product ? `${historyOf.product.name} — Master Sheet history` : 'Master Sheet history'}
        size="xl"
      >
        {historyOf.product && (
          <HistoryContent key={historyOf.session} product={historyOf.product} year={year} history={state.masterSheetHistory} />
        )}
      </Modal>
    </PageShell>
  )
}
