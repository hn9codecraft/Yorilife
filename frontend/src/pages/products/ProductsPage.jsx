import { useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import PreviewNotice from '../../components/PreviewNotice.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SearchInput from '../../components/SearchInput.jsx'
import FormModal from '../../components/FormModal.jsx'
import Modal from '../../components/Modal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import ProductHistoryTable from '../../components/ProductHistoryTable.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import { byName, findById, formatDateTime, matchesSearch } from '../../data/selectors.js'

// Active terms of a kind, plus any deleted ones the product still references
// (so editing never silently drops a historical link).
function termChoices(terms, kind, selectedIds) {
  return terms
    .filter((t) => t.kind === kind && (!t.deletedAt || selectedIds.includes(t.id)))
    .sort(byName)
}

function TermCheckboxes({ legend, terms, selected, onChange, idPrefix, emptyHint }) {
  const toggle = (id) => onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id])
  return (
    <fieldset className="col-12 col-md-6">
      <legend className="form-label">{legend}</legend>
      {terms.length === 0 ? (
        <p className="form-text mt-0">{emptyHint}</p>
      ) : (
        <div className="choice-group choice-group--scroll">
          {terms.map((term) => (
            <div className="form-check" key={term.id}>
              <input className="form-check-input" type="checkbox" id={`${idPrefix}-${term.id}`} checked={selected.includes(term.id)} onChange={() => toggle(term.id)} />
              <label className="form-check-label" htmlFor={`${idPrefix}-${term.id}`}>
                {term.name}
                {term.deletedAt && <span className="text-muted"> (deleted)</span>}
              </label>
            </div>
          ))}
        </div>
      )}
    </fieldset>
  )
}

function ProductForm({ formId, record, terms, onSave }) {
  const form = useFormState(
    {
      name: record?.name ?? '',
      code: record?.code ?? '',
      categoryId: record?.categoryId ?? '',
      tagIds: record?.tagIds ?? [],
      badgeIds: record?.badgeIds ?? [],
    },
    (v) => (v.name.trim() ? {} : { name: 'Enter the product name.' }),
  )
  const categories = termChoices(terms, 'category', [form.values.categoryId])

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((v) => onSave({ ...v, name: v.name.trim(), code: v.code.trim(), categoryId: v.categoryId || null }))}
    >
      <TextField id={form.fieldId('name')} label="Product name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} className="col-12 col-md-8" />
      <TextField id={form.fieldId('code')} label="Product code" value={form.values.code} onChange={form.set('code')} help="Optional." className="col-12 col-md-4" />
      <SelectField
        id={form.fieldId('categoryId')}
        label="Product category"
        value={form.values.categoryId}
        onChange={form.set('categoryId')}
        options={categories.map((c) => ({ value: c.id, label: c.deletedAt ? `${c.name} (deleted)` : c.name }))}
        placeholder="No category"
        help={categories.length === 0 ? 'No categories yet — create them under Product Categories.' : undefined}
      />
      <TermCheckboxes
        legend="Tags"
        idPrefix={form.fieldId('tag')}
        terms={termChoices(terms, 'tag', form.values.tagIds)}
        selected={form.values.tagIds}
        onChange={form.set('tagIds')}
        emptyHint="No tags yet — create them under Product Tags & Badges."
      />
      <TermCheckboxes
        legend="Badges"
        idPrefix={form.fieldId('badge')}
        terms={termChoices(terms, 'badge', form.values.badgeIds)}
        selected={form.values.badgeIds}
        onChange={form.set('badgeIds')}
        emptyHint="No badges yet — create them under Product Tags & Badges."
      />
    </form>
  )
}

function TermPills({ ids, terms }) {
  const items = ids.map((id) => findById(terms, id)).filter(Boolean)
  if (items.length === 0) return <span className="text-muted">—</span>
  return (
    <span className="pill-list">
      {items.map((t) => (
        <span key={t.id} className={`badge ${t.deletedAt ? 'text-secondary-emphasis bg-secondary-subtle text-decoration-line-through' : 'text-primary-emphasis bg-primary-subtle'}`}>
          {t.name}
          {t.deletedAt && <span className="visually-hidden"> (deleted)</span>}
        </span>
      ))}
    </span>
  )
}

/**
 * Product List (deleted = false) and Deleted Products (deleted = true).
 * Deleting is a soft delete: the product leaves the active list but its record
 * and history stay for past master sheets, sales and audit.
 */
