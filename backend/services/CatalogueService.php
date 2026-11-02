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
        Input::onlyFields($input, ['name', 'kind', 'parentId'], $errors);
        $name = Input::text($input, 'name', 'name', 100, $errors);
        $kind = ErpPresenter::termKindIn(is_string($input['kind'] ?? null) ? $input['kind'] : '');
        if ($kind === null) {
            $errors['kind'] = 'Choose a term type.';
        }
        $parentId = $this->parentId($input['parentId'] ?? null, $errors);
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($kind === 'category') {
            $this->assertGroup($parentId);
        } elseif ($parentId !== null) {
            throw HttpException::validation(['parentId' => 'Only a product category belongs to a group category.']);
        }

        $fields = ['kind' => $kind, 'name' => $name];
        if ($kind === 'category') {
            $fields['parent_id'] = $parentId;
        }
        $id = $this->records->insert($fields);

        return ErpPresenter::term($this->records->find($id));
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $term = $this->records->find($id) ?? throw HttpException::notFound('Catalogue term not found.');
        if ($term['deleted_at'] !== null) {
            throw HttpException::validation(['name' => 'Restore this record before editing it.']);
        }
        $errors = [];
        Input::onlyFields($input, ['name', 'parentId'], $errors);
        $name = Input::text($input, 'name', 'name', 100, $errors);
        $parentProvided = array_key_exists('parentId', $input);
        $parentId = $parentProvided ? $this->parentId($input['parentId'], $errors) : null;
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($parentProvided && $term['kind'] !== 'category') {
            throw HttpException::validation(['parentId' => 'Only a product category belongs to a group category.']);
        }
        if ($term['kind'] === 'category' && $parentProvided) {
            $this->assertGroup($parentId);
        }

        $fields = ['name' => $name];
        if ($term['kind'] === 'category' && $parentProvided) {
            $fields['parent_id'] = $parentId;
        }
        $this->records->update($id, $fields);

        return ErpPresenter::term($this->records->find($id));
    }

    public function delete(int $id): array
    {
        $term = $this->records->find($id) ?? throw HttpException::notFound('Catalogue term not found.');
        if ($term['deleted_at'] !== null) {
            return ErpPresenter::term($term);
        }
        if ($term['kind'] === 'group_category' && $this->records->activeChildCategories($id) > 0) {
            throw HttpException::validation([
                'name' => 'A product category still belongs to this group. Reassign or delete those categories before deleting the group.',
            ]);
        }
        if ($this->records->activeReferences($id) > 0) {
            throw HttpException::validation([
                'name' => 'This is still used by an active product. Remove it from those products before deleting it.',
            ]);
        }
        $this->records->setDeleted($id, true);

        return ErpPresenter::term($this->records->find($id));
    }

    public function restore(int $id): array
    {
        $term = $this->records->find($id) ?? throw HttpException::notFound('Catalogue term not found.');
        if ($term['deleted_at'] === null) {
            return ErpPresenter::term($term);
        }
        $this->records->setDeleted($id, false);

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

    private function assertGroup(?int $parentId): void
    {
        if ($parentId === null) {
            throw HttpException::validation(['parentId' => 'Choose the group category this product category belongs to.']);
        }
        $parent = $this->records->find($parentId);
        if ($parent === null || $parent['kind'] !== 'group_category' || $parent['deleted_at'] !== null) {
            throw HttpException::validation(['parentId' => 'Choose an active group category.']);
        }
    }
}
