import test from 'node:test'
import assert from 'node:assert/strict'
import { hqCodeSuffix, nextHqSequence } from './hqCode.js'

test('a headquarters suffix keeps leading zeros and ignores a legacy code', () => {
  assert.equal(hqCodeSuffix('HQ-001'), '001')
  assert.equal(hqCodeSuffix('DEMO-HQ-01'), null)
  assert.equal(hqCodeSuffix('HQ-WEST'), null)
})

test('the next headquarters number is one past the highest suffix', () => {
  assert.equal(nextHqSequence([{ code: 'HQ-001' }, { code: 'DEMO-HQ-09' }, { code: 'HQ-010' }]), '011')
  assert.equal(nextHqSequence([]), '001')
})
