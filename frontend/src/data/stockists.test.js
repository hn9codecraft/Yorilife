import test from 'node:test'
import assert from 'node:assert/strict'
import { employeeLabel, findHeadquarter, formatDate, headquarterOptions, licenceStatus, stockistCodeTaken } from './stockists.js'

test('a licence date is expired, expiring soon or valid', () => {
  assert.equal(licenceStatus('2026-11-13', '2026-11-14'), 'expired')
  assert.equal(licenceStatus('2026-11-14', '2026-11-14'), 'soon')
  assert.equal(licenceStatus('2026-12-10', '2026-11-14'), 'soon')
  assert.equal(licenceStatus('2027-03-31', '2026-11-14'), 'valid')
  assert.equal(licenceStatus('', '2026-11-14'), null)
  assert.equal(licenceStatus(null, '2026-11-14'), null)
})

test('dates show as day-month-year', () => {
  assert.equal(formatDate('2027-03-31'), '31-03-2027')
  assert.equal(formatDate(null), '')
})

test('the stockist code check ignores spaces, letter case and the record being edited', () => {
  const stockists = [{ id: '1', code: 'AB-01' }, { id: '2', code: null }]
  assert.equal(stockistCodeTaken(stockists, ' ab-01 ', null), true)
  assert.equal(stockistCodeTaken(stockists, 'AB-01', '1'), false)
  assert.equal(stockistCodeTaken(stockists, '', null), false)
  assert.equal(stockistCodeTaken(stockists, 'AB-02', null), false)
})

test('employee labels prefer first and last name, then name, and add the code', () => {
  assert.equal(employeeLabel({ firstName: 'Asha', lastName: 'Mehta', employeeCode: 'E1' }), 'Asha Mehta (E1)')
  assert.equal(employeeLabel({ name: 'Ravi Shah', employeeCode: null }), 'Ravi Shah')
  assert.equal(employeeLabel({ employeeCode: 'E9' }), 'Unnamed employee (E9)')
})

test('headquarter options come from the state list with the state name', () => {
  const locations = [
    { id: 1, name: 'Gujarat', headquarters: [{ id: 5, name: 'Surat', code: 'HQ-5' }, { id: 4, name: 'Ahmedabad', code: null }] },
    { id: 2, name: 'Goa', headquarters: [] },
  ]
  assert.deepEqual(headquarterOptions(locations).map((option) => option.label), ['Ahmedabad — Gujarat', 'Surat (HQ-5) — Gujarat'])
  assert.equal(findHeadquarter(locations, '5').stateName, 'Gujarat')
  assert.equal(findHeadquarter(locations, ''), null)
})