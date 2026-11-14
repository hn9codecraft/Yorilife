import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataTable from '../../components/DataTable.jsx'
import ExportCsvButton from '../../components/ExportCsvButton.jsx'
import FilterBar from '../../components/FilterBar.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import LoadingIndicator from '../../components/LoadingIndicator.jsx'
import SearchableSelect from '../../components/SearchableSelect.jsx'
import TableSection from '../../components/TableSection.jsx'
import { CellStack } from '../../components/TableCells.jsx'
import { LabelNote, MultiSelectField } from '../../components/FormField.jsx'
import useFormState from '../../hooks/useFormState.js'
import usePermission from '../../data/usePermission.js'
import { api } from '../../api/client.js'
import { formatMoney } from '../../data/productList.js'
import {
  BONUS_CSV,
  DEFAULT_FINANCIAL_YEAR,
  MONTHS,
  bonusCsvRecord,
  employeeOptionLabel,
  filterBonusRows,
  financialYearOptions,
  isFinancialYear,
} from '../../data/bonusCalc.js'

const EMPTY_ROWS = []

function employeeOptions(employees) {
  return employees.map((employee) => ({
    value: String(employee.id),
    label: employeeOptionLabel(employee),
    keywords: [employee.employeeCode, employee.name].filter(Boolean),
  }))
}

function AddEmployeesForm({ formId, employees, onSave }) {
  const form = useFormState({ employeeIds: [] }, (values) => (
    values.employeeIds.length ? {} : { employeeIds: 'Choose at least one employee.' }
  ))

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({ employeeIds: values.employeeIds }))}
    >
      <MultiSelectField
        id={form.fieldId('employeeIds')}
        label={<LabelNote label="Employees" note="from Employee List" />}
        ariaLabel="Employees"
        value={form.values.employeeIds}
        onChange={form.set('employeeIds')}
        options={employeeOptions(employees)}
        placeholder="None selected"
        emptyText="No employees to add. Everyone active is already on this financial year, or the Employee List is empty."
        error={form.errors.employeeIds}
      />
    </form>
  )
}

function EditEmployeeForm({ formId, row, employees, onSave }) {
  const form = useFormState(
    { employeeId: String(row.employeeId) },
    (values) => (values.employeeId ? {} : { employeeId: 'Choose an employee.' }),
  )

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({ employeeId: values.employeeId }))}
    >
      <SearchableSelect
        id={form.fieldId('employeeId')}
        label={<LabelNote label="Employee" note="from Employee List" />}
        ariaLabel="Employee"
        value={form.values.employeeId}
        onChange={form.set('employeeId')}
        options={employeeOptions(employees)}
        placeholder="Search name or employee code"
        noResultsText="No employee matches"
        error={form.errors.employeeId}
      />
    </form>
  )
}

