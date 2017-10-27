<?php
DEFINE('DEBUGDB',false);
/*
$db=new UserRepository();
print_r($db->getUserInfo('user_id_01'));
print_r($db->getUserInfo('user_id_02'));
print_r($db->getUserConfig('user_id_01'));
//*/
class Repository{
  // db name
  private $dsn='';
  // db user login and password
  private $user='';
  private $pass='';
  // PDO database handler and PDO statement
  private $hndl;
  private $stmt;
  // result
  public $result=array();

  function __construct(){
    $this->readConf();
    if(DEBUGDB){
      if($this->connect()){
        echo "PDO connected to db";
      }else{
        echo "PDO not connected to db";
      }
    }
  }
  private function connect(){
    if(DEBUGDB)echo "dsn is ".$this->dsn."\n";
    // set PDO options
    $opt=[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    try{
      switch(strtolower($this->dsn)){
        case 'mysql':
          if(DEBUGDB) echo "mysql is set\n";
          $dsn=$this->dsn.':host=localhost;dbname=VPN';
          if(DEBUGDB) echo $dsn.", ".$this->user.", ".$this->pass.", ".$opt."\n";
          $this->hndl=new PDO($dsn,$this->user,$this->pass,$opt);
          return true;
        default:
          return false;
      }
    }catch(PDOException $e){
      logErr("Error (db.class): " . $e->getMessage() . "\n");
    }
  }

  function __destruct(){
    // unsetting database handler and result
    $this->result=null;
    $this->stmt=null;
    $this->hndl=null;
  }

  //reading from manager.conf file and searching for db configs
  private function readConf(){
    $conf=file_get_contents(__DIR__."/../manager.conf");
    $confArr=explode("\n",$conf);
    $mysql=array('mysql','mariadb');
    foreach($confArr as $opt){
      if(substr(trim($opt),0,1)=='#'){
        if(DEBUGDB)echo "continue with: $opt\n";
        continue;
      }
      $tmp=explode('=',$opt);
      if(DEBUGDB)print_r($tmp);
      if(strtolower(trim($tmp[0]))=='db'){
        if(in_array(trim($tmp[1]),$mysql)){
          $this->dsn='mysql';
        }
      }elseif(strtolower(trim($tmp[0]))=='login'){
        $this->user=trim($tmp[1]);
      }elseif(strtolower(trim($tmp[0]))=='pass'){
        $this->pass=trim($tmp[1]);
      }
    }
    $confArr=null;
    $conf=null;
  }
// table users
  public function getUser($userId=''){
    $query='select id, status, expires from users';
    $params=array();
    if($userId!=''){
      $query.=' where id=:id';
      $params=array('id'=>$userId);
    }
    return $this->select('users',$query, $params);
  }
  public function getUserByStatus($status){
    $query='select id, status, expires from users where status=:status';
    $params=array('status'=>$status);
    return $this->select('users',$query, $params);
  }
  public function addUser($userId,$expires='',$status='new'){
    $query='insert into users (id, expires, status) values (:id, :expires, :status)';
    $params=array("id"=>$userId, "expires"=>$expires, "status"=>$status);
    return $this->execute('users',$query, $params);
  }
  public function checkExpired($currentDate){
    $query='select id, expires from users where expires < :expires';
    $params=array('expires'=>$currentDate);
    $ids=$this->select('users',$query, $params);
    $query='update users set status = "expired" where expires < :expires';
    $this->execute('users',$query, $params);
    return $ids;
  }
  public function updateUser($user=array()){
    //get assoc array as input where column names are keys
    $this->update('users',$user);
  }
  // table s_servers
  public function getServers($id=''){
    $query='select * from s_servers';
    $params=array();
    if($id!=''){
      $query.=' where id=:';
      $params=array('id'=>$id);
    }
    return $this->select('s_servers',$query, $params);
  }
  public function addServer($ip,$countryShort,$countryLong,$city,$status=''){
    $query='insert into s_servers (ip, country_short, country_long, city, status) values (:ip, :c_short, :c_long, :city, :status)';
    $params=array('ip'=>$ip, 'c_short'=>$countryShort, 'c_long'=>$countryLong, 'city'=>$city, 'status'=>$status);
    return $this->execute('s_servers',$query, $params);
  }
  public function updateServer($server=array()){
    //get assoc array as input where column names are keys
    $this->update('s_servers',$server);
  }
  // table tasks
  public function getTasks($userId, $sId=''){
    $query= 'select id, user_id, s_id, status from tasks where user_id = :user and status!= "expired" and status!= "revoke"';
    $params=array('user'=>$userId);
    if($sId!=''){
      $query.=' and s_id = :s';
      $params['s']=$sId;
    }
    return $this->select('tasks',$query, $params);
  }
  public function addTask($userId, $sId, $config, $status){
    $query= 'insert into tasks (user_id, s_id, task, status) values (:user, :s, :config, :status)';
    $params=array('user'=>$userId, 's'=>$sId, 'config'=>$config, 'status'=>$status);
    return $this->execute('tasks',$query, $params);
  }
  public function updateTask($task=array()){
    return $this->update('tasks', $task);
  }
  public function revokeTasks($userId){
    $query='update tasks set status="revoke" where id = :id';
    $params=array('id'=>$userId);
    return $this->execute('tasks',$query, $params);
  }
  //create query for update table
  private function update($table, $columns){
    if(!isset($columns['id']) || trim($columns['id'])=='' || strpos($columns['id'],'*')) {
      return array('error'=>8, "statement"=>"Can't update user without valid user id");
    }
    $valid=$this->validateColumns($table,$columns);
    if(isset($valid['error'])){
      return $valid;
    }
    $first=true;// for first = true, for all next = false
    $query='update '.$table.' set ';
    $keys=array_keys($valid);
    foreach($keys as $key){
      if($key=='id'){
        continue;
      }
      if($first){
        $query.=$key.' = :'.$key.' ';
      }else{
        $query.=', '.$key.' = :'.$key.' ';
      }
    }
    $query.=' where id = :id';
    if(DEBUGDB)echo "$query\n";
    return $this->execute($table,$query,$valid);
  }
  //validate columns
  private function validateColumns($table,$columns=array()){
    $valid=array();
    switch(strtolower($table)){
      case 'users':
        $valid=array( 'id', 'status', 'expires', 'note' );
        break;
      case 's_servers':
        $valid=array('id', 'ip', 'country_short', 'country_long', 'city', 'out_id', 'status', 'note');
        break;
      case 'tasks':
        $valid=array('id', 'user_id', 's_id', 'task', 'status');
        break;
      default:
        return array("error"=>9, "statement"=>"Unknown table $table set");
    }
    $result=array();
    $keys=array_keys($columns);
    foreach($keys as $key){
      if(in_array($key,$valid)){
        $result[$key]=$columns[$key];
      }
    }
    return $result;
  }
  // db operations
  private function select($table,$query,$params){
    try{
      if(DEBUGDB){
        echo "table= $table\n";
        echo "query: $query\n";
        print_r($params);
      }
      $this->execute($table,$query,$params);
      $this->result=$this->stmt->fetchAll();
      return $this->result;
    }catch(PDOException $e){
        logErr("Error (db): " . $e->getMessage() . "\n");
        return array('error'=>6, 'statement'=>$e->getMessage());
    }
  }
  private function execute($table,$query,$params){
    $this->result=array();
    $this->stmt=null;
    try{
      $this->stmt=$this->hndl->prepare($query);
      $this->bindParams($table,$params);
      return $this->stmt->execute();
    }catch(PDOException $e){
        logErr("Error (db): " . $e->getMessage() . "\n");
        return array('error'=>7, 'statement'=>$e->getMessage());
    }
  }
  private function bindParams($table,$params){
    $keys=array_keys($params);
    foreach ($keys as $key){
      if(($key=='id' && $table!='users') || $key=='s_id' || $key=='out_id'){
        $this->stmt->bindParam(':'.$key,$params[$key],PDO::PARAM_INT);
      }else{
        $this->stmt->bindParam(':'.$key,$params[$key],PDO::PARAM_STR);
      }
    }
  }
}

function logErr($str){
  if(DEBUGDB){
    echo "LOG: ".$str;
  }
  else {
    file_put_contents('/var/manager.log', date('m.d.Y h:i:s a', time()).' '.$str, FILE_APPEND);
  }
}
