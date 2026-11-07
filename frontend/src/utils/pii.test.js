import { test } from 'node:test'
import assert from 'node:assert/strict'
import { maskEmail, maskPhone, maskRecord } from './pii.js'

test('masks email and phone but leaves names', () => {
  assert.equal(maskEmail('nirav@example.com'), 'n***@example.com')
  assert.equal(maskPhone('9876543210'), '******3210')
  assert.equal(maskPhone('1234'), '****')
  const masked = maskRecord({ Name: 'Nirav', Email: 'nirav@example.com', 'Phone number': '9876543210', Code: 'HQ-1' })
  assert.equal(masked.Name, 'Nirav')
  assert.equal(masked.Email, 'n***@example.com')
  assert.equal(masked['Phone number'], '******3210')
  assert.equal(masked.Code, 'HQ-1')
})
