// Run with: npm run test:unit
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { buildCsv, csvCell, csvFilename, joinCell, toCsv } from './csv.js'

test('quotes cells with commas, quotes or line breaks', () => {
  assert.equal(csvCell('plain'), 'plain')
  assert.equal(csvCell('a, "b"'), '"a, ""b"""')
  assert.equal(csvCell('two\nlines'), '"two\nlines"')
  assert.equal(csvCell(null), '')
})

test('writes text cells so a leading zero stays in the file', () => {
  const text = toCsv(['Phone Number', 'Note'], [{ 'Phone Number': '09000000141', Note: 'a, "b"' }])
  assert.ok(text.includes('09000000141,"a, ""b"""'))
  assert.ok(text.includes('Phone Number,Note'))
})
test('dated file names are lowercase and safe', () => {
  assert.equal(csvFilename('Employee List', new Date(2026, 10, 4)), 'employee-list-2026-11-04.csv')
  assert.equal(csvFilename('  ', new Date(2026, 0, 31)), 'export-2026-01-31.csv')
})

test('exports mask email and phone unless asked not to', () => {
  const records = [{ Name: 'Ambica', Email: 'ambica@example.test', 'Phone number': '9879001001' }]
  const masked = buildCsv(['Name', 'Email', 'Phone number'], records)
  assert.ok(masked.includes('Ambica,a***@example.test,******1001'))
  assert.ok(buildCsv(['Email'], records, { mask: false }).includes('ambica@example.test'))
})

test('list cells join names and skip blanks', () => {
  assert.equal(joinCell(['Gujarat', '', null, ' Maharashtra ']), 'Gujarat; Maharashtra')
  assert.equal(joinCell(undefined), '')
})
