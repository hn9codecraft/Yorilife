import test from 'node:test'
import assert from 'node:assert/strict'
import { tableMinWidth } from './tableWidth.js'

test('table min-width adds up every column, actions and the select column', () => {
  const columns = [{ key: 'name', width: 'xl' }, { key: 'mrp', width: 'money' }, { key: 'scheme' }, { key: 'actions' }]
  assert.equal(tableMinWidth(columns), 'calc(var(--table-col-xl) + var(--table-col-money) + var(--table-col-auto) + var(--table-col-actions))')
  assert.equal(tableMinWidth([{ key: 'name', width: 'sm' }], true), 'calc(var(--table-select-col-width) + var(--table-col-sm))')
  assert.equal(tableMinWidth([]), undefined)
})

test('pinned columns count only their small minimum, not their width token', () => {
  const columns = [{ key: 'name', width: 'xl', sticky: 'start' }, { key: 'code', width: 'sm' }, { key: 'more', sticky: 'end' }]
  assert.equal(tableMinWidth(columns), 'calc(var(--table-col-pinned-min) + var(--table-col-sm) + var(--table-col-actions))')
})