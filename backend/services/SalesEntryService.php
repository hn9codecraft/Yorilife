<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SalesEntryRepository;
use App\Repositories\SalesOfficeRepository;
use App\Utils\HttpException;

/**
 * Sales entries are stored as entered. Final value is never derived from
 * achievement, bonus or deduction.
 */
final class SalesEntryService
{
    public function __construct(
        private readonly SalesEntryRepository $records = new SalesEntryRepository(),
        private readonly SalesOfficeRepository $office = new SalesOfficeRepository(),
    ) {
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $input */
    public function create(array $actor, array $input): array
    {
        $fields = $this->fields($input, null);
        $fields['entered_by'] = (int) $actor['id'];
        $id = $this->records->insert($fields);

        return ErpPresenter::sales($this->records->find($id));
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $current = $this->records->find($id) ?? throw HttpException::notFound('Sales entry not found.');
        $this->records->update($id, $this->fields($input, $current));

        return ErpPresenter::sales($this->records->find($id));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function fields(array $input, ?array $current): array
    {
        $errors = [];
        Input::onlyFields($input, ['month', 'headquartersId', 'stockistId', 'achievementValue', 'bonus', 'deduction', 'finalValue'], $errors);
        $month = Input::month($input['month'] ?? null);
        if ($month === null) {
            $errors['month'] = 'Choose the sales month.';
        }
        $hqId = Input::id($input['headquartersId'] ?? null);
        $stockistId = Input::id($input['stockistId'] ?? null);
        if ($hqId === null) {
            $errors['headquartersId'] = 'Select a headquarters.';
        }
        if ($stockistId === null) {
            $errors['stockistId'] = 'Select a stockist.';
        }
        $amounts = [];
        foreach ([
            'achievementValue' => 'Achievement value',
            'bonus' => 'Bonus',
            'deduction' => 'Deduction',
            'finalValue' => 'Final value',
        ] as $field => $label) {
            $amounts[$field] = Input::money($input, $field, $label, $errors);
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        $stockist = $this->office->findStockist($stockistId) ?? throw HttpException::validation(['stockistId' => 'That stockist does not exist.']);
        $unchangedLink = $current !== null
            && (int) $current['stockist_id'] === $stockistId
            && (int) $current['headquarters_id'] === $hqId;
        if (!$unchangedLink && (int) $stockist['headquarters_id'] !== $hqId) {
            throw HttpException::validation(['stockistId' => 'That stockist is not linked to the selected headquarters.']);
        }
        if ($this->office->findHeadquarters($hqId) === null) {
            throw HttpException::validation(['headquartersId' => 'That headquarters does not exist.']);
        }

        return [
            'sales_month' => $month,
            'headquarters_id' => $hqId,
            'stockist_id' => $stockistId,
            'achievement_value' => $amounts['achievementValue'],
            'bonus' => $amounts['bonus'],
            'deduction' => $amounts['deduction'],
            'final_value' => $amounts['finalValue'],
        ];
    }
}
