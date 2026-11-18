<?php
/**
 * 版权所有 (c) 2017. Mallto.Co.Ltd.<mall-to.com> 保留所有权利。
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * 重设表的自增序列
 * ResetTableIdSeqCommand 类
 *
 * @package Mallto\Tool\Commands
 */
class CreateTableIdSeqCommand extends Command
{

    /**
     * 控制台命令名称。
     *
     * @var string
     */
    protected $name = 'tool:create_table_id_seq';

    /**
     * 控制台命令说明。
     *
     * @var string
     */
    protected $description = '创建表的自增序列';

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
            $seq = $tableName . '_id_seq';

            try {
            if (Schema::hasColumn($tableName, "id")) {
                $idType = Schema::connection(config('database.default'))->getColumnType($tableName, 'id');
                if ($idType == "integer") {
                    //Log::debug($tableName . ':' . $idType);

                    DB::statement("alter table $tableName alter column id set default nextval('$seq')");
                }
            }
            } catch (\Exception $exception) {
                Log::info($exception->getMessage());
                Log::info($tableName);
            }
        }

        $this->info("finish");

    }

}
