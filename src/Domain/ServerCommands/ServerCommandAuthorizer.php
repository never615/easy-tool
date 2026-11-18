<?php

namespace Mallto\Tool\Domain\ServerCommands;

use Illuminate\Auth\Access\AuthorizationException;
use Mallto\Admin\Data\Subject;

class ServerCommandAuthorizer
{
    public function allows($actor, string $permission): bool
    {
        return $actor && $actor->id && $actor->status === 'normal'
            && ($actor->isOwner() || $actor->can($permission));
    }

    public function require($actor, string $permission): void
    {
        if (!$this->allows($actor, $permission)) { throw new AuthorizationException('没有此服务器命令操作权限。'); }
    }

    public function subject($actor, int $subjectId): void
    {
        $query = Subject::query()->whereKey($subjectId);
        if (!$actor->isOwner()) {
            $roots = array_values(array_filter(array_map('intval', (array)$actor->manager_subject_ids)));
            if (!$roots) { $roots = [(int)$actor->subject_id]; }
            $query->where(function ($query) use ($roots) {
                foreach ($roots as $root) {
                    $query->orWhere(function ($query) use ($root) {
                        $query->where('id', $root)->orWhere('path', 'like', "%.{$root}.%");
                    });
                }
            });
        }
        if (!$query->exists()) { throw new AuthorizationException('项目不存在或不在当前账号管理范围内。'); }
    }

    public function reload(int $actorId)
    {
        $model = config('admin.database.users_model', \Mallto\Admin\Data\Administrator::class);
        return $model::query()->find($actorId);
    }
}
