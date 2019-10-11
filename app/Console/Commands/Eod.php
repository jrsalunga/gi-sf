<?php namespace App\Console\Commands;

use Maatwebsite\Excel\Excel;
use stdClass;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Spatie\ArrayToXml\ArrayToXml;

class Eod extends Command
{
  protected $signature = 'eod {date : YYYY-MM-DD} {--lessorcode= : File Extension} {--ext=csv : File Extension} {--mode=eod : Run Mode} {--dateTo=NULL : Date To}';
  protected $description = 'Command description';
  private $excel;
  private $sysinfo;
  private $extracted_path;
  private $lessor = NULL; // used as branch code
  private $date = NULL;

  public function __construct(Excel $excel) {
      parent::__construct();
      $this->excel = $excel;
      $this->sysinfo();
      $this->extracted_path = 'C:\\GI_GLO';
      $this->lessors = ['pro', 'aol', 'yic', 'ocl'];
      $this->path = 'C:\\EODFILES';      
  }

  public function handle() {

    $date = $this->argument('date');
    if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
      $this->info('Invalid date.');
      alog('Invalid date: '.$date);
      exit;
    }
    
    $ext = $this->option('ext');
    if(!empty($ext)) {
      if (!in_array(strtolower($ext), ['txt', 'csv'])) {
        $this->info('Invalid file extension.');
        alog('Invalid file extension: '.$ext);
        exit;
      }
    }
    

    $lessorcode = strtolower($this->option('lessorcode'));

    $date = Carbon::parse($date);

