import { useState } from 'react'
import { Link } from 'react-router'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import usePermission from '../../data/usePermission.js'
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

function activeTerms(terms, kind) {
  return terms.filter((term) => term.kind === kind && !term.deletedAt).sort(byName)
}

function classificationOf(product, terms) {
  const category = findById(terms, product.categoryId)
  const group = category?.parentId ? findById(terms, category.parentId) : null
  return { category, group }
}

function ProductForm({ formId, record, terms, onSave }) {
  const category = findById(terms, record?.categoryId)
  const form = useFormState(
    {
      name: record?.name ?? '',
      code: record?.code ?? '',
      groupCategoryId: category?.parentId ?? '',
      categoryId: record?.categoryId ?? '',
      scheme: record?.scheme ?? '',
    },
    (values) => {
      const errors = {}
      if (!values.name.trim()) errors.name = 'Enter the product name.'
      if (!values.groupCategoryId) errors.groupCategoryId = 'Choose a group category.'
      if (!values.categoryId) errors.categoryId = 'Choose a product category.'
      return errors
    },
  )
  const groups = activeTerms(terms, 'groupCategory')
  const categories = terms
    .filter((term) => term.kind === 'category' && term.parentId === form.values.groupCategoryId && (!term.deletedAt || term.id === form.values.categoryId))
    .sort(byName)

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({
        ...values,
        name: values.name.trim(),
        code: values.code.trim(),
        scheme: values.scheme.trim(),
      }))}
    >
      <TextField id={form.fieldId('name')} label="Product name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} className="col-12 col-md-8" />
      <TextField id={form.fieldId('code')} label="Product code" value={form.values.code} onChange={form.set('code')} help="Optional. Unique among active products." className="col-12 col-md-4" />
      <SelectField
        id={form.fieldId('groupCategoryId')}
        label="Group category"
        required
        value={form.values.groupCategoryId}
        onChange={(value) => {
          const selected = findById(terms, form.values.categoryId)
          form.setMany({
            groupCategoryId: value,
            categoryId: selected && selected.parentId === value ? form.values.categoryId : '',
          })
        }}
        options={groups.map((group) => ({ value: group.id, label: group.name }))}
        placeholder="Select a group category"
        error={form.errors.groupCategoryId}
      />
      <SelectField
        id={form.fieldId('categoryId')}
        label="Product category"
        required
        value={form.values.categoryId}
        onChange={form.set('categoryId')}
        options={categories.map((item) => ({ value: item.id, label: item.name }))}
        placeholder={form.values.groupCategoryId ? 'Select a product category' : 'Select a group category first'}
        error={form.errors.categoryId}
        help="Only categories in the selected group are listed."
      />
      <TextField
        id={form.fieldId('scheme')}
        label="Scheme"
        value={form.values.scheme}
        onChange={form.set('scheme')}
        placeholder="e.g. 10+5"
        help="Current scheme for this product. It is not stored separately for each financial year."
      />
    </form>
  )
}

function GroupForm({ formId, onSave }) {
  const form = useFormState({ name: '' }, (values) => (values.name.trim() ? {} : { name: 'Enter the group category name.' }))
  return (
    <form id={formId} noValidate onSubmit={form.handleSubmit((values) => onSave(values.name.trim()))}>
      <TextField id={form.fieldId('name')} label="Group category" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} />
    </form>
  )
}

function CategoryForm({ formId, groups, onSave }) {
  const form = useFormState(
    { name: '', parentId: '' },
    (values) => {
      const errors = {}
      if (!values.name.trim()) errors.name = 'Enter the product category name.'
      if (!values.parentId) errors.parentId = 'Choose a group category.'
      return errors
    },
  )
  return (
    <form id={formId} className="row g-3" noValidate onSubmit={form.handleSubmit((values) => onSave({ name: values.name.trim(), parentId: values.parentId }))}>
      <TextField id={form.fieldId('name')} label="Product category" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} />
      <SelectField
        id={form.fieldId('parentId')}
        label="Group category"
        required
        value={form.values.parentId}
        onChange={form.set('parentId')}
        options={groups.map((group) => ({ value: group.id, label: group.name }))}
        placeholder="Select a group category"
        error={form.errors.parentId}
      />
    </form>
  )
}

