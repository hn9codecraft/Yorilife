import test from 'node:test'
import assert from 'node:assert/strict'
import { contentColumnWidth, stickyEndRight, tableMinWidth } from './tableWidth.js'

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

test('a shared name column is as wide as the longest label', () => {
  assert.equal(contentColumnWidth(['Drops', 'Best Selling']), 'calc(12ch + 2 * var(--table-cell-padding-x))')
  assert.equal(contentColumnWidth([]), 'calc(0ch + 2 * var(--table-cell-padding-x))')
})

test('a sticky money total keeps its width and sits to the left of pinned actions', () => {
  const columns = [
    { key: 'name', sticky: 'start' },
    { key: 'apr', width: 'money' },
    { key: 'total', width: 'money', sticky: 'end' },
    { key: 'actions', sticky: 'end' },
  ]
  assert.equal(
    tableMinWidth(columns),
    'calc(var(--table-col-pinned-min) + var(--table-col-money) + var(--table-col-money) + var(--table-col-actions))',
  )
  assert.equal(stickyEndRight(columns, columns[2]), 'calc(var(--table-col-actions))')
  assert.equal(stickyEndRight(columns, columns[3]), '0px')
  assert.equal(stickyEndRight(columns, columns[1]), undefined)
})