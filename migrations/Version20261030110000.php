<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Webgriffe Back in Stock Notification Plugin, add pre-orderability to products and taxons and the pre-order settings to channels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sylius_product ADD preorderability VARCHAR(16) DEFAULT 'inherit' NOT NULL");
        $this->addSql("ALTER TABLE sylius_taxon ADD preorderability VARCHAR(16) DEFAULT 'inherit' NOT NULL");

        $boolean = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'BOOLEAN DEFAULT true NOT NULL'
            : 'TINYINT(1) DEFAULT 1 NOT NULL';
        $this->addSql(
            "ALTER TABLE sylius_channel ADD preorder_enabled_by_default $boolean, " .
            "ADD preorder_mode VARCHAR(16) DEFAULT 'queue' NOT NULL, " .
            'ADD preorder_hold_hours INT DEFAULT 24 NOT NULL, ' .
            'ADD preorder_rate_limit_seconds INT DEFAULT 120 NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_product DROP preorderability');
        $this->addSql('ALTER TABLE sylius_taxon DROP preorderability');
        $this->addSql('ALTER TABLE sylius_channel DROP preorder_enabled_by_default, DROP preorder_mode, DROP preorder_hold_hours, DROP preorder_rate_limit_seconds');
    }
}
