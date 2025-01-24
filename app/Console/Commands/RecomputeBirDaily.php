<?php namespace App\Console\Commands;

use stdClass;
use Exception;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RecomputeBirDaily extends Command
{
  
  protected $signature = 'bir:daily {brcode : Branch Code} {date : YYYY-MM-DD} {--dateTo=NULL : Date To} {--percentage=0 : Percentage} {--print=false : Print} {--final=false : Final}';

  protected $description = 'Command description';

  private $excel;
  private $sysinfo;
  private $extracted_path;

  public function __construct() {
      parent::__construct();
      $this->extracted_path = 'C:\\GI_GLO';
      $this->path = 'C:\\EOD_FILES';
  }

  /*
  Check muna ung CHARGES.DISC_AMT = 99.99

  select b.code, a.orddate, count(a.id) as txn
  from charges a
  left join branch b
  on a.branch_id = b.id
  where a.orddate between '2019-01-01' and '2019-12-31' and a.disc_type = 'SR' and a.disc_amt = '99.99'
  and b.code in ('TAY', 'HFT', 'VAL', 'CMC', 'HSL', 'KZA', 'VSP', 'SRC', 'STW', 'TUT', 'PPP', 'MIL', 'SDH', 'WVA', 'SAM', 'MOL', 'GTR', 'BAL')
  group by 1, a.orddate
  */

  public function handle() {



    $br = \App\Models\Branch::where('code', strtoupper($this->argument('brcode')))->first();
    if (!$br) {
      $this->info('Invalid Branch Code.');
      exit;
    }



    $date = $this->argument('date');
    if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date)) {
      $this->info('Invalid date.');
      exit;
    }

    $date = Carbon::parse($date);

    $to = $this->option('dateTo');
    if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $to)) {
      //$to = $date;        
      $to = $date->copy()->lastOfMonth();        
    } else {
      $to = Carbon::parse($to);
      if ($to->lt($date))
        $to = $date;        
    }
    

    $percent = $this->option('percentage');

    //\DB::connection('mysql-live')->enableQueryLog();
    //$this->info(json_encode(\DB::connection('mysql-live')->getQueryLog()));


    foreach ($this->dailyInterval($date, $to) as $key => $d) {
      
      $this->info($d);

      $date = $d;
      $to = $d;

      $i = [];
      $i['gross'] = 0;
      $i['xmpt'] = 0;
      $i['sr'] = 0;
      $i['disc'] = 0;
      $i['cash'] = 0;
      $i['charge'] = 0;



      $ds = $this->compute($br, $date, $to);


      // $this->info(print_r($ds));


      $i['gross']   = $ds['grschrg'];
      $i['xmpt']    = $ds['vat_ex'];
      $i['sr']      = $ds['sr_disc'];
      $i['disc']    = $ds['totdisc'];
      $i['cash']    = $ds['sale_cash'];
      $i['charge']  = $ds['sale_chrg'];

      //$filename = 'ZREAD-'.$date->format('Ymd').'-'.$to->format('Y-m-d');



      $lines = $this->arrayReceipt($br->code, $ds, $date, $to);

      
      foreach ($lines as $key => $line) {
        $this->info($line);
      }

      $this->toFile($br->code, $date, $to, $lines);


      // old format - mas mataas charge sales
      // $ds['sale_cash'] = number_format((($ds['sale']*($percent/100))-$ds['sale_chrg']), 2, '.', '');

      // new format - match sa percentage ung cash and charge sales
      $ds['sale_cash'] = number_format((($ds['sale_cash']*($percent/100))), 2, '.', '');
      $ds['sale_chrg'] = number_format((($ds['sale_chrg']*($percent/100))), 2, '.', '');


      $ds['sale'] = $ds['sale_cash'] + $ds['sale_chrg'];
      $ds['grschrg'] = $ds['sale'] + $ds['vat_ex'] + $ds['totdisc'];
      $ds['taxsale'] = $ds['sale'] - $ds['taxexsale'];
      $ds['vat'] = number_format(($ds['taxsale']/1.12)*.12,2, '.', '');




      //$this->info($ds['grschrg']);
      //$this->info($ds['sale']);
      //$this->info($ds['sale_cash']);
      //$this->info($ds['taxsale']);


      $lines = $this->arrayReceipt($br->code, $ds, $date, $to);

      
      foreach ($lines as $key => $line) {
        $this->info($line);
      }
      
      $path = $this->toFile($br->code, $date, $to, $lines, $percent);


      $dstfile = "D:\FJN6-ZREAD\\".$br->code."\\";

      if (!is_dir($dstfile))
        mkdir($dstfile, 0777, true);
     
      if ($this->option('final')=='true')
        $nfile = $dstfile.'ZREAD-'.$to->format('Ymd').'.txt';
      else
        $nfile = $dstfile.$date->format('Ymd').'-'.$to->format('Ymd').'-'.$percent.'.txt';
      copy($path, $nfile);
      
      $this->info($nfile);

      //$this->info(print_r($i));

    };

    exit;

      
     
   
      
      
  }

  public function dailyInterval($fr, $to){
    $fr = $fr->copy();
    $arr = [];
     do {
      array_push($arr, Carbon::parse($fr->format('Y-m-d')));
    } while ($fr->addDay() <= $to);

    return $arr;
  }

  private function compute($br, $date, $to) {
    $charges = \App\Models\Charges::where('branch_id', $br->id)
                                    ->whereBetween('orddate', [$date->format('Y-m-d'), $to->format('Y-m-d')])
                                    ->orderBy('cslipno')
                                    ->get();

    //$this->info($br->descriptor);
    //$this->info(count($charges));

    $ds = [];
    $ds['grschrg'] = 0;
    $ds['g_vatable'] = 0;
    $ds['g_nonvat'] = 0;
    $ds['sale'] = 0;
    $ds['vat'] = 0;
    $ds['totdisc'] = 0;
    $ds['disc'] = 0;
    $ds['sr_disc'] = 0;
    $ds['prom_disc'] = 0;
    $ds['othr_disc'] = 0;
    $ds['unit_disc'] = 0;
    $ds['sale_cash'] = 0;
    $ds['sale_chrg'] = 0;
    $ds['begor'] = NULL;
    $ds['endor'] = NULL;
    $ds['vat_ex'] = 0;
    $ds['taxsale'] = 0;
    $ds['taxexsale'] = 0;
    $ds['trx'] = 0;
    $ds['ctr'] = 0;

    


    $ctr = 0;

    foreach ($charges as $key => $c) {



      if ($c->terms=='CASH' || $c->terms=='CHARGE') {
      
        if ($ctr==0)
          $ds['begor'] = $c->cslipno;
        
        if ($c->sr_disc>0) {
          $ds['g_nonvat'] += $c->chrg_grs;
          $ds['sr_disc'] += $c->sr_disc;
          $ds['taxexsale'] += $c->tot_chrg;

        } else {
          $ds['g_vatable'] += $c->chrg_grs;
          $ds['vat'] += $c->vat;
          $ds['taxsale'] += $c->tot_chrg;
        }

        if ($c->promo_amt>0)  
          $ds['prom_disc'] += $c->promo_amt;

        if ($c->othdisc>0)  
          $ds['othr_disc'] += $c->othdisc;

        if ($c->udisc>0)  
          $ds['unit_disc'] += $c->udisc;


        if ($c->terms=='CASH')
          $ds['sale_cash'] += $c->tot_chrg;
        if ($c->terms=='CHARGE')
          $ds['sale_chrg'] += $c->tot_chrg;

    
        $ds['grschrg'] += $c->chrg_grs;
        $ds['sale'] += $c->tot_chrg;
        $ds['vat_ex'] += $c->vat_xmpt;
        $ds['trx']++;
        $ds['endor'] = $c->cslipno;


        $ctr++;
      }

      $ds['ctr']=$ctr;
      $ds['totdisc'] = $ds['unit_disc'] + $ds['sr_disc'] + $ds['othr_disc'] + $ds['prom_disc'];

      

      if ($c->terms=='CASH') {
        
      
      } else if ($c->terms=='CHARGE') {
        

      } else if ($c->terms=='SIGNED') {
        $this->info('SIGNED!'.' - '.$c->tot_chrg);
      } else {
        $this->info('OTHER PAYMENT');
      }

      



    }

    return $ds;
  }

  private function toFile($brcode, $fr, $to, $lines, $terminalid=NULL) {
    
    $logfile = is_null($terminalid)
      ? "C:\ZREPORT".DS.$brcode.DS.$fr->format('Y').DS.$fr->format('m').DS.'ZREAD-'.$fr->format('Ymd').'.txt'
      : "C:\ZREPORT".DS.$brcode.DS.$fr->format('Y').DS.$fr->format('m').DS.'ZREAD-'.$fr->format('Ymd').'-'.$terminalid.'.txt';

    $dir = pathinfo($logfile, PATHINFO_DIRNAME);

    if(!is_dir($dir))
      mkdir($dir, 0775, true);

    $new = file_exists($logfile) ? false : true;
    if($new){
      $handle = fopen($logfile, 'w+');
      chmod($logfile, 0775);
    } else
      $handle = fopen($logfile, 'w+');

    if (!is_null($lines)) {
      foreach ($lines as $key => $content) {
        fwrite($handle, $content.PHP_EOL);
      }
    }
    
    fclose($handle);

    return $logfile;
    
  }



  public function arrayReceipt($brcode, $ds, $fr, $to) {

    $heads = $this->getHeader($brcode);

    $lines = [];

    array_push($lines, bpad(' ', 40));
    foreach ($heads as $key => $h)
      array_push($lines, $h);
    
    array_push($lines, bpad(' ', 40));
    array_push($lines, bpad(' ', 40));

    array_push($lines, lpad("Z-READING REPORT :  ".$fr->format('m/d/Y D'), 40));
    // array_push($lines, lpad("TO : ".$to->format('m/d/Y D'), 40));

    // array_push($lines, bpad(' ', 40));
    array_push($lines, bpad(' ', 40));

    $sales = 0;
    array_push($lines, rpad('Gross Sales', 23).':'.lpad(number_format($ds['grschrg'], 2), 16)); $sales = $ds['grschrg'];
    array_push($lines, rpad(' less Discounts', 23).':'.lpad('('.number_format($ds['totdisc'], 2).')', 16)); $sales = $sales - $ds['totdisc'];
    array_push($lines, rpad(' less Tax Exemption', 23).':'.lpad('('.number_format($ds['vat_ex'], 2).')', 16)); $sales = $sales - $ds['vat_ex'];
    array_push($lines, rpad(' ', 23).' '.lpad('----------------', 16));
    array_push($lines, rpad(' ', 23).' '.lpad(number_format($sales, 2), 16)); 

    array_push($lines, bpad(' ', 40));

    array_push($lines, rpad('Daily Sales', 23).':'.lpad(number_format($ds['sale'], 2), 16)); 
    array_push($lines, rpad('Sales w/ Tax', 23).':'.lpad(number_format($ds['taxsale'], 2), 16)); 
    array_push($lines, rpad('Sales w/o Tax', 23).':'.lpad(number_format($ds['taxexsale'], 2), 16)); 
    array_push($lines, rpad('Taxes', 23).':'.lpad(number_format($ds['vat'], 2), 16)); 
    array_push($lines, rpad('Adjustments', 23).':'.lpad(number_format(0, 2), 16)); 
    array_push($lines, rpad('Void', 23).':'.lpad(number_format(0, 2), 16)); 
    array_push($lines, rpad('Refund', 23).':'.lpad(number_format(0, 2), 16)); 

    array_push($lines, bpad(' ', 40));

    $sales = 0;
    array_push($lines, rpad('Cash Sales', 23).':'.lpad(number_format($ds['sale_cash'], 2), 16)); $sales += $ds['sale_cash'];
    array_push($lines, rpad('Charge Sales', 23).':'.lpad(number_format($ds['sale_chrg'], 2), 16)); $sales += $ds['sale_chrg'];
    array_push($lines, rpad(' ', 23).' '.lpad('----------------', 16));
    array_push($lines, rpad('Total Sales', 23).':'.lpad(number_format($sales, 2), 16)); 

    array_push($lines, bpad(' ', 40));

    $disc = 0;
    array_push($lines, rpad('Promo Discounts', 23).':'.lpad(number_format($ds['prom_disc'], 2), 16)); $disc += $ds['prom_disc'];
    array_push($lines, rpad('Sr. Discounts', 23).':'.lpad(number_format($ds['sr_disc'], 2), 16)); $disc += $ds['sr_disc'];
    array_push($lines, rpad('Unit Discounts', 23).':'.lpad(number_format($ds['unit_disc'], 2), 16)); $disc += $ds['unit_disc'];
    array_push($lines, rpad('Other Discounts', 23).':'.lpad(number_format($ds['othr_disc'], 2), 16)); $disc += $ds['othr_disc'];
    array_push($lines, rpad(' ', 23).' '.lpad('----------------', 16));
    array_push($lines, rpad('Total Discounts', 23).':'.lpad(number_format($disc, 2), 16)); 

    array_push($lines, bpad(' ', 40));

    array_push($lines, rpad('Tax Exemption', 23).':'.lpad(number_format($ds['vat_ex'], 2), 16)); 
    array_push($lines, rpad('Service Charge', 23).':'.lpad(number_format(0, 2), 16)); 

    array_push($lines, bpad(' ', 40));


    $x = $ds['trx']==$ds['ctr'] ? ' ':'*';
    array_push($lines, rpad('First Trans.', 23).':'.lpad( $ds['begor'].'    ', 16)); 
    array_push($lines, rpad('Last Trans.', 23).':'.lpad( $ds['endor'].'    ', 16)); 
    array_push($lines, rpad('Trans. Count', 23).':'.lpad( $ds['trx'].' '.$x.'  ', 16)); 

    array_push($lines, bpad(' ', 40));

    array_push($lines, bpad(' ***** END OF REPORT *****', 40));

    array_push($lines, bpad(' ', 40));

    return $lines;
  }







  public function getHeader($brcode) {

    $lines = [];

    if ($brcode=='AVA') {
      array_push($lines, bpad("ALQUIROS FOOD CORP.", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S AVENUE OF THE ART", 40));
      array_push($lines, bpad("COR.STA.MONICA ST. ERMITA", 40));
      array_push($lines, bpad("MANILA CITY", 40));
      array_push($lines, bpad("#205-257-440-000 VAT", 40));
      array_push($lines, bpad("S/N Z5J7496FSWK7", 40));
      array_push($lines, bpad("MIN# 17010105520101695", 40));
      array_push($lines, bpad("PTU# FP012017-033-0110932-00003", 40));
    }

    if ($brcode=='SAM') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("STARMALL ALABANG", 40));
      array_push($lines, bpad("2/FLR STARMALL ALABANG", 40));
      array_push($lines, bpad("MUNTINLUPA CITY", 40));
      array_push($lines, bpad("#008-880-161-002 VAT", 40));
      array_push($lines, bpad("S/N W4Y16EK4", 40));
      array_push($lines, bpad("MIN# 15050409103797019", 40));
      array_push($lines, bpad("PTU# FP052015-53B-0032138-00002", 40));
    }

    if ($brcode=='GTR') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S GEN. TRIAS", 40));
      array_push($lines, bpad("V-CENTRAL MALL, GOVERNOR'S DRIVE", 40));
      array_push($lines, bpad("GEN. TRIAS CAVITE", 40));
      array_push($lines, bpad("#008-880-161-000 VAT", 40));
      array_push($lines, bpad("S/N WCC2ET1113758", 40));
      array_push($lines, bpad("MIN# 14120414145980894", 40));
      array_push($lines, bpad("PTU# FP122014-54B-0020679-00000", 40));
    }

    if ($brcode=='MOL') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S MOLINO", 40));
      array_push($lines, bpad("G/F V-CENTRAL MALL", 40));
      array_push($lines, bpad("MOLINO, BACOOR CAVITE", 40));
      array_push($lines, bpad("#008-880-161-001 VAT", 40));
      array_push($lines, bpad("S/N Z4Y1WHD5", 40));
      array_push($lines, bpad("MIN# 15002714563893426", 40));
      array_push($lines, bpad("PTU# FP032015-548-0029251-00001", 40));
    }

    if ($brcode=='BAL') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S SM BALIWAG", 40));
      array_push($lines, bpad("DONA REMEDIOS TRINIDAD HIGHWAY", 40));
      array_push($lines, bpad("BRGY.PAGALA BALIWAG, BULACAN", 40));
      array_push($lines, bpad("#008-880-161-003 VAT", 40));
      array_push($lines, bpad("S/N W4Y14YP7", 40));
      array_push($lines, bpad("MIN# 15052911552500946", 40));
      array_push($lines, bpad("PTU# FP052015-25A-0035285-00003", 40));
    }

    if ($brcode=='WVA') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S WILCON CITY CENTER", 40));
      array_push($lines, bpad("WILCON CITY CENTER MALL, #121 VISAYAS", 40));
      array_push($lines, bpad("AVE. BRGY. BAHAY TORO, QUEZON CITY", 40));
      array_push($lines, bpad("#008-880-161-004 VAT", 40));
      array_push($lines, bpad("S/N S4Y3VC1E", 40));
      array_push($lines, bpad("MIN# 15072907494412528", 40));
      array_push($lines, bpad("PTU# FP072015-038-0042760-00004", 40));
    }

    if ($brcode=='TAY') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S SM TAYTAY", 40));
      array_push($lines, bpad("GF BLDG. A EAST RD. BRGY. DOLORES", 40));
      array_push($lines, bpad("TAYTAY RIZAL", 40));
      array_push($lines, bpad("#008-880-161-005 VAT", 40));
      array_push($lines, bpad("S/N DIV052015-043", 40));
      array_push($lines, bpad("MIN# 15100211175525346", 40));
      array_push($lines, bpad("PTU# FP102015-046-0057072-00005", 40));
    }

    if ($brcode=='HFT') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S HYPERMARKET FTI", 40));
      array_push($lines, bpad("SM HYPERMARKET FTI", 40));
      array_push($lines, bpad("DBP AVENUE, TAGUIG CITY", 40));
      array_push($lines, bpad("#008-880-161-007 VAT", 40));
      array_push($lines, bpad("S/N S4Y45ZRD", 40));
      array_push($lines, bpad("MIN# 15120113590737963", 40));
      array_push($lines, bpad("PTU# FP122015-044-0066594-00007", 40));
    }

    if ($brcode=='VAL') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S SM CENTER VALENZUELA", 40));
      array_push($lines, bpad("202 MC ARTHUR HI-WAY KARUHATAN", 40));
      array_push($lines, bpad("VALENZUELA CITY", 40));
      array_push($lines, bpad("#008-880-161-008 VAT", 40));
      array_push($lines, bpad("S/N DIV092015-118", 40));
      array_push($lines, bpad("MIN# 16050711103263146", 40));
      array_push($lines, bpad("PTU# FP052016-024-0082775-00008", 40));
    }

    if ($brcode=='CMC') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S SM MEGACENTER", 40));
      array_push($lines, bpad("SM MEGACENTER SAN ROQUE,", 40));
      array_push($lines, bpad("CABANATUAN CTY, NUEVA ECIJA 3100", 40));
      array_push($lines, bpad("#008-880-161-009 VAT", 40));
      array_push($lines, bpad("S/N W4Y14XLM", 40));
      array_push($lines, bpad("MIN# 16030614420550661", 40));
      array_push($lines, bpad("PTU# FP032016-23B-0076250-00009", 40));
    }

    if ($brcode=='HSL') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S HYPERMART SUCAT-LOPEZ", 40));
      array_push($lines, bpad("108-109 SM HYPERMARKET SUCAT LOPEZ", 40));
      array_push($lines, bpad("BRANCH BRGY SAN ISIDRO PARANAQUE", 40));
      array_push($lines, bpad("#008-880-161-010 VAT", 40));
      array_push($lines, bpad("S/N WCC4J6SA7DDA", 40));
      array_push($lines, bpad("MIN# 16010708340942752", 40));
      array_push($lines, bpad("PTU# FP012016-052-0070268-00010", 40));
    }

    if ($brcode=='KZA') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S KCC MALL DE ZAMBOANGA", 40));
      array_push($lines, bpad("G/F KCC MALL DE ZAMBOANGA GOV.CAMINS", 40));
      array_push($lines, bpad("RD.CAMINO NUEVO ZAMBOANGA CITY 7000", 40));
      array_push($lines, bpad("#008-880-161-011 VAT", 40));
      array_push($lines, bpad("S/N Z4YAKD99", 40));
      array_push($lines, bpad("MIN# 16022417261949372", 40));
      array_push($lines, bpad("PTU# FP022016-93A-0075026-00011", 40));
    }

    if ($brcode=='VSP') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S VILLAR SIPAG", 40));
      array_push($lines, bpad("QUIRINO AVENUE C5 EXT ROAD QUIRINO", 40));
      array_push($lines, bpad("AVE PULANGLUPA UNO LAS PINAS CITY", 40));
      array_push($lines, bpad("#008-880-161-012 VAT", 40));
      array_push($lines, bpad("S/N 25DMZMNFS", 40));
      array_push($lines, bpad("MIN# 16031409260852042", 40));
      array_push($lines, bpad("PTU# FP032016-53A-0077107-00012", 40));
    }

    if ($brcode=='SRC') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S SM ROSARIO", 40));
      array_push($lines, bpad("GEN. TRIAS DRIVE COR COSTA VERDA RD.", 40));
      array_push($lines, bpad("BRGY. TEJERO, ROSARIO, CAVITE", 40));
      array_push($lines, bpad("#008-880-161-013 VAT", 40));
      array_push($lines, bpad("S/N DIV112015-156", 40));
      array_push($lines, bpad("MIN# 16041211070456072", 40));
      array_push($lines, bpad("PTU# FP042016-54B-0080256-00013", 40));
    }

    if ($brcode=='STW') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S SM TOWER MALL", 40));
      array_push($lines, bpad("SM TOWER AMLL, GOVERNOR'S DRIVE", 40));
      array_push($lines, bpad("TRECE MARTIRES CITY, CAVITE", 40));
      array_push($lines, bpad("#008-880-161-014 VAT", 40));
      array_push($lines, bpad("S/N DIV112015-157", 40));
      array_push($lines, bpad("MIN# 16040115282254757", 40));
      array_push($lines, bpad("PTU# FP042016-54A-0079224-00014", 40));
    }

    if ($brcode=='TUT') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S TUTUBAN CENTER", 40));
      array_push($lines, bpad("PB-GS02 PRIMEBLOCK TUTUBAN CENTER", 40));
      array_push($lines, bpad("CM RECTO AVE BRGY 048 TONDO MANILA", 40));
      array_push($lines, bpad("#008-880-161-015 VAT", 40));
      array_push($lines, bpad("S/N S4Y3YP8B", 40));
      array_push($lines, bpad("MIN# 16042123433357698", 40));
      array_push($lines, bpad("PTU# FP042016-029-0081403-00015", 40));
    }

    if ($brcode=='PPP') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S PUERTO PRINCESA CITY, PLWN", 40));
      array_push($lines, bpad("RIZAL AVENUE BRGY. TANGLAW", 40));
      array_push($lines, bpad("PUERTO PRINCESA CITY, PALAWAN", 40));
      array_push($lines, bpad("#008-880-161-016 VAT", 40));
      array_push($lines, bpad("S/N WCC3F3DTCLFA", 40));
      array_push($lines, bpad("MIN# 16052010174865037", 40));
      array_push($lines, bpad("PTU# FP052016-036-0083918-00016", 40));
    }

    if ($brcode=='MIL') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S MILLE LUCE", 40));
      array_push($lines, bpad("MILLE LUCE VILLAGE CENTER", 40));
      array_push($lines, bpad("ANTIPOLO CITY, RIZAL", 40));
      array_push($lines, bpad("#008-880-161-017 VAT", 40));
      array_push($lines, bpad("S/N S4Y3XPGS", 40));
      array_push($lines, bpad("MIN# 16060912021168268", 40));
      array_push($lines, bpad("PTU# FP062016-045-0086268-00017", 40));
    }

    if ($brcode=='SDH') {
      array_push($lines, bpad("FJN6 FOOD CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S DAANG HARI", 40));
      array_push($lines, bpad("MOLINO RD. MOLINO 4", 40));
      array_push($lines, bpad("BACOOR CAVITE", 40));
      array_push($lines, bpad("#008-880-161-018 VAT", 40));
      array_push($lines, bpad("S/N Z4YAL617", 40));
      array_push($lines, bpad("MIN# 16092713365584927", 40));
      array_push($lines, bpad("PTU# FP092016-54B-0098467-00018", 40));
    }

    if ($brcode=='GHL') {
      array_push($lines, bpad("GILIGAN'S ISLAND BAGUIO, INC.", 40));
      array_push($lines, bpad("(GILIGAN'S GREENHILLS)", 40));
      array_push($lines, bpad("PB-112 CONNECTICUT ARCADE GREENHILLS", 40));
      array_push($lines, bpad("SHOPPING CENTER SAN JUAN CITY", 40));
      array_push($lines, bpad("#006-070-024-015 VAT", 40));
      array_push($lines, bpad("S/N Z4YAL617", 40));
      array_push($lines, bpad("MIN# 19052919120338784", 40));
      array_push($lines, bpad("PTU# FP052019-042-0216077-00015", 40));
    }


    if ($brcode=='OMV') {
      array_push($lines, bpad("ALQUIROS, NIKKO ALEXANDER GARCIA", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S ONEMALL VALENZUELA", 40));
      array_push($lines, bpad("UNIT L2-06A ONEMALL VALENZUELA", 40));
      array_push($lines, bpad("BRGY.GEN.T.DELEON VALENZUELA CITY", 40));
      array_push($lines, bpad("449-124-012-004 VAT", 40));
      array_push($lines, bpad("S/N Z9A9T16H", 40));
      array_push($lines, bpad("MIN# 17110610273848996", 40));
    }

    if ($brcode=='FOR') {
      array_push($lines, bpad("ALQUIROS, NEIL ZACHARY GARCIA", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S FORA TAGAYTAY", 40));
      array_push($lines, bpad("UNIT 1010-1011 FORA, BRGY.SILANG", 40));
      array_push($lines, bpad("CROSSING EAST TAGAYTAY CITY, CAVITE", 40));
      array_push($lines, bpad("#488-179-314-006 VAT", 40));
      array_push($lines, bpad("S/N Z9ACOW8G", 40));
      array_push($lines, bpad("MIN# 130339991", 40));
    }

    if ($brcode=='TEL') {
      array_push($lines, bpad("ALQUIROS, FILIBERTO S.", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S SM TELABASTAGAN", 40));
      array_push($lines, bpad("SM CITY MACARTHUR HIGHWAY,BRGY TEL.", 40));
      array_push($lines, bpad("CITY OF SAN FERNANDO, PAMPANGA 2000", 40));
      array_push($lines, bpad(" #133-162-738-009 VAT", 40));
      array_push($lines, bpad("S/N HYSZ180000913", 40));
      array_push($lines, bpad("MIN# 18102517185907060", 40));
    }


    if ($brcode=='PBF') {
      array_push($lines, bpad("GILIGANS HOLDINGS CORPORATION", 40));
      array_push($lines, bpad("(GILIGAN'S RESTAURANT)", 40));
      array_push($lines, bpad("GILIGAN'S PUREGOLD BF", 40));
      array_push($lines, bpad("UNIT 3 PUREGOLD SOUTH PARK L. AVELI", 40));
      array_push($lines, bpad("NO ST.COR MONSERRAT ST.BF HOMES PQUE", 40));
      array_push($lines, bpad("#010-264-107-012 VAT", 40));
      array_push($lines, bpad("S/N J5LV622", 40));
      array_push($lines, bpad("MIN# 2003031629338986", 40));
    }

    return $lines;

  }




     
      


}
