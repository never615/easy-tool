<?php

/*
 * Copyright (c) 2026. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

use Mallto\Tool\Middleware\EnforcePerPageLimit;

return [
    'per_page_max' => EnforcePerPageLimit::DEFAULT_PER_PAGE_MAX,
    // 这些历史接口可能读取 MongoDB 或在 PostgreSQL 无结果时回退 MongoDB，保持其现有分页逻辑。
    'per_page_limit_excluded_paths' => EnforcePerPageLimit::DEFAULT_EXCLUDED_PATHS,
];
