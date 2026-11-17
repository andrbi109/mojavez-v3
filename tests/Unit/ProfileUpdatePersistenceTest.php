<?php
declare(strict_types=1);

require_once __DIR__ . '/../TestCase.php';

/**
 * Unit test for PdoUserAccountRepository::update() — Phase 13 (v1.4.0).
 *
 * Verifies that the Phase 13 whitelist extension (WHITELIST = ['username',
 * 'mobile', 'email', 'display_name']) actually persists `email` and
 * `display_name` to the `user_accounts` row. Before Phase 13, the
 * whitelist was `['username', 'mobile']` and these two fields were
 * silently dropped — this test would have FAILED before Phase 13 (pays
 * D-12-1).
 *
 * Test environment: requires a real DB-backed Container. If the DB or
 * Container is unavailable, the test is skipped (same pattern as
 * SmsServiceTest, NotificationServiceTest, AvatarServiceTest).
 */
final class ProfileUpdatePersistenceTest extends TestCase
{
    private \App\Container $container;
    private \App\Shared\Persistence\Connection $conn;
    private \App\Shared\Contracts\UserAccountRepositoryInterface $repo;
    private int $accountId = 0;
    private string $mobile = '09129990130';

    public function name(): string
    {
        return 'ProfileUpdatePersistenceTest';
    }

    public function setUp(): void
    {
        $logger = new \App\Shared\Support\StructuredLogger(
            sys_get_temp_dir() . '/profile-persistence-test.log',
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
            $this->conn->execute("DELETE FROM user_accounts WHERE mobile = ?", [$this->mobile]);
        } catch (\Throwable) {
            // ignore — best-effort
        }

        // Create a test account with the OLD schema (no email/display_name
        // pre-set) so we can verify the update() adds them.
        $this->accountId = (int)$this->conn->insert(
            "INSERT INTO user_accounts (username, mobile, password, is_active) VALUES ('pup_test', ?, 'x', 1)",
            [$this->mobile]
        );
    }

    public function tearDown(): void
    {
        try {
            $this->conn->execute("DELETE FROM user_accounts WHERE id = ?", [$this->accountId]);
        } catch (\Throwable) {
            // ignore — best-effort
        }
    }

    public function run(): void
    {
        // 1. update() with email + display_name → DB row has them.
        //    Before Phase 13, these were silently dropped — the DB row
        //    would have email=NULL, display_name=NULL even though the
        //    caller passed them. This assertion would have FAILED
        //    against the Phase 12 whitelist.
        $this->repo->update($this->accountId, [
            'email'        => 'pup-test@example.com',
            'display_name' => 'علی رضایی',
        ]);
        $row = $this->conn->one(
            'SELECT email, display_name FROM user_accounts WHERE id = ?',
            [$this->accountId]
        );
        $this->assertNotNull($row, 'updated row found');
        $this->assertSame('pup-test@example.com', (string)$row['email'], 'email persisted (was silently dropped before Phase 13)');
        $this->assertSame('علی رضایی', (string)$row['display_name'], 'display_name persisted (was silently dropped before Phase 13)');

        // 2. update() with all 4 whitelist fields together.
        $this->repo->update($this->accountId, [
            'username'     => 'pup_renamed',
            'mobile'       => '09129990131',
            'email'        => 'renamed@example.com',
            'display_name' => 'رضا',
        ]);
        $row = $this->conn->one(
            'SELECT username, mobile, email, display_name FROM user_accounts WHERE id = ?',
            [$this->accountId]
        );
        $this->assertSame('pup_renamed', (string)$row['username'], 'username updated');
        $this->assertSame('09129990131', (string)$row['mobile'], 'mobile updated');
        $this->assertSame('renamed@example.com', (string)$row['email'], 'email updated (multi-field update)');
        $this->assertSame('رضا', (string)$row['display_name'], 'display_name updated (multi-field update)');

        // 3. update() with email = null clears the field (allowed because
        //    the column is NULLable + DEFAULT NULL).
        $this->repo->update($this->accountId, ['email' => null]);
        $row = $this->conn->one('SELECT email FROM user_accounts WHERE id = ?', [$this->accountId]);
        $this->assertNull($row['email'], 'email cleared to null');

        // 4. update() with an unknown field (not in WHITELIST) is silently
        //    dropped — same behaviour as before Phase 13. This protects
        //    against mass-assignment vulnerabilities.
        $this->repo->update($this->accountId, [
            'is_active' => 0,           // NOT in WHITELIST — must be dropped
            'password'  => 'should-not-persist',  // NOT in WHITELIST — must be dropped
            'email'     => 'kept@example.com',
        ]);
        $row = $this->conn->one(
            'SELECT is_active, password, email FROM user_accounts WHERE id = ?',
            [$this->accountId]
        );
        $this->assertSame('1', (string)$row['is_active'], 'is_active NOT changed (not in whitelist — mass-assignment defense)');
        $this->assertSame('x', (string)$row['password'], 'password NOT changed (not in whitelist — mass-assignment defense)');
        $this->assertSame('kept@example.com', (string)$row['email'], 'email IS changed (in whitelist)');

        // 5. update() with no whitelisted fields is a no-op (short-circuit
        //    — avoids the `UPDATE ... SET WHERE id = ?` syntax error).
        $before = $this->conn->one('SELECT email, display_name FROM user_accounts WHERE id = ?', [$this->accountId]);
        $this->repo->update($this->accountId, ['unknown_field' => 'x']);
        $after = $this->conn->one('SELECT email, display_name FROM user_accounts WHERE id = ?', [$this->accountId]);
        $this->assertSame($before['email'], $after['email'], 'no-op update leaves email unchanged');
        $this->assertSame($before['display_name'], $after['display_name'], 'no-op update leaves display_name unchanged');

        // 6. Update the mobile back to the setUp value so tearDown's
        //    DELETE WHERE id works (it does, by id) and doesn't leave a
        //    row with the alternate mobile lingering in the DB.
        $this->repo->update($this->accountId, ['mobile' => $this->mobile]);
    }
}

return new ProfileUpdatePersistenceTest();
