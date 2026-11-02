import { useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SelectionBar from '../../components/SelectionBar.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import FormField, { SelectField, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import useFormState from '../../hooks/useFormState.js'
import { ALLOWED_PARENT_TYPES, MANAGER_LEVELS, PARENT_TYPES } from '../../data/constants.js'
import { managerCodePrefix, managerCodeSuffix, nextManagerSequence } from '../../data/managerCode.js'
import ListFilter from '../../components/ListFilter.jsx'
import SalesOfficeCsv from '../../components/SalesOfficeCsv.jsx'
import { byName, getManagerChain, getParent, managersAtLevel, matchesFilters, matchesSearch, sortByField } from '../../data/selectors.js'

// Records that can be chosen as a parent of the given type, with a label that
// shows where each one sits in the hierarchy.
function parentOptions(state, parentType) {
  return managersAtLevel(state, PARENT_TYPES[parentType].level)
    .sort(byName)
    .map((manager) => {
      const parent = getParent(state, manager)
      return { value: manager.id, label: parent ? `${manager.name} (${parent.name})` : manager.name }
    })
}

function StateListField({ id, locations, value, onChange, error, emptyText = 'No states yet. Add them on the Location page.' }) {
  const [query, setQuery] = useState('')
  const selected = new Set(value)
  const q = query.trim().toLowerCase()
  const choices = locations.filter((location) => location.name.toLowerCase().includes(q))
  const nameOf = (locationId) => locations.find((location) => location.id === locationId)?.name ?? 'Unknown state'
  const toggle = (locationId) => onChange(selected.has(locationId) ? value.filter((item) => item !== locationId) : [...value, locationId])

  return (
    <FormField id={id} label="State List" required error={error}>
      <div id={id} tabIndex={-1}>
        {value.length > 0 && (
          <div className="d-flex flex-wrap gap-1 mb-2">
            {value.map((locationId) => (
              <button key={locationId} type="button" className="badge text-secondary-emphasis bg-secondary-subtle border-0" onClick={() => toggle(locationId)}>
                {nameOf(locationId)} <span aria-hidden="true">×</span>
                <span className="visually-hidden">Remove {nameOf(locationId)}</span>
              </button>
            ))}
          </div>
        )}
        <input
          type="search"
          className={`form-control mb-2${error ? ' is-invalid' : ''}`}
          placeholder="Search states"
          aria-label="Search states"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
        <div className="border rounded p-2" style={{ maxHeight: '11rem', overflow: 'auto' }}>
          {locations.length === 0 ? (
            <p className="text-muted mb-0">{emptyText}</p>
          ) : choices.length === 0 ? (
            <p className="text-muted mb-0">No states match your search.</p>
          ) : choices.map((location) => (
            <div className="form-check" key={location.id}>
              <input
                className="form-check-input"
                type="checkbox"
                id={`${id}-${location.id}`}
                checked={selected.has(location.id)}
                onChange={() => toggle(location.id)}
              />
              <label className="form-check-label" htmlFor={`${id}-${location.id}`}>{location.name}</label>
            </div>
          ))}
        </div>
      </div>
    </FormField>
  )
}

function EmployeeCodeField({ id, level, value, onChange, error }) {
  const prefix = managerCodePrefix(level)
  return (
    <FormField id={id} label="Employee Code" required error={error} help="Numbers only. Leading zeros are kept.">
      <div className="input-group">
        <span className="input-group-text" id={`${id}-prefix`}>{prefix}</span>
        <input
          id={id}
          className={`form-control${error ? ' is-invalid' : ''}`}
          inputMode="numeric"
          autoComplete="off"
          maxLength={10}
          placeholder="001"
          aria-invalid={error ? 'true' : undefined}
          aria-required="true"
          aria-describedby={[`${id}-prefix`, `${id}-help`, error ? `${id}-error` : ''].filter(Boolean).join(' ')}
          value={value}
          onChange={(event) => onChange(event.target.value.replace(/\D/g, '').slice(0, 10))}
        />
      </div>
    </FormField>
  )
}

function ManagerForm({ formId, level, record, state, onSave }) {
  const allowedTypes = ALLOWED_PARENT_TYPES[level]
  const isRoot = allowedTypes.length === 0
  const prefix = managerCodePrefix(level)
  const legacyCode = record?.code && managerCodeSuffix(level, record.code) === null ? record.code : ''
  const form = useFormState(
    {
      name: record?.name ?? '',
      code: record ? (managerCodeSuffix(level, record.code) ?? '') : nextManagerSequence(state.managers, level),
      email: record?.email ?? '',
      phone: record?.phone ?? '',
      stateIds: (record?.states ?? []).map((item) => item.id),
      parentType: record?.parentType ?? allowedTypes[0] ?? '',
      parentId: record?.parentId ?? '',
    },
    (v) => {
      const errors = {}
      if (!v.name.trim()) errors.name = 'Enter the manager’s name.'
      const digits = v.code.trim()
      const fullCode = digits ? `${prefix}${digits}` : legacyCode
      if (!/^\d*$/.test(digits)) errors.code = 'Enter numbers only.'
      else if (!digits && !legacyCode) errors.code = 'Enter the employee code.'
      else if (state.managers.some((manager) => manager.id !== record?.id && manager.code && manager.code.trim().toLowerCase() === fullCode.toLowerCase())) {
        errors.code = 'This code is already in use.'
      }
      if (isRoot || level === 2 || level === 1) {
        const email = v.email.trim().toLowerCase()
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = 'Enter a valid email address.'
        else if (email && state.managers.some((manager) => manager.id !== record?.id && manager.email && manager.email.toLowerCase() === email)) {
          errors.email = 'This email address is already in use.'
        }
        const phone = v.phone.trim()
        if (phone && !/^\+?[0-9][0-9\s().-]{5,19}$/.test(phone)) errors.phone = 'Enter a valid phone number.'
      }
      if (isRoot && v.stateIds.length === 0) errors.stateIds = 'Select at least one state.'
      if (level === 1 || level === 2) {
        const allowed = new Set((state.managers.find((manager) => manager.id === v.parentId)?.states ?? []).map((item) => item.id))
        const parentLevel = level === 1 ? '2nd' : '3rd'
        if (v.stateIds.length === 0) errors.stateIds = 'Select at least one state.'
        else if (v.stateIds.some((id) => !allowed.has(id))) errors.stateIds = `Choose a state assigned to this ${parentLevel} line manager.`
      }
      if (!isRoot && !v.parentId) errors.parentId = `Select the ${PARENT_TYPES[v.parentType].label.toLowerCase()} this manager belongs to.`
      return errors
    },
  )
  const parentLabel = isRoot ? '' : PARENT_TYPES[form.values.parentType].label
  const options = isRoot ? [] : parentOptions(state, form.values.parentType)
  const employeeCode = form.values.code.trim() ? `${prefix}${form.values.code.trim()}` : legacyCode

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((v) => {
        const values = { name: v.name.trim(), code: employeeCode }
        if (isRoot) return onSave({ ...values, email: v.email.trim(), phone: v.phone.trim(), stateIds: v.stateIds })
        const child = { ...values, parentType: v.parentType, parentId: v.parentId }
        if (level === 1 || level === 2) {
          child.email = v.email.trim()
          child.phone = v.phone.trim()
          child.stateIds = v.stateIds
        }
        return onSave(child)
      })}
    >
      <TextField id={form.fieldId('name')} label="Name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} />
      <EmployeeCodeField id={form.fieldId('code')} level={level} value={form.values.code} onChange={form.set('code')} error={form.errors.code} />
      {(isRoot || level === 1 || level === 2) && (
        <>
          <TextField id={form.fieldId('email')} label="Email" type="email" value={form.values.email} onChange={form.set('email')} error={form.errors.email} help="Optional." />
          <TextField id={form.fieldId('phone')} label="Phone Number" value={form.values.phone} onChange={form.set('phone')} error={form.errors.phone} inputMode="tel" help="Optional. A leading zero or country code is kept." />
        </>
      )}
      {isRoot && (
        <StateListField id={form.fieldId('stateIds')} locations={state.locations ?? []} value={form.values.stateIds} onChange={form.set('stateIds')} error={form.errors.stateIds} />
      )}
      {!isRoot && allowedTypes.length > 1 && (
        <div className="col-12">
          <fieldset>
            <legend className="form-label">
              Reports to<span className="required" aria-hidden="true">*</span>
              <span className="visually-hidden"> (required)</span>
            </legend>
            <div className="choice-group">
              {allowedTypes.map((type) => (
                <div className="form-check" key={type}>
                  <input
                    className="form-check-input"
                    type="radio"
                    name={form.fieldId('parentType')}
                    id={form.fieldId(`parentType-${type}`)}
                    checked={form.values.parentType === type}
                    onChange={() => form.setMany({ parentType: type, parentId: '' })}
                  />
                  <label className="form-check-label" htmlFor={form.fieldId(`parentType-${type}`)}>
                    {PARENT_TYPES[type].label}
                  </label>
                </div>
              ))}
            </div>
          </fieldset>
        </div>
      )}

      {!isRoot && (
      <SelectField
        id={form.fieldId('parentId')}
        label={parentLabel}
        required
        value={form.values.parentId}
        onChange={(parentId) => {
          if (level !== 1 && level !== 2) {
            form.set('parentId')(parentId)
            return
          }
          const allowed = new Set((state.managers.find((manager) => manager.id === parentId)?.states ?? []).map((item) => item.id))
          form.setMany({ parentId, stateIds: form.values.stateIds.filter((id) => allowed.has(id)) })
        }}
        options={options}
        placeholder={options.length ? `Select ${parentLabel.toLowerCase()}` : `No ${parentLabel.toLowerCase()} records yet`}
        disabled={options.length === 0}
        error={form.errors.parentId}
        help={options.length === 0 ? `Add a ${parentLabel.toLowerCase()} first.` : undefined}
      />
      )}
      {(level === 1 || level === 2) && (
        <StateListField
          id={form.fieldId('stateIds')}
          locations={state.managers.find((manager) => manager.id === form.values.parentId)?.states ?? []}
          value={form.values.stateIds}
          onChange={form.set('stateIds')}
          error={form.errors.stateIds}
          emptyText={form.values.parentId
            ? `This ${level === 1 ? '2nd' : '3rd'} line manager has no states yet.`
            : `Select a ${level === 1 ? '2nd' : '3rd'} line manager first.`}
        />
      )}
    </form>
  )
}