export default function ProductsPage({ deleted = false }) {
  const { state, actions } = useErpData()
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [deleting, setDeleting] = useState({ open: false, product: null })
  const [historyOf, setHistoryOf] = useState({ open: false, product: null })

  const products = state.products.filter((p) => Boolean(p.deletedAt) === deleted)
  const rows = products.filter((p) => matchesSearch(p, query)).sort(byName)
  const category = (p) => {
    const term = findById(state.terms, p.categoryId)
    if (!term) return <span className="text-muted">—</span>
    return term.deletedAt ? <span>{term.name} <span className="text-muted">(deleted)</span></span> : term.name
  }

  const columns = [
    { key: 'name', header: 'Product', render: (p) => <strong className="fw-semibold">{p.name}</strong> },
    { key: 'code', header: 'Code', nowrap: true },
    { key: 'category', header: 'Category', render: category },
    { key: 'tags', header: 'Tags', render: (p) => <TermPills ids={p.tagIds} terms={state.terms} /> },
    { key: 'badges', header: 'Badges', render: (p) => <TermPills ids={p.badgeIds} terms={state.terms} /> },
    deleted
      ? { key: 'deletedAt', header: 'Deleted on', nowrap: true, render: (p) => formatDateTime(p.deletedAt) }
      : { key: 'updatedAt', header: 'Last updated', nowrap: true, render: (p) => formatDateTime(p.updatedAt) },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (p) => (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setHistoryOf({ open: true, product: p })}>
            <i className="bi bi-clock-history" aria-hidden="true" /> History<span className="visually-hidden"> of {p.name}</span>
          </button>
          {!deleted && (
            <>
              <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(p)}>
                <i className="bi bi-pencil" aria-hidden="true" /> Edit<span className="visually-hidden"> {p.name}</span>
              </button>
              <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting({ open: true, product: p })}>
                <i className="bi bi-trash3" aria-hidden="true" /> Delete<span className="visually-hidden"> {p.name}</span>
              </button>
            </>
          )}
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={
        !deleted && (
          <button type="button" className="btn btn-primary" onClick={editor.openNew}>
            <i className="bi bi-plus-lg" aria-hidden="true" /> Add product
          </button>
        )
      }
    >
      <PreviewNotice />

      {deleted && (
        <div className="alert alert-info" role="note">
          <i className="bi bi-info-circle-fill" aria-hidden="true" />
          <div>
            Deleted products are kept for past master sheets, sales and audit history. They cannot be edited, and
            there is no permanent delete. Active products are on the <Link to="/products/list">Product List</Link>.
          </div>
        </div>
      )}

      <TableCard
        title={`${deleted ? 'Deleted products' : 'Active products'} (${products.length})`}
        toolbar={<SearchInput value={query} onChange={setQuery} label="Search products" placeholder="Search name or code" />}
      >
        <DataTable
          caption={deleted ? 'Deleted products' : 'Active products'}
          columns={columns}
          rows={rows}
          emptyText={
            query ? 'No products match your search.' : deleted ? 'No deleted products.' : 'No products yet. Use “Add product” to create one.'
          }
        />
      </TableCard>

      <FormModal
        open={editor.open}
        onClose={editor.close}
        title={editor.record ? 'Edit product' : 'Add product'}
        size="lg"
        renderForm={(formId) => (
          <ProductForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            terms={state.terms}
            onSave={(values) => {
              actions.saveProduct({ ...editor.record, ...values })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={deleting.open}
        onClose={() => setDeleting((d) => ({ ...d, open: false }))}
        onConfirm={() => actions.deleteProduct(deleting.product.id)}
        title="Delete product?"
        confirmLabel="Delete product"
      >
        {deleting.product && (
          <p className="mb-0">
            <strong>{deleting.product.name}</strong> will be removed from the Product List and moved to
            Deleted Products. Its record and history are kept.
          </p>
        )}
      </ConfirmDialog>

      <Modal
        open={historyOf.open}
        onClose={() => setHistoryOf((h) => ({ ...h, open: false }))}
        title={historyOf.product ? `${historyOf.product.name} — change history` : 'Change history'}
        size="xl"
      >
        {historyOf.product && (
          <ProductHistoryTable
            showProduct={false}
            entries={state.productHistory.filter((e) => e.productId === historyOf.product.id)}
          />
        )}
      </Modal>
    </PageShell>
  )
}
