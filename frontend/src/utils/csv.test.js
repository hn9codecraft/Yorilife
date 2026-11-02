// Run with: npm run test:unit
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { inspectCsv, parseCsv, toCsv } from './csv.js'

test('parses quoted fields, escaped quotes, commas, CRLF and a BOM', () => {
  const { rows, unterminatedQuote } = parseCsv('﻿a,"b, c","say ""hi"""\r\n1,2,3\r\n')
  assert.deepEqual(rows, [['a', 'b, c', 'say "hi"'], ['1', '2', '3']])
  assert.equal(unterminatedQuote, false)
})

test('reports structural problems', () => {
  const result = inspectCsv('a,b,A\n1,2\n')
  assert.match(result.errors.join(' '), /Duplicate column names: A/)
  assert.match(result.errors.join(' '), /line 2 \(2\)/)
})

test('writes text cells so a leading zero stays in the file', () => {
  const text = toCsv(['Phone Number', 'Note'], [{ 'Phone Number': '09000000141', Note: 'a, "b"' }])
  const parsed = parseCsv(text)
  assert.equal(parsed.rows[1][0], '09000000141')
  assert.equal(parsed.rows[1][1], 'a, "b"')
})

test('flags an unclosed quote and an empty file', () => {
  assert.match(inspectCsv('a,b\n"1,2\n').errors.join(' '), /never closed/)
  assert.deepEqual(inspectCsv('').errors, ['The file has no content.'])
})
