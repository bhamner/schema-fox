<?php

namespace Bhamner\SchemaFox\Tests;

use Bhamner\SchemaFox\Tests\Fixtures\ExampleModel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\MessageBag;
use InvalidArgumentException;
use Mockery;

class SchemaFoxTest extends TestCase
{
	public function test_input_field_binds_value_attribute(): void
	{
		$model = new ExampleModel;
		$model->values = ['title' => 'Hello <world>'];
		$column = $this->makeColumn(['name' => 'title']);

		$html = $this->callPrivate($model, 'createInputField', [$column, 'text']);

		$this->assertStringContainsString('value="Hello &lt;world&gt;"', $html);
		$this->assertStringNotContainsString('</input>', $html);
		$this->assertStringContainsString('type="text" id="title"', $html);
	}

	public function test_password_field_never_prefills_value(): void
	{
		$model = new ExampleModel;
		$model->values = ['password' => 'secret'];
		$column = $this->makeColumn(['name' => 'password']);

		$html = $this->callPrivate($model, 'createInputField', [$column, 'password']);

		$this->assertStringContainsString('type="password"', $html);
		$this->assertStringNotContainsString('secret', $html);
		$this->assertStringNotContainsString('value=', $html);
	}

	public function test_checkbox_checked_state_and_html(): void
	{
		$model = new ExampleModel;
		$model->values = ['active' => 1];
		$column = $this->makeColumn(['name' => 'active']);

		$html = $this->callPrivate($model, 'createCheckBoxes', [$column]);

		$this->assertIsString($html);
		$this->assertStringContainsString('type="checkbox"', $html);
		$this->assertStringContainsString(' checked', $html);
		$this->assertStringContainsString('value="1"', $html);
	}

	public function test_select_box_uses_ids_and_marks_selected(): void
	{
		$model = new ExampleModel;
		$model->values = ['user_id' => '2'];
		$column = $this->makeColumn(['name' => 'user_id']);
		$options = [1 => 'Alice', 2 => 'Bob <admin>'];

		$html = $this->callPrivate($model, 'createSelectBox', [$column, $options]);

		$this->assertStringContainsString('value="1"', $html);
		$this->assertStringContainsString('value="2" selected', $html);
		$this->assertStringContainsString('Bob &lt;admin&gt;', $html);
		$this->assertStringNotContainsString('<admin>', $html);
	}

	public function test_enum_select_uses_option_values(): void
	{
		$model = new ExampleModel;
		$model->values = ['status' => 'draft'];
		$column = $this->makeColumn(['name' => 'status']);
		$options = ['draft', 'published'];

		$html = $this->callPrivate($model, 'createSelectBox', [$column, $options]);

		$this->assertStringContainsString('value="draft" selected', $html);
		$this->assertStringContainsString('value="published"', $html);
	}

	public function test_map_text_password_heuristic(): void
	{
		$model = new ExampleModel;
		$column = $this->makeColumn(['name' => 'user_password', 'data_type' => 'varchar']);

		$result = $this->callPrivate($model, 'map_text', [$column]);

		$this->assertStringContainsString('type="password"', $result->input);
	}

	public function test_map_text_email_heuristic(): void
	{
		$model = new ExampleModel;
		$column = $this->makeColumn(['name' => 'email', 'data_type' => 'varchar']);

		$result = $this->callPrivate($model, 'map_text', [$column]);

		$this->assertStringContainsString('type="email"', $result->input);
	}

	public function test_map_set_renders_checkboxes(): void
	{
		$model = new ExampleModel;
		$model->values = ['roles' => 'admin,editor'];
		$column = $this->makeColumn([
			'name' => 'roles',
			'data_type' => 'set',
			'column_info' => "set('admin','editor','viewer')",
		]);

		$result = $this->callPrivate($model, 'map_set', [$column]);

		$this->assertNotEmpty($result->input);
		$this->assertStringContainsString('name="roles[]"', $result->input);
		$this->assertStringContainsString('value="admin" checked', $result->input);
		$this->assertStringContainsString('value="editor" checked', $result->input);
		$this->assertStringContainsString('value="viewer"', $result->input);
	}

	public function test_unknown_type_falls_back_to_text(): void
	{
		$model = new ExampleModel;
		$column = $this->makeColumn(['name' => 'meta', 'data_type' => 'uuid']);

		$result = $this->callPrivate($model, 'mapColumn', [$column]);

		$this->assertStringContainsString('type="text"', $result->input);
		$this->assertStringContainsString('name="meta"', $result->input);
	}

