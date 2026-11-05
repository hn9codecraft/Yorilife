// Run with: npm run test:unit
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { csvCell, toCsv } from './csv.js'

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