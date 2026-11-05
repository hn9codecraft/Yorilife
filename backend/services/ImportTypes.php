<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The Sales Office data types the Excel import understands, the record each
 * one writes and how its fields map to the saved columns. Rows are applied in
 * ORDER so every parent exists first: Location, Location HQ, 3rd, 2nd and 1st
 * line managers, Headquarters, Stockist. An existing code is updated, a new
 * code is created.
 */
final class ImportTypes
{
    public const ORDER = ['location', 'location-headquarters', 'manager-3', 'manager-2', 'manager-1', 'headquarters', 'stockist'];

    /**
     * @return array<string, array{label: string, table: string, key: string, columns: list<array{header: string, required: bool}>, fields: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            'location' => [
                'label' => 'Location (states)',
                'table' => 'locations',
                'key' => 'Location Code',
                'columns' => [
                    ['header' => 'Location Code', 'required' => true],
                    ['header' => 'State Name', 'required' => true],
                ],
                'fields' => ['code' => 'Location Code', 'name' => 'State Name'],
            ],
            'location-headquarters' => [
                'label' => 'Location headquarters',
                'table' => 'location_headquarters',
                'key' => 'Headquarters Code',
                'columns' => [
                    ['header' => 'Headquarters Code', 'required' => true],
                    ['header' => 'Location Code', 'required' => true],
                    ['header' => 'Headquarters Name', 'required' => true],
                    ['header' => 'Address', 'required' => true],
                    ['header' => 'Pincode', 'required' => true],
                    ['header' => 'Phone Number', 'required' => true],
                    ['header' => 'Email', 'required' => true],
                ],
                'fields' => [
                    'code' => 'Headquarters Code', 'locationId' => 'Location Code', 'name' => 'Headquarters Name',
                    'address' => 'Address', 'pincode' => 'Pincode', 'phone' => 'Phone Number', 'email' => 'Email',
                ],
            ],
            'manager-3' => [
                'label' => '3rd line managers',
                'table' => 'managers',
                'key' => 'Employee Code',
                'columns' => [
                    ['header' => 'Employee Code', 'required' => true],
                    ['header' => 'Name', 'required' => true],
                    ['header' => 'Email', 'required' => false],
                    ['header' => 'Phone Number', 'required' => false],
                    ['header' => 'State List', 'required' => true],
                ],
                'fields' => ['code' => 'Employee Code', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone Number', 'stateIds' => 'State List', 'level' => 'Employee Code'],
            ],
            'manager-2' => [
                'label' => '2nd line managers',
                'table' => 'managers',
                'key' => 'Employee Code',
                'columns' => [
                    ['header' => 'Employee Code', 'required' => true],
                    ['header' => 'Name', 'required' => true],
                    ['header' => 'Reports To (3rd Line Code)', 'required' => true],
                    ['header' => 'Email', 'required' => false],
                    ['header' => 'Phone Number', 'required' => false],
                    ['header' => 'State List', 'required' => true],
                ],
                'fields' => [
                    'code' => 'Employee Code', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone Number', 'stateIds' => 'State List',
                    'parentId' => 'Reports To (3rd Line Code)', 'parentType' => 'Reports To (3rd Line Code)', 'level' => 'Employee Code',
                ],
            ],
            'manager-1' => [
                'label' => '1st line managers',
                'table' => 'managers',
                'key' => 'Employee Code',
                'columns' => [
                    ['header' => 'Employee Code', 'required' => true],
                    ['header' => 'Name', 'required' => true],
                    ['header' => 'Reports To (2nd Line Code)', 'required' => true],
                    ['header' => 'Email', 'required' => false],
                    ['header' => 'Phone Number', 'required' => false],
                    ['header' => 'State List', 'required' => false],
                ],
                'fields' => [
                    'code' => 'Employee Code', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone Number', 'stateIds' => 'State List',
                    'parentId' => 'Reports To (2nd Line Code)', 'parentType' => 'Reports To (2nd Line Code)', 'level' => 'Employee Code',
                ],
            ],
            'headquarters' => [
                'label' => 'Headquarters',
                'table' => 'headquarters',
                'key' => 'Headquarters Code',
                'columns' => [
                    ['header' => 'Headquarters Code', 'required' => true],
                    ['header' => '1st Line Manager Code', 'required' => true],
                ],
                'fields' => ['locationHeadquartersId' => 'Headquarters Code', 'code' => 'Headquarters Code', 'parentManagerId' => '1st Line Manager Code'],
            ],
            'stockist' => [
                'label' => 'Stockists',
                'table' => 'stockists',
                'key' => 'Stockist Code',
                'columns' => [
                    ['header' => 'Stockist Code', 'required' => true],
                    ['header' => 'Stockist Name', 'required' => true],
                    ['header' => 'Headquarters Code', 'required' => true],
                    ['header' => 'Address', 'required' => false],
                    ['header' => 'Pincode', 'required' => false],
                    ['header' => 'Phone Number', 'required' => false],
                    ['header' => 'Email', 'required' => false],
                ],
                'fields' => [
                    'code' => 'Stockist Code', 'name' => 'Stockist Name', 'headquartersId' => 'Headquarters Code',
                    'address' => 'Address', 'pincode' => 'Pincode', 'phone' => 'Phone Number', 'email' => 'Email',
                ],
            ],
        ];
    }

    public const UNIFIED = 'unified';
    public const UNIFIED_LABEL = 'All data (Excel)';
    public const UNIFIED_COLUMNS = ['Data Type', 'Code', 'Name', 'Parent Code', 'State List', 'Address', 'Pincode', 'Phone Number', 'Email', 'Notes'];

    /** Data Type labels used in the Excel sheet, by type. */
    public const DATA_TYPE_LABELS = [
        'location' => 'Location',
        'location-headquarters' => 'Location HQ',
        'manager-3' => '3rd Line Manager',
        'manager-2' => '2nd Line Manager',
        'manager-1' => '1st Line Manager',
        'headquarters' => 'Headquarters',
        'stockist' => 'Stockist',
    ];

