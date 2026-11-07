import { useMemo, useState } from 'react'
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
import SelectionBar from '../../components/SelectionBar.jsx'
import ProductHistoryTable from '../../components/ProductHistoryTable.jsx'
import { SelectField, TextField } from '../../components/FormField.jsx'
import useErpData from '../../data/useErpData.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import { byName, findById, formatDateTime, matchesSearch } from '../../data/selectors.js'

function activeTerms(terms, kind) {
  return terms.filter((term) => term.kind === kind && !term.deletedAt).sort(byName)
}

function classificationOf(product, terms) {
  const category = findById(terms, product.categoryId)
  const group = category?.parentId ? findById(terms, category.parentId) : null
  return { category, group }
}

function moneyDisplay(value) {
  if (value == null || value === '') return <span className="text-muted">—</span>
  return `₹ ${value}`
}

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

function ProductForm({ formId, record, terms, onSave }) {
  const form = useFormState(
    {
      name: record?.name ?? '',
      code: record?.code ?? '',
      categoryId: record?.categoryId ?? '',
      strip: record?.strip ?? '',
      pack: record?.pack ?? '',
      mrp: record?.mrp ?? '',
      ptr: record?.ptr ?? '',
      pts: record?.pts ?? '',
      scheme: record?.scheme ?? '',
    },
    (values) => {
      const errors = {}
      if (!values.name.trim()) errors.name = 'Enter the product name.'
      return errors
    },
  )
  const categories = activeTerms(terms, 'category')

  return (
    <form
      id={formId}
      className="row g-3"
      noValidate
      onSubmit={form.handleSubmit((values) => onSave({
        name: values.name.trim(),
        code: values.code.trim(),
        categoryId: values.categoryId || null,
        strip: values.strip.trim(),
        pack: values.pack.trim(),
        mrp: values.mrp === '' ? null : values.mrp,
        ptr: values.ptr === '' ? null : values.ptr,
        pts: values.pts === '' ? null : values.pts,
        scheme: values.scheme.trim(),
      }))}
    >
      <TextField id={form.fieldId('name')} label="Product name" required value={form.values.name} onChange={form.set('name')} error={form.errors.name} className="col-12 col-md-8" />
      <TextField id={form.fieldId('code')} label="Product code" value={form.values.code} onChange={form.set('code')} help="Optional. Unique among active products." className="col-12 col-md-4" />
      <SelectField
        id={form.fieldId('categoryId')}
        label="Product category"
        value={form.values.categoryId}
        onChange={form.set('categoryId')}
        options={categories.map((item) => {
          const group = item.parentId ? findById(terms, item.parentId) : null
          return { value: item.id, label: group ? `${item.name} (${group.name})` : item.name }
        })}
        placeholder="No category (optional)"
        error={form.errors.categoryId}
        help="Optional. The category’s group is shown when one is linked."
        className="col-12"
      />
      <TextField id={form.fieldId('strip')} label="Strip" value={form.values.strip} onChange={form.set('strip')} className="col-12 col-md-6" />
      <TextField id={form.fieldId('pack')} label="Pack" value={form.values.pack} onChange={form.set('pack')} className="col-12 col-md-6" />
      <TextField id={form.fieldId('mrp')} label="MRP (₹)" value={form.values.mrp} onChange={form.set('mrp')} error={form.errors.mrp} className="col-12 col-md-4" />
      <TextField id={form.fieldId('ptr')} label="PTR (₹)" value={form.values.ptr} onChange={form.set('ptr')} error={form.errors.ptr} className="col-12 col-md-4" />
      <TextField id={form.fieldId('pts')} label="PTS (₹)" value={form.values.pts} onChange={form.set('pts')} error={form.errors.pts} className="col-12 col-md-4" />
      <TextField
        id={form.fieldId('scheme')}
        label="Scheme"
        value={form.values.scheme}
        onChange={form.set('scheme')}
        placeholder="e.g. 10+5"
        help="Current scheme for this product."
      />
    </form>
  )
}

function labelOf(term) {
  if (!term) return <span className="text-muted">—</span>
  return term.deletedAt ? <span>{term.name} <span className="text-muted">(deleted)</span></span> : term.name
}

/**
 * Product List (deleted = false) and Deleted Products (deleted = true).
 */
