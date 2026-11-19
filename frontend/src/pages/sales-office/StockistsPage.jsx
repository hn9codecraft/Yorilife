import { useEffect, useMemo, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SelectionBar from '../../components/SelectionBar.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FilterBar from '../../components/FilterBar.jsx'
import useUrlFilters from '../../hooks/useUrlFilters.js'
import SalesOfficeCsv from '../../components/SalesOfficeCsv.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import Modal from '../../components/Modal.jsx'
import HierarchyTree from '../../components/HierarchyTree.jsx'
import { FormRow, LabelNote, TextField } from '../../components/FormField.jsx'
import SearchableSelect from '../../components/SearchableSelect.jsx'
import { CellStack, StatusTag } from '../../components/TableCells.jsx'
import { designationLabel } from '../../data/designations.js'
import { LICENCE_FILTERS, STOCKIST_FILTER_DEFAULTS, UNASSIGNED, filterStockists } from '../../data/stockistFilters.js'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import useFormState from '../../hooks/useFormState.js'
import { api } from '../../api/client.js'
import { buildHeadquartersBranch, byName, findById } from '../../data/selectors.js'
import {
  STOCKIST_LICENCES,
  employeeLabel,
  findHeadquarter,
  formatDate,
  headquarterOptions,
  licenceStatus,
  stockistCodeTaken,
  stockistLabel,
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
      <TextField id={form.fieldId('name')} label="Stockist Name" value={form.values.name} onChange={form.set('name')} error={form.errors.name} maxLength={150} className="col-12 col-md-6" />
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
      <FormRow>
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
      </FormRow>
      {STOCKIST_LICENCES.map((licence) => (
        <FormRow key={licence.key}>
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
        </FormRow>
      ))}
    </form>
  )
}

function LicenceCell({ number, validUpto, today }) {
  if (!number && !validUpto) return null
  const status = licenceStatus(validUpto, today)
  return (
    <CellStack
      primary={number || ''}
      secondary={validUpto && (
        <>
          <span className="text-nowrap">Valid up to {formatDate(validUpto)}</span>
          {status === 'expired' && <StatusTag tone="danger">Expired</StatusTag>}
          {status === 'soon' && <StatusTag tone="warning" title="Expires within 30 days">Expiring</StatusTag>}
        </>
      )}
    />
  )
}

