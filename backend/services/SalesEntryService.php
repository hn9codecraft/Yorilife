<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SalesEntryRepository;
use App\Repositories\SalesOfficeRepository;
use App\Utils\HttpException;

/**
 * Sales entries are stored as entered. Final value is never derived from
 * achievement, bonus or deduction.
 *
 * An entry is for one stockist and one month. Its Headquarter comes from the
 * stockist's Headquarter (State / Headquarter List) when the stockist is
 * chosen; the older headquarters link is copied only when the stockist still
 * has one. A client may still send headquartersId; it is ignored.
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
        EntityHistory::recordDiff('sales_entry', $id, 'Sales entry #' . $id, [], self::history($created), (int) $actor['id']);
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
        $this->records->update($id, $this->fields($input, $current));
        $updated = $this->records->find($id);
        EntityHistory::recordDiff('sales_entry', $id, 'Sales entry #' . $id, self::history($current), self::history($updated));
        AuditLogger::record([
            'module' => 'sales',
            'action' => 'update',
            'targetType' => 'sales_entry',
            'targetId' => $id,
            'targetLabel' => 'Sales entry #' . $id,
        ]);

        return ErpPresenter::sales($updated);
    }

    /** @param array<string, mixed>|null $row @return array<string, mixed> */
    private static function history(?array $row): array
    {
        return [
            'Month' => $row['sales_month'] ?? null,
            'Headquarter' => $row['location_headquarters_id'] ?? null,
            'Stockist' => $row['stockist_id'] ?? null,
            'Achievement' => $row['achievement_value'] ?? null,
            'Bonus' => $row['bonus'] ?? null,
            'Deduction' => $row['deduction'] ?? null,
            'Final value' => $row['final_value'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function fields(array $input, ?array $current): array
    {
        $errors = [];
        Input::onlyFields($input, ['month', 'stockistId', 'headquartersId', 'locationHeadquartersId', 'achievementValue', 'bonus', 'deduction', 'finalValue'], $errors);
        $month = Input::month($input['month'] ?? null);
        if ($month === null) {
            $errors['month'] = 'Choose the sales month.';
        }
        $stockistId = Input::id($input['stockistId'] ?? null);
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
        $sameStockist = $current !== null && (int) $current['stockist_id'] === $stockistId;
        // Keep the Headquarter stored on an existing entry while its stockist stays the same.
        $headquarter = $sameStockist ? ($current['location_headquarters_id'] ?? null) : ($stockist['location_headquarters_id'] ?? null);
        $oldHeadquarters = $sameStockist ? ($current['headquarters_id'] ?? null) : ($stockist['headquarters_id'] ?? null);

        return [
            'sales_month' => $month,
            'location_headquarters_id' => $headquarter,
            'headquarters_id' => $oldHeadquarters,
            'stockist_id' => $stockistId,
            'achievement_value' => $amounts['achievementValue'],
            'bonus' => $amounts['bonus'],
            'deduction' => $amounts['deduction'],
            'final_value' => $amounts['finalValue'],
        ];
    }
}