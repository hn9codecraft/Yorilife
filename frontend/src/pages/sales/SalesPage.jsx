import { useId, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import FormModal from '../../components/FormModal.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { byName, currentMonth, findById, formatMonth, formatNumber } from '../../data/selectors.js'

// Amount fields. Values are stored exactly as entered — nothing is calculated,
// because the formula linking them has not been confirmed.
const AMOUNT_FIELDS = [
  { key: 'achievementValue', label: 'Achievement value' },
  { key: 'bonus', label: 'Bonus' },
  { key: 'deduction', label: 'Deduction' },
  { key: 'finalValue', label: 'Final value' },
]

const toNumberOrNull = (value) => (value === '' || value === null || value === undefined ? null : Number(value))

function SalesEntryForm({ formId, record, state, onSave }) {
  const form = useFormState(
    {
      month: record?.month ?? currentMonth(),
      headquartersId: record?.headquartersId ?? '',
      stockistId: record?.stockistId ?? '',
      ...Object.fromEntries(AMOUNT_FIELDS.map(({ key }) => [key, record?.[key] ?? ''])),
    },
    (v) => {
      const errors = {}
      if (!v.month) errors.month = 'Choose the sales month.'
      if (!v.headquartersId) errors.headquartersId = 'Select a headquarters.'
      if (!v.stockistId) errors.stockistId = 'Select a stockist.'
      AMOUNT_FIELDS.forEach(({ key, label }) => {
        if (v[key] !== '' && !Number.isFinite(Number(v[key]))) errors[key] = `${label} must be a number.`
      })
      return errors
    },
  )

  const headquarters = [...state.headquarters].sort(byName)
  const stockists = state.stockists.filter((s) => s.headquartersId === form.values.headquartersId).sort(byName)

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((v) =>
        onSave({
          month: v.month,
          headquartersId: v.headquartersId,
          stockistId: v.stockistId,
          ...Object.fromEntries(AMOUNT_FIELDS.map(({ key }) => [key, toNumberOrNull(v[key])])),
        }),
      )}
    >
      <TextField id={form.fieldId('month')} type="month" label="Sales month" required value={form.values.month} onChange={form.set('month')} error={form.errors.month} className="col-12 col-md-4" />
      <SelectField
        id={form.fieldId('headquartersId')}
        label="Headquarters"
        required
        className="col-12 col-md-4"
        value={form.values.headquartersId}
        onChange={(value) => form.setMany({ headquartersId: value, stockistId: '' })}
        options={headquarters.map((hq) => ({ value: hq.id, label: hq.name }))}
        placeholder="Select headquarters"
        error={form.errors.headquartersId}
      />
      <SelectField
        id={form.fieldId('stockistId')}
        label="Stockist"
        required
        className="col-12 col-md-4"
        value={form.values.stockistId}
        onChange={form.set('stockistId')}
        options={stockists.map((s) => ({ value: s.id, label: s.name }))}
        placeholder={!form.values.headquartersId ? 'Select headquarters first' : stockists.length ? 'Select stockist' : 'No stockists for this HQ'}
        disabled={!form.values.headquartersId || stockists.length === 0}
        error={form.errors.stockistId}
      />

      <div className="col-12">
        <div className="alert alert-info mb-0" role="note">
          <i className="bi bi-info-circle-fill" aria-hidden="true" />
          <div>
            Amounts are stored as entered, in rupees with two decimal places. <strong>Final value is not calculated</strong> from achievement, bonus or deduction.
          </div>
        </div>
      </div>

      {AMOUNT_FIELDS.map(({ key, label }) => (
        <TextField
          key={key}
          id={form.fieldId(key)}
          type="number"
          step="0.01"
          inputMode="decimal"
          label={label}
          value={form.values[key]}
          onChange={form.set(key)}
          error={form.errors[key]}
          className="col-12 col-sm-6 col-lg-3"
        />
      ))}
    </form>
  )
}

const VARIANTS = {
  // Sales data entry + full listing
  entry: { filters: ['month', 'headquarters'], editable: true },
  monthly: { filters: ['month'], requireFilter: 'month', defaultMonth: true },
  byHeadquarters: { filters: ['headquarters'], requireFilter: 'headquarters', hideColumns: ['headquarters'] },
  byStockist: { filters: ['headquarters', 'stockist'], requireFilter: 'stockist', hideColumns: ['headquarters', 'stockist'] },
}

/**
 * Sales screens share one table so Achievement / Bonus / Deduction / Final
 * value always appear the same way. The variant decides filters and editing.
 */
