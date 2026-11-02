import test from 'node:test'
import assert from 'node:assert/strict'
import { managerCodePrefix, managerCodeSuffix, nextManagerSequence } from './managerCode.js'

test('prefixes follow the manager level', () => {
  assert.equal(managerCodePrefix(3), 'ER-M3-')
  assert.equal(managerCodePrefix(2), 'ER-M2-')
  assert.equal(managerCodePrefix(1), 'ER-M1-')
})

test('suffix keeps leading zeros and ignores another level or a legacy code', () => {
  assert.equal(managerCodeSuffix(3, 'ER-M3-001'), '001')
  assert.equal(managerCodeSuffix(3, 'ER-M3-010'), '010')
  assert.equal(managerCodeSuffix(2, 'ER-M3-001'), null)
  assert.equal(managerCodeSuffix(3, 'DEMO-M3-SHYAM'), null)
})

test('the next number skips gaps only by taking one past the highest suffix', () => {
  const managers = [
    { level: 3, code: 'ER-M3-001' },
    { level: 3, code: 'ER-M3-003' },
    { level: 3, code: 'DEMO-M3-SHYAM' },
    { level: 2, code: 'ER-M2-009' },
  ]
  assert.equal(nextManagerSequence(managers, 3), '004')
  assert.equal(nextManagerSequence(managers, 2), '010')
  assert.equal(nextManagerSequence([], 1), '001')
})
