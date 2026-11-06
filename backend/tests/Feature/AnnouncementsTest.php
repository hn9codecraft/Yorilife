<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Database;
use App\Services\AnnouncementService;
use Tests\Support\ApiClient;
use Tests\Support\FeatureTestCase;

/** Announcements admin API: drafts, audience, publish / schedule / expiry, pin, recall, files, templates, tracking. */
final class AnnouncementsTest extends FeatureTestCase
{
    /** @var array<string, int> */
    private array $org = [];

    public function testOnlyAdminsCanUseAnnouncements(): void
    {
        foreach (['sales_manager', 'sales_user'] as $role) {
            $client = $this->signedInAs($role);
            self::assertError($client->request('GET', '/api/announcements'), 403, 'forbidden');
            self::assertError($client->request('POST', '/api/announcements', ['title' => 'x', 'bodyHtml' => 'x']), 403, 'forbidden');
            self::assertError($client->request('GET', '/api/employees'), 403, 'forbidden');
            $me = $client->request('GET', '/api/auth/me');
            self::assertNotContains('announcements.view', $me['body']['data']['user']['permissions'] ?? $me['body']['data']['permissions'] ?? []);
        }
        $owner = $this->signedInAs('super_admin');
        self::assertSame(200, $owner->request('GET', '/api/announcements')['status']);
        self::assertError((new ApiClient())->request('GET', '/api/announcements'), 401, 'unauthenticated');
    }

    public function testDraftIsCleanedValidatedAndLogged(): void
    {
        $admin = $this->signedInAs('admin');
        $bad = $admin->request('POST', '/api/announcements', ['title' => '', 'type' => 'party', 'priority' => 'x', 'expiresAt' => '2001-01-01T10:00', 'extra' => 1]);
        self::assertError($bad, 422, 'validation_failed');
        self::assertEqualsCanonicalizing(['title', 'type', 'priority', 'expiresAt', 'extra'], array_keys($bad['body']['error']['details']['fields']));

        $created = $admin->request('POST', '/api/announcements', [
            'title' => 'Diwali holiday',
            'bodyHtml' => '<p>Office closed <b>Friday</b><script>alert(1)</script></p>',
            'type' => 'holiday',
            'priority' => 'important',
            'requiresAck' => true,
            'audienceAll' => true,
        ]);
        self::assertSame(201, $created['status'], $created['raw']);
        $a = $created['body']['data']['announcement'];
        self::assertSame('draft', $a['status']);
        self::assertSame('<p>Office closed <strong>Friday</strong></p>', $a['bodyHtml']);
        self::assertMatchesRegularExpression('/^ANN-\d{4}-\d{4}$/', $a['reference']);
        self::assertSame('created', $a['history'][0]['action']);
        self::assertTrue($a['can']['delete']);

        $edit = $admin->request('PUT', "/api/announcements/{$a['id']}", ['title' => 'Diwali holiday notice', 'bodyHtml' => '<p>Office closed Friday</p>', 'type' => 'holiday', 'priority' => 'urgent', 'audienceAll' => true, 'requiresAck' => true]);
        self::assertSame(200, $edit['status'], $edit['raw']);
        $history = $edit['body']['data']['announcement']['history'];
        self::assertSame('updated', $history[0]['action']);
        self::assertEquals(['from' => 'important', 'to' => 'urgent'], $history[0]['changes']['priority']);

        self::assertSame(200, $admin->request('POST', "/api/announcements/{$a['id']}/delete", [])['status']);
        self::assertError($admin->request('GET', "/api/announcements/{$a['id']}"), 404, 'not_found');
    }

