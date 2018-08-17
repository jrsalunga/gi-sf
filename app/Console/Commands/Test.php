<?php

namespace App\Console\Commands;
use Maatwebsite\Excel\Excel;

use Illuminate\Console\Command;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cmd:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */

    private $excel;

    public function __construct(Excel $excel)
    {
        parent::__construct();
        $this->excel = $excel;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
      
      $dbf_file = 'C:\\GI_GLO\\SYSINFO.DBF';

      if (file_exists($dbf_file)) { 
        $db = dbase_open($dbf_file, 0);
        $row = dbase_get_record_with_names($db, 1);

        $code = trim($row['GI_BRCODE']);

        dbase_close($db);
        if(empty($code)) {
          throw new Exception("Cannot locate Branch Code on backup");
        }
        else 
          $this->info($code);
      } else {
        throw new Exception("Cannot locate SYSINFO"); 
      }
    }

}
