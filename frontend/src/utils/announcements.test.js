import { test } from 'node:test'
import assert from 'node:assert/strict'
import { changeText, checkUpload, formatBytes, htmlHasText, statusCounts, toIstInput } from './announcements.js'

test('UTC is shown and edited in India time', () => {
  assert.equal(toIstInput('2026-11-05T04:30:00Z'), '2026-11-05T10:00')
  assert.equal(toIstInput('2026-12-31T20:00:00Z'), '2027-01-01T01:30')
  assert.equal(toIstInput(null), '')
})

test('upload check follows the 5 MB, PDF/JPG/PNG rule', () => {
  assert.equal(checkUpload({ name: 'a.pdf', size: 10 }, 'file'), null)
  assert.match(checkUpload({ name: 'a.docx', size: 10 }, 'file'), /Only PDF/)
  assert.match(checkUpload({ name: 'a.pdf', size: 10 }, 'banner'), /JPG or PNG/)
  assert.match(checkUpload({ name: 'a.png', size: 6 * 1024 * 1024 }, 'banner'), /5 MB/)
})

test('small helpers', () => {
  assert.equal(formatBytes(2048), '2 KB')
  assert.equal(htmlHasText('<p><br></p>'), false)
  assert.equal(htmlHasText('<p>Hi</p>'), true)
  assert.deepEqual(statusCounts([{ status: 'draft' }, { status: 'draft' }, { status: 'published' }]), { all: 3, draft: 2, scheduled: 0, published: 1, expired: 0, recalled: 0 })
  assert.equal(changeText(true), 'Yes')
  assert.equal(changeText(null), '\u2014')
})