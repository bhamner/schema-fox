<?php

namespace Bhamner\SchemaFox;

use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

trait SchemaFox
{
	/** @var array<string, mixed> */
	public $values = [];

	/** @var array<string, array> */
	private static $schemaCache = [];

	/** Max related rows loaded into FK select options. */
	private static $relativeLimit = 500;

	/**
	 * Clear cached information_schema results (useful in tests).
	 */
	public static function clearSchemaCache(): void
	{
		self::$schemaCache = [];
	}

	/**
	 * Read column metadata for a table from information_schema (MySQL/MariaDB).
	 *
	 * @param  string  $table
	 * @return array<int, object>
	 */
	public function getSchema($table)
	{
		$table = $this->assertSafeIdentifier($table, 'table');

		$connection = Config::get('database.default');
		$database = (string) Config::get('database.connections.'.$connection.'.database');
		if ($database === '') {
			throw new InvalidArgumentException('Invalid database identifier.');
		}

		$cacheKey = $connection.'.'.$database.'.'.$table;
		if (isset(self::$schemaCache[$cacheKey])) {
			return self::$schemaCache[$cacheKey];
		}

		$schema = DB::select(
			'SELECT
				column_schema.column_name as name,
				column_schema.character_maximum_length as max_length,
				column_schema.data_type as data_type,
				column_schema.column_comment as comment,
				column_schema.numeric_precision as num_precision,
				column_schema.column_default as default_value,
				column_schema.COLUMN_TYPE as column_info,
				column_schema.extra as extra,
				key_usage.referenced_table_schema as ref_table_schema,
				key_usage.referenced_table_name as ref_table_name,
				key_usage.referenced_column_name as ref_column_name,
				key_usage.constraint_name as constraint_name,
				key_usage.position_in_unique_constraint as unique_constraint
			FROM information_schema.columns as column_schema
			LEFT JOIN information_schema.key_column_usage key_usage
				ON key_usage.column_name = column_schema.column_name
				AND key_usage.table_name = column_schema.table_name
				AND key_usage.table_schema = column_schema.table_schema
			WHERE column_schema.table_name = ?
				AND column_schema.table_schema = ?',
			[$table, $database]
		);

		self::$schemaCache[$cacheKey] = $schema;

		return $schema;
	}

	/**
	 * @return array<int, object>
	 */
	public static function getMap()
	{
		$self = new static;
		$schema = $self->getSchema($self->getTable());

		return $schema ?: [];
	}

	/**
	 * @param  array<string, mixed>|null  $values
	 * @return array<int, object>
	 */
	public static function getInputs($values = null)
	{
		if (is_null($values)) {
			$values = Request::old();
		}

		$self = new static;
		$self->values = is_array($values) ? $values : [];
		$schema = $self->getSchema($self->getTable());

		$mapped = [];
		foreach ($schema as $column) {
			if (! is_null($self->removeHidden($column))) {
				$mapped[] = $self->mapColumn($column);
			}
		}

		return array_values(array_filter($mapped));
	}

	/**
	 * Build an HTML form string for the model's table schema.
	 *
	 * Output is escaped for HTML embedding. Prefer `{!! Model::buildForm(...) !!}`
	 * only because the library itself escapes; do not pass untrusted markup as `$url`.
	 *
	 * @param  string  $url
	 * @param  array<string, mixed>|null  $values
	 * @param  string  $method
	 * @param  bool  $files
	 * @return string
	 */
	public static function buildForm($url = '', $values = null, $method = 'post', $files = false)
	{
		$self = new static;
		$builder = [];

		$method = strtolower((string) $method);
		$allowed = ['get', 'post', 'put', 'patch', 'delete'];
		if (! in_array($method, $allowed, true)) {
			$method = 'post';
		}

		$formMethod = in_array($method, ['get', 'post'], true) ? $method : 'post';
		$needsMultipart = $files !== false && $formMethod === 'post';

		$formopen = '<form method="'.$self->e($formMethod).'" action="'.$self->e($url).'"';
		if ($needsMultipart) {
			$formopen .= ' enctype="multipart/form-data"';
		}
		$formopen .= '>';
		$formopen .= '<input type="hidden" name="_token" value="'.$self->e(csrf_token()).'">';
		if (! in_array($method, ['get', 'post'], true)) {
			$formopen .= '<input type="hidden" name="_method" value="'.$self->e(strtoupper($method)).'">';
		}

		$builder[] = $formopen;

		$formInputs = self::getInputs($values);
		$errors = Session::get('errors', new MessageBag);

		foreach ($formInputs as $formgroup) {
			$builder[] = '<br/>';
			$builder[] = $formgroup->input;
			if ($formgroup->comment) {
				$builder[] = '<br/><span><small>'.$self->e($formgroup->comment).'</small></span>';
			}
			if ($errors->has($formgroup->name)) {
				$message = $errors->first($formgroup->name);
				$builder[] = '<br/><span style="color:red" role="alert">'.$self->e($message).'</span>';
			}
		}

		$builder[] = '<br/><button type="submit" value="Submit">Submit</button>';
		$builder[] = '</form>';

		return implode('', $builder);
	}

