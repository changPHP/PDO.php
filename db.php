<?php

class Error{    
    
    // store errors
    private $errors = array();
    
    // add errors
    protected function add_error($code,$str){
        $this->errors[$code]=$str;
    }
    // get errors
    function get_errors(){
        return $this->errors;
    }
}

class DB extends Error{

    private $db;
    private $sql='';
    private $replace=array();
    
    public $debug=false;
    public $lastInsertId='';
    public $return_type='object';
    
    
    // initialize connection
    function __construct(){
        try {
            $dsn="mysql:dbname=".DB_NAME.";host=".DB_HOST;
            $this->db = new PDO($dsn, DB_USER, DB_PASS);
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->db->query('SET NAMES GBK');
        } catch (PDOException $e) {
            print "Error!: " . $e->getMessage() . "<br/>";
            die();
        }
    }
    
    // initialize the sql query
    private function begin_query($type){
        $this->sql=$type.' ';
        $this->replace=array();
    }
    
    private function build_where($where){
        if(!empty($where)){
            $this->sql.=" WHERE ";
            $c=count($this->replace);
            foreach($where as $key=>$w){
                // look for any comparitive symbols within the where array value.
                if(substr($w,0,1)=='%'){
                    // prep the query for PDO->prepare
                    $this->sql.=$key.'=%:'.$c.'% && ';
                    $this->replace[':'.$c]=$w;
                }
                // check for null variables
                elseif(strtolower($w)=='null' || strtolower($w)=='!null' || $w==NULL){
                    if(substr($w,0,1)=='!')
                        $val='IS NOT NULL';
                    else
                        $val='IS NULL';
                    
                    $this->sql.=$key.' '.$val.' && ';
                }
                //check for comparative symbols
                else{
                    if(substr($w,0,2)=='<=')
                        $eq='<=';
                    elseif(substr($w,0,2)=='>=')
                        $eq='>=';
                    elseif(substr($w,0,1)=='>')
                        $eq='>';
                    elseif(substr($w,0,1)=='<')
                        $eq='<';
                    elseif(substr($w,0,1)=='!')
                        $eq='!=';
                    else
                        $eq='=';
                    
                    // prep the query for PDO->prepare
                    $this->sql.=$key.$eq.':'.$c.' && ';
                    $this->replace[':'.$c]=$w;
                }
                $c++;
            }
            $this->sql=substr($this->sql,0,-4);
        }
    }
    
    // remove slashes from all retrieved variables
    private function prep_vars($vars){
        if(is_array($vars)){
            foreach($vars as $key=>$value)
                $ret[$key]=$this->prep_vars($value);
        }
        elseif(is_object($vars)){
            $ret=new stdClass();
            foreach($vars as $key=>$value)
                $ret->$key=$this->prep_vars($value);
        }
        elseif(is_string($vars)){
            $ret=stripslashes($vars);
        }
        else{
            $ret=$vars;
        }
        return $ret;
    }
    
    // general query function
    function query($query,$vals=''){
        // double check the database connection object is working
        if(!$this->db){
            $this->add_error('000','Database connection failed.');
            return false;
        }   
        // prep
        $sth=$this->db->prepare($query);
                
        // do it
        if($sth){
            if($vals)
                $sth->execute($vals);
            else
                $sth->execute();
        }
        else{
            $this->get_sql_error($sth,'Error executing query');
            return false;
        }
        if (substr($query,0,6)=="SELECT") {
            //grab
            if($this->return_type=='object')
                $result=$sth->fetchAll(PDO::FETCH_OBJ);
            else
                $result=$sth->fetchAll(PDO::FETCH_ASSOC);
        }
        else {
            //return number of affected rows if not a SELECT query
            $result=$sth->rowCount();
        } 
        
        //find any errors
        $this->get_sql_error($sth);
        
        return $this->prep_vars($result);
    }
    
    // select and return only one row
    function select_one($table,$vals='*',$where=array(false),$extra=''){
        $s=$this->select($table,$vals,$where,$extra);
        return $s[0];
    }
    
