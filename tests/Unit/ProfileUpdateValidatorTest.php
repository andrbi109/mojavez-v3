<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Unit test for App\Modules\Core\Support\ProfileUpdateValidator (Phase 12).
 *
 * 4 cases:
 *   1. All four fields valid → valid=true, errors=[], clean has all 4.
 *   2. Duplicate mobile → valid=false, errors contains "قبلاً ثبت شده".
 *   3. Invalid email → valid=false, errors contains email-related message.
 *   4. Empty body → valid=true, clean=[].
 *
 * Uses a real DB-backed repository (PdoUserAccountRepository) and a
 * real account created on the fly (cleaned up in tearDown). If DB is
 * unavailable, the test is skipped.
 */
final class ProfileUpdateValidatorTest extends TestCase
{
    private \App\Container $container;
    private \App\Shared\Persistence\Connection $conn;
    private \App\Shared\Contracts\UserAccountRepositoryInterface $repo;
    private int $accountId = 0;
    private int $otherAccountId = 0;
    private string $ownMobile    = '09129990050';
    private string $otherMobile  = '09129990051';

    public function name(): string
    {
        return 'ProfileUpdateValidatorTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(
            sys_get_temp_dir() . '/profile-validator-test.log',
            'debug'
        );
        try {
            $env = \App\Shared\Support\Environment::load(dirname(__DIR__, 2) . '/.env');
            $this->container = new \App\Container($env, $logger);
            $this->container->boot(dirname(__DIR__, 2));
            $this->conn = $this->container->make(\App\Shared\Persistence\Connection::class);
            $this->repo = $this->container->make(\App\Shared\Contracts\UserAccountRepositoryInterface::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB or container unavailable: ' . $e->getMessage());
            return;
        }

        // Clean leftovers from any prior run.
        try {
            $this->conn->execute("DELETE FROM user_accounts WHERE mobile IN (?, ?)", [
                $this->ownMobile, $this->otherMobile,
            ]);
        } catch (\Throwable) {
            // ignore — best-effort
        }

        // Create the test accounts (the "own" account + an "other"
        // account that owns the duplicate mobile).
        $this->accountId = (int)$this->conn->insert(
            "INSERT INTO user_accounts (username, mobile, password, is_active) VALUES ('puv_self', ?, 'x', 1)",
            [$this->ownMobile]
        );
        $this->otherAccountId = (int)$this->conn->insert(
            "INSERT INTO user_accounts (username, mobile, password, is_active) VALUES ('puv_other', ?, 'x', 1)",
            [$this->otherMobile]
        );
    }

    public function tearDown(): void
    {
        try {
            $this->conn->execute("DELETE FROM user_accounts WHERE id IN (?, ?)", [
                $this->accountId, $this->otherAccountId,
            ]);
        } catch (\Throwable) {
            // ignore — best-effort
        }
    }

    public function run(): void
    {
        // 1. All four fields valid → valid=true.
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            [
                'username'     => 'ali.reza',
                'mobile'        => '09129990052',   // a fresh mobile not in DB
                'email'         => 'ali@example.com',
                'display_name'  => 'علی رضا',
            ],
            $this->repo,
            $this->accountId
        );
        $this->assertTrue($result['valid'], 'all valid → valid=true');
        $this->assertSame([], $result['errors'], 'all valid → no errors');
        $this->assertSame('ali.reza', $result['clean']['username'] ?? null, 'username normalised to lower');
        $this->assertSame('09129990052', $result['clean']['mobile'] ?? null, 'mobile returned as-is');
        $this->assertSame('ali@example.com', $result['clean']['email'] ?? null, 'email returned as-is');
        $this->assertSame('علی رضا', $result['clean']['display_name'] ?? null, 'display_name returned as-is');