    /** Unified Excel column => the per-type CSV header it fills. Columns not listed are ignored for that type. */
    public const UNIFIED_MAP = [
        'location' => ['Code' => 'Location Code', 'Name' => 'State Name'],
        'location-headquarters' => [
            'Code' => 'Headquarters Code', 'Name' => 'Headquarters Name', 'Parent Code' => 'Location Code',
            'Address' => 'Address', 'Pincode' => 'Pincode', 'Phone Number' => 'Phone Number', 'Email' => 'Email',
        ],
        'manager-3' => ['Code' => 'Employee Code', 'Name' => 'Name', 'State List' => 'State List', 'Phone Number' => 'Phone Number', 'Email' => 'Email'],
        'manager-2' => [
            'Code' => 'Employee Code', 'Name' => 'Name', 'Parent Code' => 'Reports To (3rd Line Code)',
            'State List' => 'State List', 'Phone Number' => 'Phone Number', 'Email' => 'Email',
        ],
        'manager-1' => [
            'Code' => 'Employee Code', 'Name' => 'Name', 'Parent Code' => 'Reports To (2nd Line Code)',
            'State List' => 'State List', 'Phone Number' => 'Phone Number', 'Email' => 'Email',
        ],
        'headquarters' => ['Code' => 'Headquarters Code', 'Parent Code' => '1st Line Manager Code'],
        'stockist' => [
            'Code' => 'Stockist Code', 'Name' => 'Stockist Name', 'Parent Code' => 'Headquarters Code',
            'Address' => 'Address', 'Pincode' => 'Pincode', 'Phone Number' => 'Phone Number', 'Email' => 'Email',
        ],
    ];

