<?php namespace App\Console\Commands\Rerun;

use Maatwebsite\Excel\Excel;
use stdClass;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use App\Models\Charges;
use Illuminate\Support\Facades\Storage;

class Json extends Command 
{
    
  protected $signature = 'rerun:json  {lessorcode : XXX}';

    
  private $excel;
  private $sysinfo;
  private $extracted_path;

  public function __construct(Excel $excel) {
      parent::__construct();
      $this->excel = $excel;
      $this->sysinfo();
      $this->extracted_path = 'C:\\GI_GLO';
      $this->lessor = ['pro', 'aol', 'yic', 'ghl'];
      $this->path = 'C:\\EODFILES';
  }

  /**
   * Execute the console command.
   *
   * @return mixed
   */
  public function handle() {

    /*      
    $dir = 'D:\\rename';
    $out = 'D:\\rename_out';

    if (is_dir($dir)){
      $this->info('exist');


      $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
      $files = new \RecursiveIteratorIterator($it,
                   \RecursiveIteratorIterator::CHILD_FIRST);
      foreach($files as $file) {
          if ($file->isDir()){
              $this->info("dir: ".$file->getRealPath());//rmdir($file->getRealPath());
          } else {
            $this->info("file: ".$file->getRealPath());//rmdir($file->getRealPath());

            $f = pathinfo($file->getRealPath());

            if (strtolower($f['extension'])==='txt') {

              $f1 = substr($f['filename'], 0, 10);
              $f2 = substr($f['filename'], 13);
              $new_filename = $f1.'372'.$f2.'.'.$f['extension'];

              $this->info('file: D:\rename\\'.$new_filename);//rmdir($file->getRealPath());


              //read the entire string
              $str = file_get_contents($file->getRealPath());

              //replace something in the file string - this is a VERY simple example
              $str = str_replace("GILIGAN'S", "GILIGANS", $str);

              //write the entire string
              file_put_contents($out.DIRECTORY_SEPARATOR.$new_filename, $str);
            }
          }
      }
    } 
    exit;
    */

    $lessorcode = strtolower($this->argument('lessorcode'));
    if (!in_array($lessorcode, $this->lessor)) {
      $this->info('Invalid lessorcode.');
      alog('Invalid lessorcode: '.$lessorcode);
      exit;
    }


    switch ($lessorcode) {
      case 'aol':
        $this->aol();
        break;
      case 'ghl':
        $this->ghl();
        break;
      default:
        $this->info('No function on this lessor.');
        break;
    }
    
    

      
  }

  private function aol() {
    $this->info('json');
    $charges =  Charges::where('branch_id', '11e8918f1c1b0d85a7e09189291ab1b9')
                      ->where('terms', '<>', 'SIGNED')
                      ->orderBy('orddate')
                      ->orderBy('ordtime')
                      ->get();
    
    $sales = [];

    $sales[0] = [
      'sales' => 0,
      'date'  => $charges[0]->orddate->copy()->subDay(),
      'taxsale' => 0,
      'vat' => 0,
      'notaxsale' => 0,
      'opentime' => Carbon::now(),
      'closetime' => Carbon::now(),
      'gross' => 0,
    ];
    
    $ctr=1;
    $curr_date = NULL;
    foreach ($charges as $key => $charge) {

      if (is_null($curr_date)) {
        $curr_date = $charge->orddate;
        $sales[$ctr]['date'] = $curr_date;
        $sales[$ctr]['sales'] = 0;
        $sales[$ctr]['taxsale'] = 0;
        $sales[$ctr]['vat'] = 0;
        $sales[$ctr]['notaxsale'] = 0;
        $sales[$ctr]['notaxsale'] = 0;
        $sales[$ctr]['opentime'] = $charge->transdate;
        $sales[$ctr]['closetime'] = $charge->transdate;
        $sales[$ctr]['gross'] = 0;
      }

      

      if ($curr_date==$charge->orddate) {
        
        $sales[$ctr]['sales'] += $charge->tot_chrg;
        $sales[$ctr]['closetime'] = $charge->transdate;
        $sales[$ctr]['gross'] += $charge->chrg_grs;

        if ($charge->sr_disc>0) {
          $sales[$ctr]['taxsale'] += 0;
          $sales[$ctr]['notaxsale'] += $charge->tot_chrg;
        } else { 
          $sales[$ctr]['taxsale'] += ($charge->chrg_grs-$charge->disc_amt);
          $sales[$ctr]['vat'] += $charge->vat;
        }
        
      } else {

        //$this->info($ctr.' '.$curr_date->format('Y-m-d').' '.$charge->chrg_grs);

        $curr_date = $charge->orddate;
        $ctr++;

        $sales[$ctr]['date'] = $curr_date;
        
        $sales[$ctr]['sales'] = $charge->tot_chrg;
        $sales[$ctr]['opentime'] = $charge->transdate;
        $sales[$ctr]['gross'] = $charge->chrg_grs;
        $sales[$ctr]['notaxsale'] = 0;

        if ($charge->sr_disc>0) {
          $sales[$ctr]['taxsale'] = 0;
          $sales[$ctr]['notaxsale'] = $charge->tot_chrg;
        } else { 
          $sales[$ctr]['taxsale'] = ($charge->chrg_grs-$charge->disc_amt);
          $sales[$ctr]['vat'] = $charge->vat;
        }

      }

      //$this->info($ctr);
    }

    $previousnrgt = 0;
    $previoustax = 0;
    $previoustaxsale = 0;
    $previousnotaxsale = 0;
    foreach ($sales as $key => $ds) {
      //$this->info($key.' '.$ds['date']->format('Y-m-d').' '.$ds['sales'].' '.$ds['gross'].' '.$ds['taxsale'].' '.$ds['vat'].' '.$ds['notaxsale']);
      $data = [];

      if ($key>0) {

        $prev = $key - 1;

        $data = [
          'date'              => $ds['date']->format('Ymd'),
          'zcounter'          => ($key+1),
          'previousnrgt'      => $previousnrgt,
          'nrgt'              => ($previousnrgt+$ds['sales']),
          'previoustax'       => $previoustax,
          'newtax'            => ($previoustax+$ds['vat']),
          'previoustaxsale'   => $previoustaxsale,
          'newtaxsale'        => ($previoustaxsale+$ds['taxsale']),
          'previousnotaxsale' => $previousnotaxsale,
          'newnotaxsale'      => ($previousnotaxsale+$ds['notaxsale']),
        ];

        $previousnrgt += $ds['sales'];
        $previoustax += $ds['vat'];
        $previoustaxsale += $ds['taxsale'];
        $previousnotaxsale += $ds['notaxsale'];

        //$this->info($key.' '.$ds['date']->format('Y-m-d').' '.$ds['sales'].' '.$data['previousnrgt'].' '.$data['nrgt']);
        //$this->info($key.' '.$ds['date']->format('Y-m-d').' '.$ds['sales'].' '.$ds['gross'].' '.$data['previousnrgt'].' '.$data['nrgt']);

        $this->toJson($ds['date'], $data);
      }
    }
  }










