<?php namespace App\Console\Commands;

use Maatwebsite\Excel\Excel;
use stdClass;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;

class Eod extends Command
{
  protected $signature = 'eod {date : YYYY-MM-DD} {--ext=CSV : YYYY-MM-DD}';
  protected $description = 'Command description';
  private $excel;
  private $sysinfo;
  private $extracted_path;

  public function __construct(Excel $excel) {
      parent::__construct();
      $this->excel = $excel;
      $this->sysinfo();
      $this->extracted_path = 'C:\\GI_GLO';
      $this->lessor = ['pro'];
      $this->path = 'C:\\EOD_FILES';
  }

  public function handle() {

      $date = $this->argument('date');
      if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
        $this->info('Invalid date.');
        exit;
      }

      $ext = $this->option('ext');
      if (!in_array(strtolower($ext), ['txt', 'csv'])) {
        $this->info('Invalid file extension.');
        exit;
      }

      $date = Carbon::parse($date);

      $this->checkOrder();
      $this->checkCashAudit($date);

      $this->generateEod($date, $ext);
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
    if (starts_with($this->sysinfo->txt_path, 'C:\\'))
      return $this->sysinfo->txt_path;
    return $this->path;
  }

  private function toCSV($data, $filename=NULL, $ext='CSV') {

    $file = is_null($filename)
      ? Carbon::now()->format('YmdHis v')
      : $filename;

    $dir = $this->getpath();

    if(!is_dir($dir))
        mkdir($dir, 0775, true);

    $file = $dir.DS.$file.'.'.$ext;

    $fp = fopen($file, 'w');

    foreach ($data as $fields) {
     fputcsv($fp, $fields);
    }

    fclose($fp);
  }

  private function toTXT($data, $filename=NULL, $ext='TXT') {

    $file = is_null($filename)
      ? Carbon::now()->format('YmdHis v')
      : $filename;

    $dir = $this->getpath();

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

  private function generateEod(Carbon $date, $ext) {
    $lessor = strtolower($this->sysinfo->lessorcode);
    if (empty($lessor))
      throw new Exception("Error: LESSORINFO on SYSINFO.DBF is empty."); 
    if (!in_array($lessor, $this->lessor))
      throw new Exception("Error: No lessor found."); 

   if (!method_exists('\App\Console\Commands\Test', $lessor))
      throw new Exception("Error: No method ".$lessor." on this Class."); 

    $this->info('Generating file for: '.$lessor.' '.$date->format('Y-m-d'));

    $this->{$lessor}($date, $ext);
  }


  /*********************************************************** PRO ****************************************/
  public function PRO(Carbon $date, $ext) {

    $c = $this->proCharges($date);
    $s = $this->proSalesmtd($date);

    $this->proDaily($date, $c, $s, $ext);
    $this->proHourly($date, $s, $ext);
  }

  private function proDaily($date, $c, $s, $ext='CSV') {

    $filename = substr($this->sysinfo->tenantname, 0, 3).$this->sysinfo->pos_no.$date->format('dmy');

    $data[0] = ['TRANDATE', 'OLDGT', 'NEWGT', 'DLYSALE', 'TOTDISC', 'TOTREF', 'TOTCAN', 'VAT', 'TENTNME', 'BEGINV', 'ENDINV', 'BEGOR', 'ENDOR', 'TRANCNT', 'TOTQTY', 'SALETAX', 'SERVCHARGE', 'NOTAXSALE', 'OTHERS1', 'OTHERS2', 'OTHERS3', 'TERMINUM'];
    $data[1] = [
      $date->format('Ymd'), //TRANDATE
      number_format($this->sysinfo->grs_total - $c['eod']['grschrg'], 2,'.',''), //OLDGT
      number_format($this->sysinfo->grs_total, 2,'.',''), //NEWGT
      number_format($c['eod']['grschrg'] - $c['eod']['vat'], 2,'.',''), //DLYSALE
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
      0.00, //SALETAX
      0.00, //SERVCHARGE
      0.00, //NOTAXSALE
      0.00, //OTHERS1
      0.00, //OTHERS2
      0.00, //OTHERS3
      $this->sysinfo->pos_no //TERMINUM
    ];

    if (strtolower($ext)=='csv')
      $this->toCSV($data, $filename, $ext);
    else
      $this->toTXT($data, $filename, $ext);

    $f = $this->getpath().DS.$filename.'.'.$ext;
    if (file_exists($f)) {
      $this->info($f.' - Daily OK');
      return true;
    } else {
      $this->info($f.' - Error on generating');
      return false;
    }
  }

  private function proHourly(Carbon $date, $s, $ext='CSV') {

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
          $this->toCSV($data, $filename, $ext);
        else
          $this->toTXT($data, $filename, $ext);

        $f = $this->getpath().DS.$filename.'.'.$ext;
        if (file_exists($f)) {
          $this->info($f.' - Hourly OK');
        } else {
          $this->info($f.' - Error on generating');
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
      $ds['eod']['grschrg'] = 0;
      $ds['eod']['totdisc'] = 0;
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
          $ds['eod']['totdisc']  += ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);
          $ds['eod']['vat']      += $data['vat'];

          $h = substr($data['ordtime'], 0, 2);
          if (array_key_exists($h, $ds['hrly']))
            $ds['hrly'][$h] += $data['tot_chrg'];
          else
            $ds['hrly'][$h] = $data['tot_chrg'];

          
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

  /*********************************************************** PRO ****************************************/

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
    $disc_amt = NULL;
    $a = ['DIS_GPC', 'DIS_VIP', 'DIS_PWD', 'DIS_EMP', 'DIS_SR', 'DIS_UDISC', 'DIS_PROM'];
    foreach ($a as $key => $value) {
      if (isset($r[$value]) && $r[$value]>0) {
        $disc_type = explode('_', $value)[1];
        $disc_amt = $r[$value];
      } 
    }

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
