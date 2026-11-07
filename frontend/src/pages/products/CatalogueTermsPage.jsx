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
import SelectionBar from '../../components/SelectionBar.jsx'
import StatusBadge from '../../components/StatusBadge.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import { TERM_KINDS } from '../../data/constants.js'
import { byName, formatDateTime, matchesSearch } from '../../data/selectors.js'

function StatCards({ items }) {
  return (
    <div className="row g-3 mb-4">
      {items.map((item) => (
        <div className="col-12 col-md-4" key={item.label}>
          <div className="card h-100">
            <div className="card-body">
              <div className="text-muted small mb-1">{item.label}</div>
              <div className="fs-3 fw-semibold">{item.value}</div>
              {item.note && <div className="small text-success mt-1">{item.note}</div>}
            </div>
          </div>
        </div>
      ))}
    </div>
  )
}

function TermForm({ formId, kind, record, groups, onSave }) {
  const label = TERM_KINDS[kind].label
  const form = useFormState(
    {
      name: record?.name ?? '',
      description: record?.description ?? '',
      status: record?.status ?? 'active',
      parentId: record?.parentId ?? '',
    },
    (values) => {
      const errors = {}
      if (!values.name.trim()) errors.name = `Enter the ${label.toLowerCase()} name.`
      return errors
    },
  )

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({
        name: values.name.trim(),
        description: values.description.trim(),
        status: values.status,
        ...(kind === 'category' ? { parentId: values.parentId || null } : {}),
      }))}
    >
      <TextField id={form.fieldId('name')} label={`${label} name`} required value={form.values.name} onChange={form.set('name')} error={form.errors.name} />
      {kind === 'category' && (
        <SelectField
          id={form.fieldId('parentId')}
          label="Product group category"
          value={form.values.parentId}
          onChange={form.set('parentId')}
          options={groups.map((group) => ({ value: group.id, label: group.name }))}
          placeholder="No group category (optional)"
          error={form.errors.parentId}
          help="Optional. A category can be saved without a group."
        />
      )}
      <TextField
        id={form.fieldId('description')}
        label="Description"
        value={form.values.description}
        onChange={form.set('description')}
        error={form.errors.description}
        className="col-12"
      />
      <SelectField
        id={form.fieldId('status')}
        label="Status"
        required
        value={form.values.status}
        onChange={form.set('status')}
        options={[
          { value: 'active', label: 'Active' },
          { value: 'inactive', label: 'Inactive' },
        ]}
        error={form.errors.status}
      />
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

function childCategories(term, terms) {
  if (term.kind !== 'groupCategory') return []
  return terms.filter((item) => item.kind === 'category' && !item.deletedAt && item.parentId === term.id)
}

