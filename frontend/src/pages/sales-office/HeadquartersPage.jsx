import { useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import PreviewNotice from '../../components/PreviewNotice.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import FormModal from '../../components/FormModal.jsx'
import Modal from '../../components/Modal.jsx'
import HierarchyTree from '../../components/HierarchyTree.jsx'
import { TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { buildHeadquartersTree, byName, matchesSearch } from '../../data/selectors.js'

function HeadquartersForm({ formId, record, onSave }) {
  const form = useFormState(
    { name: record?.name ?? '', code: record?.code ?? '' },
    (v) => (v.name.trim() ? {} : { name: 'Enter the headquarters name.' }),
  )

  return (
    <form id={formId} className="row g-3" noValidate onSubmit={form.handleSubmit((v) => onSave({ name: v.name.trim(), code: v.code.trim() }))}>
      <TextField id={form.fieldId('name')} label="Headquarters name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} />
      <TextField id={form.fieldId('code')} label="Code" value={form.values.code} onChange={form.set('code')} help="Optional reference code." />
    </form>
  )
}

export default function HeadquartersPage() {
  const { state, actions } = useErpData()
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [viewing, setViewing] = useState({ open: false, id: null })

  const rows = state.headquarters.filter((hq) => matchesSearch(hq, query)).sort(byName)
  const countManagers = (hqId) => state.managers.filter((m) => m.parentType === 'headquarters' && m.parentId === hqId).length
  const countStockists = (hqId) => state.stockists.filter((s) => s.headquartersId === hqId).length
  const viewed = state.headquarters.find((hq) => hq.id === viewing.id)

  const columns = [
    { key: 'name', header: 'Headquarters', render: (hq) => <strong className="fw-semibold">{hq.name}</strong> },
    { key: 'code', header: 'Code', nowrap: true },
    { key: 'managers', header: 'Directly linked managers', align: 'end', render: (hq) => countManagers(hq.id) },
    { key: 'stockists', header: 'Stockists', align: 'end', render: (hq) => countStockists(hq.id) },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (hq) => (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setViewing({ open: true, id: hq.id })}>
            <i className="bi bi-diagram-3" aria-hidden="true" /> View<span className="visually-hidden"> {hq.name} hierarchy</span>
          </button>
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(hq)}>
            <i className="bi bi-pencil" aria-hidden="true" /> Edit<span className="visually-hidden"> {hq.name}</span>
          </button>
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={
        <button type="button" className="btn btn-primary" onClick={editor.openNew}>
          <i className="bi bi-plus-lg" aria-hidden="true" /> Add headquarters
        </button>
      }
    >
      <PreviewNotice />

      <TableCard
        title={`Headquarters (${state.headquarters.length})`}
        toolbar={<SearchInput value={query} onChange={setQuery} label="Search headquarters" placeholder="Search name or code" />}
      >
        <DataTable
          caption="Headquarters"
          columns={columns}
          rows={rows}
          emptyText={state.headquarters.length ? 'No headquarters match your search.' : 'No headquarters yet.'}
          empty={state.headquarters.length === 0 && (
            <div className="card-body">
              <EmptyState
                icon="bi-building"
                title="No headquarters yet"
                text="Headquarters are the top of the sales hierarchy. Managers and stockists are linked to them."
                action={<button type="button" className="btn btn-primary btn-sm" onClick={editor.openNew}>Add headquarters</button>}
              />
            </div>
          )}
        />
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={editor.record ? 'Edit headquarters' : 'Add headquarters'}
        renderForm={(formId) => (
          <HeadquartersForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            onSave={(values) => {
              actions.saveRecord('headquarters', { ...editor.record, ...values })
              editor.close()
            }}
          />
        )}
      />

      <Modal
        open={viewing.open}
        onClose={() => setViewing((v) => ({ ...v, open: false }))}
        title={viewed ? `${viewed.name} — hierarchy` : 'Headquarters'}
        size="lg"
      >
        {viewed && <HierarchyTree tree={buildHeadquartersTree(state, viewed.id)} />}
      </Modal>
    </PageShell>
  )
}
