<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030100000 extends AbstractMigration
{
    private const TABLE = 'webgriffe_back_in_stock_notification_subscription';

    public function getDescription(): string
    {
        return 'Webgriffe Back in Stock Notification Plugin, add product target and subscription lifecycle (status replaces notify)';
    }

    public function up(Schema $schema): void
    {
        $table = self::TABLE;

        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->addSql("ALTER TABLE $table ADD product_id INT DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD name VARCHAR(255) DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD email_canonical VARCHAR(255) DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD status VARCHAR(16) NOT NULL DEFAULT 'waiting'");
            $this->addSql("ALTER TABLE $table ADD notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD hold_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD held_variant_id INT DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD fulfilled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->addSql("ALTER TABLE $table ADD dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL");
            $this->addBackfill();
            $this->addSql("ALTER TABLE $table ALTER COLUMN status DROP DEFAULT");
            $this->addSql("ALTER TABLE $table ALTER COLUMN product_id SET NOT NULL");
            $this->addSql("ALTER TABLE $table ALTER COLUMN email_canonical SET NOT NULL");
            $this->addSql("ALTER TABLE $table ALTER COLUMN product_variant_id DROP NOT NULL");
            $this->addSql("ALTER TABLE $table DROP notify");
            $this->addSql("COMMENT ON COLUMN $table.notified_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql("COMMENT ON COLUMN $table.hold_until IS '(DC2Type:datetime_immutable)'");
            $this->addSql("COMMENT ON COLUMN $table.fulfilled_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql("COMMENT ON COLUMN $table.cancelled_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql("COMMENT ON COLUMN $table.dismissed_at IS '(DC2Type:datetime_immutable)'");
            $this->addSql("ALTER TABLE $table DROP CONSTRAINT FK_1F046E38A80EF684");
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT FK_1F046E38A80EF684 FOREIGN KEY (product_variant_id) REFERENCES sylius_product_variant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE");
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT FK_1F046E384584665A FOREIGN KEY (product_id) REFERENCES sylius_product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE");
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT FK_1F046E38DEA2B72E FOREIGN KEY (held_variant_id) REFERENCES sylius_product_variant (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE");
            $this->addSql("CREATE INDEX IDX_1F046E384584665A ON $table (product_id)");
            $this->addSql("CREATE INDEX IDX_1F046E38DEA2B72E ON $table (held_variant_id)");
            $this->addSql("CREATE INDEX idx_bisn_product_status_created ON $table (product_id, status, created_at)");
            $this->addSql("CREATE INDEX idx_bisn_email_canonical ON $table (email_canonical)");

            return;
        }

        $this->addSql("ALTER TABLE $table ADD product_id INT DEFAULT NULL, ADD name VARCHAR(255) DEFAULT NULL, ADD email_canonical VARCHAR(255) DEFAULT NULL, ADD status VARCHAR(16) NOT NULL DEFAULT 'waiting', ADD notified_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD hold_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD held_variant_id INT DEFAULT NULL, ADD fulfilled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD cancelled_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD dismissed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addBackfill();
        // The key must go before its column is changed (MySQL error 1832).
        $this->addSql("ALTER TABLE $table DROP FOREIGN KEY FK_1F046E38A80EF684");
        $this->addSql("ALTER TABLE $table CHANGE status status VARCHAR(16) NOT NULL, CHANGE product_id product_id INT NOT NULL, CHANGE email_canonical email_canonical VARCHAR(255) NOT NULL, CHANGE product_variant_id product_variant_id INT DEFAULT NULL, DROP notify");
        $this->addSql("ALTER TABLE $table ADD CONSTRAINT FK_1F046E38A80EF684 FOREIGN KEY (product_variant_id) REFERENCES sylius_product_variant (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE $table ADD CONSTRAINT FK_1F046E384584665A FOREIGN KEY (product_id) REFERENCES sylius_product (id) ON DELETE CASCADE");
        $this->addSql("ALTER TABLE $table ADD CONSTRAINT FK_1F046E38DEA2B72E FOREIGN KEY (held_variant_id) REFERENCES sylius_product_variant (id) ON DELETE SET NULL");
        $this->addSql("CREATE INDEX IDX_1F046E384584665A ON $table (product_id)");
        $this->addSql("CREATE INDEX IDX_1F046E38DEA2B72E ON $table (held_variant_id)");
        $this->addSql("CREATE INDEX idx_bisn_product_status_created ON $table (product_id, status, created_at)");
        $this->addSql("CREATE INDEX idx_bisn_email_canonical ON $table (email_canonical)");
    }

    public function down(Schema $schema): void
    {
        $table = self::TABLE;

        // Subscriptions without a variant (product target) cannot be represented by the old schema.
        $this->addSql("DELETE FROM $table WHERE product_variant_id IS NULL");

        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $this->addSql("ALTER TABLE $table ADD notify BOOLEAN NOT NULL DEFAULT false");
            $this->addSql("UPDATE $table SET notify = (status IN ('notified', 'fulfilled'))");
            $this->addSql("ALTER TABLE $table ALTER COLUMN notify DROP DEFAULT");
            $this->addSql("DROP INDEX idx_bisn_product_status_created");
            $this->addSql("DROP INDEX idx_bisn_email_canonical");
            $this->addSql("ALTER TABLE $table DROP CONSTRAINT FK_1F046E384584665A");
            $this->addSql("ALTER TABLE $table DROP CONSTRAINT FK_1F046E38DEA2B72E");
            $this->addSql("ALTER TABLE $table DROP CONSTRAINT FK_1F046E38A80EF684");
            $this->addSql("DROP INDEX IDX_1F046E384584665A");
            $this->addSql("DROP INDEX IDX_1F046E38DEA2B72E");
            $this->addSql("ALTER TABLE $table DROP product_id, DROP name, DROP email_canonical, DROP status, DROP notified_at, DROP hold_until, DROP held_variant_id, DROP fulfilled_at, DROP cancelled_at, DROP dismissed_at");
            $this->addSql("ALTER TABLE $table ALTER COLUMN product_variant_id SET NOT NULL");
            $this->addSql("ALTER TABLE $table ADD CONSTRAINT FK_1F046E38A80EF684 FOREIGN KEY (product_variant_id) REFERENCES sylius_product_variant (id) NOT DEFERRABLE INITIALLY IMMEDIATE");

            return;
        }

        $this->addSql("ALTER TABLE $table ADD notify TINYINT(1) NOT NULL");
        $this->addSql("UPDATE $table SET notify = (status IN ('notified', 'fulfilled'))");
        $this->addSql("DROP INDEX idx_bisn_product_status_created ON $table");
        $this->addSql("DROP INDEX idx_bisn_email_canonical ON $table");
        $this->addSql("ALTER TABLE $table DROP FOREIGN KEY FK_1F046E384584665A");
        $this->addSql("ALTER TABLE $table DROP FOREIGN KEY FK_1F046E38DEA2B72E");
        $this->addSql("ALTER TABLE $table DROP FOREIGN KEY FK_1F046E38A80EF684");
        $this->addSql("DROP INDEX IDX_1F046E384584665A ON $table");
        $this->addSql("DROP INDEX IDX_1F046E38DEA2B72E ON $table");
        $this->addSql("ALTER TABLE $table DROP product_id, DROP name, DROP email_canonical, DROP status, DROP notified_at, DROP hold_until, DROP held_variant_id, DROP fulfilled_at, DROP cancelled_at, DROP dismissed_at, CHANGE product_variant_id product_variant_id INT NOT NULL");
        $this->addSql("ALTER TABLE $table ADD CONSTRAINT FK_1F046E38A80EF684 FOREIGN KEY (product_variant_id) REFERENCES sylius_product_variant (id)");
    }

    private function addBackfill(): void
    {
        $table = self::TABLE;
        $this->addSql("UPDATE $table SET product_id = (SELECT v.product_id FROM sylius_product_variant v WHERE v.id = $table.product_variant_id)");
        $this->addSql("UPDATE $table SET email_canonical = LOWER(TRIM(email))");
        $this->addSql("UPDATE $table SET status = CASE WHEN notify THEN 'notified' ELSE 'waiting' END");
    }
}
