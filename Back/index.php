<?php 
include_once 'includes/db_connect.php';
include_once 'includes/functions.php';
sec_session_start();

//$user = $_GET['id']; 
$user = isset($_GET['id']) ? $_GET['id'] : 'default';
if ($user=='index.html') {
    $user='default';
}

#echo $user;
#echo $_SESSION['username'];
if (login_check($mysqli) == true) {
    $logged = 'in';
    $disp_login= 'Logout';
    if ($user == $_SESSION['username']){
        $checked = 'display:true;';
    } else {
        $checked = 'display:none;';  
    }

} else {
    $logged = 'out';
    $checked = 'display:none;';
    #$checked = 'display:true;';
    $disp_login= 'Login';
 
}
?>


<!DOCTYPE html>
<html>
<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
	<title></title>
<link rel="stylesheet" href="mystyle.css">
<link rel="stylesheet" href="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.css">

<script src="https://code.jquery.com/jquery-1.11.3.min.js"></script>
<script src="https://code.jquery.com/mobile/1.4.5/jquery.mobile-1.4.5.min.js"></script>
<script src="../scripts/jquery.printElement.js"></script>

</head>
<!--body onload="textInput()" onresize="textTrimmer()"-->

<body onload="getCombo('1')" onresize="textTrimmer()">
<body>
<style><!-- You have got no style :)--></style>
<div data-role="page">
  <div data-role="header" data-theme="b" id="header" data-position="fixed" >
    <h1 style="text-align:left; margin-left:10px;">
     <a href="#myPopup" data-rel="popup">
     <!--"#myPopup" data-rel="popup" class="ui-btn ui-btn-b ui-btn-inline ui-corner-all"-->
     <img src="./img/wrt.png" style="width:28px;height:20px;" align="left" /> 
     </a>
    <div data-role="popup" id="myPopup" class="ui-content" data-theme="b">
      <a href="#" data-rel="back" class="ui-btn ui-btn-a ui-corner-all ui-shadow ui-btn ui-icon-delete ui-btn-icon-notext ui-btn-right">Close</a>
      <p>Recall what you have already learned whether <br>
      it is a poem or a speech you need to memorize. <br>
      Paste the desired text to the box.</p>
    </div>
    MyTextWhisper <? echo $user, ' - ',$logged ?> </h1>
  </div>
  <div data-role="main" class="ui-content" id="pagemain"> 
    <div id="section2">
      <form>
      <select name="s" id='myCombo'>
  
      </select>
      </form>  
      
      
      <form method="post">

        <button type="button" id="button2" onclick=deleteData('public') style="display:none;">Delete</button>
        <button type="button" id="button1" onclick=insertData('public') style="display:none;">Save</button>

        <button type="button" id="button3" onclick=printTextArea()>Print</button>
        <button type="button" id="button3" onclick="location.href='login.php';" > <? echo $disp_login ?> </button>

        <input type="checkbox" id="editCheck" margin="0"  unchecked style=<? echo $checked ?>;">

        
        <div slider_holder>
        <!--label for="points">Slide to recall:</label-->
        <br>
        <input onchange="textTrimmer()" type="range" name="points" id="b" value="0" min="0" max="12" >
        </div>
      </form>
        <input type="hidden" name="user" id="user" value='<?php echo $user; ?>'>

      <div class="container" id="container">
          <textarea cols="45" wrap="soft" id="myTextarea" onclick="return myTextarea_onclick()" oninput="textInput()" rows="30">
          </textarea
      </div>
          <textarea cols=30 wrap="hard" name="myTextarea2" id="myTextarea2" rows="30" action="" method="post">
          </textarea>
      </p>
      
      <script type="text/javascript" src="/JSFunction.js"></script>
      
    </div>
  </div>
</div>

</body>

</html>