	private function mapColumn($column)
	{
		$method = 'map_'.$column->data_type;
		if (method_exists($this, $method)) {
			return $this->{$method}($column);
		}

		return $this->map_unknown($column);
	}

	private function map_unknown($column)
	{
		$column->input = $this->createInputField($column, 'text');

		return $column;
	}

	private function removeHidden($column)
	{
		$hidden = property_exists($this, 'hidden') && is_array($this->hidden)
			? $this->hidden
			: [];

		if (in_array($column->name, $hidden, true)) {
			return null;
		}

		return $column;
	}

	private function removeLaravelTimestamps($column)
	{
		if (in_array($column->name, ['created_at', 'updated_at', 'deleted_at'], true)) {
			return null;
		}

		return $column;
	}

	/**
	 * @return mixed
	 */
	private function getColumnValues($key)
	{
		return array_key_exists($key, $this->values) ? $this->values[$key] : null;
	}

	private function getLabelName($str, $separator = '_')
	{
		$str = preg_replace('/_id$/i', '', (string) $str);
		$quoted = preg_quote($separator, '/');

		return ucwords(preg_replace('/['.$quoted.']+/', ' ', trim(strtolower((string) $str))));
	}

	/**
	 * Load FK option map: id => display label.
	 *
	 * @return array<string|int, string>
	 */
	private function getRelatives($column)
	{
		$connection = Config::get('database.default');
		$prefix = (string) Config::get('database.connections.'.$connection.'.prefix', '');
		$foreignTable = $prefix !== ''
			? str_replace($prefix, '', (string) $column->ref_table_name)
			: (string) $column->ref_table_name;

		$foreignTable = $this->assertSafeIdentifier($foreignTable, 'foreign table');
		$refColumn = $this->assertSafeIdentifier((string) $column->ref_column_name, 'foreign column');

		$displayColumn = 'name';
		if (property_exists($this, 'schemaFoxDisplayColumn') && is_string($this->schemaFoxDisplayColumn) && $this->schemaFoxDisplayColumn !== '') {
			$displayColumn = $this->schemaFoxDisplayColumn;
		}
		$displayColumn = $this->assertSafeIdentifier($displayColumn, 'display column');

		if (! Schema::hasColumn($foreignTable, $displayColumn)) {
			$displayColumn = $refColumn;
		}

		$query = DB::table($foreignTable)->select([$refColumn, $displayColumn]);

		if (Schema::hasColumn($foreignTable, 'deleted_at')) {
			$query->whereNull('deleted_at');
		}

		return $query
			->orderBy($displayColumn)
			->limit(self::$relativeLimit)
			->pluck($displayColumn, $refColumn)
			->all();
	}

	private function createTextArea($column, $rows = 5)
	{
		$name = $this->e($column->name);
		$label = $this->e($this->getLabelName($column->name));
		$value = $this->e((string) ($this->getColumnValues($column->name) ?? ''));
		$max = $column->max_length ? ' maxlength="'.$this->e((string) $column->max_length).'"' : '';

		return '<label for="'.$name.'">'.$label.'</label><br/>'
			.'<textarea id="'.$name.'" name="'.$name.'" rows="'.(int) $rows.'"'.$max.'>'.$value.'</textarea>';
	}

	private function createInputField($column, $type)
	{
		$name = $this->e($column->name);
		$label = $this->e($this->getLabelName($column->name));
		$type = $this->e($type);
		$max = $column->max_length ? ' maxlength="'.$this->e((string) $column->max_length).'"' : '';

		$valueAttr = '';
		if (! in_array($type, ['password', 'file'], true)) {
			$value = $this->getColumnValues($column->name);
			if ($value !== null) {
				$valueAttr = ' value="'.$this->e((string) $value).'"';
			}
		}

		return '<label for="'.$name.'">'.$label.'</label><br/>'
			.'<input type="'.$type.'" id="'.$name.'" name="'.$name.'"'.$max.$valueAttr.'>';
	}

	private function createDateField($column, $type)
	{
		return $this->createInputField($column, $type);
	}

