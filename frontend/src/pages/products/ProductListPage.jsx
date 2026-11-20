import { useMemo, useState } from 'react'
import PageShell from '../../components/PageShell.jsx'
import DataStatusNotice from '../../components/DataStatusNotice.jsx'
import TableCard from '../../components/TableCard.jsx'
import DataTable from '../../components/DataTable.jsx'
import SelectionBar from '../../components/SelectionBar.jsx'
import FilterBar from '../../components/FilterBar.jsx'
import FormModal from '../../components/FormModal.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import ExportCsvButton from '../../components/ExportCsvButton.jsx'
import SearchableSelect from '../../components/SearchableSelect.jsx'
import { LabelNote, TextField } from '../../components/FormField.jsx'
import { CellStack, ChipList } from '../../components/TableCells.jsx'
import useErpData from '../../data/useErpData.js'
import usePermission from '../../data/usePermission.js'
import useEditor from '../../hooks/useEditor.js'
import useFormState from '../../hooks/useFormState.js'
import useRowSelection from '../../hooks/useRowSelection.js'
import useUrlFilters from '../../hooks/useUrlFilters.js'
import {
  NONE,
  PRODUCT_CSV,
  PRODUCT_FILTER_DEFAULTS,
  byId,
  filterProducts,
  formatMoney,
  moneyError,
  productCsvRecord,
} from '../../data/productList.js'

const MONEY_FIELDS = [
  ['mrp', 'MRP'],
  ['ptr', 'PTR'],
  ['pts', 'PTS'],
  ['netRate', 'Net Rate'],
]

const optionsOf = (records) => records.map((record) => ({ value: String(record.id), label: record.name }))
const idOrBlank = (value) => (value == null ? '' : String(value))

/** Add / edit a product. Only the name is required. */
function ProductForm({ formId, record, products, types, groups, onSave }) {
  const form = useFormState(
    {
      name: record?.name ?? '',
      sku: record?.sku ?? record?.code ?? '',
      productTypeId: idOrBlank(record?.productTypeId),
      packagingUnit: record?.packagingUnit ?? '',
      productGroupId: idOrBlank(record?.productGroupId),
      mrp: record?.mrp ?? '',
      ptr: record?.ptr ?? '',
      pts: record?.pts ?? '',
      scheme: record?.scheme ?? '',
      netRate: record?.netRate ?? '',
    },
    (values) => {
      const errors = {}
      if (!values.name.trim()) errors.name = 'Enter the product name.'
      const sku = values.sku.trim().toLowerCase()
      if (sku && products.some((product) => product.id !== record?.id && (product.sku ?? product.code ?? '').trim().toLowerCase() === sku)) {
        errors.sku = 'An active product already uses this SKU.'
      }
      if (values.productTypeId && !types.some((type) => String(type.id) === values.productTypeId)) errors.productTypeId = 'Choose a product type from the list.'
      if (values.productGroupId && !groups.some((group) => String(group.id) === values.productGroupId)) errors.productGroupId = 'Choose a product group from the list.'
      for (const [field, label] of MONEY_FIELDS) {
        const message = moneyError(values[field], label)
        if (message) errors[field] = message
      }
      return errors
    },
  )

  const submit = (values) => onSave({
    name: values.name.trim(),
    sku: values.sku.trim(),
    productTypeId: values.productTypeId,
    packagingUnit: values.packagingUnit.trim(),
    productGroupId: values.productGroupId,
    mrp: values.mrp.toString().trim(),
    ptr: values.ptr.toString().trim(),
    pts: values.pts.toString().trim(),
    scheme: values.scheme.trim(),
    netRate: values.netRate.toString().trim(),
  })

  return (
    <form id={formId} className="row g-3" noValidate onSubmit={form.handleSubmit(submit)}>
      <TextField
        id={form.fieldId('name')}
        label={<LabelNote label="Product Name" note="required" />}
        required
        value={form.values.name}
        onChange={form.set('name')}
        error={form.errors.name}
        maxLength={200}
        autoComplete="off"
        className="col-12 col-md-6"
      />
      <TextField
        id={form.fieldId('sku')}
        label={<LabelNote label="SKU" note="optional, unique" />}
        value={form.values.sku}
        onChange={form.set('sku')}
        error={form.errors.sku}
        maxLength={50}
        autoComplete="off"
        className="col-12 col-md-6"
      />
      <SearchableSelect
        id={form.fieldId('productTypeId')}
        label={<LabelNote label="Product Type" note="from Category" />}
        ariaLabel="Product Type"
        value={form.values.productTypeId}
        onChange={form.set('productTypeId')}
        options={optionsOf(types)}
        placeholder={types.length ? 'Search or select product type' : 'No product types yet'}
        noResultsText="No product type matches"
        error={form.errors.productTypeId}
        disabled={types.length === 0}
        className="col-12 col-md-6"
      />
      <TextField
        id={form.fieldId('packagingUnit')}
        label={<LabelNote label="Packaging Unit" note="e.g. 10 x 10 tablets" />}
        value={form.values.packagingUnit}
        onChange={form.set('packagingUnit')}
        error={form.errors.packagingUnit}
        maxLength={100}
        autoComplete="off"
        className="col-12 col-md-6"
      />
      <SearchableSelect
        id={form.fieldId('productGroupId')}
        label={<LabelNote label="Product Group" note="from Category" />}
        ariaLabel="Product Group"
        value={form.values.productGroupId}
        onChange={form.set('productGroupId')}
        options={optionsOf(groups)}
        placeholder={groups.length ? 'Search or select product group' : 'No product groups yet'}
        noResultsText="No product group matches"
        error={form.errors.productGroupId}
        disabled={groups.length === 0}
        className="col-12 col-md-6"
      />
      <TextField
        id={form.fieldId('scheme')}
        label={<LabelNote label="Scheme" note="e.g. 10+1" />}
        value={form.values.scheme}
        onChange={form.set('scheme')}
        error={form.errors.scheme}
        maxLength={50}
        autoComplete="off"
        className="col-12 col-md-6"
      />
      {MONEY_FIELDS.map(([field, label]) => (
        <TextField
          key={field}
          id={form.fieldId(field)}
          label={<LabelNote label={label} note="₹" />}
          value={form.values[field]}
          onChange={form.set(field)}
          error={form.errors[field]}
          inputMode="decimal"
          autoComplete="off"
          placeholder="0.00"
          className="col-6 col-md-3"
        />
      ))}
    </form>
  )
}

