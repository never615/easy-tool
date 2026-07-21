<?php

namespace Mallto\Tool\Seeder;

use Mallto\Tool\Data\AppSecretsPermission;

/**
 * Trait AppSecretSeederMaker
 *
 * @package Mallto\Admin\Seeder
 */
trait AppSecretSeederMaker
{

    /**
     * 处理「createPermissions」相关的内部协作步骤。
     */
    public function createPermissions(
        $name,
        $slug,
        $force = false
    )
    {
        try {
            $temp = AppSecretsPermission::query()
                ->updateOrCreate(
                    [
                        'slug' => $slug,
                    ],
                    [
                        'name' => $name,
                    ]);
        } catch (\Exception $exception) {
            if ($force) {
                AppSecretsPermission::query()->where('name', $name)->delete();

                $temp = AppSecretsPermission::query()
                    ->updateOrCreate(
                        [
                            'slug' => $slug,
                        ],
                        [
                            'name' => $name,
                        ]);
            } else {
                throw  $exception;
            }
        }

        return $temp->id;
    }

    /**
     * 处理「delete」相关的内部协作步骤。
     */
    public function delete($slug)
    {
        AppSecretsPermission::query()->where('slug', $slug)->delete();
    }
}
