// Run with: npm run test:unit
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { formatDateTime, headquarterUsage } from './headquarterList.js'

test('counts stockists and employees per headquarter', () => {
  const usage = headquarterUsage(
    [{ locationHeadquartersId: 1 }, { locationHeadquartersId: '1' }, { locationHeadquartersId: null }, { locationHeadquartersId: 2 }],
    [{ locationHeadquartersIds: [1, 2] }, { locationHeadquartersIds: ['1', 1] }, {}],
  )
  assert.equal(usage.stockists.get('1'), 2)
  assert.equal(usage.stockists.get('2'), 1)
  assert.equal(usage.employees.get('1'), 2)
  assert.equal(usage.employees.get('2'), 1)
})

test('employees can be unknown', () => {
  assert.equal(headquarterUsage([], null).employees.size, 0)
})

test('invalid timestamps give an empty string', () => {
  assert.equal(formatDateTime(null), '')
  assert.ok(formatDateTime('2026-11-14T09:56:00Z').includes('2026'))
})