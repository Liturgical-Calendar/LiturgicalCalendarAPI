<?php 

***REMOVED***
***REMOVED***
***REMOVED***
***REMOVED***
***REMOVED***

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
