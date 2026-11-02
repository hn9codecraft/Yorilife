import test from 'node:test'
import assert from 'node:assert/strict'
import { nextStSequence, stCodeSuffix } from './stCode.js'

test('a stockist suffix keeps leading zeros and ignores a legacy code', () => {
  assert.equal(stCodeSuffix('ST-001'), '001')
  assert.equal(stCodeSuffix('st-010'), '010')
  assert.equal(stCodeSuffix('DEMO-STK-01'), null)
  assert.equal(stCodeSuffix('ST-WEST'), null)
})

test('the next stockist number is one past the highest suffix', () => {
  assert.equal(nextStSequence([{ code: 'ST-001' }, { code: 'DEMO-STK-09' }, { code: 'ST-010' }]), '011')
  assert.equal(nextStSequence([]), '001')
})
