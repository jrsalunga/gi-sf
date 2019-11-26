<?php namespace App\Models\RMIS;

use App\Models\BaseModel;

class Product extends BaseModel {

  protected $connection = 'rmis';
	protected $table = 'product';

  protected $guarded = ['id'];



}
