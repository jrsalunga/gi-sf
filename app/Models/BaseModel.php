<?php namespace App\Models;

use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;

abstract class BaseModel extends Model {

  public $timestamps = false;
  public $incrementing = false;



  /******* this is a substitute from Illuminate\Database\Eloquent\Model ******
   * Perform a model insert operation.
   *
   * @param  \Illuminate\Database\Eloquent\Builder  $query
   * @param  array  $options
   * @return bool
   */
  protected function performInsert(Builder $query, array $options = [])
  {
    if ($this->fireModelEvent('creating') === false) {
      return false;
    }

    // First we'll need to create a fresh query instance and touch the creation and
    // update timestamps on this model, which are maintained by us for developer
    // convenience. After, we will just continue saving these model instances.
    if ($this->timestamps && Arr::get($options, 'timestamps', true)) {
      $this->updateTimestamps();
    }

    // If the model has an incrementing key, we can use the "insertGetId" method on
    // the query builder, which will give us back the final inserted ID for this
    // table from the database. Not all tables have to be incrementing though.
    $attributes = $this->attributes;
    if (empty($attributes['id'])) {
      $attributes['id'] = $this->get_uid();
      $this->setAttribute('id', $attributes['id']);
    }

    if ($this->incrementing) {
      $this->insertAndSetId($query, $attributes);
    } 

    // If the table isn't incrementing we'll simply insert these attributes as they
    // are. These attribute arrays must contain an "id" column previously placed
    // there by the developer as the manually determined key for these models.
    else {
      $query->insert($attributes);
    }

    // We will go ahead and set the exists property to true, so that it is set when
    // the created event is fired, just in case the developer tries to update it
    // during the event. This will allow them to do so and run an update here.
    $this->exists = true;

    $this->wasRecentlyCreated = true;

    $this->fireModelEvent('created', false);

    return true;
  }

  public static function get_uid(){
    $uid = self::raw_uid();
    # $uid = '7ec394d3-1507-11e7-9eff-1c1b0d85a7e0' 
    # SUBSTR($uid, 0, 8) - SUBSTR($uid, 9, 4) - SUBSTR($uid, 14, 4) - SUBSTR($uid, 19, 4) - SUBSTR($uid, 24)
    
    /* for mysql */
    //return strtoupper(SUBSTR($uid, 14, 4).SUBSTR($uid, 9, 4).SUBSTR($uid, 19, 4).SUBSTR($uid, 24).SUBSTR($uid, 0, 8));
    
    //$uid = strtoupper(SUBSTR($uid, 14, 4).SUBSTR($uid, 10, 4).SUBSTR($uid, 1, 8).SUBSTR($uid, 20, 4).SUBSTR($uid, 24));
    //return str_replace("-", "", $uid);
    
    /* for ramsey/uuid */
    return strtoupper(SUBSTR($uid, 14, 4).SUBSTR($uid, 9, 4).SUBSTR($uid, 24).SUBSTR($uid, 0, 8).SUBSTR($uid, 19, 4));
  }

  public static function raw_uid() {
    //$id = \DB::select('SELECT UUID() as id');
    //$id = array_shift($id);
    //return $id->id;

    // Ramsey\Uuid\Uuid not gererating sequence id
    try {
      
      // Generate a version 1 (time-based) UUID object
      $uuid1 = Uuid::uuid1();
      return $uuid1->toString(); // i.e. e4eaaaf2-d142-11e1-b3e4-080027620cdd
      /*
      // Generate a version 3 (name-based and hashed with MD5) UUID object
      $uuid3 = Uuid::uuid3(Uuid::NAMESPACE_DNS, 'php.net');
      echo $uuid3->toString() . "\n"; // i.e. 11a38b9a-b3da-360f-9353-a5a725514269
   
      // Generate a version 4 (random) UUID object
      $uuid4 = Uuid::uuid4();
      echo $uuid4->toString() . "\n"; // i.e. 25769c6c-d34d-4bfe-ba98-e0ee856f3e7a
   
      // Generate a version 5 (name-based and hashed with SHA1) UUID object
      $uuid5 = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'php.net');
      echo $uuid5->toString() . "\n"; // i.e. c4a760a8-dbcf-5254-a0d9-6a4474bd1b62
      */
    } catch (UnsatisfiedDependencyException $e) {
     
      //throw $e;
      $id = \DB::select('SELECT CONCAT(substr(UUID(),15,4),substr(UUID(),10,4),substr(UUID(),25,12),substr(UUID(),1,8),substr(UUID(),20,4)) as id');
      $id = array_shift($id);
      return $id->id;
    }

  }

