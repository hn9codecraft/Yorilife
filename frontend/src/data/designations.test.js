import { test } from 'node:test'
import assert from 'node:assert/strict'
import { DESIGNATIONS, clearManagerSlots, designationLabel, designationsAbove } from './designations.js'

test('designations stay in hierarchy order with the full labels', () => {
  assert.deepEqual(DESIGNATIONS.map((item) => item.code), ['GM', 'ZSM', 'DSM', 'RBM', 'DBM', 'ABM', 'BO'])
  assert.equal(designationLabel('GM'), 'GM - General Manager')
  assert.equal(designationLabel('ZSM'), 'ZSM - Zonal Sales Manager')
  assert.equal(designationLabel('DSM'), 'DSM - Direct Sales Manager')
  assert.equal(designationLabel('RBM'), 'RBM - Region Business Manager')
  assert.equal(designationLabel('DBM'), 'DBM - Deputy Business Manager')
  assert.equal(designationLabel('ABM'), 'ABM - Area Business Manager')
  assert.equal(designationLabel('BO'), 'BO - Business Officer')
})

test('each designation asks only for the levels above it', () => {
  assert.deepEqual(designationsAbove('').map((item) => item.code), [])
  assert.deepEqual(designationsAbove('GM').map((item) => item.code), [])
  assert.deepEqual(designationsAbove('ZSM').map((item) => item.code), ['GM'])
  assert.deepEqual(designationsAbove('BO').map((item) => item.code), ['GM', 'ZSM', 'DSM', 'RBM', 'DBM', 'ABM'])
})

test('changing designation clears manager slots that are no longer above it', () => {
  const slots = { GM: '1', ZSM: '2', DSM: '3', RBM: '4', DBM: '5', ABM: '6', BO: '7' }
  assert.deepEqual(clearManagerSlots('ABM', slots), {
    GM: '1', ZSM: '2', DSM: '3', RBM: '4', DBM: '5', ABM: '', BO: '',
  })
  assert.deepEqual(clearManagerSlots('GM', slots).GM, '')
  assert.equal(clearManagerSlots('', slots).RBM, '')
})
