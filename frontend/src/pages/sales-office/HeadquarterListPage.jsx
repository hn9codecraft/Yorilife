import { useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SelectionBar from '../../components/SelectionBar.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import { FormRow, LabelNote, TextField } from '../../components/FormField.jsx'
import SearchableSelect from '../../components/SearchableSelect.jsx'
import SalesOfficeCsv from '../../components/SalesOfficeCsv.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import useFormState from '../../hooks/useFormState.js'
import { matchesSearch } from '../../data/selectors.js'

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE = /^\+?[0-9][0-9\s().-]{5,19}$/

function OfficeNameForm({ formId, record, locations, offices, headquarters, onSave }) {
  const form = useFormState(
    {
      locationId: record?.locationId != null ? String(record.locationId) : '',
      name: record?.name ?? '',
      code: record?.code ?? '',
      address: record?.address ?? '',
      pincode: record?.pincode ?? '',
      phone: record?.phone ?? '',
      email: record?.email ?? '',
    },
    (values) => {
      const errors = {}
      const name = values.name.trim()
      const code = values.code.trim().toLowerCase()
      if (!locations.some((item) => String(item.id) === String(values.locationId))) errors.locationId = 'Choose a state from the list.'
      if (!name) errors.name = 'Enter the headquarter name.'
      else if (offices.some((office) => office.id !== record?.id && String(office.locationId) === String(values.locationId) && (office.name ?? '').trim().toLowerCase() === name.toLowerCase())) {
        errors.name = 'This headquarter name already exists for the state.'
      }
      if (code && (
        offices.some((office) => office.id !== record?.id && (office.code ?? '').trim().toLowerCase() === code)
        || headquarters.some((hq) => String(hq.locationHeadquartersId) !== String(record?.id ?? '') && (hq.code ?? '').trim().toLowerCase() === code)
      )) {
        errors.code = 'This code is already in use.'
      }
      if (values.pincode.trim() && !/^[1-9][0-9]{5}$/.test(values.pincode.trim())) errors.pincode = 'Enter a 6-digit pincode.'
      if (values.phone.trim() && !PHONE.test(values.phone.trim())) errors.phone = 'Enter a valid phone number.'
      if (values.email.trim() && !EMAIL.test(values.email.trim())) errors.email = 'Enter a valid email address.'
      return errors
    },
  )
  const stateOptions = locations.map((location) => ({ value: String(location.id), label: location.name, keywords: [location.code].filter(Boolean) }))

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({
        locationId: values.locationId,
        name: values.name.trim(),
        code: values.code.trim(),
        address: values.address.trim(),
        pincode: values.pincode.trim(),
        phone: values.phone.trim(),
        email: values.email.trim(),
      }))}
    >
      <TextField id={form.fieldId('name')} label="Headquarter Name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} maxLength={150} className="col-12 col-md-6" />
      <TextField
        id={form.fieldId('code')}
        label={<LabelNote label="Headquarter Code" note="any format, must be unique" />}
        value={form.values.code}
        onChange={form.set('code')}
        error={form.errors.code}
        maxLength={50}
        autoComplete="off"
        className="col-12 col-md-6"
      />
      <SearchableSelect
        id={form.fieldId('locationId')}
        label={<LabelNote label="State" note="from State List" />}
        ariaLabel="State"
        required
        value={form.values.locationId}
        onChange={form.set('locationId')}
        options={stateOptions}
        placeholder={locations.length ? 'Search or select state' : 'No states yet'}
        noResultsText="No state matches"
        error={form.errors.locationId}
        disabled={locations.length === 0}
        className="col-12 col-md-6"
      />
      <TextField id={form.fieldId('address')} label="Address" value={form.values.address} onChange={form.set('address')} error={form.errors.address} maxLength={255} className="col-12 col-md-6" />
      <FormRow>
        <TextField id={form.fieldId('email')} label="Email" type="email" value={form.values.email} onChange={form.set('email')} error={form.errors.email} maxLength={254} className="col-12 col-md-4" />
        <TextField id={form.fieldId('phone')} label="Phone Number" value={form.values.phone} onChange={form.set('phone')} error={form.errors.phone} inputMode="tel" maxLength={20} className="col-12 col-md-4" />
        <TextField
          id={form.fieldId('pincode')}
          label={<LabelNote label="Pincode" note="6 digits" />}
          value={form.values.pincode}
          onChange={form.set('pincode')}
          error={form.errors.pincode}
          inputMode="numeric"
          maxLength={6}
          className="col-12 col-md-4"
        />
      </FormRow>
    </form>
  )
}
export default function HeadquarterListPage() {
  const { state, actions } = useErpData()
  const canManage = usePermission('sales_office.manage')
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [stateId, setStateId] = useState('')
  const [deleting, setDeleting] = useState(null)
  const [deletingMany, setDeletingMany] = useState(false)
  const locations = state.locations ?? []
  const offices = locations.flatMap((location) => (location.headquarters ?? []).map((office) => ({
    ...office,
    state: location.name,
    locationId: location.id,
  })))
  const rows = offices.filter((office) => {
    if (stateId && String(office.locationId) !== stateId) return false
    return matchesSearch(office, query, ['name', 'code', 'state', 'address', 'pincode', 'phone', 'email'])
  })
  const selection = useRowSelection(rows)

  return (
    <PageShell
      actions={canManage && (
        <button type="button" className="btn btn-primary" onClick={editor.openNew}>
          <i className="bi bi-plus-lg" aria-hidden="true" /> Add headquarter
        </button>
      )}
    >
      <DataStatusNotice />
      <TableCard
        title={`Headquarters (${rows.length})`}
        toolbar={(
          <>
            <SearchInput value={query} onChange={setQuery} label="Search headquarters" placeholder="Search name, code, or contact" />
            <SearchableSelect
              id="headquarter-list-state"
              label="State"
              labelHidden
              value={stateId}
              onChange={setStateId}
              options={locations.map((location) => ({ value: String(location.id), label: location.name, keywords: [location.code].filter(Boolean) }))}
              placeholder="All states"
              noResultsText="No state matches"
              className="toolbar__field searchable-select--toolbar"
            />
            <SalesOfficeCsv
              module="headquarter"
              records={rows.map((office) => ({
                'Headquarter name': office.name ?? '',
                State: office.state ?? '',
                'Headquarter code': office.code ?? '',
                Address: office.address ?? '',
                Pincode: office.pincode ?? '',
                'Phone number': office.phone ?? '',
                Email: office.email ?? '',
              }))}
            />
          </>
        )}
      >
        {canManage && <SelectionBar count={selection.count} onDelete={() => setDeletingMany(true)} onClear={selection.clear} />}
        <DataTable
          caption="Headquarters"
          selection={canManage ? {
            isSelected: (office) => selection.isSelected(office.id),
            onToggle: (office) => selection.toggle(office.id),
            onToggleAll: selection.toggleAll,
            allSelected: selection.allSelected,
            indeterminate: selection.indeterminate,
            label: (office) => `Select ${office.name}`,
          } : null}
          columns={[
            { key: 'name', header: 'Headquarter Name', render: (office) => <strong className="fw-semibold">{office.name}</strong> },
            { key: 'state', header: 'State', render: (office) => office.state },
            { key: 'code', header: 'Headquarter Code', nowrap: true, render: (office) => office.code || '—' },
            { key: 'address', header: 'Address', render: (office) => office.address || '—' },
            { key: 'pincode', header: 'Pincode', nowrap: true, render: (office) => office.pincode || '—' },
            { key: 'phone', header: 'Phone Number', nowrap: true, render: (office) => office.phone || '—' },
            { key: 'email', header: 'Email', render: (office) => office.email || '—' },
            {
              key: 'actions',
              header: 'Actions',
              align: 'end',
              nowrap: true,
              render: (office) => canManage ? (
                <div className="table-actions">
                  <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${office.name}`} title="Edit" onClick={() => editor.openEdit(office)}>
                    <i className="bi bi-pencil" aria-hidden="true" />
                  </button>
                  <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${office.name}`} title="Delete" onClick={() => setDeleting(office)}>
                    <i className="bi bi-trash" aria-hidden="true" />
                  </button>
                </div>
              ) : null,
            },
          ]}
          rows={rows}
          emptyText={offices.length ? 'No headquarters match your search.' : 'No headquarters yet.'}
        />
      </TableCard>

      <FormModal
        size="lg"
        variant="wide"
        open={editor.open}
        onClose={editor.close}
        title={editor.record?.id ? 'Edit headquarter' : 'Add headquarter'}
        renderForm={(formId) => (
          <OfficeNameForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            locations={locations}
            offices={offices}
            headquarters={state.headquarters}
            onSave={async (values) => {
              await actions.createLocationHeadquarters({ ...editor.record, ...values })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title="Delete headquarter"
        confirmLabel="Delete"
        onConfirm={() => actions.deleteLocationHeadquarters(deleting.id)}
      >
        Delete {deleting?.name}? It cannot be deleted while a headquarters office or an employee still uses it.
      </ConfirmDialog>

      <ConfirmDialog
        open={deletingMany}
        onClose={() => setDeletingMany(false)}
        title="Delete selected headquarters"
        confirmLabel="Delete selected"
        onConfirm={async () => {
          await actions.deleteMany('locationHeadquarters', selection.selectedIds)
          selection.clear()
        }}
      >
        Delete {selection.count} headquarters? One that is linked to a headquarters office or an employee cannot be deleted.
      </ConfirmDialog>
    </PageShell>
  )
}
