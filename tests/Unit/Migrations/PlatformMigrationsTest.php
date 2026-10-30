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
use Webgriffe\SyliusBackInStockNotificationPlugin\Migrations\Version20261030100000;

final class PlatformMigrationsTest extends TestCase
{
    public function testInitialMigrationEmitsPostgresqlSqlOnPostgres(): void
    {
        $sql = $this->upSql(Version20210419151725::class, new PostgreSQLPlatform());

        self::assertStringContainsString('CREATE TABLE webgriffe_back_in_stock_notification_subscription (id INT NOT NULL', $sql);
        self::assertStringContainsString('CREATE SEQUENCE webgriffe_back_in_stock_notification_subscription_id_seq INCREMENT BY 1 MINVALUE 1 START 1', $sql);
        self::assertStringNotContainsString('SERIAL', $sql);
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
        self::assertStringContainsString(
            'ALTER TABLE webgriffe_back_in_stock_notification_subscription ALTER COLUMN notify DROP DEFAULT',
            $sql,
        );
        self::assertGreaterThan(
            strpos($sql, 'ADD notify BOOLEAN'),
            strpos($sql, 'ALTER COLUMN notify DROP DEFAULT'),
        );
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

    public function testLifecycleMigrationEmitsPostgresqlSqlOnPostgres(): void
    {
        $sql = $this->upSql(Version20261030100000::class, new PostgreSQLPlatform());

        self::assertStringContainsString('ADD notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL', $sql);
        self::assertStringContainsString('ALTER COLUMN product_id SET NOT NULL', $sql);
        self::assertStringContainsString('ALTER COLUMN product_variant_id DROP NOT NULL', $sql);
        self::assertStringContainsString('FK_1F046E384584665A FOREIGN KEY (product_id) REFERENCES sylius_product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE', $sql);
        self::assertStringContainsString('FK_1F046E38DEA2B72E FOREIGN KEY (held_variant_id) REFERENCES sylius_product_variant (id) ON DELETE SET NULL', $sql);
        self::assertStringContainsString('CREATE INDEX idx_bisn_product_status_created', $sql);
        self::assertStringContainsString('DROP notify', $sql);
        self::assertStringNotContainsString('DATETIME', $sql);
        self::assertStringNotContainsString('TINYINT', $sql);
        self::assertStringNotContainsString('CHANGE ', $sql);
    }

    public function testLifecycleMigrationBackfillsBeforeConstraints(): void
    {
        $sql = $this->upSql(Version20261030100000::class, new PostgreSQLPlatform());

        self::assertStringContainsString("SET status = CASE WHEN notify THEN 'notified' ELSE 'waiting' END", $sql);
        self::assertStringContainsString('SET email_canonical = LOWER(TRIM(email))', $sql);
        self::assertLessThan(strpos($sql, 'ALTER COLUMN product_id SET NOT NULL'), strpos($sql, 'SET product_id = (SELECT'));
        self::assertLessThan(strpos($sql, 'DROP notify'), strpos($sql, 'SET status = CASE'));
    }

    public function testLifecycleMigrationKeepsMysqlSqlOnMysql(): void
    {
        $sql = $this->upSql(Version20261030100000::class, new MySQL80Platform());

        self::assertStringContainsString('ADD notified_at DATETIME DEFAULT NULL', $sql);
        self::assertStringContainsString('DROP notify', $sql);
        self::assertStringNotContainsString('TIMESTAMP', $sql);
        self::assertStringNotContainsString('SET NOT NULL', $sql);
        // MySQL error 1832: a column used by a foreign key cannot be changed while the key exists.
        $dropFk = strpos($sql, 'DROP FOREIGN KEY FK_1F046E38A80EF684');
        self::assertNotFalse($dropFk);
        self::assertLessThan(strpos($sql, 'CHANGE product_variant_id'), $dropFk);
        self::assertGreaterThan(
            strpos($sql, 'CHANGE product_variant_id'),
            strpos($sql, 'ADD CONSTRAINT FK_1F046E38A80EF684'),
        );
    }

    public function testDownMigrationsAreAvailableOnBothPlatforms(): void
    {
        foreach ([new PostgreSQLPlatform(), new MySQL80Platform()] as $platform) {
            $initial = $this->migration(Version20210419151725::class, $platform);
            $initial->down(new Schema());
            self::assertStringContainsString('DROP TABLE webgriffe_back_in_stock_notification_subscription', $this->statements($initial));
            self::assertSame(
                $platform instanceof PostgreSQLPlatform,
                str_contains($this->statements($initial), 'DROP SEQUENCE webgriffe_back_in_stock_notification_subscription_id_seq'),
            );

            $notify = $this->migration(Version20220523152238::class, $platform);
            $notify->down(new Schema());
            self::assertStringContainsString('DROP notify', $this->statements($notify));

            $lifecycle = $this->migration(Version20261030100000::class, $platform);
            $lifecycle->down(new Schema());
            self::assertStringContainsString('ADD notify', $this->statements($lifecycle));
            self::assertStringContainsString('DROP product_id', $this->statements($lifecycle));
            $down = $this->statements($lifecycle);
            self::assertLessThan(
                strpos($down, $platform instanceof PostgreSQLPlatform ? 'ALTER COLUMN product_variant_id SET NOT NULL' : 'CHANGE product_variant_id'),
                strpos($down, 'FK_1F046E38A80EF684'),
            );
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
