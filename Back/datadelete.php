<?php
$dataname = $_POST['dataname'];
$surrogate= $_POST['surrogate'];
echo $dataname;
echo $surrogate;
echo " deleting! ";
//echo $text;

     //$text= $_POST['myTextarea2'];
     //echo $text;


// $con = mysql_connect('localhost','wecanrec_text','gotext');
 
//The new connection method
$con = mysqli_connect("localhost", "wecanrec_text", "gotext", "wecanrec_text");


 
// echo mysql_client_encoding();
mysqli_query($con, "set collation_connection='utf8'" );


 
// if (!$con) {
//     die('Could not connect: ' . mysqli_error($con));
// }
 
if (mysqli_connect_errno()) {
  echo "Failed to connect to MySQL: " . mysqli_connect_error();
  exit();
}

if (!mysqli_select_db($con, 'wecanrec_text')) {
   echo "Unable to select wecanrec_text: " . mysqli_error($con);
    exit;
}
mysqli_query($con,  "set collation_connection='utf8_unicode_ci'" );

$text=addslashes($text);

$sql="update text set deleted= 'D' WHERE Surrogate= '".$surrogate."' and Dataname= '".$dataname."'";

mysql_query( $con, $sql ) or trigger_error( mysqli_error( $con ), E_USER_ERROR );
     
 mysqli_close($con);
 
 function mysql_escape_mimic($inp) { 
    if(is_array($inp)) 
        return array_map(__METHOD__, $inp); 

    if(!empty($inp) && is_string($inp)) { 
      return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp); 
    } 

    return $inp; 
} 
 
 ?>