export default function BonusCalcPage() {
  const canManage = usePermission('sales.manage')
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const requested = params.get('year') || DEFAULT_FINANCIAL_YEAR
  const year = isFinancialYear(requested) ? requested : DEFAULT_FINANCIAL_YEAR
  const [data, setData] = useState(null)
  const [status, setStatus] = useState('loading')
  const [error, setError] = useState(null)
  const [reloadToken, setReloadToken] = useState(0)
  const [query, setQuery] = useState('')
  const [adding, setAdding] = useState(false)
  const [editing, setEditing] = useState(null)
  const [deleting, setDeleting] = useState(null)

  useEffect(() => {
    const controller = new AbortController()
    setStatus('loading')
    setError(null)
    api(`/api/bonus-calc?financialYear=${encodeURIComponent(year)}`, { signal: controller.signal })
      .then((payload) => {
        setData(payload)
        setStatus('ready')
      })
      .catch((err) => {
        if (err.name === 'AbortError') return
        setError(err)
        setStatus('error')
      })
    return () => controller.abort()
  }, [year, reloadToken])

  const rows = data?.rows ?? EMPTY_ROWS
  const employees = data?.employees ?? []
  const taken = useMemo(() => new Set(rows.map((row) => String(row.employeeId))), [rows])
  const available = employees.filter((employee) => !taken.has(String(employee.id)))
  const shown = useMemo(() => filterBonusRows(rows, query), [rows, query])
  const years = financialYearOptions(data?.financialYear ? [data.financialYear] : [])

  const columns = [
    {
      key: 'employee',
      header: 'Employee',
      sticky: 'start',
      title: (row) => [row.employeeName, row.employeeCode || `ID ${row.employeeId}`].filter(Boolean).join(' · '),
      render: (row) => (
        <CellStack strong primary={row.employeeName} secondary={row.employeeCode || `ID ${row.employeeId}`} />
      ),
    },
    ...MONTHS.map((month) => ({
      key: month.short,
      header: month.short,
      width: 'money',
      render: (row) => {
        const amount = row.months?.[String(month.value)] ?? null
        const label = amount == null
          ? `Open ${month.label} for ${row.employeeName}, no figures yet`
          : `Open ${month.label} for ${row.employeeName}`
        return (
          <button
            type="button"
            className="bonus-month"
            aria-label={label}
            onClick={() => navigate(`/sales/bonus-calc/${year}/${row.employeeId}/${month.value}`)}
          >
            {amount == null ? <span className="bonus-month__empty">-</span> : formatMoney(amount)}
          </button>
        )
      },
    })),
    {
      key: 'total',
      header: 'Total',
      width: 'money',
      render: (row) => (row.total == null ? <span className="bonus-month__empty">-</span> : formatMoney(row.total)),
    },
    ...(canManage ? [{
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      sticky: 'end',
      render: (row) => (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${row.employeeName}`} title="Edit" onClick={() => setEditing(row)}>
            <i className="bi bi-pencil" aria-hidden="true" />
          </button>
          <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${row.employeeName}`} title="Delete" onClick={() => setDeleting(row)}>
            <i className="bi bi-trash" aria-hidden="true" />
          </button>
        </div>
      ),
    }] : []),
  ]

  const editChoices = editing
    ? employees.filter((employee) => String(employee.id) === String(editing.employeeId) || !taken.has(String(employee.id)))
    : []

  return (
    <PageShell
      actions={canManage && (
        <button type="button" className="btn btn-primary" onClick={() => setAdding(true)}>
          <i className="bi bi-plus-lg" aria-hidden="true" /> Add Employee
        </button>
      )}
    >
      {status === 'loading' && <LoadingIndicator label="Loading Bonus Calc…" />}
      {status === 'error' && (
        <div className="alert alert-danger" role="alert">
          <i className="bi bi-exclamation-octagon-fill" aria-hidden="true" />
          <div>
            {error?.message || 'Bonus Calc could not be loaded.'}{' '}
            <button type="button" className="btn btn-sm btn-outline-danger ms-2" onClick={() => setReloadToken((token) => token + 1)}>Try again</button>
          </div>
        </div>
      )}
      {status === 'ready' && (
        <TableSection title={`Financial Year ${year}`} count={rows.length}>
          <FilterBar
            label="Filter Bonus Calc"
            search={{ value: query, onChange: setQuery, label: 'Search employees', placeholder: 'Search name or employee code' }}
            shown={shown.length}
            total={rows.length}
            noun="employees"
            actions={(
              <ExportCsvButton
                name={`${BONUS_CSV.filename}-${year}`}
                headers={BONUS_CSV.headers}
                auditKind="bonus_calc_csv"
                mask={false}
                records={shown.map(bonusCsvRecord)}
              />
            )}
          >
            <SearchableSelect
              id="bonus-calc-year"
              label="Financial year"
              labelHidden
              ariaLabel="Financial year"
              value={year}
              clearable={false}
              onChange={(value) => setParams(value ? { year: value } : {})}
              options={years.map((item) => ({ value: item, label: item }))}
              placeholder="Financial year"
              className="filter-bar__control"
            />
          </FilterBar>
          <DataTable
            caption={`Financial Year ${year}`}
            striped
            scroll
            columns={columns}
            rows={shown}
            emptyIcon={rows.length ? 'bi-funnel' : 'bi-calculator'}
            emptyText={rows.length ? 'No employees match your search' : `No employees in Financial Year ${year}`}
            emptyHint={rows.length ? 'Change the search to see more employees.' : 'Add employees from the Sales Office Employee List. Removing someone here does not delete the employee.'}
            emptyAction={rows.length
              ? <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => setQuery('')}>Clear search</button>
              : canManage && (
                <button type="button" className="btn btn-primary btn-sm" onClick={() => setAdding(true)}>
                  <i className="bi bi-plus-lg" aria-hidden="true" /> Add Employee
                </button>
              )}
          />
        </TableSection>
      )}

      <FormModal
        size="lg"
        variant="wide"
        open={adding}
        onClose={() => setAdding(false)}
        title="Add employees"
        submitLabel="Add"
        renderForm={(formId) => (
          <AddEmployeesForm
            key={year}
            formId={formId}
            employees={available}
            onSave={async (values) => {
              const payload = await api('/api/bonus-calc/rows', {
                method: 'POST',
                body: { financialYear: year, employeeIds: values.employeeIds },
              })
              setData(payload)
              setAdding(false)
            }}
          />
        )}
      />

      <FormModal
        size="lg"
        variant="wide"
        open={Boolean(editing)}
        onClose={() => setEditing(null)}
        title="Edit employee"
        renderForm={(formId) => editing && (
          <EditEmployeeForm
            key={editing.id}
            formId={formId}
            row={editing}
            employees={editChoices}
            onSave={async (values) => {
              const payload = await api(`/api/bonus-calc/rows/${editing.id}`, {
                method: 'PATCH',
                body: values,
              })
              setData(payload)
              setEditing(null)
            }}
          />
        )}
      />

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title="Remove from Bonus Calc"
        confirmLabel="Remove"
        onConfirm={async () => {
          const payload = await api(`/api/bonus-calc/rows/${deleting.id}/delete`, { method: 'POST', body: {} })
          setData(payload)
        }}
      >
        Remove {deleting?.employeeName} from Financial Year {year}? The employee stays on the Employee List. Month figures already entered are kept if you add them again.
      </ConfirmDialog>
    </PageShell>
  )
}