        // 2. Duplicate mobile (other account owns it) → valid=false,
        //    errors contains "قبلاً ثبت شده".
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            ['mobile' => $this->otherMobile],   // owned by the OTHER account
            $this->repo,
            $this->accountId
        );
        $this->assertFalse($result['valid'], 'duplicate mobile → valid=false');
        $dupErrFound = false;
        foreach ($result['errors'] as $e) {
            if (str_contains($e, 'قبلاً ثبت شده')) {
                $dupErrFound = true;
                break;
            }
        }
        $this->assertTrue($dupErrFound, 'duplicate mobile errors contain "قبلاً ثبت شده"');

        // 2b. The own mobile IS valid (it's the account's own mobile —
        //     the validator excludes $accountId from the uniqueness check).
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            ['mobile' => $this->ownMobile],
            $this->repo,
            $this->accountId
        );
        $this->assertTrue($result['valid'], 'own mobile → valid=true (excluded from uniqueness)');

        // 3. Invalid email → valid=false, errors contains "ایمیل نامعتبر".
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            ['email' => 'not-an-email'],
            $this->repo,
            $this->accountId
        );
        $this->assertFalse($result['valid'], 'invalid email → valid=false');
        $emailErrFound = false;
        foreach ($result['errors'] as $e) {
            if (str_contains($e, 'ایمیل نامعتبر')) {
                $emailErrFound = true;
                break;
            }
        }
        $this->assertTrue($emailErrFound, 'invalid email errors contain "ایمیل نامعتبر"');

        // 4. Empty body → valid=true, clean=[].
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            [],
            $this->repo,
            $this->accountId
        );
        $this->assertTrue($result['valid'], 'empty body → valid=true');
        $this->assertSame([], $result['errors'], 'empty body → no errors');
        $this->assertSame([], $result['clean'], 'empty body → empty clean');

        // 5. Bonus: invalid username pattern → valid=false.
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            ['username' => 'invalid user name!'],
            $this->repo,
            $this->accountId
        );
        $this->assertFalse($result['valid'], 'invalid username pattern → valid=false');

        // 6. Bonus: too-short username → valid=false.
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            ['username' => 'ab'],   // 2 chars < 3-char minimum
            $this->repo,
            $this->accountId
        );
        $this->assertFalse($result['valid'], 'too-short username → valid=false');

        // 7. Bonus: display_name too long → valid=false.
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            ['display_name' => str_repeat('x', 101)],
            $this->repo,
            $this->accountId
        );
        $this->assertFalse($result['valid'], 'display_name >100 chars → valid=false');

        // 8. Bonus: empty username (clear) → valid=true, clean has username=null.
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            ['username' => ''],
            $this->repo,
            $this->accountId
        );
        $this->assertTrue($result['valid'], 'empty username (clear) → valid=true');
        $this->assertArrayHasKey('username', $result['clean'], 'clean has username key');
        $this->assertNull($result['clean']['username'], 'username normalised to null (clear)');

        // 9. Phase 13 (v1.4.0): the validator's `clean` output is now
        //    PERSISTED — `update()` whitelist extended to include
        //    `email` + `display_name` (migration 008). Before Phase 13,
        //    these were silently dropped (D-12-1). Verify the
        //    persistence path end-to-end: validate → update → DB row.
        $validInput = [
            'username'     => 'ali.reza.persist',
            'mobile'       => '09129990149',  // a fresh mobile not in DB
            'email'        => 'persist@example.com',
            'display_name' => 'علی رضایی-پایدار',
        ];
        $result = \App\Modules\Core\Support\ProfileUpdateValidator::validate(
            $validInput,
            $this->repo,
            $this->accountId
        );
        $this->assertTrue($result['valid'], 'persistence-path validate → valid=true');
        $this->assertSame([], $result['errors'], 'persistence-path validate → no errors');
        // Persist via the repository — the Phase 13 whitelist extension
        // means email + display_name are now actually stored (before
        // Phase 13, they were silently dropped).
        $this->repo->update($this->accountId, $result['clean']);
        $row = $this->conn->one(
            'SELECT username, mobile, email, display_name FROM user_accounts WHERE id = ?',
            [$this->accountId]
        );
        $this->assertNotNull($row, 'persistence-path row found');
        $this->assertSame('ali.reza.persist', (string)$row['username'], 'persistence-path: username persisted');
        $this->assertSame('09129990149', (string)$row['mobile'], 'persistence-path: mobile persisted');
        $this->assertSame('persist@example.com', (string)$row['email'], 'persistence-path: email persisted (was silently dropped before Phase 13)');
        $this->assertSame('علی رضایی-پایدار', (string)$row['display_name'], 'persistence-path: display_name persisted (was silently dropped before Phase 13)');

        // 9b. Restoring the original mobile so tearDown's `DELETE WHERE id`
        //     works (it does, by id) — but also keeps the test account
        //     state consistent in case the test is re-run in the same
        //     process. The unique constraint on `mobile` would block
        //     re-running the test if we left the changed mobile in place.
        $this->repo->update($this->accountId, [
            'username' => 'puv_self',
            'mobile'   => $this->ownMobile,
            'email'    => null,
            'display_name' => null,
        ]);
    }
}

return new ProfileUpdateValidatorTest();