export default function ProductListPage() {
  const { state, actions } = useErpData()
  const canManage = usePermission('products.manage')
  const canDelete = usePermission('products.delete')
  const editor = useEditor()
  const [filters, setFilter, clearFilters] = useUrlFilters(PRODUCT_FILTER_DEFAULTS)
  const [deleting, setDeleting] = useState(null)
  const [deletingMany, setDeletingMany] = useState(false)

  const types = useMemo(() => state.productTypes ?? [], [state.productTypes])
  const groups = useMemo(() => state.productGroups ?? [], [state.productGroups])
  const typesById = useMemo(() => byId(types), [types])
  const groupsById = useMemo(() => byId(groups), [groups])
  // Deleted products are not shown (their records and history are kept).
  const products = useMemo(() => (state.products ?? []).filter((product) => !product.deletedAt), [state.products])

  const rows = filterProducts(products, filters)
  const selection = useRowSelection(rows)

  const typeOptions = [{ value: NONE, label: 'No product type' }, ...optionsOf(types)]
  const groupOptions = [{ value: NONE, label: 'No product group' }, ...optionsOf(groups)]
  const optionLabel = (options, value) => options.find((option) => option.value === value)?.label ?? value
  const chips = [
    filters.q && { key: 'q', label: `Search: ${filters.q}` },
    filters.type && { key: 'type', label: `Product type: ${optionLabel(typeOptions, filters.type)}` },
    filters.group && { key: 'group', label: `Product group: ${optionLabel(groupOptions, filters.group)}` },
  ].filter(Boolean).map((chip) => ({ ...chip, onRemove: () => setFilter(chip.key, '') }))

  const money = (key, header) => ({
    key,
    header,
    width: 'money',
    render: (product) => formatMoney(product[key]),
  })

  const columns = [
    {
      key: 'name',
      header: 'Product',
      width: 'xl',
      sticky: 'start',
      truncate: true,
      title: (product) => [product.name, product.sku].filter(Boolean).join(' · '),
      render: (product) => <CellStack strong primary={product.name} secondary={product.sku ? `SKU ${product.sku}` : null} />,
    },
    { key: 'type', header: 'Product Type', width: 'md', render: (product) => typesById.get(String(product.productTypeId ?? ''))?.name ?? null },
    { key: 'packagingUnit', header: 'Packaging Unit', width: 'md', truncate: true, title: (product) => product.packagingUnit, render: (product) => product.packagingUnit },
    {
      key: 'group',
      header: 'Product Group',
      width: 'md',
      render: (product) => {
        const group = groupsById.get(String(product.productGroupId ?? ''))
        return group ? <ChipList items={[group.name]} label="Product group" /> : null
      },
    },
    money('mrp', 'MRP'),
    money('ptr', 'PTR'),
    money('pts', 'PTS'),
    { key: 'scheme', header: 'Scheme', width: 'sm', nowrap: true, render: (product) => product.scheme },
    money('netRate', 'Net Rate'),
    {
      key: 'actions',
      header: 'Actions',
      align: 'end',
      nowrap: true,
      sticky: 'end',
      render: (product) => (canManage || canDelete) ? (
        <div className="table-actions">
          {canManage && (
            <button type="button" className="btn btn-sm btn-outline-secondary btn-icon" aria-label={`Edit ${product.name}`} title="Edit" onClick={() => editor.openEdit(product)}>
              <i className="bi bi-pencil" aria-hidden="true" />
            </button>
          )}
          {canDelete && (
            <button type="button" className="btn btn-sm btn-outline-danger btn-icon" aria-label={`Delete ${product.name}`} title="Delete" onClick={() => setDeleting(product)}>
              <i className="bi bi-trash" aria-hidden="true" />
            </button>
          )}
        </div>
      ) : null,
    },
  ]

  return (
    <PageShell
      actions={canManage && (
        <button type="button" className="btn btn-primary" onClick={editor.openNew}>
          <i className="bi bi-plus-lg" aria-hidden="true" /> Add product
        </button>
      )}
    >
      <DataStatusNotice />
      <TableCard title="Products" count={products.length}>
        <FilterBar
          label="Filter products"
          search={{ value: filters.q, onChange: (value) => setFilter('q', value), label: 'Search products', placeholder: 'Search name or SKU' }}
          chips={chips}
          onClearAll={() => clearFilters()}
          shown={rows.length}
          total={products.length}
          noun="products"
          actions={(
            <ExportCsvButton
              name={PRODUCT_CSV.filename}
              headers={PRODUCT_CSV.headers}
              auditKind="products_csv"
              mask={false}
              records={rows.map((product) => productCsvRecord(product, typesById, groupsById))}
            />
          )}
        >
          <SearchableSelect
            id="product-list-type"
            label="Product Type"
            labelHidden
            value={filters.type}
            onChange={(value) => setFilter('type', value)}
            options={typeOptions}
            placeholder="All product types"
            noResultsText="No product type matches"
            className="filter-bar__control"
          />
          <SearchableSelect
            id="product-list-group"
            label="Product Group"
            labelHidden
            value={filters.group}
            onChange={(value) => setFilter('group', value)}
            options={groupOptions}
            placeholder="All product groups"
            noResultsText="No product group matches"
            className="filter-bar__control"
          />
        </FilterBar>
        {canDelete && <SelectionBar count={selection.count} onDelete={() => setDeletingMany(true)} onClear={selection.clear} />}
        <DataTable
          caption="Products"
          striped
          selection={canDelete ? {
            isSelected: (product) => selection.isSelected(product.id),
            onToggle: (product) => selection.toggle(product.id),
            onToggleAll: selection.toggleAll,
            allSelected: selection.allSelected,
            indeterminate: selection.indeterminate,
            label: (product) => `Select ${product.name}`,
          } : null}
          columns={columns}
          rows={rows}
          emptyIcon={products.length ? 'bi-funnel' : 'bi-box2'}
          emptyText={products.length ? 'No products match your filters' : 'No products yet'}
          emptyHint={products.length ? 'Change or clear the filters to see more products.' : 'Add your first product. Only the product name is required.'}
          emptyAction={products.length
            ? <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => clearFilters()}>Clear filters</button>
            : canManage && (
              <button type="button" className="btn btn-primary btn-sm" onClick={editor.openNew}>
                <i className="bi bi-plus-lg" aria-hidden="true" /> Add product
              </button>
            )}
        />
      </TableCard>

      <FormModal
        size="lg"
        variant="wide"
        open={editor.open}
        onClose={editor.close}
        title={editor.record?.id ? 'Edit product' : 'Add product'}
        renderForm={(formId) => (
          <ProductForm
            key={editor.session}
            formId={formId}
            record={editor.record}
            products={products}
            types={types}
            groups={groups}
            onSave={async (values) => {
              await actions.saveProductListItem({ id: editor.record?.id, ...values })
              editor.close()
            }}
          />
        )}
      />

      <ConfirmDialog
        open={Boolean(deleting)}
        onClose={() => setDeleting(null)}
        title="Delete product"
        confirmLabel="Delete"
        onConfirm={() => actions.deleteProduct(deleting.id)}
      >
        Delete {deleting?.name}? It is removed from the product list; its record and change history are kept.
      </ConfirmDialog>

      <ConfirmDialog
        open={deletingMany}
        onClose={() => setDeletingMany(false)}
        title="Delete selected products"
        confirmLabel="Delete selected"
        onConfirm={async () => {
          await actions.deleteMany('products', selection.selectedIds)
          selection.clear()
        }}
      >
        Delete {selection.count} products? They are removed from the product list; their records and change history are kept.
      </ConfirmDialog>
    </PageShell>
  )
}