function ClassificationPanel() {
  const { state, actions } = useErpData()
  const canManage = usePermission('catalogue.manage')
  const groupEditor = useEditor()
  const categoryEditor = useEditor()
  const [mapping, setMapping] = useState({})
  if (!canManage) return null

  const groups = activeTerms(state.terms, 'groupCategory')
  const categories = activeTerms(state.terms, 'category')
  const unmapped = categories.filter((term) => !term.parentId)

  return (
    <div className="card mb-4">
      <div className="card-body">
        <div className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
          <div>
            <h2 className="h5 mb-1">Product classification</h2>
            <p className="text-muted mb-0">Each product category belongs to one group category. These are fields on the product, not separate menu pages.</p>
          </div>
          <div className="d-flex flex-wrap gap-2">
            <button type="button" className="btn btn-outline-secondary" onClick={groupEditor.openNew}>Add group category</button>
            <button type="button" className="btn btn-outline-secondary" onClick={categoryEditor.openNew} disabled={groups.length === 0}>Add product category</button>
          </div>
        </div>
        {unmapped.length > 0 && (
          <div className="alert alert-warning" role="status">
            <div className="mb-2">These product categories are not mapped to a group. They cannot be used on a product until a group is chosen.</div>
            {unmapped.map((term) => (
              <div className="d-flex flex-wrap align-items-center gap-2 mb-2" key={term.id}>
                <strong>{term.name}</strong>
                <select
                  className="form-select form-select-sm w-auto"
                  aria-label={`Group category for ${term.name}`}
                  value={mapping[term.id] ?? ''}
                  onChange={(event) => setMapping((current) => ({ ...current, [term.id]: event.target.value }))}
                >
                  <option value="">Select a group category</option>
                  {groups.map((group) => <option key={group.id} value={group.id}>{group.name}</option>)}
                </select>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  disabled={!mapping[term.id]}
                  onClick={() => actions.saveRecord('terms', { id: term.id, name: term.name, kind: 'category', parentId: mapping[term.id] })}
                >
                  Save mapping
                </button>
              </div>
            ))}
          </div>
        )}
      </div>
      <FormModal
        open={groupEditor.open}
        onClose={groupEditor.close}
        title="Add group category"
        submitLabel="Add group category"
        renderForm={(formId) => (
          <GroupForm
            key={groupEditor.session}
            formId={formId}
            onSave={async (name) => {
              await actions.saveRecord('terms', { name, kind: 'groupCategory' })
              groupEditor.close()
            }}
          />
        )}
      />
      <FormModal
        open={categoryEditor.open}
        onClose={categoryEditor.close}
        title="Add product category"
        submitLabel="Add product category"
        renderForm={(formId) => (
          <CategoryForm
            key={categoryEditor.session}
            formId={formId}
            groups={groups}
            onSave={async (values) => {
              await actions.saveRecord('terms', { ...values, kind: 'category' })
              categoryEditor.close()
            }}
          />
        )}
      />
    </div>
  )
}

function labelOf(term) {
  if (!term) return <span className="text-muted">—</span>
  return term.deletedAt ? <span>{term.name} <span className="text-muted">(deleted)</span></span> : term.name
}

/**
 * Product List (deleted = false) and Deleted Products (deleted = true).
 * Deleting is a soft delete: the product leaves the active list but its record
 * and history stay for past master sheets, sales and audit.
 */