export default function SalesPage({ variant }) {
  const config = VARIANTS[variant]
  const { state, actions } = useErpData()
  const canCreate = usePermission('sales.create')
  const canUpdate = usePermission('sales.update')
  const editor = useEditor()
  const ids = useId()
  const [month, setMonth] = useState(config.defaultMonth ? currentMonth() : '')
  const [headquartersId, setHeadquartersId] = useState('')
  const [stockistId, setStockistId] = useState('')

  const headquarters = [...state.headquarters].sort(byName)
  const stockistsInHq = state.stockists.filter((s) => s.headquartersId === headquartersId).sort(byName)

  const filterValues = { month, headquarters: headquartersId, stockist: stockistId }
  const waitingForFilter = config.requireFilter && !filterValues[config.requireFilter]

  const rows = waitingForFilter
    ? []
    : state.sales
        .filter((s) => (!month || s.month === month) && (!headquartersId || s.headquartersId === headquartersId) && (!stockistId || s.stockistId === stockistId))
        .sort((a, b) => b.month.localeCompare(a.month) || a.createdAt.localeCompare(b.createdAt))

  const columns = [
    { key: 'month', header: 'Month', nowrap: true, render: (s) => formatMonth(s.month) },
    { key: 'headquarters', header: 'Headquarters', render: (s) => findById(state.headquarters, s.headquartersId)?.name ?? '—' },
    { key: 'stockist', header: 'Stockist', render: (s) => findById(state.stockists, s.stockistId)?.name ?? '—' },
    ...AMOUNT_FIELDS.map(({ key, label }) => ({ key, header: label, align: 'end', nowrap: true, render: (s) => formatNumber(s[key]) })),
    config.editable && canUpdate && {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      render: (s) => (
        <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(s)}>
          <i className="bi bi-pencil" aria-hidden="true" /> Edit<span className="visually-hidden"> entry for {formatMonth(s.month)}</span>
        </button>
      ),
    },
  ].filter((column) => column && !config.hideColumns?.includes(column.key))

  const canEnter = state.stockists.length > 0
  const emptyText = waitingForFilter
    ? config.requireFilter === 'month' ? 'Choose a month to see its sales entries.'
      : config.requireFilter === 'headquarters' ? 'Choose a headquarters to see its sales entries.'
        : 'Choose a headquarters and then a stockist to see its sales entries.'
    : state.sales.length === 0 ? 'No sales entries yet.' : 'No sales entries match these filters.'

  return (
    <PageShell
      actions={
        config.editable && canCreate && (
          <>
            <Link to="/imports/data?type=sales" className="btn btn-outline-secondary">
              <i className="bi bi-filetype-csv" aria-hidden="true" /> Import CSV
            </Link>
            <button type="button" className="btn btn-primary" onClick={editor.openNew} disabled={!canEnter}>
              <i className="bi bi-plus-lg" aria-hidden="true" /> Add sales entry
            </button>
          </>
        )
      }
    >
      <DataStatusNotice />

      {config.editable && canCreate && state.status === 'ready' && !canEnter && (
        <div className="alert alert-info" role="note">
          <i className="bi bi-info-circle-fill" aria-hidden="true" />
          <div>
            Sales are recorded per stockist. Add a <Link to="/sales-office/headquarters">headquarters</Link> and
            a <Link to="/sales-office/stockists">stockist</Link> first.
          </div>
        </div>
      )}

      <TableCard
        title={`Sales entries (${rows.length})`}
        toolbar={
          <>
            {config.filters.includes('month') && (
              <div className="toolbar__field">
                <label htmlFor={`${ids}-month`} className="visually-hidden">Month</label>
                <input id={`${ids}-month`} type="month" className="form-control toolbar__select" value={month} onChange={(e) => setMonth(e.target.value)} />
              </div>
            )}
            {config.filters.includes('headquarters') && (
              <>
                <label htmlFor={`${ids}-hq`} className="visually-hidden">Headquarters</label>
                <select
                  id={`${ids}-hq`}
                  className="form-select toolbar__select"
                  value={headquartersId}
                  onChange={(e) => { setHeadquartersId(e.target.value); setStockistId('') }}
                >
                  <option value="">{config.requireFilter ? 'Choose headquarters' : 'All headquarters'}</option>
                  {headquarters.map((hq) => <option key={hq.id} value={hq.id}>{hq.name}</option>)}
                </select>
              </>
            )}
            {config.filters.includes('stockist') && (
              <>
                <label htmlFor={`${ids}-stockist`} className="visually-hidden">Stockist</label>
                <select
                  id={`${ids}-stockist`}
                  className="form-select toolbar__select"
                  value={stockistId}
                  onChange={(e) => setStockistId(e.target.value)}
                  disabled={!headquartersId}
                >
                  <option value="">{headquartersId ? 'Choose stockist' : 'Choose headquarters first'}</option>
                  {stockistsInHq.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                </select>
              </>
            )}
          </>
        }
      >
        <DataTable caption="Sales entries" columns={columns} rows={rows} emptyText={emptyText} />
      </TableCard>

      {config.editable && (
        <FormModal
          open={editor.open}
          onClose={editor.close}
          title={editor.record ? 'Edit sales entry' : 'Add sales entry'}
          size="lg"
          renderForm={(formId) => (
            <SalesEntryForm
              key={editor.session}
              formId={formId}
              record={editor.record}
              state={state}
              onSave={async (values) => {
                await actions.saveRecord('sales', { ...editor.record, ...values })
                editor.close()
              }}
            />
          )}
        />
      )}
    </PageShell>
  )
}