    if (strtolower($this->option('mode'))==='eod') {
      alog('Starting...');
      //$this->info($this->sysinfo->trandate);

      if ($date->gte(Carbon::now()))
        $this->checkOrder();

      $this->checkCashAudit($date);

      $this->generateEod($date, $lessorcode, $ext);

    } else if (strtolower($this->option('mode'))==='resend') {
      $this->info('running on resend mode');
      $this->getOut();

      $to = $this->option('dateTo');
      if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $to)) {
        $to = $date;        
      } else {
        $to = Carbon::parse($to);
        if ($to->lt($date))
          $to = $date;        
      }
      $this->resend($date, $to, $lessorcode);

    } else {
      $this->info('Error: unknown mode');
    }

    
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

  private function getpath() {
    //if (starts_with($this->sysinfo->txt_path, 'C:\\'))
    //  return $this->sysinfo->txt_path;
    return $this->path.DS.'output'.DS.$this->lessor;
  }

  private function getStoragePath() {
    //return $this->path.DS.'storage'.DS.$this->sysinfo->gi_brcode;
    return $this->path.DS.'storage'.DS.$this->lessor;
  }

  private function getOut() {
    //return $this->out = NULL;
    switch ($this->lessor) {
      case 'AOL':
        //$this->out = '\\\\192.168.1.50\\User0001L';

        if (app()->environment()=='local')
          return $this->out = '\\\\192.168.1.5\\maindepot\\TEST_AOL';
        else
          return $this->out = '\\\\192.168.1.50\\User0001L';

        return $this->out = 'Z:';
        /* run as admin

        net use Z: \\192.168.1.50\User0001L /user:User0001L D808bREMREf1kMJ /p:yes /savecred
        */
        break;
      case 'YIC':
        $dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$this->date->format('Y').DS.$this->date->format('n').DS.$this->date->format('j');
        mdir($dir);
        return $this->out = $dir;
        break;
      case 'OCL':
        $dir = 'D:'.DS.'OCL'.DS.$this->date->format('Y').DS.$this->date->format('n').DS.$this->date->format('j');
        mdir($dir);
        return $this->out = $dir;
        break;
      default:
        return $this->out = NULL;
        break;
    }
  }

  private function verifyCopyFile($file, $newfile) {

    $this->info(' ');

    if (file_exists($file)) {
      $this->info('OK - '.$file);
      alog('OK - Generating: '.$file);
    } else {
      $this->info('ERROR - '.$file);
      alog('ERROR - Generating: '.$file);
    }

    if ((!is_null($this->out) || !empty($this->out)) && is_dir($this->out)) {  

      //$this->info('OK - Drive: '.$this->out);
      alog('OK - Drive: '.$this->out);

      //$this->info('Copying: '.$file);
      //$this->info($newfile);
      alog('Copying: '.$file.' - '.$newfile);
      if (copy($file, $newfile)) {
        $this->info('OK - Copying: '.$newfile);
        alog($file.' - Success on copying');
      } else {
        $this->info('ERROR - Copying: '.$file);
        alog($file.' - Error on copying');
      }
    } else {
      $this->info('ERROR - Drive: '.$this->out.' not found. Unable to copy files.');
      alog('ERROR - Drive: '.$this->out.' not found. Unable to copy files.');
    }
  }

  private function toCSV($data, $date, $filename=NULL, $ext='CSV', $path=NULL) {

    $file = is_null($filename)
      ? Carbon::now()->format('YmdHis v')
      : $filename;

    $dir = is_null($path)
      ? $this->getpath().DS.$date->format('Y').DS.$date->format('m')
      : $path;


    if(!is_dir($dir))
        mkdir($dir, 0775, true);

    $file = $dir.DS.$file.'.'.$ext;

    $fp = fopen($file, 'w');

    foreach ($data as $fields) {
     fputcsv($fp, $fields);
    }

    fclose($fp);
  }

  private function toTXT($data, $date, $filename=NULL, $ext='TXT') {

    $file = is_null($filename)
      ? Carbon::now()->format('YmdHis v')
      : $filename;

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');

    if(!is_dir($dir))
        mkdir($dir, 0775, true);

    $file = $dir.DS.$file.'.'.$ext;

    $fp = fopen($file, 'w');

    foreach ($data as $fields) {
      //$this->info(join(',', $fields));
      fwrite($fp, join(',', $fields).PHP_EOL);
    }

    fclose($fp);
  }

  private function generateEod(Carbon $date, $lessor, $ext) {
    
    $lessor = empty($lessor) 
      ? strtolower($this->sysinfo->lessorcode)
      : $lessor;
    
    if (empty($lessor)) {
      alog('Error: LESSORINFO on SYSINFO.DBF is empty.');
      throw new Exception("Error: LESSORINFO on SYSINFO.DBF is empty."); 
    }
    
    if (!in_array($lessor, $this->lessors)){
      alog('Error: No lessor found.');
      throw new Exception("Error: No lessor found."); 
    }

    if (!method_exists('\App\Console\Commands\Eod', $lessor)) {
      alog("Error: No method ".$lessor." on this Class.");
      throw new Exception("Error: No method ".$lessor." on this Class."); 
    }

    $this->lessor = strtoupper($lessor);
    $this->date = $date;

    //$this->info('lessor: '. $this->lessor);
    //exit;

    alog('Generating file for: '.$lessor.' '.$date->format('Y-m-d'));
    $this->info('Generating file for: '.$lessor.' '.$date->format('Y-m-d'));

    $this->getOut();
    
    $this->{$lessor}($date, $ext);
  }

  private function resend(Carbon $date, $to, $lessor) {
    $this->info($date);
    $this->info($lessor);

     $lessor = empty($lessor) 
      ? strtolower($this->sysinfo->lessorcode)
      : $lessor;
    
    if (empty($lessor)) {
      alog('Error: LESSORINFO on SYSINFO.DBF is empty.');
      throw new Exception("Error: LESSORINFO on SYSINFO.DBF is empty."); 
    }
    
    if (!in_array($lessor, $this->lessors)){
      alog('Error: No lessor found.');
      throw new Exception("Error: No lessor found."); 
    }

    $this->lessor = strtoupper($lessor);


    $this->info('lessor: '.$this->lessor);
    $this->info('to: '.$to);
    $this->info($this->getPath());
    $this->info('diff: '.$to->diffInDays($date));
    
    $xDay = $date->copy();
    for ($i=0; $i <= $to->diffInDays($date); $i++) { 
      $this->info($i.' '.$xDay);
      $this->date = $xDay;
      
      $p = $this->getPath().DS.$xDay->format('Y').DS.$xDay->format('m').DS.$xDay->format('d');

      if (is_dir($p)) {
        foreach (scandir($p) as $key => $value) {
          if(!is_dir($value)) {
            $this->info($value);

            $file = $p.DS.$value;
            $newfile = $this->getOut().DS.$value;

            $this->verifyCopyFile($file, $newfile);
          }
        }
      }

      $this->info('Success resending '.$xDay->format('Y-m-d'));
      sleep(3);
      $xDay->addDay();
    }
    $this->info('Done!');
  }


  /*********************************************************** OCL ****************************************/
  public function OCL(Carbon $date, $ext) {
    $c = $this->oclCharges($date);
    $this->oclDaily($date, $c, $ext);
    $this->oclHourly($date, $c, $ext);
    $this->oclInvoice($date, $c, $ext);
  }

  private function zero($field) {
    return $field == 0 ? 0 :  number_format($field, 2,'','');
  } 

  private function oclDaily($date, $c, $ext) {

    $ext = $this->sysinfo->zread_ctr>0
      ? $this->sysinfo->zread_ctr+1
      : 1;

    $filename = 'D'.substr($this->sysinfo->tenantname, 0, 5).'0'.($this->sysinfo->pos_no+0).$date->format('mdY');

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    if(!is_dir($dir))
        mkdir($dir, 0775, true);
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');

    $data = $this->oclDailyData($date, $c);

    foreach ($data as $line => $value) {
      //$this->info($value);
      $sum = 0;

      $ln = str_pad($line+1, 2, '0', STR_PAD_LEFT);
     
      //$value = $ln.' '.$value; // on local
      $value = $ln.$value; // on productiom
      //$this->info('value: '.$value);

      if (count($data)==($line+1))
        fwrite($fp, $value);
      else
        fwrite($fp, $value.PHP_EOL);

    }

    fclose($fp);

    $newfile = $this->out.DS.$filename.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);
  }

  private function oclDailyData($date, $c) {

    $zread = trim($this->sysinfo->zread_ctr)+1;

    $vat = (($c['vat_gross']-$c['totdisc'])*.12)/1.12;
    $vat_sales = $c['vat_gross']-$c['totdisc']-$vat;

    $novat_sales = $c['novat_gross'] - $c['sr_disc'];

    //$this->info($c['novat_gross'].' = '.$c['sr_disc']);

    $prev = $this->oclGetPrev($date);

    $this->toJson($date, [
      'zcounter'            => $zread,
      'vat_gross'           => $c['vat_gross'],
      'vat_sale'            => $vat_sales,
      'novat_gross'         => $c['novat_gross'],
      'novat_sale'          => $novat_sales,
      'old_vat_gt_gross'    => $prev['prev_vat_gross'],
      'new_vat_gt_gross'    => $prev['prev_vat_gross']+$c['vat_gross'],
      'old_vat_gt_sale'     => $prev['prev_vat_sale'],
      'new_vat_gt_sale'     => $prev['prev_vat_sale']+$vat_sales,
      'old_novat_gt_gross'  => $prev['prev_novat_gross'],
      'new_novat_gt_gross'  => $prev['prev_novat_gross']+$c['novat_gross'],
      'old_novat_gt_sale'   => $prev['prev_novat_sale'],
      'new_novat_gt_sale'   => $prev['prev_novat_sale']+$novat_sales,
    ]);

    return [
      trim(substr($this->sysinfo->tenantname, 0, 5)), //1 tenant code
      '0'.($this->sysinfo->pos_no+0), // 2 terminal no
      $date->format('mdY'), //3 trans date
      $this->zero($prev['prev_vat_sale']), // 4 old gt
      $this->zero($prev['prev_vat_sale']+$vat_sales), //5 new gt
      $this->zero($c['vat_gross']), //6 gross sales
      //number_format($c['grschrg'], 2,'.',''), 
      $this->zero($c['totdisc']), // 7 total deductions
      0, // 8 total promo sales amount
      $this->zero($c['totdisc']), // 9 total discount
      0, // 10 total refund amount
      0, // 11 total returned
      0, // 12 total other taxes
      0, // 13 total service charge
      0, // 14 total adjustment
      0, // 15 total void
      0, // 16 total discount cards
      0, // 17 total delivery charges
      0, // 18 total GC
      0, // 19 store specific discount 1
      0, // 20 store specific discount 2
      0, // 21 store specific discount 3
      0, // 22 store specific discount 4
      0, // 23 store specific discount 5
      0, // 24 total non-approved store discounts
      0, // 25 store specific discount 1
      0, // 26 store specific discount 2
      0, // 27 store specific discount 3
      0, // 28 store specific discount 4
      0, // 29 store specific discount 5
      $this->zero($vat), // 30 total vat/tax amount 
      //$this->zero($c['vat']), // total vat on salesmtd
      $this->zero($vat_sales), // 31 total net sales amount
      //$this->zero($c['sale']), //6 dailysales
      //$this->zero($c['vat_sale']), //6 dailysales
      $c['reg_cust'], // 32 total cover count
      $zread, // 33 control #
      $c['vat_trx'], // 34 total # of sales transactions
      '03', // 35 sales type
      $this->zero($vat_sales), // 36 amount

      $this->zero($prev['prev_novat_sale']), // 37 old novat gt
      $this->zero($prev['prev_novat_sale']+$novat_sales), //38 new novat gt
      $this->zero($c['novat_gross']), //39 gross novat sales
      $this->zero($c['sr_disc']), // 40 total deductions
      0, // 41 total promo sales amount
      $this->zero($c['sr_disc']), // 42 total senior/pwd discount
      0, // 43 total refund amount
      0, // 44 total returned
      0, // 45 total other taxes
      0, // 46 total service charge
      0, // 47 total adjustment
      0, // 48 total void
      0, // 49 total discount cards
      0, // 50 total delivery charges
      0, // 51 total GC
      0, // 52 store specific discount 1
      0, // 53 store specific discount 2
      0, // 54 store specific discount 3
      0, // 55 store specific discount 4
      0, // 56 store specific discount 5
      0, // 57 total non-approved store discounts
      0, // 58 store specific discount 1
      0, // 59 store specific discount 2
      0, // 60 store specific discount 3
      0, // 61 store specific discount 4
      0, // 62 store specific discount 5
      0, // 63 total vat (N/A)
      $this->zero($novat_sales), // 64 novat amount
      $this->zero($vat_sales+$novat_sales), // 65 vatable + non vatable


      /*
      str_pad($ext, 12, ' ', STR_PAD_LEFT), //3 // tenant no
      str_pad(number_format($c['grschrg'], 2,'.',''), 12, '0', STR_PAD_LEFT), //5 gross sales but tot charge/sales
      str_pad(number_format($c['vat'], 2,'.',''), 12, '0', STR_PAD_LEFT), //6 
      str_pad(number_format($c['vat_ex'], 2,'.',''), 12, '0', STR_PAD_LEFT), //7 non tax sales
      '000000000.00', //8  void
      '000000000000', //9   void cnt
      str_pad(number_format($c['totdisc'], 2,'.',''), 12, '0', STR_PAD_LEFT), //10 discount
      str_pad(number_format($c['disccnt'], 0,'.',''), 12, '0', STR_PAD_LEFT), //11 discount cnt
      '000000000.00', //12 refund
      '000000000000', //13 refund cnt
      str_pad(number_format($c['sr_disc'], 2,'.',''), 12, '0', STR_PAD_LEFT), //14 Sr Discount
      str_pad(number_format($c['sr_cnt'], 0,'.',''), 12, '0', STR_PAD_LEFT), //15 Sr Discount Cnt
      '000000000.00', //16 svc charge
      str_pad(number_format($c['sale_chrg'], 2,'.',''), 12, '0', STR_PAD_LEFT), //17 credit card sales
      str_pad(number_format($c['sale_cash'], 2,'.',''), 12, '0', STR_PAD_LEFT), //18 cash sales
      '000000000.00', //19 other sales
      str_pad(number_format($ctr-1, 0,'.',''), 12, '0', STR_PAD_LEFT), //20 prev Eod Ctr
      str_pad(number_format($this->sysinfo->grs_total, 2,'.',''), 12, '0', STR_PAD_LEFT), //21 Prev Grand Total
      str_pad(number_format($ctr, 0,'.',''), 12, '0', STR_PAD_LEFT), //22 Curr Eod Ctr
      str_pad(number_format($this->sysinfo->grs_total+$c['grschrg'], 2,'.',''), 12, '0', STR_PAD_LEFT), //23 Curr Grand Total
      str_pad(number_format($c['trancnt'], 0,'.',''), 12, '0', STR_PAD_LEFT), //24 No of Trans
      str_pad(number_format($c['begor'], 0,'.',''), 12, '0', STR_PAD_LEFT), //25 Beg Rcpt
      str_pad(number_format($c['endor'], 0,'.',''), 12, '0', STR_PAD_LEFT), //26 End Rcpt
      */
    ];
  }

  private function oclHourly($date, $c, $ext) {

    $ext = $this->sysinfo->zread_ctr>0
      ? $this->sysinfo->zread_ctr+1
      : 1;

    $filename = 'H'.substr($this->sysinfo->tenantname, 0, 5).'0'.($this->sysinfo->pos_no+0).$date->format('mdY');

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    mdir($dir);
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');

    foreach ([
      trim(substr($this->sysinfo->tenantname, 0, 5)), //1 tenant code
      '0'.($this->sysinfo->pos_no+0), // 2 terminal no
      $date->format('mdY'), //3 trans date
    ] as $line => $value) {
      
      $ln = str_pad($line+1, 2, '0', STR_PAD_LEFT);
      //$value = $ln.' '.$value; // on local
      $value = $ln.$value; // on productiom
      //$this->info('value: '.$value);      
       
      fwrite($fp, $value.PHP_EOL);
    }
  
    $hrly = $this->oclHourlyData($date, $c);

    $tot_sales = 0;
    $tot_trx = 0;
    $len = 4;
    foreach ($hrly as $key => $hr) {

      foreach ($hr as $k => $value) {
        //$n = ($len+$key+$k)+($len*$key);
        $n = str_pad($len+$k, 2, '0', STR_PAD_LEFT);
        //$this->info($n);
        //$this->info($n.' '.$hr[$k]);

        //$n = str_pad($line+1, 2, '0', STR_PAD_LEFT);
        //$value = $n.' '.$value; // on local
        $value = $n.$value; // on productiom
        fwrite($fp, $value.PHP_EOL);
        
        if ($k==1)
          $tot_sales += $hr[$k];
        if ($k==2)
          $tot_trx += $hr[$k];
      }
    }
    //$this->info('tot sales: '.$tot_sales);
    //$this->info('tot trx: '.$tot_trx);
    
    fwrite($fp, '08'.$this->zero($tot_sales).PHP_EOL);
    fwrite($fp, '09'.$this->zero($tot_trx));

    fclose($fp);

    $newfile = $this->out.DS.$filename.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);
  }

  private function oclHourlyData($date, $c) {

    $data = [];

    for ($i=1; $i <= 24; $i++) { 

      $k = str_pad($i, 2, '0', STR_PAD_LEFT);
      //$this->info($k);
      //$data[$i]['date'] = Carbon::parse($date->format('Y-m-d').' '.$k.'00:00');
      if (array_key_exists($k, $c['hrly'])) {
        $data[$i] = [
          $k=='00'?'24':$k,
          $this->zero($c['hrly'][$k]['sales']), //SALES
          number_format($c['hrly'][$k]['ctr'], 0,'.',''), // NO. OF TRX
          number_format($c['hrly'][$k]['cust'], 0,'.',''), //QUANTITY SOLD
          //number_format($s['hrly'][$k]['sr'], 0,'.',''), //QUANTITY SOLD
        ];
      } else {
         $data[$i] = [
          $k,
          $this->zero(0),
          '0',
          '0',
        ];
      }
    }

    return $data;
  }

  private function oclInvoice($date, $c, $ext) {
    
    $ext = $this->sysinfo->zread_ctr>0
      ? $this->sysinfo->zread_ctr+1
      : 1;

    $filename = 'I'.substr($this->sysinfo->tenantname, 0, 5).'0'.($this->sysinfo->pos_no+0).$date->format('mdY');

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    mdir($dir);
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');
    
    foreach ([
      trim(substr($this->sysinfo->tenantname, 0, 5)), //1 tenant code
      '01', // Sales type
      $date->format('mdY'), //3 trans date
      '0'.($this->sysinfo->pos_no+0), // 4 terminal no
    ] as $line => $value) {
      
      $ln = str_pad($line+1, 2, '0', STR_PAD_LEFT);
      //$value = $ln.' '.$value; // on local
      $value = $ln.$value; // on productiom
      //$this->info('value: '.$value);      
       
      fwrite($fp, $value.PHP_EOL);
    }

    if (count($c['inv'])>0){
      
      foreach ($c['inv'] as $key => $inv) {
        foreach ($inv as $k => $value) {
          $n = str_pad(5+$k, 2, '0', STR_PAD_LEFT);
          //$this->info($n.' '.$inv[$k]);

          //$n = str_pad($line+1, 2, '0', STR_PAD_LEFT);
          //$value = $n.' '.$value; // on local
          $value = $n.$value; // on productiom
          fwrite($fp, $value.PHP_EOL);
        }
      }
    } else
      $this->info('ERROR - No invoice found!');

    fclose($fp);

    $newfile = $this->out.DS.$filename.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);
  }

  private function oclGetPrev(Carbon $date) {
    $filename = $date->copy()->subDay()->format('Ymd');
    $dir = $this->getStoragePath().DS.$date->format('Y').DS.$date->format('m');
    $file = $dir.DS.$filename.'.json';
    alog('Getting previous data - OK');
    $a = [];
    $a['prev_vat_gross'] = 0;
    $a['prev_vat_sale'] = 0;
    $a['prev_novat_gross'] = 0;
    $a['prev_novat_sale'] = 0;

    if (file_exists($file)) {
      alog('Reading - '.$file);
      $json = json_decode(file_get_contents($file), true); 
      $a['prev_vat_gross'] = $json['new_vat_gt_gross'];
      $a['prev_vat_sale'] = $json['new_vat_gt_sale'];
      $a['prev_novat_gross'] = $json['new_novat_gt_gross'];
      $a['prev_novat_sale'] = $json['new_novat_gt_sale'];
    } else {
      alog($file.' not found!');
    }
    return $a;
  }

  private function oclCharges(Carbon $date) {
    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;
      
      $ds = [];
      $ds['hrly'] = [];
      $ds['inv'] = [];
      $ds['vat_gross'] = 0;
      $ds['vat_sale'] = 0;
      $ds['novat_gross'] = 0;
      $ds['novat_sale'] = 0;
      $ds['grschrg'] = 0;
      $ds['sale'] = 0;
      $ds['vat'] = 0;
      $ds['totdisc'] = 0;
      $ds['disccnt'] = 0;
      $ds['sr_disc'] = 0;
      $ds['sr_cnt'] = 0;
      $ds['snr_cust'] = 0;
      $ds['reg_cust'] = 0;
      $ds['cust'] = 0;
      $ds['sale_cash'] = 0;
      $ds['sale_chrg'] = 0;
      $ds['begor'] = NULL;
      $ds['endor'] = NULL;
      $ds['vat_in'] = 0; //tax sale
      $ds['vat_ex'] = 0; //no tax sale
      $ds['cnt_cash'] = 0;
      $ds['cnt_chrg'] = 0;
      $ds['trx_disc'] = 0;
      $ds['taxsale'] = 0;
      $ds['notaxsale'] = 0;
      $ds['taxexsale'] = 0;
      $ds['taxincsale'] = 0;
      $ds['trx_disc'] = 0;
      $ds['open'] = '';
      $ds['close'] = '';
      $ds['vat_trx'] = 0;
      $ds['novat_trx'] = 0;


      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $data = $this->associateAttributes($row);

          if (is_null($ds['begor'])) {
            $ds['begor'] = $data['cslipno'];
            try {
              $ds['open'] = Carbon::parse($data['orddate'].' '.$data['ordtime'])->format('YmdHis');
            } catch (Exception $e) {
              $ds['open'] = $data['orddate'].' '.$data['ordtime'];
            }
          }
          $ds['endor'] = $data['cslipno'];
          try {
            $ds['close'] = Carbon::parse($data['orddate'].' '.$data['ordtime'])->format('YmdHis');
          } catch (Exception $e) {
            $ds['close'] = $data['orddate'].' '.$data['ordtime'];
          }

          switch (strtolower($data['terms'])) {
            case 'cash':
              $ds['cnt_cash']++;
              break;
            case 'charge':
              $ds['cnt_chrg']++;
              break;
            default:
              break;
          }


          $ds['grschrg']  += $data['chrg_grs'];
          $ds['sale']  += $data['tot_chrg'];
          
          $disc = ($data['promo_amt'] + $data['oth_disc'] + $data['u_disc']);
          $ds['totdisc']  += $disc;
          if ($disc>0)
            $ds['disccnt']++;

          if ($data['dis_sr']>0) {
            $m = $data['vat_xmpt'] + $data['dis_sr'];
            $non_tax_sale = ($m / 0.285714286) - $m;
            $ds['vat_ex'] += $non_tax_sale;
          } 

          $ds['trx_disc'] += 0;
          $ds['notaxsale'] += 0;
          if ($data['dis_sr']>0) {
            //$ds['trx_disc'] += $data['vat_xmpt'];
            //$ds['taxsale'] += $data['chrg_grs']+$data['vat_xmpt'];
            $ds['notaxsale'] += $data['tot_chrg'];

            $ds['novat_gross'] += $data['grschrg'];
            $ds['novat_sale'] += $data['totchrg'];
            $ds['snr_cust'] += $data['sr_body'];
            $ds['novat_trx']++;
          } else {
            $ds['taxsale'] += $data['chrg_grs']-$ds['totdisc'];
            $ds['taxincsale'] += $data['chrg_grs']-$ds['totdisc'];

            $ds['vat']      += $data['vat'];
            
            $ds['vat_gross'] += $data['grschrg'];
            $ds['vat_sale'] += $data['totchrg'];
            $ds['reg_cust'] += $data['sr_tcust']-$data['sr_body'];
            $ds['vat_trx']++;
          }

          if ($data['sr_disc']>0) {
              $ds['sr_disc'] += $data['sr_disc'];
              $ds['sr_cnt'] ++;
          }

          if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
            // dont compute cust
          } else {
            $ds['cust']  += ($data['sr_body'] + $data['sr_tcust']);
          }

          if (strtolower($data['terms'])=='charge')
            $ds['sale_chrg'] += $data['tot_chrg'];
          else
            $ds['sale_cash'] += $data['tot_chrg'];



          /********* hourly ******************/
          $h = substr($data['ordtime'], 0, 2);
          if (array_key_exists($h, $ds['hrly'])) {
            $ds['hrly'][$h]['sales'] += $data['tot_chrg'];
            $ds['hrly'][$h]['ctr']++;

            if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
              // dont compute cust
            } else {
              $ds['hrly'][$h]['cust']  += ($data['sr_body'] + $data['sr_tcust']);
            }

            if ($data['dis_sr']>0)
              $ds['hrly'][$h]['sr'] += $data['sr_body'];
          } else {
            $ds['hrly'][$h]['sales'] = $data['tot_chrg'];
            $ds['hrly'][$h]['ctr'] = 1;
            
            if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
              $ds['hrly'][$h]['cust'] = 0;
            } else {
              $ds['hrly'][$h]['cust']  = ($data['sr_body'] + $data['sr_tcust']);
            }

            if ($data['dis_sr']>0)
              $ds['hrly'][$h]['sr'] = $data['sr_body'];
            else
              $ds['hrly'][$h]['sr'] = 0;
          }
          /********* end: hourly ******************/


          /********* invoices ******************/
          array_push($ds['inv'],[
            $data['cslipno'],
            $this->zero($data['tot_chrg']),
            '01'
          ]);
          /********* end: invoices ******************/

          
          $update++;
        }
      }
      $ds['vat_in'] = $ds['grschrg'] - $ds['vat_ex'];
      $ds['trancnt'] = $update;
      
      dbase_close($db);
      return $ds;
    } else {
      throw new Exception("Cannot locate CHARGES.DBF"); 
    }
  }

  /*********************************************************** end: OCL ****************************************/

  /*********************************************************** YIC ****************************************/
  public function YIC(Carbon $date, $ext) {
    $c = $this->yicCharges($date);
    //$this->info(print_r($c));
    //exit;
    $this->yicDaily($date, $c, $ext);
    $this->yicHourly($date, $c, $ext);
    $this->yicPayment($date, $c, $ext);
    $this->yicDiscount($date, $c, $ext);
    $this->yicCancelled($date, $c, $ext);
  }

  private function yicCancelled(Carbon $date, $s, $ext='csv') {

    $filename = substr($this->sysinfo->tenantcode, 0, 3).$date->format('jny').'R';
    //$dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
    mdir($dir);
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');

    $header = ['fdtTrnsctn', 'fvcMrchntCd', 'fvcRfndCncldCd', 'fvcRfndCncldRsn', 'fnmAmt', 'fnmCntDcmnt', 'fnmCntCstmr', 'fnmCntSnrCtzn'];
    if (strtolower($ext)=='csv')
      fputcsv($fp, $header);
    else
      fwrite($fp, $header);

    $rc = ['RFND', 'CNCLD'];

    foreach ($rc as $key => $value) {
      $data = [
        $date->format('Y-m-d'), 
        substr($this->sysinfo->tenantcode, 0, 3),
        $value, 
        '', 
        '0.000',
        0,
        0,
        0,
      ];

      if (strtolower($ext)=='csv')
        fputcsv($fp, $data);
      else
        fwrite($fp, $data);
    }
    
    fclose($fp);

    $newfile = $this->out.DS.$filename.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);
  }

  private function yicDiscount(Carbon $date, $s, $ext='csv') {

    if (count($s['disc'])>0) {

      $filename = substr($this->sysinfo->tenantcode, 0, 3).$date->format('jny').'D';
      //$dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
      $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
      mdir($dir);
      $file = $dir.DS.$filename.'.'.$ext;
      $fp = fopen($file, 'w');

      $header = ['fdtTrnsctn', 'fvcMrchntCd', 'fvcDscntCd', 'fvcDscntPrcntg', 'fnmDscnt', 'fnmCntDcmnt', 'fnmCntCstmr', 'fnmCntSnrCtzn'];
      if (strtolower($ext)=='csv')
        fputcsv($fp, $header);
      else
        fwrite($fp, $header);

     
      
      foreach ($s['disc'] as $key => $v) {

        $data = [
          $date->format('Y-m-d'), //TRANDATE
          substr($this->sysinfo->tenantcode, 0, 3),
          strtoupper($key), //DscntCd
          number_format($v['pct'], 4,'.',''), //DscntPrcntg
          number_format($v['amt'], 4,'.',''), //Dscnt
          number_format($v['cnt'], 0,'.',''), //CntDcmnt
          number_format($v['cust'], 0,'.',''), //CntCstmr
          number_format($v['snr'], 0,'.',''), //CntSnrCtzn
        ];

        if (strtolower($ext)=='csv')
          fputcsv($fp, $data);
        else
          fwrite($fp, $data);
      }
      fclose($fp);

      $newfile = $this->out.DS.$filename.'.'.$ext;

      $this->verifyCopyFile($file, $newfile);

    } else
      $this->info('No sales record found on SALESMTD.DBF');
  }

  private function yicPayment(Carbon $date, $s, $ext='csv') {

    if (count($s['payment'])>0) {

      $filename = substr($this->sysinfo->tenantcode, 0, 3).$date->format('jny').'P';
      //$dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
      $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
      $file = $dir.DS.$filename.'.'.$ext;
      mdir($dir);
      $fp = fopen($file, 'w');

      $header = ['fdtTrnsctn', 'fvcMrchntCd', 'fvcPymntCd', 'fvcPymntDsc', 'fvcPymntCdCLSCd', 'fvcPymntCdCLSDsc', 'fnmPymnt'];
      if (strtolower($ext)=='csv')
        fputcsv($fp, $header);
      else
        fwrite($fp, $header);
      
      foreach ($s['payment'] as $key => $v) {

        $data = [
          $date->format('Y-m-d'), //TRANDATE
          substr($this->sysinfo->tenantcode, 0, 3),
          $key=='card' ? 'CRD':'CSH', //PymntCd
          strtoupper($key), //PymntDsc
          $key=='card' ? 'CRD':'CSH', //PymntCdCLSCd
          strtoupper($key).' PAYMENT', //PymntCdCLSDsc
          number_format($v, 4,'.',''), //QUANTITY SOLD
        ];

        if (strtolower($ext)=='csv')
          fputcsv($fp, $data);
        else
          fwrite($fp, $data);
      }
      fclose($fp);

      $newfile = $this->out.DS.$filename.'.'.$ext;

      $this->verifyCopyFile($file, $newfile);

    } else
      $this->info('No sales record found on SALESMTD.DBF');
  }

  private function yicHourly(Carbon $date, $s, $ext='csv') {

    if (count($s['hrly'])>0) {

      $filename = substr($this->sysinfo->tenantcode, 0, 3).$date->format('jny').'H';
      //$dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
      $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
      mdir($dir);

      $file = $dir.DS.$filename.'.'.$ext;
      $fp = fopen($file, 'w');

      $header = ['fdtTrnsctn', 'fvcMrchntCd', 'fvcHRLCd', 'fnmDlySls', 'fnmCntDcmnt', 'fnmCntCstmr', 'fnmCntSnrCtzn'];
      if (strtolower($ext)=='csv')
        fputcsv($fp, $header);
      else
        fwrite($fp, $header);

      for ($i=0; $i <24 ; $i++) { 
        
        $k = str_pad($i, 2, '0', STR_PAD_LEFT);
        //$this->info($k);

        if (array_key_exists($k, $s['hrly'])) {
          $data = [
            $date->format('Y-m-d'), //TRANDATE
            substr($this->sysinfo->tenantcode, 0, 3),
            $k.':00', //HOUR
            number_format($s['hrly'][$k]['sales'], 4,'.',''), //SALES
            number_format($s['hrly'][$k]['ctr'], 0,'.',''), //QUANTITY SOLD
            number_format($s['hrly'][$k]['cust']-$s['hrly'][$k]['sr'], 0,'.',''), //QUANTITY SOLD
            number_format($s['hrly'][$k]['sr'], 0,'.',''), //QUANTITY SOLD
          ];
        } else {
          $data = [
            $date->format('Y-m-d'), //TRANDATE
            substr($this->sysinfo->tenantcode, 0, 3),
            $k.':00', //HOUR
            '0.0000', //SALES
            0, //QUANTITY SOLD
            0, //QUANTITY SOLD
            0, //QUANTITY SOLD
          ];
        }

        if (strtolower($ext)=='csv')
          fputcsv($fp, $data);
        else
          fwrite($fp, $data);

      }
      
      fclose($fp);

      $newfile = $this->out.DS.$filename.'.'.$ext;

      $this->verifyCopyFile($file, $newfile);

    } else
      $this->info('No sales record found on SALESMTD.DBF');
  }

  private function yicDaily($date, $c, $ext='csv') {

    $filename = substr($this->sysinfo->tenantcode, 0, 3).$date->format('jny').'S';
    //$dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
    mdir($dir);
   

    $data[0] = ['fdtTrnsctn', 'fvcMrchntCd', 'fvcMrcntDsc', 'fnmGrndTtlOld', 'fnmGrndTtlNew', 'fnmGTDlySls', 'fnmGTDscnt', 'fnmGTDscntSNR', 'fnmfnmGTDscntPWD', 'fnmGTDscntGPC', 'fnmGTDscntVIP', 'fnmGTDscntEMP', 'fnmGTDscntREG', 'fnmGTDscntOTH', 'fnmGTRfnd', 'fnmGTCncld', 'fnmGTSlsVAT', 'fnmGTVATSlsInclsv', 'fnmGTVATSlsExclsv', 'fnmOffclRcptBeg', 'fnmOffclRcptEnd', 'fnmGTCntDcmnt', 'fnmGTCntCstmr', 'fnmGTCntSnrCtzn', 'fnmGTLclTax', 'fnmGTSrvcChrg', 'fnmGTSlsNonVat', 'fnmGTRwGrss', 'fnmGtLclTaxDly', 'fcvWrksttnNmbr', 'fnmGTPymntCSH', 'fnmGTPymntCRD', 'fnmGTPymntOTH'];
    $data[1] = [
      $date->format('Y-m-d'), //DteTrnsctn
      substr($this->sysinfo->tenantcode, 0, 3), //MrchntCd
      substr(trim($this->sysinfo->tenantname), 0, 50), //MrchntDsc
      number_format($this->sysinfo->grs_total, 4,'.',''), //GrndTtlOld
      number_format($this->sysinfo->grs_total + $c['eod']['sale'], 4,'.',''), //GrndTtlNew
      number_format($c['eod']['sale'], 4,'.',''), //GTDlySls
      number_format($c['eod']['totdisc'], 4,'.',''), //GTDscnt
      number_format($c['eod']['dis_sr'], 4,'.',''), //GTDscntSNR
      number_format($c['eod']['dis_pwd'], 4,'.',''),
      number_format($c['eod']['dis_gpc'], 4,'.',''),
      number_format($c['eod']['dis_vip'], 4,'.',''),
      number_format($c['eod']['dis_emp'], 4,'.',''),
      number_format($c['eod']['dis_prom'], 4,'.',''),
      number_format($c['eod']['dis_udisc'], 4,'.',''),
      number_format(0 , 4,'.',''), //TOTREF
      number_format(0 , 4,'.',''), //TOTCAN
      number_format($c['eod']['vat'], 4,'.',''),  // VAT
      number_format($c['eod']['vat_in'], 4,'.',''), //GTVATSlsInclsv
      number_format($c['eod']['vat_ex'], 4,'.',''), //GTVATSlsExclsv
      $c['eod']['begor'],  //BEGINV
      $c['eod']['endor'], //ENDINV
      $c['eod']['trancnt'], //TRANCNT
      $c['eod']['cust']-$c['eod']['srcnt'], //TOTQTY
      $c['eod']['srcnt'], //TOTQTY
      number_format(0 , 4,'.',''), //GTLclTax
      number_format(0 , 4,'.',''), //GTSrvcChrg
      number_format(0 , 4,'.',''), //GTSlsNonVat
      number_format($c['eod']['grschrg'], 4,'.',''), //GTRwGrss
      number_format(0 , 4,'.',''), //GtLclTaxDly
      ($this->sysinfo->pos_no+0), //TERMINUM
      number_format($c['eod']['sale_cash'], 4,'.',''),
      number_format($c['eod']['sale_chrg'], 4,'.',''),
      number_format(0 , 4,'.',''), //GTPymntOTH
    ];

    if (strtolower($ext)=='csv')
      $this->toCSV($data, $date, $filename, $ext, $dir);
    else
      $this->toTXT($data, $date, $filename, $ext);

    $file = $dir.DS.$filename.'.'.$ext;

    $newfile = $this->out.DS.$filename.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);
  }

  private function yicCharges(Carbon $date) {

    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 1;

      $tot_cust = $sr_cust = 0;
      
      $ds = [];
      $ds['hrly'] = [];
      $ds['disc'] = [];
      $ds['payment']['cash'] = 0;
      $ds['payment']['card'] = 0;
      $ds['eod']['grschrg'] = 0;
      $ds['eod']['sale'] = 0;
      $ds['eod']['net'] = 0;
      $ds['eod']['vat_xmpt'] = 0;
      $ds['eod']['totdisc'] = 0;
      $ds['eod']['vat'] = 0;
      $ds['eod']['begor'] = NULL;
      $ds['eod']['endor'] = NULL;
      $ds['eod']['cust'] = 0;
      $ds['eod']['srcnt'] = 0;
      $ds['eod']['vat_ex'] = 0;
      $ds['eod']['vat_in'] = 0;
      $ds['eod']['srcnt'] = 0;

      $ds['eod']['dis_sr']      = 0;
      $ds['eod']['dis_pwd']     = 0;
      $ds['eod']['dis_gpc']     = 0;
      $ds['eod']['dis_vip']     = 0;
      $ds['eod']['dis_emp']     = 0;
      $ds['eod']['dis_prom']    = 0;
      $ds['eod']['dis_udisc']   = 0;
      $ds['eod']['sale_chrg']   = 0;
      $ds['eod']['sale_cash']   = 0;

      $ds['disc']['snr']['amt'] = 0;
      $ds['disc']['snr']['pct'] = 20;
      $ds['disc']['snr']['cust'] = 0;
      $ds['disc']['snr']['snr'] = 0;
      $ds['disc']['snr']['cnt'] = 0;

      $ds['disc']['gpc']['amt'] = 0;
      $ds['disc']['gpc']['pct'] = 0;
      $ds['disc']['gpc']['cust'] = 0;
      $ds['disc']['gpc']['snr'] = 0;
      $ds['disc']['gpc']['cnt'] = 0;

      $ds['disc']['pwd']['amt'] = 0;
      $ds['disc']['pwd']['pct'] = 20;
      $ds['disc']['pwd']['cust'] = 0;
      $ds['disc']['pwd']['snr'] = 0;
      $ds['disc']['pwd']['cnt'] = 0;

      $ds['disc']['vip']['amt'] = 0;
      $ds['disc']['vip']['pct'] = 10;
      $ds['disc']['vip']['cust'] = 0;
      $ds['disc']['vip']['snr'] = 0;
      $ds['disc']['vip']['cnt'] = 0;

      $ds['disc']['emp']['amt'] = 0;
      $ds['disc']['emp']['pct'] = 10;
      $ds['disc']['emp']['cust'] = 0;
      $ds['disc']['emp']['snr'] = 0;
      $ds['disc']['emp']['cnt'] = 0;

      $ds['disc']['reg']['amt'] = 0;
      $ds['disc']['reg']['pct'] = 0;
      $ds['disc']['reg']['cust'] = 0;
      $ds['disc']['reg']['snr'] = 0;
      $ds['disc']['reg']['cnt'] = 0;

      $ds['disc']['oth']['amt'] = 0;
      $ds['disc']['oth']['pct'] = 0;
      $ds['disc']['oth']['cust'] = 0;
      $ds['disc']['oth']['snr'] = 0;
      $ds['disc']['oth']['cnt'] = 0;

      for ($i=1; $i<=$record_numbers; $i++) {
        
        $row = dbase_get_record_with_names($db, $i);

        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $data = $this->associateAttributes($row);

          if (is_null($ds['eod']['begor']))
          $ds['eod']['begor'] = $data['cslipno'];
          $ds['eod']['endor'] = $data['cslipno'];

          $ds['eod']['grschrg']  += $data['chrg_grs'];
          //$ds['eod']['grschrg']  += $data['tot_chrg'];
          $ds['eod']['vat_xmpt'] += $data['vat_xmpt'];
          $ds['eod']['sale']     += $data['tot_chrg'];
          $ds['eod']['net']     += ($data['tot_chrg'] - $data['vat']);
          $ds['eod']['totdisc']  += ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);

          if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
            // dont compute cust

          } else {
            $ds['eod']['cust']  += ($data['sr_body'] + $data['sr_tcust']);
            //$ds['eod']['vat']      += $data['vat'];
          }

          if ($data['dis_sr']>0 && $data['sr_body']>0) {

          } else 
            $ds['eod']['vat']      += $data['vat'];


          if ($data['sr_disc']>0) {
            $ds['eod']['srcnt'] += $data['sr_body'];
            /*
            $m = $data['vat_xmpt'] + $data['dis_sr'];
            $non_tax_sale = ($m / 0.285714286) - $m;
            $ds['eod']['vat_ex'] += $non_tax_sale;
            */
            $ds['eod']['vat_ex'] += $data['tot_chrg'];
          } else {
            $sale_tax = $data['chrg_grs'] / 1.12;
            
            $ds['eod']['vat_in'] += $data['chrg_grs'] - ($data['promo_amt'] + $data['oth_disc'] + $data['u_disc']);
          }

          
          if ($data['sr_disc']>0) {
            $ds['eod']['dis_sr'] += $data['sr_disc'];
            if (array_key_exists('snr', $ds['disc'])){
              $ds['disc']['snr']['amt'] += $data['sr_disc'];
              $ds['disc']['snr']['cnt'] ++;
              $ds['disc']['snr']['snr'] += $data['sr_body'];
              $ds['disc']['snr']['cust']  += $data['sr_tcust'];
            } else {
              $ds['disc']['snr']['amt'] = $data['sr_disc'];
              $ds['disc']['snr']['cnt'] = 1;
              $ds['disc']['snr']['snr'] = $data['sr_body'];
              $ds['disc']['snr']['cust']  = $data['sr_tcust'];
            }
          }

          if ($data['dis_gpc']>0) {
            $ds['eod']['dis_gpc'] += $data['dis_gpc'];
            if (array_key_exists('gpc', $ds['disc'])){
              $ds['disc']['gpc']['amt'] += $data['dis_gpc'];
              $ds['disc']['gpc']['cust'] += $data['sr_tcust'];
              $ds['disc']['gpc']['snr'] += $data['sr_body'];
              $ds['disc']['gpc']['cnt'] ++;
            } else {
              $ds['disc']['gpc']['amt'] = $data['dis_gpc'];
              $ds['disc']['gpc']['cust'] = $data['sr_tcust'];
              $ds['disc']['gpc']['snr'] = $data['sr_body'];
              $ds['disc']['gpc']['cnt'] = 1;
            }
          }

          if ($data['dis_pwd']>0) {
            $ds['eod']['dis_pwd'] += $data['dis_pwd'];
            if (array_key_exists('pwd', $ds['disc'])) {
              $ds['disc']['pwd']['amt'] += $data['dis_pwd'];
              $ds['disc']['pwd']['cust'] += $data['sr_tcust'];
              $ds['disc']['pwd']['snr'] += $data['sr_body'];
              $ds['disc']['pwd']['cnt'] ++;
            } else {
              $ds['disc']['pwd']['amt'] = $data['dis_pwd'];
              $ds['disc']['pwd']['cust'] = $data['sr_tcust'];
              $ds['disc']['pwd']['snr'] = $data['sr_body'];
              $ds['disc']['pwd']['cnt'] = 1;
            }
          }

          if ($data['dis_vip']>0) {
            $ds['eod']['dis_vip'] += $data['dis_vip'];
            if (array_key_exists('vip', $ds['disc'])) {
              $ds['disc']['vip']['amt'] += $data['dis_vip'];
              $ds['disc']['vip']['cust'] += $data['sr_tcust'];
              $ds['disc']['vip']['snr'] += $data['sr_body'];
              $ds['disc']['vip']['cnt'] ++;
            } else {
              $ds['disc']['vip']['amt'] = $data['dis_vip'];
              $ds['disc']['vip']['cust'] = $data['sr_tcust'];
              $ds['disc']['vip']['snr'] = $data['sr_body'];
              $ds['disc']['vip']['cnt'] = 1;
            }
          }

          if ($data['dis_emp']>0) {
            $ds['eod']['dis_emp'] += $data['dis_emp'];
            if (array_key_exists('emp', $ds['disc'])) {
              $ds['disc']['emp']['amt'] += $data['dis_emp'];
              $ds['disc']['emp']['cust'] += $data['sr_tcust'];
              $ds['disc']['emp']['snr'] += $data['sr_body'];
              $ds['disc']['emp']['cnt'] ++;
            } else {
              $ds['disc']['emp']['amt'] = $data['dis_emp'];
              $ds['disc']['emp']['cust'] = $data['sr_tcust'];
              $ds['disc']['emp']['snr'] = $data['sr_body'];
              $ds['disc']['emp']['cnt'] = 1;
            }
          }

          if ($data['dis_prom']>0) {
            $ds['eod']['dis_prom'] += $data['dis_prom'];
            $tcust = $data['sr_tcust'] > 1 ? ($data['sr_tcust'] + $data['sr_body']) - $data['sr_body'] : $data['custcount'];
            if (array_key_exists('reg', $ds['disc'])) {
              $ds['disc']['reg']['amt'] += $data['dis_prom'];
              $ds['disc']['reg']['cust'] += $tcust;
              $ds['disc']['reg']['snr'] += $data['sr_body'];
              $ds['disc']['reg']['cnt'] ++;
            } else {
              $ds['disc']['reg']['amt'] = $data['dis_prom'];
              $ds['disc']['reg']['cust'] = $tcust;
              $ds['disc']['reg']['snr'] = $data['sr_body'];
              $ds['disc']['reg']['cnt'] = 1;
            }
          }

          if ($data['dis_udisc']>0) {
            $ds['eod']['dis_udisc'] += $data['dis_udisc'];
            $tcust = $data['sr_tcust'] > 1 ? ($data['sr_tcust'] + $data['sr_body']) - $data['sr_body'] : $data['custcount'];
            if (array_key_exists('oth', $ds['disc'])) {
              $ds['disc']['oth']['amt'] += $data['dis_udisc'];
              $ds['disc']['oth']['cust'] += $tcust;
              $ds['disc']['oth']['snr'] += $data['sr_body'];
              $ds['disc']['oth']['cnt'] ++;
            } else {
              $ds['disc']['oth']['amt'] = $data['dis_udisc'];
              $ds['disc']['oth']['cust'] = $tcust;
              $ds['disc']['oth']['snr'] = $data['sr_body'];
              $ds['disc']['oth']['cnt'] = 1;
            }
          }

          if (strtolower($data['terms'])=='charge') {
            $ds['eod']['sale_chrg'] += $data['tot_chrg'];
            $ds['payment']['card'] += $data['tot_chrg'];
          } else {
            $ds['eod']['sale_cash'] += $data['tot_chrg'];
            $ds['payment']['cash'] += $data['tot_chrg'];
          }

          //$h = str_pad(substr($data['ordtime'], 0, 2), 2, '0');
          $h = substr($data['ordtime'], 0, 2);
          if (array_key_exists($h, $ds['hrly'])) {
            $ds['hrly'][$h]['sales'] += $data['tot_chrg'];
            $ds['hrly'][$h]['ctr']++;
            /*
            if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
              // dont compute cust
            } else {
              $ds['hrly'][$h]['sr'] += $data['sr_body'];
              $ds['hrly'][$h]['cust']  += ($data['sr_body'] + $data['sr_tcust']);
            }
            */
            if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
              // dont compute cust
            } else {
              $ds['hrly'][$h]['cust']  += ($data['sr_body'] + $data['sr_tcust']);
            }

            if ($data['dis_sr']>0)
              $ds['hrly'][$h]['sr'] += $data['sr_body'];
          } else {
            $ds['hrly'][$h]['sales'] = $data['tot_chrg'];
            $ds['hrly'][$h]['ctr'] = 1;
            /*
            if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
              // dont compute cust
            } else {
              $ds['hrly'][$h]['sr'] = $data['sr_body'];
              $ds['hrly'][$h]['cust']  = ($data['sr_body'] + $data['sr_tcust']);
            }*/
            if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
              $ds['hrly'][$h]['cust'] = 0;
            } else {
              $ds['hrly'][$h]['cust']  = ($data['sr_body'] + $data['sr_tcust']);
            }

            if ($data['dis_sr']>0)
              $ds['hrly'][$h]['sr'] = $data['sr_body'];
            else
              $ds['hrly'][$h]['sr'] = 0;
          }



          if ($data['sr_body']>0 && $data['sr_disc']==0){
            //$this->info('Trx: '.$update.' '.$row['CSLIPNO'].' '.$data['sr_disc']);
          } else {
            $tot_cust += ($data['sr_tcust']-$data['sr_body']);
            $sr_cust  += $data['sr_body'];
            //$this->info('Trx: '.$update.' '.$row['CSLIPNO'].' '.$data['sr_disc'].' '.($data['sr_tcust']-$data['sr_body']).' '.$data['sr_body']);
          }

          // if ($data['sr_body']<0 && $data['sr_disc']!=0){
          //   $tot_cust += ($data['sr_tcust']-$data['sr_body']);
          //   $sr_cust  += $data['sr_body'];
          // }

          $update++;
          
        }
      }

      //$this->info('Total Customer: '.$tot_cust);
      //$this->info('Total Senior: '.$sr_cust);

      $ds['eod']['trancnt'] = $update;
      //$ds['eod']['vat_in'] = $ds['eod']['sale'] - $ds['eod']['vat_ex'];
      
      dbase_close($db);
      return $ds;
    } else {
      throw new Exception("Cannot locate CHARGES.DBF"); 
    }
  }
  /*********************************************************** end: YIC ****************************************/

  /*********************************************************** AOL ****************************************/
  public function AOL(Carbon $date, $ext) {
    $c = $this->aolCharges($date);
    //$this->aolDaily($date, $c);
    $this->aolDailyXml($date, $c);
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
      $this->info('OK - Generating: '.$file);
      alog('OK - Generating: '.$file);
    } else {
      $this->info('ERROR - Generating: '.$file);
      alog('ERROR - Generating: '.$file);
    }
  }

  private function getJsonData(Carbon $date) {
    $dir = $this->getStoragePath().DS.$date->format('Y').DS.$date->format('m');
    $filename = $date->format('Ymd');
    $file = $dir.DS.$filename.'.json';

    if (file_exists($file)) 
      return json_decode(file_get_contents($file), true);
    return NULL;
  }

  private function aolGetPrev(Carbon $date) {
    $prev_date = $date->copy()->subDay();
    $filename = $prev_date->format('Ymd');
    $dir = $this->getStoragePath().DS.$prev_date->format('Y').DS.$prev_date->format('m');
    $file = $dir.DS.$filename.'.json';
    alog('Getting previous data');

    if (file_exists($file)) {
      $this->info('OK - File: '.$file);
      alog('OK - File: '.$file);
    } else {
      $this->info('ERROR - File not exist: '.$file);
      alog('Error - File not exist: '.$file);
    }


    $a['zcounter'] = 0;
    $a['prev_gt'] = 0;
    $a['prev_gt'] = 0;
    $a['prev_tax'] = 0;
    $a['prev_vat_in'] = 0;
    $a['prev_vat_ex'] = 0;

    if (file_exists($file)) {
      alog('Reading - '.$file);
      $json = json_decode(file_get_contents($file), true); 
      $a['prev_gt'] = $json['nrgt'];
      $a['prev_tax'] = $json['newtax'];
      $a['prev_vat_in'] = $json['newtaxsale'];
      $a['prev_vat_ex'] = $json['newnotaxsale'];
      
      $a['zcounter'] = $json['zcounter'];
    } else {
      alog($file.' not found!');
    }
    return $a;
  }

  private function aolGetItem(Carbon $date, $cslipno, $disc_pct=0, $table_no) {
    $dbf_file = $this->extracted_path.DS.'SALESMTD.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);

      $arr = [];
      $items = [];
      $prod = [];
      $ctr = 0;

      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);

        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }

        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) { // if salesmtd date == backup date
          $data = $this->associateSalesmtd($row);

          //if ($data['cslipno']==$cslipno) {
          if ($data['cslipno']==$cslipno && ($data['tblno']==$table_no || strtolower($data['tblno'])=='zrmeal')) {

            if (empty($data['productcode'])) { // update:20190728
              if (empty($data['product']))
                $data['productcode'] = 'MISC';
              else
                $data['productcode'] = $data['product'];
            } // end:update:20190728

            if (strtolower($data['productcode'])=='zrmeal' && strtolower($data['tblno'])=='zrmeal') {
              
              $vat_exmp_sales = round($data['netamt']/1.12, 2);
              $vat_exmp = round($data['netamt']-$vat_exmp_sales, 2);
              $sr_disc = round($vat_exmp_sales*.2, 2);
              $tax = 0;

              $disc = 0;
              //$items[$ctr]['disc'] = '0.00';
              //$items[$ctr]['senior'] = $sr_disc;

              $net = $data['netamt'];//-$vat_exmp;//-($vat_exmp+$sr_disc);
              $uprice = $data['uprice'];//-$vat_exmp;
              $taxtype = 0;
            } else {
              $sr_disc = 0;

              if ($disc_pct>0) {
                $less = $data['netamt']*$disc_pct;
                $disc = $data['netamt']-$less;
              } else {
                $disc = 0;
              }


              $vat_exmp_sales = round($data['netamt']/1.12, 2);
              $tax = $data['netamt']-$vat_exmp_sales;

              $net = $data['netamt'];//-$disc;
              $uprice = $data['uprice'];
              $taxtype = 0;
            }

            if (!array_key_exists($data['productcode'], $prod)) {
              $prod[$data['productcode']] = [
                'sku' => $data['productcode'],
                'name' => $data['product'],
                'inventory' => 0,
                'price' =>  number_format($uprice, 2,'.',''),
                'category' => '01'
              ];
            }

            $items[$ctr] = [
              'sku' => $data['productcode'],
              'qty' => number_format($data['qty'], 2,'.',''),
              'unitprice' => number_format($uprice, 2,'.','')
            ];

            $items[$ctr]['disc']      = '0.00';//number_format($disc, 2,'.','');
            $items[$ctr]['senior']    = '0.00';//number_format($sr_disc, 2,'.','');
            $items[$ctr]['pwd']       = '0.00';
            $items[$ctr]['diplomat']  = '0.00';
            $items[$ctr]['taxtype']   = $taxtype;
            $items[$ctr]['tax']       = number_format($tax, 2,'.','');
            $items[$ctr]['memo']      = empty($data['remarks']) ? '':$data['remarks'];
            $items[$ctr]['total']     = number_format($net, 2,'.','');

            $ctr++;
          }
        }
      }
      
      $arr['items'] = $items;
      $arr['prods'] = $prod;

      return $arr;
    }

  }

  private function aolGetTrans(Carbon $date) {
    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;
      
      $arr = [];
      $arr['trx'] = [];
      $arr['product'] = [];
      $arr['balance'] = [];
      $inv = [];
      $inv2 = [];
      $ctr = 0;

      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $data = $this->associateAttributes($row);

          $cash = strtolower($data['terms'])=='cash' ? number_format($data['tot_chrg'], 2,'.','') : '0.00';
          $chrg = strtolower($data['terms'])=='charge' ? number_format($data['tot_chrg'], 2,'.','') : '0.00';
          $linesenior = $data['dis_sr']>0 ? 1 : 0;

          $arr['trx'][$ctr] = [
            'receiptno'     => $data['cslipno'],
            'void'          => 0,
            'cash'          => $cash,
            'credit'        => $chrg,
            'charge'        => '0.00',
            'giftcheck'     => '0.00',
            'othertender'   => '0.00',
            'linedisc'      => '0.00',
            'linesenior'    => '0.00',
            //'linedisc'      => number_format($data['totdisc'], 2,'.',''),
            //'linesenior'    => number_format($data['dis_sr'], 2,'.',''),
            'evat'          => '0.00',
            'linepwd'       => '0.00',
            'linediplomat'  => '0.00',
            'subtotal'      => number_format($data['subtotal'], 2,'.',''),
            'disc'          => number_format($data['totdisc'], 2,'.',''),
            'senior'        => number_format($data['dis_sr'], 2,'.',''),
            'pwd'           => '0.00',
            'diplomat'      => '0.00',
            'vat'           => number_format($data['aol_trx_vat'], 2,'.',''),
            'exvat'         => '0.00',
            'incvat'        => number_format($data['aol_trx_vat'], 2,'.',''),
            'localtax'      => '0.00',
            'amusement'     => '0.00',
            'service'       => '0.00',
            'taxsale'       => number_format($data['taxsale'], 2,'.',''),
            'notaxsale'     => number_format($data['notaxsale'], 2,'.',''),
            'taxexsale'     => number_format($data['taxexsale'], 2,'.',''),
            'taxincsale'    => number_format($data['taxincsale'], 2,'.',''),
            'zerosale'      => '0.00',
            'customercount' => number_format($data['custcount'], 0,'.',''),
            'gross'         => number_format($data['gross'], 2,'.',''),
            'refund'        => '0.00',
            'taxrate'       => '12.00',
            'posted'        => $data['vfpdate']->format('YmdHis'),
            'memo'          => ' ',
          ];

          $disc_pct = $data['totdisc']>0 ? (($data['chrg_grs']-$data['totdisc'])/$data['chrg_grs']) : 0 ;
          $items = $this->aolGetItem($date, $data['cslipno'], $disc_pct, $data['tblno']);

          foreach ($items['prods'] as $key => $prod) {
            if (!array_key_exists($key, $inv)) 
              $inv[$key] = $prod;
          }

          foreach ($items['items'] as $key => $prod) {
            if (!array_key_exists($prod['sku'], $inv2)) 
              $inv2[$prod['sku']] = ($prod['qty']+0);
            else
              $inv2[$prod['sku']] += ($prod['qty']+0);
            
          }


          $arr['trx'][$ctr]['line'] = $items['items'];


          $ctr++;
        }
      }

      $ctr=0;
      foreach ($inv as $k => $value) {
        $arr['product'][$ctr] = $value;
        $ctr++;
      }

      $ctr=0;
      foreach ($inv2 as $k => $value) {
        $arr['balance'][$ctr] = [
          'sku' => $k,
          'qty' => '-'.$value,
          'sold' => $value,
          'adjusted' => 0,
        ];
        $ctr++;
      }

      return $arr;
    }
  }

  private function aolDailyXml(Carbon $date, $c) {
    
    $j = $this->getJsonData($date);
    if (is_null($j)) {
      $p = $this->aolGetPrev($date);
      if (is_null($p)) {
         $ctr = $this->sysinfo->zread_ctr>0
        ? $this->sysinfo->zread_ctr+1
        : 1;
      } else
        $ctr = $p['zcounter']+1;
    } else
      $ctr = $j['zcounter'];

    $pos_no = str_pad($this->sysinfo->pos_no, 4, '0', STR_PAD_LEFT);
    $zread = str_pad($ctr, 5, '0', STR_PAD_LEFT);//'0000'.$ctr;
    $f_tenantid = trim($this->sysinfo->tenantname);
   
    $filename = 'sales_'.$f_tenantid.'_'.$pos_no.'_'.$zread;

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    mdir($dir);
    $file = $dir.DS.$filename.'.xml';

    $id = [
      'tenantid'  => $f_tenantid,//19010883
      'key'       => 'ROWZWNLI',//'D15403MN',
      'tmid'      => $pos_no,
      'doc'       => 'SALES_EOD'
    ];

    $sales = $this->aolGenHeader($date, $c, $ctr);

    $this->toJson($date, $sales);
    
    $trans = $this->aolGetTrans($date);

    $sales['trx'] = $trans['trx'];
    $product['product'] = $trans['product'];

    foreach ($sales['trx'] as $key => $trx)
      $this->aolReceiptXml($trx['receiptno']);

    $this->aolInv($date, $trans);

    $root = [
      'id' => $id,
      'sales' => $sales,
      'master' => $product
    ];

    $result = ArrayToXml::convert($root);
    $fp = fopen($file, 'w');

    fwrite($fp, $result);

    fclose($fp);

    $newfile = $this->out.DS.$filename.'.xml';

    $this->verifyCopyFile($file, $newfile);
  }

  private function aolGenHeader($date, $c, $ctr) {
    $prev = $this->aolGetPrev($date);
    return [
      'date'              => $date->format('Ymd'),
      'zcounter'          => $ctr,
      'previousnrgt'      => number_format($prev['prev_gt'], 2,'.',''),
      'nrgt'              => number_format($prev['prev_gt']+$c['sale'], 2,'.',''),
      'previoustax'       => number_format($prev['prev_tax'], 2,'.',''),
      'newtax'            => number_format($prev['prev_tax']+$c['vat'], 2,'.',''),
      'previoustaxsale'   => number_format($prev['prev_vat_in'], 2,'.',''),
      'newtaxsale'        => number_format($prev['prev_vat_in']+$c['taxsale'], 2,'.',''),
      'previousnotaxsale' => number_format($prev['prev_vat_ex'], 2,'.',''),
      'newnotaxsale'      => number_format($prev['prev_vat_ex']+$c['notaxsale'], 2,'.',''),
      'opentime'          => $c['open'],
      'closetime'         => $c['close'],
      'gross'             => number_format($c['grschrg'], 2,'.',''),
      'vat'               => number_format($c['vat'], 2,'.',''),
      'localtax'          => '0.00',
      'amusement'         => '0.00',
      'taxsale'           => number_format($c['taxsale'], 2,'.',''),
      'notaxsale'         => number_format($c['notaxsale'], 2,'.',''),
      'zerosale'          => '0.00',
      'void'              => '0.00',
      'voidcnt'           => '0.00',
      'disc'              => number_format($c['totdisc'], 2,'.',''),
      'disccnt'           => number_format($c['disccnt'], 0,'.',''),
      'refund'            => '0.00',
      'refundcnt'         => '0',
      'senior'            => number_format($c['sr_disc'], 2,'.',''),
      'seniorcnt'         => number_format($c['sr_cnt'], 0,'.',''),
      'pwd'               => '0.00',
      'pwdcnt'            => '0',
      'diplomat'          => '0.00',
      'diplomatcnt'       => '0',
      'service'           => '0.00',
      'servicecnt'        => '0',
      'receiptstart'      => $c['begor'],
      'receiptend'        => $c['endor'],
      'trxcnt'            => number_format($c['trancnt'], 0,'.',''),
      'cash'              => number_format($c['sale_cash'], 2,'.',''),
      'cashcnt'           => number_format($c['cnt_cash'], 0,'.',''),
      'credit'            => number_format($c['sale_chrg'], 2,'.',''),
      'creditcnt'         => number_format($c['cnt_chrg'], 0,'.',''),
      'charge'            => '0.00',
      'chargecnt'         => '0',
      'giftcheck'         => '0.00',
      'giftcheckcnt'      => '0',
      'othertender'       => '0.00',
      'othertendercnt'    => '0',
    ];
  }

  private function aolReceiptXml($cslipno) {
    $ctr = $this->sysinfo->zread_ctr>0
      ? $this->sysinfo->zread_ctr+2
      : 2;

    $pos_no = str_pad($this->sysinfo->pos_no, 4, '0', STR_PAD_LEFT);
    $f_tenantid = trim($this->sysinfo->tenantname);
   

    $id = [
      'tenantid'  => $f_tenantid,//19010883
      'key'       => 'ROWZWNLI',//'D15403MN',
      'tmid'      => $pos_no,
      'doc'       => 'SALES_PREEOD'
    ];

    $prods = [];
    
    $trans = $this->aolGetTransReceipt($cslipno);

    $date = $trans['date'];

    $filename = 'sales_preeod_'.$f_tenantid.'_'.$pos_no.'_'.$date->format('YmdHis');

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    mdir($dir);
    $file = $dir.DS.$filename.'.xml';
    
    $sales['date'] = $date->format('Ymd');
    $sales['trx'] = $trans['trx'];
    $product['product'] = $trans['product'];

    $root = [
      'id' => $id,
      'sales' => $sales,
      'master' => $product
    ];

    $result = ArrayToXml::convert($root);
    $fp = fopen($file, 'w');

    fwrite($fp, $result);

    fclose($fp);

    $newfile = $this->out.DS.$filename.'.xml';

    $this->verifyCopyFile($file, $newfile);
  }

  private function aolGetTransReceipt($cslipno) {
    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;
      
      $arr = [];
      $arr['trx'] = [];
      $arr['date'] = '';
      $arr['product'] = [];
      $inv = [];
      $ctr = 0;

      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        
        if (trim($row['CSLIPNO'])==$cslipno) {

          try {
            $date = vfpdate_to_carbon(trim($row['ORDDATE']));
          } catch(Exception $e) {
            continue;
          }

          //$arr['date'] = $date->format('Ymd');

          $data = $this->associateAttributes($row);

          $cash = strtolower($data['terms'])=='cash' ? number_format($data['tot_chrg'], 2,'.','') : '0.00';
          $chrg = strtolower($data['terms'])=='charge' ? number_format($data['tot_chrg'], 2,'.','') : '0.00';
          $date_for_sr_rcpt = $data['dis_sr']>0 ? $data['vfpdate']->addSecond() : $data['vfpdate'];
          $linesenior = $data['dis_sr']>0 ? 1 : 0;
          $linedisc = $data['totdisc']>0 ? 1 : 0;

          $arr['date'] =  $date_for_sr_rcpt;
          $arr['trx'] = [
            'receiptno'     => $data['cslipno'],
            'date'          => $date->format('Ymd'),
            'void'          => 0,
            'cash'          => $cash,
            'credit'        => $chrg,
            'charge'        => '0.00',
            'giftcheck'     => '0.00',
            'othertender'   => '0.00',
            //'linedisc'      => number_format($data['totdisc'], 2,'.',''),
            'linedisc'      => '0.00',
            //'linesenior'    => number_format($data['dis_sr'], 2,'.',''),
            'linesenior'    => '0.00',
            'evat'          => '0.00',
            'linepwd'       => '0.00',
            'linediplomat'  => '0.00',
            'subtotal'      => number_format($data['subtotal'], 2,'.',''),
            'disc'          => number_format($data['totdisc'], 2,'.',''),
            'senior'        => number_format($data['dis_sr'], 2,'.',''),
            'pwd'           => '0.00',
            'diplomat'      => '0.00',
            'vat'           => number_format($data['aol_trx_vat'], 2,'.',''),
            'exvat'         => '0.00',
            'incvat'        => number_format($data['aol_trx_vat'], 2,'.',''),
            'localtax'      => '0.00',
            'amusement'     => '0.00',
            'service'       => '0.00',
            'taxsale'       => number_format($data['taxsale'], 2,'.',''),
            'notaxsale'     => number_format($data['notaxsale'], 2,'.',''),
            'taxexsale'     => number_format($data['taxexsale'], 2,'.',''),
            'taxincsale'    => number_format($data['taxincsale'], 2,'.',''),
            'zerosale'      => '0.00',
            'customercount' => number_format($data['custcount'], 0,'.',''),
            'gross'         => number_format($data['gross'], 2,'.',''),
            'refund'        => '0.00',
            'taxrate'       => '12.00',
            'posted'        => $data['vfpdate']->format('YmdHis'),
            'memo'          => '',
          ];

          $disc_pct = $data['totdisc']>0 ? (($data['chrg_grs']-$data['totdisc'])/$data['chrg_grs']) : 0 ;
          $items = $this->aolGetItem($date, $data['cslipno'], $disc_pct, $data['tblno']);

          foreach ($items['prods'] as $key => $prod) {
            if (!array_key_exists($key, $inv)) {
              $inv[$key] = $prod;
            }
          }

          $arr['trx']['line'] = $items['items'];


          $ctr++;
        }
      }

      $ctr=0;
      foreach ($inv as $k => $value) {
        $arr['product'][$ctr] = $value;
        $ctr++;
      }

      return $arr;
    }
  }

  private function aolInv(Carbon $date, $trans) {

    $tenantid = trim($this->sysinfo->tenantname);
   
    $filename = 'inv_'.$tenantid.'_'.$date->format('Ymd');

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    mdir($dir);
    $file = $dir.DS.$filename.'.xml';

    $id = [
      'tenantid'  => $tenantid,//19010883
      'key'       => 'ROWZWNLI',//'D15403MN',
      'date'      => $date->format('Ymd'),
      'doc'       => 'INVENTORY'
    ];



    $inventory['balance'] = $trans['balance'];
    $prods['product'] = $trans['product'];

    $root = [
      'id' => $id,
      'inventory' => $inventory,
      'master' => $prods
    ];

    $result = ArrayToXml::convert($root);
    $fp = fopen($file, 'w');

    fwrite($fp, $result);

    fclose($fp);

    $newfile = $this->out.DS.$filename.'.xml';

    $this->verifyCopyFile($file, $newfile);
  }

  private function aolDaily(Carbon $date, $c) {

    $ctr = $this->sysinfo->zread_ctr>0
      ? $this->sysinfo->zread_ctr+2
      : 2;

    $ext = str_pad($this->sysinfo->pos_no, 3, '0', STR_PAD_LEFT);
    $filename = str_pad($ctr, 4, '0', STR_PAD_LEFT).$date->format('md');
   
    //$this->info(' ');

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
    if(!is_dir($dir))
        mkdir($dir, 0775, true);
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');

   


    $data = [
      str_pad('OUTLETSLIPA', 12, ' ', STR_PAD_LEFT),  //1 mall id
      str_pad(trim($this->sysinfo->tenantname), 12, ' ', STR_PAD_LEFT), //2 tenant id
      str_pad($ext, 12, ' ', STR_PAD_LEFT), //3 // tenant no
      str_pad($date->format('Y-m-d'), 12, ' ', STR_PAD_LEFT), //4 trans date
      str_pad(number_format($c['grschrg'], 2,'.',''), 12, '0', STR_PAD_LEFT), //5 gross sales but tot charge/sales
      str_pad(number_format($c['vat'], 2,'.',''), 12, '0', STR_PAD_LEFT), //6 
      str_pad(number_format($c['vat_ex'], 2,'.',''), 12, '0', STR_PAD_LEFT), //7 non tax sales
      '000000000.00', //8  void
      '000000000000', //9   void cnt
      str_pad(number_format($c['totdisc'], 2,'.',''), 12, '0', STR_PAD_LEFT), //10 discount
      str_pad(number_format($c['disccnt'], 0,'.',''), 12, '0', STR_PAD_LEFT), //11 discount cnt
      '000000000.00', //12 refund
      '000000000000', //13 refund cnt
      str_pad(number_format($c['sr_disc'], 2,'.',''), 12, '0', STR_PAD_LEFT), //14 Sr Discount
      str_pad(number_format($c['sr_cnt'], 0,'.',''), 12, '0', STR_PAD_LEFT), //15 Sr Discount Cnt
      '000000000.00', //16 svc charge
      str_pad(number_format($c['sale_chrg'], 2,'.',''), 12, '0', STR_PAD_LEFT), //17 credit card sales
      str_pad(number_format($c['sale_cash'], 2,'.',''), 12, '0', STR_PAD_LEFT), //18 cash sales
      '000000000.00', //19 other sales
      str_pad(number_format($ctr-1, 0,'.',''), 12, '0', STR_PAD_LEFT), //20 prev Eod Ctr
      str_pad(number_format($this->sysinfo->grs_total, 2,'.',''), 12, '0', STR_PAD_LEFT), //21 Prev Grand Total
      str_pad(number_format($ctr, 0,'.',''), 12, '0', STR_PAD_LEFT), //22 Curr Eod Ctr
      str_pad(number_format($this->sysinfo->grs_total+$c['grschrg'], 2,'.',''), 12, '0', STR_PAD_LEFT), //23 Curr Grand Total
      str_pad(number_format($c['trancnt'], 0,'.',''), 12, '0', STR_PAD_LEFT), //24 No of Trans
      str_pad(number_format($c['begor'], 0,'.',''), 12, '0', STR_PAD_LEFT), //25 Beg Rcpt
      str_pad(number_format($c['endor'], 0,'.',''), 12, '0', STR_PAD_LEFT), //26 End Rcpt
    ];

    $final = [];

    foreach ($data as $line => $value) {
      //$this->info($value);
      $sum = 0;

      $ln = str_pad($line+1, 2, '0', STR_PAD_LEFT);
      foreach (str_split($ln) as $key => $char)  {
        $ascii = ord($char);
        //$this->info($char.' = '.$ascii);
        $sum += $ascii;
      }

      foreach (str_split($value) as $key => $char) {
        $ascii = ord($char);
        //$this->info($char.' = '.$ascii);
        $sum += $ascii;
      }
     // $this->info('sum: '.$sum);
      $mod = $sum % 10;
      //$this->info('modulo: '.$mod);
      $parity = $mod % 2;
     //$this->info('parity: '.$parity);

      $value = $ln.$mod.$parity.$value;
      //$this->info('value: '.$value);
      //$this->info($value);

      $final[$line] = $value;

      if (count($data)==($line+1))
        fwrite($fp, $value);
      else
        fwrite($fp, $value.PHP_EOL);

    }
    fclose($fp);

    //$this->info(' ');
    if (file_exists($file)) {
      $this->info($file.' - Daily OK');
      alog($file.' - Daily OK');
    } else {
      $this->info($file.' - Error on generating');
      alog($file.' - Error on generating');
    }

    return $final;
  }

  private function aolCharges(Carbon $date) {
    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;
      
      $ds = [];
      $ds['grschrg'] = 0;
      $ds['sale'] = 0;
      $ds['vat'] = 0;
      $ds['totdisc'] = 0;
      $ds['disccnt'] = 0;
      $ds['sr_disc'] = 0;
      $ds['sr_cnt'] = 0;
      $ds['cust'] = 0;
      $ds['sale_cash'] = 0;
      $ds['sale_chrg'] = 0;
      $ds['begor'] = NULL;
      $ds['endor'] = NULL;
      $ds['vat_ex'] = 0;
      $ds['vat_in'] = 0;
      $ds['cnt_cash'] = 0;
      $ds['cnt_chrg'] = 0;
      $ds['trx_disc'] = 0;
      $ds['taxsale'] = 0;
      $ds['notaxsale'] = 0;
      $ds['taxexsale'] = 0;
      $ds['taxincsale'] = 0;
      $ds['trx_disc'] = 0;
      $ds['open'] = '';
      $ds['close'] = '';

      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $data = $this->associateAttributes($row);

          if (is_null($ds['begor'])) {
            $ds['begor'] = $data['cslipno'];
            try {
              $ds['open'] = Carbon::parse($data['orddate'].' '.$data['ordtime'])->format('YmdHis');
            } catch (Exception $e) {
              $ds['open'] = $data['orddate'].' '.$data['ordtime'];
            }
          }
          $ds['endor'] = $data['cslipno'];
          try {
            $ds['close'] = Carbon::parse($data['orddate'].' '.$data['ordtime'])->format('YmdHis');
          } catch (Exception $e) {
            $ds['close'] = $data['orddate'].' '.$data['ordtime'];
          }

          switch (strtolower($data['terms'])) {
            case 'cash':
              $ds['cnt_cash']++;
              break;
            case 'charge':
              $ds['cnt_chrg']++;
              break;
            default:
              break;
          }


          $ds['sale']  += $data['tot_chrg'];
          
          $disc = ($data['promo_amt'] + $data['oth_disc'] + $data['u_disc']);
          $ds['totdisc']  += $disc;
          if ($disc>0)
            $ds['disccnt']++;

          if ($data['dis_sr']>0) {
            $m = $data['vat_xmpt'] + $data['dis_sr'];
            $non_tax_sale = ($m / 0.285714286) - $m;
            $ds['vat_ex'] += $non_tax_sale;
          } 

          $ds['trx_disc'] += 0;
          $ds['notaxsale'] += 0;
          if ($data['dis_sr']>0) {
            //$ds['trx_disc'] += $data['vat_xmpt'];
            //$ds['taxsale'] += $data['chrg_grs']+$data['vat_xmpt'];
            $ds['notaxsale'] += $data['tot_chrg'];
            $ds['grschrg']  += $data['tot_chrg'];
          } else {
            $ds['grschrg']  += $data['tot_chrg'];
            #$ds['taxsale'] += $data['chrg_grs']-$ds['totdisc'];
            $ds['taxsale'] += $data['tot_chrg'];
            $ds['taxincsale'] += $data['chrg_grs']-$ds['totdisc'];

            $ds['vat']      += $data['vat'];
          }

          if ($data['dis_sr']>0) {
              $ds['sr_disc'] += $data['dis_sr'];
              $ds['sr_cnt'] ++;
          }

          if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
            // dont compute cust
          } else {
            $ds['cust']  += ($data['sr_body'] + $data['sr_tcust']);
          }

          if (strtolower($data['terms'])=='charge')
            $ds['sale_chrg'] += $data['tot_chrg'];
          else
            $ds['sale_cash'] += $data['tot_chrg'];
          
          $update++;
        }
      }
      $ds['vat_in'] = $ds['grschrg'] - $ds['vat_ex'];
      $ds['trancnt'] = $update;
      
      dbase_close($db);
      return $ds;
    } else {
      throw new Exception("Cannot locate CHARGES.DBF"); 
    }
  }
  /*********************************************************** End: AOL ****************************************/


  /*********************************************************** PRO ****************************************/
  public function PRO(Carbon $date, $ext) {

    $c = $this->proCharges($date);
    $s = $this->proSalesmtd($date);

    $this->proDaily($date, $c, $s, $ext);
    $this->proHourly($date, $s, $ext);
  }

  private function proDaily($date, $c, $s, $ext='CSV') {

    $filename = substr($this->sysinfo->tenantname, 0, 3).($this->sysinfo->pos_no+0).$date->format('mdy');

    $data[0] = ['TRANDATE', 'OLDGT', 'NEWGT', 'DLYSALE', 'TOTDISC', 'TOTREF', 'TOTCAN', 'VAT', 'TENTNME', 'BEGINV', 'ENDINV', 'BEGOR', 'ENDOR', 'TRANCNT', 'TOTQTY', 'SALETAX', 'SERVCHARGE', 'NOTAXSALE', 'OTHERS1', 'OTHERS2', 'OTHERS3', 'TERMINUM'];
    $data[1] = [
      $date->format('Ymd'), //TRANDATE
      number_format($this->sysinfo->grs_total, 2,'.',''), //OLDGT
      number_format($this->sysinfo->grs_total + $c['eod']['sale'], 2,'.',''), //NEWGT
      number_format($c['eod']['sale'], 2,'.',''), //DLYSALE
      number_format($c['eod']['totdisc'], 2,'.',''), //TOTDISC
      0.00, //TOTREF
      0.00, //TOTCAN
      number_format($c['eod']['vat'], 2,'.',''),  // VAT
      $this->sysinfo->tenantname, //TENTNME
      $c['eod']['begor'],  //BEGINV
      $c['eod']['endor'], //ENDINV
      $c['eod']['begor'], //BEGOR
      $c['eod']['endor'], //ENDOR
      $c['eod']['trancnt'], //TRANCNT
      $s['eod']['totqty'], //TOTQTY
      $c['eod']['saletax'], //SALETAX
      0.00, //SERVCHARGE
      $c['eod']['notaxsale'], //NOTAXSALE
      0.00, //OTHERS1
      0.00, //OTHERS2
      0.00, //OTHERS3
      $this->sysinfo->pos_no //TERMINUM
    ];

    if (strtolower($ext)=='csv')
      $this->toCSV($data, $date, $filename, $ext);
    else
      $this->toTXT($data, $date, $filename, $ext);

    $f = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$filename.'.'.$ext;
    if (file_exists($f)) {
      $this->info($f.' - Daily OK');
      alog($f.' - Daily OK');
      return true;
    } else {
      $this->info($f.' - Error on generating');
      alog($f.' - Error on generating');
      return false;
    }
  }

  private function proHourly(Carbon $date, $s, $ext='CSV') {

    if (count($s['hrly'])>0) {

      $filename = substr($this->sysinfo->tenantname, 0, 3).($this->sysinfo->pos_no+0).$date->format('md').'H';

      $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
      mdir($dir);
      $file = $dir.DS.$filename.'.'.$ext;
      $fp = fopen($file, 'w');

      $header = ['TRANDATE', 'HOUR', 'SALES', 'QUANTITY SOLD', 'TENTNME', 'TERMNUM'];
      if (strtolower($ext)=='csv')
        fputcsv($fp, $header);
      else
        fwrite($fp, $header);
      
      foreach ($s['hrly'] as $key => $v) {

        $data = [
          $date->format('Ymd'), //TRANDATE
          $key.':00', //HOUR
          number_format($v['sales'], 2,'.',''), //SALES
          number_format($v['qty'], 2,'.',''), //QUANTITY SOLD
          $this->sysinfo->tenantname, //TENTNME
          $this->sysinfo->pos_no //TERMINUM
        ];

        if (strtolower($ext)=='csv')
          fputcsv($fp, $data);
        else
          fwrite($fp, $data);
      }
      fclose($fp);

      $f = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$filename.'.'.$ext;
      if (file_exists($f)) {
        $this->info($f.' - Hourly OK');
        alog($f.' - Hourly OK');
      } else {
        $this->info($f.' - Error on generating');
        alog($f.' - Error on generating');
      }


    } else
      $this->info('No sales record found on SALESMTD.DBF');
  }

  private function proHourlyOld(Carbon $date, $s, $ext='CSV') {

    if (count($s['hrly'])>0) {
      foreach ($s['hrly'] as $key => $v) {

        $filename = substr($this->sysinfo->tenantname, 0, 3).$this->sysinfo->pos_no.$date->format('my').$key;

        $data[0] = ['TRANDATE', 'HOUR', 'SALES', 'QUANTITY SOLD', 'TENTNME', 'TERMINUM'];
        $data[1] = [
          $date->format('Ymd'), //TRANDATE
          $key.':00', //HOUR
          number_format($v['sales'], 2,'.',''), //SALES
          number_format($v['qty'], 2,'.',''), //QUANTITY SOLD
          $this->sysinfo->tenantname, //TENTNME
          $this->sysinfo->pos_no //TERMINUM
        ];

        if (strtolower($ext)=='csv')
          $this->toCSV($data, $date, $filename, $ext);
        else
          $this->toTXT($data, $date, $filename, $ext);

        $f = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$filename.'.'.$ext;
        if (file_exists($f)) {
          $this->info($f.' - Hourly OK');
          alog($f.' - Hourly OK');
        } else {
          $this->info($f.' - Error on generating');
          alog($f.' - Error on generating');
        }
      }
    } else
      $this->info('No sales record found on SALESMTD.DBF');
  }

  private function proCharges(Carbon $date) {

    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;
      
      $ds = [];
      $ds['hrly'] = [];
      $ds['eod']['sale'] = 0;
      $ds['eod']['grschrg'] = 0;
      $ds['eod']['totdisc'] = 0;
      $ds['eod']['saletax'] = 0;
      $ds['eod']['notaxsale'] = 0;
      $ds['eod']['vat'] = 0;
      $ds['eod']['begor'] = NULL;
      $ds['eod']['endor'] = NULL;

      for ($i=1; $i<=$record_numbers; $i++) {
        
        $row = dbase_get_record_with_names($db, $i);

        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $data = $this->associateAttributes($row);

          if (is_null($ds['eod']['begor']))
            $ds['eod']['begor'] = $data['cslipno'];
          $ds['eod']['endor'] = $data['cslipno'];

          $ds['eod']['grschrg']  += $data['tot_chrg'];
          //$ds['eod']['vat']      += $data['vat'];
          $ds['eod']['sale']      += ($data['tot_chrg']);
          $ds['eod']['totdisc']  += ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);

          if ($data['dis_sr']>0) {
            $ds['eod']['notaxsale'] += $data['tot_chrg'];
          } else {
            $ds['eod']['saletax'] += $data['chrg_grs'] - ($data['promo_amt'] + $data['oth_disc'] + $data['u_disc']);
            $ds['eod']['vat']      += $data['vat'];
          }

          $h = substr($data['ordtime'], 0, 2);
          if (array_key_exists($h, $ds['hrly']))
            $ds['hrly'][$h] += ($data['tot_chrg']);
          else
            $ds['hrly'][$h] = ($data['tot_chrg']);

          
          $update++;
        }
      }
      $ds['eod']['trancnt'] = $update;
      
      dbase_close($db);
      return $ds;
    } else {
      throw new Exception("Cannot locate CHARGES.DBF"); 
    }
  }

  private function proSalesmtd(Carbon $date) {

    $dbf_file = $this->extracted_path.DS.'SALESMTD.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);

      $ds = [];
      $ds['eod']['totqty'] = 0;
      $ds['hrly'] = [];
      
      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);

        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          $vfpdate = $date->copy()->subDay();
        }

        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) { // if salesmtd date == backup date
          $data = $this->associateSalesmtd($row);

          $h = substr($data['ordtime'], 11, 2);
          if (array_key_exists($h, $ds['hrly'])) {
            $ds['hrly'][$h]['qty'] += $data['qty'];
            $ds['hrly'][$h]['sales'] += $data['netamt'];
          } else {
  
            $ds['hrly'][$h]['qty'] = $data['qty'];
            $ds['hrly'][$h]['sales'] = $data['netamt'];
          }
          
          $ds['eod']['totqty'] += $data['qty'];

        }
      }

      dbase_close($db);
      return $ds;
    } else {
      throw new Exception("Cannot locate SALESMTD.DBF"); 
    }
  }

  /*********************************************************** End: PRO ****************************************/

  public function associateAttributes($r) {
    $row = [];

    $vfpdate = Carbon::parse(trim($r['ORDDATE']).' '.trim($r['ORDTIME']));
    
    if (($r['SR_TCUST']==$r['SR_BODY']) && ($r['SR_DISC']>0)) // 4 4 78.7
      $cuscount = $r['SR_TCUST']; 
    else if ($r['SR_TCUST']>0 && $r['SR_BODY']>0 && $r['SR_DISC']>0)
      $cuscount = 0;
    else
      $cuscount = ($r['SR_TCUST'] + $r['SR_BODY']);
    
    $disc_type = NULL;
    $disc_amt = 0;
    $a = ['DIS_GPC', 'DIS_VIP', 'DIS_PWD', 'DIS_EMP', 'DIS_SR', 'DIS_UDISC', 'DIS_PROM'];
    foreach ($a as $key => $value) {
      if (isset($r[$value]) && $r[$value]>0) {
        $disc_type = explode('_', $value)[1];
        $disc_amt = $r[$value];
      } 
    }

    $row['totdisc'] = 0;
    $row['totdisccnt'] = 0;

    $row['vfpdate']       = $vfpdate;
    $row['cslipno']       = trim($r['CSLIPNO']);
    $row['orddate']       = $vfpdate->format('Y-m-d');
    $row['ordtime']       = $vfpdate->format('H:i:s');
    $row['tblno']         = trim($r['CUSNO']);
    $row['chrg_type']     = trim($r['CUSNAME']);
    $row['chrg_pct']      = trim($r['CHARGPCT']);
    $row['chrg_grs']      = trim($r['GRSCHRG']);
    $row['sr_tcust']      = trim($r['SR_TCUST']);
    $row['sr_body']       = trim($r['SR_BODY']);
    $row['custcount']     = trim($cuscount);
    $row['sr_disc']       = trim($r['SR_DISC']);
    $row['oth_disc']      = trim($r['OTHDISC']);
    $row['u_disc']        = trim($r['UDISC']);
    $row['promo_amt']     = trim($r['PROMO_AMT']);
    $row['vat']           = trim($r['VAT']);
    $row['bank_chrg']     = trim($r['BANKCHARG']);
    $row['tot_chrg']      = trim($r['TOTCHRG']);
    $row['balance']       = trim($r['BALANCE']);
    $row['terms']         = trim($r['TERMS']);
    $row['card_type']     = trim($r['CARDTYP']);
    $row['card_no']       = trim($r['CARDNO']);
    $row['card_name']     = trim($r['CUSADDR1']);
    $row['card_addr']     = trim($r['CUSADDR2']);
    $row['tcash']         = trim($r['TCASH']);
    $row['tcharge']       = trim($r['TCHARGE']);
    $row['tsigned']       = trim($r['TSIGNED']);
    $row['vat_xmpt']      = trim($r['VAT_XMPT']);
    $row['disc_type']     = trim($disc_type);
    $row['disc_amt']      = trim($disc_amt);
    $row['remarks']       = trim($r['CUSCONT']);
    $row['cashier']       = trim($r['REMARKS']);
    $row['dis_gpc']       = trim($r['DIS_GPC']);
    $row['dis_vip']       = trim($r['DIS_VIP']);
    $row['dis_pwd']       = trim($r['DIS_PWD']);
    $row['dis_emp']       = trim($r['DIS_EMP']);
    $row['dis_sr']        = trim($r['DIS_SR']);
    $row['dis_udisc']     = trim($r['DIS_UDISC']);
    $row['dis_prom']      = trim($r['DIS_PROM']);
    $row['grschrg']       = trim($r['GRSCHRG']);
    $row['totchrg']       = trim($r['TOTCHRG']);

    $row['subtotal'] = 0;
    $row['gross'] = 0;

    $row['vat_ex'] = 0;
    $row['aol_trx_vat'] = 0;
    if ($row['dis_sr']>0) {
      $m = $row['vat_xmpt'] + $row['dis_sr'];
      $non_tax_sale = ($m / 0.285714286) - $m;
      $row['vat_ex'] = $non_tax_sale;
      $row['dis_sr'] = $m;
    } 
    $row['vat_in'] = $row['chrg_grs'] - $row['vat_ex'];

    $disc = ($row['promo_amt'] + $row['oth_disc'] + $row['u_disc']);
      $row['totdisc']  += $disc;
      if ($disc>0)
        $row['totdisccnt']++;


    if ($row['dis_sr']>0) {
      $row['trx_disc'] = '0.00';
      $row['taxsale'] = '0.00';
      //$row['chrg_grs'] = $row['chrg_grs']-$row['vat_xmpt'];
      $row['taxincsale'] = '0.00';

      $row['notaxsale'] = $row['tot_chrg'];
      $row['taxexsale'] = '0.00';
      
      $row['subtotal'] = $row['chrg_grs'];
      $row['gross'] = $row['tot_chrg'];
    } else {
      $row['trx_disc'] = '0.00';
      #$row['taxsale'] = $row['chrg_grs']-$row['totdisc'];
      $row['taxsale'] = $row['tot_chrg'];
      $row['notaxsale'] = '0.00';
      $row['taxexsale'] = '0.00';
      $row['taxincsale'] = $row['chrg_grs']-$row['totdisc'];
      $row['aol_trx_vat'] = $row['vat'];

      $row['subtotal'] = $row['chrg_grs'];
      $row['gross'] = $row['chrg_grs']-$row['totdisc'];
    }
    
    

    return $row;
  }

  public function associateSalesmtd($r) {
    $row = [];

    $cut = Carbon::parse(trim($r['ORDDATE']).' 06:00:00');
    $t = is_time(trim($r['ORDTIME'])) ? trim($r['ORDTIME']) : '00:00:01';
    $vfpdate = Carbon::parse(trim($r['ORDDATE']).' '.$t);
    $cuscount = substr(trim($r['CUSNO']), 0, strspn(trim($r['CUSNO']), '0123456789'));

    $row['tblno']         = trim($r['TBLNO']);
    $row['wtrno']         = trim($r['WTRNO']);
    $row['ordno']         = trim($r['ORDNO']);
    $row['productcode']   = trim($r['PRODNO']);
    $row['product']       = trim($r['PRODNAME']);
    $row['qty']           = trim($r['QTY']);
    
    $row['uprice']        = trim($r['UPRICE']);
    $row['grsamt']        = trim($r['GRSAMT']);
    $row['disc']          = trim($r['DISC']);
    $row['netamt']        = trim($r['NETAMT']);
    $row['prodcat']       = trim($r['CATNAME']);
    $row['orddate']       = $vfpdate->format('Y-m-d');
    //$row['ordtime']       = $vfpdate->format('H:i:s');
    $row['ordtime']       = $cut->gt($vfpdate) ? $vfpdate->addDay()->format('Y-m-d H:i:s') : $vfpdate->format('Y-m-d H:i:s');
    $row['recno']         = trim($r['RECORD']);
    $row['cslipno']       = trim($r['CSLIPNO']);
    if($cuscount < 300) 
      $row['custcount']   = $cuscount;
    else
      $row['custcount']   = 0;
    $row['paxloc']        = substr(trim($r['CUSNO']), -2);
    $row['group']         = trim($r['COMP2']);
    $row['group_cnt']     = trim($r['COMP3']);
    $row['remarks']       = trim($r['COMP1']);
    $row['cashier']       = trim($r['CUSNAME']);
    $row['menucat']       = trim($r['COMPUNIT2']).trim($r['COMPUNIT3']);

    return $row;
  }

}