	private function createHiddenInput($column)
	{
		$name = $this->e($column->name);
		$value = $this->getColumnValues($column->name);
		$valueAttr = $value !== null ? ' value="'.$this->e((string) $value).'"' : '';

		return '<input type="hidden" id="'.$name.'" name="'.$name.'"'.$valueAttr.'>';
	}

	private function createNumberRange($column, $min, $max)
	{
		$name = $this->e($column->name);
		$label = $this->e($this->getLabelName($column->name));
		$value = $this->getColumnValues($column->name);
		$valueAttr = $value !== null && $value !== '' ? ' value="'.$this->e((string) $value).'"' : '';

		return '<label for="'.$name.'">'.$label.'</label><br/>'
			.'<input type="number" id="'.$name.'" name="'.$name.'" min="'.$this->e((string) $min).'" max="'.$this->e((string) $max).'"'.$valueAttr.'>';
	}

	private function createCheckBoxes($column)
	{
		$name = $this->e($column->name);
		$label = $this->e($this->getLabelName($column->name));
		$current = $this->getColumnValues($column->name);
		$checked = ($current !== null && (int) $current > 0) ? ' checked' : '';

		return '<label for="'.$name.'">'.$label.'</label><br/>'
			.'<input type="checkbox" id="'.$name.'" name="'.$name.'" value="1"'.$checked.'>';
	}

	/**
	 * @param  array<int|string, mixed>  $options  list of values, or id => label map
	 */
	private function createSelectBox($column, $options)
	{
		$name = $this->e($column->name);
		$label = $this->e($this->getLabelName($column->name));
		$current = $this->getColumnValues($column->name);
		$isList = array_is_list($options);

		$select = '<label for="'.$name.'">'.$label.'</label><br/>'
			.'<select id="'.$name.'" name="'.$name.'">';

		foreach ($options as $key => $optionLabel) {
			$value = $isList ? $optionLabel : $key;
			$selected = ((string) $current === (string) $value) ? ' selected' : '';
			$select .= '<option value="'.$this->e((string) $value).'"'.$selected.'>'
				.$this->e(ucwords((string) $optionLabel))
				.'</option>';
		}

		$select .= '</select>';

		return $select;
	}

	/**
	 * @param  array<int, string>  $options
	 */
	private function createSetCheckboxes($column, $options)
	{
		$name = $this->e($column->name);
		$label = $this->e($this->getLabelName($column->name));
		$current = $this->getColumnValues($column->name);

		if (is_array($current)) {
			$selected = array_map('strval', $current);
		} else {
			$selected = $current === null || $current === ''
				? []
				: array_map('trim', explode(',', (string) $current));
		}

		$html = '<fieldset><legend>'.$label.'</legend>';
		foreach ($options as $index => $option) {
			$option = (string) $option;
			$id = $name.'_'.$index;
			$checked = in_array($option, $selected, true) ? ' checked' : '';
			$html .= '<label for="'.$id.'">'
				.'<input type="checkbox" id="'.$id.'" name="'.$name.'[]" value="'.$this->e($option).'"'.$checked.'> '
				.$this->e($option)
				.'</label><br/>';
		}
		$html .= '</fieldset>';

		return $html;
	}

