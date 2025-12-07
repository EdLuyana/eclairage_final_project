<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251116115707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product ADD barcode_base VARCHAR(32) DEFAULT NULL, ADD is_archived TINYINT(1) DEFAULT 0 NOT NULL, CHANGE season_id season_id INT DEFAULT NULL, CHANGE category_id category_id INT DEFAULT NULL, CHANGE color_id color_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D34A04ADCC0CA8AB ON product (barcode_base)');
        $this->addSql('ALTER TABLE product RENAME INDEX uniq_product_reference TO UNIQ_D34A04ADAEA34913');
        $this->addSql('ALTER TABLE stock_movement ADD original_price NUMERIC(10, 2) DEFAULT NULL, ADD final_price NUMERIC(10, 2) DEFAULT NULL, ADD discount_percent SMALLINT DEFAULT NULL, ADD discount_label VARCHAR(100) DEFAULT NULL, ADD is_discounted TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stock_movement DROP original_price, DROP final_price, DROP discount_percent, DROP discount_label, DROP is_discounted');
        $this->addSql('DROP INDEX UNIQ_D34A04ADCC0CA8AB ON product');
        $this->addSql('ALTER TABLE product DROP barcode_base, DROP is_archived, CHANGE season_id season_id INT NOT NULL, CHANGE category_id category_id INT NOT NULL, CHANGE color_id color_id INT NOT NULL');
        $this->addSql('ALTER TABLE product RENAME INDEX uniq_d34a04adaea34913 TO UNIQ_PRODUCT_REFERENCE');
    }
}