    public function testAudienceFiltersAndFrozenRecipients(): void
    {
        $admin = $this->signedInAs('admin');
        $this->organisation($admin);
        $preview = fn (array $body): array => $admin->request('POST', '/api/announcements/audience-preview', $body)['body']['data']['audience'];

        $all = $preview(['audienceAll' => true]);
        // 3 managers + 2 active ERP users (admin, sales) + 2 active employees (inactive one left out)
        self::assertSame(['manager' => 3, 'user' => 2, 'employee' => 2], $all['byType']);

        self::assertSame(['manager' => 3, 'user' => 0, 'employee' => 1], $preview(['audience' => ['location' => [$this->org['gujarat']]]])['byType'], 'managers inherit the state; employee by own state');
        self::assertSame(['manager' => 1, 'user' => 0, 'employee' => 0], $preview(['audience' => ['managerLevel' => [1]]])['byType']);
        self::assertSame(['manager' => 1, 'user' => 0, 'employee' => 0], $preview(['audience' => ['managerLevel' => [1], 'location' => [$this->org['gujarat']]]])['byType'], 'AND between filters');
        self::assertSame(0, $preview(['audience' => ['managerLevel' => [1], 'location' => [$this->org['bihar']]]])['total']);
        self::assertSame(['manager' => 3, 'user' => 0, 'employee' => 1], $preview(['audience' => ['headquarters' => [$this->org['hq']]]])['byType'], 'HQ: the 1st line manager and lines above, plus linked employees');
        self::assertSame(['manager' => 0, 'user' => 1, 'employee' => 1], $preview(['audience' => ['role' => ['sales_user', 'Field Officer']]])['byType'], 'OR inside a filter');
        self::assertSame(['manager' => 0, 'user' => 0, 'employee' => 1], $preview(['audience' => ['employee' => [$this->org['emp2']]]])['byType']);
        self::assertError($admin->request('POST', '/api/announcements/audience-preview', ['audience' => ['managerLevel' => [7]]]), 422, 'validation_failed');

        $id = $this->draft($admin, ['audienceAll' => false, 'audience' => ['managerLevel' => [1]]]);
        $published = $admin->request('POST', "/api/announcements/{$id}/publish", []);
        self::assertSame(200, $published['status'], $published['raw']);
        $a = $published['body']['data']['announcement'];
        self::assertSame('published', $a['status']);
        self::assertSame(1, $a['recipientCount']);

        // Org change after publish does not change who got it.
        Database::connection()->exec("UPDATE managers SET level = level WHERE id = {$this->org['m1']}");
        Database::connection()->prepare('INSERT INTO managers (level, name, code, parent_manager_id) VALUES (1, ?, ?, ?)')->execute(['New Line', 'T-M1-NEW', $this->org['m2']]);
        $tracking = $admin->request('GET', "/api/announcements/{$id}/tracking")['body']['data']['tracking'];
        self::assertSame(1, $tracking['counts']['total']);
        self::assertFalse($tracking['appConnected']);
        self::assertSame(0, $tracking['counts']['read']);
        self::assertSame(['announcement.published' => 1], $tracking['outbox']);
        self::assertError($admin->request('PUT', "/api/announcements/{$id}", ['title' => 'Changed', 'bodyHtml' => 'x', 'audience' => ['managerLevel' => [2]]]), 422, 'validation_failed');
    }

    public function testPublishNeedsAnAudience(): void
    {
        $admin = $this->signedInAs('admin');
        $id = $this->draft($admin, ['audienceAll' => false]);
        $response = $admin->request('POST', "/api/announcements/{$id}/publish", []);
        self::assertError($response, 422, 'validation_failed');
        self::assertArrayHasKey('audience', $response['body']['error']['details']['fields']);
    }

