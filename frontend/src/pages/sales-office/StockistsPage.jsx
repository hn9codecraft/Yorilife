import { useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import PreviewNotice from '../../components/PreviewNotice.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import FormModal from '../../components/FormModal.jsx'
import Modal from '../../components/Modal.jsx'
import HierarchyTree from '../../components/HierarchyTree.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { buildHeadquartersTree, byName, findById, matchesSearch } from '../../data/selectors.js'

function StockistForm({ formId, record, headquarters, onSave }) {
  const form = useFormState(
    { name: record?.name ?? '', code: record?.code ?? '', headquartersId: record?.headquartersId ?? '' },
    (v) => {
      const errors = {}
      if (!v.name.trim()) errors.name = 'Enter the stockist name.'
      if (!v.headquartersId) errors.headquartersId = 'Select the headquarters this stockist is mapped to.'
      return errors
    },
  )

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((v) => onSave({ name: v.name.trim(), code: v.code.trim(), headquartersId: v.headquartersId }))}
    >
      <TextField id={form.fieldId('name')} label="Stockist name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} />
      <TextField id={form.fieldId('code')} label="Stockist code" value={form.values.code} onChange={form.set('code')} help="Optional." />
      <SelectField
        id={form.fieldId('headquartersId')}
        label="Headquarters"
        required
        value={form.values.headquartersId}
        onChange={form.set('headquartersId')}
        options={headquarters.map((hq) => ({ value: hq.id, label: hq.name }))}
        placeholder="Select headquarters"
        error={form.errors.headquartersId}
        help="Stockists map directly to a headquarters. Its managers are shown in the stockist’s hierarchy view."
      />
    </form>
  )
}

export default function StockistsPage() {
  const { state, actions } = useErpData()
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [headquartersFilter, setHeadquartersFilter] = useState('')
  const [viewing, setViewing] = useState({ open: false, id: null })

  const headquarters = [...state.headquarters].sort(byName)
  const rows = state.stockists
    .filter((s) => matchesSearch(s, query) && (!headquartersFilter || s.headquartersId === headquartersFilter))
    .sort(byName)
  const viewed = findById(state.stockists, viewing.id)

  const columns = [
    { key: 'name', header: 'Stockist', render: (s) => <strong className="fw-semibold">{s.name}</strong> },
    { key: 'code', header: 'Code', nowrap: true },
    { key: 'hq', header: 'Headquarters', render: (s) => findById(state.headquarters, s.headquartersId)?.name ?? '—' },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (s) => (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setViewing({ open: true, id: s.id })}>
            <i className="bi bi-diagram-3" aria-hidden="true" /> Hierarchy<span className="visually-hidden"> for {s.name}</span>
          </button>
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(s)}>
            <i className="bi bi-pencil" aria-hidden="true" /> Edit<span className="visually-hidden"> {s.name}</span>
          </button>
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={
        <button type="button" className="btn btn-primary" onClick={editor.openNew} disabled={headquarters.length === 0}>
          <i className="bi bi-plus-lg" aria-hidden="true" /> Add stockist
        </button>
      }
    >
      <PreviewNotice />

      {headquarters.length === 0 && (
        <div className="alert alert-info" role="note">
          <i className="bi bi-info-circle-fill" aria-hidden="true" />
          <div>
            Every stockist is mapped to a headquarters. <Link to="/sales-office/headquarters">Add a headquarters</Link> first.
          </div>
        </div>
      )}

      <TableCard
        title={`Stockists (${state.stockists.length})`}
        toolbar={
          <>
            <SearchInput value={query} onChange={setQuery} label="Search stockists" placeholder="Search name or code" />
            <label className="visually-hidden" htmlFor="stockist-hq-filter">Filter by headquarters</label>
            <select id="stockist-hq-filter" className="form-select toolbar__select" value={headquartersFilter} onChange={(e) => setHeadquartersFilter(e.target.value)}>
              <option value="">All headquarters</option>
              {headquarters.map((hq) => <option key={hq.id} value={hq.id}>{hq.name}</option>)}
            </select>
          </>
        }
      >
        <DataTable
          caption="Stockists"
          columns={columns}
          rows={rows}
          emptyText={state.stockists.length ? 'No stockists match these filters.' : 'No stockists yet.'}
          empty={state.stockists.length === 0 && (
            <div className="card-body">
              <EmptyState icon="bi-shop" title="No stockists yet" text="Add a stockist and map it to a headquarters." />
            </div>
          )}
        />
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={editor.record ? 'Edit stockist' : 'Add stockist'}
        renderForm={(formId) => (
          <StockistForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            headquarters={headquarters}
            onSave={(values) => {
              actions.saveRecord('stockists', { ...editor.record, ...values })
              editor.close()
            }}
          />
        )}
      />

      <Modal
        open={viewing.open}
        onClose={() => setViewing((v) => ({ ...v, open: false }))}
        title={viewed ? `${viewed.name} — hierarchy` : 'Stockist hierarchy'}
        size="lg"
      >
        {viewed && (
          <>
            <p className="text-muted">
              Stockists are mapped to a headquarters. The managers below are everyone linked under that headquarters.
            </p>
            <HierarchyTree tree={buildHeadquartersTree(state, viewed.headquartersId)} highlightStockistId={viewed.id} />
          </>
        )}
      </Modal>
    </PageShell>
  )
}
