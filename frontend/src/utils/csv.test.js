// Run with: npm run test:unit
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { inspectCsv, parseCsv } from './csv.js'

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

test('flags an unclosed quote and an empty file', () => {
  assert.match(inspectCsv('a,b\n"1,2\n').errors.join(' '), /never closed/)
  assert.deepEqual(inspectCsv('').errors, ['The file has no content.'])
})
