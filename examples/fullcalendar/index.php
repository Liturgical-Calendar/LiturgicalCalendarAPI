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
</body>
