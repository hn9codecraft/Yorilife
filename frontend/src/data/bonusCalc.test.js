import { test } from 'node:test'
import assert from 'node:assert/strict'
import {
  addMoney,
  columnTotal,
  filterBonusRows,
  isFinancialYear,
  lineFigures,
  monthBonus,
  monthTotals,
  multiplyMoney,
  normalizeQuantity,
  quantityError,
  stockistSubtitle,
  stockistTotals,
  sumMoney,
} from './bonusCalc.js'

test('financial year labels', () => {
  assert.equal(isFinancialYear('2026-27'), true)
  assert.equal(isFinancialYear('2099-00'), true)
  assert.equal(isFinancialYear('2026-2027'), false)
  assert.equal(isFinancialYear('2026-28'), false)
})

test('quantities reject negatives, extra decimals and huge amounts', () => {
  assert.equal(normalizeQuantity('10.5'), '10.50')
  assert.equal(normalizeQuantity(''), '0.00')
  assert.equal(normalizeQuantity('-1'), null)
  assert.equal(normalizeQuantity('1.234'), null)
  assert.equal(quantityError('-2', 'OP'), 'OP cannot be negative.')
  assert.equal(quantityError('1.234', 'OP'), 'OP must be a number with at most two decimal places.')
  assert.equal(quantityError('', 'OP'), null)
  assert.equal(quantityError('10000000000.00', 'OP'), 'OP is too large.')
})

test('total product and bonus use the same rounding as the API', () => {
  assert.equal(addMoney('10.00', '5.50'), '15.50')
  assert.equal(addMoney('1.25', '2.75'), '4.00')
  assert.equal(multiplyMoney('4.00', '80.00'), '320.00')
  assert.equal(multiplyMoney('10.00', '80.50'), '805.00')
  assert.equal(multiplyMoney('1.10', '1.10'), '1.21')
  assert.equal(multiplyMoney('0.05', '0.10'), '0.01')
  assert.equal(multiplyMoney('0.04', '0.10'), '0.00')
  assert.equal(multiplyMoney('4.00', null), '0.00')
  assert.equal(sumMoney(['320.00', '21.00']), '341.00')
  assert.equal(lineFigures({ netRate: '80.00', openingQty: '10', newPurchaseQty: '5.50', productSalesQty: '4' }).totalProduct, '15.50')
  assert.equal(lineFigures({ netRate: '80.00', openingQty: '10', newPurchaseQty: '5.50', productSalesQty: '4' }).bonusAmount, '320.00')
  assert.equal(lineFigures({ netRate: '80.00', openingQty: '', newPurchaseQty: '', productSalesQty: '' }).totalProduct, null)
  assert.equal(lineFigures({ netRate: '10.50', openingQty: '1', newPurchaseQty: '1', productSalesQty: '2' }).bonusAmount, '21.00')
})

test('stockist and month totals ignore blank rows and sum the rest', () => {
  const lines = [
    { netRate: '80.00', openingQty: '10', newPurchaseQty: '5.50', productSalesQty: '4' },
    { netRate: '10.50', openingQty: '', newPurchaseQty: '', productSalesQty: '2' },
    { netRate: '5.00', openingQty: '', newPurchaseQty: '', productSalesQty: '' },
  ]
  const totals = stockistTotals(lines)
  assert.equal(totals.openingQty, '10.00')
  assert.equal(totals.newPurchaseQty, '5.50')
  assert.equal(totals.totalProduct, '15.50')
  assert.equal(totals.productSalesQty, '6.00')
  assert.equal(totals.bonusAmount, '341.00')
  assert.equal(columnTotal(['', '']), null)
  assert.equal(monthBonus([{ lines }, { lines: [] }]), '341.00')
  assert.equal(monthBonus([{ lines: [] }]), null)
})

test('month totals add every stockist and a subtitle names the headquarters', () => {
  const lines = [
    { netRate: '80.00', openingQty: '10', newPurchaseQty: '5.50', productSalesQty: '4' },
  ]
  const other = [
    { netRate: '10.50', openingQty: '1', newPurchaseQty: '', productSalesQty: '2' },
  ]
  const totals = monthTotals([{ lines }, { lines: other }])
  assert.equal(totals.openingQty, '11.00')
  assert.equal(totals.bonusAmount, '341.00')
  assert.equal(monthBonus([{ lines }, { lines: other }]), '341.00')
  assert.equal(stockistSubtitle({ headquartersName: 'Surat', headquartersCode: '12', assigned: true }, 12), 'HQ: Surat (12) · 12 products')
  assert.equal(stockistSubtitle({ headquartersName: 'Surat', assigned: true }, 1), 'HQ: Surat · 1 product')
  assert.equal(stockistSubtitle({ assigned: false }, 0), '0 products · No longer assigned to this employee')
})

test('employee search matches name and code', () => {
  const rows = [
    { employeeName: 'Asha Rao', employeeCode: 'EMP-1', employeeId: '1' },
    { employeeName: 'Bina Shah', employeeCode: 'EMP-2', employeeId: '2' },
  ]
  assert.equal(filterBonusRows(rows, 'emp-2').length, 1)
  assert.equal(filterBonusRows(rows, 'rao')[0].employeeName, 'Asha Rao')
  assert.equal(filterBonusRows(rows, '').length, 2)
})
