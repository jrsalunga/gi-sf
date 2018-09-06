<?php namespace App\Console\Commands;

use Maatwebsite\Excel\Excel;
use stdClass;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;

class Eod extends Command
{
  protected $signature = 'eod {date : YYYY-MM-DD} {--lessorcode= : File Extension} {--ext=csv : File Extension}';
  protected $description = 'Command description';
  private $excel;
  private $sysinfo;
  private $extracted_path;

  public function __construct(Excel $excel) {
      parent::__construct();
      $this->excel = $excel;
      $this->sysinfo();
      $this->extracted_path = 'C:\\GI_GLO';
      $this->lessor = ['pro', 'aol', 'yic'];
      $this->path = 'C:\\EODFILES';
  }

  public function handle() {

      alog('Starting...');
      //$this->info($this->sysinfo->trandate);

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
      

      $lessorcode = $this->option('lessorcode');

      $date = Carbon::parse($date);

      $this->checkOrder();
      $this->checkCashAudit($date);

      $this->generateEod($date, $lessorcode, $ext);
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
    
    if (!in_array($lessor, $this->lessor)){
      alog('Error: No lessor found.');
      throw new Exception("Error: No lessor found."); 
    }

    if (!method_exists('\App\Console\Commands\Eod', $lessor)) {
      alog("Error: No method ".$lessor." on this Class.");
      throw new Exception("Error: No method ".$lessor." on this Class."); 
    }

    alog('Generating file for: '.$lessor.' '.$date->format('Y-m-d'));
    $this->info('Generating file for: '.$lessor.' '.$date->format('Y-m-d'));

    $this->{$lessor}($date, $ext);
  }

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


    $filename = substr($this->sysinfo->tenantcode, 0, 3).($this->sysinfo->pos_no+0).$date->format('jny').'R';
    $dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
    mdir($dir);
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');

    $header = ['DteTrnsctn', 'MrchntCd', 'RfndCncldCd', 'RfndCncldRsn', 'Amt', 'CntDcmnt', 'CntCstmr', 'CntSnrCtzn'];
    if (strtolower($ext)=='csv')
      fputcsv($fp, $header);
    else
      fwrite($fp, $header);
    
    fclose($fp);

