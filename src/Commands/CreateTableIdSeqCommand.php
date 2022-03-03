<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 重设表的自增序列
 * Class ResetTableIdSeqCommand
 *
 * @package Mallto\Tool\Commands
 */
class CreateTableIdSeqCommand extends Command
{

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'tool:create_table_id_seq';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '创建表的自增序列';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';


    /**
     * Execute the console command.
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
                    //\Log::debug($tableName . ':' . $idType);

                    DB::statement("alter table $tableName alter column id set default nextval('$seq')");
                }
            }
            } catch (\Exception $exception) {
                \Log::info($exception->getMessage());
                \Log::info($tableName);
            }
        }

        $this->info("finish");

    }

}
