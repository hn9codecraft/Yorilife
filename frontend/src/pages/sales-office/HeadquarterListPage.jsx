import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SelectionBar from '../../components/SelectionBar.jsx'
import FilterBar from '../../components/FilterBar.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import { LabelNote, TextField } from '../../components/FormField.jsx'
import SearchableSelect from '../../components/SearchableSelect.jsx'
import SalesOfficeCsv from '../../components/SalesOfficeCsv.jsx'
import { CellStack } from '../../components/TableCells.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import useFormState from '../../hooks/useFormState.js'
import useUrlFilters from '../../hooks/useUrlFilters.js'
import { api } from '../../api/client.js'
import { formatDateTime, headquarterUsage } from '../../data/headquarterList.js'

const FILTER_DEFAULTS = Object.freeze({ q: '', state: '' })

/**
 * Add / edit a headquarter: only the state and the name.
 * Code, address, pincode, phone and email are no longer edited here; values
 * already stored stay in the database (the form does not send them).
 */
function HeadquarterForm({ formId, record, locations, offices, onSave }) {
  const form = useFormState(
    {
      locationId: record?.locationId != null ? String(record.locationId) : '',
      name: record?.name ?? '',
    },
    (values) => {
      const errors = {}
      const name = values.name.trim()
      if (!locations.some((item) => String(item.id) === String(values.locationId))) errors.locationId = 'Choose a state from the list.'
      if (!name) errors.name = 'Enter the headquarter name.'
      else if (offices.some((office) => office.id !== record?.id && String(office.locationId) === String(values.locationId) && (office.name ?? '').trim().toLowerCase() === name.toLowerCase())) {
        errors.name = 'This headquarter name already exists for the state.'
      }
      return errors
    },
  )
  const stateOptions = locations.map((location) => ({ value: String(location.id), label: location.name, keywords: [location.code].filter(Boolean) }))

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({ locationId: values.locationId, name: values.name.trim() }))}
    >
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
      <TextField
        id={form.fieldId('name')}
        label={<LabelNote label="Headquarter Name" note="unique within the state" />}
        required
        value={form.values.name}
        onChange={form.set('name')}
        error={form.errors.name}
        maxLength={150}
        autoComplete="off"
        className="col-12 col-md-6"
      />
    </form>
  )
}