function TermList({ kind }) {
  const { state, actions } = useErpData()
  const canManage = usePermission('catalogue.manage')
  const canRestore = usePermission('catalogue.restore')
  const editor = useEditor()
  const [view, setView] = useState('active')
  const [query, setQuery] = useState('')
  const [deleting, setDeleting] = useState({ open: false, term: null, restore: false, many: false })
  const meta = TERM_KINDS[kind]

  const terms = state.terms.filter((t) => t.kind === kind)
  const active = terms.filter((t) => !t.deletedAt)
  const deleted = terms.filter((t) => t.deletedAt)
  const rows = (view === 'active' ? active : deleted).filter((t) => matchesSearch(t, query, ['name', 'description'])).sort(byName)
  const selection = useRowSelection(view === 'active' && canManage ? rows : [])

  const groups = state.terms.filter((term) => term.kind === 'groupCategory' && !term.deletedAt).sort(byName)
  const linked = (term) => associatedProducts(term, state.products, state.terms)
  const activeCount = active.filter((t) => t.status !== 'inactive').length

  const stats = kind === 'groupCategory'
    ? [
        { label: 'Total group categories', value: active.length, note: 'Master records' },
        { label: 'Active groups', value: activeCount, note: 'Available for assignment' },
        { label: 'Linked categories', value: state.terms.filter((t) => t.kind === 'category' && !t.deletedAt && t.parentId).length, note: 'With a group' },
      ]
    : kind === 'category'
      ? [
          { label: 'Total product categories', value: active.length, note: 'Across all groups' },
          { label: 'Linked to a group', value: active.filter((t) => t.parentId).length, note: 'Group assignments' },
          { label: 'Without a group', value: active.filter((t) => !t.parentId).length, note: 'Optional link' },
        ]
      : [
          { label: `Total ${meta.plural.toLowerCase()}`, value: active.length, note: 'Active list' },
          { label: 'Deleted', value: deleted.length, note: 'Soft-deleted' },
          { label: 'Page', value: meta.plural, note: 'Catalogue' },
        ]

  const columns = [
    { key: 'name', header: 'Name', render: (t) => (
      <>
        <strong className="fw-semibold">{t.name}</strong>
        {t.description ? <div className="small text-muted">{t.description}</div> : null}
      </>
    ) },
    kind === 'groupCategory' && {
      key: 'categories',
      header: 'Categories',
      render: (term) => childCategories(term, state.terms).length,
    },
    kind === 'category' && {
      key: 'group',
      header: 'Product group category',
      render: (term) => state.terms.find((item) => item.id === term.parentId)?.name || <span className="text-muted">No group</span>,
    },
    {
      key: 'status',
      header: 'Status',
      render: (term) => (
        <StatusBadge variant={term.status === 'inactive' ? 'secondary' : 'success'}>
          {term.status === 'inactive' ? 'Inactive' : 'Active'}
        </StatusBadge>
      ),
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
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting({ open: true, term: t, restore: true, many: false })}>
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
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting({ open: true, term: t, restore: false, many: false })}>
              <i className="bi bi-trash3" aria-hidden="true" /> Delete<span className="visually-hidden"> {t.name}</span>
            </button>
          </div>
        ) : null,
    },
  ].filter(Boolean)

  const deleteMessage = (term) => {
    if (!term) return null
    if (term.kind === 'groupCategory') {
      const kids = childCategories(term, state.terms).length
      return kids > 0
        ? `${kids} product categor${kids === 1 ? 'y' : 'ies'} will keep their names but the link to this group will be cleared. Products are not deleted.`
        : 'No product categories are linked to this group.'
    }
    if (term.kind === 'category') {
      const count = linked(term).length
      return count > 0
        ? `${count} product(s) will keep their records but the category link will be cleared. Products are not deleted.`
        : 'No products use this category.'
    }
    return null
  }

  return (
    <>
      {view === 'active' && <StatCards items={stats} />}

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
        {canManage && view === 'active' && (
          <SelectionBar count={selection.count} onDelete={() => setDeleting({ open: true, term: null, restore: false, many: true })} onClear={selection.clear} />
        )}
        <DataTable
          caption={`${view === 'active' ? 'Active' : 'Deleted'} ${meta.plural.toLowerCase()}`}
          columns={columns}
          rows={rows}
          selection={canManage && view === 'active' ? {
            isSelected: (term) => selection.isSelected(term.id),
            onToggle: (term) => selection.toggle(term.id),
            onToggleAll: selection.toggleAll,
            allSelected: selection.allSelected,
            indeterminate: selection.indeterminate,
            label: (term) => term.name,
          } : null}
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
        onConfirm={async () => {
          if (deleting.many) {
            await actions.deleteMany('terms', selection.selectedIds)
            selection.clear()
            return
          }
          if (deleting.restore) await actions.restoreTerm(deleting.term.id)
          else await actions.softDelete('terms', deleting.term.id)
        }}
        title={deleting.many ? `Delete ${selection.count} ${meta.plural.toLowerCase()}?` : deleting.restore ? `Restore ${meta.label.toLowerCase()}?` : `Delete ${meta.label.toLowerCase()}?`}
        confirmLabel={deleting.restore ? 'Restore' : 'Delete'}
        variant={deleting.restore ? 'primary' : 'danger'}
      >
        {deleting.many ? (
          <p className="mb-0">
            Selected {meta.plural.toLowerCase()} will be removed from the active list. Links from child records are cleared; products are not deleted.
          </p>
        ) : deleting.term && (
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
                {deleteMessage(deleting.term) && <p className="mb-0">{deleteMessage(deleting.term)}</p>}
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