export default function ProductsPage({ deleted = false }) {
  const { state, actions } = useErpData()
  const canManage = usePermission('products.manage')
  const canDelete = usePermission('products.delete')
  const canRestore = usePermission('products.restore')
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [deleting, setDeleting] = useState({ open: false, product: null, restore: false })
  const [historyOf, setHistoryOf] = useState({ open: false, product: null })

  const products = state.products.filter((product) => Boolean(product.deletedAt) === deleted)
  const rows = products.filter((product) => matchesSearch(product, query)).sort(byName)

  const columns = [
    { key: 'name', header: 'Product', render: (product) => <strong className="fw-semibold">{product.name}</strong> },
    { key: 'code', header: 'Code', nowrap: true },
    { key: 'group', header: 'Group category', render: (product) => labelOf(classificationOf(product, state.terms).group) },
    { key: 'category', header: 'Product category', render: (product) => labelOf(classificationOf(product, state.terms).category) },
    { key: 'scheme', header: 'Scheme', nowrap: true, render: (product) => product.scheme || <span className="text-muted">—</span> },
    deleted
      ? { key: 'deletedAt', header: 'Deleted on', nowrap: true, render: (product) => formatDateTime(product.deletedAt) }
      : { key: 'updatedAt', header: 'Last updated', nowrap: true, render: (product) => formatDateTime(product.updatedAt) },
    {
      key: 'actions',
      header: 'Actions',
      srOnlyHeader: true,
      align: 'end',
      nowrap: true,
      render: (product) => (
        <div className="table-actions">
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setHistoryOf({ open: true, product })}>
            <i className="bi bi-clock-history" aria-hidden="true" /> History<span className="visually-hidden"> of {product.name}</span>
          </button>
          {!deleted && canManage && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => editor.openEdit(product)}>
              <i className="bi bi-pencil" aria-hidden="true" /> Edit<span className="visually-hidden"> {product.name}</span>
            </button>
          )}
          {!deleted && canDelete && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting({ open: true, product })}>
              <i className="bi bi-trash3" aria-hidden="true" /> Delete<span className="visually-hidden"> {product.name}</span>
            </button>
          )}
          {deleted && canRestore && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting({ open: true, product, restore: true })}>
              <i className="bi bi-arrow-counterclockwise" aria-hidden="true" /> Restore<span className="visually-hidden"> {product.name}</span>
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <PageShell
      actions={
        !deleted && canManage && (
          <button type="button" className="btn btn-primary" onClick={editor.openNew}>
            <i className="bi bi-plus-lg" aria-hidden="true" /> Add product
          </button>
        )
      }
    >
      <DataStatusNotice />
      {!deleted && <ClassificationPanel />}

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
            onSave={async (values) => {
              await actions.saveProduct({ ...editor.record, ...values })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={deleting.open}
        onClose={() => setDeleting((current) => ({ ...current, open: false }))}
        onConfirm={() => (deleting.restore ? actions.restoreProduct(deleting.product.id) : actions.deleteProduct(deleting.product.id))}
        title={deleting.restore ? 'Restore product?' : 'Delete product?'}
        confirmLabel={deleting.restore ? 'Restore product' : 'Delete product'}
        variant={deleting.restore ? 'primary' : 'danger'}
      >
        {deleting.product && (
          <p className="mb-0">
            <strong>{deleting.product.name}</strong>{' '}
            {deleting.restore
              ? 'will return to the Product List. If another active product already uses its code, restore is refused until that code is changed.'
              : 'will be removed from the Product List and moved to Deleted Products. Its record and history are kept.'}
          </p>
        )}
      </ConfirmDialog>

      <Modal
        open={historyOf.open}
        onClose={() => setHistoryOf((current) => ({ ...current, open: false }))}
        title={historyOf.product ? `${historyOf.product.name} — change history` : 'Change history'}
        size="xl"
      >
        {historyOf.product && (
          <ProductHistoryTable
            showProduct={false}
            entries={state.productHistory.filter((entry) => entry.productId === historyOf.product.id)}
          />
        )}
      </Modal>
    </PageShell>
  )
}
