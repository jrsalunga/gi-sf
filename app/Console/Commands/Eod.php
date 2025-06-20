<?php namespace App\Console\Commands;

use Maatwebsite\Excel\Excel;
use stdClass;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Spatie\ArrayToXml\ArrayToXml;
use phpseclib3\Net\SFTP;

class Eod extends Command
{
  // php artisan eod 2021-02-26 --lessorcode=yic
  protected $signature = 'eod {date : YYYY-MM-DD} {--lessorcode= : File Extension} {--ext=csv : File Extension} {--mode=eod : Run Mode} {--dateTo= : Date To}  {--hour= : Hour} {--payment=false : process on Payment}';
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
      $this->lessors = ['pro', 'aol', 'yic', 'ocl', 'pla', 'ver', 'sia', 'ali', 'rlc'];
      $this->path = 'C:\\EODFILES';      
  }

  public function handle() {

    $date = $this->argument('date');

    if (strtolower($date) === 'hourly') {

      // $this->info(Carbon::parse($this->sysinfo->trandate)->format('Y-m-d'));
      // $this->info($this->sysinfo->lessorcode);
      
      $date = Carbon::parse($this->sysinfo->trandate)->format('Y-m-d');
    }  else {
      if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
        $this->info('Invalid date.');
        alog('Invalid date: '.$date);
        exit;
      }
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

      if (is_null($this->option('dateTo'))) {
        if ($lessorcode!='sia')
          if ($date->gte(Carbon::now()))
            $this->checkOrder();

          $this->checkCashAudit($date);
          $this->generateEod($date, $lessorcode, $ext);
      } else {
        $this->info('!NULL so generateEodByDr!');

        $to = $this->option('dateTo');
        if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $to)) {
          $to = $date;        
        } else {
          $to = Carbon::parse($to);
          if ($to->lt($date))
            $to = $date;        
        }
        $this->generateEodByDr($date, $to, $lessorcode);
      }
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

    } else if (strtolower($this->option('mode'))==='unsent') {
      $this->info('running on unsent mode');
      
      $lessorUnsent = $lessorcode.'Unsent';

      if (method_exists('\App\Console\Commands\Eod', $lessorUnsent)) {
        $this->{$lessorUnsent}($date);
      }



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

      // if (!$valid) {
      //   throw new Exception("Validation Error: No encoded ".join(", ", $a).". Please perform an EoD on POS before executing this command."); 
      // }

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

        $dir = 'D:\\SALESFILE'.DS.$this->date->format('Y').DS.$this->date->format('n').DS.$this->date->format('j');
        mdir($dir);
        return $this->out = $dir;

        return $this->out = 'D:\SALESFILE'.DS.$this->date->format('Y').DS.$this->date->format('n').DS.$this->date->format('j');
        /* run as admin

        net use Z: \\192.168.1.50\User0001L /user:User0001L D808bREMREf1kMJ /p:yes /savecred
        */
        break;
      case 'YIC':
        $dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$this->date->format('Y').DS.$this->date->format('n').DS.$this->date->format('j');
        mdir($dir);
        return $this->out = $dir;
        /* run as admin

        net use Z: \\192.168.35.190\gil_pos /user:gil.onemall 12345 /p:yes /savecred
        */
        break;
      case 'OCL':
        $dir = 'D:'.DS.'OCL'.DS.$this->date->format('Y').DS.$this->date->format('n').DS.$this->date->format('j');
        mdir($dir);
        return $this->out = $dir;
        break;
      case 'VER':
        $dir = 'C:'.DS.'LSG';
        // $dir = 'D:'.DS.'VER'.DS.$this->date->format('Y').DS.$this->date->format('n');
        mdir($dir);
        return $this->out = $dir;
        break;
      case 'SIA':
        $dir = 'D:'.DS.'SIA';
        if (!is_dir($dir))
          mdir($dir);
        return $this->out = $dir;
        break;
      case 'ALI':
        if (app()->environment()=='local')
          $dir = 'D:'.DS.'AYALA'.DS.$this->date->format('Y').DS.$this->date->format('m').DS.$this->date->format('d');
        else
          $dir = 'D:'.DS.'AYALA'.DS.$this->date->format('Y');
        if (!is_dir($dir))
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

    if (!is_dir($this->out))
        mdir($this->out);

    if ((!is_null($this->out) || !empty($this->out)) && is_dir($this->out)) {  

      //$this->info('OK - Drive: '.$this->out);
      alog('OK - Drive: '.$this->out);

      //$this->info('Copying: '.$file);
      //$this->info($newfile);

      $p = pathinfo($newfile);

      if (!is_dir($p['dirname']))
        mdir($p['dirname']);


      alog('Copying: '.$file.' - '.$newfile);

      

      if ($this->lessor=='ALI') 
        if(strtolower($p['extension'])=='csv') {
          $dir = 'D:'.DS.'AYALA'.DS.'tenant_api'.DS.'storage'.DS.'app'.DS.'OUTGOING';
          if (!is_dir($dir))
            mdir($dir);
          $this->out = $dir;
        } else 
          $this->getOut();
      
      




      if (copy($file, $this->out.DS.$newfile)) {
        $this->info('OK - Copying: '.$this->out.DS.$newfile);
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
     fputcsv($fp, $fields, ",", "\t");
    }

    fclose($fp);
  }

  private function toTXT($data, $date, $filename=NULL, $ext='TXT', $path=NULL) {

    $file = is_null($filename)
      ? Carbon::now()->format('YmdHis v')
      : $filename;


    $dir = is_null($path)
      ? $this->getpath().DS.$date->format('Y').DS.$date->format('m')
      : $path;

    // $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');

    if(!is_dir($dir))
        mkdir($dir, 0775, true);

    $file = $dir.DS.$file.'.'.$ext;

    $fp = fopen($file, 'w');

    foreach ($data as $fields) {
      //$this->info(join(',', $fields));
      fwrite($fp, '"'.join('","', $fields).'"'.PHP_EOL);
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

  private function generateEodByDr(Carbon $fr, Carbon $to, $lessor, $ext='CSV') {
    $this->info($fr);
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
    $this->info('diff: '.$fr->diffInDays($to));


    $d = $fr->copy();
    for ($i=0; $i <= $to->diffInDays($fr); $i++) { 
      $this->info($i.' '.$d);
      $this->date = $d;

      if ($lessor!='sia')
        if ($d->gte(Carbon::now()))
          $this->checkOrder();

      $this->checkCashAudit($d);
      $this->generateEod($d, $lessor, $ext);

      // sleep(3);
      $d->addDay();

    }
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
      ($c['vat_trx']+$c['novat_trx']), // 34 total # of sales transactions
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
    $dir = $this->getStoragePath().DS.$date->copy()->subDay()->format('Y').DS.$date->copy()->subDay()->format('m');
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
    $filename = substr($this->sysinfo->tenantcode, 0, 3).$this->getMonthParam($date->format('n')).$this->getDateParam($date->format('j')).$date->format('y').'R';
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

      $filename = substr($this->sysinfo->tenantcode, 0, 3).$this->getMonthParam($date->format('n')).$this->getDateParam($date->format('j')).$date->format('y').'D';
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

      $filename = substr($this->sysinfo->tenantcode, 0, 3).$this->getMonthParam($date->format('n')).$this->getDateParam($date->format('j')).$date->format('y').'P';
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

      $filename = substr($this->sysinfo->tenantcode, 0, 3).$this->getMonthParam($date->format('n')).$this->getDateParam($date->format('j')).$date->format('y').'H';
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
    
    $filename = substr($this->sysinfo->tenantcode, 0, 3).$this->getMonthParam($date->format('n')).$this->getDateParam($date->format('j')).$date->format('y').'S';
    //$dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
    mdir($dir);

    $zread_dbf = 'C:\\GI_GLO\\ZREAD\\ZREAD.DBF';
    if (!file_exists($zread_dbf)) {
      
      $this->info('ERROR - File not exist: '.$zread_dbf);
      $this->info('Unable to generate CSV Salesfile.');
      alog('Error - File not exist: '.$zread_dbf);
      alog('Unable to generate CSV Salesfile');

      return false;
    }

    $this->info('OK - File: '.$zread_dbf);
    alog('OK - File: '.$zread_dbf);

    $data[0] = ['fdtTrnsctn', 'fvcMrchntCd', 'fvcMrcntDsc', 'fnmGrndTtlOld', 'fnmGrndTtlNew', 'fnmGTDlySls', 'fnmGTDscnt', 'fnmGTDscntSNR', 'fnmGTDscntPWD', 'fnmGTDscntGPC', 'fnmGTDscntVIP', 'fnmGTDscntEMP', 'fnmGTDscntREG', 'fnmGTDscntOTH', 'fnmGTRfnd', 'fnmGTCncld', 'fnmGTSlsVAT', 'fnmGTVATSlsInclsv', 'fnmGTVATSlsExclsv', 'fnmOffclRcptBeg', 'fnmOffclRcptEnd', 'fnmGTCntDcmnt', 'fnmGTCntCstmr', 'fnmGTCntSnrCtzn', 'fnmGTLclTax', 'fnmGTSrvcChrg', 'fnmGTSlsNonVat', 'fnmGTRwGrss', 'fnmGtLclTaxDly', 'fvcWrksttnNmbr', 'fnmGTPymntCSH', 'fnmGTPymntCRD', 'fnmGTPymntOTH'];
    $data[1] = $this->yicGetZread($date, $zread_dbf);

    $x = [];
    foreach ($data[0] as $k => $val) {
      $x[$val] = $data[1][$k];
    }
    //$x['zcounter'] = $prev['zcounter'] + 1;
    //$this->toJson($date, $x);

    if (strtolower($ext)=='csv')
      $this->toCSV($data, $date, $filename, $ext, $dir);
    else
      $this->toTXT($data, $date, $filename, $ext);

    $file = $dir.DS.$filename.'.'.$ext;

    $newfile = $this->out.DS.$filename.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);
    // exit;
  }

  private function yicGetZread($date, $zread_dbf) {
    //$this->info('OK - File: '.$zread_dbf);

    $dbf_file = $zread_dbf;
    if (file_exists($dbf_file)) {
      //$this->info('dbase_open');
      $db = dbase_open($dbf_file, 0);
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);

      $zread = [];



      for ($i=1; $i<=$record_numbers; $i++) {
          
        $row = dbase_get_record_with_names($db, $i);

        //$this->info($row['F01']);

        try {
          $vfpdate = vfpdate_to_carbon(trim($row['F01']));
        } catch(Exception $e) {
          continue;
        }

        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
         for ($j=1; $j <=33 ; $j++) { 
          $v = $row['F'.str_pad($j,2,'0',STR_PAD_LEFT)];

          if ($j==1) 
            array_push($zread, $date->format('Y-m-d'));
          else if($j==2)
            array_push($zread, substr($this->sysinfo->tenantcode, 0, 3));
          else if($j==3 || $j==30)
            array_push($zread, trim($v));
          else if (in_array($j, [20,21,22,23,24]))
            array_push($zread, $v);
          else
            array_push($zread, number_format($v, 4,'.',''));
         }
        }
      }

      dbase_close($db);
      return $zread;
    } else {
      throw new Exception("Cannot locate ".$zread_dbf); 
      return false;
    }
  }

  private function yicDaily2($date, $c, $ext='csv') {

    $filename = substr($this->sysinfo->tenantcode, 0, 3).$this->getDateParam($date->format('n')).'-'.$this->getMonthParam($date->format('j')).'-'.$date->format('y').'S';
    //$dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
    mdir($dir);


    $this->info('getJsonData');
    $j = $this->getJsonData($date);

    //$this->info(dd($j));


    $prev = $this->yicGetPrev($date);

    //$this->info(dd($prev));


    $data[0] = ['fdtTrnsctn', 'fvcMrchntCd', 'fvcMrcntDsc', 'fnmGrndTtlOld', 'fnmGrndTtlNew', 'fnmGTDlySls', 'fnmGTDscnt', 'fnmGTDscntSNR', 'fnmGTDscntPWD', 'fnmGTDscntGPC', 'fnmGTDscntVIP', 'fnmGTDscntEMP', 'fnmGTDscntREG', 'fnmGTDscntOTH', 'fnmGTRfnd', 'fnmGTCncld', 'fnmGTSlsVAT', 'fnmGTVATSlsInclsv', 'fnmGTVATSlsExclsv', 'fnmOffclRcptBeg', 'fnmOffclRcptEnd', 'fnmGTCntDcmnt', 'fnmGTCntCstmr', 'fnmGTCntSnrCtzn', 'fnmGTLclTax', 'fnmGTSrvcChrg', 'fnmGTSlsNonVat', 'fnmGTRwGrss', 'fnmGtLclTaxDly', 'fvcWrksttnNmbr', 'fnmGTPymntCSH', 'fnmGTPymntCRD', 'fnmGTPymntOTH'];
    $data[1] = [
      $date->format('Y-m-d'), //DteTrnsctn
      substr($this->sysinfo->tenantcode, 0, 3), //MrchntCd
      substr(trim($this->sysinfo->tenantname), 0, 50), //MrchntDsc
      number_format($prev['prev_gt'], 4,'.',''), //GrndTtlOld
      //number_format($prev['prev_gt'] + ($c['eod']['sale']+$c['eod']['vat']), 4,'.',''), //GrndTtlNew
      number_format($prev['prev_gt'] + ($c['eod']['sale']), 4,'.',''), //GrndTtlNew
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
      //number_format($c['eod']['sale'], 4,'.',''), //GTDlySls + GTLclTax
      number_format(0 , 4,'.',''), //GTSrvcChrg
      number_format(0 , 4,'.',''), //GTSlsNonVat
      //number_format(($c['eod']['sale']+$c['eod']['vat']+$c['eod']['totdisc']), 4,'.',''), //GTRwGrss
      number_format($c['eod']['grschrg'], 4,'.',''), //GTRwGrss
      number_format($c['eod']['sale'] , 4,'.',''), //GtLclTaxDly  // daily sales + local tax
      ($this->sysinfo->pos_no+0), //TERMINUM
      number_format($c['eod']['sale_cash'], 4,'.',''),
      number_format($c['eod']['sale_chrg'], 4,'.',''),
      number_format(0 , 4,'.',''), //GTPymntOTH
    ];


    $x = [];
    foreach ($data[0] as $k => $val) {
      $x[$val] = $data[1][$k];
    }
    $x['zcounter'] = $prev['zcounter'] + 1;
    $this->toJson($date, $x);


    //$this->info(dd($x));


    if (strtolower($ext)=='csv')
      $this->toCSV($data, $date, $filename, $ext, $dir);
    else
      $this->toTXT($data, $date, $filename, $ext);

    $file = $dir.DS.$filename.'.'.$ext;

    $newfile = $this->out.DS.$filename.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);
    // exit;
  }

  private function yicCharges(Carbon $date) {

    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;

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

  private function getMonthParam($x) {
    $arr = [
      0 => 0,
      1 => 1,
      2 => 2,
      3 => 3,
      4 => 4,
      5 => 5,
      6 => 6,
      7 => 7,
      8 => 8,
      9 => 9,
      10 => 'A',
      11 => 'B',
      12 => 'C',
    ];
    return $arr[$x];
  }

  private function getDateParam($x) {
    $arr = [
      0 => 0,
      1 => 1,
      2 => 2,
      3 => 3,
      4 => 4,
      5 => 5,
      6 => 6,
      7 => 7,
      8 => 8,
      9 => 9,
      10 => 'A',
      11 => 'B',
      12 => 'C',
      13 => 'D',
      14 => 'E',
      15 => 'F',
      16 => 'G',
      17 => 'H',
      18 => 'I',
      19 => 'J',
      20 => 'K',
      21 => 'L',
      22 => 'M',
      23 => 'N',
      24 => 'O',
      25 => 'P',
      26 => 'Q',
      27 => 'R',
      28 => 'S',
      29 => 'T',
      30 => 'U',
      31 => 'V',
    ];
    return $arr[$x];
  }

  private function yicGetPrev(Carbon $date) {
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
    $a['prev_dailysales'] = 0;
    $a['prev_vat'] = 0;
    $a['prev_rawgross'] = 0;

    if (file_exists($file)) {
      alog('Reading - '.$file);
      $json = json_decode(file_get_contents($file), true); 
      
      $a['prev_gt']         = $json['fnmGrndTtlNew'];
      $a['prev_dailysales'] = $json['fnmGTDlySls'];
      $a['prev_vat']        = $json['fnmGTSlsVAT'];
      $a['prev_rawgross']   = $json['fnmGTRwGrss']; // dly sales + gt disc + vat
      
      $a['zcounter'] = $json['zcounter'];
    } else {
      alog($file.' not found!');
    }
    return $a;
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
      'tenantid'  => $f_tenantid,//19010883 //23012682
      'key'       => 'ROWZWNLI',//'D15403MN', //L8A6JDT6
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


  /*********************************************************** PLA ****************************************/
  public function PLA(Carbon $date, $ext) {
    $c = $this->plaCharges($date);
    //$this->info('this is AOL');
    //$this->info(json_encode($c));
    $this->plaDaily($date, $c);
  }

  private function plaDaily(Carbon $date, $c) {

    $ext = str_pad($this->sysinfo->pos_no, 3, '0', STR_PAD_LEFT);
    $filename = $date->format('mdY');

    //$this->info(' ');

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
    if(!is_dir($dir))
        mkdir($dir, 0775, true);
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');


    $data = [
      str_pad('PACIFICMALL', 12, ' ', STR_PAD_LEFT),
      str_pad(trim($this->sysinfo->tenantname), 12, ' ', STR_PAD_LEFT),
      str_pad($ext, 12, ' ', STR_PAD_LEFT),
      str_pad($date->format('Y-m-d'), 12, ' ', STR_PAD_LEFT),
      str_pad(number_format($c['grschrg'], 2,'.',''), 12, '0', STR_PAD_LEFT),
      str_pad(number_format($c['vat'], 2,'.',''), 12, '0', STR_PAD_LEFT),
      '000000000.00',
      '000000000.00',
      '000000000000',
      '000000000.00',
      '000000000000',
      '000000000.00',
      '000000000000',
      str_pad(number_format($c['totdisc'], 2,'.',''), 12, '0', STR_PAD_LEFT),
      str_pad(number_format($c['disccnt'], 0,'.',''), 12, '0', STR_PAD_LEFT),
      '000000000.00',
      str_pad(number_format($c['sale_chrg'], 2,'.',''), 12, '0', STR_PAD_LEFT),
      str_pad(number_format($c['sale_cash'], 2,'.',''), 12, '0', STR_PAD_LEFT),
      '000000000.00',
      str_pad(number_format($this->sysinfo->zread_ctr-1, 0,'.',''), 12, '0', STR_PAD_LEFT),
      str_pad(number_format($this->sysinfo->grs_total-$c['grschrg'], 2,'.',''), 12, '0', STR_PAD_LEFT),
      str_pad(number_format($this->sysinfo->zread_ctr, 0,'.',''), 12, '0', STR_PAD_LEFT),
      str_pad(number_format($this->sysinfo->grs_total, 2,'.',''), 12, '0', STR_PAD_LEFT),
      str_pad(number_format($c['trancnt'], 0,'.',''), 12, '0', STR_PAD_LEFT),
      str_pad(number_format($c['begor'], 0,'.',''), 12, '0', STR_PAD_LEFT),
      str_pad(number_format($c['endor'], 0,'.',''), 12, '0', STR_PAD_LEFT),
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
    } else {
      $this->info($file.' - Error on generating');
    }

  // public function plaCharges(Carbon $date) {
    return $final;
  }

  private function plaCharges(Carbon $date) {
    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;
      
      $ds = [];
      $ds['grschrg'] = 0;
      $ds['vat'] = 0;
      $ds['totdisc'] = 0;
      $ds['disccnt'] = 0;
      $ds['sale_cash'] = 0;
      $ds['sale_chrg'] = 0;
      // $ds['begdor'] = NULL;
      $ds['begor'] = NULL;
      $ds['endor'] = NULL;

      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $data = $this->associateAttributes($row);
          if (is_null($ds['begor']))
            $ds['begor'] = $data['cslipno'];
          $ds['endor'] = $data['cslipno'];

          $ds['grschrg']  += $data['tot_chrg'];
          $ds['vat']      += $data['vat'];
          // $ds['totdisc']  += ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);
          // if ($ds['totdisc']>0)
          $disc = ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);
          $ds['totdisc']  += $disc;
          if ($disc>0)
            $ds['disccnt']++;

          if (strtolower($data['terms'])=='charge')
            $ds['sale_chrg'] += $data['tot_chrg'];
          else
            $ds['sale_cash'] += $data['tot_chrg'];


          // $h = substr($data['ordtime'], 0, 2);
          // if (array_key_exists($h, $ds['hrly']))
          //   $ds['hrly'][$h] += $data['tot_chrg'];
          // else
          //   $ds['hrly'][$h] = $data['tot_chrg'];


          $update++;
        }
      }
      $ds['trancnt'] = $update;
      
      dbase_close($db);
      return $ds;
    } else {
      throw new Exception("Cannot locate CHARGES.DBF"); 
    }
  }
  /*********************************************************** End: PLA ****************************************/



  /*********************************************************** VER ****************************************/
  public function VER(Carbon $date, $ext) {
    $c = $this->verCharges($date);
    
    $this->verDaily($date, $c, $ext='txt');    
  }

  private function verDaily(Carbon $date, $c, $ext) {



    
    $filename = $date->format('mdY');

    $zread = trim($this->sysinfo->zread_ctr)+1;

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
    mdir($dir);

    // $vat = (($c['vat_gross']-$c['totdisc'])*.12)/1.12;
    // $vat_sales = $c['vat_gross']-$c['totdisc']-$vat;

    // $novat_sales = $c['novat_gross'] - $c['sr_disc'];

    //$this->info($c['novat_gross'].' = '.$c['sr_disc']);

    
    

    $data = [];

    $data[0] = [
      'VS1', // 1. mall code
      '123456789012345', // 2. contract #
      1, // 3. Open field; default:1
      'OF2', // 4. Open field; default:OF2
      1, // 5. outlet #
      number_format($this->sysinfo->grs_total,2,'.',''), // 6. New Grand Total
      number_format(($this->sysinfo->grs_total-$c['grschrg']),2,'.',''), // 7. Old Grand Total
      'S01', // 8. Sales Type; default:ST01
      number_format($c['grschrg'],2,'.',''), // 9. Net Sales
      number_format($c['dis_prom'],2,'.',''), // 10. Regular Discount
      number_format($c['dis_emp'],2,'.',''), // 11. Employee Discount
      number_format($c['dis_sr'],2,'.',''), // 12. Senior Citizen Discount
      number_format($c['dis_vip'],2,'.',''), // 13. VIP Discount
      number_format($c['dis_pwd'],2,'.',''), // 14. PWD Discount
      number_format($c['dis_oth'],2,'.',''), // 15. Other Discount
      number_format($c['dis_ath'],2,'.',''), // 16. Athlete Discount
      number_format(0,2,'.',''), // 17. Open field; default:0
      number_format(0,2,'.',''), // 18. Open field; default:0
      number_format(0,2,'.',''), // 19. Open field; default:0
      number_format(0,2,'.',''), // 20. Open field; default:0
      number_format(0,2,'.',''), // 21. Zero Rated Sales
      number_format($c['vat'],2,'.',''), // 22. VAT
      number_format(0,2,'.',''), // 23. Other Tax; default:0
      number_format(0,2,'.',''), // 24. Adjustments; default:0
      number_format(0,2,'.',''), // 25. Positive Adjustments; default:0
      number_format(0,2,'.',''), // 26. Negative Adjustments; default:0
      number_format(0,2,'.',''), // 27. Non Tax Positive Adjustments; default:0
      number_format(0,2,'.',''), // 28. Non Tax Negative Adjustments; default:0
      number_format($c['chrg_grs'],2,'.',''), // 29. Gross Sales
      number_format(0,2,'.',''), // 30. Void
      number_format(0,2,'.',''), // 31. Refund
      number_format($c['grschrg'],2,'.',''), // 32. Sales inclusive of Vat // Net Sales
      number_format($c['vat_xmpt'],2,'.',''), // 33. Non Vat Sales 
      number_format($c['sale_chrg'],2,'.',''), // 34. Charge payment
      number_format($c['sale_cash'], 2,'.',''), // 35. Cash payment
      number_format(0,2,'.',''), // 36. Gift Cheque
      number_format(0,2,'.',''), // 37. Debit Card
      number_format(0,2,'.',''), // 38. Other Tender
      number_format($c['master'], 2,'.',''), // 39. Cash payment
      number_format($c['visa'], 2,'.',''), // 40. Cash payment
      number_format($c['amex'], 2,'.',''), // 41. Cash payment
      number_format($c['diners'], 2,'.',''), // 42. Cash payment
      number_format($c['jcb'], 2,'.',''), // 43. Cash payment
      number_format($c['other'], 2,'.',''), // 44. Cash payment
      number_format(0,2,'.',''), // 45. Service Charge
      number_format(0,2,'.',''), // 46. Other Charge
      $c['begor']+0, // 47. First Transaction
      $c['endor']+0, // 48. Last Transaction
      $c['trancnt'], // 49. # of Transactions
      $c['begor']+0, // 50. Beg Inv #
      $c['endor']+0, // 51. End Inv #
      $c['sale_cash_ctr'], // 52. Cash Transactions
      0, // 53. GC Transactions
      0, // 54. Debit Card Transactions
      0, // 55. Other Tender Transactions
      $c['master_ctr'], // 56. Master Trx
      $c['visa_ctr'], // 57. Visa Trx
      $c['amex_ctr'], // 58. Amex Trx
      $c['diners_ctr'], // 59. Diners Trx
      $c['jcb_ctr'], // 60. JCB Trx
      $c['other_ctr'], // 61. Other Trx
      1, // 62. POS #
      trim($this->sysinfo->serialno), // 63. Serial No
      trim($this->sysinfo->zread_ctr)+1, // 64. Z-Count
      now()->format('His'), // 65. Transaction Time
      now()->format('mdY'), // 66. Transaction Date
    ];



    if (strtolower($ext)=='csv')
      $this->toCSV($data, $date, $filename, $ext);
    else
      $this->toTXT($data, $date, $filename, $ext);

    $file = $dir.DS.$filename.'.'.$ext;

    $newfile = $this->out.DS.$filename.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);  
  }

  private function verCharges(Carbon $date) {
    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;
      
      $ds = [];
      $ds['grschrg'] = 0;
      $ds['chrg_grs'] = 0;
      $ds['vat'] = 0;
      $ds['totdisc'] = 0;
      $ds['disccnt'] = 0;
      $ds['sale_cash'] = 0;
      $ds['sale_cash_ctr'] = 0;
      $ds['sale_chrg'] = 0;
      $ds['sale_chrg_ctr'] = 0;
      $ds['begor'] = NULL;
      $ds['endor'] = NULL;
      $ds['dis_prom'] = 0;
      $ds['dis_emp'] = 0;
      $ds['dis_sr'] = 0;
      $ds['dis_vip'] = 0;
      $ds['dis_pwd'] = 0;
      $ds['dis_oth'] = 0;
      $ds['dis_ath'] = 0;
      $ds['vat_xmpt'] = 0;
      $ds['master'] = 0;
      $ds['master_ctr'] = 0;
      $ds['visa'] = 0;
      $ds['visa_ctr'] = 0;
      $ds['amex'] = 0;
      $ds['amex_ctr'] = 0;
      $ds['jcb'] = 0;
      $ds['jcb_ctr'] = 0;
      $ds['diners'] = 0;
      $ds['diners_ctr'] = 0;
      $ds['other'] = 0;
      $ds['other_ctr'] = 0;
      

      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $data = $this->associateAttributes($row);
          if (is_null($ds['begor']))
            $ds['begor'] = $data['cslipno'];
          $ds['endor'] = $data['cslipno'];

          $ds['grschrg']  += $data['tot_chrg'];
          $ds['chrg_grs']  += $data['chrg_grs'];
          $ds['vat']      += $data['vat'];
          $ds['vat_xmpt'] += $data['vat_xmpt'];
          // $ds['totdisc']  += ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);
          // if ($ds['totdisc']>0)
          $disc = ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);
          $ds['totdisc']  += $disc;
          if ($disc>0)
            $ds['disccnt']++;


          
          $this->info($data['terms'].'='.$data['tot_chrg']);

          if (strtolower($data['terms'])=='charge') {
            $ds['sale_chrg'] += $data['tot_chrg'];
            $ds['sale_chrg_ctr']++;
            $this->info($data['card_type']);
            if (starts_with($data['card_type'],'MASTER')) {
              $ds['master'] += $data['tot_chrg'];
              $ds['master_ctr']++;
            } else if (starts_with($data['card_type'],'VISA')) {
              $ds['visa'] += $data['tot_chrg'];
              $ds['visa_ctr']++;
            } else if (starts_with($data['card_type'],'DINERS')) {
              $ds['amex'] += $data['tot_chrg'];
              $ds['amex_ctr']++;
            } else if (starts_with($data['card_type'],'AMEX')) {
               $ds['diners'] += $data['tot_chrg'];
              $ds['diners_ctr']++;
            } else if (starts_with($data['card_type'],'JCB')) {
              $ds['jcb'] += $data['tot_chrg'];
              $ds['jcb_ctr']++;
            } else {
              $ds['other'] += $data['tot_chrg'];
              $ds['other_ctr']++;
            }

          } else {
            $ds['sale_cash'] += $data['tot_chrg'];
            $ds['sale_cash_ctr']++;
          }

           $this->info($ds['sale_cash'].'='.$ds['sale_chrg']);

          

          $ds['dis_prom'] += $data['dis_prom'];
          $ds['dis_emp'] += $data['dis_emp'];
          $ds['dis_vip'] += $data['dis_vip'];
          $ds['dis_oth'] += ($data['dis_udisc']+$data['dis_gpc']);
          // $ds['dis_oth'] += $data['oth_disc'];

          //if SR_DISC > 0
          if ($data['sr_disc']>0) {
            if (starts_with($data['card_name'],'SC'))
               $ds['dis_sr'] += $data['dis_sr'];
            if (starts_with($data['card_name'],'PWD'))
               $ds['dis_pwd'] += $data['dis_sr'];
            if (starts_with($data['card_name'],'ATH'))
               $ds['dis_ath'] += $data['dis_sr'];
          }

          $update++;
        }
      }
      $ds['trancnt'] = $update;
      
      dbase_close($db);
      return $ds;
    } else {
      throw new Exception("Cannot locate CHARGES.DBF"); 
    }
  }
  /*********************************************************** end: VER ****************************************/


  /*********************************************************** SIA ****************************************/
  public function SIA(Carbon $date, $ext) {

    $d = $this->siaCharges($date, $ext);

    $s = $this->siaCountCslipno($date);

    $this->siaSalesmtd($date, $d, $s, $ext);

    $this->siaCombine($date, $ext);
  }

  private function siaCharges(Carbon $date, $ext='csv') {

    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      // $this->info('CHARGES.DBF found!');
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;



      $filename = $date->format('Ymd').'-CHARGES';
      $dir = $this->getStoragePath().DS.$date->format('Y').DS.$date->format('m');
      
     
      $disc = [];
      $ctr = 0;
      $arr = [];
      $r_disc = [];
      

      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $data = $this->associateAttributes($row);



        if (in_array($data['chrg_type'], ['PANDA','GRAB'])) 
          $trxtype = $data['chrg_type'];
        else if (in_array($data['saletype'], ['CALPUP','ONLCUS'])) 
          $trxtype = 'PICKUP';
        else
          $trxtype = $data['saletype'];

        $tcust = $pwd = $sr = $ath = $vip = $emp = $prom = $pay_amt = $vatable = 0;
        $tot_disc = $pwd_cust = $sr_cust = $ath_cust = $vip = $emp = $prom = $vat = $vat_xmpt = $vat_xmpt_sales = $oth_disc_amt = $odeal_disc_amt = 0;
        $oth_disc_name = $odeal_disc_name = $pay_type = "";
        $master = $visa = $amex = $jcb = $diners = $ewallet = $oth_ccard = $oth_tender = $cash = 0; 
        $tot_disc_name = '';


        if ($data['sr_disc']>0) {

          $tcust = $data['sr_body'];
          $vat_xmpt = $data['vat_xmpt'];
          
          // $this->info($data['grschrg'].' '.$data['disc_type'].'='.$data['disc_amt'].'  '.$data['card_name']);
          // $this->info($data['card_name']);



          if (app()->environment()=='local')  {
            if ($data['sr_body']==1) { // if 1 lang senior
            
              $vat_xmpt_sales = $data['tot_chrg'];
              if (str_contains($data['card_name'], 'PWD')) {
                $pwd_cust = $data['sr_body'];
                $tot_disc_name = 'PWD';
                $r_disc[$ctr++]['PWD']=$data['disc_amt'];
                $pwd = $data['disc_amt'];
              } else {
                $sr_cust = $data['sr_body'];
                $tot_disc_name = 'SC';
                $r_disc[$ctr++]['SC']=$data['disc_amt'];
                $sr = $data['disc_amt'];
              }
            } else {
              $vat_xmpt_sales = $data['tot_chrg'];
              $sr_cust = $data['sr_body'];
              $tot_disc_name = 'SC';
            }
          } else {
            $vat_xmpt_sales = $data['tot_chrg'];
            $sr_cust = $data['sr_body'];
            $tot_disc_name = 'SC';
            $sr = $data['disc_amt'];
          }
        
        } else {
          $tcust = $data['sr_tcust'] - $data['sr_body'];
          $vat = $data['vat'];
        }


        // $this->info($data['grschrg'].' '.$data['disc_type'].'='.$data['disc_amt'].'  '.$data['card_name'].'  '.$data['chrg_type'].'  '.$data['card_type']);

        //** Discounts
        $tot_disc = $data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc'];


        $a = ['DIS_PWD', 'DIS_UDISC', 'DIS_PROM', 'DIS_G', 'DIS_H', 'DIS_I', 'DIS_J', 'DIS_K', 'DIS_L', 'DIS_VX'];
        foreach ($a as $key => $value) {
          if (isset($row[$value]) && $row[$value]>0) {
            // $this->info($value.'='.$row[$value]);
            // $oth_disc_name = (is_null($oth_disc_name)) ? explode('_', $value)[1] : $oth_disc_name.'|'.explode('_', $value)[1];

            $r_disc[$ctr++][explode('_', $value)[1]]=$row[$value];

            if (empty($oth_disc_name) && empty($oth_disc_amt)) {
              $oth_disc_name = explode('_', $value)[1];
              $oth_disc_amt = $row[$value];
            } else {
              $oth_disc_name = $oth_disc_name.'::'.explode('_', $value)[1];
              $oth_disc_amt = $oth_disc_amt.'::'.$row[$value];
            }
          } 
        }
        
        if ($oth_disc_amt>0)
          $tot_disc_name = empty($tot_disc_name) ? $oth_disc_name : $tot_disc_name.'::'.$oth_disc_name ;

        // if (empty($oth_disc_name) && empty($oth_disc_amt)) {
          
        // } else {
        //   $this->info('NOT EMPTY oth_disc_amt');
        // }

        // $this->info('oth_disc_name='.$oth_disc_name);
       
       // $this->info($data['disc_type']);


        switch (trim($data['disc_type'])) {
          case 'EMP':
              $emp = $data['disc_amt'];
              $tot_disc_name = empty($tot_disc_name) ? 'EMP' : $tot_disc_name.'::EMP' ;
              $r_disc[$ctr++]['EMP']=$data['disc_amt'];
            break;
          case 'VIP':
              $vip = $data['disc_amt'];
              $tot_disc_name = empty($tot_disc_name) ? 'VIP' : $tot_disc_name.'::VIP' ;
              $r_disc[$ctr++]['VIP']=$data['disc_amt'];
            break;
          case 'GPC':
              $ath = $data['disc_amt'];
              $tot_disc_name = empty($tot_disc_name) ? 'ATH' : $tot_disc_name.'::ATH' ;
              $r_disc[$ctr++]['GPC']=$data['disc_amt'];
              $vat = ($data['chrg_grs']/1.12)*.12;
            break;
          case 'SR': 
              // $sr = $data['disc_amt']; // do nothing //nasa taas na
            break;
          default:
            // $oth_disc_name = $data['disc_type'];
            // $oth_disc_amt = $data['disc_amt'];
            break;
        }


        if ($tot_disc>0) {
          $p = ($tot_disc/$data['tot_chrg'])*100;
            // $this->info($value.'='.$row[$value].' '.$p);
          $disc[$data['cslipno']] = [$data['cslipno'], $tot_disc_name, $data['tot_chrg'], $tot_disc, $p, $data['sr_tcust'], $data['sr_body'], $data['vat_xmpt']];
        }
        //** end: Discounts



        $pay_amt = $data['tot_chrg'];
        if ($data['chrg_type']=='CASH') {
          $pay_type = $data['chrg_type'];
          $cash = $data['tot_chrg'];
        } else 

        if (in_array($data['chrg_type'], ['CHARGE', 'MAYA', 'BDO', 'BANKARD'])) {

          // $pay_type = $data['chrg_type'].' '.$data['card_type'];
          $pay_type = $data['card_type'];
          switch (trim($data['card_type'])) {
            case 'MASTER':
              $master = $data['tot_chrg'];
              break;
            case 'VISA':
              $visa = $data['tot_chrg'];
              break;
            case 'AMEX':
              $amex = $data['tot_chrg'];
              break;
            case 'DINERS':
              $diners = $data['tot_chrg'];
              break;
            case 'JCB':
              $jcb = $data['tot_chrg'];
              break;
            case 'GCASH':
            case 'MAYA':
            case 'PAYMAYA':
            case 'ALI':
            case 'SHOPEE':
            case 'SHOPEEPAY':
            case 'QRPH':
            case 'GRABPAY':
            case 'WECHATPAY':
              $ewallet = $data['tot_chrg'];
              break;
            default:
              $oth_ccard = $data['tot_chrg'];
              break;
          }
        } else

        if (in_array($data['chrg_type'], ['GRAB', 'PANDA'])) {
          $pay_type = $data['chrg_type'];
          $oth_tender = $data['tot_chrg'];
        } else { // ZAP
          $pay_type = 'OTHERS';
          $oth_tender = $data['tot_chrg'];
        }

        $vatable = $data['tot_chrg'] - $vat; // this is the Net Sales Amount

        $_arr[0] = [
          "Order Num",
          "Business Day",
          "Check Open",
          "Check Close",
          "Sales Type",
          "Transaction Type",
          "Void",
          "Void Amount",
          "Refund",
          "Refund Amount",
          "Guest Count",
          "Senior",
          "PWD",
          "Gross Sales Amount",
          "Net Sales Amount",
          "Total Tax",
          "Other Local Tax",
          "Total Service Charge",
          "Total Tip",
          "Total Discount",
          "Less Tax Amount",
          "Tax Exepmt Sales",
          "Regular/Other Disc Name",
          "Regular/Other Disc Amt",
          "Emp Disc Amt",
          "SR Disc Amt",
          "VIP Disc Amt",
          "PWD Disc Amt",
          "ATH Disc Amt",
          "SMAC Disc Amt",
          "Online Deal Disc Name",
          "Online Deal Disc Amt",
          "Disc Field 1 Name",
          "Disc Field 2 Name",
          "Disc Field 3 Name",
          "Disc Field 4 Name",
          "Disc Field 5 Name",
          "Disc Field 6 Name",
          "Disc Field 1 Amount",
          "Disc Field 2 Amount",
          "Disc Field 3 Amount",
          "Disc Field 4 Amount",
          "Disc Field 5 Amount",
          "Disc Field 6 Amount",
          "Payment Type 1",
          "Payment Amt 1",
          "Payment Type 2",
          "Payment Amt 2",
          "Payment Type 3",
          "Payment Amt 3",
          "Total Cash Sales Amt",
          "Total GC Sales Amt",
          "Total Debit Sales Amt",
          "Total eWallet Sales Amt",
          "Total Other Tender Sales Amt",
          "Total Master Sales Amt",
          "Total Visa Sales Amt",
          "Total Amex Sales Amt",
          "Total Diners Sales Amt",
          "Total JCB Sales Amt",
          "Total Other Card Sales Amt",
          "Terminal #",
          "Serial #",
        ];

        
        $dt = Carbon::parse($vfpdate->format('Y-m-d')." ".$data['ordtime']);

        $arr[$i] = [
          $data['cslipno'],
          $vfpdate->format('Y-m-d'),
          $vfpdate->format('Y-m-d')." ".$data['ordtime'],
          $dt->copy()->addMinutes(rand(1,7))->addSecond(rand(1,59))->format('Y-m-d H:i:s'),
          'SM01',
          $trxtype,
          0, // Void
          0, // Void Amount
          0, // Refund
          0, // Refund Amount
          $tcust,
          $sr_cust,
          $pwd_cust,
          $data['chrg_grs'],
          $vatable, //$data['tot_chrg'], // Net Sales Amount
          $vat,
          0, // Other Local Tax
          0, // Total Service Charge
          0, // Total Tip
          $tot_disc,
          $vat_xmpt,
          $vat_xmpt_sales,
          $oth_disc_name,
          $oth_disc_amt,
          $emp,
          $sr,
          $vip,
          $pwd,
          $ath,
          0, // SMAC
          "", // Online Deal Disc Name $odeal_disc_name
          0, // Online Deal Disc Amt  = $odeal_disc_amt
          "", // Disc Field 1 Name
          "", // Disc Field 2 Name
          "", // Disc Field 3 Name
          "", // Disc Field 4 Name
          "", // Disc Field 5 Name
          "", // Disc Field 6 Name
          0, // Disc Field 1 Amount
          0, // Disc Field 2 Amount
          0, // Disc Field 3 Amount
          0, // Disc Field 4 Amount
          0, // Disc Field 5 Amount
          0, // Disc Field 6 Amount
          $pay_type,
          $pay_amt,
          "", // Payment Type 2
          0, // "Payment Amt 2
          "", // Payment Type 3
          0, // "Payment Amt 3
          $cash,
          0, // GC
          0, // Debit
          $ewallet,
          $oth_tender,
          $master,
          $visa,
          $amex,
          $diners,
          $jcb,
          $oth_ccard,
          1,
          trim($this->sysinfo->serialno)
        ];
         




        } // end:if
      } // end:for

      // print_r(array_keys($disc));
      // print_r($disc);
      // print_r(array_keys($disc));
      // print_r($r_disc);

      // $this->info($dir);
      // $this->info($filename);
      $this->toTXT($arr, $date, $filename, $ext, $dir);


      return $disc;
    } else
      $this->info('CHARGES.DBF not found!');

    return false;
  }

  private function siaCountCslipno(Carbon $date) {

    $dbf_file = $this->extracted_path.DS.'SALESMTD.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);     
      $arr = [];

      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }

        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          // $data = $this->associateSalesmtd($row);
          array_push($arr, trim($row['CSLIPNO']));
        } // end:if
      } // end:for
      return array_count_values($arr);
    } else
      $this->info('SALESMTD.DBF not found!');
    return [];
  }

  private function siaSalesmtd(Carbon $date, array $disc, array $s, $ext='csv') {

    $dbf_file = $this->extracted_path.DS.'SALESMTD.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;

      $filename = $date->format('Ymd').'-SALESMTD';
      $dir = $this->getStoragePath().DS.$date->format('Y').DS.$date->format('m');
     
      $arr = [];
      
      $disc_pct = 0;
      $disc_amt = 0;
      $x = 0;

      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $data = $this->associateSalesmtd($row);

          $disc_name = "";
          $disc_pct = 0;
          $x = 0;
          if (in_array($data['cslipno'], array_keys($disc))) {

              $disc_name = $disc[$data['cslipno']][1];

              $disc_pct = ($disc[$data['cslipno']][4]/100)*$disc[$data['cslipno']][2];

              if ($s[$data['cslipno']]>1) {
                $disc_pct = $disc_pct/$s[$data['cslipno']];
                $x = $disc_pct+($disc[$data['cslipno']][7]/$s[$data['cslipno']]);
              } else {
                $x = $disc_pct+$disc[$data['cslipno']][7];
              }
              
              // $this->info($disc[$data['cslipno']][1].' '.$disc[$data['cslipno']][4].' '.$disc_pct.' '.$data['qty'].' '.$x);
              // $this->info($s[$data['cslipno']]);
          }


          switch ($data['productcode']) {
            case 'ZRMEAL':
              $menucat = 'ZRMEAL';
              $prodcat = 'Foods';
              break;
            case 'MISC':
              $menucat = 'MISC';
              $prodcat = $data['prodcat'];
              break;
            default:
              $menucat = $data['menucat'];
              $prodcat = $data['prodcat'];
              break;
          }


          $_arr[0] = [
            "Order Num / Bill Num",
            "Item ID",
            "Item Name",
            "Item Parent Category",
            "Item Category",
            "Item Sub-Category",
            "Item Quantity",
            "Transaction Item Price",
            "Menu Item Price",
            "Discount Code",
            "Discount Amount",
            "Modifier (1) Name",
            "Modifier (1) Quantity",
            "Modifier (2) Name",
            "Modifier (2) Quantity",
            "Void",
            "Void Amount",
            "Refund",
            "Refund Amount",
          ];



          $arr[$i] = [
            $data['cslipno'],
            $data['productcode'],
            $data['product'],
            $prodcat,
            $menucat,
            "", // Item SubCategory
            $data['qty'],
            number_format($data['netamt']-$x,2),
            $data['grsamt'],
            $disc_name,
            $disc_pct,
            // $x, // $disc_pct,
            "", // Modifier (1) Name
            0,  // Modifier (1) Quantity
            "", // Modifier (2) Name
            0,  // Modifier (2) Quantity
            0, // Void
            0, // Void Amount
            0, // Refund
            0, // Refund Amount
          ];




        } // end:if
      } // end:for

      // $this->info($dir);
      // $this->info($filename);
      $this->toTXT($arr, $date, $filename, $ext, $dir);

    } else
      $this->info('SALESMTD.DBF not found!');
    return false;
  }

  private function siaCombine(Carbon $date, $ext='csv') {
    $cnt = 31;
    $ctr = 0;
    $c = [];
    $s = [];
    $dd = [];
    array_push($c, $this->getStoragePath().DS.'HEADER-CHARGES.csv');
    array_push($s, $this->getStoragePath().DS.'HEADER-SALESMTD.csv');

    $d = $date->copy()->subday($cnt);
    // $this->info($d->format('Y-m-d'));

    do {

      $d->addDay();
      array_push($dd, $d->format('Y-m-d'));

      $pc = $this->getStoragePath().DS.$d->format('Y').DS.$d->format('m').DS.$d->format('Ymd').'-CHARGES.csv';
      if (file_exists($pc))
        array_push($c, $pc);
      // else
        // $this->info('WARNING: '.$pc.' doesn\'t exist!');

      $ps = $this->getStoragePath().DS.$d->format('Y').DS.$d->format('m').DS.$d->format('Ymd').'-SALESMTD.csv';
      if (file_exists($ps))
        array_push($s, $ps);
      // else
      //   $this->info('WARNING: '.$ps.' doesn\'t exist!');
      $ctr++;
    } while ($ctr<$cnt);

    // print_r($dd);

    // $newfile = $this->getOut().DS.$value;
    if (!is_dir($this->getPath().DS.$date->format('Y')))
      mkdir($this->getPath().DS.$date->format('Y'), 0755, true);

    $dc = $this->getPath().DS.$date->format('Y').DS.$date->format('m_Y').'_transactions.csv';
    $this->joinFiles($c, $dc);
    $ds = $this->getPath().DS.$date->format('Y').DS.$date->format('m_Y').'_transactiondetails.csv';
    $this->joinFiles($s, $ds);

    // $this->verifyCopyFile($dc, $this->getOut().DS.$date->format('m_Y').'_transactions.csv');
    // $this->verifyCopyFile($ds, $this->getOut().DS.$date->format('m_Y').'_transactiondetails.csv');
    $this->verifyCopyFile($dc, $date->format('m_Y').'_transactions.csv');
    $this->verifyCopyFile($ds, $date->format('m_Y').'_transactiondetails.csv');

    $this->info(' ');
  }

  private function joinFiles(array $files, $result) {
    if(!is_array($files)) {
        throw new Exception('`$files` must be an array');
    }

    $wH = fopen($result, "w+");

    foreach($files as $file) {
        $fh = fopen($file, "r");
        while(!feof($fh)) {
            fwrite($wH, fgets($fh));
        }
        fclose($fh);
        unset($fh);
        // fwrite($wH, "\n"); //usually last line doesn't have a newline
    }
    fclose($wH);
    unset($wH);

    // joinFiles(array('join1.csv', 'join2.csv'), 'join3.csv');
  }


  /*********************************************************** end: SIA ****************************************/



  /*********************************************************** ALI ****************************************/
  public function ALI(Carbon $date, $ext) {

    // $this->info('mode:'.$this->option('mode').' - payment:'.$this->option('payment'));

    if($this->option('payment')==='true')
      $this->aliProcessPostedPayment($date);
    else
      $this->aliCharges($date);
  }

  # Generate All Receipt to CVS - not applicable anymore
  private function aliProcessPostedPayment(Carbon $date) {
    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      $record_numbers = dbase_numrecords($db);
      // $row = dbase_get_record_with_names($db, $record_numbers);  /// $record_numbers - x, x = number
      $trans = [];
      
      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);

        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          
        }
        // $this->info('vfpdate:'.$vfpdate->format('Y-m-d').' = '.$date->format('Y-m-d'));

        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {
          $r = $this->associateAttributes($row);
          $trans = $this->aliGetTrans($date, $r);
          $this->aliGenerateCSVPosted($date, $trans, $r['cslipno']);
        } else {
          // $this->info('no payment posted');
        }
      }
      dbase_close($db);
    } else {
      throw new Exception("Cannot locate CHARGES.DBF"); 
    }
  }

  private function aliGetItem(Carbon $date, $cslipno, $table_no) {
    $dbf_file = $this->extracted_path.DS.'SALESMTD.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);

      $ctr = 0;
      $items = [];

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

            $items[$ctr]['QTY']      = number_format($data['qty'], 3,'.','');
            $items[$ctr]['ITEMCODE'] = $data['productcode'];
            $items[$ctr]['PRICE']    = number_format($data['netamt'], 2,'.','');
            $items[$ctr]['LDISC']    = number_format(0, 2,'.','');
            $ctr++;
          }
        }
      }
      return $items;
    }
  }

  private function aliGetTrans(Carbon $date, array $row) {

    $vat = $vtble = $ves = $vea = $qty_sld = $pwd = $sr = $tot_disc = 0;
    
    if ($row['sr_disc']>0) {
      $ves = $row['tot_chrg'];
      $vea = $row['vat_xmpt'];
      $tcust = $row['sr_body'];

      if (app()->environment()=='local')  {
        if ($row['sr_body']==1) { // if 1 lang senior
          if (str_contains($row['card_name'], 'PWD')) {
            $pwd = $row['sr_disc']; // pwd disc amt
          } else {
            $sr = $row['sr_disc']; // snr disc amt
          }
        } else {
          $sr = $row['sr_disc']; // snr disc amt
        }
      } else {
        $sr = $row['sr_disc']; // snr disc amt
      }



    } else {
      $vat = $row['vat'];
      $vtble = $row['tot_chrg']-$row['vat'];
      $tcust = $row['sr_tcust']-$row['sr_body'];
    }

    $oth_disc = $row['dis_gpc']+$row['dis_vip']+$row['dis_udisc']+$row['dis_prom'];


    $master = $visa = $amex = $jcb = $diners = $charge = $cash = $other_pay = 0; 
    $gcash = $maya = $alipay = $wechat = $grab = $panda = $epay = 0; 


    // CASH or CHARGE sales
    if ($row['chrg_type']=='CASH') {
      $cash = $row['tot_chrg']; //total cash sales
    } else 

    if (in_array($row['chrg_type'], ['CHARGE', 'MAYA', 'BDO', 'BANKARD'])) {

      $pay_type = $row['card_type'];
      switch (trim($row['card_type'])) {
        case 'MASTER':
          $master = $row['tot_chrg'];
          $charge = $row['tot_chrg'];
          break;
        case 'VISA':
          $visa = $row['tot_chrg'];
          $charge = $row['tot_chrg'];
          break;
        case 'AMEX':
          $amex = $row['tot_chrg'];
          $charge = $row['tot_chrg'];
          break;
        case 'DINERS':
          $diners = $row['tot_chrg'];
          $charge = $row['tot_chrg'];
          break;
        case 'JCB':
          $jcb = $row['tot_chrg'];
          $charge = $row['tot_chrg'];
          break;
        case 'GCASH':
          $gcash = $row['tot_chrg'];
          $epay = $row['tot_chrg'];
          break;
        case 'MAYA':
          $maya = $row['tot_chrg'];
          $epay = $row['tot_chrg'];
          break;
        case 'PAYMAYA':
          $maya = $row['tot_chrg'];
          $epay = $row['tot_chrg'];
          break;
        case 'ALI':
          $alipay = $row['tot_chrg'];
          $epay = $row['tot_chrg'];
          break;
        case 'WECHATPAY':
          $wechat = $row['tot_chrg'];
          $epay = $row['tot_chrg'];
          break;
        default:
          $other_pay = $row['tot_chrg'];
          break;
      }
    } else

    if (in_array($row['chrg_type'], ['GRAB', 'PANDA'])) {
      if ($row['chrg_type']=='GRAB')
        $grab = $row['tot_chrg'];
      if ($row['chrg_type']=='PANDA')
        $panda = $row['tot_chrg'];
      // $other_pay = $row['tot_chrg'];
    } else { // ZAP
      $other_pay = $row['tot_chrg'];
    }

    //Sale Type
    if (in_array($row['saletype'], ['CALPUP', 'ONLCUS']))
      $saletype = 'O';
    else if (in_array($row['saletype'], ['CALWED', 'ONLRID','ONLWED'])) 
      $saletype = 'C';
    else
      $saletype = 'D';

    $items = $this->aliGetItem($date, $row['cslipno'], $row['tblno']);
    foreach($items as $k => $v)
      $qty_sld += $v['QTY'];

    $tot_disc = $row['promo_amt'] + $row['sr_disc'] + $row['oth_disc'] + $row['u_disc'];
    
    $datas = [
      'CDATE' => $row['vfpdate']->format('H')<8 ? $row['vfpdate']->copy()->addDay()->format('Y-m-d') : $row['vfpdate']->format('Y-m-d'),
      'TRN_TIME' => $row['vfpdate']->format('H:i'),
      'TER_NO' => str_pad($this->sysinfo->pos_no, 3, 0, STR_PAD_LEFT),
      'TRANSACTION_NO' => $row['cslipno'],
      'GROSS_SLS' => number_format($row['chrg_grs'], 2,'.',''),
      'VAT_AMNT' => number_format($vat, 2,'.',''),
      'VATABLE_SLS' => number_format($vtble, 2,'.',''),
      'NONVAT_SLS' => number_format(0, 2,'.',''),
      'VATEXEMPT_SLS' => number_format($ves, 2,'.',''),
      'VATEXEMPT_AMNT' => number_format($vea, 2,'.',''),
      'LOCAL_TAX' => number_format(0, 2,'.',''),
      'PWD_DISC' => number_format($pwd, 2,'.',''),
      'SNRCIT_DISC' => number_format($sr, 2,'.',''),
      'EMPLO_DISC' => number_format($row['dis_emp'], 2,'.',''),
      'AYALA_DISC' => number_format(0, 2,'.',''),
      'STORE_DISC' => number_format(0, 2,'.',''),
      'OTHER_DISC' => number_format($oth_disc, 2,'.',''),
      'REFUND_AMT' => number_format(0, 2,'.',''),
      'SCHRGE_AMT' => number_format(0, 2,'.',''),
      'OTHER_SCHR' => number_format(0, 2,'.',''),
      'CASH_SLS' => number_format($cash, 2,'.',''),
      'CARD_SLS' => number_format($charge, 2,'.',''),
      'EPAY_SLS' => number_format($epay, 2,'.',''),
      'DCARD_SLS' => number_format(0, 2,'.',''),
      'OTHERSL_SLS' => number_format($other_pay, 2,'.',''),
      'CHECK_SLS' => number_format(0, 2,'.',''),
      'GC_SLS' => number_format(0, 2,'.',''),
      'MASTERCARD_SLS' => number_format($master, 2,'.',''),
      'VISA_SLS' => number_format($visa, 2,'.',''),
      'AMEX_SLS' => number_format($amex, 2,'.',''),
      'DINERS_SLS' => number_format($diners, 2,'.',''),
      'JCB_SLS' => number_format($jcb, 2,'.',''),
      'GCASH_SLS' => number_format($gcash, 2,'.',''),
      'PAYMAYA_SLS' => number_format($maya, 2,'.',''),
      'ALIPAY_SLS' => number_format($alipay, 2,'.',''),
      'WECHAT_SLS' => number_format($wechat, 2,'.',''),
      'GRAB_SLS' => number_format($grab, 2,'.',''),
      'FOODPANDA_SLS' => number_format($panda, 2,'.',''),
      'MASTERDEBIT_SLS' => number_format(0, 2,'.',''),
      'VISADEBIT_SLS' => number_format(0, 2,'.',''),
      'PAYPAL_SLS' => number_format(0, 2,'.',''),
      'ONLINE_SLS' => number_format(0, 2,'.',''),
      'OPEN_SALES' => number_format(0, 2,'.',''),
      'OPEN_SALES_2' => number_format(0, 2,'.',''),
      'OPEN_SALES_3' => number_format(0, 2,'.',''),
      'OPEN_SALES_4' => number_format(0, 2,'.',''),
      'OPEN_SALES_5' => number_format(0, 2,'.',''),
      'OPEN_SALES_6' => number_format(0, 2,'.',''),
      'OPEN_SALES_7' => number_format(0, 2,'.',''),
      'OPEN_SALES_8' => number_format(0, 2,'.',''),
      'OPEN_SALES_9' => number_format(0, 2,'.',''),
      'OPEN_SALES_10' => number_format(0, 2,'.',''),
      'OPEN_SALES_11' => number_format(0, 2,'.',''),
      'GC_EXCESS' => number_format(0, 2,'.',''),
      'MOBILE_NO' => '',
      'NO_CUST' => number_format($tcust, 0,'.',''),
      'TRN_TYPE' => $saletype,
      'SLS_FLAG' => 'S',
      'VAT_PCT' => number_format(1.12, 2,'.',''),
      'QTY_SLD' => number_format($qty_sld, 3,'.',''),
      // 'QTY_SLD' => number_format(count($items), 3,'.',''),

      'ITEMS' => $this->aliGetItem($date, $row['cslipno'], $row['tblno'])
    ];

    return $datas;
  }

  private function aliGenerateCSVPosted(Carbon $date, array $data, $cslipno) {
    
    $filename = trim($this->sysinfo->tenantcode).trim($this->sysinfo->contract).$date->format('mdy').str_pad($this->sysinfo->pos_no, 3, 0, STR_PAD_LEFT).'_'.$cslipno.'.csv';
    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    if (!is_dir($dir))
      mdir($dir);
    $file = $dir.DS.$filename;
    $fp = fopen($file, 'w');

    $head = [
      'CCCODE' => trim($this->sysinfo->tenantcode).trim($this->sysinfo->contract),
      'MERCHANT_NAME' => $this->aliGetTenantName(),
      'TRN_DATE' => $date->format('Y-m-d'),
      'NO_TRN' => 1,
    ];


    foreach ($head as $key => $value) {
      $ln = $key.','.$value; 
      fwrite($fp, $ln.PHP_EOL);
    }

    foreach ($data as $k => $v) 
    if ($k==='ITEMS') {
      foreach ($v as $m => $n)
        foreach ($n as $o => $p) {
          $ln = $o.','.$p; 
          fwrite($fp, $ln.PHP_EOL);
        }
    } else {
      $ln = $k.','.$v; 
      fwrite($fp, $ln.PHP_EOL);
    }
    

    $this->verifyCopyFile($file, $filename);
  }

  private  function aliGenHourlyCsv(Carbon $date, array $data, $hr, $last_cslipno, $head) {

    $filename = $head['CCCODE'].$date->format('mdy').str_pad($this->sysinfo->pos_no, 3, 0, STR_PAD_LEFT).'_'.$last_cslipno.'.csv';
    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    if (!is_dir($dir))
      mdir($dir);
    $file = $dir.DS.$filename;
    $fp = fopen($file, 'w');

    $head['NO_TRN'] = count($data[$hr]);

    foreach ($head as $key => $value) {
      $ln = $key.','.$value; 
      fwrite($fp, $ln.PHP_EOL);
    }

    foreach ($data[$hr] as $rcpt) {
      foreach ($rcpt as $k => $v) 
      if ($k==='ITEMS') {
        foreach ($v as $m => $n)
          foreach ($n as $o => $p) {
            $ln = $o.','.$p; 
            fwrite($fp, $ln.PHP_EOL);
          }
      } else {
        $ln = $k.','.$v; 
        fwrite($fp, $ln.PHP_EOL);
      }
    }

    $this->verifyCopyFile($file, $filename);
  }

  private function getJsonArray(Carbon $date) {
    $this_date = $date; 
    $filename = $this_date->format('Ymd');
    $dir = $this->getStoragePath().DS.$this_date->format('Y').DS.$this_date->format('m');
    $file = $dir.DS.$filename.'.json';
    $a = [];
    // alog('Getting previous data - OK');

    if (file_exists($file)) {
      alog('Reading - '.$file);
      foreach (json_decode(file_get_contents($file), true) as $key => $value)
        $a[$key] = $value;
    } 
    return $a;
  }

  private function aliGenEodCsv(Carbon $date, array $data) {

    $pos_gross_sls = $data[7]; // gross sales
    // or $gross_sls = $vat_amnt + $vatable_sls + $vatexempt_sls + $vatexempt_amnt + $tot_disc; 
    $gross_sls = $pos_gross_sls;
    
    $sales = $data[111]; // $data[111] ==  $vatable_sls + $vatexempt_sls + $vat_amnt
    
    $vat_amnt = $data[8];
    $tot_disc = $data[18];
    $sc_disc = $data[22];
    $vatable_sls = $data[9]; 
    $vatexempt_sls = $data[11];  
    $vatexempt_amnt = $data[12];

    $netsales = $vatable_sls + $vatexempt_sls;     
    $dailysales = $gross_sls - ($vatexempt_amnt + $tot_disc); // $data[111]
    $ayala_gross = $gross_sls - $tot_disc;

    $txt_gross_sls = $vat_amnt + $vatable_sls + $vatexempt_sls + $tot_disc; // or  $pos_gross_sls - $vatexempt_amnt
    

    $e_txt_gross_sales = $gross_sls - $vatexempt_amnt;
    $e_txt_sales = $e_txt_gross_sales - $tot_disc - $vat_amnt;
    $e_txt_notaxsales = $vatexempt_sls;
    $e_txt_add_grntot = $sales;

    $csv_add_grntot = $vatable_sls + $vatexempt_sls + $vat_amnt;
    $csv_gross_sls = $vatable_sls + $vatexempt_sls + $vat_amnt + $vatexempt_amnt + $tot_disc;


    $trans_cnt = $data[108];
    $cust_cnt = $data[107];
    $cash_sale = $data[35];
    $charge_sale = $data[36];

    $prev = $this->getJsonArray($date->copy()->subDay());

    // print_r($prev);

    $this->toJson($date, [
      'zcounter' => isset($prev['zcounter']) ? ($prev['zcounter']+1) : 1 ,
      'pos_gross_sls'=> number_format($pos_gross_sls, 2, '.', ''),
      'gross_sls'=> number_format($gross_sls, 2, '.', ''),
      'sales' => number_format($sales, 2, '.', ''),
      'dailysales' => number_format($dailysales, 2, '.', ''),
      'netsales'=> number_format($netsales, 2, '.', ''),
      'vat_amnt' => number_format($vat_amnt, 2, '.', ''),
      'vatable_sls' => number_format($vatable_sls, 2, '.', ''),
      'vatexempt_sls' => number_format($vatexempt_sls, 2, '.', ''),
      'vatexempt_amnt' => number_format($vatexempt_amnt, 2, '.', ''),
      'tot_disc' => number_format($tot_disc, 2, '.', ''),
      'old_grntot' => number_format((isset($prev['new_grntot']) ? $prev['new_grntot'] : 0), 2, '.', ''),
      'new_grntot' => number_format((isset($prev['new_grntot']) ? $prev['new_grntot'] : 0) + $sales, 2, '.', ''), // vat + vatable + vat xmpt
      'ayala_gross' => number_format($ayala_gross, 2, '.', ''),
      'txt_gross_sls'=> number_format($txt_gross_sls, 2, '.', ''),
      'cust_cnt'=> number_format($cust_cnt, 0, '.', ''),
      'trans_cnt'=> number_format($trans_cnt, 0, '.', ''),
      'cash_sale' => number_format($cash_sale, 2, '.', ''),
      'charge_sale'=> number_format($charge_sale, 2, '.', ''),
    ]);

    $now = $this->getJsonArray($date);
    // print_r($now);



    $datas = [
      'CCCODE' => $data[1],
      'MERCHANT_NAME' => $data[2],
      'TER_NO' => $data[3],
      'TRN_DATE' => $data[4],
      'STRANS' => number_format($data[5], 0, '.', ''),
      'ETRANS' => number_format($data[6], 0, '.', ''),
      'GROSS_SLS' => number_format($csv_gross_sls, 2, '.', ''),
      'VAT_AMNT' => number_format($vat_amnt, 2, '.', ''),
      'VATABLE_SLS' => number_format($vatable_sls, 2, '.', ''),
      'NONVAT_SLS' => number_format(0, 2, '.', ''),
      'VATEXEMPT_SLS' => number_format($vatexempt_sls, 2, '.', ''),
      'VATEXEMPT_AMNT' => number_format($vatexempt_amnt, 2, '.', ''),
      'OLD_GRNTOT' => number_format($now['old_grntot'], 2, '.', ''),
      'NEW_GRNTOT' => number_format($now['new_grntot'], 2, '.', ''),
      'LOCAL_TAX' => number_format(0, 2, '.', ''),
      'VOID_AMNT' => number_format(0, 2, '.', ''),
      'NO_VOID' => number_format(0, 0, '.', ''),
      'DISCOUNTS' => number_format($tot_disc, 2, '.', ''),
      'NO_DISC' => number_format($data[19], 0, '.', ''),
      'REFUND_AMT' => number_format(0, 2, '.', ''),
      'NO_REFUND' => number_format(0, 0, '.', ''),
      'SNRCIT_DISC' => number_format($data[22], 2, '.', ''),
      'NO_SNRCIT' => number_format($data[23], 0, '.', ''),
      'PWD_DISC' => number_format($data[24], 2, '.', ''),
      'NO_PWD' => number_format($data[25], 0, '.', ''),
      'EMPLO_DISC' => number_format($data[26], 2, '.', ''),
      'NO_EMPLO' => number_format($data[27], 0, '.', ''),
      'AYALA_DISC' => number_format(0, 2, '.', ''),
      'NO_AYALA' => number_format(0, 0, '.', ''), // 29
      'STORE_DISC' => number_format(0, 2, '.', ''), // 30
      'NO_STORE' => number_format(0, 0, '.', ''), // 31
      'OTHER_DISC' => number_format($data[31], 2, '.', ''), // 31?
      'NO_OTHER_DISC' => number_format($data[32], 0, '.', ''),
      'SCHRGE_AMT' => number_format(0, 2, '.', ''),
      'OTHER_SCHR' => number_format(0, 2, '.', ''),
      'CASH_SLS' => number_format($data[35], 2, '.', ''),
      'CARD_SLS' => number_format($data[36], 2, '.', ''),
      'EPAY_SLS' => number_format($data[37], 2, '.', ''),
      'DCARD_SLS' => number_format(0, 2, '.', ''),
      'OTHER_SLS' => number_format($data[39], 2, '.', ''),
      'CHECK_SLS' => number_format(0, 2, '.', ''),
      'GC_SLS' => number_format(0, 2, '.', ''),
      'MASTERCARD_SLS' => number_format($data[42], 2, '.', ''),
      'VISA_SLS' => number_format($data[43], 2, '.', ''),
      'AMEX_SLS' => number_format($data[44], 2, '.', ''),
      'DINERS_SLS' => number_format($data[45], 2, '.', ''),
      'JCB_SLS' => number_format($data[46], 2, '.', ''),
      'GCASH_SLS' => number_format($data[47], 2, '.', ''),
      'PAYMAYA_SLS' => number_format($data[48], 2, '.', ''),
      'ALIPAY_SLS' => number_format($data[49], 2, '.', ''),
      'WECHAT_SLS' => number_format($data[50], 2, '.', ''),
      'GRAB_SLS' => number_format($data[51], 2, '.', ''),
      'FOODPANDA_SLS' => number_format($data[52], 2, '.', ''),
      'MASTERDEBIT_SLS' => number_format(0, 2, '.', ''),
      'VISADEBIT_SLS' => number_format(0, 2, '.', ''),
      'PAYPAL_SLS' => number_format(0, 2, '.', ''),
      'ONLINE_SLS' => number_format(0, 2, '.', ''),
      'OPEN_SALES' => number_format(0, 2, '.', ''),
      'OPEN_SALES_2' => number_format(0, 2, '.', ''),
      'OPEN_SALES_3' => number_format(0, 2, '.', ''),
      'OPEN_SALES_4' => number_format(0, 2, '.', ''),
      'OPEN_SALES_5' => number_format(0, 2, '.', ''),
      'OPEN_SALES_6' => number_format(0, 2, '.', ''),
      'OPEN_SALES_7' => number_format(0, 2, '.', ''),
      'OPEN_SALES_8' => number_format(0, 2, '.', ''),
      'OPEN_SALES_9' => number_format(0, 2, '.', ''),
      'OPEN_SALES_10' => number_format(0, 2, '.', ''),
      'OPEN_SALES_11' => number_format(0, 2, '.', ''),
      'GC_EXCESS' => number_format(0, 2, '.', ''),
      'NO_VATEXEMT' => number_format($data[69], 0, '.', ''),
      'NO_SCHRGE' => number_format(0, 0, '.', ''),
      'NO_OTHER_SUR' => number_format(0, 0, '.', ''),
      'NO_CASH' => number_format($data[72], 0, '.', ''),
      'NO_CARD' => number_format($data[73], 0, '.', ''),
      'NO_EPAY' => number_format($data[74], 0, '.', ''),
      'NO_DCARD_SLS' => number_format(0, 0, '.', ''),
      'NO_OTHER_SLS' => number_format($data[76], 0, '.', ''),
      'NO_CHECK' => number_format(0, 0, '.', ''),
      'NO_GC' => number_format(0, 0, '.', ''),
      'NO_MASTERCARD_SLS' => number_format($data[79], 0, '.', ''),
      'NO_VISA_SLS' => number_format($data[80], 0, '.', ''),
      'NO_AMEX_SLS' => number_format($data[81], 0, '.', ''),
      'NO_DINERS_SLS' => number_format($data[82], 0, '.', ''),
      'NO_JCB_SLS' => number_format($data[83], 0, '.', ''),
      'NO_GCASH_SLS' => number_format($data[84], 0, '.', ''),
      'NO_PAYMAYA_SLS' => number_format($data[85], 0, '.', ''),
      'NO_ALIPAY_SLS' => number_format($data[86], 0, '.', ''),
      'NO_WECHAT_SLS' => number_format($data[87], 0, '.', ''),
      'NO_GRAB_SLS' => number_format($data[88], 0, '.', ''),
      'NO_FOODPANDA_SLS' => number_format($data[89], 0, '.', ''),
      'NO_MASTERDEBIT_SLS' => number_format(0, 0, '.', ''),
      'NO_VISADEBIT_SLS' => number_format(0, 0, '.', ''),
      'NO_PAYPAL_SLS' => number_format(0, 0, '.', ''),
      'NO_ONLINE_SLS' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_2' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_3' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_4' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_5' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_6' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_7' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_8' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_9' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_10' => number_format(0, 0, '.', ''),
      'NO_OPEN_SALES_11' => number_format(0, 0, '.', ''),
      'NO_NOSALE' => number_format(0, 0, '.', ''),
      'NO_CUST' => number_format($data[107], 0, '.', ''),
      'NO_TRN' => number_format($data[108], 0, '.', ''),
      'PREV_EODCTR' => number_format($now['zcounter']-1, 0, '.', ''),
      'EODCTR' => number_format($now['zcounter'], 0, '.', ''),
    ];


    $filename = 'EOD'.$datas['CCCODE'].$date->format('mdy').'.csv';
    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    if (!is_dir($dir))
      mdir($dir);
    $file = $dir.DS.$filename;
    $fp = fopen($file, 'w');

    foreach ($datas as $key => $value) {
      $ln = $key.','.$value; // on productiom
      fwrite($fp, $ln.PHP_EOL);
    }

    $this->verifyCopyFile($file, $filename);



    /********* generate text file **********/

    $datas = [];
    $ext = 'TXT';
    $filename2 = trim($this->sysinfo->contract).$date->format('md');
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');

    $datas[0] = ['TRANDATE','OLDGT','NEWGT','DLYSALE','TOTDISC','TOTREF','TOTCAN','VAT','TENTNAME','BEGINV','ENDINV','BEGOR','ENDOR','TRANCNT','LOCALTX','SERVCHARGE','NOTAXSALE','RAWGROSS','DLYLOCTAX','OTHERS','TERMNUM'];
    $datas[1] = [
      $date->format('m/d/Y'), 
      number_format($now['old_grntot'], 2, '.', ''),
      number_format($now['new_grntot'], 2, '.', ''),
      number_format($netsales, 2, '.', ''),
      number_format($tot_disc, 2, '.', ''),
      number_format(0, 2, '.', ''),
      number_format(0, 2, '.', ''),
      number_format($vat_amnt, 2, '.', ''),
      $this->aliGetTenantName(),
      number_format($data[5], 0, '.', ''),
      number_format($data[6], 0, '.', ''),
      number_format($data[5], 0, '.', ''),
      number_format($data[6], 0, '.', ''),
      number_format($data[108], 0, '.', ''),
      number_format(0, 2, '.', ''),
      number_format(0, 2, '.', ''),
      number_format($vatexempt_sls, 2, '.', ''),
      number_format($txt_gross_sls, 2, '.', ''),
      number_format($netsales, 2, '.', ''),
      0,
      1,
    ];


    $this->toTXT($datas, $date, $filename2, $ext, $dir, false);

    $file = $dir.DS.$filename2.'.'.$ext;

    $newfile = $filename2.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);





    $lines = $this->aliGenerateZread($date, $now);

    $zfilename = trim($this->sysinfo->contract).$date->format('md').'Z';
    $zfile = $dir.DS.$zfilename.'.'.$ext;

    $new = file_exists($zfile) ? false : true;
    if($new){
      $handle = fopen($zfile, 'w+');
      chmod($zfile, 0775);
    } else
      $handle = fopen($zfile, 'w+');

    if (!is_null($lines)) {
      foreach ($lines as $key => $content) {
        fwrite($handle, $content.PHP_EOL);
      }
    }
    
    fclose($handle);

    $this->verifyCopyFile($zfile, $zfilename.'.'.$ext);
  }

  public function aliGenerateZread(Carbon $date, array $data) {

    $heads = $this->aliGetHeader(trim($this->sysinfo->gi_brcode));

    $lines = [];
    array_push($lines, bpad(' ', 40));
    array_push($lines, bpad(' ', 40));
    array_push($lines, bpad(' ', 40));

    array_push($lines, bpad(' ', 40));
    foreach ($heads as $key => $h)
      array_push($lines, $h);

    array_push($lines, bpad(' ', 40));

    array_push($lines, bpad("----------------------------------------", 40));
    array_push($lines, bpad("CONSOLIDATED REPORT Z-READ", 40));
    array_push($lines, bpad("----------------------------------------", 40));
    array_push($lines, rpad('Daily Sales', 23).lpad(nf($data['sales'], 2, true), 17));
    array_push($lines, rpad('Total Discount', 23).lpad(nf($data['tot_disc'], 2, true), 17));
    array_push($lines, rpad('Total Refund', 23).lpad('0.00', 17));
    array_push($lines, rpad('Total Cancelled/Void', 23).lpad('0.00', 17));
    array_push($lines, rpad('Total Service Charge', 23).lpad('0.00', 17));
    array_push($lines, rpad('Total Vatable Sales', 23).lpad(nf($data['vatable_sls'], 2, true), 17));
    array_push($lines, rpad('Total VAT Amount', 23).lpad(nf($data['vat_amnt'], 2, true), 17));
    array_push($lines, rpad('Total Non Taxable', 23).lpad(nf($data['vatexempt_sls'], 2, true), 17));
    array_push($lines, rpad('Total Exempt Amount', 23).lpad(nf($data['vatexempt_amnt'], 2, true), 17));
    array_push($lines, rpad('Net Sales', 23).lpad(nf($data['netsales'], 2, true), 17));
    array_push($lines, rpad('CSV Gross', 23).lpad(nf($data['gross_sls'], 2, true), 17));
    array_push($lines, rpad('TXT Gross', 23).lpad(nf($data['txt_gross_sls'], 2, true), 17));
    array_push($lines, rpad('Ayala Gross', 23).lpad(nf($data['ayala_gross'], 2, true), 17));
    array_push($lines, rpad('Old Grand Total', 23).lpad(nf($data['old_grntot'], 2, true), 17));
    array_push($lines, rpad('New Grand Total', 23).lpad(nf($data['new_grntot'], 2, true), 17));
    array_push($lines, rpad('Transaction Count', 23).lpad(nf($data['trans_cnt'], 0, true), 17));
    array_push($lines, rpad('Customer Count', 23).lpad(nf($data['cust_cnt'], 0, true), 17));
    array_push($lines, rpad('Cash Sales', 23).lpad(nf($data['cash_sale'], 2, true), 17));
    array_push($lines, rpad('Charge Sales', 23).lpad(nf($data['charge_sale'], 2, true), 17));
    array_push($lines, rpad('ZRead Count', 23).lpad(nf($data['zcounter'], 0, true), 17));
    array_push($lines, bpad("----------------------------------------", 40));
    array_push($lines, bpad('DATE: '.$date->format('m/d/Y'), 40));
    array_push($lines, bpad(' ', 40));
    array_push($lines, bpad("*** END OF REPORT ***", 40));

    array_push($lines, bpad(' ', 40));
    array_push($lines, bpad(' ', 40));

    // foreach ($lines as $key => $h)
    //   $this->info($h);

    return $lines;
  }

  public function aliGetHeader($brcode) {

    $lines = [];

    if ($brcode=='MAR') {
      array_push($lines, bpad("ALQUIROS FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("Market! Market!", 40));
      array_push($lines, bpad("FIESTA MARKET MARKET FORT BONIFACIO", 40));
      array_push($lines, bpad("TAGUIG CITY 1630", 40));
      array_push($lines, bpad("#205-257-440-004 VAT", 40));
      array_push($lines, bpad("S/N 147P11S", 40));
      array_push($lines, bpad("MIN# 070073156", 40));
      array_push($lines, bpad("PTU# 1107-044-25319-004", 40));
      array_push($lines, bpad("BIR Accredit # 040-205257440-000305", 40));
    }

    if ($brcode=='AST') {
      array_push($lines, bpad("GILIGANS HOLDINGS CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("AYALA MALLS SERIN", 40));
      array_push($lines, bpad("GEN. EMILIO AGUINALDO HWY, SILANG,", 40));
      array_push($lines, bpad("JCT. NORTH, TAGAYTAY CITY, CAVITE", 40));
      array_push($lines, bpad("#010-264-107-026 VAT", 40));
      array_push($lines, bpad("S/N JPH830NHN9", 40));
      array_push($lines, bpad("MIN# 070073156", 40));
      array_push($lines, bpad("PTU# 1107-044-25319-004", 40));
      array_push($lines, bpad("BIR Accredit # 040-205257440-000305", 40));
    }

    if ($brcode=='ANG') {
      array_push($lines, bpad("ALQUIROS, FILIBERTO SAINZ", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("MARQUEE MALL", 40));
      array_push($lines, bpad("G,F MARQUEEMALL NEPO AVENUE,", 40));
      array_push($lines, bpad("ANGELES CITY, 2009", 40));
      array_push($lines, bpad("#133-162-738-002 VAT", 40));
      array_push($lines, bpad("S/N AZLF920087S", 40));
      array_push($lines, bpad("MIN# 100139647", 40));
      array_push($lines, bpad("PTU# 0810-21A-76229-002", 40));
      array_push($lines, bpad("BIR Accredit # 040-205257440-000305", 40));
    }

    if ($brcode=='AMK') {
      array_push($lines, bpad("ALQUIROS, JO-ANDREW GARCIA", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S AYALA MALL MARIKINA", 40));
      array_push($lines, bpad("UNIT F106 AYALA MALL MARIKINA,", 40));
      array_push($lines, bpad("LIWASAN KALAYAAN ST., MARIKINA CITY", 40));
      array_push($lines, bpad("#199-013-974-000 VAT", 40));
      array_push($lines, bpad("S/N Z9ACECV1", 40));
      array_push($lines, bpad("MIN# 18052117594479336", 40));
      array_push($lines, bpad("PTU# FP052018-045-0169487-00006", 40));
      array_push($lines, bpad("Accred# 040-205257440-000305-15061", 40));
    }

    if ($brcode=='CM1') {
      array_push($lines, bpad("ALQUIROS, NICOLE IONE GARCIA", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S CIRCUIT MAKATI", 40));
      array_push($lines, bpad("CIRCUIT LANE AYALA MALL,REYES AVE.", 40));
      array_push($lines, bpad("BRGY.CARMONA, MAKATI CITY 1207", 40));
      array_push($lines, bpad("#232-360-252-002 VAT", 40));
      array_push($lines, bpad("S/N Z9ADJ8SV", 40));
      array_push($lines, bpad("MIN# 17100607430441455", 40));
      array_push($lines, bpad("PTU# FP102017-049-0140347-00002", 40));
      array_push($lines, bpad("Accred# 040-205257440-000305-15061", 40));
    }

    return $lines;
  }

  private function aliCharges(Carbon $date) {
    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;
      $flag = false;
      $now = Carbon::now();
      $data = [];
      $trans = [];
      $hrly_data = [];

      $head = [
        'CCCODE' => trim($this->sysinfo->tenantcode).trim($this->sysinfo->contract),
        'MERCHANT_NAME' => $this->aliGetTenantName(),
        'TRN_DATE' => $date->format('Y-m-d'),
      ];
      
      $data['EOD'][1] = $head['CCCODE'];
      $data['EOD'][2] = $head['MERCHANT_NAME'];
      $data['EOD'][3] = '00'.(trim($this->sysinfo->pos_no)+0);
      $data['EOD'][4] = $date->format('Y-m-d');
      $data['EOD'][6] = $data['EOD'][5] = NULL;

      
      foreach (range(7,108) as $k => $v)
        $data['EOD'][$v] = 0;
      
      $data['EOD'][109] = trim($this->sysinfo->zread_ctr);
      $data['EOD'][110] = trim($this->sysinfo->zread_ctr)+1;
      $data['EOD'][111] = 0;

      $ds = [];
      $tmp_hr = NULL;
      $last_cslipno = NULL;


      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
        try {
          $vfpdate = vfpdate_to_carbon(trim($row['ORDDATE']));
        } catch(Exception $e) {
          continue;
        }
        
        if ($vfpdate->format('Y-m-d')==$date->format('Y-m-d')) {

          $dt = Carbon::parse($vfpdate->format('Y-m-d').' '.trim($row['ORDTIME']));
          // $this->info($dt->format('H').' '.($now->format('H')-1).' '.$now->format('H'));
          
          $r = $this->associateAttributes($row);
            
          if ($tmp_hr == $r['vfpdate']->format('H')) {
            // $this->info($update.' '.$r['vfpdate']->format('Y-m-d H:i:s').' '.$r['cslipno'].' -1');
            $data[$tmp_hr][$update] = $this->aliGetTrans($date, $r); //****************************************************************************/

            $last_cslipno = $r['cslipno'];
          } else {
            if (!is_null($tmp_hr)) {
              
              // generate hourly CSV
              // $this->aliGenHourlyCsv($date, $data, $tmp_hr, $last_cslipno, $head); //****************************************************************************/
              
              $tmp_hr = $r['vfpdate']->format('H');
              $data[$tmp_hr] = [];        

            } else {

              $tmp_hr = $r['vfpdate']->format('H');
              // $this->info('this is 1st run'.' '.$tmp_hr);
            }
            
            // $this->info($update.' '.$r['vfpdate']->format('Y-m-d H:i:s').' '.$r['cslipno'].' -2');
            $data[$tmp_hr][$update] = $this->aliGetTrans($date, $r); //****************************************************************************/

            $last_cslipno = $r['cslipno'];
          }



          if (is_null($data['EOD'][5]))
            $data['EOD'][5] = $r['cslipno'];
          
          $data['EOD'][6] = $r['cslipno'];
          $data['EOD'][7] += $r['chrg_grs'];


          if ($r['sr_disc']>0) {
            $data['EOD'][11] += $r['tot_chrg']; // vat exmpt sales
            $data['EOD'][12] += $r['vat_xmpt']; // vat exmpt samount
            $data['EOD'][69]++; // # of vat xmpt trans

            if (app()->environment()=='local')  {
              if ($r['sr_body']==1) { // if 1 lang senior
                if (str_contains($r['card_name'], 'PWD')) {
                  $data['EOD'][24] += $r['sr_disc']; // senior disc amt
                  $data['EOD'][25]++; // senior disc trx
                } else {
                  $data['EOD'][22] += $r['sr_disc']; // senior disc amt
                  $data['EOD'][23]++; // senior disc trx
                }
              } else {
                $data['EOD'][22] += $r['sr_disc']; // senior disc amt
                $data['EOD'][23]++; // senior disc trx
              }
            } else {
              $data['EOD'][22] += $r['sr_disc']; // senior disc amt
              $data['EOD'][23]++; // senior disc trx
            }
            
            $data['EOD'][107] += $r['sr_body']; // total customer
          } else {

            $data['EOD'][8] += $r['vat']; // vat amount
            $data['EOD'][9] += ($r['tot_chrg']-$r['vat']); // vatable amount



            // compute emp disc
            if ($r['dis_emp']>0) {
              $data['EOD'][26] +=$r['dis_emp']; // total disc amt
              $data['EOD'][27]++; // total disc trans
            }
            // compute other disc
            if ($r['dis_gpc']>0 || $r['dis_vip']>0 || $r['dis_pwd']>0 || $r['dis_udisc']>0 || $r['dis_prom']>0) {
              $data['EOD'][31] +=($r['dis_gpc']+$r['dis_vip']+$r['dis_pwd']+$r['dis_udisc']+$r['dis_prom']); // total disc amt
              $data['EOD'][32]++; // total disc trans
            }
            $data['EOD'][107] += ($r['sr_tcust']-$r['sr_body']); // total customer
          }

          if ($r['sr_disc']>0 || $r['oth_disc']>0 || $r['u_disc']>0 || $r['promo_amt']>0) {
            $data['EOD'][18] +=$r['disc_amt']; // total disc amt
            $data['EOD'][19]++; // total disc trans
          }


          // CASH or CHARGE sales
          if ($r['chrg_type']=='CASH') {
            $data['EOD'][35] += $r['tot_chrg']; //total cash sales
            $data['EOD'][72]++; // total cash trans
          } else 

          if (in_array($r['chrg_type'], ['CHARGE', 'MAYA', 'BDO', 'BANKARD'])) {

            switch (trim($r['card_type'])) {
              case 'MASTER':
                $data['EOD'][36] += $r['tot_chrg'];
                $data['EOD'][42] += $r['tot_chrg'];
                $data['EOD'][73]++;
                $data['EOD'][79]++;
                break;
              case 'VISA':
                $data['EOD'][36] += $r['tot_chrg'];
                $data['EOD'][43] += $r['tot_chrg'];
                $data['EOD'][73]++;
                $data['EOD'][80]++;
                break;
              case 'AMEX':
                $data['EOD'][36] += $r['tot_chrg'];
                $data['EOD'][44] += $r['tot_chrg'];
                $data['EOD'][73]++;
                $data['EOD'][81]++;
                break;
              case 'DINERS':
                $data['EOD'][36] += $r['tot_chrg'];
                $data['EOD'][45] += $r['tot_chrg'];
                $data['EOD'][73]++;
                $data['EOD'][82]++;
                break;
              case 'JCB':
                $data['EOD'][36] += $r['tot_chrg'];
                $data['EOD'][46] += $r['tot_chrg'];
                $data['EOD'][73]++;
                $data['EOD'][83]++;
                break;
              case 'GCASH':
                $data['EOD'][37] += $r['tot_chrg']; // EPAY_SLS
                $data['EOD'][47] += $r['tot_chrg']; // GCASH_SLS
                $data['EOD'][74]++; // NO_EPAY
                $data['EOD'][84]++;
                break;
              case 'MAYA':
                $data['EOD'][37] += $r['tot_chrg']; // EPAY_SLS
                $data['EOD'][48] += $r['tot_chrg']; // PAYMAYA_SLS
                $data['EOD'][74]++; // NO_EPAY
                $data['EOD'][85]++;
                break;
              case 'PAYMAYA':
                $data['EOD'][37] += $r['tot_chrg']; // EPAY_SLS
                $data['EOD'][48] += $r['tot_chrg']; // PAYMAYA_SLS
                $data['EOD'][74]++; // NO_EPAY
                $data['EOD'][85]++;
                break;
              case 'ALIPAY':
                $data['EOD'][37] += $r['tot_chrg']; // EPAY_SLS
                $data['EOD'][49] += $r['tot_chrg'];
                $data['EOD'][74]++; // NO_EPAY
                $data['EOD'][86]++;
                break;
              case 'ALI':
                $data['EOD'][37] += $r['tot_chrg']; // EPAY_SLS
                $data['EOD'][49] += $r['tot_chrg'];
                $data['EOD'][74]++; // NO_EPAY
                $data['EOD'][86]++;
                break;               
              case 'WECHATPAY':
                $data['EOD'][37] += $r['tot_chrg']; // EPAY_SLS
                $data['EOD'][50] += $r['tot_chrg'];
                $data['EOD'][74]++; // NO_EPAY
                $data['EOD'][87]++;
                break;
              case 'WECHAT':
                $data['EOD'][37] += $r['tot_chrg']; // EPAY_SLS
                $data['EOD'][50] += $r['tot_chrg'];
                $data['EOD'][74]++; // NO_EPAY
                $data['EOD'][87]++;
                break;
              default:
                $data['EOD'][39] += $r['tot_chrg']; // OTHER_SLS
                $data['EOD'][76]++; // NO_OTHER_SLS
                break;
            }
          } else

          if (in_array($r['chrg_type'], ['GRAB', 'PANDA'])) {
              // $data['EOD'][39] += $r['tot_chrg'];
            $data['EOD'][76]++;
            if ($r['chrg_type']=='GRAB') {
              $data['EOD'][51] += $r['tot_chrg'];
              $data['EOD'][88]++;
            }
            if ($r['chrg_type']=='PANDA') {
              $data['EOD'][52] += $r['tot_chrg'];
              $data['EOD'][89]++;
            }
          } else { // ZAP
            $data['EOD'][39] += $r['tot_chrg'];
            $data['EOD'][76]++;
          }

          $update++;  
          $data['EOD'][108]++;
          $data['EOD'][111] += $r['tot_chrg']; // NETSALES


          if (array_key_exists($r['vfpdate']->format('H'), $hrly_data)) {
            $hrly_data[$r['vfpdate']->format('H')]['gross'] += ($r['tot_chrg']); 
            $hrly_data[$r['vfpdate']->format('H')]['cnt']++; 
            $hrly_data[$r['vfpdate']->format('H')]['cslipno'] = $r['cslipno']; 
          } else {
            $hrly_data[$r['vfpdate']->format('H')]['gross'] = ($r['tot_chrg']); 
            $hrly_data[$r['vfpdate']->format('H')]['cnt'] = 1; 
            $hrly_data[$r['vfpdate']->format('H')]['cslipno'] = $r['cslipno']; 
          }

          // php artisan eod YYYY-MMM-DD --lessorcode=ali --payment=rerun
          if ($this->option('payment')=='rerun') {
            $trans = $this->aliGetTrans($date, $r);
            $this->aliGenerateCSVPosted($date, $trans, $r['cslipno']);
          } 

          // $this->info('r[cslipno]:'. $r['cslipno'] .'  last_cslipno:'.$last_cslipno.' flag:'.json_encode($flag));
        } // end: vfpdate == date

         // print_r($data);

        
      } // end:for 

       // print_r($data);
      // print_r($hrly_data);
      foreach($hrly_data as $hr => $hrly)
        $this->aliGenHourlyCsv($date, $data, $hr, $hrly['cslipno'], $head); /****************************************************************************/

      // $this->line($this->argument('date'));

      if (strtolower($this->argument('date')) !== 'hourly') {
        $this->aliGenHourlyTxt($date, $hrly_data);
        $this->aliGenEodCsv($date, $data['EOD']);
      } else {
        alog('HOURLY - Trigger: '.Carbon::now());
      }

      // print_r($data['EOD']);
      dbase_close($db);
      return $ds;
    } else {
      throw new Exception("Cannot locate CHARGES.DBF"); 
    }
  }

  private function aliGenHourlyTxt(Carbon $date, $data) {

    $tenantname = $this->aliGetTenantName();
    $ext = 'TXT';
    $datas = [];

    $filename = trim($this->sysinfo->contract).$date->format('md').'H';
    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m').DS.$date->format('d');
    if (!is_dir($dir))
      mdir($dir);
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');

    $datas[0] = ['TRANDATE','HOUR','SALES','TRANCNT','TENTNAME','TERMNUM'];

    $d = Carbon::parse($date->format('Y-m-d').' 06:00:00');

    for($i=1; $i<=24; $i++) {
      // $this->info($d->format('Y-m-d H:i:s'));

      $gross = $cnt = 0;
      if (array_key_exists($d->format('H'), $data)) {
        $gross = $data[$d->format('H')]['gross'];
        $cnt = $data[$d->format('H')]['cnt'];
      } 

      $datas[$i] = [
        $d->format('m/d/Y'), 
        $d->format('H:i'),
        number_format($gross, 2, '.', ''),
        $cnt,
        $tenantname,
        1,
      ];

      $d->addHour();
    }

    // print_r($datas);

    $this->toTXT($datas, $date, $filename, $ext, $dir, false);

    $file = $dir.DS.$filename.'.'.$ext;

    $newfile = $filename.'.'.$ext;

    $this->verifyCopyFile($file, $newfile);
  }

  private function aliGetTenantName() {

    switch (trim($this->sysinfo->gi_brcode)) {
      case 'ANG':
        return "GILIGAN'S ISLAND RESTAURANT";
      case 'CM1':
        return "GILIGAN'S RESTAURANT";
        break;
      case 'MAR':
      case 'AMK':
        return "GILIGAN'S";
        break;
      default:
        return trim($this->sysinfo->tenantname);
        break;
    }
  }


  /*********************************************************** end: ALI ****************************************/





  /*********************************************************** RLC ****************************************/


  public function RLC(Carbon $date) {
    
    $this->rlcSend($date);

   //  $this->info('info success!');
   //  $this->info('info success!');
   //  $this->alert('alert success!');
   //  $this->error('error success!');
   //  $this->line('line success!');
   //  $this->confirm('confirm success!');
   //  $this->ask('ask success!');
   //  $this->secret('secret success!');
   //  $this->anticipate('anticipate success!');
  }

  public function rlcSend(Carbon $date) {

    $tid = substr(trim($this->sysinfo->tenantname),4);
    $src_path = 'C:\RLC'.DS.$date->format('Y');
    $filename = $tid.$date->format('md');
    $fullpath = $src_path.DS.$filename.'.011';

    if (file_exists($fullpath)) {

      $j = $this->getJsonData($date);

      $ctr = isset($j['send_counter']) ? ($j['send_counter']+1) : 1;
    
      $d_ext = '01'.$ctr;
      $newfile = $filename.'.'.$d_ext;

      // $this->info($newfile);

      $this->rlcToStorage($date, $newfile);

      $this->toJson($date, ['send_counter'=>$ctr]);

      $auth_res = 0;
      $error_code = 0;

      if (trim($this->sysinfo->ftp_rlc)=='I') {
        $error_code = 400;
      } else if (trim($this->sysinfo->ftp_rlc)=='A') {
        try {
          $sftp = $this->rlcGetSftpServer();
        } catch (Exception $e) {
          // throw new Exception($e->getCode()); 
          $error_code = $e->getCode(); 
        }
      } else {
        $this->line('Wrong value on POS 5-3-3, FTP Connection.');
      }

      // $error_code = 500;
      if ($error_code == 500) {
        $this->line(' ');
        $this->error('Cannot connect to server '.trim($this->sysinfo->ftp_ip).'. Offline mode');
        $this->error('Sales file is not sent to RLC Server. Please contact your POS vendor.');
        $this->rlcStorageToUnsent($date, $newfile);
        exit;
      } else if ($error_code == 400) {
        $this->line(' ');
        $this->error('POS FTP connection set to (I) inactive. Offline mode');
        $this->error('Sales file is not sent to RLC Server. Please contact your POS vendor.');
        $this->rlcStorageToUnsent($date, $newfile);
        exit;
      } else {

        // $this->info('Login success!');
        // $this->info($fullpath.' '.$newfile);

        $success_send = $sftp->put($newfile, $fullpath, SFTP::SOURCE_LOCAL_FILE);

        if ($success_send) {
          
          $this->line(' ');
          $this->alert('Sales file successfully sent to RLC Server! ');

          $this->rlcStorageToSent($date, $newfile);

        } else {
          $this->error('Sales file is not sent to RLC Server. Please contact your POS vendor.');
          $this->rlcStorageToUnsent($date, $newfile);
        }

      } 
    } else {
      $this->line(' ');
      $this->error('Salesfile not found! ('.$fullpath.')');
    } // end: file_exists
  }

  private function rlcUnsent(Carbon $date) {

    $path = 'C:\RLC'.DS.'UNSENT'.DS.$date->format('Y');
    $files = array_diff(scandir($path), array('.'));
    array_shift($files);

    if (count($files)>0) {

      $this->line(' ');
      $this->alert(count($files).' unsent sales file.');
      
      // $this->info(print_r($files));
      foreach ($files as $k => $f)
        $this->info('       '.($k+1).'. '.$f);

      $res = $this->confirm('You want to send unsent sales file?');

      if ($res) {

        $error_code = 0;

        if (trim($this->sysinfo->ftp_rlc)=='I') {
          $error_code = 400;
        } else if (trim($this->sysinfo->ftp_rlc)=='A') {
          try {
            $sftp = $this->rlcGetSftpServer();
          } catch (Exception $e) {
            // throw new Exception($e->getCode()); 
            $error_code = $e->getCode(); 
          }
        } else {
          $this->line('Wrong value on POS 5-3-3, FTP Connection.');
        }

        if ($error_code == 500) {
          $this->line(' ');
          $this->error('Cannot connect to server '.trim($this->sysinfo->ftp_ip).'. Offline mode');
          $this->error('Sales file is not sent to RLC Server. Please contact your POS vendor.');
          exit;
       } else if ($error_code == 400) {
          $this->line(' ');
          $this->error('POS FTP connection set to (I) inactive. Offline mode');
          $this->error('Sales file is not sent to RLC Server. Please contact your POS vendor.');
          exit;
        } else {

          $bar = $this->output->createProgressBar(count($files));

          foreach ($files as $file) {

            // $this->line($path.DS.$file);
            $success_send = $sftp->put($file, $path.DS.$file, SFTP::SOURCE_LOCAL_FILE);

            if ($success_send) {
             
              $this->info(' '.$file.' sales file successfully sent to RLC Server! ');
             
              $this->rlcUnsentToSent($date, $file);

            } else {
              $this->error('Sales file is not sent to RLC Server. Please contact your POS vendor.');
            }

            // usleep(50000);
            // $bar->advance();
            
          }  
          $bar->finish();
          $this->info(' ');
        }
      } else {
        $this->info('Cancelled.');
      }
    } else {
      $this->line('No unsent files.');
    }
  }

  private function rlcGetSftpServer() {

    $msg = '';
    $auth_res = $ctr = 0;
    $try_connect = 10; 
    
    do {

      $sftp = new SFTP(trim($this->sysinfo->ftp_ip));
      // $sftp = new SFTP('rlccloud.robinsonsland.com');
      // $this->info('Try logging on: '. trim($this->sysinfo->ftp_ip));
       
      try {
        // $auth_res = $sftp->login('accredit', 'RLC@Partners');
        $auth_res = $sftp->login(trim($this->sysinfo->ftp_user), trim($this->sysinfo->ftp_pw));
      } catch (Exception $e) {
        $msg = $e->getMessage();
      } 

      // if(str_contains($msg, 'SSH'))
        // $this->info('msg:'.$msg);

      if(str_contains($msg, 'php_network_getaddresses')) {
        throw new Exception('Could not connect to RLC Server. Check network connection', 500);
        exit;
      }

      if(str_contains($msg, 'timeout') || str_contains($msg, 'timed out')) {
        throw new Exception('Could not connect to RLC Server. Check network connection', 500);
        exit;
      }

      $ctr++;
      usleep(50000);
    } while (intval($auth_res)==0 && $ctr<$try_connect);

    if ($ctr>=$try_connect) {
      throw new Exception('Could not connect to RLC Server. Connection timeout', 500);
      exit;
    }

    return $sftp;
  }

  private function rlcStorageToUnsent(Carbon $date, $filename) {

    $dir = 'C:\RLC'.DS.'UNSENT'.DS.$date->format('Y');
    $storage_dir = 'C:\RLC'.DS.'STORAGE'.DS.$date->format('Y');
    $fullpath = $storage_dir.DS.$filename;

    if (!is_dir($dir))
      mdir($dir);

    if (copy($fullpath, $dir.DS.$filename)) {
      // $this->info('Copying: '.$dir.DS.$filename);
    } else
      $this->error('Error copying: '.$dir.DS.$filename);
  }

  private function rlcUnsentToSent(Carbon $date, $filename) {

    $dir = 'C:\RLC'.DS.'FTP'.DS.$date->format('Y');
    $source_dir = 'C:\RLC'.DS.'UNSENT'.DS.$date->format('Y');
    $source_file = $source_dir.DS.$filename;

    if (!is_dir($dir))
      mdir($dir);

    if (copy($source_file, $dir.DS.$filename)) {
      if (file_exists($source_file)) 
        unlink($source_file);
    } else
      $this->error('Error copying: '.$dir.DS.$filename);
  }

  private function rlcStorageToSent(Carbon $date, $filename) {

    $dir = 'C:\RLC'.DS.'FTP'.DS.$date->format('Y');
    $source_dir = 'C:\RLC'.DS.'STORAGE'.DS.$date->format('Y');
    $source_file = $source_dir.DS.$filename;

    if (!is_dir($dir))
      mdir($dir);

    if (copy($source_file, $dir.DS.$filename)) {
      // $this->info('Copying: '.$dir.DS.$filename);
    } else
      $this->error('Error copying: '.$dir.DS.$filename);
  }

  private function rlcToStorage(Carbon $date, $new_filename) {

    $tid = substr(trim($this->sysinfo->tenantname),4);
    $src_path = 'C:\RLC'.DS.$date->format('Y');
    $filename = $tid.$date->format('md');
    $fullpath = $src_path.DS.$filename.'.011';

    $storage_dir = 'C:\RLC'.DS.'STORAGE'.DS.$date->format('Y');

    if (!is_dir($storage_dir))
      mdir($storage_dir);

    if (copy($fullpath, $storage_dir.DS.$new_filename)) {
        $this->info('OK - Copying: '.$storage_dir.DS.$new_filename);
        return true;
    } else {
        $this->info('ERROR - Copying: '.$storage_dir.DS.$new_filename);
        return false;
    }
  }

  /*********************************************************** end: RLC ****************************************/



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
    $row['saletype']      = trim($r['CUSFAX']);

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
