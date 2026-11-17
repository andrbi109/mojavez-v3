<?php
declare(strict_types=1);

use App\Support\Validator;

require_once __DIR__ . '/../TestCase.php';

final class ValidatorTest extends TestCase
{
    public function name(): string
    {
        return 'ValidatorTest';
    }

    public function run(): void
    {
        // nationalCode() — valid 10-digit code with correct parity.
        // Test code: 0720593107 — parity check:
        //   sum = 0*10 + 7*9 + 2*8 + 0*7 + 5*6 + 9*5 + 3*4 + 1*3 + 0*2
        //       = 0 + 63 + 16 + 0 + 30 + 45 + 12 + 3 + 0 = 169
        //   169 % 11 = 4. Since 4 >= 2: control = 11 - 4 = 7. Matches last digit 7. ✓
        $valid = Validator::nationalCode('0720593107');
        $this->assertSame('0720593107', $valid, 'valid national code with correct parity');

        // Persian digit input normalised.
        $valid = Validator::nationalCode('۰۷۲۰۵۹۳۱۰۷');
        $this->assertSame('0720593107', $valid, 'Persian-digit national code normalised');

        // Short input padded.
        $valid = Validator::nationalCode('721593107');
        $this->assertSame('0720593107', $valid, '9-digit national code padded');

        // Wrong parity → null.
        $valid = Validator::nationalCode('0720593100');
        $this->assertNull($valid, 'wrong parity returns null');

        // Non-numeric → null.
        $this->assertNull(Validator::nationalCode('not-a-code'), 'non-numeric returns null');
        $this->assertNull(Validator::nationalCode(''), 'empty returns null');

        // Too long → null.
        $this->assertNull(Validator::nationalCode('123456789012345'), 'too long returns null');

        // trackingCode() — HG-XXXXXXXX format.
        $this->assertSame('HG-ABC12345', Validator::trackingCode('HG-ABC12345'), 'valid tracking code');
        $this->assertSame('HG-ABCD1234', Validator::trackingCode('HG-ABCD1234'), 'valid tracking code all alnum');
        $this->assertNull(Validator::trackingCode('HG-abc12345'), 'lowercase rejected');
        $this->assertNull(Validator::trackingCode('HG-ABC1234'), 'too short rejected');
        $this->assertNull(Validator::trackingCode('XX-ABC12345'), 'wrong prefix rejected');
        $this->assertNull(Validator::trackingCode(''), 'empty tracking code rejected');

        // mobile() — Iranian mobile.
        $this->assertSame('09152345678', Validator::mobile('09152345678'), 'valid mobile');
        $this->assertSame('09152345678', Validator::mobile('+989152345678'), 'mobile with +98');
        $this->assertNull(Validator::mobile('12345'), 'short mobile rejected');
        $this->assertNull(Validator::mobile('0912345678'), 'mobile too short');
        $this->assertNull(Validator::mobile('10123456789'), 'mobile not starting with 09');

        // jalaliDateOrNull() — YYYY/MM/DD with Persian digits.
        $this->assertSame('1405/08/21', Validator::jalaliDateOrNull('1405/08/21'), 'Jalali date valid');
        $this->assertSame('1405/08/21', Validator::jalaliDateOrNull('۱۴۰۵/۰۸/۲۱'), 'Jalali Persian digits');
        $this->assertNull(Validator::jalaliDateOrNull('not-a-date'), 'Jalali invalid returns null');
        $this->assertNull(Validator::jalaliDateOrNull('1405-08-21'), 'Jalali wrong separator returns null');
        $this->assertNull(Validator::jalaliDateOrNull('99/01/01'), 'Jalali short year rejected');

        // intOrNull, positiveIntOrNull, intInRange.
        $this->assertSame(42, Validator::intOrNull(42), 'intOrNull int input');
        $this->assertSame(42, Validator::intOrNull('42'), 'intOrNull string input');
        $this->assertSame(-5, Validator::intOrNull('-5'), 'intOrNull negative');
        $this->assertNull(Validator::intOrNull(''), 'intOrNull empty null');
        $this->assertNull(Validator::intOrNull('not-a-number'), 'intOrNull invalid null');
        $this->assertSame(5, Validator::positiveIntOrNull(5), 'positiveIntOrNull');
        $this->assertNull(Validator::positiveIntOrNull(-3), 'positiveIntOrNull rejects negative');
        $this->assertNull(Validator::positiveIntOrNull(0), 'positiveIntOrNull rejects zero');
        $this->assertSame(5, Validator::intInRange(5, 1, 10), 'intInRange in range');
        $this->assertNull(Validator::intInRange(11, 1, 10), 'intInRange out of range');
        $this->assertNull(Validator::intInRange(0, 1, 10), 'intInRange below range');

        // nonEmptyString, sanitizeText.
        $this->assertSame('hello', Validator::nonEmptyString('  hello  '), 'nonEmptyString trims');
        $this->assertNull(Validator::nonEmptyString('   '), 'nonEmptyString rejects whitespace-only');
        $this->assertNull(Validator::nonEmptyString(''), 'nonEmptyString rejects empty');
        $this->assertNull(Validator::nonEmptyString(str_repeat('a', 300), 255), 'nonEmptyString length cap');
        $sanitised = Validator::sanitizeText("  hello\x00\x01world  ");
        $this->assertSame('hello world', $sanitised, 'sanitizeText strips control chars');

        // inEnum.
        $this->assertSame('active', Validator::inEnum('active', ['active', 'expired']), 'inEnum valid');
        $this->assertNull(Validator::inEnum('unknown', ['active', 'expired']), 'inEnum invalid');
        $this->assertNull(Validator::inEnum(null, ['active']), 'inEnum non-string');

        // nationalRequestNumber.
        $this->assertSame('I17981871', Validator::nationalRequestNumber('I17981871'), 'valid request number');
        $this->assertSame('Q-12345678', Validator::nationalRequestNumber('Q-12345678'), 'valid with dash');
        $this->assertNull(Validator::nationalRequestNumber(''), 'empty rejected');
        $this->assertNull(Validator::nationalRequestNumber('ab'), 'too short rejected');
        $this->assertNull(Validator::nationalRequestNumber(str_repeat('a', 33)), 'too long rejected');
    }
}

return new ValidatorTest();
