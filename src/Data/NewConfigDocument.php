<?php

namespace Mallto\Tool\Data;

class NewConfigDocument extends BaseModel
{
    public const SLUG_USAGE = 'configuration_usage';

    protected $table = 'new_config_documents';

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
