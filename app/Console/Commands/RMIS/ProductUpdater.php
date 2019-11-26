<?php namespace App\Console\Commands\RMIS;

use stdClass;
use Exception;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProductUpdater extends Command
{
  
  protected $signature = 'rmis:product-updater';

  protected $description = 'update rmis.product based on gi_glo\product.dbf';

  private $product_path;
  private $backup_path;

  public function __construct() {
      parent::__construct();
      $this->product_path = 'C:\\GI_GLO\\PRODUCTs.DBF';
      $this->backup_path = 'D:\\Backups';
  }

  public function handle() {

    $this->getProductDbf();

    $products = \App\Models\RMIS\Product::orderBy('code')->get();

    $this->info(count($products));

    // foreach ($products as $key => $p) {
    //   $this->info($p->code.' '.$p->shortdesc.' '.$p->unitprice.' '.$p->descriptor);
    // }
  }



  private function getProductDbf() {

    if (!file_exists($this->product_path)) {
      throw new \Exception($this->product_path.' not found!');
      return false;
    }


    $dbf_file = $this->product_path;
    if (file_exists($dbf_file)) {
      $db = dbase_open($dbf_file, 0);
      
      $header = dbase_get_header_info($db);
      $record_numbers = dbase_numrecords($db);
      
      for ($i=1; $i<=$record_numbers; $i++) {
        $row = dbase_get_record_with_names($db, $i);
    

        $this->info($row['PRODNO']);

        $prod = \App\Models\RMIS\Product::where('code', trim($row['PRODNO']))->first();

        if (is_null($prod)) {

          $s = \App\Models\RMIS\Product::where('shortdesc', trim($row['PRODNAME']))->get();

          $data = [
            'code'        => trim(strtoupper($row['PRODNO'])),
            'descriptor'  => trim(strtoupper($row['PRODNAME'])),
            'shortdesc'   => count($s)>0 ? trim($row['PRODNAME']).' '.strtoupper($row['PRODNO']) : trim($row['PRODNAME']),
            'unitprice'   => trim($row['UPRICE']),
            'accountid'   => '698BCB934F7B4DA2A28A3B0D610FABF3',
            'vatcode'     => 'V',
            'inactive'    => 0,
          ];
          
          $n = \App\Models\RMIS\Product::create($data);

          is_null($n) ? $this->info('ERROR ON SAVING') : $this->info('SAVED!');

        } else {
          // $this->info($prod->shortdesc.' '.$prod->unitprice);

          $u = \App\Models\RMIS\Product::where('id', $prod->id)->update(['unitprice' => trim($row['UPRICE']), 'inactive' => 0]);
          
          is_null($u) ? $this->info('ERROR ON SAVING') : $this->info('UPDATED!');
        }

        $this->info(' ');
      }
    }


  }






}