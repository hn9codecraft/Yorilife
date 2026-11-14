import { useEffect, useMemo, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import { FormRow, LabelNote, MultiSelectField, SelectField, TextField } from '../../components/FormField.jsx'
import SearchableSelect from '../../components/SearchableSelect.jsx'
import useFormState from '../../hooks/useFormState.js'
import usePermission from '../../data/usePermission.js'
import useErpData from '../../data/useErpData.js'
import { api } from '../../api/client.js'
import { DESIGNATIONS, clearManagerSlots, designationLabel, designationsAbove } from '../../data/designations.js'
import { employeeLabel, fullName, headquartersChoices, headquartersInStates } from '../../data/employees.js'

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function managerFields(employee) {
  const fields = Object.fromEntries(DESIGNATIONS.map((item) => [`manager${item.code}`, '']))
  for (const manager of employee?.managers ?? []) {
    fields[`manager${manager.designation}`] = String(manager.employeeId)
  }
  return fields
}

function tagList(items, labelOf = (item) => item.name) {
  if (!items?.length) return '—'
  return (
    <ul className="pill-list">
      {items.map((item) => <li key={item.id} className="pill">{labelOf(item)}</li>)}
    </ul>
  )
}

function headquartersTags(offices) {
  const many = new Set((offices ?? []).map((office) => office.locationId)).size > 1
  return tagList(offices, (office) => (many && office.locationName ? `${office.name} (${office.locationName})` : office.name))
}

function EmployeeForm({ formId, employee, employees, locations, onSaved }) {
  const form = useFormState(
    {
      firstName: employee?.firstName ?? '',
      lastName: employee?.lastName ?? '',
      employeeCode: employee?.employeeCode ?? '',
      phone: employee?.phone ?? '',
      email: employee?.email ?? '',
      designation: employee?.designation ?? '',
      locationIds: (employee?.locationIds ?? []).map(String),
      locationHeadquartersIds: (employee?.locationHeadquartersIds ?? []).map(String),
      ...managerFields(employee),
    },
    (values) => {
      const errors = {}
      if (values.email.trim() && !EMAIL.test(values.email.trim())) errors.email = 'Enter a valid email address.'
      return errors
    },
  )
  const { values, errors, set, setMany, fieldId } = form
  const above = designationsAbove(values.designation)
  const headquarters = headquartersChoices(locations, values.locationIds, { requireState: true })

  const onDesignation = (code) => {
    const slots = Object.fromEntries(DESIGNATIONS.map((item) => [item.code, values[`manager${item.code}`]]))
    const cleared = clearManagerSlots(code, slots)
    const patch = { designation: code }
    for (const item of DESIGNATIONS) patch[`manager${item.code}`] = cleared[item.code]
    setMany(patch)
  }

  const onStates = (locationIds) => {
    setMany({
      locationIds,
      locationHeadquartersIds: headquartersInStates(values.locationHeadquartersIds, locations, locationIds),
    })
  }

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit(async (body) => {
        const managers = {}
        for (const item of designationsAbove(body.designation)) {
          managers[item.code] = body[`manager${item.code}`] ? Number(body[`manager${item.code}`]) : null
        }
        const payload = {
          firstName: body.firstName.trim() || null,
          lastName: body.lastName.trim() || null,
          employeeCode: body.employeeCode.trim() || null,
          phone: body.phone.trim() || null,
          email: body.email.trim() || null,
          designation: body.designation || null,
          locationIds: body.locationIds.map(Number),
          locationHeadquartersIds: body.locationHeadquartersIds.map(Number),
          managers,
        }
        const data = employee
          ? await api(`/api/sales-employees/${employee.id}`, { method: 'PATCH', body: payload })
          : await api('/api/sales-employees', { method: 'POST', body: payload })
        onSaved(data.employee)
      })}
    >
      <TextField id={fieldId('firstName')} label="First Name" value={values.firstName} onChange={set('firstName')} error={errors.firstName} maxLength={150} className="col-12 col-md-6" />
      <TextField id={fieldId('lastName')} label="Last Name" value={values.lastName} onChange={set('lastName')} error={errors.lastName} maxLength={150} className="col-12 col-md-6" />
      <TextField
        id={fieldId('employeeCode')}
        label={<LabelNote label="Employee Code" note="must be unique" />}
        value={values.employeeCode}
        onChange={set('employeeCode')}
        error={errors.employeeCode}
        maxLength={100}
        autoComplete="off"
        className="col-12 col-md-6"
      />
      <SelectField
        id={fieldId('designation')}
        label="Designation"
        value={values.designation}
        onChange={onDesignation}
        error={errors.designation}
        placeholder="Not set"
        options={DESIGNATIONS.map((item) => ({ value: item.code, label: item.label }))}
        className="col-12 col-md-6"
      />
      <FormRow>
        <TextField id={fieldId('email')} type="email" label={<LabelNote label="Email" note="must be unique" />} value={values.email} onChange={set('email')} error={errors.email} maxLength={254} className="col-12 col-md-6" />
        <TextField id={fieldId('phone')} type="tel" label={<LabelNote label="Phone Number" note="must be unique" />} value={values.phone} onChange={set('phone')} error={errors.phone} maxLength={30} className="col-12 col-md-6" />
      </FormRow>
      <MultiSelectField
        id={fieldId('locationIds')}
        label="State"
        value={values.locationIds}
        onChange={onStates}
        error={errors.locationIds}
        placeholder={locations.length ? 'None selected' : 'No states yet'}
        emptyText={locations.length ? 'No matches.' : 'No states yet.'}
        options={locations.map((location) => ({ value: String(location.id), label: location.name }))}
        className="col-12 col-md-6"
      />
      <MultiSelectField
        id={fieldId('locationHeadquartersIds')}
        label={<LabelNote label="HQ" note="for the selected states" />}
        ariaLabel="HQ"
        value={values.locationHeadquartersIds}
        onChange={set('locationHeadquartersIds')}
        error={errors.locationHeadquartersIds}
        placeholder={headquarters.length ? 'None selected' : 'No headquarters yet'}
        emptyText={values.locationIds.length ? 'No headquarters for the selected states.' : 'Choose a state to see its headquarters.'}
        options={headquarters}
        className="col-12 col-md-6"
      />
      {above.length > 0 && (
        <fieldset className="col-12">
          <legend className="form-label mb-1">
            <LabelNote label="Reports to" note="each list is independent; choosing one does not fill the others" />
          </legend>
          <div className="row g-3">
            {above.map((item) => {
              const field = `manager${item.code}`
              const options = employees
                .filter((person) => person.designation === item.code && person.id !== employee?.id)
                .map((person) => ({ value: String(person.id), label: employeeLabel(person), keywords: [person.employeeCode].filter(Boolean) }))
              return (
                <SearchableSelect
                  key={item.code}
                  id={fieldId(field)}
                  label={item.label}
                  value={values[field]}
                  onChange={set(field)}
                  error={errors[field]}
                  placeholder={options.length ? 'Search or select' : 'No employees with this designation yet'}
                  noResultsText="No employee matches"
                  missingLabel="Employee no longer listed"
                  disabled={options.length === 0}
                  options={options}
                  className="col-12 col-md-6"
                />
              )
            })}
          </div>
        </fieldset>
      )}
    </form>
  )
}

