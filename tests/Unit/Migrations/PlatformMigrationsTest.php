<?php

declare(strict_types=1);

namespace Tests\Webgriffe\SyliusBackInStockNotificationPlugin\Unit\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Webgriffe\SyliusBackInStockNotificationPlugin\Migrations\Version20210419151725;
use Webgriffe\SyliusBackInStockNotificationPlugin\Migrations\Version20220523152238;

final class PlatformMigrationsTest extends TestCase
{
    public function testInitialMigrationEmitsPostgresqlSqlOnPostgres(): void
    {
        $sql = $this->upSql(Version20210419151725::class, new PostgreSQLPlatform());

        self::assertStringContainsString('CREATE TABLE webgriffe_back_in_stock_notification_subscription (id SERIAL', $sql);
        self::assertStringNotContainsString('ENGINE = InnoDB', $sql);
        self::assertStringNotContainsString('TINYINT', $sql);
        self::assertStringNotContainsString('AUTO_INCREMENT', $sql);
        self::assertStringNotContainsString('DATETIME', $sql);
        self::assertStringContainsString('TIMESTAMP(0) WITHOUT TIME ZONE', $sql);
        self::assertStringContainsString('CREATE UNIQUE INDEX UNIQ_1F046E38D1B862B8', $sql);
        self::assertStringContainsString('CREATE INDEX IDX_1F046E389395C3F3', $sql);
        self::assertStringContainsString('ADD CONSTRAINT FK_1F046E3872F5A1AA FOREIGN KEY', $sql);
    }

    public function testNotifyMigrationEmitsBooleanOnPostgres(): void
    {
        $sql = $this->upSql(Version20220523152238::class, new PostgreSQLPlatform());

        self::assertStringContainsString('ADD notify BOOLEAN NOT NULL DEFAULT false', $sql);
        self::assertStringNotContainsString('TINYINT', $sql);
    }

    public function testInitialMigrationKeepsMysqlSqlOnMysql(): void
    {
        $sql = $this->upSql(Version20210419151725::class, new MySQL80Platform());

        self::assertStringContainsString('CREATE TABLE webgriffe_back_in_stock_notification_subscription (id INT AUTO_INCREMENT NOT NULL', $sql);
        self::assertStringContainsString('ENGINE = InnoDB', $sql);
        self::assertStringNotContainsString('SERIAL', $sql);
    }

    public function testNotifyMigrationKeepsMysqlSqlOnMysql(): void
    {
        $sql = $this->upSql(Version20220523152238::class, new MySQL80Platform());

        self::assertStringContainsString('ADD notify TINYINT(1) NOT NULL', $sql);
        self::assertStringNotContainsString('BOOLEAN', $sql);
    }

    public function testDownMigrationsAreAvailableOnBothPlatforms(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQL80Platform()] as $platform) {
            $initial = $this->migration(Version20210419151725::class, $platform);
            $initial->down(new Schema());
            self::assertStringContainsString('DROP TABLE webgriffe_back_in_stock_notification_subscription', $this->statements($initial));

            $notify = $this->migration(Version20220523152238::class, $platform);
            $notify->down(new Schema());
            self::assertStringContainsString('DROP notify', $this->statements($notify));
        }
    }

    /**
     * @param class-string<AbstractMigration> $class
     */
    private function upSql(string $class, AbstractPlatform $platform): string
    {
        $migration = $this->migration($class, $platform);
        $migration->up(new Schema());

        return $this->statements($migration);
    }

    /**
     * @param class-string<AbstractMigration> $class
     */
    private function migration(string $class, AbstractPlatform $platform): AbstractMigration
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        return new $class($connection, new NullLogger());
    }

    private function statements(AbstractMigration $migration): string
    {
        return implode(";\n", array_map(
            static fn ($query): string => $query->getStatement(),
            $migration->getSql(),
        ));
    }
}
