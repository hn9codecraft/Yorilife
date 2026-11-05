import { test } from 'node:test'
import assert from 'node:assert/strict'
import { checkImportFile, formatBytes, linkedRollbackItems, previewSummary, rollbackSelectionSummary, selectableRollbackIds, toggleRollbackItem } from './imports.js'

test('checkImportFile accepts only .xlsx and rejects empty and too-large files', () => {
  const max = 10 * 1024 * 1024
  const wrongType = 'Only .xlsx files can be imported (not .xls, .xlsm or .csv).'
  assert.equal(checkImportFile(null, max), 'Choose an Excel (.xlsx) file.')
  assert.equal(checkImportFile({ name: 'data.csv', size: 10 }, max), wrongType)
  assert.equal(checkImportFile({ name: 'data.xlsm', size: 10 }, max), wrongType)
  assert.equal(checkImportFile({ name: 'data.xls', size: 10 }, max), wrongType)
  assert.equal(checkImportFile({ name: 'data.xlsx', size: 0 }, max), 'The file is empty.')
  assert.equal(checkImportFile({ name: 'data.xlsx', size: max + 1 }, max), 'The file is larger than 10.0 MB.')
  assert.equal(checkImportFile({ name: 'Sales Office.XLSX', size: 100 }, max), null)
})

test('previewSummary groups rows by data type and counts warnings', () => {
  const summary = previewSummary([
    { type: 'location', typeLabel: 'Location', action: 'created', warnings: ['Address ignored'] },
    { type: 'stockist', typeLabel: 'Stockist', action: 'error', warnings: [] },
    { type: 'location', typeLabel: 'Location', action: 'unchanged' },
    { type: null, typeLabel: null, action: 'error' },
  ])
  assert.deepEqual(summary.types, [
    { key: 'location', label: 'Location', total: 2, errors: 0 },
    { key: 'stockist', label: 'Stockist', total: 1, errors: 1 },
    { key: 'unknown', label: 'Unknown type', total: 1, errors: 1 },
  ])
  assert.equal(summary.warnings, 1)
  assert.equal(summary.savingTypes, 1)
})

test('formatBytes', () => {
  assert.equal(formatBytes(512), '512 B')
  assert.equal(formatBytes(2048), '2.0 KB')
})

test('rollback selection: parents pull in created children, unchecking a child drops its parents', () => {
  const items = [
    { id: 5, action: 'created', result: 'deleted', children: [] },     // stockist
    { id: 4, action: 'created', result: 'deleted', children: [5] },    // headquarters
    { id: 3, action: 'created', result: 'deleted', children: [4] },    // 2nd line
    { id: 2, action: 'created', result: 'deleted', children: [3] },    // 3rd line
    { id: 1, action: 'updated', result: 'restored', children: [] },    // Nirav phone restore
    { id: 6, action: 'created', result: 'skipped', children: [] },     // conflict
  ]
  assert.deepEqual(selectableRollbackIds(items), [5, 4, 3, 2, 1])
  const all = new Set(selectableRollbackIds(items))
  const withoutHq = toggleRollbackItem(items, all, 4, false)
  assert.deepEqual([...withoutHq].sort(), [1, 5], 'unchecking the HQ unchecks the managers above it, the stockist stays')
  const rechecked = toggleRollbackItem(items, new Set([1]), 2, true)
  assert.deepEqual([...rechecked].sort(), [1, 2, 3, 4, 5], 'checking the 3rd line checks everything below it')
  assert.deepEqual([...toggleRollbackItem(items, new Set(), 1, true)], [1], 'a restore does not pull in other rows')
  assert.deepEqual([...toggleRollbackItem(items, new Set(), 6, true)], [], 'a skipped row cannot be chosen')
  assert.deepEqual(rollbackSelectionSummary(items, rechecked), { restore: 1, delete: 4, skip: 1, notSelected: 0 })
  assert.deepEqual(rollbackSelectionSummary(items, withoutHq), { restore: 1, delete: 1, skip: 1, notSelected: 3 })
})

test('linkedRollbackItems lists every created record below one, at all levels', () => {
  const items = [
    { id: 1, action: 'created', result: 'deleted', children: [2, 4] }, // location
    { id: 2, action: 'created', result: 'deleted', children: [3] },    // location HQ
    { id: 3, action: 'created', result: 'deleted', children: [5] },    // headquarters
    { id: 4, action: 'created', result: 'deleted', children: [3] },    // 1st line
    { id: 5, action: 'created', result: 'deleted', children: [] },     // stockist
    { id: 6, action: 'updated', result: 'restored', children: [] },
  ]
  assert.deepEqual(linkedRollbackItems(items, 1).map((item) => item.id), [2, 3, 4, 5])
  assert.deepEqual(linkedRollbackItems(items, 2).map((item) => item.id), [3, 5])
  assert.deepEqual(linkedRollbackItems(items, 5), [])
  assert.deepEqual(linkedRollbackItems(items, 6), [])
  assert.deepEqual([...toggleRollbackItem(items, new Set([1, 2, 3, 4, 5, 6]), 5, false)].sort(), [6], 'unticking the stockist unticks every record above it')
})
