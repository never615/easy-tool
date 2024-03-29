<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Data\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

trait DatabasesTrait
{

    /**
     * 获取当前数据库所有数据表
     *
     * @return array
     */
    protected function getAllTableName()
    {
        return DB::select("SELECT relname AS table_name FROM pg_class WHERE relkind = 'r' AND relname NOT LIKE'pg_%' AND relname NOT LIKE'sql_%'");
    }


    /**
     * 判断索引是否存在
     *
     * @param $tableName '表名'
     * @param $indexName '索引名'
     *
     * @return bool
     */
    protected function existIndex($tableName, $indexName)
    {
        return (boolean) DB::select("select EXISTS (select * from pg_indexes where tablename = '{$tableName}' and  indexname = '{$indexName}')")[0]->exists;
    }


    /**
     * 检查并创建索引
     *
     * @param $tableName
     * @param $indexName
     */
    protected function createAndcheck($tableName, $indexName, $indexColumn)
    {
        if (Schema::hasColumn($tableName, $indexColumn) && ! $this->existIndex($tableName, $indexName)) {
            // 添加subject_id索引
            DB::select("create index {$indexName} on {$tableName}({$indexColumn})");

            Log::info('---------------索引创建成功----------------');
            Log::info('数据表名：' . $tableName);
            Log::info('索引名称' . $indexName);
        }
    }


    /**
     * 自定义需要添加索引的表.
     *
     * @param $tableAndIndex
     *
     * 格式为：  [
     *      table_name => [
     *          column
     *      ]
     * ]
     */
    protected function addIndexForTable($tableAndIndex)
    {
        foreach ($tableAndIndex as $tableName => $indexColumns) {
            foreach ($indexColumns as $indexColumn) {
                // 拼接索引名称
                $indexName = $tableName . '_' . $indexColumn . '_index';

                $this->createAndcheck($tableName, $indexName, $indexColumn);
            }
        }
    }


    /**
     * 所有表都创建传入的索引
     *
     * @param $indexColumns
     */
    protected function addIndexForAllTable($indexColumns)
    {
        $allTableInfo = $this->getAllTableName();

        foreach ($allTableInfo as $index => $tableInfo) {
            // 获取表名
            $tableName = $tableInfo->table_name;

            foreach ($indexColumns as $indexColumn) {
                // 拼接subject_id索引名称
                $indexName = $tableName . '_' . $indexColumn . '_index';
                $this->createAndcheck($tableName, $indexName, $indexColumn);
            }
        }
    }

}
