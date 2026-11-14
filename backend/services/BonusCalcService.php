<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BonusCalcRepository;
use App\Utils\HttpException;

/**
 * Bonus Calc: a financial-year list of Sales Office employees, and a month
 * sheet of quantities per assigned stockist and product.
 *
 * Rates (MRP, PTR, PTS, scheme, net rate, packaging) are copied onto the line
 * the first time it is saved. Later edits keep those rates, so a price change
 * on the product list does not change a month that was already saved.
 * Bonus amount = product sales × stored net rate (two decimal places, half up).
 * Total product = opening quantity + new purchase.
 */
final class BonusCalcService
{
    public function __construct(private readonly BonusCalcRepository $records = new BonusCalcRepository())
    {
    }

    /** @return array<string, mixed> */
    public function list(string $yearInput): array
    {
        $year = $this->year($yearInput);

        return $this->presentList($year);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function add(array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['financialYear', 'employeeIds'], $errors);
        $year = BonusCalcMath::financialYear($input['financialYear'] ?? null);
        if ($year === null) {
            $errors['financialYear'] = 'Choose a financial year such as 2026-27.';
        }
        $ids = Input::idList($input['employeeIds'] ?? null);
        if ($ids === null) {
            $errors['employeeIds'] = 'Choose employees from the list.';
        } elseif ($ids === []) {
            $errors['employeeIds'] = 'Choose at least one employee.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $year = (string) $year;
        $ids = $ids ?? [];

        $created = $this->records->transaction(function () use ($year, $ids): array {
            $made = [];
            foreach ($ids as $employeeId) {
                $employee = $this->requireActiveEmployee($employeeId);
                if ($this->records->activeRowForEmployee($year, $employeeId) !== null) {
                    throw HttpException::validation(
                        ['employeeIds' => $this->employeeLabel($employee) . ' is already in Financial Year ' . $year . '.']
                    );
                }
                $id = $this->records->insertRowFor($year, $employeeId);
                $label = $this->employeeLabel($employee);
                EntityHistory::recordDiff('bonus_calc_row', $id, $label, [], [
                    'Financial year' => $year,
                    'Employee' => $label,
                    'Status' => 'Active',
                ]);
                AuditLogger::record([
                    'module' => 'sales',
                    'action' => 'create',
                    'targetType' => 'bonus_calc_row',
                    'targetId' => $id,
                    'targetLabel' => $label . ' · ' . $year,
                ]);
                $made[] = $id;
            }

            return $made;
        });

        return $this->presentList($year) + ['added' => array_map(Presenter::id(...), $created)];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function reassign(int $id, array $input): array
    {
        $current = $this->requireActiveRow($id);
        $errors = [];
        Input::onlyFields($input, ['employeeId'], $errors);
        $employeeId = Input::id($input['employeeId'] ?? null);
        if ($employeeId === null) {
            $errors['employeeId'] = 'Choose an employee.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $employeeId = (int) $employeeId;
        $year = (string) $current['financial_year'];
        if ($employeeId === (int) $current['sales_employee_id']) {
            return $this->presentList($year);
        }
        $employee = $this->requireActiveEmployee($employeeId, 'employeeId');
        $occupied = $this->records->activeRowForEmployee($year, $employeeId);
        if ($occupied !== null) {
            throw HttpException::validation(
                ['employeeId' => $this->employeeLabel($employee) . ' is already in Financial Year ' . $year . '.']
            );
        }
        if ($this->records->employeeHasLines($year, $employeeId)) {
            throw HttpException::validation(
                ['employeeId' => 'That employee already has bonus figures for this financial year. Add them on their own row.']
            );
        }

        $fromId = (int) $current['sales_employee_id'];
        $fromLabel = $this->rowLabel($current);
        $toLabel = $this->employeeLabel($employee);
        $this->records->transaction(function () use ($id, $year, $fromId, $employeeId, $fromLabel, $toLabel): void {
            $this->records->moveLines($year, $fromId, $employeeId);
            $this->records->reassign($id, $employeeId);
            EntityHistory::recordDiff('bonus_calc_row', $id, $toLabel, [
                'Employee' => $fromLabel,
            ], [
                'Employee' => $toLabel,
            ]);
            AuditLogger::record([
                'module' => 'sales',
                'action' => 'update',
                'targetType' => 'bonus_calc_row',
                'targetId' => $id,
                'targetLabel' => $toLabel . ' · ' . $year,
                'details' => ['fields' => ['Employee']],
            ]);
        });

        return $this->presentList($year);
    }

    /** @return array<string, mixed> */
    public function remove(int $id): array
    {
        $current = $this->requireActiveRow($id);
        $year = (string) $current['financial_year'];
        $this->records->transaction(function () use ($current): void {
            $this->forgetRow($current);
        });

        return $this->presentList($year);
    }

    /**
     * Remove several employees from one financial year. Saved month lines stay,
     * so adding the employee again brings the figures back.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function removeMany(array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['financialYear', 'ids'], $errors);
        $year = BonusCalcMath::financialYear($input['financialYear'] ?? null);
        if ($year === null) {
            $errors['financialYear'] = 'Choose a financial year such as 2026-27.';
        }
        $ids = Input::idList($input['ids'] ?? null);
        if ($ids === null) {
            $errors['ids'] = 'Choose employees to remove.';
        } elseif ($ids === []) {
            $errors['ids'] = 'Choose at least one employee.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $year = (string) $year;
        $ids = $ids ?? [];
        $this->records->transaction(function () use ($year, $ids): void {
            foreach ($ids as $id) {
                $row = $this->records->findRow($id);
                if ($row === null || $row['deleted_at'] !== null || (string) $row['financial_year'] !== $year) {
                    throw HttpException::validation(['ids' => 'Choose employees who are on this financial year.']);
                }
                $this->forgetRow($row);
            }
        });

        return $this->presentList($year);
    }

    /** @return array<string, mixed> */
    public function month(string $yearInput, int $employeeId, string $monthInput): array
    {
        [$year, $month, $employee] = $this->sheetKey($yearInput, $employeeId, $monthInput);

        return $this->presentMonth($year, $month, $employee);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function saveMonth(string $yearInput, int $employeeId, string $monthInput, array $input): array
    {
        [$year, $month, $employee] = $this->sheetKey($yearInput, $employeeId, $monthInput);
        $parsed = $this->parseLines($input);
        $existing = $this->indexLines($this->records->linesForMonth($year, $employeeId, $month));
        $allowedStockists = $this->allowedStockists($employeeId, $existing);
        $products = $this->records->productsByIds($this->productIdsForSave($parsed, $existing));
        $errors = [];
        $seen = [];
        foreach ($parsed as $index => $line) {
            $key = $line['stockistId'] . ':' . $line['productId'];
            if (isset($seen[$key])) {
                $errors['lines'] = 'Each product can only be entered once for a stockist.';
                continue;
            }
            $seen[$key] = true;
            $prefix = 's' . $line['stockistId'] . '-p' . $line['productId'];
            if (!isset($allowedStockists[$line['stockistId']])) {
                $errors[$prefix] = 'That stockist is not assigned to this employee.';
            }
            $product = $products[$line['productId']] ?? null;
            $slot = $existing[$line['stockistId']][$line['productId']] ?? null;
            if ($product === null || ($product['deleted_at'] !== null && $slot === null)) {
                $errors[$prefix . '-productId'] = 'Choose a product from the product list.';
            }
            foreach (['openingQty' => 'OP', 'newPurchaseQty' => 'New purchase', 'productSalesQty' => 'Product sales'] as $field => $label) {
                [, $error] = BonusCalcMath::quantity($line[$field], $label);
                if ($error !== null) {
                    $errors[$prefix . '-' . $field] = $error;
                }
            }
            unset($index);
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $label = $this->employeeLabel($employee) . ' · ' . BonusCalcMath::MONTHS[$month] . ' ' . $year;
        $changed = 0;
        $this->records->transaction(function () use ($parsed, $existing, $products, $year, $employeeId, $month, $label, &$changed): void {
            foreach ($parsed as $line) {
                $slot = $existing[$line['stockistId']][$line['productId']] ?? null;
                $opening = BonusCalcMath::quantity($line['openingQty'], 'OP')[0] ?? '0.00';
                $purchase = BonusCalcMath::quantity($line['newPurchaseQty'], 'New purchase')[0] ?? '0.00';
                $sales = BonusCalcMath::quantity($line['productSalesQty'], 'Product sales')[0] ?? '0.00';
                $allZero = $opening === '0.00' && $purchase === '0.00' && $sales === '0.00';
                if ($allZero) {
                    if ($slot !== null) {
                        $this->forgetLine($slot, $label);
                        $changed++;
                    }
                    continue;
                }
                $product = $products[$line['productId']];
                $netRate = $slot !== null ? $this->nullableMoney($slot['net_rate']) : $this->nullableMoney($product['net_rate']);
                [$bonus, $bonusError] = BonusCalcMath::bonusAmount($sales, $netRate);
                if ($bonusError !== null || $bonus === null) {
                    throw HttpException::validation([
                        's' . $line['stockistId'] . '-p' . $line['productId'] . '-productSalesQty' => $bonusError ?? 'Bonus amount is too large.',
                    ]);
                }
                $after = [
                    'Opening qty' => $opening,
                    'New purchase' => $purchase,
                    'Product sales' => $sales,
                    'Net rate' => $netRate,
                    'Bonus amount' => $bonus,
                ];
                if ($slot === null) {
                    $id = $this->records->insertLine([
                        'financial_year' => $year,
                        'sales_employee_id' => $employeeId,
                        'month' => $month,
                        'stockist_id' => $line['stockistId'],
                        'product_id' => $line['productId'],
                        'product_name' => (string) $product['name'],
                        'packaging_unit' => $product['packaging_unit'],
                        'mrp' => $this->nullableMoney($product['mrp']),
                        'ptr' => $this->nullableMoney($product['ptr']),
                        'pts' => $this->nullableMoney($product['pts']),
                        'scheme' => $product['scheme'],
                        'net_rate' => $netRate,
                        'opening_qty' => $opening,
                        'new_purchase_qty' => $purchase,
                        'product_sales_qty' => $sales,
                        'bonus_amount' => $bonus,
                    ]);
                    EntityHistory::recordDiff('bonus_calc_line', $id, $this->lineLabel($label, $product['name']), [], $after);
                    $changed++;
                    continue;
                }
                $before = [
                    'Opening qty' => BonusCalcMath::moneyString($slot['opening_qty']),
                    'New purchase' => BonusCalcMath::moneyString($slot['new_purchase_qty']),
                    'Product sales' => BonusCalcMath::moneyString($slot['product_sales_qty']),
                    'Net rate' => $this->nullableMoney($slot['net_rate']),
                    'Bonus amount' => BonusCalcMath::moneyString($slot['bonus_amount']),
                ];
                if ($before === $after) {
                    continue;
                }
                $this->records->updateLineQuantities((int) $slot['id'], [
                    'opening_qty' => $opening,
                    'new_purchase_qty' => $purchase,
                    'product_sales_qty' => $sales,
                    'bonus_amount' => $bonus,
                ]);
                EntityHistory::recordDiff('bonus_calc_line', (int) $slot['id'], $this->lineLabel($label, (string) ($slot['product_name'] ?: $product['name'])), $before, $after);
                $changed++;
            }
            if ($changed > 0) {
                AuditLogger::record([
                    'module' => 'sales',
                    'action' => 'update',
                    'targetType' => 'bonus_calc_month',
                    'targetId' => $employeeId,
                    'targetLabel' => $label,
                    'details' => ['financialYear' => $year, 'month' => $month, 'linesChanged' => $changed],
                ]);
            }
        });

        return $this->presentMonth($year, $month, $employee);
    }

    public function stockistIsUsed(int $stockistId): bool
    {
        return $this->records->stockistLineCount($stockistId) > 0;
    }

    /** @return array<string, mixed> */
    private function presentList(string $year): array
    {
        $rows = $this->records->rowsForYear($year);
        $ids = array_map(static fn (array $row): int => (int) $row['sales_employee_id'], $rows);
        $totals = $this->records->monthTotals($year, $ids);
        $presented = [];
        foreach ($rows as $row) {
            $employeeId = (int) $row['sales_employee_id'];
            $months = [];
            $amounts = [];
            $any = false;
            foreach (BonusCalcMath::MONTHS as $number => $name) {
                unset($name);
                $cell = $totals[$employeeId][$number] ?? null;
                if ($cell === null || $cell['count'] === 0) {
                    $months[(string) $number] = null;
                    continue;
                }
                $any = true;
                $amount = BonusCalcMath::moneyString($cell['total']);
                $months[(string) $number] = $amount;
                $amounts[] = $amount;
            }
            $presented[] = [
                'id' => Presenter::id($row['id']),
                'financialYear' => $year,
                'employeeId' => Presenter::id($employeeId),
                'employeeName' => $this->displayName($row['employee_name'] ?? null),
                'employeeCode' => $this->blankToNull($row['employee_code'] ?? null),
                'months' => $months,
                'total' => $any ? BonusCalcMath::sum($amounts) : null,
            ];
        }

        return [
            'financialYear' => $year,
            'rows' => $presented,
            'employees' => array_map(fn (array $row): array => [
                'id' => Presenter::id($row['id']),
                'name' => $this->displayName($row['employee_name'] ?? null),
                'employeeCode' => $this->blankToNull($row['employee_code'] ?? null),
            ], $this->records->activeEmployees()),
        ];
    }

    /** @param array<string, mixed> $employee @return array<string, mixed> */
    private function presentMonth(string $year, int $month, array $employee): array
    {
        $employeeId = (int) $employee['id'];
        $lines = $this->records->linesForMonth($year, $employeeId, $month);
        $byStockist = [];
        foreach ($lines as $line) {
            $byStockist[(int) $line['stockist_id']][(int) $line['product_id']] = $line;
        }
        $assigned = $this->records->stockistsForEmployee($employeeId);
        $assignedIds = [];
        $stockists = [];
        foreach ($assigned as $stockist) {
            $assignedIds[(int) $stockist['id']] = true;
            $stockists[] = $stockist + ['assigned' => true];
        }
        $extraIds = array_values(array_diff(array_keys($byStockist), array_keys($assignedIds)));
        if ($extraIds !== []) {
            foreach ($this->records->stockistsByIds($extraIds) as $stockist) {
                $stockists[] = $stockist + ['assigned' => false];
            }
        }
        usort($stockists, static function (array $a, array $b): int {
            $assigned = ((int) $b['assigned']) <=> ((int) $a['assigned']);
            if ($assigned !== 0) {
                return $assigned;
            }

            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')) ?: ((int) $a['id'] <=> (int) $b['id']);
        });

        $activeProducts = $this->records->activeProducts();
        $activeIds = [];
        foreach ($activeProducts as $product) {
            $activeIds[(int) $product['id']] = $product;
        }
        $sections = [];
        foreach ($stockists as $stockist) {
            $saved = $byStockist[(int) $stockist['id']] ?? [];
            $products = $activeProducts;
            foreach ($saved as $productId => $line) {
                if (!isset($activeIds[$productId])) {
                    $products[] = [
                        'id' => $productId,
                        'name' => $line['product_name'],
                        'packaging_unit' => $line['packaging_unit'],
                        'mrp' => $line['mrp'],
                        'ptr' => $line['ptr'],
                        'pts' => $line['pts'],
                        'scheme' => $line['scheme'],
                        'net_rate' => $line['net_rate'],
                        'deleted_at' => 'kept',
                    ];
                }
            }
            $presentedLines = [];
            foreach ($products as $product) {
                $presentedLines[] = $this->presentLine($stockist, $product, $saved[(int) $product['id']] ?? null);
            }
            $sections[] = [
                'id' => Presenter::id($stockist['id']),
                'name' => $this->displayName($stockist['name'] ?? null, 'Unnamed stockist'),
                'code' => $this->blankToNull($stockist['code'] ?? null),
                'headquartersName' => $this->blankToNull($stockist['headquarters_name'] ?? null),
                'headquartersCode' => $this->blankToNull($stockist['headquarters_code'] ?? null),
                'assigned' => (bool) $stockist['assigned'],
                'lines' => $presentedLines,
            ];
        }

        return [
            'financialYear' => $year,
            'month' => $month,
            'monthLabel' => BonusCalcMath::MONTHS[$month],
            'monthShort' => BonusCalcMath::MONTH_SHORT[$month],
            'employee' => [
                'id' => Presenter::id($employeeId),
                'name' => $this->employeeLabel($employee),
                'employeeCode' => $this->blankToNull($employee['employee_code'] ?? null),
            ],
            'stockists' => $sections,
            'productsEmpty' => $activeProducts === [],
        ];
    }

    /**
     * @param array<string, mixed> $stockist
     * @param array<string, mixed> $product
     * @param array<string, mixed>|null $saved
     * @return array<string, mixed>
     */
    private function presentLine(array $stockist, array $product, ?array $saved): array
    {
        $source = $saved ?? $product;
        $savedRow = $saved !== null;
        $opening = $savedRow ? BonusCalcMath::moneyString($saved['opening_qty']) : null;
        $purchase = $savedRow ? BonusCalcMath::moneyString($saved['new_purchase_qty']) : null;
        $sales = $savedRow ? BonusCalcMath::moneyString($saved['product_sales_qty']) : null;
        $netRate = $this->nullableMoney($source['net_rate'] ?? null);
        $bonus = null;
        $totalProduct = null;
        if ($savedRow && $opening !== null && $purchase !== null && $sales !== null) {
            $totalProduct = BonusCalcMath::totalProduct($opening, $purchase);
            $bonus = BonusCalcMath::bonusAmount($sales, $netRate)[0];
        }
        $name = $savedRow && ($product['deleted_at'] ?? null) !== null
            ? (string) ($saved['product_name'] ?: $product['name'])
            : (string) $product['name'];

        return [
            'stockistId' => Presenter::id($stockist['id']),
            'productId' => Presenter::id($product['id']),
            'productName' => $name,
            'packagingUnit' => $source['packaging_unit'] ?? null,
            'mrp' => $this->nullableMoney($source['mrp'] ?? null),
            'ptr' => $this->nullableMoney($source['ptr'] ?? null),
            'pts' => $this->nullableMoney($source['pts'] ?? null),
            'scheme' => $source['scheme'] ?? null,
            'netRate' => $netRate,
            'openingQty' => $opening,
            'newPurchaseQty' => $purchase,
            'productSalesQty' => $sales,
            'totalProduct' => $totalProduct,
            'bonusAmount' => $bonus,
            'saved' => $savedRow,
        ];
    }

    /** @param array<string, mixed> $input @return list<array{stockistId: int, productId: int, openingQty: mixed, newPurchaseQty: mixed, productSalesQty: mixed}> */
    private function parseLines(array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['lines'], $errors);
        $lines = $input['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines)) {
            $errors['lines'] = 'Enter the month figures.';
        } elseif (count($lines) > 2000) {
            $errors['lines'] = 'Too many rows to save at once.';
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $parsed = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw HttpException::validation(['lines' => 'Enter the month figures.']);
            }
            $lineErrors = [];
            Input::onlyFields($line, ['stockistId', 'productId', 'openingQty', 'newPurchaseQty', 'productSalesQty'], $lineErrors);
            $stockistId = Input::id($line['stockistId'] ?? null);
            $productId = Input::id($line['productId'] ?? null);
            if ($stockistId === null) {
                $lineErrors['stockistId'] = 'Choose a stockist.';
            }
            if ($productId === null) {
                $lineErrors['productId'] = 'Choose a product.';
            }
            if ($lineErrors !== []) {
                throw HttpException::validation($lineErrors);
            }
            $parsed[] = [
                'stockistId' => (int) $stockistId,
                'productId' => (int) $productId,
                'openingQty' => $line['openingQty'] ?? null,
                'newPurchaseQty' => $line['newPurchaseQty'] ?? null,
                'productSalesQty' => $line['productSalesQty'] ?? null,
            ];
        }

        return $parsed;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function indexLines(array $lines): array
    {
        $indexed = [];
        foreach ($lines as $line) {
            $indexed[(int) $line['stockist_id']][(int) $line['product_id']] = $line;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $existing
     * @return array<int, true>
     */
    private function allowedStockists(int $employeeId, array $existing): array
    {
        $allowed = [];
        foreach ($this->records->stockistsForEmployee($employeeId) as $stockist) {
            $allowed[(int) $stockist['id']] = true;
        }
        foreach ($existing as $stockistId => $lines) {
            unset($lines);
            $allowed[$stockistId] = true;
        }

        return $allowed;
    }

    /**
     * @param list<array{stockistId: int, productId: int, openingQty: mixed, newPurchaseQty: mixed, productSalesQty: mixed}> $parsed
     * @param array<int, array<int, array<string, mixed>>> $existing
     * @return list<int>
     */
    private function productIdsForSave(array $parsed, array $existing): array
    {
        $ids = [];
        foreach ($parsed as $line) {
            $ids[$line['productId']] = $line['productId'];
        }
        foreach ($existing as $lines) {
            foreach ($lines as $productId => $line) {
                unset($line);
                $ids[$productId] = $productId;
            }
        }

        return array_values($ids);
    }

    /** @param array<string, mixed> $row */
    private function forgetRow(array $row): void
    {
        $id = (int) $row['id'];
        $label = $this->rowLabel($row);
        $this->records->softDelete($id);
        EntityHistory::recordDiff('bonus_calc_row', $id, $label, ['Status' => 'Active'], ['Status' => 'Removed']);
        AuditLogger::record([
            'module' => 'sales',
            'action' => 'delete',
            'targetType' => 'bonus_calc_row',
            'targetId' => $id,
            'targetLabel' => $label . ' · ' . $row['financial_year'],
        ]);
    }

    /** @param array<string, mixed> $slot */
    private function forgetLine(array $slot, string $sheetLabel): void
    {
        $before = [
            'Opening qty' => BonusCalcMath::moneyString($slot['opening_qty']),
            'New purchase' => BonusCalcMath::moneyString($slot['new_purchase_qty']),
            'Product sales' => BonusCalcMath::moneyString($slot['product_sales_qty']),
            'Net rate' => $this->nullableMoney($slot['net_rate']),
            'Bonus amount' => BonusCalcMath::moneyString($slot['bonus_amount']),
            'Status' => 'Saved',
        ];
        $this->records->deleteLine((int) $slot['id']);
        EntityHistory::recordDiff(
            'bonus_calc_line',
            (int) $slot['id'],
            $this->lineLabel($sheetLabel, (string) $slot['product_name']),
            $before,
            ['Status' => 'Cleared']
        );
    }

    /** @return array{0: string, 1: int, 2: array<string, mixed>} */
    private function sheetKey(string $yearInput, int $employeeId, string $monthInput): array
    {
        $year = BonusCalcMath::financialYear($yearInput);
        $month = BonusCalcMath::month($monthInput);
        if ($year === null || $month === null) {
            throw HttpException::notFound('That bonus month was not found.');
        }
        $row = $this->records->activeRowForEmployee($year, $employeeId);
        if ($row === null) {
            throw HttpException::notFound('That employee is not on the Bonus Calc list for this financial year.');
        }
        $employee = $this->records->findEmployee($employeeId);
        if ($employee === null) {
            throw HttpException::notFound('Employee not found.');
        }

        return [$year, $month, $employee];
    }

    /** @return array<string, mixed> */
    private function requireActiveRow(int $id): array
    {
        $row = $this->records->findRow($id);
        if ($row === null || $row['deleted_at'] !== null) {
            throw HttpException::notFound('That Bonus Calc row was not found.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function requireActiveEmployee(int $id, string $field = 'employeeIds'): array
    {
        $employee = $this->records->findEmployee($id);
        if ($employee === null || $employee['deleted_at'] !== null) {
            throw HttpException::validation([$field => 'Choose an employee from the Employee List.']);
        }

        return $employee;
    }

    private function year(string $yearInput): string
    {
        $year = BonusCalcMath::financialYear($yearInput === '' ? BonusCalcMath::DEFAULT_YEAR : $yearInput);
        if ($year === null) {
            throw HttpException::validation(['financialYear' => 'Choose a financial year such as 2026-27.']);
        }

        return $year;
    }

    /** @param array<string, mixed> $row */
    private function employeeLabel(array $row): string
    {
        return $this->displayName($row['employee_name'] ?? null);
    }

    /** @param array<string, mixed> $row */
    private function rowLabel(array $row): string
    {
        $name = $this->displayName($row['employee_name'] ?? null);
        $code = $this->blankToNull($row['employee_code'] ?? null);

        return $code === null ? $name : $name . ' (' . $code . ')';
    }

    private function displayName(mixed $name, string $fallback = 'Unnamed employee'): string
    {
        $text = trim((string) $name);

        return $text === '' ? $fallback : $text;
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function nullableMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return BonusCalcMath::moneyString($value);
    }

    private function lineLabel(string $sheet, ?string $product): string
    {
        return $sheet . ' · ' . ($product !== null && $product !== '' ? $product : 'Product');
    }
}
