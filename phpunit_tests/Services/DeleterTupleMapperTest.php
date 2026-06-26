<?php

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\DeleterTupleMapper;
use PHPUnit\Framework\TestCase;

class DeleterTupleMapperTest extends TestCase
{
    public function testMapsDeleterToAdmin(): void
    {
        $mapped = ( new DeleterTupleMapper() )->mapTuple(
            ['user' => 'user:x', 'relation' => 'deleter', 'object' => 'national_calendar:IT']
        );
        $this->assertSame(['user' => 'user:x', 'relation' => 'admin', 'object' => 'national_calendar:IT'], $mapped);
    }

    public function testIgnoresNonDeleterTuples(): void
    {
        $this->assertNull(
            ( new DeleterTupleMapper() )->mapTuple(['user' => 'user:x', 'relation' => 'editor', 'object' => 'national_calendar:IT'])
        );
    }

    public function testIgnoresViewerTuples(): void
    {
        $this->assertNull(
            ( new DeleterTupleMapper() )->mapTuple(['user' => 'user:y', 'relation' => 'viewer', 'object' => 'diocesan_calendar:IT#ROME'])
        );
    }

    public function testIgnoresAdminTuples(): void
    {
        $this->assertNull(
            ( new DeleterTupleMapper() )->mapTuple(['user' => 'user:z', 'relation' => 'admin', 'object' => 'national_calendar:DE'])
        );
    }
}
