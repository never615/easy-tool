<?php

namespace Mallto\Tool\Data;

use Encore\Admin\Auth\Database\Traits\DynamicData;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\HasApiTokens;

/**
 *
 * Class Log
 *
 * @package Mallto\Mall\Module\Log
 */
class ThirdUser extends Authenticatable
{
    use HasApiTokens;
    protected $guarded = [
    ];
}
