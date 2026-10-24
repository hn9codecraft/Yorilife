// Run with: npm run test:unit
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loginPathFor, safeRedirect } from './safeRedirect.js'

test('keeps in-app paths, query and hash', () => {
  assert.equal(safeRedirect('/products/list'), '/products/list')
  assert.equal(safeRedirect('/imports/data?type=sales#top'), '/imports/data?type=sales#top')
})

test('rejects external, protocol-relative and scheme URLs', () => {
  for (const bad of ['https://evil.example', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)', 'products', '', null, undefined, 42]) {
    assert.equal(safeRedirect(bad), '/dashboard', String(bad))
  }
})

test('rejects control characters and loops back to /login', () => {
  assert.equal(safeRedirect('/\n/evil.example'), '/dashboard')
  assert.equal(safeRedirect('/login'), '/dashboard')
  assert.equal(safeRedirect('/login?next=/x'), '/dashboard')
})

test('normalises dot segments without leaving the app', () => {
  assert.equal(safeRedirect('/a/../system/users'), '/system/users')
})

test('loginPathFor encodes the requested page', () => {
  assert.equal(loginPathFor({ pathname: '/system/users', search: '?q=a&b=1' }), '/login?next=%2Fsystem%2Fusers%3Fq%3Da%26b%3D1')
  assert.equal(loginPathFor({ pathname: '/dashboard', search: '' }), '/login')
})
