<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CatalogueRepository;
use App\Utils\HttpException;

final class CatalogueService
{
    public function __construct(private readonly CatalogueRepository $records = new CatalogueRepository())
    {
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): array
    {
        $errors = [];
        Input::onlyFields($input, ['name', 'kind', 'parentId', 'description', 'status'], $errors);
        $name = Input::text($input, 'name', 'name', 100, $errors);
        $kind = ErpPresenter::termKindIn(is_string($input['kind'] ?? null) ? $input['kind'] : '');
        if ($kind === null) {
            $errors['kind'] = 'Choose a term type.';
        }
        $description = Input::optionalText($input, 'description', 'description', 500, $errors);
        $status = $this->status($input['status'] ?? 'active', $errors);
        $parentId = $this->parentId($input['parentId'] ?? null, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($kind === 'category') {
            $this->assertGroupOptional($parentId);
        } elseif ($parentId !== null) {
            throw HttpException::validation(['parentId' => 'Only a product category belongs to a group category.']);
        }

        $fields = [
            'kind' => $kind,
            'name' => $name,
            'description' => $description,
            'status' => $status,
        ];
        if ($kind === 'category') {
            $fields['parent_id'] = $parentId;
        }
        $id = $this->records->insert($fields);
        $created = $this->records->find($id);
        EntityHistory::recordDiff('catalogue_term', $id, $created['name'], [], [
            'Name' => $created['name'],
            'Kind' => $created['kind'],
            'Status' => $created['status'],
            'Description' => $created['description'] ?? null,
            'Group' => $created['parent_id'] ?? null,
        ]);
        AuditLogger::record([
            'module' => 'catalogue',
            'action' => 'create',
            'targetType' => 'catalogue_term',
            'targetId' => $id,
            'targetLabel' => $created['name'],
            'details' => ['kind' => $created['kind']],
        ]);

        return ErpPresenter::term($created);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $term = $this->records->find($id) ?? throw HttpException::notFound('Catalogue term not found.');
        if ($term['deleted_at'] !== null) {
            throw HttpException::validation(['name' => 'Restore this record before editing it.']);
        }
        $errors = [];
        Input::onlyFields($input, ['name', 'parentId', 'description', 'status'], $errors);
        $name = Input::text($input, 'name', 'name', 100, $errors);
        $description = array_key_exists('description', $input)
            ? Input::optionalText($input, 'description', 'description', 500, $errors)
            : ($term['description'] ?? null);
        $status = array_key_exists('status', $input)
            ? $this->status($input['status'], $errors)
            : (string) ($term['status'] ?? 'active');
        $parentProvided = array_key_exists('parentId', $input);
        $parentId = $parentProvided ? $this->parentId($input['parentId'], $errors) : null;
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($parentProvided && $term['kind'] !== 'category') {
            throw HttpException::validation(['parentId' => 'Only a product category belongs to a group category.']);
        }
        if ($term['kind'] === 'category' && $parentProvided) {
            $this->assertGroupOptional($parentId);
        }

        $fields = [
            'name' => $name,
            'description' => $description,
            'status' => $status,
        ];
        if ($term['kind'] === 'category' && $parentProvided) {
            $fields['parent_id'] = $parentId;
        }
        $before = [
            'Name' => $term['name'],
            'Status' => $term['status'],
            'Description' => $term['description'] ?? null,
            'Group' => $term['parent_id'] ?? null,
        ];
        $this->records->update($id, $fields);
        $updated = $this->records->find($id);
        EntityHistory::recordDiff('catalogue_term', $id, $updated['name'], $before, [
            'Name' => $updated['name'],
            'Status' => $updated['status'],
            'Description' => $updated['description'] ?? null,
            'Group' => $updated['parent_id'] ?? null,
        ]);
        AuditLogger::record([
            'module' => 'catalogue',
            'action' => 'update',
            'targetType' => 'catalogue_term',
            'targetId' => $id,
            'targetLabel' => $updated['name'],
        ]);

        return ErpPresenter::term($updated);
    }

    public function delete(int $id): array
    {
        $term = $this->records->find($id) ?? throw HttpException::notFound('Catalogue term not found.');
        if ($term['deleted_at'] !== null) {
            return ErpPresenter::term($term);
        }

        // Unlink children instead of blocking: groups clear category.parent_id;
        // categories clear product.category_id. Products themselves are kept.
        if ($term['kind'] === 'group_category') {
            $this->records->clearChildParents($id);
        }
        if ($term['kind'] === 'category') {
            $this->records->clearProductCategories($id);
        }
        if (in_array($term['kind'], ['tag', 'badge'], true)) {
            $this->records->clearProductTermLinks($id);
        }
        $this->records->setDeleted($id, true);
        EntityHistory::recordDiff('catalogue_term', $id, $term['name'], ['Status' => 'Active'], ['Status' => 'Deleted']);
        AuditLogger::record([
            'module' => 'catalogue',
            'action' => 'delete',
            'targetType' => 'catalogue_term',
            'targetId' => $id,
            'targetLabel' => $term['name'],
            'details' => ['kind' => $term['kind']],
        ]);

        return ErpPresenter::term($this->records->find($id));
    }

    public function restore(int $id): array
    {
        $term = $this->records->find($id) ?? throw HttpException::notFound('Catalogue term not found.');
        if ($term['deleted_at'] === null) {
            return ErpPresenter::term($term);
        }
        $this->records->setDeleted($id, false);
        EntityHistory::recordDiff('catalogue_term', $id, $term['name'], ['Status' => 'Deleted'], ['Status' => 'Active']);
        AuditLogger::record([
            'module' => 'catalogue',
            'action' => 'restore',
            'targetType' => 'catalogue_term',
            'targetId' => $id,
            'targetLabel' => $term['name'],
            'details' => ['kind' => $term['kind']],
        ]);

        return ErpPresenter::term($this->records->find($id));
    }

    /** @param array<string, string> $errors */
    private function parentId(mixed $value, array &$errors): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = Input::id($value);
        if ($id === null) {
            $errors['parentId'] = 'Choose a group category.';
        }

        return $id;
    }

    /** Group is optional; when set it must be an active group category. */
    private function assertGroupOptional(?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = $this->records->find($parentId);
        if ($parent === null || $parent['kind'] !== 'group_category' || $parent['deleted_at'] !== null) {
            throw HttpException::validation(['parentId' => 'Choose an active group category.']);
        }
    }

    /** @param array<string, string> $errors */
    private function status(mixed $value, array &$errors): string
    {
        if ($value === null || $value === '') {
            return 'active';
        }
        if (!is_string($value) || !in_array($value, ['active', 'inactive'], true)) {
            $errors['status'] = 'Choose active or inactive.';

            return 'active';
        }

        return $value;
    }
}
