import { useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import PreviewNotice from '../../components/PreviewNotice.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import FormModal from '../../components/FormModal.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { ALLOWED_PARENT_TYPES, MANAGER_LEVELS, PARENT_TYPES } from '../../data/constants.js'
import { byName, getManagerChain, getParent, managersAtLevel, matchesSearch, resolveHeadquarters } from '../../data/selectors.js'

// Records that can be chosen as a parent of the given type, with a label that
// shows where each one sits in the hierarchy.
function parentOptions(state, parentType) {
  if (parentType === 'headquarters') {
    return [...state.headquarters].sort(byName).map((hq) => ({ value: hq.id, label: hq.name }))
  }
  return managersAtLevel(state, PARENT_TYPES[parentType].level)
    .sort(byName)
    .map((m) => {
      const hq = resolveHeadquarters(state, m)
      return { value: m.id, label: hq ? `${m.name} (${hq.name})` : m.name }
    })
}

function ManagerForm({ formId, level, record, state, onSave }) {
  const allowedTypes = ALLOWED_PARENT_TYPES[level]
  const form = useFormState(
    {
      name: record?.name ?? '',
      code: record?.code ?? '',
      parentType: record?.parentType ?? allowedTypes[0],
      parentId: record?.parentId ?? '',
    },
    (v) => {
      const errors = {}
      if (!v.name.trim()) errors.name = 'Enter the manager’s name.'
      if (!v.parentId) errors.parentId = `Select the ${PARENT_TYPES[v.parentType].label.toLowerCase()} this manager reports to.`
      return errors
    },
  )
  const parentLabel = PARENT_TYPES[form.values.parentType].label
  const options = parentOptions(state, form.values.parentType)

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((v) => onSave({ name: v.name.trim(), code: v.code.trim(), parentType: v.parentType, parentId: v.parentId }))}
    >
      <TextField id={form.fieldId('name')} label="Name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} />
      <TextField id={form.fieldId('code')} label="Employee code" value={form.values.code} onChange={form.set('code')} help="Optional." />

      {allowedTypes.length > 1 && (
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

      <SelectField
        id={form.fieldId('parentId')}
        label={parentLabel}
        required
        value={form.values.parentId}
        onChange={form.set('parentId')}
        options={options}
        placeholder={options.length ? `Select ${parentLabel.toLowerCase()}` : `No ${parentLabel.toLowerCase()} records yet`}
        disabled={options.length === 0}
        error={form.errors.parentId}
        help={options.length === 0 ? `Add a ${parentLabel.toLowerCase()} first.` : undefined}
      />
    </form>
  )
}

// "3rd Line Manager: A → 2nd Line Manager: B → … → Headquarters: Z"
function ReportingChain({ chain }) {
  const above = chain.slice(1)
  if (above.length === 0) return <span className="text-muted">Not linked</span>
  return (
    <ol className="reporting-chain">
      {above.map((step) => (
        <li key={step.record.id}>
          <span className="reporting-chain__type">{step.label}</span> {step.record.name}
        </li>
      ))}
    </ol>
  )
}

export default function ManagersPage({ level }) {
  const { state, actions } = useErpData()
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const meta = MANAGER_LEVELS[level]

  const managers = managersAtLevel(state, level)
  const rows = managers.filter((m) => matchesSearch(m, query)).sort(byName)
  const canAdd = ALLOWED_PARENT_TYPES[level].some((type) => parentOptions(state, type).length > 0)
  const firstParentType = PARENT_TYPES[ALLOWED_PARENT_TYPES[level][0]]
  const prerequisitePath = level === 2 ? '/sales-office/first-line-managers' : '/sales-office/headquarters'

  const columns = [
    { key: 'name', header: 'Name', render: (m) => <strong className="fw-semibold">{m.name}</strong> },
    { key: 'code', header: 'Employee code', nowrap: true },
    {
      key: 'parent',
      header: 'Reports to',
      render: (m) => {
        const parent = getParent(state, m)
        return (
          <span className="d-inline-flex flex-wrap align-items-center gap-2">
            <span className="badge text-secondary-emphasis bg-secondary-subtle">{PARENT_TYPES[m.parentType].label}</span>
            <span>{parent?.name ?? '—'}</span>
          </span>
        )
      },
    },
    { key: 'chain', header: 'Full reporting line', render: (m) => <ReportingChain chain={getManagerChain(state, m)} /> },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      render: (m) => (
        <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(m)}>
          <i className="bi bi-pencil" aria-hidden="true" /> Edit<span className="visually-hidden"> {m.name}</span>
        </button>
      ),
    },
  ]

  const addButton = (
    <button type="button" className="btn btn-primary" onClick={editor.openNew} disabled={!canAdd}>
      <i className="bi bi-plus-lg" aria-hidden="true" /> Add {meta.label.toLowerCase()}
    </button>
  )

  return (
    <PageShell actions={addButton}>
      <PreviewNotice />

      {!canAdd && (
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
        toolbar={<SearchInput value={query} onChange={setQuery} label={`Search ${meta.plural.toLowerCase()}`} placeholder="Search name or code" />}
      >
        <DataTable
          caption={meta.plural}
          columns={columns}
          rows={rows}
          emptyText={managers.length ? 'No managers match your search.' : `No ${meta.plural.toLowerCase()} yet.`}
          empty={managers.length === 0 && (
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
            onSave={(values) => {
              actions.saveRecord('managers', { ...editor.record, ...values, level })
              editor.close()
            }}
          />
        )}
      />
    </PageShell>
  )
}

function levelHelp(level) {
  if (level === 1) return 'Each 1st line manager reports to a headquarters.'
  if (level === 2) return 'Each 2nd line manager reports to a 1st line manager.'
  return 'A 3rd line manager can report to a headquarters, a 1st line manager or a 2nd line manager.'
}
