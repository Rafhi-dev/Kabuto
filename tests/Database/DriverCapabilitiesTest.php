<?php

declare(strict_types=1);

namespace Kabuto\Tests\Database;

use Kabuto\Database\DriverCapabilities;
use Kabuto\Tests\TestCase;

final class DriverCapabilitiesTest extends TestCase
{
    public function testDriverAliasesNormalize(): void
    {
        self::assertSame('pgsql', DriverCapabilities::normalize('postgres'));
        self::assertSame('pgsql', DriverCapabilities::normalize('PostgreSQL'));
        self::assertSame('mysql', DriverCapabilities::normalize('mariadb'));
        self::assertSame('sqlite', DriverCapabilities::normalize('SQLite'));
    }

    public function testCapabilityMapCoversAllDrivers(): void
    {
        $matrix = DriverCapabilities::matrix();
        self::assertSame(DriverCapabilities::DRIVERS, array_keys($matrix));

        $referenceKeys = array_keys($matrix['sqlite']);
        foreach ($matrix as $driver => $caps) {
            self::assertSame($referenceKeys, array_keys($caps), "Capability keys differ for [{$driver}].");
        }
    }

    public function testSchemaQualificationDiffersByDriver(): void
    {
        $matrix = DriverCapabilities::matrix();
        self::assertSame('schema_table', $matrix['sqlite']['schema_qualification']);
        self::assertSame('schema.table', $matrix['mysql']['schema_qualification']);
        self::assertSame('schema.table', $matrix['pgsql']['schema_qualification']);
    }

    public function testTransactionAndDdlCapabilities(): void
    {
        $matrix = DriverCapabilities::matrix();

        self::assertTrue($matrix['sqlite']['transactional_ddl']);
        self::assertFalse($matrix['mysql']['transactional_ddl']);
        self::assertTrue($matrix['pgsql']['transactional_ddl']);

        self::assertTrue($matrix['sqlite']['savepoints']);
        self::assertTrue($matrix['mysql']['savepoints']);
        self::assertTrue($matrix['pgsql']['savepoints']);

        self::assertFalse($matrix['mysql']['if_not_exists_index']);
        self::assertTrue($matrix['sqlite']['if_not_exists_index']);
        self::assertTrue($matrix['pgsql']['if_not_exists_index']);
    }

    public function testTypeCapabilities(): void
    {
        $matrix = DriverCapabilities::matrix();

        self::assertSame('TEXT', $matrix['sqlite']['json_type']);
        self::assertSame('JSON', $matrix['mysql']['json_type']);
        self::assertSame('JSONB', $matrix['pgsql']['json_type']);

        self::assertSame('TINYINT(1)', $matrix['mysql']['boolean_type']);
        self::assertSame('BOOLEAN', $matrix['pgsql']['boolean_type']);
        self::assertSame('BOOLEAN', $matrix['sqlite']['boolean_type']);

        self::assertTrue($matrix['pgsql']['returning']);
        self::assertFalse($matrix['mysql']['returning']);
    }

    public function testUnknownDriverThrows(): void
    {
        self::assertThrows(
            \Kabuto\Database\DatabaseException::class,
            static fn () => DriverCapabilities::for('sqlsrv'),
        );
    }
}
