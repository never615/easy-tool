<?php
/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
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
