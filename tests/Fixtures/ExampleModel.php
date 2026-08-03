<?php

namespace Bhamner\SchemaFox\Tests\Fixtures;

use Bhamner\SchemaFox\SchemaFox;
use Illuminate\Database\Eloquent\Model;

class ExampleModel extends Model
{
	use SchemaFox;

	protected $table = 'users';

	protected $hidden = [
		'remember_token',
	];

	public string $schemaFoxDisplayColumn = 'name';
}
