<?php
    /* PLEASE DON'T REMOVE THIS FOR YOUR OWN SECURITY */
    if(!defined("LITURGYAPP") || LITURGYAPP != "AMDG"){
    	exit(0);
    }
    /* PLEASE DON'T REMOVE THIS FOR YOUR OWN SECURITY */


    /* DATABASE CONFIGURATION FOR LITURGY APPLICATION */

    //IT IS POSSIBLE TO USE A SPECIFIC DATABASE USERNAME AND PASSWORD 
    //FOR LOCALHOST INSTALLATION AND FOR REMOTE HOST INSTALLATION
    if($_SERVER["REMOTE_ADDR"]=="127.0.0.1"){ //LOCALHOST INSTALLATION
        define("DB_USER","");
        define("DB_PASSWORD","");
        define("DB_NAME","liturgy");
        define("DB_CHARSET","utf8");
        define("DB_SERVER","localhost");
	}
    else{ //REMOTE HOST INSTALLATION
        define("DB_USER","");
        define("DB_PASSWORD","");
        define("DB_NAME","liturgy");
        define("DB_CHARSET","utf8");
        define("DB_SERVER","localhost");
    }
