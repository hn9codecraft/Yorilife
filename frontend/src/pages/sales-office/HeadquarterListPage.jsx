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
import FormField, { SelectField, TextField } from '../../components/FormField.jsx'
import { hqCodeSuffix, nextHqSequence } from '../../data/hqCode.js'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import useFormState from '../../hooks/useFormState.js'
import { matchesSearch } from '../../data/selectors.js'

function OfficeNameForm({ formId, record, locations, offices, headquarters, onSave }) {
  const legacyCode = record?.code && hqCodeSuffix(record.code) === null ? record.code : ''
  const form = useFormState(
    {
      locationId: record?.locationId != null ? String(record.locationId) : '',
      name: record?.name ?? '',
      code: record?.code ? (hqCodeSuffix(record.code) ?? '') : nextHqSequence([...offices, ...headquarters]),
      address: record?.address ?? '',
      pincode: record?.pincode ?? '',
      phone: record?.phone ?? '',
      email: record?.email ?? '',
    },
    (values) => {
      const errors = {}
      const name = values.name.trim()
      const digits = values.code.trim()
      const fullCode = digits ? `HQ-${digits}` : legacyCode
      const location = locations.find((item) => String(item.id) === String(values.locationId))
      if (!location) errors.locationId = 'Choose a state from the list.'
      if (!name) errors.name = 'Enter the headquarters name.'
      else if (offices.some((office) => office.id !== record?.id && String(office.locationId) === String(values.locationId) && office.name.trim().toLowerCase() === name.toLowerCase())) {
        errors.name = 'This headquarters name already exists for the state.'
      }
      if (!/^\d*$/.test(digits)) errors.code = 'Enter numbers only.'
      else if (!fullCode) errors.code = 'Enter the headquarters code.'
      else if (
        offices.some((office) => office.id !== record?.id && office.code && office.code.toLowerCase() === fullCode.toLowerCase())
        || headquarters.some((hq) => String(hq.locationHeadquartersId) !== String(record?.id ?? '') && hq.code && hq.code.toLowerCase() === fullCode.toLowerCase())
      ) {
        errors.code = 'This code is already in use.'
      }
      if (!values.address.trim()) errors.address = 'Enter the address.'
      if (!/^[1-9][0-9]{5}$/.test(values.pincode.trim())) errors.pincode = 'Enter a 6-digit pincode.'
      if (!/^\+?[0-9][0-9\s().-]{5,19}$/.test(values.phone.trim())) errors.phone = 'Enter a valid phone number.'
      const email = values.email.trim().toLowerCase()
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = 'Enter a valid email address.'
      return errors
    },
  )
  const codeId = form.fieldId('code')

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({
        locationId: values.locationId,
        name: values.name.trim(),
        code: values.code.trim() ? `HQ-${values.code.trim()}` : legacyCode,
        address: values.address.trim(),
        pincode: values.pincode.trim(),
        phone: values.phone.trim(),
        email: values.email.trim(),
      }))}
    >
      <SelectField
        id={form.fieldId('locationId')}
        label="State"
        required
        value={form.values.locationId}
        onChange={form.set('locationId')}
        error={form.errors.locationId}
        placeholder={locations.length ? 'Select a state' : 'No states yet'}
        options={locations.map((location) => ({ value: location.id, label: location.name }))}
        disabled={locations.length === 0}
        className="col-12 col-md-6"
      />
      <TextField id={form.fieldId('name')} label="Headquarters name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} className="col-12 col-md-6" />
      <FormField id={codeId} label="Headquarters Code" required error={form.errors.code} help="Numbers only. Leading zeros are kept." className="col-12 col-md-6">
        <div className="input-group">
          <span className="input-group-text" id={`${codeId}-prefix`}>HQ-</span>
          <input
            id={codeId}
            className={`form-control${form.errors.code ? ' is-invalid' : ''}`}
            inputMode="numeric"
            autoComplete="off"
            maxLength={10}
            placeholder="001"
            aria-describedby={`${codeId}-prefix`}
            value={form.values.code}
            onChange={(event) => form.set('code')(event.target.value.replace(/\D/g, '').slice(0, 10))}
          />
        </div>
      </FormField>
      <TextField id={form.fieldId('pincode')} label="Pincode" required value={form.values.pincode} onChange={form.set('pincode')} error={form.errors.pincode} inputMode="numeric" className="col-12 col-md-6" />
      <TextField id={form.fieldId('address')} label="Headquarters address" required value={form.values.address} onChange={form.set('address')} error={form.errors.address} />
      <TextField id={form.fieldId('phone')} label="Phone number" required value={form.values.phone} onChange={form.set('phone')} error={form.errors.phone} inputMode="tel" maxLength={20} help="A country code, spaces, or a leading zero are kept." className="col-12 col-md-6" />
      <TextField id={form.fieldId('email')} label="Email" type="email" required value={form.values.email} onChange={form.set('email')} error={form.errors.email} className="col-12 col-md-6" />
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
            <select className="form-select form-select-sm toolbar__select" aria-label="State" value={stateId} onChange={(event) => setStateId(event.target.value)}>
              <option value="">All states</option>
              {locations.map((location) => <option key={location.id} value={String(location.id)}>{location.name}</option>)}
            </select>
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
            { key: 'name', header: 'Headquarters Name', render: (office) => <strong className="fw-semibold">{office.name}</strong> },
            { key: 'state', header: 'State', render: (office) => office.state },
            { key: 'code', header: 'Headquarters Code', nowrap: true, render: (office) => office.code || '—' },
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
