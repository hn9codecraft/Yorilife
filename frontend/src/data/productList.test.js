import test from 'node:test'
import assert from 'node:assert/strict'
import { NONE, byId, filterProducts, formatMoney, moneyError, nameTaken, productCsvRecord } from './productList.js'

const products = [
  { id: '1', name: 'Yori Cap', sku: 'YC-10', productTypeId: '5', productGroupId: '9' },
  { id: '2', name: 'Calci Syrup', sku: null, productTypeId: '6', productGroupId: null },
  { id: '3', name: 'Plain', sku: 'PL-1', productTypeId: null, productGroupId: '9' },
]

test('product list filters by name or SKU, type and group, including "none"', () => {
  const names = (filters) => filterProducts(products, { q: '', type: '', group: '', ...filters }).map((product) => product.name)
  assert.deepEqual(names({}), ['Yori Cap', 'Calci Syrup', 'Plain'])
  assert.deepEqual(names({ q: 'yc-' }), ['Yori Cap'])
  assert.deepEqual(names({ q: ' syrup ' }), ['Calci Syrup'])
  assert.deepEqual(names({ type: '6' }), ['Calci Syrup'])
  assert.deepEqual(names({ type: NONE }), ['Plain'])
  assert.deepEqual(names({ group: '9' }), ['Yori Cap', 'Plain'])
  assert.deepEqual(names({ group: NONE }), ['Calci Syrup'])
  assert.deepEqual(names({ group: '9', q: 'plain' }), ['Plain'])
})

test('names are unique case-insensitively, ignoring the record being edited', () => {
  const types = [{ id: 1, name: 'Tablet' }, { id: 2, name: 'Syrup' }]
  assert.equal(nameTaken(types, ' tablet '), true)
  assert.equal(nameTaken(types, 'TABLET', '1'), false)
  assert.equal(nameTaken(types, 'Capsule'), false)
  assert.equal(nameTaken(types, '  '), false)
})

test('money fields are optional, non-negative, two decimals and fit DECIMAL(12,2)', () => {
  assert.equal(moneyError('', 'MRP'), null)
  assert.equal(moneyError('120.5', 'MRP'), null)
  assert.equal(moneyError('9999999999.99', 'MRP'), null)
  assert.equal(moneyError('-1', 'MRP'), 'MRP cannot be negative.')
  assert.match(moneyError('1.234', 'PTR'), /two decimal places/)
  assert.match(moneyError('abc', 'PTS'), /two decimal places/)
  assert.equal(moneyError('10000000000', 'Net rate'), 'Net rate is too large.')
})

test('money is shown in rupees with Indian grouping', () => {
  assert.equal(formatMoney('123456.5'), '₹ 1,23,456.50')
  assert.equal(formatMoney(null), null)
  assert.equal(formatMoney(''), null)
})

test('CSV rows use readable type and group names', () => {
  const types = byId([{ id: 5, name: 'Capsule' }])
  const groups = byId([{ id: 9, name: 'Focus Product' }])
  assert.deepEqual(productCsvRecord({ ...products[0], packagingUnit: '10 x 10', mrp: '120.50', ptr: null, pts: '86.75', scheme: '10+1', netRate: '80.00' }, types, groups), {
    'Product name': 'Yori Cap', SKU: 'YC-10', 'Product type': 'Capsule', 'Packaging unit': '10 x 10', 'Product group': 'Focus Product',
    MRP: '120.50', PTR: '', PTS: '86.75', Scheme: '10+1', 'Net rate': '80.00',
  })
  assert.equal(productCsvRecord(products[1], types, groups)['Product group'], '')
})