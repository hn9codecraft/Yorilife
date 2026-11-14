<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductCategoryRepository;
use App\Utils\HttpException;

/**
 * Product Type and Product Group (the Products > Category page).
 *
 * - One field: name (required, 100 characters, unique case-insensitive among
 *   records that are not deleted).
 * - Delete is a soft delete (the row stays for history). It is refused while
 *   any product that is not deleted still uses the record.
 */
final class ProductCategoryService
{
    private const LABELS = [
        'type' => ['singular' => 'product type', 'title' => 'Product type', 'entity' => 'product_type'],
        'group' => ['singular' => 'product group', 'title' => 'Product group', 'entity' => 'product_group'],
    ];

    private readonly ProductCategoryRepository $records;

    public function __construct(private readonly string $kind, ?ProductCategoryRepository $records = null)
    {
        $this->records = $records ?? new ProductCategoryRepository($kind);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $counts = $this->records->productCounts();

        return array_map(
            fn (array $row): array => ErpPresenter::productCategory($row, $counts[(int) $row['id']] ?? 0),
            $this->records->active()
        );
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $name = $this->name($input);
        $id = $this->records->transaction(function () use ($name): int {
            $id = $this->records->insert($name);
            EntityHistory::recordDiff($this->label('entity'), $id, $name, [], ['Name' => $name]);
            AuditLogger::record([
                'module' => 'products',
                'action' => 'create',
                'targetType' => $this->label('entity'),
                'targetId' => $id,
                'targetLabel' => $name,
            ]);

            return $id;
        });

        return $this->present($id);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $current = $this->findActive($id);
        $name = $this->name($input);
        if ($name !== $current['name']) {
            $this->records->transaction(function () use ($id, $name, $current): void {
                $this->records->rename($id, $name);
                EntityHistory::recordDiff($this->label('entity'), $id, $name, ['Name' => $current['name']], ['Name' => $name]);
                AuditLogger::record([
                    'module' => 'products',
                    'action' => 'update',
                    'targetType' => $this->label('entity'),
                    'targetId' => $id,
                    'targetLabel' => $name,
                    'details' => ['fields' => ['Name']],
                ]);
            });
        }

        return $this->present($id);
    }

    public function delete(int $id): array
    {
        $current = $this->findActive($id);
        $used = $this->records->activeProductCount($id);
        if ($used > 0) {
            $noun = $used === 1 ? 'product uses' : 'products use';
            throw HttpException::validation(
                ['record' => "{$used} {$noun} this {$this->label('singular')}. Change those products first."],
                "This {$this->label('singular')} is in use."
            );
        }
        $this->records->transaction(function () use ($id, $current): void {
            $this->records->markDeleted($id);
            EntityHistory::recordDiff($this->label('entity'), $id, $current['name'], ['Status' => 'Active'], ['Status' => 'Deleted']);
            AuditLogger::record([
                'module' => 'products',
                'action' => 'delete',
                'targetType' => $this->label('entity'),
                'targetId' => $id,
                'targetLabel' => $current['name'],
            ]);
        });

        return ErpPresenter::productCategory($this->records->find($id) ?? $current, 0);
    }

    /** @return array<string, mixed> */
    private function findActive(int $id): array
    {
        $current = $this->records->find($id);
        if ($current === null || $current['deleted_at'] !== null) {
            throw HttpException::notFound(ucfirst($this->label('singular')) . ' not found.');
        }

        return $current;
    }

    /** @param array<string, mixed> $input */
    private function name(array $input): string
    {
        $errors = [];
        Input::onlyFields($input, ['name'], $errors);
        $name = Input::text($input, 'name', $this->label('singular'), 100, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return (string) $name;
    }

    private function present(int $id): array
    {
        return ErpPresenter::productCategory($this->records->find($id), $this->records->activeProductCount($id));
    }

    private function label(string $key): string
    {
        return self::LABELS[$this->kind][$key];
    }
}