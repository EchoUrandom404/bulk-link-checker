<?php
namespace acelabs\bulklinkchecker\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    public $concurrency = 1;

    public function rules(): array
    {
        return [
            ['concurrency', 'integer', 'min' => 1, 'max' => 50],
        ];
    }
}
