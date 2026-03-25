<?php 
//$q = intval($_GET['q']);
 $q = $_GET['q'];
//echo "getText q";
//echo $q;


 //$con = mysql_connect('localhost','wecanrec_text','gotext');
 //$con =mysqli_connect('localhost', 'wecanrec_text', 'gotext', 'wecanrec_text');
 

//The new connection method
$con = mysqli_connect("localhost", "wecanrec_text", "gotext", "wecanrec_text");
 
 
if (mysqli_connect_errno()) {
  echo "Failed to connect to MySQL: " . mysqli_connect_error();
  exit();
}

// if (!$con) {
//     die('Could not connect: ' . mysqli_error($con));
// }



//if (!mysqli_select_db(!$con, 'wecanrec_text')) {
//   echo "Unable to select wecanrec_text: " . mysqli_error($con);
//    exit;
//}

// Perform a query, check for error
$sql="SELECT * FROM text WHERE surrogate= '".$q."'";
if (!mysqli_query($con,$sql)) {
  echo("Error description: " . mysqli_error($con));
  exit();
}

//mysql_query( "set collation_connection='utf8_unicode_ci'" );
//$mysqli->set_charset('utf8mb4');

mysqli_query($con, "set collation_connection='utf8'" );
//mysqli_query("SET character_set_results = 'utf8', character_set_client = 'utf8', character_set_connection = 'utf8', character_set_database = 'utf8', character_set_server = 'utf8'", $con);

$sql="SELECT * FROM text WHERE surrogate= '".$q."'";


 
$result = mysqli_query($con, $sql);


if(mysqli_num_rows($result) == 0) {
 //echo "Could not successfully run query  ($sql) from DB: " . mysql_error();
 echo "Subject...

Body...";
 exit;
 }
 while($row =  mysqli_fetch_assoc($result)) {
     //utf8_encode_deep($row);
     echo $row['Text'];
     }
     
 mysqli_close($con);
 
// The function utf8_encode_deep
function utf8_encode_deep(&$input) {
    if (is_string($input)) {
        $input = utf8_encode($input);
    } else if (is_array($input)) {
        foreach ($input as &$value) {
            utf8_encode_deep($value);
        }

        unset($value);
    } else if (is_object($input)) {
        $vars = array_keys(get_object_vars($input));

        foreach ($vars as $var) {
            utf8_encode_deep($input->$var);
        }
    }
}
 ?>