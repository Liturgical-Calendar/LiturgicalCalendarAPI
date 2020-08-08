<?php 

define("DB_USER","bibleget");
define("DB_PASSWORD","fxVr79&9");
define("DB_NAME","biblegetio");
define("DB_CHARSET","utf8");
define("DB_SERVER","localhost");

$mysqli = new mysqli(DB_SERVER, DB_USER, DB_PASSWORD, DB_NAME);
$mysqli->set_charset(DB_CHARSET);

?>

<!DOCTYPE html>
<head>

</head>
<body>
    <ul>
<?php
foreach (glob("examples/*.html") as $filename) {
    echo '<li><a href="' . $filename . '">' . $filename . '</a></li>';
}
?>
</ul>
<pre>
<?php 
if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StLawrenceDeacon'")) {
    $row = mysqli_fetch_assoc($result);
    print_r($row);
}
?>
</pre>
</body>