  public static function get_uid_old(){
    $id = \DB::select('SELECT UUID() as id');
    $id = array_shift($id);
    $uid = $id->id;
    # $uid = '7ec394d3-1507-11e7-9eff-1c1b0d85a7e0' 
    # SUBSTR($uid, 0, 8) - SUBSTR($uid, 9, 4) - SUBSTR($uid, 14, 4) - SUBSTR($uid, 19, 4) - SUBSTR($uid, 24)
    return strtoupper(SUBSTR($uid, 14, 4).SUBSTR($uid, 9, 4).SUBSTR($uid, 19, 4).SUBSTR($uid, 24).SUBSTR($uid, 0, 8));
    //$uid = strtoupper(SUBSTR($uid, 14, 4).SUBSTR($uid, 10, 4).SUBSTR($uid, 1, 8).SUBSTR($uid, 20, 4).SUBSTR($uid, 24));
    //return str_replace("-", "", $uid);
  }

  public static function get_uid2(){
    $id = \DB::select('SELECT UUID() as id');
    $id = array_shift($id);
    return strtoupper(str_replace("-", "", $id->id));
  }

  public function getUuid(){
    return strtoupper(md5(uniqid()));
  }


  public function next($fields = ['id']) {
    $class = get_called_class();
    $res = $class::where('id', '>', $this->id)->orderBy('id', 'ASC')->get($fields)->first();
    return !empty($res) ? $res : 'false';
  }

  public function previous($fields = ['id']) {
    $class = get_called_class();
    $res = $class::where('id', '<', $this->id)->orderBy('id', 'DESC')->get($fields)->first();
    return !empty($res) ? $res : 'false';
  }

  public function lid(){
    return strtolower($this->id);
  }

  public function lcode(){
    return strtolower($this->code);
  }

  public function nextByField($field = 'id'){
    $res = $this->query()->where($field, '>', $this->{$field})->orderBy($field, 'ASC')->get()->first();
    return $res==null ? 'false':$res;
  }

  public function previousByField($field = 'id'){
    $res = $this->query()->where($field, '<', $this->{$field})->orderBy($field, 'DESC')->get()->first();
    return $res==null ? 'false':$res;
  }

  public function firstRecord($field = 'id'){
    $res = $this->query()->orderBy($field, 'ASC')->get()->first();
    return $res==null ? 'false':$res;
  }

  public function lastRecord($field = 'id'){
    $res = $this->query()->orderBy($field, 'DESC')->get()->first();
    return $res==null ? 'false':$res;
  }


  

  


  public function getDaysByWeekNo($weekno='', $year=''){
    $weekno = (empty($weekno) || $weekno > $this->lastWeekOfYear()) ? date('W', strtotime('now')) : $weekno;
    $year = empty($year) ?  date('Y', strtotime('now')) : $year;
    for($day=1; $day<=7; $day++) {
        $arr[$day-1] = date('Y-m-d', strtotime($year."W".str_pad($weekno,2,'0',STR_PAD_LEFT).$day));
    }
    return $arr;
  }

  public function lastWeekOfYear($year='') {
    $year = empty($year) ? date('Y', strtotime('now')):$year;
    $date = new \DateTime;
    $date->setISODate($year, 53);
    return ($date->format("W") === "53" ? 53 : 52);
  }

  public function firstDayOfWeek($weekno='', $year=''){
    $weekno = empty($weekno) ? date('W', strtotime('now')) : $weekno;
    $year = empty($year) ? date('Y', strtotime('now')) : $year;
    $dt = new DateTime();
    $dt->setISODate($year, $weekno);
    return $dt;
  }

  public function carbonDate($date=''){
    return Carbon::parse(date('Y-m-d', strtotime($date)));
  }
  



  public static function getLastDayLastWeekOfYear($year=""){
      
      $year = empty($year) ?  date('Y', strtotime('now')) : $year;
      $day = 31;
      $init_weekno = date("W", mktime(0,0,0,12,$day,$year));
      //echo $init_weekno.'<br>';

      $weekno = 0;
      while ($init_weekno == '01') {
        $weekno = $init_weekno;
        $init_weekno = date("W", mktime(0,0,0,12,$day,$year));
        //echo '12/'.$day.'/'.$year.'<br>';
        $day--;
      }
      $weekno = date("W", strtotime($year.'-12-'.$day));
      return ['date' => $year.'-12-'.$day, 'weekno' => $weekno];
    }


  public function getRefno($len = 8){
    return str_pad((intval(\DB::table($this->table)->max('refno')) + 1), $len, '0', STR_PAD_LEFT);
  }




  public function get_date($field) {
    return !is_null($this->{$field})&&is_iso_date($this->{$field}->format('Y-m-d'))
      ? $this->{$field}->format('Y-m-d')
      : NULL;
  }
  
}
