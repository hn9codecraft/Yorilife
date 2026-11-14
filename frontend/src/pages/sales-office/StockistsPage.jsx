import { useEffect, useMemo, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SelectionBar from '../../components/SelectionBar.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import ListFilter from '../../components/ListFilter.jsx'
import SalesOfficeCsv from '../../components/SalesOfficeCsv.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import Modal from '../../components/Modal.jsx'
import HierarchyTree from '../../components/HierarchyTree.jsx'
import { LabelNote, TextField } from '../../components/FormField.jsx'
import SearchableSelect from '../../components/SearchableSelect.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import useFormState from '../../hooks/useFormState.js'
import { api } from '../../api/client.js'
import { buildHeadquartersBranch, byName, findById, matchesFilters } from '../../data/selectors.js'
import {
  STOCKIST_LICENCES,
  employeeLabel,
  findHeadquarter,
  formatDate,
  headquarterOptions,
  licenceStatus,
  stockistCodeTaken,
  todayIso,
} from '../../data/stockists.js'

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const DATE = /^\d{4}-\d{2}-\d{2}$/

function StockistForm({ formId, record, stockists, headquarters, employees, onSave }) {
  const licenceValues = Object.fromEntries(
    STOCKIST_LICENCES.flatMap((licence) => [
      [licence.number, record?.[licence.number] ?? ''],
      [licence.validUpto, record?.[licence.validUpto] ?? ''],
    ]),
  )
  const form = useFormState(
    {
      name: record?.name ?? '',
      code: record?.code ?? '',
      locationHeadquartersId: record?.locationHeadquartersId ? String(record.locationHeadquartersId) : '',
      address: record?.address ?? '',
      pincode: record?.pincode ?? '',
      phone: record?.phone ?? '',
      email: record?.email ?? '',
      ...licenceValues,
      assignedEmployeeId: record?.assignedEmployeeId ? String(record.assignedEmployeeId) : '',
    },
    (v) => {
      const errors = {}
      if (!v.name.trim()) errors.name = 'Enter the stockist name.'
      if (stockistCodeTaken(stockists, v.code, record?.id)) errors.code = 'This stockist code is already in use.'
      if (v.pincode.trim() && !/^[1-9][0-9]{5}$/.test(v.pincode.trim())) errors.pincode = 'Enter a 6-digit pincode.'
      if (v.phone.trim() && !/^\+?[0-9][0-9\s().-]{5,19}$/.test(v.phone.trim())) errors.phone = 'Enter a valid phone number.'
      if (v.email.trim() && !EMAIL.test(v.email.trim())) errors.email = 'Enter a valid email address.'
      for (const licence of STOCKIST_LICENCES) {
        if (v[licence.validUpto] && !DATE.test(v[licence.validUpto])) errors[licence.validUpto] = 'Enter a valid date.'
      }
      return errors
    },
  )

  const employeeOptions = employees.map((employee) => ({
    value: String(employee.id),
    label: employeeLabel(employee),
    keywords: [employee.employeeCode, employee.name, employee.firstName, employee.lastName].filter(Boolean),
  }))


  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((v) => onSave({
        name: v.name.trim(),
        code: v.code.trim(),
        locationHeadquartersId: v.locationHeadquartersId || null,
        address: v.address.trim(),
        pincode: v.pincode.trim(),
        phone: v.phone.trim(),
        email: v.email.trim(),
        ...Object.fromEntries(
          STOCKIST_LICENCES.flatMap((licence) => [
            [licence.number, v[licence.number].trim()],
            [licence.validUpto, v[licence.validUpto] || null],
          ]),
        ),
        assignedEmployeeId: v.assignedEmployeeId || null,
      }))}
    >
      <TextField id={form.fieldId('name')} label="Stockist Name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} maxLength={150} className="col-12 col-md-6" />
      <TextField
        id={form.fieldId('code')}
        label={<LabelNote label="Stockist Code" note="must be unique" />}
        value={form.values.code}
        onChange={form.set('code')}
        error={form.errors.code}
        maxLength={50}
        autoComplete="off"
        className="col-12 col-md-6"
      />
      <SearchableSelect
        id={form.fieldId('locationHeadquartersId')}
        label={<LabelNote label="Headquarter" note={headquarters.length === 0 ? 'none added yet' : 'from Headquarter list'} />}
        value={form.values.locationHeadquartersId}
        onChange={form.set('locationHeadquartersId')}
        options={headquarters}
        placeholder="Search or select headquarter"
        ariaLabel="Headquarter"
        missingLabel="Headquarter no longer listed"
        noResultsText="No headquarter matches"
        error={form.errors.locationHeadquartersId}
        className="col-12 col-md-6"
      />
      <SearchableSelect
        id={form.fieldId('assignedEmployeeId')}
        label={<LabelNote label="Assigned To" note="from Employee List" />}
        value={form.values.assignedEmployeeId}
        onChange={form.set('assignedEmployeeId')}
        options={employeeOptions}
        placeholder="Search name or employee code"
        ariaLabel="Assigned To"
        missingLabel="Employee no longer listed"
        noResultsText="No employee matches"
        error={form.errors.assignedEmployeeId}
        className="col-12 col-md-6"
      />
      <TextField id={form.fieldId('address')} label="Address" value={form.values.address} onChange={form.set('address')} error={form.errors.address} maxLength={255} />
      <div className="col-12">
        <div className="row g-3">
          <TextField id={form.fieldId('email')} label="Email" type="email" value={form.values.email} onChange={form.set('email')} error={form.errors.email} maxLength={255} className="col-12 col-md-4" />
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
        </div>
      </div>
      {STOCKIST_LICENCES.map((licence) => (
        <div key={licence.key} className="col-12">
          <div className="row g-3">
            <TextField
              id={form.fieldId(licence.number)}
              label={`${licence.label} Number`}
              value={form.values[licence.number]}
              onChange={form.set(licence.number)}
              error={form.errors[licence.number]}
              maxLength={100}
              className="col-12 col-md-6"
            />
            <TextField
              id={form.fieldId(licence.validUpto)}
              label={`${licence.label} Valid Up To`}
              type="date"
              value={form.values[licence.validUpto]}
              onChange={form.set(licence.validUpto)}
              error={form.errors[licence.validUpto]}
              className="col-12 col-md-6"
            />
          </div>
        </div>
      ))}
    </form>
  )
}

