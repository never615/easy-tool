<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data;

use Mallto\Admin\Data\Traits\DynamicData;
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
