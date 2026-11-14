import { useEffect, useMemo, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import useFormState from '../../hooks/useFormState.js'
import usePermission from '../../data/usePermission.js'
import useErpData from '../../data/useErpData.js'
import { api } from '../../api/client.js'
import { DESIGNATIONS, clearManagerSlots, designationLabel, designationsAbove } from '../../data/designations.js'

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function managerFields(employee) {
  const fields = Object.fromEntries(DESIGNATIONS.map((item) => [`manager${item.code}`, '']))
  for (const manager of employee?.managers ?? []) {
    fields[`manager${manager.designation}`] = String(manager.employeeId)
  }
  return fields
}

function headquartersChoices(locations, stateId) {
  const choices = []
  for (const location of locations ?? []) {
    if (stateId && String(location.id) !== String(stateId)) continue
    for (const office of location.headquarters ?? []) {
      choices.push({
        value: String(office.id),
        label: stateId ? office.name : `${office.name} (${location.name})`,
      })
    }
  }
  return choices.sort((a, b) => a.label.localeCompare(b.label))
}

function EmployeeForm({ formId, employee, employees, locations, onSaved }) {
  const form = useFormState(
    {
      name: employee?.name ?? '',
      employeeCode: employee?.employeeCode ?? '',
      phone: employee?.phone ?? '',
      email: employee?.email ?? '',
      designation: employee?.designation ?? '',
      locationId: employee?.locationId ? String(employee.locationId) : '',
      locationHeadquartersId: employee?.locationHeadquartersId ? String(employee.locationHeadquartersId) : '',
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
  const headquarters = headquartersChoices(locations, values.locationId)

  const onDesignation = (code) => {
    const slots = Object.fromEntries(DESIGNATIONS.map((item) => [item.code, values[`manager${item.code}`]]))
    const cleared = clearManagerSlots(code, slots)
    const patch = { designation: code }
    for (const item of DESIGNATIONS) patch[`manager${item.code}`] = cleared[item.code]
    setMany(patch)
  }

  const onState = (locationId) => {
    const stillThere = headquartersChoices(locations, locationId).some((choice) => choice.value === values.locationHeadquartersId)
    setMany({ locationId, locationHeadquartersId: stillThere ? values.locationHeadquartersId : '' })
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
          name: body.name.trim() || null,
          employeeCode: body.employeeCode.trim() || null,
          phone: body.phone.trim() || null,
          email: body.email.trim() || null,
          designation: body.designation || null,
          locationId: body.locationId ? Number(body.locationId) : null,
          locationHeadquartersId: body.locationHeadquartersId ? Number(body.locationHeadquartersId) : null,
          managers,
        }
        const data = employee
          ? await api(`/api/sales-employees/${employee.id}`, { method: 'PATCH', body: payload })
          : await api('/api/sales-employees', { method: 'POST', body: payload })
        onSaved(data.employee)
      })}
    >
      <TextField id={fieldId('name')} label="Name" value={values.name} onChange={set('name')} error={errors.name} maxLength={150} className="col-12 col-md-6" />
      <TextField id={fieldId('employeeCode')} label="Employee Code" value={values.employeeCode} onChange={set('employeeCode')} error={errors.employeeCode} maxLength={100} autoComplete="off" help="Type the code. It is not generated." className="col-12 col-md-6" />
      <TextField id={fieldId('phone')} type="tel" label="Phone Number" value={values.phone} onChange={set('phone')} error={errors.phone} maxLength={30} className="col-12 col-md-6" />
      <TextField id={fieldId('email')} type="email" label="Email" value={values.email} onChange={set('email')} error={errors.email} maxLength={254} className="col-12 col-md-6" />
      <SelectField
        id={fieldId('designation')}
        label="Designation"
        value={values.designation}
        onChange={onDesignation}
        error={errors.designation}
        placeholder="Not set"
        options={DESIGNATIONS.map((item) => ({ value: item.code, label: item.label }))}
        className="col-12"
      />
      <SelectField
        id={fieldId('locationId')}
        label="State"
        value={values.locationId}
        onChange={onState}
        error={errors.locationId}
        placeholder={locations.length ? 'Not set' : 'No states yet'}
        options={locations.map((location) => ({ value: String(location.id), label: location.name }))}
        className="col-12 col-md-6"
      />
      <SelectField
        id={fieldId('locationHeadquartersId')}
        label="HQ"
        value={values.locationHeadquartersId}
        onChange={set('locationHeadquartersId')}
        error={errors.locationHeadquartersId}
        placeholder={headquarters.length ? 'Not set' : 'No headquarters yet'}
        options={headquarters}
        help={values.locationId ? 'Headquarters for the selected state.' : 'All headquarters. Choose a state to narrow the list.'}
        className="col-12 col-md-6"
      />
      {above.length > 0 && (
        <fieldset className="col-12">
          <legend className="form-label mb-1">Reports to</legend>
          <p className="form-text mt-0">Optional. Each list is everyone with that designation. Choosing one does not fill or limit the others.</p>
          <div className="row g-3">
            {above.map((item) => {
              const field = `manager${item.code}`
              const options = employees
                .filter((person) => person.designation === item.code && person.id !== employee?.id)
                .map((person) => ({
                  value: String(person.id),
                  label: person.name || person.employeeCode || `Employee ${person.id}`,
                }))
              return (
                <SelectField
                  key={item.code}
                  id={fieldId(field)}
                  label={item.label}
                  value={values[field]}
                  onChange={set(field)}
                  error={errors[field]}
                  placeholder={options.length ? 'Not set' : 'No employees with this designation yet'}
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
      if (stateId && String(row.locationId ?? '') !== stateId) return false
      if (headquartersId && String(row.locationHeadquartersId ?? '') !== headquartersId) return false
      if (!q) return true
      const managers = (row.managers ?? []).map((manager) => `${manager.designation} ${manager.employeeName ?? ''} ${manager.employeeCode ?? ''}`).join(' ')
      const haystack = [
        row.name, row.employeeCode, row.phone, row.email, designationLabel(row.designation),
        row.locationName, row.locationHeadquartersName, managers,
      ].filter(Boolean).join(' ').toLowerCase()
      return haystack.includes(q)
    })
  }, [rows, query, designation, stateId, headquartersId])

  const open = (employee) => {
    setFormKey((key) => key + 1)
    setEditing(employee ?? 'new')
  }

  const columns = [
    { key: 'name', header: 'Name', render: (row) => <strong className="fw-semibold">{row.name || '—'}</strong> },
    { key: 'code', header: 'Employee Code', nowrap: true, render: (row) => row.employeeCode || '—' },
    { key: 'designation', header: 'Designation', render: (row) => designationLabel(row.designation) || '—' },
    { key: 'state', header: 'State', render: (row) => row.locationName || '—' },
    { key: 'hq', header: 'HQ', render: (row) => row.locationHeadquartersName || '—' },
    { key: 'phone', header: 'Phone Number', nowrap: true, render: (row) => row.phone || '—' },
    { key: 'email', header: 'Email', render: (row) => row.email || '—' },
    { key: 'managers', header: 'Managers', render: (row) => managerSummary(row.managers) },
    ...(canManage ? [{
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      render: (row) => {
        const label = row.name || row.employeeCode || 'employee'
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
        Remove {deleting?.name || deleting?.employeeCode || 'this employee'} from the employee list?
      </ConfirmDialog>
    </PageShell>
  )
}