export default function StockistsPage() {
  const { state, actions } = useErpData()
  const canManage = usePermission('sales_office.manage')
  const editor = useEditor()
  const [filters, setFilter, clearFilters] = useUrlFilters(STOCKIST_FILTER_DEFAULTS)
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

  const rows = filterStockists(state.stockists, filters, { officeOf, today }).sort(byName)

  // Filter choices. Headquarters narrow to the chosen state.
  const stateOptions = locations.map((location) => ({ value: String(location.id), label: location.name, keywords: [location.code].filter(Boolean) }))
  const hqOptions = headquarterOptions(filters.state ? locations.filter((location) => String(location.id) === filters.state) : locations)
  const assignedOptions = [
    { value: UNASSIGNED, label: 'Unassigned' },
    ...sortedEmployees.map((employee) => ({ value: String(employee.id), label: employeeLabel(employee), keywords: [employee.employeeCode].filter(Boolean) })),
  ]
  const labelFor = (options, value) => options.find((option) => option.value === value)?.label ?? value
  const chips = [
    filters.q && { key: 'q', label: `Search: ${filters.q}` },
    filters.hq && { key: 'hq', label: `Headquarter: ${findHeadquarter(locations, filters.hq)?.name ?? labelFor(hqOptions, filters.hq)}` },
    filters.state && { key: 'state', label: `State: ${labelFor(stateOptions, filters.state)}` },
    filters.assigned && { key: 'assigned', label: `Assigned To: ${labelFor(assignedOptions, filters.assigned)}` },
    filters.licence && { key: 'licence', label: `Licence: ${labelFor(LICENCE_FILTERS, filters.licence)}` },
  ].filter(Boolean).map((chip) => ({ ...chip, onRemove: () => setFilter(chip.key, '') }))
  const chooseState = (value) => {
    const office = findHeadquarter(locations, filters.hq)
    const keepHq = !value || !office || String(office.stateId) === value
    setFilter({ state: value, hq: keepHq ? filters.hq : '' })
  }
  const selection = useRowSelection(rows)
  const viewed = findById(state.stockists, viewing.id)

  const columns = [
    { key: 'name', header: 'Stockist', width: 'lg', sticky: 'start', render: (s) => <CellStack strong primary={stockistLabel(s)} secondary={s.name ? s.code : null} /> },
    {
      key: 'hq',
      header: 'Headquarter',
      width: 'md',
      render: (s) => {
        const office = officeOf(s)
        return office ? <CellStack primary={office.name} secondary={office.stateName} /> : null
      },
    },
    {
      key: 'assigned',
      header: 'Assigned To',
      width: 'md',
      render: (s) => {
        const employee = s.assignedEmployeeId ? employeeOf(s) : null
        return employee ? <CellStack primary={employeeLabel(employee)} secondary={designationLabel(employee.designation)} /> : null
      },
    },
    { key: 'address', header: 'Address', width: 'lg', render: (s) => (s.address || s.pincode ? <CellStack primary={s.address || ''} secondary={s.pincode} /> : null) },
    { key: 'contact', header: 'Contact', width: 'md', render: (s) => (s.phone || s.email ? <CellStack primary={s.phone || ''} secondary={s.email} title={[s.phone, s.email].filter(Boolean).join(' · ')} /> : null) },
    ...STOCKIST_LICENCES.map((licence) => ({
      key: licence.key,
      header: licence.label,
      width: 'lg',
      render: (s) => <LicenceCell number={s[licence.number]} validUpto={s[licence.validUpto]} today={today} />,
    })),
    {
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      sticky: 'end',
      render: (s) => (
        <div className="table-actions">
          {s.headquartersId && (
            <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`View ${stockistLabel(s)} hierarchy`} title="View hierarchy" onClick={() => setViewing({ open: true, id: s.id })}>
              <i className="bi bi-diagram-3" aria-hidden="true" />
            </button>
          )}
          {canManage && (
            <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${stockistLabel(s)}`} title="Edit" onClick={() => editor.openEdit(s)}>
              <i className="bi bi-pencil" aria-hidden="true" />
            </button>
          )}
          {canManage && (
            <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${stockistLabel(s)}`} title="Delete" onClick={() => setDeleting(s)}>
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
      >
        <FilterBar
          label="Filter stockists"
          search={{ value: filters.q, onChange: (value) => setFilter('q', value), label: 'Search stockists', placeholder: 'Search name, code, phone or email' }}
          chips={chips}
          onClearAll={() => clearFilters()}
          shown={rows.length}
          total={state.stockists.length}
          noun="stockists"
          actions={(
            <SalesOfficeCsv
              module="stockist"
              records={rows.map((stockist) => {
                const office = officeOf(stockist)
                return {
                  'Stockist name': stockist.name ?? '',
                  'Stockist code': stockist.code ?? '',
                  Headquarter: office?.name ?? '',

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
          )}
        >
          <SearchableSelect
            id="stockist-filter-hq"
            label="Headquarter"
            labelHidden
            value={filters.hq}
            onChange={(value) => setFilter('hq', value)}
            options={hqOptions}
            placeholder="All headquarters"
            noResultsText="No headquarter matches"
            className="filter-bar__control"
          />
          <SearchableSelect
            id="stockist-filter-state"
            label="State"
            labelHidden
            value={filters.state}
            onChange={chooseState}
            options={stateOptions}
            placeholder="All states"
            noResultsText="No state matches"
            className="filter-bar__control"
          />
          <SearchableSelect
            id="stockist-filter-assigned"
            label="Assigned To"
            labelHidden
            value={filters.assigned}
            onChange={(value) => setFilter('assigned', value)}
            options={assignedOptions}
            placeholder="All employees"
            noResultsText="No employee matches"
            missingLabel="Employee no longer listed"
            className="filter-bar__control"
          />
          <select
            className="form-select filter-bar__control"
            aria-label="Licence status"
            value={filters.licence}
            onChange={(event) => setFilter('licence', event.target.value)}
          >
            <option value="">All licence statuses</option>
            {LICENCE_FILTERS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </FilterBar>
        {canManage && <SelectionBar count={selection.count} onDelete={() => setDeletingMany(true)} onClear={selection.clear} />}
        <DataTable
          caption="Stockists"
          columns={columns}
          rows={rows}
          striped
          selection={canManage ? {
            isSelected: (stockist) => selection.isSelected(stockist.id),
            onToggle: (stockist) => selection.toggle(stockist.id),
            onToggleAll: selection.toggleAll,
            allSelected: selection.allSelected,
            indeterminate: selection.indeterminate,
            label: (stockist) => `Select ${stockistLabel(stockist)}`,
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
        Delete {stockistLabel(deleting)}?
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
        Delete {selection.count} stockists?
      </ConfirmDialog>

      <Modal
        open={viewing.open}
        onClose={() => setViewing((v) => ({ ...v, open: false }))}
        title={viewed ? `${stockistLabel(viewed)} — hierarchy` : 'Stockist hierarchy'}
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
