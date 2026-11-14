import { useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SelectionBar from '../../components/SelectionBar.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import SalesOfficeCsv from '../../components/SalesOfficeCsv.jsx'
import { ChipList } from '../../components/TableCells.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import { LabelNote, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import useFormState from '../../hooks/useFormState.js'
import { matchesSearch } from '../../data/selectors.js'
import { joinCell } from '../../utils/csv.js'

function StateForm({ formId, record, locations, onSave }) {
  const form = useFormState(
    { name: record?.name ?? '', code: record?.code ?? '' },
    (values) => {
      const errors = {}
      const name = values.name.trim()
      const code = values.code.trim().toUpperCase()
      if (!name) errors.name = 'Enter the state name.'
      else if (locations.some((location) => location.id !== record?.id && (location.name ?? '').trim().toLowerCase() === name.toLowerCase())) {
        errors.name = 'This state already exists.'
      }
      if (code && locations.some((location) => location.id !== record?.id && location.code && location.code.trim().toUpperCase() === code)) {
        errors.code = 'This location code is already in use.'
      }
      return errors
    },
  )

  return (
    <form id={formId} className="row g-3" noValidate onSubmit={form.handleSubmit((values) => onSave({ name: values.name.trim(), code: values.code.trim().toUpperCase() }))}>
      <TextField id={form.fieldId('name')} label="State Name" required maxLength={100} value={form.values.name} onChange={form.set('name')} error={form.errors.name} className="col-12 col-md-6" />
      <TextField
        id={form.fieldId('code')}
        label={<LabelNote label="Location Code" note="stored in uppercase" />}
        value={form.values.code}
        onChange={form.set('code')}
        error={form.errors.code}
        maxLength={20}
        autoComplete="off"
        className="col-12 col-md-6"
      />
    </form>
  )
}

export default function LocationPage() {
  const { state, actions } = useErpData()
  const canManage = usePermission('sales_office.manage')
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [deleting, setDeleting] = useState(null)
  const [deletingMany, setDeletingMany] = useState(false)
  const locations = state.locations ?? []
  const rows = locations.filter((location) => matchesSearch(location, query))
  const selection = useRowSelection(rows)

  const columns = [
    { key: 'name', header: 'State Name', width: 'md', sticky: 'start', truncate: true, render: (location) => <strong className="fw-semibold">{location.name}</strong>, title: (location) => location.name },
    { key: 'code', header: 'Location Code', width: 'sm', nowrap: true, render: (location) => location.code },
    {
      key: 'headquarters',
      header: 'Headquarters',
      width: 'xl',
      render: (location) => (
        <ChipList label="Headquarters" max={3} items={(location.headquarters ?? []).map((office) => ({ key: office.id, label: office.name }))} />
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'end',
      sticky: 'end',
      render: (location) => (
        canManage ? (
          <div className="table-actions">
            <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${location.name}`} title="Edit" onClick={() => editor.openEdit(location)}>
              <i className="bi bi-pencil" aria-hidden="true" />
            </button>
            <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${location.name}`} title="Delete" onClick={() => setDeleting(location)}>
              <i className="bi bi-trash" aria-hidden="true" />
            </button>
          </div>
        ) : null
      ),
    },
  ]

  return (
    <PageShell
      actions={canManage && (
        <button type="button" className="btn btn-primary" onClick={editor.openNew}>
          <i className="bi bi-plus-lg" aria-hidden="true" /> Add State
        </button>
      )}
    >
      <DataStatusNotice />
      <TableCard
        title={`States (${locations.length})`}
        toolbar={(
          <>
            <SearchInput value={query} onChange={setQuery} label="Search states" placeholder="Search state name or code" />
            <SalesOfficeCsv
              module="state"
              records={rows.map((location) => ({
                'State name': location.name ?? '',
                'Location code': location.code ?? '',
                Headquarters: joinCell((location.headquarters ?? []).map((office) => office.name)),
              }))}
            />
          </>
        )}
      >
        {canManage && <SelectionBar count={selection.count} onDelete={() => setDeletingMany(true)} onClear={selection.clear} />}
        <DataTable
          caption="States"
          columns={columns}
          rows={rows}
          striped
          scroll
          selection={canManage ? {
            isSelected: (location) => selection.isSelected(location.id),
            onToggle: (location) => selection.toggle(location.id),
            onToggleAll: selection.toggleAll,
            allSelected: selection.allSelected,
            indeterminate: selection.indeterminate,
            label: (location) => `Select ${location.name}`,
          } : null}
          emptyText={locations.length ? 'No states match your search.' : 'No states yet.'}
          empty={state.status === 'ready' && locations.length === 0 && (
            <div className="card-body">
              <EmptyState icon="bi-geo-alt" title="No states yet" text="Add a state. The headquarter list and the employee list use these states." />
            </div>
          )}
        />
      </TableCard>

      <FormModal
        size="lg"
        variant="wide"
        open={editor.open}
        onClose={editor.close}
        title={editor.record ? 'Edit state' : 'Add State'}
        renderForm={(formId) => (
          <StateForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            locations={locations}
            onSave={async (values) => {
              await actions.saveRecord('locations', { ...editor.record, ...values })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title="Delete state"
        confirmLabel="Delete"
        onConfirm={() => actions.deleteLocation(deleting.id)}
      >
        Delete {deleting?.name}? A state that is assigned to a manager, a headquarters, or an employee cannot be deleted.
      </ConfirmDialog>

      <ConfirmDialog
        open={deletingMany}
        onClose={() => setDeletingMany(false)}
        title="Delete selected states"
        confirmLabel="Delete selected"
        onConfirm={async () => {
          await actions.deleteMany('locations', selection.selectedIds)
          selection.clear()
        }}
      >
        Delete {selection.count} states? A state that is assigned to a manager, a headquarters, or an employee cannot be deleted.
      </ConfirmDialog>
    </PageShell>
  )
}
