<?php
declare(strict_types=1);

use App\Shared\Support\Normalizer;

require_once __DIR__ . '/../TestCase.php';

final class NormalizerTest extends TestCase
{
    public function name(): string
    {
        return 'NormalizerTest';
    }

    public function run(): void
    {
        // Persian digits → Latin.
        $this->assertSame('1234567890', Normalizer::digits('۱۲۳۴۵۶۷۸۹۰'), 'Persian digits');
        // Arabic-Indic digits → Latin.
        $this->assertSame('1234567890', Normalizer::digits('١٢٣٤٥٦٧٨٩٠'), 'Arabic-Indic digits');
        // Mixed Persian + Arabic + Latin.
        $this->assertSame('0123456789', Normalizer::digits('۰۱۲۳۴٥٦٧٨٩'), 'Mixed digits');
        // Empty string.
        $this->assertSame('', Normalizer::digits(''), 'Empty digits');

        // National code normalization — 9-digit input padded to 10 (leading 0).
        $this->assertSame('0721593107', Normalizer::nationalCode('721593107'), 'National code padding');
        // National code with separators stripped.
        $this->assertSame('0720593107', Normalizer::nationalCode('072-059-3107'), 'National code with dashes');
        // Persian digits in national code.
        $this->assertSame('0720593107', Normalizer::nationalCode('۰۷۲۰۵۹۳۱۰۷'), 'Persian national code');
        // Too long → null.
        $this->assertNull(Normalizer::nationalCode('12345678901'), 'National code too long');
        // Empty → null.
        $this->assertNull(Normalizer::nationalCode(''), 'National code empty');

        // Mobile normalization.
        $this->assertSame('09152345678', Normalizer::mobile('09152345678'), 'Mobile canonical');
        $this->assertSame('09152345678', Normalizer::mobile('989152345678'), 'Mobile with country code 98');
        $this->assertSame('09152345678', Normalizer::mobile('+989152345678'), 'Mobile with +98');
        $this->assertSame('09152345678', Normalizer::mobile('0915-234-5678'), 'Mobile with dashes');
        $this->assertSame('09152345678', Normalizer::mobile('۰۹۱۵۲۳۴۵۶۷۸'), 'Persian mobile');
        $this->assertNull(Normalizer::mobile('12345'), 'Mobile too short');
        $this->assertNull(Normalizer::mobile('0912345'), 'Mobile wrong length');
        $this->assertNull(Normalizer::mobile('10123456789'), 'Mobile not starting with 09');

        // Persian text normalization.
        $this->assertSame('hello world', Normalizer::persianText('  hello   world  '), 'Whitespace collapse');
        $this->assertSame('سلام دنیا', Normalizer::persianText('  سلام   دنیا  '), 'Persian whitespace');
        // ZWNJ is preserved (significant in Persian text).
        $this->assertSame('می‌روم', Normalizer::persianText(' می‌روم '), 'ZWNJ preserved');

        // National code mask — anchor pattern for golden-link matching.
        $this->assertSame('072****107', Normalizer::nationalCodeMask('0720593107'), 'Mask pattern');
        $this->assertSame('072****107', Normalizer::nationalCodeMask('۷۲۰۵۹۳۱۰۷'), 'Persian mask input');
        $this->assertSame('', Normalizer::nationalCodeMask('invalid'), 'Mask invalid input');
    }
}

return new NormalizerTest();
