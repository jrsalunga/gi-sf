<?php namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model {

  protected $connection = 'mysql-live';
	protected $table = 'branch';
  public $incrementing = false;

  //protected $guarded = ['id'];



}
