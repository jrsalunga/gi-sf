<?php namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Charges extends Model {

  protected $connection = 'mysql-live';
	protected $table = 'charges';
  protected $fillable = ['cslipno', 'orddate', 'ordtime', 'tblno', 'chrg_type', 'chrg_pct', 'chrg_grs', 
                  'sr_tcust', 'sr_body', 'custcount', 'sr_disc', 'vat', 'bank_chrg', 'tot_chrg', 
                  'balance', 'terms', 'card_type', 'card_no', 'card_name', 'card_addr', 'tcash', 'tcharge', 
                  'tsigned', 'vat_xmpt', 'disc_type', 'disc_amt', 'cashier', 'remarks', 'branch_id'];
	//protected $guarded = ['id'];
  protected $appends = ['transdate'];
  protected $dates = ['orddate'];
	protected $casts = [
    'cslipno' => 'integer',
    'chrg_pct' => 'float',
    'chrg_grs' => 'float',
    'sr_tcost' => 'integer',
    'sr_body' => 'integer',
    'custcount' => 'integer',
    'sr_disc' => 'float',
    'vat' => 'float',
    'bank_chrg' => 'float',
    'tot_chrg' => 'float',
    'balance' => 'float',
    'tcash' => 'float',
    'tcharge' => 'float',
    'tsigned' => 'float',
    'vat_xmpt' => 'float',
    'disc_amt' => 'float',
  ];


  //public function branch() {
  //  return $this->belongsTo('App\Models\Branch');
  //}

  public function getTransdateAttribute(){
    return Carbon::parse($this->orddate->format('Y-m-d').' '.$this->ordtime);
  }




 



}
