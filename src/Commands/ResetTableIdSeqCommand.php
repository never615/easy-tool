<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * 重设表的自增序列
 * ResetTableIdSeqCommand 类
 *
 * @package Mallto\Tool\Commands
 */
class ResetTableIdSeqCommand extends Command
{

    /**
     * 控制台命令名称。
     *
     * @var string
     */
    protected $name = 'tool:reset_table_id_seq';

    /**
     * 控制台命令说明。
     *
     * @var string
     */
    protected $description = '重设表的自增序列';

    /**
     * 安装目录。
     *
     * @var string
     */
    protected $directory = '';


    /**
     * 执行控制台命令。
     *
     * @return void
     */
    public function handle()
    {
        $tableNames = \DB::select("select tablename from pg_tables where schemaname='public'");

        $tableNames = json_decode(json_encode($tableNames), true);

        foreach ($tableNames as $tableName) {
            $tableName = $tableName['tablename'];
            try {
                if (Schema::hasColumn($tableName, "id")) {
                    \DB::select("select setval('" . $tableName . "_id_seq',(select max(id) from $tableName))");
                }
            } catch (\Exception $exception) {
                Log::info($tableName);
                Log::info($exception->getMessage());
            }
        }

        $this->info("finish");

    }

}
