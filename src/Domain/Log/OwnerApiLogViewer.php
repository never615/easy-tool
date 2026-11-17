<?php

namespace Mallto\Tool\Domain\Log;

use Illuminate\Database\Eloquent\Builder;
use Mallto\Admin\Data\Administrator;
use Mallto\Admin\Data\Subject;
use Mallto\Tool\Data\OwnerApiLog;

/** 列表、详情与请求关联查询共用项目可见范围；不改变日志采集或存储。 */
class OwnerApiLogViewer
{
    public function query(Administrator $admin, array $filters = []): Builder
    {
        return $this->applyTo(OwnerApiLog::query(), $admin, $filters);
    }

    /** Grid 与详情复用相同的项目范围和筛选条件。 */
    public function applyTo(Builder $query, Administrator $admin, array $filters = []): Builder
    {
        if (!$admin->isOwner()) {
            $roots = array_values(array_unique(array_filter(
                array_merge([$admin->subject_id], (array) $admin->manager_subject_ids),
                static fn ($id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0
            )));
            if ($roots === []) {
                $query->whereRaw('1 = 0');
            } else {
                // 沿用主体自身、子主体及额外管理主体的范围，以 UUID 关联日志。
                $subjects = Subject::query()->select('uuid')->where(function (Builder $scope) use ($roots): void {
                    $scope->whereIn('id', $roots);
                    foreach ($roots as $id) {
                        $scope->orWhere('path', 'like', '%.' . (int) $id . '.%');
                    }
                })->whereNotNull('uuid')->where('uuid', '<>', '')->where('uuid', '<>', '0');
                $query->whereIn('uuid', $subjects);
            }
        }

        return $this->applyFilters($query, $filters);
    }

    public function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['request_id', 'uuid', 'method', 'action', 'status'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->where($key, $filters[$key]);
            }
        }
        if (isset($filters['url']) && $filters['url'] !== '') {
            // 按字面量包含匹配，防止输入 % / _ 意外扩大查询。
            $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['url']);
            $query->whereRaw("url LIKE ? ESCAPE '!'", ['%' . $pattern . '%']);
        }
        if (!empty($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }
        if (!empty($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        return $query;
    }

    /** 美化有效 JSON，截断内容或普通文本保留原样；视图负责 HTML 转义。 */
    public static function formatPayload(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            json_decode($value, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }

        // 仅调整字符串外的空白；解码后重新编码会改变空对象、大整数及小数精度。
        $formatted = '';
        $depth = 0;
        $inString = false;
        $escaped = false;
        $previous = '';
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $character = $value[$i];
            if ($inString) {
                $formatted .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }
            if (ctype_space($character)) {
                continue;
            }
            if ($character === '"') {
                $inString = true;
                $formatted .= $character;
            } elseif ($character === '{' || $character === '[') {
                $formatted .= $character . "\n" . str_repeat('    ', ++$depth);
            } elseif ($character === '}' || $character === ']') {
                $depth--;
                $formatted = ($previous === '{' || $previous === '[')
                    ? rtrim($formatted) . $character
                    : $formatted . "\n" . str_repeat('    ', $depth) . $character;
            } elseif ($character === ',') {
                $formatted .= ",\n" . str_repeat('    ', $depth);
            } elseif ($character === ':') {
                $formatted .= ': ';
            } else {
                $formatted .= $character;
            }
            $previous = $character;
        }

        return $formatted;
    }
}
