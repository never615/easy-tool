<?php
/**
 * Copyright (c) 2017. Mallto.Co.Ltd.<mall-to.com> All rights reserved.
 */

namespace Mallto\Tool\Commands;

use Illuminate\Console\Command;

/**
 * 重设表的自增序列
 * Class ResetTableIdSeqCommand
 *
 * @package Mallto\Tool\Commands
 */
class ResetTableIdSeqCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'tool:reset_table_id_seq';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重设表的自增序列';

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
//            \Log::debug($tableName);
            $tableName = $tableName['tablename'];

            try {
                \DB::select("select setval('".$tableName."_id_seq',(select max(id) from $tableName))");
            } catch (\Exception $exception) {
                \Log::debug($exception->getMessage());
            }
        }

        return;
    }

}