  private function toJson($date, $data) {

    $filename = $date->format('Ymd');
    $dir = $this->getStoragePath().DS.$date->format('Y').DS.$date->format('m');
    mdir($dir);
    $file = $dir.DS.$filename.'.json';

    $fp = fopen($file, 'w');

    fwrite($fp, json_encode($data));

    fclose($fp);

    if (file_exists($file)) {
      $this->info($file.' - OK');
      alog($file.' - OK');
    } else {
      $this->info($file.' - Error on generating');
      alog($file.' - Error on generating');
    }
  }

  private function getpath() {
    if (starts_with($this->sysinfo->txt_path, 'C:\\'))
      return $this->sysinfo->txt_path;
    return $this->path;
  }

  private function getStoragePath() {
    return $this->path.DS.'storage'.DS.$this->sysinfo->gi_brcode;
  }

  private function getSysinfo($r) {
    $s = new StdClass;
    foreach ($r as $key => $value) {
      $f = strtolower($key);
      $s->{$f} = isset($r[$key]) ? $r[$key]:NULL;
    }
    return $s;
  }

  private function sysinfo() {
    $dbf_file = 'C:\\GI_GLO\\SYSINFO.DBF';

    if (file_exists($dbf_file)) { 
      $db = dbase_open($dbf_file, 0);
      $row = dbase_get_record_with_names($db, 1);

      $this->sysinfo = $this->getSysinfo($row);

      dbase_close($db);

    } else {
      throw new Exception("Cannot locate SYSINFO"); 
    }
  }

  private function checkOrder() {

    $dbf_file = $this->extracted_path.DS.'ORDERS.DBF';
    
    if (file_exists($dbf_file)) { 
      $db = dbase_open($dbf_file, 0);
      $record_numbers = dbase_numrecords($db);
      $grsamt = 0;

      for ($i = 1; $i <= $record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        $grsamt += $row['GRSAMT'];
      }
      dbase_close($db);
    
      if ($record_numbers>0 || $grsamt>0)
        throw new Exception("Validation Error: ".$record_numbers." unsettled item(s) on ORDERS.DBF with total amount of ". number_format($grsamt, 2).". Kindly settle and perform an EoD on POS before executing this command."); 

    } else {
      throw new Exception("Cannot locate ORDERS.DBF"); 
    }
  }

  private function checkCashAudit(Carbon $date) {
    $dbf_file = $this->extracted_path.DS.'CSH_AUDT.DBF';
    if (file_exists($dbf_file)) { 
      $db = dbase_open($dbf_file, 0);
      $record_numbers = dbase_numrecords($db);
      $a = [];
      $valid = true;
      for ($i = 1; $i <= $record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        $vfpdate = vfpdate_to_carbon(trim($row['TRANDATE']));

        if ( $vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {

          $t = trim($row['TIP']);
          if (empty($t)) {
            array_push($a, 'TIPS');
          }

          $k = trim($row['CREW_KIT']);
          if (empty($k)) {
            array_push($a, 'CREW_KIT');
            $valid = false;
          }

          $d = trim($row['CREW_DIN']);
          if (empty($k)) {
            array_push($a, 'CREW_DIN');
            $valid = false;
          }
        }
      }
      dbase_close($db);

      if (!$valid) {
        throw new Exception("Validation Error: No encoded ".join(", ", $a).". Please perform an EoD on POS before executing this command."); 
      }

    } else {
      throw new Exception("Cannot locate CSH_AUDT.DBF"); 
    }
  }



  
}
