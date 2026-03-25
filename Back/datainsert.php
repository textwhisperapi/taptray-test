<?php 
$dataname = $_POST['dataname'];
$surrogate= $_POST['surrogate'];
$target= $_POST['target'];
$text = $_POST['text'];
//echo $dataname;
//echo $surrogate;
//echo " svar frá php forritinu ";
//echo $text;

     //$text= $_POST['myTextarea2'];
     //echo $text;


 //$con = mysql_connect('localhost','wecanrec_text','gotext');
//The new connection method
$con = mysqli_connect("localhost", "wecanrec_text", "gotext", "wecanrec_text");
 
 
// echo mysql_client_encoding();
 mysqli_query($con, "set collation_connection='utf8'" );


 if (!$con) {
     die('Could not connect: ' . mysqli_error($con));
 }

if (!mysqli_select_db($con, 'wecanrec_text')) {
   echo "Unable to select wecanrec_text: " . mysqli_error($con);
    exit;
}
mysqli_query($con, "set collation_connection='utf8_unicode_ci'" );

//$text=mysql_escape_mimic($text);
//$text=nl2br($text);
$text=addslashes($text);


$sql="SELECT * FROM text WHERE surrogate= '".$surrogate."'"; // and Owner = '".$target.'"";
//$sql="SELECT * FROM text WHERE surrogate= '".$surrogate."' and Dataname= '".$dataname."'";

$result = mysqli_query($con, $sql);

if(mysqli_num_rows($result) != 0) {

$sql = "UPDATE text SET Text = '".$text."', Dataname= '".$dataname."' WHERE surrogate= '".$surrogate."'";
//$sql = "UPDATE text SET Text = '".$text."' WHERE surrogate= '".$surrogate."'";
mysqli_query($con, $sql ) or trigger_error( mysql_error( $con ), E_USER_ERROR );
    if(mysqli_fetch_assoc($con, $result)['Dataname']!= $dataname){ 
    echo $surrogate;  //Return surrogate if dataname has changed
    }
}
else{

   $sql = "INSERT INTO text" .
      "(`Owner`, `Dataname`, `Text`, `Published`, `CreatedTime`, `CreatedUser`, `UpdatedTime`, `UpdatedUser`, `Surrogate`, `Category`, `Author`) ".
      "VALUES ('$target', '$dataname', '$text', '1', '2015-12-26 00:00:00', 'me', '2015-12-26 00:00:00', 'me', NULL, 'Poem', 'me')";

mysqli_query($con, $sql ) or trigger_error( mysql_error( $con ), E_USER_ERROR );


  // Get the last surrogate
echo mysqli_insert_id($con);

}

     
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