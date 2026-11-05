<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Services\ImportTypes;
use App\Services\ImportXlsx;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Rolling back an Excel import when the data changed after it was imported. */
final class ExcelImportRollbackTest extends FeatureTestCase
{
    public function testRollbackRestoresDeletesAndSkipsConflicts(): void
    {
        $admin = $this->signedInAs('admin');
        $this->importAndConfirm($admin, [['Location', 'XA', 'Aland']]);

        $id = $this->importAndConfirm($admin, [
            ['Location', 'XA', 'Aland renamed'], // updated -> restored
            ['Location', 'XR', 'Rland'],         // created -> deleted
            ['Location', 'XE', 'Eland'],         // created, then edited -> skipped
            ['Location', 'XD', 'Dland'],         // created, then deleted -> skipped
            ['Location', 'XN', 'Nland'],         // created, then gets a child -> skipped
        ]);
        self::assertSame('Aland renamed', $this->locationName('XA'));

        $this->db()->exec("UPDATE locations SET name = 'Eland edited' WHERE code = 'XE'");
        $this->db()->exec("DELETE FROM locations WHERE code = 'XD'");
        $child = $this->importAndConfirm($admin, [['Location HQ', 'HQ-950', 'Ncity', 'XN', '', 'Main Road', '380001', '9876543210', 'n@example.com', '']]);

        // The preview reports the plan and changes nothing.
        $preview = $admin->request('POST', "/api/imports/{$id}/rollback/preview", []);
        self::assertSame(200, $preview['status'], $preview['raw']);
        $plan = $preview['body']['data']['rollback'];
        self::assertSame([1, 1, 3], [$plan['restored'], $plan['deleted'], $plan['skipped']], $preview['raw']);
        self::assertSame('completed', $plan['status'], 'the preview does not change the import');
        self::assertSame('Aland renamed', $this->locationName('XA'));
        self::assertSame(1, $this->locationCount('XR'));

        $rollback = $admin->request('POST', "/api/imports/{$id}/rollback", []);
        self::assertSame(200, $rollback['status'], $rollback['raw']);
        $result = $rollback['body']['data']['rollback'];
        self::assertSame('partially_rolled_back', $result['status']);
        self::assertSame([1, 1, 3], [$result['restored'], $result['deleted'], $result['skipped']]);
        $byCode = array_column($result['items'], null, 'code');
        self::assertSame('restored', $byCode['XA']['result']);
        self::assertSame('deleted', $byCode['XR']['result']);
        self::assertSame('location', $byCode['XR']['type']);
        self::assertSame('skipped', $byCode['XE']['result']);
        self::assertStringContainsString('changed after the import', $byCode['XE']['note']);
        self::assertSame('skipped', $byCode['XD']['result']);
        self::assertStringContainsString('no longer exists', $byCode['XD']['note']);
        self::assertSame('skipped', $byCode['XN']['result']);
        self::assertStringStartsWith('Not deleted:', $byCode['XN']['note']);

        self::assertSame('Aland', $this->locationName('XA'), 'updated record is restored');
        self::assertSame(0, $this->locationCount('XR'), 'created record is removed');
        self::assertSame('Eland edited', $this->locationName('XE'), 'edited record is left as it is');
        self::assertSame(0, $this->locationCount('XD'));
        self::assertSame(1, $this->locationCount('XN'), 'record with a new child is kept');
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM location_headquarters WHERE code = 'HQ-950'")->fetchColumn());
        self::assertSame('partially_rolled_back', $this->db()->query("SELECT status FROM imports WHERE id = {$id}")->fetchColumn());
        self::assertSame(3, (int) $this->db()->query("SELECT COUNT(*) FROM import_changes WHERE import_id = {$id} AND rollback_status = 'skipped'")->fetchColumn());

        // Only once.
        self::assertError($admin->request('POST', "/api/imports/{$id}/rollback", []), 409, 'rollback_not_allowed');
        self::assertSame('Aland', $this->locationName('XA'));

        // Rolling back the child import removes the child; the earlier import stays rolled back.
        $second = $admin->request('POST', "/api/imports/{$child}/rollback", []);
        self::assertSame(200, $second['status'], $second['raw']);
        self::assertSame('rolled_back', $second['body']['data']['rollback']['status']);
        self::assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM location_headquarters WHERE code = 'HQ-950'")->fetchColumn());
        self::assertError($admin->request('POST', "/api/imports/{$child}/rollback", []), 409, 'rollback_not_allowed');
    }

