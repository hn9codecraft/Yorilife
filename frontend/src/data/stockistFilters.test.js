// Run with: npm run test:unit
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { UNASSIGNED, filterStockists, licenceSummary } from './stockistFilters.js'

const today = '2026-11-14'
const offices = { 1: { id: 1, stateId: 10 }, 2: { id: 2, stateId: 20 } }
const officeOf = (stockist) => offices[stockist.locationHeadquartersId] ?? null
const stockists = [
  { id: 1, name: 'Ambica', code: 'STK-1', phone: '98790 01001', locationHeadquartersId: 1, assignedEmployeeId: 5, drugLicenceNo: 'D1', drugLicenceValidUpto: '2031-03-31' },
  { id: 2, name: 'Krishna', code: 'surat 12', email: 'k@example.test', locationHeadquartersId: 1, assignedEmployeeId: 6, drugLicenceValidUpto: '2026-06-30', foodLicenceValidUpto: '2031-01-01' },
  { id: 3, name: 'Jai Ganesh', locationHeadquartersId: 2, assignedEmployeeId: 6, fssaiLicenceValidUpto: '2026-11-30' },
  { id: 4, name: 'Marudhar', locationHeadquartersId: 2, assignedEmployeeId: null },
]
const ids = (list) => list.map((stockist) => stockist.id)

test('licence summary picks the worst licence', () => {
  assert.deepEqual(stockists.map((stockist) => licenceSummary(stockist, today)), ['valid', 'expired', 'soon', 'none'])
})

test('search matches name, code, email and phone digits', () => {
  assert.deepEqual(ids(filterStockists(stockists, { q: 'SURAT' }, { officeOf, today })), [2])
  assert.deepEqual(ids(filterStockists(stockists, { q: '9879001001' }, { officeOf, today })), [1])
  assert.deepEqual(ids(filterStockists(stockists, { q: 'k@example' }, { officeOf, today })), [2])
})

test('headquarter, state, assigned and licence filters combine', () => {
  assert.deepEqual(ids(filterStockists(stockists, { hq: '1' }, { officeOf, today })), [1, 2])
  assert.deepEqual(ids(filterStockists(stockists, { state: '20' }, { officeOf, today })), [3, 4])
  assert.deepEqual(ids(filterStockists(stockists, { assigned: UNASSIGNED }, { officeOf, today })), [4])
  assert.deepEqual(ids(filterStockists(stockists, { assigned: '6', licence: 'soon' }, { officeOf, today })), [3])
  assert.deepEqual(ids(filterStockists(stockists, { licence: 'none' }, { officeOf, today })), [4])
})