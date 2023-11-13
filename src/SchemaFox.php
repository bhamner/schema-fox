<?php 

namespace Bhamner\SchemaFox;


use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Schema;


trait SchemaFox{

	public $values;

	public function getSchema($table){	
		$default_connection = Config::get('database.default');
		$database_config = 'database.connections.'.$default_connection.'.database';

		return DB::select('SELECT 
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
				LEFT join information_schema.key_column_usage key_usage
				ON key_usage.column_name = column_schema.column_name
				AND key_usage.table_name = column_schema.table_name
				AND key_usage.table_schema = column_schema.table_schema
				WHERE column_schema.table_name = "'. $table .'" and column_schema.table_schema = "'.Config::get($database_config).'"');
	}

	public static function getMap(){
		$self = new static;
		$table = $self->getTable();
		$schema = $self->getSchema($table);
		if(!$schema){ return "This table does not exist"; }
		return $schema;
	}

	public static function getInputs($values = null){
		if(is_null($values)){ $values = Request::old(); }
		$self = new static;
		$table = $self->getTable();
		$self->values = $values;
		$schema = $self->getSchema($table);
		if(!$schema){ return "This table does not exist"; }
		foreach($schema as $column){
			if(!is_null($self->removeHidden($column))){
				$mapped[] = call_user_func ( array($self,"map_".$column->data_type) , $column );
			}
		}
		return array_values(array_filter($mapped));
	}

	public static function buildform($url = '', $values = NULL, $method = 'post', $files = false){
        $self = new static;
        $builder = [];
		$formopen = '<form method="'.$method.'" action="'.$url.'"'; 
			if(strtolower($method == "post") && $files !== false ){ $formopen .= ' enctype="multipart/form-data"'; } 
		$formopen .= '>  <input type="hidden" name="_token" value="'.csrf_token().'">';

		$builder[] = $formopen;
        $formInputs = self::getInputs($values);
        $errors = Session::get('errors', new MessageBag);
        $model = get_class($self);
        foreach($formInputs as $formgroup){
        	$builder[] = '<br/>';
        	$builder[] = $formgroup->input;
            if( $formgroup->comment ){
                 $builder[] = '<br/><span><small>'. $formgroup->comment .'</small></span>';
            }
            if($errors->has($formgroup->name)){
            	$builder[] = $errors->first($formgroup->name, '<br/><span style="color:red">:message</span>');
        	}
        }
        $builder[] = '<br/><button type="submit" value="Submit">Submit</button>';
        $builder[] = '</form>';
        return implode('', $builder);
    }

    /**
     * remove hidden columns
     *
     * @param  schema query object $column
     * @return string|null
     */

	private function removeHidden($column){
		if(in_array($column->name, $this->hidden)){
			return null;
		}
		return $column;
	}

	
    /**
     * remove timestamp columns
     *
     * @param  schema query object $column
     * @return string|null
     */

	private function removeLaravelTimestamps($column){
		if(Str::is('created_at', $column->name) || 
			Str::is('updated_at', $column->name) || 
			Str::is('deleted_at', $column->name)){
			return null;
		}
		return $column;
	}

	
	/**
     * retrieve values if record exists
     *
     * @param  column name $key
     * @return string|null
     */

	private function getColumnValues($key){
		return (array_key_exists($key, $this->values))? $this->values[$key] : null ;
	}


	/**
     * format the column name to make an input label
     *
     * @param  column name $str
     * @return string
     */

	private function getLabelName($str, $separator = '_'){
        return ucwords(preg_replace('/['.$separator.']+/', ' ', trim(str_replace('id', '', strtolower($str)))));
    }


	/**
     * get columns of foreign keys
     *
     * @param  column name $str
     * @return array
     */

	private function getRelatives($column){
		$foreign_table = str_replace( Config::get('database.connections.production.prefix'), '', $column->ref_table_name ) ;
		if(Schema::hasColumn($foreign_table, 'deleted_at')){
			return DB::table($foreign_table)->whereNull('deleted_at')->lists("name", $column->ref_column_name);
		}
		return DB::table($foreign_table)->lists("name", $column->ref_column_name);
	}



	/**
	 * create form inputs
	 * 
	 */ 

	private function createTextArea($column, $rows = 5){
		return '<label for="'.$column->name.'">'. $this->getLabelName($column->name).'</label><br/>'.
			'<textarea id="'.$column->name.'" name="'.$column->name.'" rows="'.$rows.'" maxlength="'.$column->max_length.'">'.$this->getColumnValues($column->name).'</textarea>';
	}

	private function createInputField($column, $type){
		return '<label for="'.$column->name.'">'. $this->getLabelName($column->name).'</label><br/>'.
			'<input type="'.$type.'"id="'.$column->name.'" name="'.$column->name.'" maxlength="'.$column->max_length.'">'.$this->getColumnValues($column->name).'</input>';
	}	

	private function createDateField($column, $type){
		return $this->createInputField($column, $type);
	}	

	private function createHiddenInput($column){
		return '<input type="hidden" id="'.$column->name.'" name="'.$column->name.'" maxlength="'.$column->max_length.'">'.$this->getColumnValues($column->name).'</input>';
	}	

	private function createNumberRange($column, $min, $max){
		return '<label for="'.$column->name.'">'. $this->getLabelName($column->name).'</label><br/>'.
			'<input type="number" id="'.$column->name.'" name="'.$column->name.'" min="'.$min.'" max="'.$max.'" maxlength="'.$column->max_length.'">'.$this->getColumnValues($column->name).'</input>';
	}	

	private function createCheckBoxes($column){
		return  '<label for="'.$column->name.'">'. $this->getLabelName($column->name).'</label><br/>'.
				'<input type="checkbox" id="'.$column->name.'" name="'.$column->name.'" value="1">'.$this->getColumnValues($column->name) > 0 .'</input>';
	}	

	private function createSelectBox($column,$options){
		
		$select = '<label for="'.$column->name.'">'. $this->getLabelName($column->name).'</label><br/>'.
			'<select id="'.$column->name.'" name="'.$column->name.'" >';
		foreach($options as $option){
			$select .= '<option value="'.$option.'">'.ucwords($option).'</option>';
		}
		$select .= '</select>';
	}	


	/**
	 * Numeric Types
	 * 
	 */

	private function map_int($column){
		$column->input = $this->createNumberRange($column, "-2,147,483,648", "2,147,483,647");
		if($column->constraint_name == "PRIMARY"){
			$column->input = $this->createHiddenInput($column);
		}
		if(!is_null($column->ref_table_name)){
			$options = $this->getRelatives($column);
			$column->input = $this->createSelectBox($column,$options);
		}
		return $column;
	}

	private function map_integer($column){
		$column->input = $this->createNumberRange($column, "-2,147,483,648", "2,147,483,647");
		if($column->constraint_name == "PRIMARY"){
			$column->input = $this->createHiddenInput($column);
		}
		if(!is_null($column->ref_table_name)){
			$options = $this->getRelatives($column);
			$column->input = $this->createSelectBox($column,$options);
		}
		return $column;
	}

	private function map_tinyint($column){
		$column->input = $this->createNumberRange($column, "-128", "127");
		if($column->column_info == "tinyint(1)"){
			$column->input = $this->createCheckBoxes($column);
		}
		return $column;
	}

	private function map_smallint($column){
		$column->input = $this->createNumberRange($column, "-32,768", "32,767");
		return $column;
	}

	private function map_mediumint($column){
		$column->input = $this->createNumberRange($column, "-8388608", "8388607");
		return $column;
	}

	private function map_bigint($column){
		$column->input = $this->createNumberRange($column, "-9,223,372,036,854,775,808", "9,223,372,036,854,775,807");
		return $column;
	}

	private function map_bit($column){
		$column->input = $this->createNumberRange($column, "1", "64");
		return $column;
	}


	private function map_real($column){
		$max = pow ( 10 , $column->num_precision ) - 1;
		$column->input = $this->createNumberRange($column, "-", $max);
		return $column;
	}
	private function map_double($column){
		return $this->map_real($column);
	}  

	private function map_float($column){
		return $this->map_real($column);
	}
	
	private function map_decimal($column){
		return $this->map_real($column);
	} 

	/**
	 * Text Types
	 * 
	 */
	private function map_text($column){

		switch ($column->name) {
			case Str::is('*password*', $column->name):
				$column->input = $this->createInputField($column, 'password');

				break;

			case Str::is('*email*', $column->name):
				$column->input = $this->createInputField($column, 'email');

				break;

			case Str::is('*phone*', $column->name):
				$column->input = $this->createInputField($column, 'text');

				break;

			case Str::is('*url*', $column->name):
				$column->input = $this->createInputField($column, 'url');

				break;

			case Str::is('*image*', $column->name):
				$column->input = $this->createInputField($column, 'file');

				break;

			case Str::is('*logo*', $column->name):
				$column->input = $this->createInputField($column, 'file');

				break;

			case Str::is('*photo*', $column->name):
				$column->input = $this->createInputField($column, 'file');

				break;


			default:
				$column->input = $this->createInputField($column, 'text');
		}


		if($column->max_length > 300){
			$column->input = $this->createTextArea($column, 5);
		}

		return $column;
	}


	private function map_varchar($column){

		return $this->map_text($column);
	}	

	private function map_char($column){

		return $this->map_text($column);
	}

	private function map_tinytext($column){
		$column->input = $this->createInputField($column, 'text');
		return $column;
	}

	private function map_mediumtext($column){
		$column->input = $this->createTextArea($column, 10);
		return $column;
	}

	private function map_longtext($column){
		$column->input = $this->createTextArea($column, 15);
		return $column;
	}

	/**
	 * Date Types
	 * 
	 */
	private function map_time($column){
		$column->input = $this->createDateField($column, 'time');
		return $column;
	}

	private function map_datetime($column){
		$column->input = $this->createDateField($column, 'datetime-local');
		return $this->removeLaravelTimestamps($column);
	}

	private function map_timestamp($column){
		$column->input = $this->createDateField($column, 'time');
		return $this->removeLaravelTimestamps($column);
	}

	private function map_year($column){
		$column->input = $this->createDateField($column, 'date');
		return $this->removeLaravelTimestamps($column);
	}

	private function map_date($column){
		$column->input = $this->createDateField($column, 'date');
		return $this->removeLaravelTimestamps($column);
	}

	/**
	 * Binary Types
	 * 
	 */
	private function map_binary($column){
		$column->input = $this->createInputField($column, 'file');
		return $column;
	}

	private function map_varbinary($column){
		return $this->map_binary($column);
	}

	private function map_blob($column){
		return $this->map_binary($column);
	}

	private function map_tinyblob($column){
		return $this->map_binary($column);
	}

	private function map_mediumblob($column){
		return $this->map_binary($column);
	}

	private function map_longblob($column){
		return $this->map_binary($column);
	}


	/**
	 * Enum Types
	 * 
	 */

	private function map_enum($column){

		$enumValues = str_replace(array("(", ")" , "'"), '', substr($column->column_info, 4));
		$options = explode(',',$enumValues);
		$column->input = $this->createSelectBox($column,$options);

		return $column;
	}

	private function map_set($column){

		$setValues = str_replace(array("(", ")" , "'"), '', substr($column->column_info, 4));
		$options = explode(',',$setValues);
		foreach($options as $option){
			$inputs[]= $this->createCheckBoxes($column);
		}
		// $column->input = Form::label($column->name, $this->getLabelName($column->name)). implode(' ',$inputs);

		return $column;
	}


	/**
	 * Spatial Types
	 * 
	 */

	private function map_point($column){
		$column->input = $this->createInputField($column, 'text');
		return $column;
	}

	private function map_linestring($column){
		return $this->map_point($column);
	}
	private function map_polygon($column){
		return $this->map_point($column);

	}
	private function map_geometry($column){
		return $this->map_point($column);

	}
	private function map_multipoint($column){
		return $this->map_point($column);

	}
	private function map_multilinestring($column){
		return $this->map_point($column);

	}
	private function map_multipolygon($column){
		return $this->map_point($column);

	}
	private function map_geometrycollection($column){
		return $this->map_point($column);

	}



}