    // select function
    function select($table,$vals='*',$where=array(false),$extra=''){
        // initialize the sql query
        $this->begin_query('SELECT');
        
        // add all the values to be selected 
        if(is_array($vals)){
            foreach($vals as $v)
                $this->sql.=$v.',';
            $this->sql=substr($this->sql,0,-1);
        }
        else
            $this->sql.=$vals;
        
        $this->sql.=' FROM '.$table;
        
        // build the WHERE portion of the query
        $this->build_where($where);
        
        $this->sql=$this->sql.' '.$extra;
        $ret=$this->query($this->sql,$this->replace);
        return $ret;
    }
    
    // insert
    function insert($table,$vals){
        $this->begin_query('INSERT INTO '.$table.' SET');
        
        // build the replace array and the query
        $c=count($this->replace);
        foreach($vals as $key=>$v){
            $this->sql.=$key.'=:'.$c.', ';
            $this->replace[':'.$c]=$v;
            $c++;
        }
        $this->sql=substr($this->sql,0,-2);
        // run and return the query
        $ret=$this->query($this->sql,$this->replace);
        $id=$this->db->lastInsertId();
        $this->lastInsertId=$id;
        
        if($id)
            return $id;
        else
            return $ret;
    }
    
    // update
    function update($table,$vals,$where=array(false)){
        $this->begin_query('UPDATE '.Table.' SET');
        
        // build the replace array and the query
        $c=count($this->replace);
        foreach($vals as $key=>$v){
            $this->sql.=$key.'=:'.$c.', ';
            $this->replace[':'.$c]=$v;
            $c++;
        }
        $this->sql=substr($this->sql,0,-2);
        
        // build the WHERE portion of the query
        $this->build_where($where);
        
        // run and return the query
        return $this->query($this->sql,$this->replace);
    }
    function delete($table,$where){
        $this->begin_query('DELETE FROM');
        
        // build the WHERE portion of the query
        $this->build_where($where);
        
        // run and return the query
        return $this->query($this->sql,$this->replace);
    }
    
    // get the number of records matching the requirements
    function get_count($table,$where=array(false)){
    
        $this->begin_query("SELECT COUNT(*) c FROM ".$table);
        
        // build the WHERE portion of the query
        if($where)
            $this->build_where($where);
        
        // run and return the query
        $sth=$this->db->prepare($this->sql);
        
        if($sth){            
            if($this->replace)
                $sth->execute($this->replace);
            else
                $sth->execute();
            $this->get_sql_error($sth);
            
            //get and return the count
            $result=$sth->fetchAll(PDO::FETCH_OBJ);
            return $result[0]->c;
        }
        else{
            $this->get_sql_error($sth,'ERROR RETRIEVING get_count');
            return false;
        }
    }
    
    // gets value of requested column
    function get_value($table,$val,$where=array(false)){
        // run query
        $o=$this->select($table,$val,$where);
        
        // convert first object in associative array to array
        if($o)
            $v=get_object_vars($o[0]);
        
        // return requested value
        return $v[$val];
    }
    
    //find any errors in the mysql statement
    private function get_sql_error($sth,$error_statement=''){
    // find the fail
        if($sth)
            $e=$sth->errorInfo();
        else
            $e=array('db error','',$error_statement);
        
        // catch any PDO errors and log them
        if($e[0]!='00000'){
            if($this->debug){
                if($e[2])
                    echo '<strong>ERROR:</strong>: '.$e[2];
                else
                    echo '<strong>ERROR:</strong>: General Error';
            }
            else{
                if($e[2])
                    $this->add_error($e[0],$e[2]);
                else
                    $this->add_error($e[0],'General Error upon execution');
            }
        }
        
        if($this->debug)
            $this->_get_query($this->sql,$this->replace,$e);
    }
    
    //debugging function
    private function _get_query($query,$val,$er=0){
        echo '<p>';
        if($val)
        foreach($val as $key=>$value){
            if(strtolower($value)=='null')
                $query=str_replace($key,"'".$value."'",$query);
            else
                $query=str_replace($key,"'".$value."'",$query);
        }
        echo '<strong>QUERY:</strong><br />'.$query;
        if($er){
            echo '<br /><br /><strong>Raw error:</strong><pre>';
            print_r($er);
            echo '</pre>';
        }
        echo '<br /><strong>DB.php status:</strong><br /><pre>';
        echo '$db->sql: ';print_r($this->sql);
        echo '<br />$db->replace: ';print_r($this->replace);
        echo '</pre>';
        echo '</p><hr />';
    }
}

?>
