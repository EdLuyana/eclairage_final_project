<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251115195454 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reservation (id INT AUTO_INCREMENT NOT NULL, product_id INT DEFAULT NULL, size_id INT DEFAULT NULL, location_id INT DEFAULT NULL, requested_by_location_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, quantity INT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', customer_name VARCHAR(255) DEFAULT NULL, customer_phone VARCHAR(50) DEFAULT NULL, expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_42C849554584665A (product_id), INDEX IDX_42C84955498DA827 (size_id), INDEX IDX_42C8495564D218E (location_id), INDEX IDX_42C84955C670305E (requested_by_location_id), INDEX IDX_42C84955B03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE transfer_request (id INT AUTO_INCREMENT NOT NULL, product_id INT DEFAULT NULL, size_id INT DEFAULT NULL, from_location_id INT DEFAULT NULL, to_location_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, quantity INT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', customer_name VARCHAR(255) DEFAULT NULL, customer_phone VARCHAR(50) DEFAULT NULL, INDEX IDX_8422FDD44584665A (product_id), INDEX IDX_8422FDD4498DA827 (size_id), INDEX IDX_8422FDD4980210EB (from_location_id), INDEX IDX_8422FDD428DE1FED (to_location_id), INDEX IDX_8422FDD4B03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C849554584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955498DA827 FOREIGN KEY (size_id) REFERENCES size (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C8495564D218E FOREIGN KEY (location_id) REFERENCES location (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955C670305E FOREIGN KEY (requested_by_location_id) REFERENCES location (id)');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD44584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD4498DA827 FOREIGN KEY (size_id) REFERENCES size (id)');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD4980210EB FOREIGN KEY (from_location_id) REFERENCES location (id)');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD428DE1FED FOREIGN KEY (to_location_id) REFERENCES location (id)');
        $this->addSql('ALTER TABLE transfer_request ADD CONSTRAINT FK_8422FDD4B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C849554584665A');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955498DA827');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C8495564D218E');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955C670305E');
        $this->addSql('ALTER TABLE reservation DROP FOREIGN KEY FK_42C84955B03A8386');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD44584665A');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD4498DA827');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD4980210EB');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD428DE1FED');
        $this->addSql('ALTER TABLE transfer_request DROP FOREIGN KEY FK_8422FDD4B03A8386');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('DROP TABLE transfer_request');
    }
}
