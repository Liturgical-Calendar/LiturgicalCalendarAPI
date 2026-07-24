<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiocesanMetadata::class)]
final class DiocesanMetadataTest extends TestCase
{
    public function testRiteDefaultsToRomanWhenAbsent(): void
    {
        $meta = DiocesanMetadata::fromArray([
            'nation'       => 'IT',
            'diocese_id'   => 'agrige_it',
            'diocese_name' => 'Arcidiocesi di Agrigento',
            'locales'      => ['it_IT'],
            'timezone'     => 'Europe/Rome',
        ]);
        self::assertSame(Rite::ROMAN, $meta->rite);
    }

    public function testRiteParsedWhenPresent(): void
    {
        $meta = DiocesanMetadata::fromArray([
            'nation'       => 'IT',
            'diocese_id'   => 'milano_it',
            'diocese_name' => 'Arcidiocesi di Milano',
            'locales'      => ['it_IT'],
            'timezone'     => 'Europe/Rome',
            'rite'         => 'ambrosian',
        ]);
        self::assertSame(Rite::AMBROSIAN, $meta->rite);
    }

    public function testRiteDefaultsToRomanWhenAbsentFromObject(): void
    {
        $data = (object) [
            'nation'       => 'IT',
            'diocese_id'   => 'agrige_it',
            'diocese_name' => 'Arcidiocesi di Agrigento',
            'locales'      => ['it_IT'],
            'timezone'     => 'Europe/Rome',
        ];
        $meta = DiocesanMetadata::fromObject($data);
        self::assertSame(Rite::ROMAN, $meta->rite);
    }

    public function testRiteParsedWhenPresentFromObject(): void
    {
        $data = (object) [
            'nation'       => 'IT',
            'diocese_id'   => 'milano_it',
            'diocese_name' => 'Arcidiocesi di Milano',
            'locales'      => ['it_IT'],
            'timezone'     => 'Europe/Rome',
            'rite'         => 'ambrosian',
        ];
        $meta = DiocesanMetadata::fromObject($data);
        self::assertSame(Rite::AMBROSIAN, $meta->rite);
    }
}
