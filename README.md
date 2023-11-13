
<img alt="schemafox.png" src="https://github.com/bhamner/schema-fox/blob/main/schemafox.png?raw=true"  width="150">

# schema-fox
A Proof-of-Concept Laravel package that will build a web form directly from a table schema, alleviating the need to build forms manually. 
Integer Primary keys create hidden inputs
Integer type columns create number inputs
Tinyint columns create a checkbox with a 0 or 1 value
Varchar type columns create text inputs depending on the name. 
   If the column’s name is password, it creates a password field
   If the column’s name is email, it creates an email field
   If the column’s name is image, logo, or photo, it creates a file upload
Enum column types create a select input, with the enum options as values


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
If the form is to be filled with model values to be updated, send them as an array through $values 
ex: $values = User::find(1)->toArray() 
If $values is left null, the form will create a new record