export default function ManagersPage({ level }) {
  const { state, actions } = useErpData()
  const canManage = usePermission('sales_office.manage')
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [deleting, setDeleting] = useState(null)
  const [deletingMany, setDeletingMany] = useState(false)
  const [sort, setSort] = useState({ key: 'name', direction: 'asc' })
  const [filters, setFilters] = useState({ stateIds: [], reportIds: [] })
  const meta = MANAGER_LEVELS[level]

  const managers = managersAtLevel(state, level)
  const stateIdsFor = (manager) => (manager.states ?? []).map((item) => item.id)
  const matched = managers.filter((manager) => matchesSearch(manager, query) && matchesFilters(
    stateIdsFor(manager),
    level === 3 ? null : manager.parentId,
    filters,
  ))
  const rows = level === 3 ? matched.sort(byName) : sortByField(matched, sort.key, sort.direction)
  const selection = useRowSelection(rows)
  const toggleSort = (key) => setSort((current) => (
    current.key === key ? { key, direction: current.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'asc' }
  ))
  const canAdd = level === 3 || ALLOWED_PARENT_TYPES[level].some((type) => parentOptions(state, type).length > 0)
  const firstParentType = ALLOWED_PARENT_TYPES[level][0] ? PARENT_TYPES[ALLOWED_PARENT_TYPES[level][0]] : null
  const prerequisitePath = level === 1 ? '/sales-office/second-line-managers' : '/sales-office/third-line-managers'

  const reportsTo = {
    key: 'parent',
    header: level === 3 ? 'Report To' : 'Reports to',
    render: (m) => {
      const names = getManagerChain(state, m).slice(1).reverse().map((step) => step.record.name)
      return names.length ? names.join(' > ') : '—'
    },
  }
  const editAction = {
    key: 'actions',
    header: 'Actions',
    align: 'end',
    render: (m) => (
      canManage ? (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${m.name}`} title="Edit" onClick={() => editor.openEdit(m)}>
            <i className="bi bi-pencil" aria-hidden="true" />
          </button>
          <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${m.name}`} title="Delete" onClick={() => setDeleting(m)}>
            <i className="bi bi-trash" aria-hidden="true" />
          </button>
        </div>
      ) : null
    ),
  }
  const columns = level === 3
    ? [
      { key: 'name', header: 'Name', render: (m) => <strong className="fw-semibold">{m.name}</strong> },
      { key: 'code', header: 'Employee Code', nowrap: true, render: (m) => m.code || '—' },
      { key: 'email', header: 'Email', render: (m) => m.email || '—' },
      { key: 'phone', header: 'Phone Number', nowrap: true, render: (m) => m.phone || '—' },
      {
        key: 'states',
        header: 'State List',
        render: (m) => (m.states?.length
          ? <span className="d-inline-flex flex-wrap gap-1">{m.states.map((item) => <span key={item.id} className="badge text-secondary-emphasis bg-secondary-subtle">{item.name}</span>)}</span>
          : '—'),
      },
      {
        key: 'seconds',
        header: '2nd Line Manager',
        render: (m) => {
          const reports = state.managers.filter((child) => child.level === 2 && child.parentId === m.id).sort(byName)
          return reports.length ? reports.map((child) => child.name).join(', ') : '—'
        },
      },
      editAction,
    ]
    : [
      { key: 'name', header: 'Name', sortable: true, render: (m) => <strong className="fw-semibold">{m.name}</strong> },
      { key: 'code', header: 'Employee code', sortable: true, nowrap: true },
      ...(level === 1 || level === 2 ? [
        { key: 'email', header: 'Email', render: (m) => m.email || '—' },
        { key: 'phone', header: 'Phone Number', nowrap: true, render: (m) => m.phone || '—' },
        {
          key: 'states',
          header: 'State',
          render: (m) => (m.states?.length
            ? <span className="d-inline-flex flex-wrap gap-1">{m.states.map((item) => <span key={item.id} className="badge text-secondary-emphasis bg-secondary-subtle">{item.name}</span>)}</span>
            : '—'),
        },
      ] : []),
      reportsTo,
      {
        key: 'children',
        header: level === 1 ? 'Headquarters' : '1st line managers',
        align: 'end',
        render: (m) => (level === 1
          ? state.headquarters.filter((office) => office.parentManagerId === m.id).length
          : state.managers.filter((child) => child.parentId === m.id && child.level === level - 1).length),
      },
      editAction,
    ]

  const addButton = (
    canManage && <button type="button" className="btn btn-primary" onClick={editor.openNew} disabled={!canAdd}>
      <i className="bi bi-plus-lg" aria-hidden="true" /> Add {meta.label.toLowerCase()}
    </button>
  )

  return (
    <PageShell actions={addButton}>
      <DataStatusNotice />

      {canManage && !canAdd && firstParentType && (
        <div className="alert alert-info" role="note">
          <i className="bi bi-info-circle-fill" aria-hidden="true" />
          <div>
            Every {meta.label.toLowerCase()} must report to a {firstParentType.label.toLowerCase()}.{' '}
            <Link to={prerequisitePath}>Add a {firstParentType.label.toLowerCase()}</Link> first.
          </div>
        </div>
      )}

      <TableCard
        title={`${meta.plural} (${managers.length})`}
        toolbar={(
          <>
            <SearchInput value={query} onChange={setQuery} label={`Search ${meta.plural.toLowerCase()}`} placeholder="Search name or code" />
            <ListFilter
              locations={state.locations ?? []}
              reports={level === 3 ? null : {
                label: 'Reports to',
                options: managersAtLevel(state, level === 2 ? 3 : 2).sort(byName).map((manager) => ({ id: manager.id, name: manager.name })),
              }}
              applied={filters}
              onApply={setFilters}
            />
            <SalesOfficeCsv
              module={`manager-${level}`}
              records={rows.map((manager) => {
                const parent = getParent(state, manager)
                return {
                  Name: manager.name,
                  'Employee Code': manager.code ?? '',
                  Email: manager.email ?? '',
                  'Phone Number': manager.phone ?? '',
                  'Reports To': parent?.code || parent?.name || '',
                  'State List': (manager.states ?? []).map((item) => item.name).join('; '),
                }
              })}
            />
          </>
        )}
      >
        {canManage && <SelectionBar count={selection.count} onDelete={() => setDeletingMany(true)} onClear={selection.clear} />}
        <DataTable
          caption={meta.plural}
          columns={columns}
          rows={rows}
          sort={level === 3 ? null : sort}
          onSort={level === 3 ? null : toggleSort}
          selection={canManage ? {
            isSelected: (manager) => selection.isSelected(manager.id),
            onToggle: (manager) => selection.toggle(manager.id),
            onToggleAll: selection.toggleAll,
            allSelected: selection.allSelected,
            indeterminate: selection.indeterminate,
            label: (manager) => `Select ${manager.name}`,
          } : null}
          emptyText={managers.length ? 'No managers match your search.' : `No ${meta.plural.toLowerCase()} yet.`}
          empty={state.status === 'ready' && managers.length === 0 && (
            <div className="card-body">
              <EmptyState icon="bi-person-badge" title={`No ${meta.plural.toLowerCase()} yet`} text={levelHelp(level)} />
            </div>
          )}
        />
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={`${editor.record ? 'Edit' : 'Add'} ${meta.label.toLowerCase()}`}
        renderForm={(formId) => (
          <ManagerForm
            key={editor.session}
            formId={formId}
            level={level}
            record={editor.record}
            state={state}
            onSave={async (values) => {
              await actions.saveRecord('managers', { ...editor.record, ...values, level })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title={`Delete ${meta.label.toLowerCase()}`}
        confirmLabel="Delete"
        onConfirm={() => actions.deleteManager(deleting.id)}
      >
        Delete {deleting?.name}? A manager who still has people or headquarters reporting to them cannot be deleted.
      </ConfirmDialog>

      <ConfirmDialog
        open={deletingMany}
        onClose={() => setDeletingMany(false)}
        title={`Delete selected ${meta.plural.toLowerCase()}`}
        confirmLabel="Delete selected"
        onConfirm={async () => {
          await actions.deleteMany('managers', selection.selectedIds)
          selection.clear()
        }}
      >
        Delete {selection.count} {meta.plural.toLowerCase()}? A manager who still has people or headquarters reporting to them cannot be deleted.
      </ConfirmDialog>
    </PageShell>
  )
}

function levelHelp(level) {
  if (level === 1) return 'Each 1st line manager belongs to one 2nd line manager. Several 1st line managers can belong to the same 2nd line manager.'
  if (level === 2) return 'Each 2nd line manager belongs to one 3rd line manager. Several 2nd line managers can belong to the same 3rd line manager.'
  return 'A 3rd line manager is the top of the hierarchy and has no parent.'
}
