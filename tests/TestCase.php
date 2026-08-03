<?php

namespace Bhamner\SchemaFox\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		\Bhamner\SchemaFox\Tests\Fixtures\ExampleModel::clearSchemaCache();
	}

	protected function callPrivate(object $object, string $method, array $args = [])
	{
		$reflection = new \ReflectionMethod($object, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs($object, $args);
	}

	protected function makeColumn(array $attributes = []): object
	{
		return (object) array_merge([
			'name' => 'title',
			'max_length' => 255,
			'data_type' => 'varchar',
			'comment' => null,
			'num_precision' => null,
			'default_value' => null,
			'column_info' => 'varchar(255)',
			'extra' => '',
			'ref_table_schema' => null,
			'ref_table_name' => null,
			'ref_column_name' => null,
			'constraint_name' => null,
			'unique_constraint' => null,
		], $attributes);
	}
}
