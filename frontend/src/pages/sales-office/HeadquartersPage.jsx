import { useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import ListFilter from '../../components/ListFilter.jsx'
import SalesOfficeCsv from '../../components/SalesOfficeCsv.jsx'
import FormModal from '../../components/FormModal.jsx'
import Modal from '../../components/Modal.jsx'
import HierarchyTree from '../../components/HierarchyTree.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { Link } from 'react-router'
import { buildHeadquartersBranch, byName, managersAtLevel, matchesFilters, matchesSearch } from '../../data/selectors.js'

function HeadquartersForm({ formId, record, parents, offices, onSave }) {
  const form = useFormState(
    {
      locationHeadquartersId: record?.locationHeadquartersId != null ? String(record.locationHeadquartersId) : '',
      parentManagerId: record?.parentManagerId != null ? String(record.parentManagerId) : '',
    },
    (values) => {
      const errors = {}
      if (!values.locationHeadquartersId) errors.locationHeadquartersId = 'Choose a headquarters from the location list.'
      if (!values.parentManagerId) errors.parentManagerId = 'Select the 1st line manager this headquarters belongs to.'
      return errors
    },
  )
  const selected = offices.find((office) => String(office.id) === String(form.values.locationHeadquartersId))
  const detail = (value) => value || ''
  const locked = {
    readOnly: true,
    onChange: () => {},
    placeholder: 'Select a location headquarters',
  }

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({
        locationHeadquartersId: values.locationHeadquartersId,
        parentManagerId: values.parentManagerId,
      }))}
    >
      <SelectField
        id={form.fieldId('locationHeadquartersId')}
        label="Location Headquarters"
        required
        value={form.values.locationHeadquartersId}
        onChange={form.set('locationHeadquartersId')}
        error={form.errors.locationHeadquartersId}
        placeholder={offices.length ? 'Select a location headquarters' : 'Add a headquarters under Location first'}
        options={offices.map((office) => ({
          value: String(office.id),
          label: office.code ? `${office.name} (${office.state} · ${office.code})` : `${office.name} (${office.state})`,
        }))}
        disabled={offices.length === 0}
      />
      <SelectField
        id={form.fieldId('parentManagerId')}
        label="1st Line Manager"
        required
        value={form.values.parentManagerId}
        onChange={form.set('parentManagerId')}
        error={form.errors.parentManagerId}
        placeholder={parents.length ? 'Select a 1st line manager' : 'No 1st line managers yet'}
        options={parents}
        disabled={parents.length === 0}
      />
      <TextField id={form.fieldId('name')} label="Headquarters name" value={detail(selected?.name)} {...locked} />
      <TextField id={form.fieldId('state')} label="State" value={detail(selected?.state)} {...locked} className="col-12 col-md-6" />
      <TextField id={form.fieldId('code')} label="Headquarters Code" value={detail(selected?.code)} {...locked} className="col-12 col-md-6" />
      <TextField id={form.fieldId('address')} label="Headquarters address" value={detail(selected?.address)} {...locked} />
      <TextField id={form.fieldId('pincode')} label="Pincode" value={detail(selected?.pincode)} {...locked} className="col-12 col-md-4" />
      <TextField id={form.fieldId('phone')} label="Phone number" value={detail(selected?.phone)} {...locked} className="col-12 col-md-8" />
      <TextField id={form.fieldId('email')} label="Email" value={detail(selected?.email)} {...locked} />
    </form>
  )
}