    public function testScheduleTickExpiryPinAndRecall(): void
    {
        $admin = $this->signedInAs('admin');
        $id = $this->draft($admin);
        $future = (new \DateTimeImmutable('+2 hours', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d\TH:i');
        $scheduled = $admin->request('POST', "/api/announcements/{$id}/publish", ['publishAt' => $future]);
        self::assertSame(200, $scheduled['status'], $scheduled['raw']);
        self::assertSame('scheduled', $scheduled['body']['data']['announcement']['status']);
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM announcement_recipients WHERE announcement_id = {$id}")->fetchColumn(), 'not frozen until it goes live');

        $back = $admin->request('POST', "/api/announcements/{$id}/unschedule", []);
        self::assertSame('draft', $back['body']['data']['announcement']['status']);
        $admin->request('POST', "/api/announcements/{$id}/publish", ['publishAt' => $future]);

        Database::connection()->exec("UPDATE announcements SET publish_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = {$id}");
        $tick = (new AnnouncementService())->tick();
        self::assertSame([$id], $tick['published']);
        $live = $admin->request('GET', "/api/announcements/{$id}")['body']['data']['announcement'];
        self::assertSame('published', $live['status']);
        self::assertSame(1, $live['recipientCount']);

        $pinned = $admin->request('POST', "/api/announcements/{$id}/pin", ['pinned' => true]);
        self::assertTrue($pinned['body']['data']['announcement']['isPinned']);

        $edited = $admin->request('PUT', "/api/announcements/{$id}", ['title' => 'Updated title', 'bodyHtml' => '<p>Body changed</p>', 'audienceAll' => true, 'markUpdated' => true]);
        self::assertSame(200, $edited['status'], $edited['raw']);
        self::assertTrue($edited['body']['data']['announcement']['isUpdated']);
        self::assertSame(2, $edited['body']['data']['announcement']['version']);

        self::assertError($admin->request('POST', "/api/announcements/{$id}/recall", ['reason' => '']), 422, 'validation_failed');
        $recalled = $admin->request('POST', "/api/announcements/{$id}/recall", ['reason' => 'Sent by mistake']);
        self::assertSame('recalled', $recalled['body']['data']['announcement']['status']);
        self::assertSame('Sent by mistake', $recalled['body']['data']['announcement']['recallReason']);
        self::assertFalse($recalled['body']['data']['announcement']['isPinned']);
        $outbox = $admin->request('GET', "/api/announcements/{$id}/tracking")['body']['data']['tracking']['outbox'];
        ksort($outbox);
        self::assertSame(['announcement.published' => 1, 'announcement.recalled' => 1, 'announcement.updated' => 1], $outbox, $this->sortKeys($outbox));
        self::assertError($admin->request('PUT', "/api/announcements/{$id}", ['title' => 'x', 'bodyHtml' => 'x', 'audienceAll' => true]), 409, 'not_editable');

        $copy = $admin->request('POST', "/api/announcements/{$id}/copy", []);
        self::assertSame(201, $copy['status']);
        self::assertSame('draft', $copy['body']['data']['announcement']['status']);

        // Expiry (API safety net runs on list).
        $id2 = $this->draft($admin, ['expiresAt' => (new \DateTimeImmutable('+1 day', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d\TH:i')]);
        $admin->request('POST', "/api/announcements/{$id2}/publish", []);
        Database::connection()->exec("UPDATE announcements SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = {$id2}");
        $list = $admin->request('GET', '/api/announcements')['body']['data']['announcements'];
        $row = array_values(array_filter($list, static fn (array $a): bool => $a['id'] === $id2))[0];
        self::assertSame('expired', $row['status']);
    }

    public function testAttachmentsTemplatesAndCsv(): void
    {
        $admin = $this->signedInAs('admin');
        $id = $this->draft($admin);
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
        $upload = $this->upload($admin, $id, 'file', 'policy.pdf', $pdf);
        self::assertSame(201, $upload['status'], $upload['raw']);
        $fileId = $upload['body']['data']['attachment']['id'];
        self::assertError($this->upload($admin, $id, 'file', 'evil.pdf', '<?php echo 1;'), 422, 'validation_failed');
        self::assertError($this->upload($admin, $id, 'banner', 'b.pdf', $pdf), 422, 'validation_failed');
        self::assertError($this->upload($admin, $id, 'file', 'big.pdf', $pdf . str_repeat('a', 5 * 1024 * 1024)), 422, 'validation_failed');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $banner = $this->upload($admin, $id, 'banner', 'banner.png', $png);
        self::assertSame(201, $banner['status'], $banner['raw']);
        for ($i = 0; $i < 4; $i++) {
            $this->upload($admin, $id, 'file', "f{$i}.pdf", $pdf);
        }
        self::assertError($this->upload($admin, $id, 'file', 'sixth.pdf', $pdf), 422, 'validation_failed');

        $download = $admin->request('GET', "/api/announcements/{$id}/attachments/{$fileId}");
        self::assertSame(200, $download['status']);
        self::assertSame($pdf, $download['raw']);
        self::assertStringContainsString('attachment; filename="policy.pdf"', $download['headers']['content-disposition']);
        $detail = $admin->request('GET', "/api/announcements/{$id}")['body']['data']['announcement'];
        self::assertSame($banner['body']['data']['attachment']['id'], $detail['bannerId']);
        self::assertCount(6, $detail['attachments']);
        self::assertSame(200, $admin->request('POST', "/api/announcements/{$id}/attachments/{$fileId}/delete", [])['status']);
        self::assertError($admin->request('GET', "/api/announcements/{$id}/attachments/{$fileId}"), 404, 'not_found');
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/public/announcements', 'files are never in the public folder');

        $template = $admin->request('POST', '/api/announcement-templates', ['name' => 'Holiday notice', 'type' => 'holiday', 'priority' => 'normal', 'title' => 'Holiday on {date}', 'bodyHtml' => '<p>Office closed<script>x</script></p>', 'requiresAck' => false]);
        self::assertSame(201, $template['status'], $template['raw']);
        self::assertSame('<p>Office closed</p>', $template['body']['data']['template']['bodyHtml']);
        $tid = $template['body']['data']['template']['id'];
        self::assertSame('Holiday notice 2', $admin->request('PATCH', "/api/announcement-templates/{$tid}", ['name' => 'Holiday notice 2', 'title' => 'Holiday', 'bodyHtml' => ''])['body']['data']['template']['name']);
        self::assertSame(200, $admin->request('POST', "/api/announcement-templates/{$tid}/delete", [])['status']);
        self::assertSame([], $admin->request('GET', '/api/announcement-templates')['body']['data']['templates']);

        $admin->request('POST', "/api/announcements/{$id}/publish", []);
        $csv = $admin->request('GET', "/api/announcements/{$id}/tracking/export?pending=1");
        self::assertSame(200, $csv['status'], $csv['raw']);
        self::assertStringStartsWith('text/csv', $csv['headers']['content-type']);
        self::assertStringContainsString('not-read.csv', $csv['headers']['content-disposition']);
        self::assertStringContainsString('Type,Name,Email / Phone', $csv['raw']);
        self::assertStringContainsString('Pending', $csv['raw']);
    }

    public function testEmployeesCrud(): void
    {
        $admin = $this->signedInAs('admin');
        $bad = $admin->request('POST', '/api/employees', ['name' => '', 'email' => 'nope', 'status' => 'gone', 'locationId' => 999999]);
        self::assertError($bad, 422, 'validation_failed');
        self::assertEqualsCanonicalizing(['name', 'email', 'status', 'locationId'], array_keys($bad['body']['error']['details']['fields']));
        $created = $admin->request('POST', '/api/employees', ['name' => 'Riya Shah', 'email' => 'Riya@Example.com', 'phone' => '9876543210', 'role' => 'Field Officer']);
        self::assertSame(201, $created['status'], $created['raw']);
        $employee = $created['body']['data']['employee'];
        self::assertSame('riya@example.com', $employee['email']);
        self::assertError($admin->request('POST', '/api/employees', ['name' => 'Copy', 'email' => 'riya@example.com']), 422, 'validation_failed');
        $updated = $admin->request('PATCH', "/api/employees/{$employee['id']}", ['name' => 'Riya S', 'status' => 'inactive']);
        self::assertSame('inactive', $updated['body']['data']['employee']['status']);
        self::assertSame(200, $admin->request('POST', "/api/employees/{$employee['id']}/delete", [])['status']);
        self::assertSame([], $admin->request('GET', '/api/employees')['body']['data']['employees']);
    }

    // ---- Helpers ------------------------------------------------------------

    private function draft(ApiClient $client, array $extra = []): int
    {
        $response = $client->request('POST', '/api/announcements', $extra + ['title' => 'Notice', 'bodyHtml' => '<p>Hello</p>', 'audienceAll' => true]);
        self::assertSame(201, $response['status'], $response['raw']);

        return (int) $response['body']['data']['announcement']['id'];
    }

    private function upload(ApiClient $client, int $id, string $kind, string $name, string $content): array
    {
        $boundary = '----yorilife' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"kind\"\r\n\r\n{$kind}\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$content}\r\n"
            . "--{$boundary}--\r\n";

        return $client->request('POST', "/api/announcements/{$id}/attachments", $body, ['Content-Type' => "multipart/form-data; boundary={$boundary}"]);
    }

    private function sortKeys(array $values): string
    {
        ksort($values);

        return json_encode($values);
    }

    /** 3rd -> 2nd -> 1st line managers in Gujarat, one HQ, ERP sales user, 3 employees (one inactive). */
    private function organisation(ApiClient $admin): void
    {
        $pdo = Database::connection();
        $location = static fn (string $name): int => (int) $pdo->query('SELECT id FROM locations WHERE name = ' . $pdo->quote($name))->fetchColumn();
        $this->org['gujarat'] = $location('Gujarat');
        $this->org['bihar'] = $location('Bihar');
        $insert = $pdo->prepare('INSERT INTO managers (level, name, code, email, parent_manager_id) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([3, 'Third', 'T-M3', 'm3@example.test', null]);
        $this->org['m3'] = (int) $pdo->lastInsertId();
        $insert->execute([2, 'Second', 'T-M2', null, $this->org['m3']]);
        $this->org['m2'] = (int) $pdo->lastInsertId();
        $insert->execute([1, 'First', 'T-M1', null, $this->org['m2']]);
        $this->org['m1'] = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO manager_states (manager_id, location_id) VALUES (?, ?)')->execute([$this->org['m3'], $this->org['gujarat']]);
        $pdo->prepare('INSERT INTO headquarters (name, code, location_id, parent_manager_id) VALUES (?, ?, ?, ?)')->execute(['Ahmedabad', 'T-HQ', $this->org['gujarat'], $this->org['m1']]);
        $this->org['hq'] = (int) $pdo->lastInsertId();
        $this->createUser('sales@example.test', 'sales_user');
        $this->createUser('off@example.test', 'sales_user', 'disabled');

        $employee = fn (array $body): int => (int) $admin->request('POST', '/api/employees', $body)['body']['data']['employee']['id'];
        $this->org['emp1'] = $employee(['name' => 'Emp One', 'headquartersId' => $this->org['hq'], 'role' => 'Field Officer']);
        $this->org['emp2'] = $employee(['name' => 'Emp Two', 'locationId' => $this->org['bihar'], 'role' => 'Accounts']);
        $employee(['name' => 'Emp Gone', 'locationId' => $this->org['gujarat'], 'status' => 'inactive']);
    }
}