    /** Parent Code points at a record of this type (in the same file or already saved). */
    public const UNIFIED_PARENT = [
        'location-headquarters' => 'location',
        'manager-2' => 'manager-3',
        'manager-1' => 'manager-2',
        'headquarters' => 'manager-1',
        'stockist' => 'headquarters',
    ];

    /** Resolve a Data Type cell (label or short alias, any case) to a type key. */
    public static function dataType(string $value): ?string
    {
        $key = strtolower(preg_replace('/[\s_()-]+/', '', $value) ?? '');
        $aliases = [
            'location' => 'location', 'locationstate' => 'location', 'state' => 'location', 'locations' => 'location',
            'locationhq' => 'location-headquarters', 'lhq' => 'location-headquarters', 'locationheadquarters' => 'location-headquarters',
            '3rdlinemanager' => 'manager-3', 'thirdlinemanager' => 'manager-3', 'm3' => 'manager-3', 'manager3' => 'manager-3', '3rdline' => 'manager-3',
            '2ndlinemanager' => 'manager-2', 'secondlinemanager' => 'manager-2', 'm2' => 'manager-2', 'manager2' => 'manager-2', '2ndline' => 'manager-2',
            '1stlinemanager' => 'manager-1', 'firstlinemanager' => 'manager-1', 'm1' => 'manager-1', 'manager1' => 'manager-1', '1stline' => 'manager-1',
            'headquarters' => 'headquarters', 'hq' => 'headquarters',
            'stockist' => 'stockist', 'stockists' => 'stockist',
        ];

        return $aliases[$key] ?? null;
    }

    /** Unified sheet description: which column is required, optional or left blank for each Data Type. */
    public static function unified(): array
    {
        $types = [];
        foreach (self::ORDER as $type) {
            $required = [];
            foreach (self::all()[$type]['columns'] as $column) {
                $required[$column['header']] = $column['required'];
            }
            $columns = [];
            foreach (self::UNIFIED_COLUMNS as $unified) {
                if ($unified === 'Data Type') {
                    $columns[$unified] = 'required';
                    continue;
                }
                if ($unified === 'Notes') {
                    $columns[$unified] = 'optional';
                    continue;
                }
                $header = self::UNIFIED_MAP[$type][$unified] ?? null;
                $columns[$unified] = $header === null ? 'blank' : (($required[$header] ?? false) ? 'required' : 'optional');
            }
            $types[] = ['type' => $type, 'label' => self::DATA_TYPE_LABELS[$type], 'step' => count($types) + 1, 'columns' => $columns];
        }

        return ['type' => self::UNIFIED, 'label' => self::UNIFIED_LABEL, 'columns' => self::UNIFIED_COLUMNS, 'dataTypes' => $types];
    }

    /** @return list<list<string>> example rows for the template, one per Data Type */
    public static function unifiedExample(): array
    {
        return [
            ['Location', 'GUJ', 'Gujarat', '', '', '', '', '', '', ''],
            ['Location HQ', 'HQ-101', 'Ahmedabad', 'GUJ', '', '12 Ring Road, Ahmedabad', '380001', '9876543210', 'ahmedabad@example.com', ''],
            ['3rd Line Manager', 'ER-M3-1', 'Shyam Patel', '', 'GUJ', '', '', '9876543210', 'shyam@example.com', ''],
            ['2nd Line Manager', 'ER-M2-1', 'Ravi Shah', 'ER-M3-1', 'GUJ', '', '', '9876500001', 'ravi@example.com', ''],
            ['1st Line Manager', 'ER-M1-1', 'Asha Mehta', 'ER-M2-1', 'GUJ', '', '', '9876500002', 'asha@example.com', ''],
            ['Headquarters', 'HQ-101', '', 'ER-M1-1', '', '', '', '', '', 'Code = Location HQ code'],
            ['Stockist', 'ST-1001', 'Shree Medico', 'HQ-101', '', 'Station Road', '380002', '9876500003', 'medico@example.com', ''],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

}
