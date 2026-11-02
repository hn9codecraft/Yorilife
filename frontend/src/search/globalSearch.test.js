import { test } from 'node:test'
import assert from 'node:assert/strict'
import { acceptedRecordGroups, buildSearchGroups, flattenResults, searchPages } from './globalSearch.js'

const allow = (permission) => permission !== 'users.view'
const allowUsers = () => true

test('page search is empty until there is a query', () => {
  assert.deepEqual(searchPages('   ', allow), [])
})

test('page search matches a menu label and keeps the group trail', () => {
  const [match] = searchPages('stockist reports', allow)
  assert.equal(match.label, 'Stockist Reports')
  assert.equal(match.path, '/reports/stockists')
  assert.equal(match.detail, 'Sales Management · Reports')
})

test('page search matches a group label and skips pages the user cannot open', () => {
  const office = searchPages('sales office', allow).map((item) => item.path)
  assert.ok(office.includes('/sales-office/locations'))
  assert.equal(searchPages('users', allow).length, 0)
  assert.equal(searchPages('users', allowUsers)[0].path, '/system/users')
})

test('page search stays in menu order and caps the list', () => {
  const pages = searchPages('sales', allow)
  assert.equal(pages.length, 8)
  assert.deepEqual(pages.slice(0, 2).map((item) => item.label), ['Stockist Sales', 'Monthly Sales'])
})

test('record groups drop off-app paths and blank groups', () => {
  const groups = acceptedRecordGroups([
    { type: 'products', label: 'Products', items: [{ id: 'product:1', label: 'Zephyr', detail: 'ZEP', path: '/products/list' }] },
    { type: 'bad', label: 'Bad', items: [{ id: 'x', label: 'Outside', detail: '', path: 'https://evil.example' }] },
    { type: 'empty', label: 'Empty', items: [] },
    null,
  ])
  assert.equal(groups.length, 1)
  assert.equal(groups[0].items[0].path, '/products/list')
})

test('pages come first and flatten in group order', () => {
  const groups = buildSearchGroups(
    [{ id: 'page:/dashboard', label: 'Dashboard', detail: '', path: '/dashboard' }],
    [{ type: 'locations', label: 'Locations', items: [{ id: 'location:1', label: 'Gujarat', detail: 'GUJ', path: '/sales-office/locations' }] }],
  )
  assert.deepEqual(groups.map((group) => group.label), ['Pages', 'Locations'])
  assert.deepEqual(flattenResults(groups).map((item) => item.label), ['Dashboard', 'Gujarat'])
})