function LicenceCell({ number, validUpto, today }) {
  if (!number && !validUpto) return '—'
  const status = licenceStatus(validUpto, today)
  return (
    <div>
      <div>{number || '—'}</div>
      {validUpto && (
        <div className="small text-body-secondary text-nowrap">
          Valid up to {formatDate(validUpto)}
          {status === 'expired' && <span className="badge bg-danger-subtle text-danger-emphasis ms-1">Expired</span>}
          {status === 'soon' && <span className="badge bg-warning-subtle text-warning-emphasis ms-1">Expiring soon</span>}
        </div>
      )}
    </div>
  )
}

export default function StockistsPage() {
  const { state, actions } = useErpData()
  const canManage = usePermission('sales_office.manage')
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [filters, setFilters] = useState({ stateIds: [], reportIds: [] })
  const [viewing, setViewing] = useState({ open: false, id: null })
  const [deleting, setDeleting] = useState(null)
  const [deletingMany, setDeletingMany] = useState(false)
  const [employees, setEmployees] = useState([])

  useEffect(() => {
    let cancelled = false
    api('/api/sales-employees')
      .then((data) => {
        if (!cancelled) setEmployees(data.employees ?? [])
      })
      .catch(() => {
        if (!cancelled) setEmployees([])
      })
    return () => {
      cancelled = true
    }
  }, [editor.session])

  const locations = useMemo(() => state.locations ?? [], [state.locations])
  const headquarters = useMemo(() => headquarterOptions(locations), [locations])
  const sortedEmployees = useMemo(
    () => [...employees].sort((a, b) => employeeLabel(a).localeCompare(employeeLabel(b))),
    [employees],
  )
  const today = todayIso()
  const officeOf = (stockist) => findHeadquarter(locations, stockist.locationHeadquartersId)
  const employeeOf = (stockist) => employees.find((employee) => String(employee.id) === String(stockist.assignedEmployeeId ?? ''))

  const needle = query.trim().toLowerCase()
  const rows = state.stockists
    .filter((stockist) => {
      const office = officeOf(stockist)
      const haystack = [
        stockist.name, stockist.code, stockist.address, stockist.pincode, stockist.phone, stockist.email,
        office?.name, office?.stateName, employeeLabel(employeeOf(stockist)),
        ...STOCKIST_LICENCES.map((licence) => stockist[licence.number]),
      ].filter(Boolean).join(' ').toLowerCase()
      return (!needle || haystack.includes(needle)) && matchesFilters(office ? [office.stateId] : [], null, filters)
    })
    .sort(byName)
  const selection = useRowSelection(rows)
  const viewed = findById(state.stockists, viewing.id)

  const columns = [
    { key: 'name', header: 'Stockist', render: (s) => <strong className="fw-semibold">{s.name}</strong> },
    { key: 'code', header: 'Code', nowrap: true, render: (s) => s.code || '—' },
    { key: 'hq', header: 'Headquarter', render: (s) => officeOf(s)?.name ?? '—' },
    { key: 'state', header: 'State', render: (s) => officeOf(s)?.stateName ?? '—' },
    { key: 'assigned', header: 'Assigned To', render: (s) => (s.assignedEmployeeId ? employeeLabel(employeeOf(s)) || '—' : '—') },
    { key: 'address', header: 'Address', render: (s) => [s.address, s.pincode].filter(Boolean).join(' - ') || '—' },
    { key: 'phone', header: 'Phone Number', nowrap: true, render: (s) => s.phone || '—' },
    { key: 'email', header: 'Email', render: (s) => s.email || '—' },
    ...STOCKIST_LICENCES.map((licence) => ({
      key: licence.key,
      header: licence.label,
      render: (s) => <LicenceCell number={s[licence.number]} validUpto={s[licence.validUpto]} today={today} />,
    })),
    {
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      render: (s) => (
        <div className="table-actions">
          {s.headquartersId && (
            <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`View ${s.name} hierarchy`} title="View hierarchy" onClick={() => setViewing({ open: true, id: s.id })}>
              <i className="bi bi-diagram-3" aria-hidden="true" />
            </button>
          )}
          {canManage && (
            <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${s.name}`} title="Edit" onClick={() => editor.openEdit(s)}>
              <i className="bi bi-pencil" aria-hidden="true" />
            </button>
          )}
          {canManage && (
            <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${s.name}`} title="Delete" onClick={() => setDeleting(s)}>
              <i className="bi bi-trash" aria-hidden="true" />
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={
        canManage && (
          <button type="button" className="btn btn-primary" onClick={editor.openNew}>
            <i className="bi bi-plus-lg" aria-hidden="true" /> Add stockist
          </button>
        )
      }
    >
      <DataStatusNotice />

      <TableCard
        title={`Stockists (${state.stockists.length})`}
        toolbar={
          <>
            <SearchInput value={query} onChange={setQuery} label="Search stockists" placeholder="Search name, code or licence" />
            <ListFilter
              locations={locations}
              reports={null}
              applied={filters}
              onApply={setFilters}
            />
            <SalesOfficeCsv
              module="stockist"
              records={rows.map((stockist) => {
                const office = officeOf(stockist)
                return {
                  'Stockist name': stockist.name,
                  'Stockist code': stockist.code ?? '',
                  Headquarter: office ? (office.code || office.name) : '',
                  State: office?.stateName ?? '',
                  'Assigned to': stockist.assignedEmployeeId ? employeeLabel(employeeOf(stockist)) : '',
                  Address: stockist.address ?? '',
                  Pincode: stockist.pincode ?? '',
                  'Phone number': stockist.phone ?? '',
                  Email: stockist.email ?? '',
                  'Drug licence number': stockist.drugLicenceNo ?? '',
                  'Drug licence valid up to': formatDate(stockist.drugLicenceValidUpto),
                  'Food licence number': stockist.foodLicenceNo ?? '',
                  'Food licence valid up to': formatDate(stockist.foodLicenceValidUpto),
                  'FSSAI licence number': stockist.fssaiLicenceNo ?? '',
                  'FSSAI licence valid up to': formatDate(stockist.fssaiLicenceValidUpto),
                }
              })}
            />
          </>
        }
      >
        {canManage && <SelectionBar count={selection.count} onDelete={() => setDeletingMany(true)} onClear={selection.clear} />}
        <DataTable
          caption="Stockists"
          columns={columns}
          rows={rows}
          selection={canManage ? {
            isSelected: (stockist) => selection.isSelected(stockist.id),
            onToggle: (stockist) => selection.toggle(stockist.id),
            onToggleAll: selection.toggleAll,
            allSelected: selection.allSelected,
            indeterminate: selection.indeterminate,
            label: (stockist) => `Select ${stockist.name}`,
          } : null}
          emptyText={state.stockists.length ? 'No stockists match these filters.' : 'No stockists yet.'}
          empty={state.status === 'ready' && state.stockists.length === 0 && (
            <div className="card-body">
              <EmptyState icon="bi-shop" title="No stockists yet" text="Add a stockist with its headquarter, licences and assigned employee." />
            </div>
          )}
        />
      </TableCard>

      <FormModal
        size="lg"
        variant="wide"
        open={editor.open}
        onClose={editor.close}
        title={editor.record ? 'Edit stockist' : 'Add stockist'}
        renderForm={(formId) => (
          <StockistForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            stockists={state.stockists}
            headquarters={headquarters}
            employees={sortedEmployees}
            onSave={async (values) => {
              await actions.saveRecord('stockists', { ...editor.record, ...values })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title="Delete stockist"
        confirmLabel="Delete"
        onConfirm={() => actions.deleteStockist(deleting.id)}
      >
        Delete {deleting?.name}? Sales entries for this stockist are removed with it.
      </ConfirmDialog>

      <ConfirmDialog
        open={deletingMany}
        onClose={() => setDeletingMany(false)}
        title="Delete selected stockists"
        confirmLabel="Delete selected"
        onConfirm={async () => {
          await actions.deleteMany('stockists', selection.selectedIds)
          selection.clear()
        }}
      >
        Delete {selection.count} stockists? Sales entries for these stockists are removed with them.
      </ConfirmDialog>

      <Modal
        open={viewing.open}
        onClose={() => setViewing((v) => ({ ...v, open: false }))}
        title={viewed ? `${viewed.name} — hierarchy` : 'Stockist hierarchy'}
        size="lg"
      >
        {viewed && (
          <>
            <p className="text-muted">
              The managers below are everyone linked under this stockist’s headquarters.
            </p>
            <HierarchyTree branch={buildHeadquartersBranch(state, viewed.headquartersId)} highlightStockistId={viewed.id} />
          </>
        )}
      </Modal>
    </PageShell>
  )
}