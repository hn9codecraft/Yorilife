import { test } from 'node:test'
import assert from 'node:assert/strict'
import { employeeLabel, fullName, headquartersChoices, headquartersInStates } from './employees.js'

const locations = [
  { id: 1, name: 'Gujarat', headquarters: [{ id: 10, name: 'Ahmedabad' }, { id: 11, name: 'Surat' }] },
  { id: 2, name: 'Bihar', headquarters: [{ id: 20, name: 'Patna' }] },
]

test('full name joins the parts that were typed', () => {
  assert.equal(fullName('Bina', 'Rao Officer'), 'Bina Rao Officer')
  assert.equal(fullName('Asha', ''), 'Asha')
  assert.equal(fullName('  ', 'Patel'), 'Patel')
  assert.equal(fullName('', null), '')
})

test('a person is labelled by full name, then code', () => {
  assert.equal(employeeLabel({ firstName: 'Gita', lastName: 'Manager', employeeCode: 'GM-1', id: 4 }), 'Gita Manager')
  assert.equal(employeeLabel({ employeeCode: 'GM-1', id: 4 }), 'GM-1')
  assert.equal(employeeLabel({ id: 4 }), 'Employee 4')
  assert.equal(employeeLabel(null), 'employee')
})

test('headquarters follow the selected states', () => {
  assert.deepEqual(headquartersChoices(locations, [], { requireState: true }), [])
  assert.deepEqual(headquartersChoices(locations, ['1'], { requireState: true }).map((choice) => choice.label), ['Ahmedabad', 'Surat'])
  assert.deepEqual(
    headquartersChoices(locations, ['1', '2'], { requireState: true }).map((choice) => choice.label),
    ['Ahmedabad (Gujarat)', 'Patna (Bihar)', 'Surat (Gujarat)'],
  )
  assert.equal(headquartersChoices(locations, []).length, 3)
})

test('removing a state removes the headquarters that belong to it', () => {
  assert.deepEqual(headquartersInStates(['10', '20', '11'], locations, ['1']), ['10', '11'])
  assert.deepEqual(headquartersInStates(['10', '20'], locations, []), [])
})
