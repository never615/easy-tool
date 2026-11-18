<?php
/*
 * 版权所有 (c) 2026. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

use Illuminate\Database\Migrations\Migration;
use Mallto\Tool\Data\AppSecretsPermission;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        AppSecretsPermission::query()->delete();

    }

    public function down(): void
    {
    }
};
