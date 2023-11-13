
<!-- ABOUT THE PROJECT -->
## About The Project

# schema-fox
A POC Laravel package that will build a web form directly from a table schema, alleviating the need to build forms manually. 
  

### Built With
* [![Laravel][Laravel.com]][Laravel-url]
 

### Installation

 To pull this package directly from github into your laravel project: 

1. Add this repo to your composer json:
 
   ``` 
   "repositories": [{
        "type": "vcs",
        "url": "https://github.com/bhamner/schema-fox"
    }],

   ```
2. Use composer to pull in the package
   ```sh
   composer require bhamner/schema-fox:dev-main
   ```
 

<!-- USAGE EXAMPLES -->
## Usage

1. Add the trait to any model
```php
use Bhamner\SchemaFox\SchemaFox;
 
class MyModel extends Model{
    use SchemaFox;
    
}
```
2. Call the buildForm method now available to your model to return the form html

```php
 {!!  \App\Models\MyModel::buildForm( $url ='' , $values = NULL, $method = 'post', $files = false) !!}
```
If the form is to be filled with model values to be updated, send them as an array through $values (ex $values = User::find(1)->toArray() ). If values is left null, the form will create a new record.