    $f = $dir.DS.$filename.'.'.$ext;
    if (file_exists($f)) {
      $this->info($f.' - Refund/Cancelled OK');
      alog($f.' - Refund/Cancelled OK');
    } else {
      $this->info($f.' - Error on generating');
      alog($f.' - Error on generating');
    }
  }

  private function yicDiscount(Carbon $date, $s, $ext='csv') {

    if (count($s['disc'])>0) {

      $filename = substr($this->sysinfo->tenantcode, 0, 3).($this->sysinfo->pos_no+0).$date->format('jny').'D';
      $dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
      mdir($dir);
      $file = $dir.DS.$filename.'.'.$ext;
      $fp = fopen($file, 'w');

      $header = ['DteTrnsctn', 'MrchntCd', 'DscntCd', 'DscntPrcntg', 'Dscnt', 'CntDcmnt', 'CntCstmr', 'CntSnrCtzn'];
      if (strtolower($ext)=='csv')
        fputcsv($fp, $header);
      else
        fwrite($fp, $header);
      
      foreach ($s['disc'] as $key => $v) {

        $data = [
          $date->format('Y-m-d'), //TRANDATE
          substr($this->sysinfo->tenantcode, 0, 3),
          strtoupper($key), //PymntCd
          0.00, //DscntPrcntg
          number_format($v['amt'], 2,'.',''), //Dscnt
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

      $f = $dir.DS.$filename.'.'.$ext;
      if (file_exists($f)) {
        $this->info($f.' - Discount OK');
        alog($f.' - Discount OK');
      } else {
        $this->info($f.' - Error on generating');
        alog($f.' - Error on generating');
      }


    } else
      $this->info('No sales record found on SALESMTD.DBF');
  }

  private function yicPayment(Carbon $date, $s, $ext='csv') {

    if (count($s['payment'])>0) {

      $filename = substr($this->sysinfo->tenantcode, 0, 3).($this->sysinfo->pos_no+0).$date->format('jny').'P';
      $dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
      mdir($dir);
      $file = $dir.DS.$filename.'.'.$ext;
      $fp = fopen($file, 'w');

      $header = ['DteTrnsctn', 'MrchntCd', 'PymntCd', 'PymntDsc', 'PymntCdCLSCd', 'PymntCdCLSDsc', 'Pymnt'];
      if (strtolower($ext)=='csv')
        fputcsv($fp, $header);
      else
        fwrite($fp, $header);
      
      foreach ($s['payment'] as $key => $v) {

        $data = [
          $date->format('Y-m-d'), //TRANDATE
          substr($this->sysinfo->tenantcode, 0, 3),
          strtoupper($key), //PymntCd
          '', //PymntDsc
          '', //PymntCdCLSCd
          '', //PymntCdCLSDsc
          number_format($v, 2,'.',''), //QUANTITY SOLD
        ];

        if (strtolower($ext)=='csv')
          fputcsv($fp, $data);
        else
          fwrite($fp, $data);
      }
      fclose($fp);

      $f = $dir.DS.$filename.'.'.$ext;
      if (file_exists($f)) {
        $this->info($f.' - Payment OK');
        alog($f.' - Payment OK');
      } else {
        $this->info($f.' - Error on generating');
        alog($f.' - Error on generating');
      }
    } else
      $this->info('No sales record found on SALESMTD.DBF');
  }

  private function yicHourly(Carbon $date, $s, $ext='csv') {

    if (count($s['hrly'])>0) {

      $filename = substr($this->sysinfo->tenantcode, 0, 3).($this->sysinfo->pos_no+0).$date->format('jny').'H';
      $dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');
      mdir($dir);
      $file = $dir.DS.$filename.'.'.$ext;
      $fp = fopen($file, 'w');

      $header = ['DteTrnsctn', 'MrchntCd', 'HRLCd', 'DlySls', 'CntDcmnt', 'CntCstmr', 'CntSnrCtzn'];
      if (strtolower($ext)=='csv')
        fputcsv($fp, $header);
      else
        fwrite($fp, $header);
      
      foreach ($s['hrly'] as $key => $v) {

        $data = [
          $date->format('Y-m-d'), //TRANDATE
          substr($this->sysinfo->tenantcode, 0, 3),
          $key.':00', //HOUR
          number_format($v['sales'], 2,'.',''), //SALES
          number_format($v['ctr'], 0,'.',''), //QUANTITY SOLD
          number_format($v['cust'], 0,'.',''), //QUANTITY SOLD
          number_format($v['sr'], 0,'.',''), //QUANTITY SOLD
        ];

        if (strtolower($ext)=='csv')
          fputcsv($fp, $data);
        else
          fwrite($fp, $data);
      }
      fclose($fp);

      $f = $dir.DS.$filename.'.'.$ext;
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

  private function yicDaily($date, $c, $ext='csv') {

    $filename = substr($this->sysinfo->tenantcode, 0, 3).($this->sysinfo->pos_no+0).$date->format('jny').'S';
    $dir = 'D:\\'.substr($this->sysinfo->tenantcode, 0, 3).DS.$date->format('Y').DS.$date->format('n').DS.$date->format('j');

    $data[0] = ['DteTrnsctn', 'MrchntCd', 'MrchntDsc', 'GrndTtlOld', 'GrndTtlNew', 'GTDlySls', 'GTDscnt', 'GTDscntSNR', 'GTDscntPWD', 'GTDscntGPC', 'GTDscntVIP', 'GTDscntEMP', 'GTDscntREG', 'GTDscntOTH', 'GTRfnd', 'GTCncld', 'GTSlsVAT', 'GTVATSlsInclsv', 'GTVATSlsExclsv', 'OffclRcptBeg', 'OffclRcptEnd', 'GTCntDcmnt', 'GTCntCstmr', 'GTCntSnrCtzn', 'GTLclTax', 'GTSrvcChrg', 'GTSlsNonVat', 'GTRwGrss', 'GtLclTaxDly', 'WrksttnNmbr', 'GTPymntCSH', 'GTPymntCRD', 'GTPymntOTH'];
    $data[1] = [
      $date->format('Y-m-d'), //TRANDATE
      substr($this->sysinfo->tenantcode, 0, 3),
      substr(trim($this->sysinfo->tenantname), 0, 50),
      number_format($this->sysinfo->grs_total, 2,'.',''), //OLDGT
      number_format($this->sysinfo->grs_total + $c['eod']['sale'], 2,'.',''), //NEWGT
      number_format($c['eod']['sale'], 2,'.',''), //DLYSALE
      number_format($c['eod']['totdisc'], 2,'.',''), //TOTDISC
      number_format($c['eod']['dis_sr'], 2,'.',''),
      number_format($c['eod']['dis_pwd'], 2,'.',''),
      number_format($c['eod']['dis_gpc'], 2,'.',''),
      number_format($c['eod']['dis_vip'], 2,'.',''),
      number_format($c['eod']['dis_emp'], 2,'.',''),
      number_format($c['eod']['dis_prom'], 2,'.',''),
      number_format($c['eod']['dis_udisc'], 2,'.',''),
      0.00, //TOTREF
      0.00, //TOTCAN
      number_format($c['eod']['vat'], 2,'.',''),  // VAT
      number_format($c['eod']['vat_in'], 2,'.',''), //GTVATSlsInclsv
      number_format($c['eod']['vat_ex'], 2,'.',''), //GTVATSlsExclsv
      $c['eod']['begor'],  //BEGINV
      $c['eod']['endor'], //ENDINV
      $c['eod']['trancnt'], //TRANCNT
      $c['eod']['cust'], //TOTQTY
      $c['eod']['srcnt'], //TOTQTY
      0.00, //GTLclTax
      0.00, //GTSrvcChrg
      0.00, //GTSlsNonVat
      $c['eod']['grschrg'], //GTRwGrss
      0.00, //GtLclTaxDly
      ($this->sysinfo->pos_no+0), //TERMINUM
      number_format($c['eod']['sale_cash'], 2,'.',''),
      number_format($c['eod']['sale_chrg'], 2,'.',''),
      0.00, //GTPymntOTH
    ];

    if (strtolower($ext)=='csv')
      $this->toCSV($data, $date, $filename, $ext, $dir);
    else
      $this->toTXT($data, $date, $filename, $ext);

    $f = $dir.DS.$filename.'.'.$ext;
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

  private function yicCharges(Carbon $date) {

    $dbf_file = $this->extracted_path.DS.'CHARGES.DBF';
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      $update = 0;
      
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
          $ds['eod']['vat']      += $data['vat'];
          $ds['eod']['vat_xmpt'] += $data['vat_xmpt'];
          $ds['eod']['sale']     += $data['tot_chrg'];
          $ds['eod']['net']     += ($data['tot_chrg'] - $data['vat']);
          $ds['eod']['totdisc']  += ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);

          if ($data['dis_sr']>0 && $data['sr_body']!=$data['sr_tcust']) {
            // dont compute cust
          } else {
            $ds['eod']['cust']  += ($data['sr_body'] + $data['sr_tcust']);
          }

          if ($data['dis_sr']>0) {
            $ds['eod']['srcnt'] += $data['sr_body'];
            $non_tax_sale = ($data['vat_xmpt'] + $data['dis_sr']) / 0.285714286;
            $ds['eod']['vat_ex'] += $non_tax_sale;
          } else {
            $sale_tax = $data['chrg_grs'] / 1.12;
            $ds['eod']['vat_in'] += $sale_tax;
          }

          if ($data['dis_sr']>0) {
            $ds['eod']['dis_sr'] += $data['dis_sr'];
            if (array_key_exists('snr', $ds['disc'])){
              $ds['disc']['snr']['amt'] += $data['dis_sr'];
              $ds['disc']['snr']['cnt'] ++;
              $ds['disc']['snr']['snr'] += $data['sr_body'];
              $ds['disc']['snr']['cust']  += $data['sr_tcust'];
            } else {
              $ds['disc']['snr']['amt'] = $data['dis_sr'];
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
          } else{
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
  /*********************************************************** end: YIC ****************************************/

  /*********************************************************** AOL ****************************************/
  public function AOL(Carbon $date, $ext) {
    $c = $this->aolCharges($date);
    $this->aolDaily($date, $c);
  }

  private function aolDaily(Carbon $date, $c) {

    $ext = str_pad($this->sysinfo->pos_no, 3, '0', STR_PAD_LEFT);
    $filename = str_pad($this->sysinfo->zread_ctr, 4, '0', STR_PAD_LEFT).$date->format('md');
   
    //$this->info(' ');

    $dir = $this->getpath().DS.$date->format('Y').DS.$date->format('m');
    if(!is_dir($dir))
        mkdir($dir, 0775, true);
    $file = $dir.DS.$filename.'.'.$ext;
    $fp = fopen($file, 'w');

    $ctr = $this->sysinfo->zread_ctr>0
      ? $this->sysinfo->zread_ctr-1
      : 0;


    $data = [
      str_pad('OUTLETSLIPA', 12, ' ', STR_PAD_LEFT),
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
      str_pad(number_format($ctr, 0,'.',''), 12, '0', STR_PAD_LEFT),
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
      $ds['vat'] = 0;
      $ds['totdisc'] = 0;
      $ds['disccnt'] = 0;
      $ds['sale_cash'] = 0;
      $ds['sale_chrg'] = 0;
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
          $disc = ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);
          $ds['totdisc']  += $disc;
          if ($disc>0)
            $ds['disccnt']++;

          if (strtolower($data['terms'])=='charge')
            $ds['sale_chrg'] += $data['tot_chrg'];
          else
            $ds['sale_cash'] += $data['tot_chrg'];
          
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
      number_format($this->sysinfo->grs_total - ($c['eod']['grschrg'] - $c['eod']['vat']), 2,'.',''), //OLDGT
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
          $ds['eod']['vat']      += $data['vat'];
          $ds['eod']['sale']      += ($data['tot_chrg'] - $data['vat']);
          $ds['eod']['totdisc']  += ($data['promo_amt'] + $data['sr_disc'] + $data['oth_disc'] + $data['u_disc']);

          $h = substr($data['ordtime'], 0, 2);
          if (array_key_exists($h, $ds['hrly']))
            $ds['hrly'][$h] += ($data['tot_chrg'] - $data['vat']);
          else
            $ds['hrly'][$h] = ($data['tot_chrg'] - $data['vat']);

          
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
    $row['dis_gpc']       = trim($r['DIS_GPC']);
    $row['dis_vip']       = trim($r['DIS_VIP']);
    $row['dis_pwd']       = trim($r['DIS_PWD']);
    $row['dis_emp']       = trim($r['DIS_EMP']);
    $row['dis_sr']        = trim($r['DIS_SR']);
    $row['dis_udisc']     = trim($r['DIS_UDISC']);
    $row['dis_prom']      = trim($r['DIS_PROM']);

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
