import test from 'node:test'
import assert from 'node:assert/strict'
import { matchesFilters, sortByField } from './selectors.js'

test('names sort without regard to case', () => {
  const rows = [{ name: 'raj' }, { name: 'Ali' }, { name: 'Shubham' }]
  assert.deepEqual(sortByField(rows, 'name').map((row) => row.name), ['Ali', 'raj', 'Shubham'])
  assert.deepEqual(sortByField(rows, 'name', 'desc').map((row) => row.name), ['Shubham', 'raj', 'Ali'])
})

test('state and reports-to filters combine, and an empty filter matches everything', () => {
  assert.equal(matchesFilters(['guj'], 'shyam', { stateIds: [], reportIds: [] }), true)
  assert.equal(matchesFilters(['guj', 'ap'], 'shyam', { stateIds: ['ap'], reportIds: ['shyam'] }), true)
  assert.equal(matchesFilters(['guj'], 'shyam', { stateIds: ['bihar'], reportIds: [] }), false)
  assert.equal(matchesFilters(['guj'], 'ali', { stateIds: ['guj'], reportIds: ['shyam'] }), false)
})

test('employee codes sort by the numeric suffix', () => {
  const rows = [
    { code: 'ER-M1-010' },
    { code: 'ER-M1-002' },
    { code: 'ER-M1-001' },
    { code: '' },
  ]
  assert.deepEqual(sortByField(rows, 'code').map((row) => row.code), ['', 'ER-M1-001', 'ER-M1-002', 'ER-M1-010'])
  assert.deepEqual(sortByField(rows, 'code', 'desc').map((row) => row.code), ['ER-M1-010', 'ER-M1-002', 'ER-M1-001', ''])
})
