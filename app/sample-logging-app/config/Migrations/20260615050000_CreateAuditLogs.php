<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateAuditLogs extends AbstractMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('audit_logs');
        $table->addColumn('trace_id', 'string', [
            'default' => null,
            'limit' => 50,
            'null' => false,
        ]);
        $table->addColumn('action', 'string', [
            'default' => null,
            'limit' => 100,
            'null' => false,
        ]);
        $table->addColumn('table_name', 'string', [
            'default' => null,
            'limit' => 100,
            'null' => false,
        ]);
        $table->addColumn('entity_id', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => true,
        ]);
        $table->addColumn('user_id', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => true,
        ]);
        $table->addColumn('ip_address', 'string', [
            'default' => null,
            'limit' => 45,
            'null' => true,
        ]);
        $table->addColumn('user_agent', 'string', [
            'default' => null,
            'limit' => 255,
            'null' => true,
        ]);
        $table->addColumn('original_values', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('new_values', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('changed_fields', 'text', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('created', 'datetime', [
            'default' => null,
            'null' => false,
        ]);
        
        // Add indexes for better query performance
        $table->addIndex(['trace_id']);
        $table->addIndex(['action']);
        $table->addIndex(['table_name']);
        $table->addIndex(['entity_id']);
        $table->addIndex(['user_id']);
        $table->addIndex(['created']);
        
        $table->create();
    }
}