export default function HeadquarterListPage() {
  const { state, actions } = useErpData()
  const canManage = usePermission('sales_office.manage')
  const editor = useEditor()
  const [filters, setFilter, clearFilters] = useUrlFilters(FILTER_DEFAULTS)
  const [deleting, setDeleting] = useState(null)
  const [deletingMany, setDeletingMany] = useState(false)
  const [employees, setEmployees] = useState(null)

  // Employee counts per headquarter (the Employee List is loaded separately).
  useEffect(() => {
    let cancelled = false
    api('/api/sales-employees')
      .then((data) => { if (!cancelled) setEmployees(data.employees ?? []) })
      .catch(() => { if (!cancelled) setEmployees(null) })
    return () => { cancelled = true }
  }, [])

  const locations = useMemo(() => state.locations ?? [], [state.locations])
  const offices = useMemo(() => locations.flatMap((location) => (location.headquarters ?? []).map((office) => ({
    ...office,
    state: location.name,
    stateCode: location.code,
    locationId: location.id,
  }))), [locations])
  const usage = useMemo(() => headquarterUsage(state.stockists, employees), [state.stockists, employees])

  const q = filters.q.trim().toLowerCase()
  const rows = offices.filter((office) => {
    if (filters.state && String(office.locationId) !== filters.state) return false
    return !q || [office.name, office.state, office.stateCode].filter(Boolean).join(' ').toLowerCase().includes(q)
  })
  const selection = useRowSelection(rows)

  const stateOptions = locations.map((location) => ({ value: String(location.id), label: location.name, keywords: [location.code].filter(Boolean) }))
  const chips = [
    filters.q && { key: 'q', label: `Search: ${filters.q}` },
    filters.state && { key: 'state', label: `State: ${stateOptions.find((option) => option.value === filters.state)?.label ?? filters.state}` },
  ].filter(Boolean).map((chip) => ({ ...chip, onRemove: () => setFilter(chip.key, '') }))

  const countOf = (office, key) => usage[key].get(String(office.id)) ?? 0
  const employeesKnown = employees !== null

  const columns = [
    { key: 'name', header: 'Headquarter', width: 'lg', sticky: 'start', render: (office) => <CellStack strong primary={office.name} /> },
    { key: 'state', header: 'State', width: 'md', render: (office) => <CellStack primary={office.state} secondary={office.stateCode} /> },
    {
      key: 'stockists',
      header: 'Stockists',
      width: 'sm',
      align: 'end',
      render: (office) => {
        const count = countOf(office, 'stockists')
        return count > 0
          ? <Link to={`/sales-office/stockists?hq=${office.id}`} className="table-count-link" title={`Show the ${count} stockists of ${office.name}`}>{count}</Link>
          : <span className="table-count is-zero">0</span>
      },
    },
    {
      key: 'employees',
      header: 'Employees',
      width: 'sm',
      align: 'end',
      render: (office) => (employeesKnown ? <span className={`table-count${countOf(office, 'employees') ? '' : ' is-zero'}`}>{countOf(office, 'employees')}</span> : null),
    },
    {
      key: 'updated',
      header: 'Last Updated',
      width: 'md',
      render: (office) => (office.updatedAt
        ? <CellStack primary={formatDateTime(office.updatedAt)} secondary={office.createdAt ? `Created ${formatDateTime(office.createdAt)}` : null} />
        : null),
    },
    {
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      sticky: 'end',
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
  ]

  return (
    <PageShell
      actions={canManage && (
        <button type="button" className="btn btn-primary" onClick={editor.openNew}>
          <i className="bi bi-plus-lg" aria-hidden="true" /> Add headquarter
        </button>
      )}
    >
      <DataStatusNotice />
      <TableCard title={`Headquarters (${offices.length})`}>
        <FilterBar
          label="Filter headquarters"
          search={{ value: filters.q, onChange: (value) => setFilter('q', value), label: 'Search headquarters', placeholder: 'Search headquarter or state' }}
          chips={chips}
          onClearAll={() => clearFilters()}
          shown={rows.length}
          total={offices.length}
          noun="headquarters"
          actions={(
            <SalesOfficeCsv
              module="headquarter"
              records={rows.map((office) => ({
                'Headquarter name': office.name ?? '',
                State: office.state ?? '',
                'State code': office.stateCode ?? '',
                Stockists: countOf(office, 'stockists'),
                Employees: employeesKnown ? countOf(office, 'employees') : '',
                'Last updated': office.updatedAt ? formatDateTime(office.updatedAt) : '',
              }))}
            />
          )}
        >
          <SearchableSelect
            id="headquarter-list-state"
            label="State"
            labelHidden
            value={filters.state}
            onChange={(value) => setFilter('state', value)}
            options={stateOptions}
            placeholder="All states"
            noResultsText="No state matches"
            className="filter-bar__control"
          />
        </FilterBar>
        {canManage && <SelectionBar count={selection.count} onDelete={() => setDeletingMany(true)} onClear={selection.clear} />}
        <DataTable
          caption="Headquarters"
          striped
          scroll
          selection={canManage ? {
            isSelected: (office) => selection.isSelected(office.id),
            onToggle: (office) => selection.toggle(office.id),
            onToggleAll: selection.toggleAll,
            allSelected: selection.allSelected,
            indeterminate: selection.indeterminate,
            label: (office) => `Select ${office.name}`,
          } : null}
          columns={columns}
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
          <HeadquarterForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            locations={locations}
            offices={offices}
            onSave={async (values) => {
              // Only id, state and name are sent, so stored code/address/contact values are kept.
              await actions.createLocationHeadquarters({ id: editor.record?.id, ...values })
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