/** Headquarters offices. Location headquarters names are chosen on this form; they are not the table rows. */
export default function HeadquartersPage() {
  const { state, actions } = useErpData()
  const canManage = usePermission('sales_office.manage')
  const ready = state.status === 'ready'
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [filters, setFilters] = useState({ stateIds: [], reportIds: [] })
  const [viewing, setViewing] = useState({ open: false, id: null })
  const [deleting, setDeleting] = useState(null)

  const locations = state.locations ?? []
  const offices = [...locations].sort(byName).flatMap((location) => (location.headquarters ?? []).map((office) => ({
    ...office,
    state: location.name,
    locationId: location.id,
  })))
  const rows = state.headquarters
    .filter((hq) => matchesSearch(hq, query, ['name', 'code', 'state', 'phone', 'email', 'address', 'pincode']) && matchesFilters(
      hq.locationId ? [hq.locationId] : locations.filter((location) => location.name.toLowerCase() === String(hq.state ?? '').toLowerCase()).map((location) => location.id),
      hq.parentManagerId,
      filters,
    ))
    .sort(byName)
  const parents = managersAtLevel(state, 1).sort(byName).map((manager) => ({ value: manager.id, label: manager.name }))
  const viewed = state.headquarters.find((hq) => hq.id === viewing.id)
  const stateName = (hq) => locations.find((location) => location.id === hq.locationId)?.name ?? hq.state ?? '—'

  const columns = [
    { key: 'name', header: 'Headquarters Name', render: (hq) => <strong className="fw-semibold">{hq.name}</strong> },
    { key: 'state', header: 'State', nowrap: true, render: stateName },
    { key: 'code', header: 'Headquarters Code', nowrap: true, render: (hq) => hq.code || '—' },
    { key: 'address', header: 'Address', render: (hq) => hq.address || '—' },
    { key: 'pincode', header: 'Pincode', nowrap: true, render: (hq) => hq.pincode || '—' },
    { key: 'phone', header: 'Phone Number', nowrap: true, render: (hq) => hq.phone || '—' },
    { key: 'email', header: 'Email', render: (hq) => hq.email || '—' },
    {
      key: 'manager',
      header: '1st Line Manager',
      render: (hq) => state.managers.find((manager) => manager.id === hq.parentManagerId)?.name ?? '—',
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      render: (hq) => (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`View ${hq.name} hierarchy`} title="View hierarchy" onClick={() => setViewing({ open: true, id: hq.id })}>
            <i className="bi bi-diagram-3" aria-hidden="true" />
          </button>
          {canManage && (
            <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${hq.name}`} title="Edit" onClick={() => editor.openEdit(hq)}>
              <i className="bi bi-pencil" aria-hidden="true" />
            </button>
          )}
          {canManage && (
            <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${hq.name}`} title="Delete" onClick={() => setDeleting(hq)}>
              <i className="bi bi-trash" aria-hidden="true" />
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <PageShell>
      <DataStatusNotice />
      <div>
      {canManage && ready && parents.length === 0 && (
        <div className="alert alert-info" role="note">
          <i className="bi bi-info-circle-fill" aria-hidden="true" />
          <div>
            Every headquarters belongs to a 1st line manager. <Link to="/sales-office/first-line-managers">Add a 1st line manager</Link> first.
          </div>
        </div>
      )}

      <TableCard
        title={`Headquarters (${state.headquarters.length})`}
        toolbar={
          <>
            <SalesOfficeCsv
              module="headquarters"
              records={rows.map((hq) => {
                const manager = state.managers.find((item) => item.id === hq.parentManagerId)
                return {
                  'Headquarters name': hq.name,
                  '1st line manager': manager?.code || manager?.name || '',
                  State: stateName(hq),
                  Code: hq.code ?? '',
                  'Headquarters address': hq.address ?? '',
                  Pincode: hq.pincode ?? '',
                  'Phone number': hq.phone ?? '',
                  Email: hq.email ?? '',
                }
              })}
            />
            <SearchInput value={query} onChange={setQuery} label="Search headquarters" placeholder="Search name or code" />
            <ListFilter
              locations={locations}
              reports={{ label: 'Reports to', options: parents.map((manager) => ({ id: manager.value, name: manager.label })) }}
              applied={filters}
              onApply={setFilters}
            />
            {canManage && (
              <button type="button" className="btn btn-primary ms-auto" onClick={editor.openNew}>
                <i className="bi bi-plus-lg" aria-hidden="true" /> Add Headquarters
              </button>
            )}
          </>
        }
      >
        <DataTable
          caption="Headquarters"
          columns={columns}
          rows={rows}
          emptyText={state.headquarters.length ? 'No headquarters match your search.' : 'No headquarters yet.'}
          empty={ready && state.headquarters.length === 0 && (
            <div className="card-body">
              <EmptyState
                icon="bi-building"
                title="No headquarters yet"
                text="Each headquarters belongs to one 1st line manager and one state. Stockists are mapped to a headquarters."
              />
            </div>
          )}
        />
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={editor.record?.id ? 'Edit headquarters' : 'Add headquarters'}
        renderForm={(formId) => (
          <HeadquartersForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            parents={parents}
            offices={offices}
            onSave={async (values) => {
              await actions.saveRecord('headquarters', { ...editor.record, ...values })
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
        {viewed && (
          <>
            <p className="mb-3">
              {viewed.address}<br />
              {viewed.state} {viewed.pincode}<br />
              {viewed.phone}
            </p>
            <HierarchyTree branch={buildHeadquartersBranch(state, viewed.id)} />
          </>
        )}
      </Modal>

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title="Delete headquarters"
        confirmLabel="Delete"
        onConfirm={() => actions.deleteHeadquarters(deleting.id)}
      >
        Delete {deleting?.name}? Stockists and sales entries for this headquarters are removed with it. The location headquarters record stays on the Location page.
      </ConfirmDialog>
      </div>
    </PageShell>
  )
}
