// Run with: npm run test:unit
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { splitChips } from './chips.js'

test('shows the first items and folds the rest into +N', () => {
  const result = splitChips(['Ahmedabad', 'Surat', 'Pune', 'Jaipur'], 2)
  assert.deepEqual(result.shown.map((item) => item.label), ['Ahmedabad', 'Surat'])
  assert.equal(result.hidden.length, 2)
  assert.equal(result.moreLabel, 'Pune, Jaipur')
})

test('never hides a single item behind +1', () => {
  const result = splitChips(['A', 'B', 'C'], 2)
  assert.equal(result.shown.length, 3)
  assert.equal(result.hidden.length, 0)
})

test('skips blanks and accepts objects', () => {
  const result = splitChips([{ key: 7, label: 'Gujarat' }, '', null, { key: 8, label: ' ' }])
  assert.deepEqual(result.shown, [{ key: 7, label: 'Gujarat' }])
})