export default function ProductsPage({ deleted = false }) {
  const { state, actions } = useErpData()
  const canManage = usePermission('products.manage')
  const canDelete = usePermission('products.delete')
  const canRestore = usePermission('products.restore')
  const editor = useEditor()
  const [query, setQuery] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('all')
  const [deleting, setDeleting] = useState({ open: false, product: null, restore: false, many: false })
  const [historyOf, setHistoryOf] = useState({ open: false, product: null })

  const products = state.products.filter((product) => Boolean(product.deletedAt) === deleted)
  const categories = activeTerms(state.terms, 'category')

  const filtered = useMemo(() => {
    return products.filter((product) => {
      if (!matchesSearch(product, query, ['name', 'code', 'scheme', 'strip', 'pack'])) return false
      if (categoryFilter === 'all') return true
      if (categoryFilter === 'none') return !product.categoryId
      return String(product.categoryId) === String(categoryFilter)
    }).sort(byName)
  }, [products, query, categoryFilter])

  const selection = useRowSelection(!deleted && canDelete ? filtered : [])
  const usedCategories = new Set(products.filter((p) => p.categoryId).map((p) => p.categoryId)).size

  const columns = [
    { key: 'name', header: 'Product', render: (product) => <strong className="fw-semibold">{product.name}</strong> },
    { key: 'strip', header: 'Strip', nowrap: true, render: (product) => product.strip || <span className="text-muted">—</span> },
    { key: 'pack', header: 'Pack', nowrap: true, render: (product) => product.pack || <span className="text-muted">—</span> },
    { key: 'mrp', header: 'MRP', nowrap: true, render: (product) => moneyDisplay(product.mrp) },
    { key: 'ptr', header: 'PTR', nowrap: true, render: (product) => moneyDisplay(product.ptr) },
    { key: 'pts', header: 'PTS', nowrap: true, render: (product) => moneyDisplay(product.pts) },
    { key: 'scheme', header: 'Scheme', nowrap: true, render: (product) => product.scheme || <span className="text-muted">—</span> },
    {
      key: 'category',
      header: 'Product category',
      render: (product) => {
        const { category, group } = classificationOf(product, state.terms)
        if (!category) return <span className="text-muted">No category</span>
        return (
          <>
            {labelOf(category)}
            {group ? <div className="small text-muted">{group.name}</div> : null}
          </>
        )
      },
    },
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
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting({ open: true, product, many: false })}>
              <i className="bi bi-trash3" aria-hidden="true" /> Delete<span className="visually-hidden"> {product.name}</span>
            </button>
          )}
          {deleted && canRestore && (
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setDeleting({ open: true, product, restore: true, many: false })}>
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

      {!deleted && (
        <StatCards
          items={[
            { label: 'Total products', value: products.length, note: 'Product master records' },
            { label: 'Product categories used', value: usedCategories, note: 'Linked categories' },
            { label: 'Without category', value: products.filter((p) => !p.categoryId).length, note: 'Optional link' },
          ]}
        />
      )}

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
        toolbar={
          <>
            <SearchInput value={query} onChange={setQuery} label="Search products" placeholder="Search name or code" />
            {!deleted && (
              <select
                className="form-select w-auto"
                aria-label="Filter by product category"
                value={categoryFilter}
                onChange={(event) => setCategoryFilter(event.target.value)}
              >
                <option value="all">All categories</option>
                <option value="none">No category</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>{category.name}</option>
                ))}
              </select>
            )}
          </>
        }
      >
        {!deleted && canDelete && (
          <SelectionBar count={selection.count} onDelete={() => setDeleting({ open: true, product: null, many: true })} onClear={selection.clear} />
        )}
        <DataTable
          caption={deleted ? 'Deleted products' : 'Active products'}
          columns={columns}
          rows={filtered}
          selection={!deleted && canDelete ? {
            isSelected: (product) => selection.isSelected(product.id),
            onToggle: (product) => selection.toggle(product.id),
            onToggleAll: selection.toggleAll,
            allSelected: selection.allSelected,
            indeterminate: selection.indeterminate,
            label: (product) => product.name,
          } : null}
          emptyText={
            query || categoryFilter !== 'all'
              ? 'No products match your search.'
              : deleted
                ? 'No deleted products.'
                : 'No products yet. Use “Add product” to create one.'
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
        onConfirm={async () => {
          if (deleting.many) {
            await actions.deleteMany('products', selection.selectedIds)
            selection.clear()
            return
          }
          if (deleting.restore) await actions.restoreProduct(deleting.product.id)
          else await actions.deleteProduct(deleting.product.id)
        }}
        title={deleting.many ? `Delete ${selection.count} products?` : deleting.restore ? 'Restore product?' : 'Delete product?'}
        confirmLabel={deleting.restore ? 'Restore product' : 'Delete'}
        variant={deleting.restore ? 'primary' : 'danger'}
      >
        {deleting.many ? (
          <p className="mb-0">Selected products will move to Deleted Products. Their records and history are kept.</p>
        ) : deleting.product && (
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
