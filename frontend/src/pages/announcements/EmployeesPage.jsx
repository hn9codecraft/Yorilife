import { useEffect, useMemo, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import useFormState from '../../hooks/useFormState.js'
import usePermission from '../../data/usePermission.js'
import { api } from '../../api/client.js'

const STATUS = [{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]

function EmployeeForm({ formId, employee, options, onSaved }) {
  const form = useFormState(
    {
      name: employee?.name ?? '',
      email: employee?.email ?? '',
      phone: employee?.phone ?? '',
      status: employee?.status ?? 'active',
      locationId: employee?.locationId ? String(employee.locationId) : '',
      headquartersId: employee?.headquartersId ? String(employee.headquartersId) : '',
      role: employee?.role ?? '',
    },
    (values) => {
      const errors = {}
      if (!values.name.trim()) errors.name = 'Enter the name.'
      if (values.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) errors.email = 'Enter a valid email address.'
      return errors
    },
  )
  const { values, errors, set, fieldId } = form
  return (
    <form id={formId} noValidate onSubmit={form.handleSubmit(async (body) => {
      const payload = { ...body, locationId: body.locationId || null, headquartersId: body.headquartersId || null, email: body.email || null, phone: body.phone || null, role: body.role || null }
      const data = employee
        ? await api(`/api/employees/${employee.id}`, { method: 'PATCH', body: payload })
        : await api('/api/employees', { method: 'POST', body: payload })
      onSaved(data.employee)
    })}>
      <div className="row g-3">
        <TextField id={fieldId('name')} label="Name" required value={values.name} onChange={set('name')} error={errors.name} maxLength={150} />
        <TextField id={fieldId('email')} type="email" label="Email" value={values.email} onChange={set('email')} error={errors.email} maxLength={254} />
        <TextField id={fieldId('phone')} type="tel" label="Phone" value={values.phone} onChange={set('phone')} error={errors.phone} maxLength={20} />
        <TextField id={fieldId('role')} label="Role" value={values.role} onChange={set('role')} error={errors.role} maxLength={100} help="For example: Field Officer, Accounts" list={`${fieldId('role')}-list`} />
        <datalist id={`${fieldId('role')}-list`}>
          {(options?.roles ?? []).filter((role) => role.label.endsWith('(employee role)')).map((role) => <option key={role.value} value={role.value} />)}
        </datalist>
        <SelectField id={fieldId('locationId')} label="Location (state)" value={values.locationId} onChange={set('locationId')} placeholder="Not set" options={(options?.locations ?? []).map((l) => ({ value: String(l.id), label: l.name }))} error={errors.locationId} />
        <SelectField id={fieldId('headquartersId')} label="Headquarters" value={values.headquartersId} onChange={set('headquartersId')} placeholder="Not set" options={(options?.headquarters ?? []).map((h) => ({ value: String(h.id), label: h.name }))} error={errors.headquartersId} />
        <SelectField id={fieldId('status')} label="Status" value={values.status} onChange={set('status')} options={STATUS} error={errors.status} help="Inactive employees do not receive announcements." />
      </div>
    </form>
  )
}

export default function EmployeesPage() {
  const canManage = usePermission('announcements.manage')
  const [rows, setRows] = useState(null)
  const [options, setOptions] = useState(null)
  const [error, setError] = useState(null)
  const [query, setQuery] = useState('')
  const [editing, setEditing] = useState(null)
  const [deleting, setDeleting] = useState(null)
  const [formKey, setFormKey] = useState(0)
  const [version, setVersion] = useState(0)

  useEffect(() => {
    let cancelled = false
    api('/api/employees').then((data) => !cancelled && setRows(data.employees)).catch((err) => !cancelled && setError(err.message))
    api('/api/announcements/options').then((data) => !cancelled && setOptions(data)).catch(() => {})
    return () => {
      cancelled = true
    }
  }, [version])

  const shown = useMemo(() => {
    const q = query.trim().toLowerCase()
    return (rows ?? []).filter((row) => !q || `${row.name} ${row.email ?? ''} ${row.phone ?? ''} ${row.role ?? ''}`.toLowerCase().includes(q))
  }, [rows, query])

  const open = (employee) => {
    setFormKey((key) => key + 1)
    setEditing(employee ?? 'new')
  }

  const columns = [
    { key: 'name', header: 'Name', render: (row) => <strong>{row.name}</strong> },
    { key: 'contact', header: 'Email / Phone', render: (row) => <span className="small">{[row.email, row.phone].filter(Boolean).join(' · ') || '\u2014'}</span> },
    { key: 'role', header: 'Role', render: (row) => row.role || '\u2014' },
    { key: 'place', header: 'Location / HQ', render: (row) => <span className="small">{[row.locationName, row.headquartersName].filter(Boolean).join(' · ') || '\u2014'}</span> },
    { key: 'status', header: 'Status', nowrap: true, render: (row) => <StatusBadge variant={row.status === 'active' ? 'success' : 'secondary'}>{row.status === 'active' ? 'Active' : 'Inactive'}</StatusBadge> },
    ...(canManage ? [{
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (row) => (
        <div className="btn-group btn-group-sm" role="group" aria-label={`${row.name} actions`}>
          <button type="button" className="btn btn-outline-secondary" onClick={() => open(row)}>Edit</button>
          <button type="button" className="btn btn-outline-danger" onClick={() => setDeleting(row)}>Delete</button>
        </div>
      ),
    }] : []),
  ]

  return (
    <PageShell actions={canManage && <button type="button" className="btn btn-primary" onClick={() => open(null)}><i className="bi bi-person-plus" aria-hidden="true" /> Add employee</button>}>
      {error && <div className="alert alert-danger" role="alert">{error}</div>}
      <TableCard title={`Employees (${shown.length})`} toolbar={<SearchInput value={query} onChange={setQuery} label="Search employees" placeholder="Search name, email, phone or role" />}>
        <DataTable caption="Employees" columns={columns} rows={shown} emptyText={rows === null ? 'Loading...' : 'No employees yet.'} />
      </TableCard>
      <FormModal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing && editing !== 'new' ? 'Edit employee' : 'Add employee'}
        submitLabel="Save"
        variant="wide"
        renderForm={(formId) => (
          <EmployeeForm
            key={formKey}
            formId={formId}
            employee={editing && editing !== 'new' ? editing : null}
            options={options}
            onSaved={() => {
              setEditing(null)
              setVersion((v) => v + 1)
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
          await api(`/api/employees/${deleting.id}/delete`, { method: 'POST', body: {} })
          setVersion((v) => v + 1)
        }}
      >
        Remove {deleting?.name} from the employee list? Announcements already sent to them keep their record.
      </ConfirmDialog>
    </PageShell>
  )
}