	public function test_number_range_has_no_commas_in_min_max(): void
	{
		$model = new ExampleModel;
		$column = $this->makeColumn(['name' => 'votes', 'data_type' => 'int']);

		$result = $this->callPrivate($model, 'map_int', [$column]);

		$this->assertStringContainsString('min="-2147483648"', $result->input);
		$this->assertStringContainsString('max="2147483647"', $result->input);
		$this->assertStringNotContainsString(',', $result->input);
	}

	public function test_get_schema_uses_parameter_bindings(): void
	{
		Config::set('database.default', 'mysql');
		Config::set('database.connections.mysql.database', 'app_db');

		DB::shouldReceive('select')
			->once()
			->with(Mockery::on(function ($sql) {
				return str_contains($sql, 'information_schema.columns')
					&& str_contains($sql, 'table_name = ?')
					&& str_contains($sql, 'table_schema = ?');
			}), ['users', 'app_db'])
			->andReturn([]);

		$model = new ExampleModel;
		$result = $model->getSchema('users');

		$this->assertSame([], $result);
	}

	public function test_get_schema_rejects_unsafe_table_names(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$model = new ExampleModel;
		$model->getSchema('users"; drop table users; --');
	}

	public function test_get_schema_caches_results(): void
	{
		Config::set('database.default', 'mysql');
		Config::set('database.connections.mysql.database', 'app_db');

		DB::shouldReceive('select')
			->once()
			->andReturn([(object) ['name' => 'id']]);

		$model = new ExampleModel;
		$first = $model->getSchema('users');
		$second = $model->getSchema('users');

		$this->assertSame($first, $second);
	}

	public function test_build_form_escapes_url_whitelists_method_and_spoofs_put(): void
	{
		Config::set('database.default', 'mysql');
		Config::set('database.connections.mysql.database', 'app_db');

		DB::shouldReceive('select')->once()->andReturn([]);
		Session::start();

		$html = ExampleModel::buildForm('https://example.com/x?a="onclick', null, 'put', false);

		$this->assertStringContainsString('method="post"', $html);
		$this->assertStringContainsString('name="_method" value="PUT"', $html);
		$this->assertStringContainsString('action="https://example.com/x?a=&quot;onclick"', $html);
		$this->assertStringContainsString('name="_token"', $html);
		$this->assertStringNotContainsString('enctype="multipart/form-data"', $html);
	}

	public function test_build_form_adds_enctype_for_post_files(): void
	{
		Config::set('database.default', 'mysql');
		Config::set('database.connections.mysql.database', 'app_db');

		DB::shouldReceive('select')->once()->andReturn([]);
		Session::start();

		$html = ExampleModel::buildForm('/upload', null, 'post', true);

		$this->assertStringContainsString('enctype="multipart/form-data"', $html);
	}

	public function test_build_form_escapes_comments_and_errors(): void
	{
		Config::set('database.default', 'mysql');
		Config::set('database.connections.mysql.database', 'app_db');

		DB::shouldReceive('select')->once()->andReturn([
			$this->makeColumn([
				'name' => 'title',
				'comment' => '<script>alert(1)</script>',
				'data_type' => 'varchar',
			]),
		]);

		Session::start();
		Session::put('errors', new MessageBag(['title' => ['Bad <script>']]));

		$html = ExampleModel::buildForm('/save', ['title' => 'ok'], 'post');

		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
		$this->assertStringContainsString('Bad &lt;script&gt;', $html);
		$this->assertStringNotContainsString('<script>alert(1)</script>', $html);
	}

	public function test_label_strips_trailing_id_only(): void
	{
		$model = new ExampleModel;

		$this->assertSame('User', $this->callPrivate($model, 'getLabelName', ['user_id']));
		$this->assertSame('Video', $this->callPrivate($model, 'getLabelName', ['video']));
	}

	public function test_timestamp_mapper_uses_datetime_local(): void
	{
		$model = new ExampleModel;
		$column = $this->makeColumn(['name' => 'published_at', 'data_type' => 'timestamp']);

		$result = $this->callPrivate($model, 'map_timestamp', [$column]);

		$this->assertStringContainsString('type="datetime-local"', $result->input);
	}
}
