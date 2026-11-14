import { useId, useMemo, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import FormModal from '../../components/FormModal.jsx'
import { FormRow, LabelNote, TextField } from '../../components/FormField.jsx'
import SearchableSelect from '../../components/SearchableSelect.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { currentMonth, findById, formatMonth, formatNumber } from '../../data/selectors.js'
import { findHeadquarter, headquarterOptions, stockistLabel, stockistOptions } from '../../data/stockists.js'

// Amount fields. Values are stored exactly as entered — nothing is calculated,
// because the formula linking them has not been confirmed.
const AMOUNT_FIELDS = [
  { key: 'achievementValue', label: 'Achievement value' },
  { key: 'bonus', label: 'Bonus' },
  { key: 'deduction', label: 'Deduction' },
  { key: 'finalValue', label: 'Final value' },
]

const toNumberOrNull = (value) => (value === '' || value === null || value === undefined ? null : Number(value))

/** The Headquarter shown for an entry: the one saved on it, else its stockist's. */
function entryHeadquarter(entry, state, locations) {
  const stockist = findById(state.stockists, entry.stockistId)
  return findHeadquarter(locations, entry.locationHeadquartersId ?? stockist?.locationHeadquartersId)
}

function SalesEntryForm({ formId, record, state, locations, onSave }) {
  const form = useFormState(
    {
      month: record?.month ?? currentMonth(),
      stockistId: record?.stockistId ? String(record.stockistId) : '',
      ...Object.fromEntries(AMOUNT_FIELDS.map(({ key }) => [key, record?.[key] ?? ''])),
    },
    (v) => {
      const errors = {}
      if (!v.month) errors.month = 'Choose the sales month.'
      if (!v.stockistId) errors.stockistId = 'Select a stockist.'
      AMOUNT_FIELDS.forEach(({ key, label }) => {
        if (v[key] !== '' && !Number.isFinite(Number(v[key]))) errors[key] = `${label} must be a number.`
      })
      return errors
    },
  )

  const options = useMemo(() => stockistOptions(state.stockists, locations), [state.stockists, locations])
  const stockist = findById(state.stockists, form.values.stockistId)
  const sameStockist = record && String(record.stockistId) === form.values.stockistId
  const office = findHeadquarter(locations, sameStockist ? (record.locationHeadquartersId ?? stockist?.locationHeadquartersId) : stockist?.locationHeadquartersId)

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((v) =>
        onSave({
          month: v.month,
          stockistId: v.stockistId,
          ...Object.fromEntries(AMOUNT_FIELDS.map(({ key }) => [key, toNumberOrNull(v[key])])),
        }),
      )}
    >
      <FormRow>
        <TextField id={form.fieldId('month')} type="month" label="Sales Month" required value={form.values.month} onChange={form.set('month')} error={form.errors.month} className="col-12 col-md-4" />
        <SearchableSelect
          id={form.fieldId('stockistId')}
          label={<LabelNote label="Stockist" note="search name, code or headquarter" />}
          ariaLabel="Stockist"
          required
          value={form.values.stockistId}
          onChange={form.set('stockistId')}
          options={options}
          placeholder={options.length ? 'Search or select stockist' : 'No stockists yet'}
          noResultsText="No stockist matches"
          missingLabel="Stockist no longer listed"
          error={form.errors.stockistId}
          disabled={options.length === 0}
          className="col-12 col-md-8"
        />
      </FormRow>
      <FormRow>
        <TextField
          id={form.fieldId('headquarter')}
          label={<LabelNote label="Headquarter" note="from the stockist" />}
          value={office?.name ?? ''}
          onChange={() => {}}
          placeholder={stockist ? 'Stockist has no headquarter' : 'Select a stockist'}
          readOnly
          tabIndex={-1}
          className="col-12 col-md-6"
        />
        <TextField
          id={form.fieldId('state')}
          label={<LabelNote label="State" note="from the headquarter" />}
          value={office?.stateName ?? ''}
          onChange={() => {}}
          placeholder="—"
          readOnly
          tabIndex={-1}
          className="col-12 col-md-6"
        />
      </FormRow>

      <div className="col-12">
        <div className="alert alert-info mb-0" role="note">
          <i className="bi bi-info-circle-fill" aria-hidden="true" />
          <div>
            Amounts are stored as entered, in rupees with two decimal places. <strong>Final value is not calculated</strong> from achievement, bonus or deduction.
          </div>
        </div>
      </div>

      <FormRow>
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
      </FormRow>
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
 * Headquarters come from the State / Headquarter List (via the stockist).
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

  const locations = useMemo(() => state.locations ?? [], [state.locations])
  const headquarters = useMemo(() => headquarterOptions(locations), [locations])
  const stockistChoices = useMemo(() => stockistOptions(state.stockists, locations, headquartersId), [state.stockists, locations, headquartersId])

  const filterValues = { month, headquarters: headquartersId, stockist: stockistId }
  const waitingForFilter = config.requireFilter && !filterValues[config.requireFilter]

  const rows = waitingForFilter
    ? []
    : state.sales
        .filter((s) => (!month || s.month === month)
          && (!headquartersId || String(entryHeadquarter(s, state, locations)?.id ?? '') === headquartersId)
          && (!stockistId || String(s.stockistId) === stockistId))
        .sort((a, b) => b.month.localeCompare(a.month) || a.createdAt.localeCompare(b.createdAt))

  const columns = [
    { key: 'month', header: 'Month', nowrap: true, render: (s) => formatMonth(s.month) },
    { key: 'headquarters', header: 'Headquarter', render: (s) => entryHeadquarter(s, state, locations)?.name ?? '—' },
    { key: 'stockist', header: 'Stockist', render: (s) => stockistLabel(findById(state.stockists, s.stockistId)) || '—' },
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
      : config.requireFilter === 'headquarters' ? 'Choose a headquarter to see its sales entries.'
        : 'Choose a stockist to see its sales entries.'
    : state.sales.length === 0 ? 'No sales entries yet.' : 'No sales entries match these filters.'

  return (
    <PageShell
      actions={
        config.editable && canCreate && (
          <button type="button" className="btn btn-primary" onClick={editor.openNew} disabled={!canEnter}>
            <i className="bi bi-plus-lg" aria-hidden="true" /> Add sales entry
          </button>
        )
      }
    >
      <DataStatusNotice />

      {config.editable && canCreate && state.status === 'ready' && !canEnter && (
        <div className="alert alert-info" role="note">
          <i className="bi bi-info-circle-fill" aria-hidden="true" />
          <div>
            Sales are recorded per stockist. Add a <Link to="/sales-office/stockists">stockist</Link> first.
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
                <input id={`${ids}-month`} type="month" className="form-control toolbar__select" value={month} data-empty={String(!month)} onChange={(e) => setMonth(e.target.value)} />
              </div>
            )}
            {config.filters.includes('headquarters') && (
              <SearchableSelect
                id={`${ids}-hq`}
                label="Headquarter"
                labelHidden
                value={headquartersId}
                onChange={(value) => { setHeadquartersId(value); setStockistId('') }}
                options={headquarters}
                placeholder={config.requireFilter === 'headquarters' ? 'Choose headquarter' : 'All headquarters'}
                noResultsText="No headquarter matches"
                className="toolbar__field searchable-select--toolbar"
              />
            )}
            {config.filters.includes('stockist') && (
              <SearchableSelect
                id={`${ids}-stockist`}
                label="Stockist"
                labelHidden
                value={stockistId}
                onChange={setStockistId}
                options={stockistChoices}
                placeholder="Choose stockist"
                noResultsText="No stockist matches"
                className="toolbar__field searchable-select--toolbar"
              />
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
          variant="wide"
          renderForm={(formId) => (
            <SalesEntryForm
              key={editor.session}
              formId={formId}
              record={editor.record}
              state={state}
              locations={locations}
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