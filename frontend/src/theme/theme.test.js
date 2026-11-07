import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readThemePreference, resolveTheme } from './theme.js'

test('a missing or unknown preference follows the system', () => {
  assert.equal(readThemePreference(null), 'system')
  assert.equal(readThemePreference(''), 'system')
  assert.equal(readThemePreference('blue'), 'system')
  assert.equal(resolveTheme('system', true), 'dark')
  assert.equal(resolveTheme('system', false), 'light')
})

test('an explicit light or dark choice wins over the system', () => {
  assert.equal(readThemePreference('light'), 'light')
  assert.equal(readThemePreference('dark'), 'dark')
  assert.equal(resolveTheme('light', true), 'light')
  assert.equal(resolveTheme('dark', false), 'dark')
})
