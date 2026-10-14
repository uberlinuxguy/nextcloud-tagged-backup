<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261013000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('tagged_backup_jobs')) {
			return null;
		}
		$table = $schema->createTable('tagged_backup_jobs');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
		$table->addColumn('tag_ids', Types::TEXT, ['notnull' => true]);
		$table->addColumn('scope_user', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('compression', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'gzip']);
		$table->addColumn('age_recipients', Types::TEXT, ['notnull' => false]);
		$table->addColumn('destination_type', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('destination_config', Types::TEXT, ['notnull' => false]);
		$table->addColumn('interval_hours', Types::INTEGER, ['notnull' => true, 'default' => 24]);
		$table->addColumn('retention_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('last_run_at', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('last_status', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('last_message', Types::TEXT, ['notnull' => false]);
		$table->addColumn('last_size', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('last_file_count', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('running_since', Types::BIGINT, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		return $schema;
	}
}
