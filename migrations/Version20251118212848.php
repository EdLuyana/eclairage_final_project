<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251118212848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE discount ADD starts_at DATETIME DEFAULT NULL, ADD ends_at DATETIME DEFAULT NULL, DROP start_at, DROP end_at, CHANGE name name VARCHAR(255) NOT NULL, CHANGE type type VARCHAR(20) NOT NULL, CHANGE value value NUMERIC(10, 2) NOT NULL, CHANGE is_active is_active TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE discount ADD start_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD end_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', DROP starts_at, DROP ends_at, CHANGE name name VARCHAR(100) NOT NULL, CHANGE type type VARCHAR(10) NOT NULL, CHANGE value value DOUBLE PRECISION NOT NULL, CHANGE is_active is_active TINYINT(1) DEFAULT 1 NOT NULL');
    }
}