	private function e($value): string
	{
		return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function assertSafeIdentifier(string $identifier, string $label): string
	{
		if ($identifier === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
			throw new InvalidArgumentException('Invalid '.$label.' identifier.');
		}

		return $identifier;
	}

	private function mapIntegerLike($column, string $min, string $max)
	{
		$column->input = $this->createNumberRange($column, $min, $max);

		if ($column->constraint_name == 'PRIMARY') {
			$column->input = $this->createHiddenInput($column);
		}

		if (! is_null($column->ref_table_name)) {
			$options = $this->getRelatives($column);
			$column->input = $this->createSelectBox($column, $options);
		}

		return $column;
	}

	private function map_int($column)
	{
		return $this->mapIntegerLike($column, '-2147483648', '2147483647');
	}

	private function map_integer($column)
	{
		return $this->map_int($column);
	}

	private function map_tinyint($column)
	{
		$column->input = $this->createNumberRange($column, '-128', '127');
		if ($column->column_info == 'tinyint(1)') {
			$column->input = $this->createCheckBoxes($column);
		}

		return $column;
	}

	private function map_smallint($column)
	{
		$column->input = $this->createNumberRange($column, '-32768', '32767');

		return $column;
	}

	private function map_mediumint($column)
	{
		$column->input = $this->createNumberRange($column, '-8388608', '8388607');

		return $column;
	}

	private function map_bigint($column)
	{
		$column->input = $this->createNumberRange($column, '-9223372036854775808', '9223372036854775807');

		return $column;
	}

	private function map_bit($column)
	{
		$column->input = $this->createNumberRange($column, '0', '64');

		return $column;
	}

	private function map_real($column)
	{
		$max = pow(10, (int) $column->num_precision) - 1;
		$column->input = $this->createNumberRange($column, (string) (-$max), (string) $max);

		return $column;
	}

	private function map_double($column)
	{
		return $this->map_real($column);
	}

	private function map_float($column)
	{
		return $this->map_real($column);
	}

	private function map_decimal($column)
	{
		return $this->map_real($column);
	}

	private function map_text($column)
	{
		if (Str::is('*password*', $column->name)) {
			$column->input = $this->createInputField($column, 'password');
		} elseif (Str::is('*email*', $column->name)) {
			$column->input = $this->createInputField($column, 'email');
		} elseif (Str::is('*phone*', $column->name)) {
			$column->input = $this->createInputField($column, 'text');
		} elseif (Str::is('*url*', $column->name)) {
			$column->input = $this->createInputField($column, 'url');
		} elseif (Str::is('*image*', $column->name) || Str::is('*logo*', $column->name) || Str::is('*photo*', $column->name)) {
			$column->input = $this->createInputField($column, 'file');
		} else {
			$column->input = $this->createInputField($column, 'text');
		}

		if ($column->max_length > 300 && ! Str::is('*password*', $column->name)) {
			$column->input = $this->createTextArea($column, 5);
		}

		return $column;
	}

	private function map_varchar($column)
	{
		return $this->map_text($column);
	}

	private function map_char($column)
	{
		return $this->map_text($column);
	}

	private function map_tinytext($column)
	{
		$column->input = $this->createInputField($column, 'text');

		return $column;
	}

	private function map_mediumtext($column)
	{
		$column->input = $this->createTextArea($column, 10);

		return $column;
	}

	private function map_longtext($column)
	{
		$column->input = $this->createTextArea($column, 15);

		return $column;
	}

	private function map_json($column)
	{
		$column->input = $this->createTextArea($column, 8);

		return $column;
	}

	private function map_time($column)
	{
		$column->input = $this->createDateField($column, 'time');

		return $column;
	}

	private function map_datetime($column)
	{
		$column->input = $this->createDateField($column, 'datetime-local');

		return $this->removeLaravelTimestamps($column);
	}

	private function map_timestamp($column)
	{
		$column->input = $this->createDateField($column, 'datetime-local');

		return $this->removeLaravelTimestamps($column);
	}

	private function map_year($column)
	{
		$column->input = $this->createDateField($column, 'date');

		return $this->removeLaravelTimestamps($column);
	}

	private function map_date($column)
	{
		$column->input = $this->createDateField($column, 'date');

		return $this->removeLaravelTimestamps($column);
	}

	private function map_binary($column)
	{
		$column->input = $this->createInputField($column, 'file');

		return $column;
	}

	private function map_varbinary($column)
	{
		return $this->map_binary($column);
	}

	private function map_blob($column)
	{
		return $this->map_binary($column);
	}

	private function map_tinyblob($column)
	{
		return $this->map_binary($column);
	}

	private function map_mediumblob($column)
	{
		return $this->map_binary($column);
	}

	private function map_longblob($column)
	{
		return $this->map_binary($column);
	}

	private function map_enum($column)
	{
		$enumValues = str_replace(["(", ")", "'"], '', substr($column->column_info, 4));
		$options = array_values(array_filter(array_map('trim', explode(',', $enumValues)), 'strlen'));
		$column->input = $this->createSelectBox($column, $options);

		return $column;
	}

	private function map_set($column)
	{
		$setValues = str_replace(["(", ")", "'"], '', substr($column->column_info, 4));
		$options = array_values(array_filter(array_map('trim', explode(',', $setValues)), 'strlen'));
		$column->input = $this->createSetCheckboxes($column, $options);

		return $column;
	}

	private function map_point($column)
	{
		$column->input = $this->createInputField($column, 'text');

		return $column;
	}

	private function map_linestring($column)
	{
		return $this->map_point($column);
	}

	private function map_polygon($column)
	{
		return $this->map_point($column);
	}

	private function map_geometry($column)
	{
		return $this->map_point($column);
	}

	private function map_multipoint($column)
	{
		return $this->map_point($column);
	}

	private function map_multilinestring($column)
	{
		return $this->map_point($column);
	}

	private function map_multipolygon($column)
	{
		return $this->map_point($column);
	}

	private function map_geometrycollection($column)
	{
		return $this->map_point($column);
	}
}
