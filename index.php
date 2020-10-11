<!doctype html>
<html lang="en">
<head>
    <title>Generate Roman Calendar</title>
    <?php echo  file_get_contents('layout/head.php'); ?>
</head>
<body>

    <?php echo  file_get_contents('layout/header.php'); ?>

        <!-- Page Heading -->
        <h1 class="h3 mb-2 text-gray-800">Liturgical Calendar Project</h1>
        <p class="mb-4">Liturgical Calendar engine with algorithm to generate the Universal or General Roman
            Calendar for any given year</p>

        <!-- Page Heading -->
        <h3 class="h3 mb-2 text-gray-800">SCRIPTS</h3>
    
        <!-- Content Row -->
        <div class="row">
            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Data Generation Endpoint</h6>
                    </div>
                    <div class="card-body">
                        <div><a href="LitCalEngine.php" class="btn btn-primary mt-2">View Data Example</a></div>
                        <p><i>A sample request to the endpoint could look like this:</i></p>
                        <code>/LitCalEngine.php?year=2020&epiphany=SUNDAY_JAN2_JAN8&ascension=SUNDAY&corpuschristi=SUNDAY&returntype=JSON&locale=EN</code>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Easter</h6>
                    </div>
                    <div class="card-body">
                        <p>Calculation of the Date of Easter, both Gregorian and Julian, from 1583
                            (year of the adoption of the Gregorian Calendar) to 9999 (max date calculation in
                            64bit PHP)</p>
                        <div><a href="easter.php" class="btn btn-primary">View Easter Example</a></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Page Heading -->
        <h3 class="h3 mb-2 text-gray-800">EXAMPLES</h3>

        <div class="row">

            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">PHP</h6>
                    </div>
                    <div class="card-body">
                        <p>HTML presentation elaborated by PHP using a CURL request</p>
                        <div><a href="examples/php/" class="btn btn-primary">View PHP Example</a></div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">JavaScript</h6>
                    </div>
                    <div class="card-body">
                        <p>HTML presentation elaborated by JAVASCRIPT using an AJAX
                            request</p>
                        <div><a href="examples/javascript/" class="btn btn-primary">View JavaScript Example</a></div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow m-2">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Calendar</h6>
                    </div>
                    <div class="card-body">
                        <p>Fullcalendar representation
                            elaborated by JAVASCRIPT using an AJAX request</p>
                        <div><a href="examples/fullcalendar/examples/month-view.html" class="btn btn-primary">View Full Calendar</a></div>
                        <div><a href="examples/fullcalendar/examples/messages.html" class="btn btn-primary mt-2">View Full Calendar (messages first)</a></div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /.row -->

    <?php echo  file_get_contents('layout/footer.php'); ?>

</body>
</html>