function managerSummary(managers) {
  if (!managers?.length) return '—'
  return (
    <ul className="employee-managers">
      {managers.map((manager) => (
        <li key={manager.designation}>
          <span className="employee-managers__role">{manager.designation}</span>
          {' '}
          {manager.employeeName || manager.employeeCode || '—'}
        </li>
      ))}
    </ul>
  )
}

export default function EmployeesPage() {
  const { state } = useErpData()
  const canManage = usePermission('sales_office.manage')
  const [rows, setRows] = useState(null)
  const [error, setError] = useState(null)
  const [query, setQuery] = useState('')
  const [designation, setDesignation] = useState('')
  const [stateId, setStateId] = useState('')
  const [headquartersId, setHeadquartersId] = useState('')
  const [editing, setEditing] = useState(null)
  const [deleting, setDeleting] = useState(null)
  const [formKey, setFormKey] = useState(0)
  const [version, setVersion] = useState(0)

  useEffect(() => {
    let cancelled = false
    api('/api/sales-employees')
      .then((data) => {
        if (!cancelled) setRows(data.employees)
      })
      .catch((err) => {
        if (!cancelled) setError(err.message)
      })
    return () => {
      cancelled = true
    }
  }, [version])

  const locations = state.locations
  const headquarters = useMemo(() => headquartersChoices(locations, stateId), [locations, stateId])

  const shown = useMemo(() => {
    const q = query.trim().toLowerCase()
    return (rows ?? []).filter((row) => {
      if (designation && row.designation !== designation) return false
      if (stateId && !(row.locationIds ?? []).some((id) => String(id) === stateId)) return false
      if (headquartersId && !(row.locationHeadquartersIds ?? []).some((id) => String(id) === headquartersId)) return false
      if (!q) return true
      const managers = (row.managers ?? []).map((manager) => `${manager.designation} ${manager.employeeName ?? ''} ${manager.employeeCode ?? ''}`).join(' ')
      const places = [
        ...(row.locations ?? []).map((location) => location.name),
        ...(row.headquarters ?? []).map((office) => `${office.name} ${office.locationName ?? ''}`),
      ]
      const haystack = [
        fullName(row.firstName, row.lastName), row.employeeCode, row.phone, row.email, designationLabel(row.designation),
        ...places, managers,
      ].filter(Boolean).join(' ').toLowerCase()
      return haystack.includes(q)
    })
  }, [rows, query, designation, stateId, headquartersId])

  const open = (employee) => {
    setFormKey((key) => key + 1)
    setEditing(employee ?? 'new')
  }

  const columns = [
    { key: 'name', header: 'Name', render: (row) => <strong className="fw-semibold">{fullName(row.firstName, row.lastName) || '—'}</strong> },
    { key: 'code', header: 'Employee Code', nowrap: true, render: (row) => row.employeeCode || '—' },
    { key: 'designation', header: 'Designation', render: (row) => designationLabel(row.designation) || '—' },
    { key: 'state', header: 'State', render: (row) => tagList(row.locations) },
    { key: 'hq', header: 'HQ', render: (row) => headquartersTags(row.headquarters) },
    { key: 'phone', header: 'Phone Number', nowrap: true, render: (row) => row.phone || '—' },
    { key: 'email', header: 'Email', render: (row) => row.email || '—' },
    { key: 'managers', header: 'Managers', render: (row) => managerSummary(row.managers) },
    ...(canManage ? [{
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      render: (row) => {
        const label = fullName(row.firstName, row.lastName) || row.employeeCode || 'employee'
        return (
          <div className="table-actions">
            <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${label}`} title="Edit" onClick={() => open(row)}>
              <i className="bi bi-pencil" aria-hidden="true" />
            </button>
            <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${label}`} title="Delete" onClick={() => setDeleting(row)}>
              <i className="bi bi-trash" aria-hidden="true" />
            </button>
          </div>
        )
      },
    }] : []),
  ]

  return (
    <PageShell
      actions={canManage && (
        <button type="button" className="btn btn-primary" onClick={() => open(null)}>
          <i className="bi bi-plus-lg" aria-hidden="true" /> Add employee
        </button>
      )}
    >
      <DataStatusNotice />
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      <TableCard
        title={`Employees (${shown.length})`}
        toolbar={(
          <>
            <SearchInput value={query} onChange={setQuery} label="Search employees" placeholder="Search name, code, phone, email, or manager" />
            <select className="form-select form-select-sm toolbar__select" aria-label="Designation" value={designation} onChange={(event) => setDesignation(event.target.value)}>
              <option value="">All designations</option>
              {DESIGNATIONS.map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}
            </select>
            <select
              className="form-select form-select-sm toolbar__select"
              aria-label="State"
              value={stateId}
              onChange={(event) => {
                const next = event.target.value
                setStateId(next)
                if (headquartersId && !headquartersChoices(locations, next).some((choice) => choice.value === headquartersId)) {
                  setHeadquartersId('')
                }
              }}
            >
              <option value="">All states</option>
              {locations.map((location) => <option key={location.id} value={String(location.id)}>{location.name}</option>)}
            </select>
            <select className="form-select form-select-sm toolbar__select" aria-label="HQ" value={headquartersId} onChange={(event) => setHeadquartersId(event.target.value)}>
              <option value="">All headquarters</option>
              {headquarters.map((choice) => <option key={choice.value} value={choice.value}>{choice.label}</option>)}
            </select>
          </>
        )}
      >
        <DataTable
          caption="Employees"
          columns={columns}
          rows={shown}
          emptyText={rows === null ? 'Loading...' : rows.length ? 'No employees match your search.' : 'No employees yet.'}
          empty={rows !== null && rows.length === 0 && (
            <div className="card-body">
              <EmptyState icon="bi-people" title="No employees yet" text="Add an employee. Every field can be left empty." />
            </div>
          )}
        />
      </TableCard>
      <FormModal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing && editing !== 'new' ? 'Edit employee' : 'Add employee'}
        submitLabel="Save"
        size="lg"
        variant="wide"
        renderForm={(formId) => (
          <EmployeeForm
            key={formKey}
            formId={formId}
            employee={editing && editing !== 'new' ? editing : null}
            employees={rows ?? []}
            locations={locations}
            onSaved={() => {
              setEditing(null)
              setVersion((value) => value + 1)
            }}
          />
        )}
      />
      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Delete employee"
        confirmLabel="Delete"
        onConfirm={async () => {
          await api(`/api/sales-employees/${deleting.id}/delete`, { method: 'POST', body: {} })
          setVersion((value) => value + 1)
        }}
      >
        Remove {fullName(deleting?.firstName, deleting?.lastName) || deleting?.employeeCode || 'this employee'} from the employee list?
      </ConfirmDialog>
    </PageShell>
  )
}
