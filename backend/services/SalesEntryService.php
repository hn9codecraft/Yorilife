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
        $created = $this->records->find($id);
        EntityHistory::recordDiff('sales_entry', $id, 'Sales entry #' . $id, [], [
            'Month' => $created['sales_month'],
            'Headquarters' => $created['headquarters_id'],
            'Stockist' => $created['stockist_id'],
            'Achievement' => $created['achievement_value'],
            'Bonus' => $created['bonus'],
            'Deduction' => $created['deduction'],
            'Final value' => $created['final_value'],
        ], (int) $actor['id']);
        AuditLogger::record([
            'module' => 'sales',
            'action' => 'create',
            'targetType' => 'sales_entry',
            'targetId' => $id,
            'targetLabel' => 'Sales entry #' . $id,
        ]);

        return ErpPresenter::sales($created);
    }

    /** @param array<string, mixed> $input */
    public function update(int $id, array $input): array
    {
        $current = $this->records->find($id) ?? throw HttpException::notFound('Sales entry not found.');
        $before = [
            'Month' => $current['sales_month'],
            'Headquarters' => $current['headquarters_id'],
            'Stockist' => $current['stockist_id'],
            'Achievement' => $current['achievement_value'],
            'Bonus' => $current['bonus'],
            'Deduction' => $current['deduction'],
            'Final value' => $current['final_value'],
        ];
        $this->records->update($id, $this->fields($input, $current));
        $updated = $this->records->find($id);
        EntityHistory::recordDiff('sales_entry', $id, 'Sales entry #' . $id, $before, [
            'Month' => $updated['sales_month'],
            'Headquarters' => $updated['headquarters_id'],
            'Stockist' => $updated['stockist_id'],
            'Achievement' => $updated['achievement_value'],
            'Bonus' => $updated['bonus'],
            'Deduction' => $updated['deduction'],
            'Final value' => $updated['final_value'],
        ]);
        AuditLogger::record([
            'module' => 'sales',
            'action' => 'update',
            'targetType' => 'sales_entry',
            'targetId' => $id,
            'targetLabel' => 'Sales entry #' . $id,
        ]);

        return ErpPresenter::sales($updated);
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
