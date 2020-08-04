<!doctype html>
<head>
    <title>Generate Roman Calendar</title>
    <meta charset="UTF-8">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <meta name="msapplication-TileColor" content="#ffffff" />
    <meta name="msapplication-TileImage" content="easter-egg-5-144-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="152x152" href="easter-egg-5-152-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="144x144" href="easter-egg-5-144-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="120x120" href="easter-egg-5-120-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="114x114" href="easter-egg-5-114-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="72x72" href="easter-egg-5-72-279148.png">    
    <link rel="apple-touch-icon-precomposed" href="easter-egg-5-57-279148.png">
    <link rel="icon" href="easter-egg-5-32-279148.png" sizes="32x32">
    <style>
		.button_wrap { width: 60%; margin: 35px auto; text-align: center; }
		.button_wrap:hover { box-shadow: 10px 10px 10px Black; }
		.button_wrap button { 
            cursor: pointer; 
            /* height:145px; */
            padding:15px 30px;
            font-size:1.2em;
            width: 100%;
        }
		.button_wrap button:hover { background-color: White; color: Gray;; }
	</style>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
</head>
<body>

<?php

    echo '<h1 style="text-align:center;background-color:Gray;padding:10px;">Liturgical Calendar Project</h1>';
    echo '<h2 style="text-align:center;">Liturgical Calendar engine with algorithm to generate the Universal or General Roman Calendar for any given year</h2>';
    echo '<h3 style="text-align:center;"><a href="LitCalEngine.php" style="background-color:LightGray;border:1px solid DarkBlue;padding: 6px 12px;">Data generation Endpoint here (similar to a REST API)</a></h3>';
    echo '<hr>';
?>
    <div class="container" style="width: 80%; margin:30px auto;border:1px solid Blue;background-color:LightBlue;">
        <div class="button_wrap"><a href="easter.php"><button>Calculate Date of Easter, both Gregorian and Julian, from 1583 (year of the adoption of the Gregorian Calendar) to 9999 (max date calculation in 64bit PHP)</button></a></div>
        <div class="button_wrap"><a href="examples/php/"><button>HTML presentation elaborated by PHP using a CURL request</button></a></div>
        <div class="button_wrap"><a href="examples/javascript/"><button>HTML presentation elaborated by JAVASCRIPT using an AJAX request</button></a></div>
        <div class="button_wrap"><a href="examples/fullcalendar/examples/month-view.html"><button>Fullcalendar representation elaborated by JAVASCRIPT using an AJAX request</button></a></div>
    </div>

</body>
