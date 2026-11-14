import test from 'node:test'
import assert from 'node:assert/strict'
import { filterOptions, moveActiveIndex, normalizeSearch } from './searchOptions.js'

const people = [
  { value: '1', label: 'Asha Mehta (E-101)', keywords: ['E-101'] },
  { value: '2', label: 'Ravi Shah (E-202)', keywords: ['E-202'] },
  { value: '3', label: 'Mehul Patel (B-7)', keywords: ['B-7'] },
  { value: '4', label: 'José Fernandes', keywords: ['GOA-9'] },
]

test('an empty query returns the list in order, up to the limit', () => {
  assert.deepEqual(filterOptions(people, '').items.map((o) => o.value), ['1', '2', '3', '4'])
  const limited = filterOptions(people, '   ', 2)
  assert.equal(limited.items.length, 2)
  assert.equal(limited.total, 4)
})

test('matches name or code in any letter case', () => {
  assert.deepEqual(filterOptions(people, 'ravi').items.map((o) => o.value), ['2'])
  assert.deepEqual(filterOptions(people, 'e-101').items.map((o) => o.value), ['1'])
  assert.deepEqual(filterOptions(people, 'goa-9').items.map((o) => o.value), ['4'])
})

test('every typed word must match and accents are ignored', () => {
  assert.deepEqual(filterOptions(people, 'mehta asha').items.map((o) => o.value), ['1'])
  assert.deepEqual(filterOptions(people, 'jose').items.map((o) => o.value), ['4'])
  assert.deepEqual(filterOptions(people, 'asha ravi').items, [])
})

test('labels starting with the query rank first, then word starts, then the rest', () => {
  const ranked = filterOptions(people, 'meh').items.map((o) => o.value)
  assert.deepEqual(ranked, ['3', '1'])
  const contains = filterOptions([{ value: 'a', label: 'Xmeh' }, { value: 'b', label: 'Meh' }], 'meh').items.map((o) => o.value)
  assert.deepEqual(contains, ['b', 'a'])
})

test('large lists only return the first matches but report the total', () => {
  const many = Array.from({ length: 5000 }, (_, i) => ({ value: String(i), label: `Employee ${i}` }))
  const result = filterOptions(many, 'employee', 100)
  assert.equal(result.items.length, 100)
  assert.equal(result.total, 5000)
  assert.equal(filterOptions(many, 'employee 4999').items[0].value, '4999')
})

test('no results gives an empty list', () => {
  assert.deepEqual(filterOptions(people, 'zzz'), { items: [], total: 0 })
  assert.deepEqual(filterOptions(null, 'a'), { items: [], total: 0 })
})

test('arrow keys wrap around and an empty list has no active item', () => {
  assert.equal(moveActiveIndex(-1, 1, 3), 0)
  assert.equal(moveActiveIndex(-1, -1, 3), 2)
  assert.equal(moveActiveIndex(2, 1, 3), 0)
  assert.equal(moveActiveIndex(0, -1, 3), 2)
  assert.equal(moveActiveIndex(0, 1, 0), -1)
})

test('normalizing trims, lowercases and folds spaces', () => {
  assert.equal(normalizeSearch('  Ahmed  ABAD '), 'ahmed abad')
  assert.equal(normalizeSearch(null), '')
})