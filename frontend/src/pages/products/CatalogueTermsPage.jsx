import { useId, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import SegmentedControl from '../../components/SegmentedControl.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { TERM_KINDS } from '../../data/constants.js'
import { byName, formatDateTime, matchesSearch } from '../../data/selectors.js'

function TermForm({ formId, kind, record, groups, onSave }) {
  const label = TERM_KINDS[kind].label
  const form = useFormState(
    { name: record?.name ?? '', parentId: record?.parentId ?? '' },
    (values) => {
      const errors = {}
      if (!values.name.trim()) errors.name = `Enter the ${label.toLowerCase()} name.`
      if (kind === 'category' && !values.parentId) errors.parentId = 'Choose a product group category.'
      return errors
    },
  )

  return (
    <form id={formId} className="row g-3" noValidate onSubmit={form.handleSubmit((values) => onSave({
      name: values.name.trim(),
      ...(kind === 'category' ? { parentId: values.parentId } : {}),
    }))}>
      <TextField id={form.fieldId('name')} label={`${label} name`} required value={form.values.name} onChange={form.set('name')} error={form.errors.name} />
      {kind === 'category' && (
        <SelectField
          id={form.fieldId('parentId')}
          label="Product group category"
          required
          value={form.values.parentId}
          onChange={form.set('parentId')}
          options={groups.map((group) => ({ value: group.id, label: group.name }))}
          placeholder="Select a group category"
          error={form.errors.parentId}
        />
      )}
    </form>
  )
}

function associatedProducts(term, products, terms) {
  return products.filter((product) => {
    if (product.deletedAt) return false
    if (term.kind === 'groupCategory') {
      const category = terms.find((item) => item.id === product.categoryId)
      return category?.parentId === term.id
    }
    if (term.kind === 'category') return product.categoryId === term.id
    if (term.kind === 'tag') return (product.tagIds ?? []).includes(term.id)
    if (term.kind === 'badge') return (product.badgeIds ?? []).includes(term.id)
    return false
  })
}

// One list of terms of a single kind, with Active / Deleted views.
function TermList({ kind }) {
  const { state, actions } = useErpData()
  const canManage = usePermission('catalogue.manage')
  const canRestore = usePermission('catalogue.restore')
  const editor = useEditor()
  const [view, setView] = useState('active')
  const [query, setQuery] = useState('')
  const [deleting, setDeleting] = useState({ open: false, term: null, restore: false })
  const meta = TERM_KINDS[kind]

  const terms = state.terms.filter((t) => t.kind === kind)
  const active = terms.filter((t) => !t.deletedAt)
  const deleted = terms.filter((t) => t.deletedAt)
  const rows = (view === 'active' ? active : deleted).filter((t) => matchesSearch(t, query, ['name'])).sort(byName)

  // Products that currently reference this term (shown so admins know the impact).
  const groups = state.terms.filter((term) => term.kind === 'groupCategory' && !term.deletedAt).sort(byName)
  const linked = (term) => associatedProducts(term, state.products, state.terms)

  const columns = [
    { key: 'name', header: 'Name', render: (t) => <strong className="fw-semibold">{t.name}</strong> },
    kind === 'category' && {
      key: 'group',
      header: 'Product group category',
      render: (term) => state.terms.find((item) => item.id === term.parentId)?.name || <span className="text-muted">Not mapped</span>,
    },
    {
      key: 'products',
      header: 'Associated products',
      render: (term) => {
        const names = linked(term).map((product) => product.name)
        if (names.length === 0) return <span className="text-muted">None</span>
        return names.join(', ')
      },
    },
    view === 'active'
      ? { key: 'createdAt', header: 'Created', nowrap: true, render: (t) => formatDateTime(t.createdAt) }
      : { key: 'deletedAt', header: 'Deleted on', nowrap: true, render: (t) => formatDateTime(t.deletedAt) },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (t) =>
        t.deletedAt ? (
          canRestore ? (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting({ open: true, term: t, restore: true })}>
              <i className="bi bi-arrow-counterclockwise" aria-hidden="true" /> Restore<span className="visually-hidden"> {t.name}</span>
            </button>
          ) : (
            <StatusBadge variant="danger">Deleted</StatusBadge>
          )
        ) : canManage ? (
          <div className="table-actions">
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(t)}>
              <i className="bi bi-pencil" aria-hidden="true" /> Edit<span className="visually-hidden"> {t.name}</span>
            </button>
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting({ open: true, term: t, restore: false })}>
              <i className="bi bi-trash3" aria-hidden="true" /> Delete<span className="visually-hidden"> {t.name}</span>
            </button>
          </div>
        ) : null,
    },
  ].filter(Boolean)

  return (
    <>
      <TableCard
        title={meta.plural}
        toolbar={
          <>
            <SegmentedControl
              label={`Show active or deleted ${meta.plural.toLowerCase()}`}
              value={view}
              onChange={setView}
              options={[
                { value: 'active', label: 'Active', count: active.length },
                { value: 'deleted', label: 'Deleted', count: deleted.length },
              ]}
            />
            <SearchInput value={query} onChange={setQuery} label={`Search ${meta.plural.toLowerCase()}`} placeholder="Search name" />
            {canManage && (
              <button type="button" className="btn btn-primary" onClick={editor.openNew}>
                <i className="bi bi-plus-lg" aria-hidden="true" /> Create {meta.label.toLowerCase()}
              </button>
            )}
          </>
        }
      >
        <DataTable
          caption={`${view === 'active' ? 'Active' : 'Deleted'} ${meta.plural.toLowerCase()}`}
          columns={columns}
          rows={rows}
          emptyText={
            query
              ? 'Nothing matches your search.'
              : view === 'active'
                ? `No ${meta.plural.toLowerCase()} yet. Use “Create ${meta.label.toLowerCase()}” to add one.`
                : `No deleted ${meta.plural.toLowerCase()}.`
          }
        />
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={`${editor.record ? 'Edit' : 'Create'} ${meta.label.toLowerCase()}`}
        submitLabel={editor.record ? 'Save' : 'Create'}
        renderForm={(formId) => (
          <TermForm
            key={editor.session}
            formId={formId}
            kind={kind}
            record={editor.record}
            groups={groups}
            onSave={async (values) => {
              await actions.saveRecord('terms', { id: editor.record?.id, kind, ...values })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={deleting.open}
        onClose={() => setDeleting((d) => ({ ...d, open: false }))}
        onConfirm={() => (deleting.restore ? actions.restoreTerm(deleting.term.id) : actions.softDelete('terms', deleting.term.id))}
        title={deleting.restore ? `Restore ${meta.label.toLowerCase()}?` : `Delete ${meta.label.toLowerCase()}?`}
        confirmLabel={deleting.restore ? 'Restore' : 'Delete'}
        variant={deleting.restore ? 'primary' : 'danger'}
      >
        {deleting.term && (
          <>
            {deleting.restore ? (
              <p className="mb-0">
                <strong>{deleting.term.name}</strong> will return to the active list.
              </p>
            ) : (
              <>
                <p>
                  <strong>{deleting.term.name}</strong> will be removed from the active list. The record is kept and
                  can still be viewed under <em>Deleted</em>.
                </p>
                {linked(deleting.term).length > 0 && (
                  <p className="mb-0">
                    {linked(deleting.term).length} active product(s) still use it, so deletion will be refused. Remove it from those products first.
                  </p>
                )}
              </>
            )}
          </>
        )}
      </ConfirmDialog>
    </>
  )
}

/**
 * Manages one or more term kinds. With several kinds (e.g. tags and badges),
 * each gets its own tab so the concepts stay separate.
 */
export default function CatalogueTermsPage({ kinds }) {
  const [activeKind, setActiveKind] = useState(kinds[0])
  const tabsId = useId()

  return (
    <PageShell>
      <DataStatusNotice />

      {kinds.length > 1 && (
        <div className="nav nav-tabs mb-3" role="tablist" aria-label="Term type">
          {kinds.map((kind) => (
            <button
              key={kind}
              type="button"
              role="tab"
              id={`${tabsId}-${kind}-tab`}
              aria-controls={`${tabsId}-${kind}-panel`}
              aria-selected={activeKind === kind}
              tabIndex={activeKind === kind ? 0 : -1}
              className={`nav-link${activeKind === kind ? ' active' : ''}`}
              onClick={() => setActiveKind(kind)}
              onKeyDown={(event) => {
                if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return
                const step = event.key === 'ArrowRight' ? 1 : -1
                const next = kinds[(kinds.indexOf(kind) + step + kinds.length) % kinds.length]
                setActiveKind(next)
                document.getElementById(`${tabsId}-${next}-tab`)?.focus()
              }}
            >
              <i className={`bi ${TERM_KINDS[kind].icon}`} aria-hidden="true" /> {TERM_KINDS[kind].plural}
            </button>
          ))}
        </div>
      )}

      <div
        id={`${tabsId}-${activeKind}-panel`}
        role={kinds.length > 1 ? 'tabpanel' : undefined}
        aria-labelledby={kinds.length > 1 ? `${tabsId}-${activeKind}-tab` : undefined}
      >
        <TermList key={activeKind} kind={activeKind} />
      </div>
    </PageShell>
  )
}