    public function testRollbackPermissionsAndPreviewOnlyImports(): void
    {
        $admin = $this->signedInAs('admin');
        $id = $this->importAndConfirm($admin, [['Location', 'XP', 'Pland']]);

        foreach (['sales_manager', 'sales_user'] as $role) {
            $other = $this->signedInAs($role, "{$role}-rollback@example.com");
            self::assertError($other->request('POST', "/api/imports/{$id}/rollback/preview", []), 403, 'forbidden');
            self::assertError($other->request('POST', "/api/imports/{$id}/rollback", []), 403, 'forbidden');
        }
        self::assertSame(1, $this->locationCount('XP'), 'a forbidden rollback changes nothing');
        self::assertSame('completed', $this->db()->query("SELECT status FROM imports WHERE id = {$id}")->fetchColumn());

        // A previewed (never confirmed) import cannot be rolled back.
        $previewOnly = $this->upload($admin, [['Location', 'XQ', 'Qland']]);
        self::assertError($admin->request('POST', "/api/imports/{$previewOnly}/rollback", []), 409, 'rollback_not_allowed');
        self::assertSame(404, $admin->request('POST', '/api/imports/999999/rollback', [])['status']);

        $super = $this->signedInAs('super_admin', 'super-rollback@example.com');
        $rollback = $super->request('POST', "/api/imports/{$id}/rollback", []);
        self::assertSame(200, $rollback['status'], $rollback['raw']);
        self::assertSame('rolled_back', $rollback['body']['data']['rollback']['status']);
        self::assertSame(0, $this->locationCount('XP'));
        self::assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM import_audit_logs WHERE import_id = {$id} AND action = 'rollback'")->fetchColumn());
    }

    /** @param list<list<string>> $rows */
    private function importAndConfirm(ApiClient $client, array $rows): int
    {
        $id = $this->upload($client, $rows);
        $confirm = $client->request('POST', "/api/imports/{$id}/confirm", []);
        self::assertSame(200, $confirm['status'], $confirm['raw']);
        self::assertSame(0, $confirm['body']['data']['import']['errorRows'], $confirm['raw']);

        return $id;
    }

    /** @param list<list<string>> $rows */
    private function upload(ApiClient $client, array $rows): int
    {
        $padded = array_map(static fn (array $r): array => array_pad($r, count(ImportTypes::UNIFIED_COLUMNS), ''), $rows);
        $file = ImportXlsx::write(['Import' => [ImportTypes::UNIFIED_COLUMNS, ...$padded]]);
        $boundary = '----yorilife' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"type\"\r\n\r\nunified\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"data.xlsx\"\r\nContent-Type: application/octet-stream\r\n\r\n{$file}\r\n"
            . "--{$boundary}--\r\n";
        $response = $client->request('POST', '/api/imports', $body, ['Content-Type' => "multipart/form-data; boundary={$boundary}"]);
        self::assertSame(201, $response['status'], $response['raw']);

        return (int) $response['body']['data']['import']['id'];
    }

    private function db(): \PDO
    {
        return Database::connection();
    }

    private function locationName(string $code): mixed
    {
        $stmt = $this->db()->prepare('SELECT name FROM locations WHERE code = ?');
        $stmt->execute([$code]);

        return $stmt->fetchColumn();
    }

    private function locationCount(string $code): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM locations WHERE code = ?');
        $stmt->execute([$code]);

        return (int) $stmt->fetchColumn();
    }
}